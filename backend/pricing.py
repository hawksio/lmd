"""Loan pricing: applies an organisation's configured financial rules to an amount and term.

Pure calculation (no database writes). Money is handled as integers in minor units (pence/cents); percentages
are exact decimals. The returned quote is stored, unchanged, on every approval decision ("pricing snapshot"),
so editing a rule later never changes an offer, agreement or schedule that already exists.

Calculations are arithmetic only. They do not make any rate, fee or APR lawful or suitable: each organisation is
responsible for the values it configures, and the calculated APR must be checked against the rules that apply
to it before it is shown to customers.
"""
import json
from datetime import date, timedelta
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation

PERIODS_PER_YEAR = {"monthly": 12, "fortnightly": 26, "weekly": 52}
ONE = Decimal("1")
HUNDRED = Decimal("100")


class PricingError(ValueError):
    pass


def dec(value):
    try:
        d = Decimal(str(value).strip())
    except (InvalidOperation, AttributeError):
        raise PricingError("Enter a number.")
    if not d.is_finite():
        raise PricingError("Enter a number.")
    return d


def to_minor(amount):
    return int(Decimal(amount).quantize(ONE, rounding=ROUND_HALF_UP))


def parse_money(text):
    """'10,000.50' -> 1000050 minor units. None if invalid."""
    text = (text or "").replace(",", "").replace("£", "").replace("€", "").replace("$", "").strip()
    try:
        d = Decimal(text)
    except InvalidOperation:
        return None
    if not d.is_finite() or d < 0 or d.as_tuple().exponent < -2 or d > Decimal("1000000000"):
        return None
    return to_minor(d * 100)


def money(minor, currency="GBP"):
    symbol = {"GBP": "£", "EUR": "€", "USD": "$"}.get(currency, currency + " ")
    sign = "-" if minor < 0 else ""
    return f"{sign}{symbol}{abs(minor) / 100:,.2f}"


def validate_percent(text, cap):
    """Return a normalised decimal string, or raise PricingError. cap is the maximum allowed percentage."""
    p = dec(text)
    if p < 0:
        raise PricingError("A percentage can't be negative.")
    if p.as_tuple().exponent < -4:
        raise PricingError("Use at most 4 decimal places.")
    if p > dec(cap):
        raise PricingError(f"This organisation's limit for this setting is {cap}%.")
    return format(p.normalize(), "f") if p != 0 else "0"


# ---------------------------------------------------------------- rule selection
def _csv(text):
    return [x.strip() for x in (text or "").split(",") if x.strip()]


def rule_applies(rule, product_id, currency, on_date):
    if not rule["enabled"]:
        return False
    if rule["effective_from"] > on_date or (rule["effective_to"] and rule["effective_to"] < on_date):
        return False
    products = _csv(rule["product_ids"])
    currencies = _csv(rule["currencies"])
    return (not products or str(product_id) in products) and (not currencies or currency in currencies)


def select_rules(rules, product_id, currency, on_date):
    """Rules in force for this product/currency/date. A product-specific interest rate beats an all-products one."""
    live = [dict(r) for r in rules if rule_applies(r, product_id, currency, on_date)]
    interest = [r for r in live if r["kind"] == "interest"]
    if len(interest) > 1:
        specific = [r for r in interest if _csv(r["product_ids"])]
        interest = specific if specific else interest
        if len(interest) > 1:
            raise PricingError("More than one interest rate applies to this product. Disable or date-limit one of them.")
    return interest[0] if interest else None, [r for r in live if r["kind"] != "interest"]


def rule_amount(rule, basis_minor):
    if rule["method"] == "fixed":
        return int(rule["fixed_minor"])
    return to_minor(Decimal(basis_minor) * dec(rule["percent"]) / HUNDRED)


# ---------------------------------------------------------------- schedule
def build_schedule(principal_minor, annual_percent, term_months, frequency, method, grace_months=0, first_fee_minor=0):
    """Instalments as dicts {number, principal, interest, fees, amount, balance}. Rounding goes to the last one."""
    ppy = PERIODS_PER_YEAR[frequency]
    n = max(1, int((Decimal(term_months) * ppy / 12).quantize(ONE, rounding=ROUND_HALF_UP)))
    grace = min(n - 1, int((Decimal(grace_months) * ppy / 12).quantize(ONE, rounding=ROUND_HALF_UP)))
    rate = dec(annual_percent) / HUNDRED / ppy
    principal = Decimal(principal_minor)
    rows, balance = [], principal
    repaying = n - grace
    if method == "flat":
        total_interest = principal * dec(annual_percent) / HUNDRED * Decimal(term_months) / 12
        interest_each = total_interest / n
        principal_each = principal / repaying
        for k in range(1, n + 1):
            p = Decimal(0) if k <= grace else principal_each
            rows.append([p, interest_each])
    else:
        if rate == 0:
            level = principal / repaying
        else:
            level = principal * rate / (ONE - (ONE + rate) ** -repaying)
        for k in range(1, n + 1):
            interest = balance * rate
            p = Decimal(0) if k <= grace else level - interest
            balance -= p
            rows.append([p, interest])
    out, remaining, interest_total_exact = [], principal_minor, sum(r[1] for r in rows)
    interest_paid = 0
    for k, (p, i) in enumerate(rows, start=1):
        if k == n:
            p_minor = remaining
            i_minor = to_minor(interest_total_exact) - interest_paid
        else:
            p_minor, i_minor = to_minor(p), to_minor(i)
        remaining -= p_minor
        interest_paid += i_minor
        fees = first_fee_minor if k == 1 else 0
        out.append({"number": k, "principal": p_minor, "interest": i_minor, "fees": fees,
                    "amount": p_minor + i_minor + fees, "balance": remaining})
    return out


