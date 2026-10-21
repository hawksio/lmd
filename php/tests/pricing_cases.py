"""Generate pricing cases with the Flask edition's engine (backend/pricing.py) so the PHP engine can be checked
against it. Usage: .venv\\Scripts\\python php\\tests\\pricing_cases.py php\\tests\\pricing_cases.json"""
import json
import os
import random
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", ".."))
from backend import pricing  # noqa: E402

random.seed(20261020)
KINDS = ["processing_fee", "admin_fee", "document_fee", "commission", "service_fee", "custom", "late_charge"]
TIMINGS = ["deducted_from_disbursement", "added_to_loan", "with_first_instalment", "not_charged_to_customer"]


def rule(i, kind, **kw):
    r = {"id": i, "version": 1, "kind": kind, "name": f"{kind} {i}", "method": "percent", "percent": None, "fixed_minor": None,
         "basis": "approved", "product_ids": "", "currencies": "", "timing": "interest" if kind == "interest" else "added_to_loan",
         "conditions": "", "disclosure": "Shown to the customer.", "description": "", "arrangement_reference": "",
         "effective_from": "2026-01-01", "effective_to": None, "enabled": 1}
    r.update(kw)
    return r


cases = []
for n in range(400):
    product = {"id": 1, "name": "Test", "interest_method": random.choice(["amortising", "flat"]),
               "repayment_frequency": random.choice(["monthly", "fortnightly", "weekly"]),
               "grace_period_months": random.choice([0, 0, 0, 1, 2, 3])}
    rules = []
    if random.random() < 0.9:
        rules.append(rule(1, "interest", percent=random.choice(["0", "4.9", "7.25", "12.5", "19.99", "29.9", "3.1415"])))
    for i in range(random.randint(0, 4)):
        kind = random.choice(KINDS)
        if kind == "late_charge":
            rules.append(rule(10 + i, kind, basis="instalment", timing="on_overdue_instalment", percent="5"))
            continue
        fixed = random.random() < 0.3
        rules.append(rule(10 + i, kind, method="fixed" if fixed else "percent",
                          percent=None if fixed else random.choice(["0.5", "1", "1.25", "2.5", "3.3333"]),
                          fixed_minor=random.choice([5000, 12550, 99]) if fixed else None,
                          basis=random.choice(["requested", "approved"]), timing=random.choice(TIMINGS)))
    requested = random.choice([500000, 1000000, 2500050, 7777777, 15000000])
    approved = requested if random.random() < 0.5 else random.randint(requested // 4, requested)
    term = random.choice([3, 6, 12, 18, 24, 36, 60, 84])
    if product["grace_period_months"] >= term:
        product["grace_period_months"] = 0
    try:
        q = pricing.quote(product, rules, requested, approved, term, "GBP", on_date="2026-10-20")
        expected = {"ok": True, "quote": q, "dated": pricing.dated_schedule(q, approved, "2026-01-31")}
    except pricing.PricingError as exc:
        expected = {"ok": False, "error": str(exc)}
    cases.append({"product": product, "rules": rules, "requested": requested, "approved": approved, "term": term, "expected": expected})

extra = []
for text in ["10,000.50", "£1,234", "0.1", "12.345", "-5", "abc", "1000000000.01", "999.999", ""]:
    extra.append({"money": text, "expected": pricing.parse_money(text)})
for text, cap in [("2.5", "100"), ("2.50000", "100"), ("0", "10"), ("100", "100"), ("100.0001", "100"), ("-1", "100"), ("3.10", "5")]:
    try:
        extra.append({"percent": text, "cap": cap, "expected": pricing.validate_percent(text, cap)})
    except pricing.PricingError as exc:
        extra.append({"percent": text, "cap": cap, "error": str(exc)})
for m, c in [(0, "GBP"), (123456789, "EUR"), (-5, "USD"), (100, "JPY")]:
    extra.append({"format": m, "currency": c, "expected": pricing.money(m, c)})

with open(sys.argv[1], "w", encoding="utf-8") as fh:
    json.dump({"cases": cases, "extra": extra}, fh)
print(len(cases), "cases written")