def calculated_apr(net_advance_minor, schedule, frequency):
    """Annual rate X solving: net advance = sum(payment_k / (1+X)^(t_k)), with t_k in years. Bisection."""
    if net_advance_minor <= 0 or not schedule:
        return None
    ppy = PERIODS_PER_YEAR[frequency]
    flows = [(row["number"] / ppy, row["amount"]) for row in schedule]

    def pv(x):
        return sum(a / (1 + x) ** t for t, a in flows)

    if pv(0.0) < net_advance_minor:
        return 0.0
    lo, hi = 0.0, 50.0
    for _ in range(200):
        mid = (lo + hi) / 2
        if pv(mid) > net_advance_minor:
            lo = mid
        else:
            hi = mid
    return round(lo * 100, 1)


# ---------------------------------------------------------------- the quote
def quote(product, rules, requested_minor, approved_minor, term_months, currency, on_date=None):
    """Full breakdown for an approval. `product` and `rules` are rows/dicts; returns a JSON-safe dict."""
    on_date = on_date or date.today().isoformat()
    if approved_minor <= 0:
        raise PricingError("The approved amount must be more than zero.")
    interest_rule, others = select_rules(rules, product["id"], currency, on_date)
    annual = dec(interest_rule["percent"]) if interest_rule else Decimal(0)
    fees, disclosures = [], []
    for r in others:
        if r["basis"] in ("outstanding", "instalment") or r["timing"] == "on_overdue_instalment":
            disclosures.append(_rule_view(r, None))   # late charges: disclosed now, calculated only if they arise
            continue
        basis_minor = requested_minor if r["basis"] == "requested" else approved_minor
        item = _rule_view(r, basis_minor)
        item["amount"] = rule_amount(r, basis_minor)
        (disclosures if r["timing"] == "not_charged_to_customer" else fees).append(item)
    added = sum(f["amount"] for f in fees if f["timing"] == "added_to_loan")
    deducted = sum(f["amount"] for f in fees if f["timing"] == "deducted_from_disbursement")
    first = sum(f["amount"] for f in fees if f["timing"] == "with_first_instalment")
    if deducted >= approved_minor:
        raise PricingError("Fees deducted from the payment would be more than the amount approved.")
    financed = approved_minor + added
    schedule = build_schedule(financed, annual, term_months, product["repayment_frequency"], product["interest_method"],
                              product["grace_period_months"], first)
    total_repayable = sum(r["amount"] for r in schedule)
    net_advance = approved_minor - deducted
    return {
        "currency": currency,
        "requested": requested_minor,
        "approved": approved_minor,
        "unapproved": max(0, requested_minor - approved_minor),
        "term_months": term_months,
        "product": {"id": product["id"], "name": product["name"], "interest_method": product["interest_method"],
                    "repayment_frequency": product["repayment_frequency"], "grace_period_months": product["grace_period_months"]},
        "interest": _rule_view(interest_rule, None) if interest_rule else None,
        "annual_rate": format(annual.normalize(), "f") if annual else "0",
        "fees": fees,
        "disclosures": disclosures,
        "fees_added_to_loan": added,
        "fees_deducted": deducted,
        "fees_first_instalment": first,
        "financed_principal": financed,
        "net_advance": net_advance,
        "total_interest": sum(r["interest"] for r in schedule),
        "total_fees": added + deducted + first,
        "total_repayable": total_repayable,
        "total_charge_for_credit": total_repayable - net_advance,
        "instalments": len(schedule),
        "regular_payment": schedule[1]["amount"] if len(schedule) > 1 else schedule[0]["amount"],
        "apr_calculated": calculated_apr(net_advance, schedule, product["repayment_frequency"]),
        "schedule": schedule,
        "calculated_on": on_date,
    }


def _rule_view(r, basis_minor):
    return {"rule_id": r["id"], "version": r["version"], "kind": r["kind"], "name": r["name"], "method": r["method"],
            "percent": r["percent"], "fixed": r["fixed_minor"], "basis": r["basis"], "basis_amount": basis_minor,
            "timing": r["timing"], "disclosure": r["disclosure"], "description": r["description"],
            "arrangement_reference": r.get("arrangement_reference", "")}


def snapshot_hash(pricing):
    import hashlib
    return hashlib.sha256(json.dumps(pricing, sort_keys=True).encode()).hexdigest()


def dated_schedule(pricing, principal_minor, first_due):
    """Turn the frozen terms into real due dates, for the principal actually lent."""
    p = pricing["product"]
    rows = build_schedule(principal_minor, pricing["annual_rate"], pricing["term_months"], p["repayment_frequency"],
                          p["interest_method"], p["grace_period_months"], pricing.get("fees_first_instalment", 0))
    start = date.fromisoformat(first_due)
    for i, row in enumerate(rows):
        row["due_date"] = _step(start, p["repayment_frequency"], i).isoformat()
    return rows


def _step(start, frequency, i):
    if frequency == "weekly":
        return start + timedelta(weeks=i)
    if frequency == "fortnightly":
        return start + timedelta(weeks=2 * i)
    month = start.month - 1 + i
    year, month = start.year + month // 12, month % 12 + 1
    days = [31, 29 if year % 4 == 0 and (year % 100 != 0 or year % 400 == 0) else 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
    return date(year, month, min(start.day, days[month - 1]))
