"""Loan products and the Financial Settings (percentage & financial rules) for one organisation.

Only organisation administrators can change these. Every change to a rule is versioned in
financial_rule_history with the previous and new values. Decisions already made keep the rules they were
priced with (see pricing.py), so changes are never applied retroactively.
"""
import json
from datetime import date

from flask import Blueprint, abort, flash, redirect, render_template, request, url_for

from . import audit, pricing
from .db import execute, now, query, transaction
from .options import (CURRENCIES, DOC_TYPES, FINANCE_TYPES, FREQUENCIES, INTEREST_METHODS, RULE_BASES, RULE_KINDS,
                      RULE_TIMINGS)
from .security import can, current_user, permission_required, staff_required
from .tenancy import current_org, org_id, settings

bp = Blueprint("products", __name__, url_prefix="/admin")

# Which timings make sense for which kind of rule. Interest is always charged through the schedule; late charges only
# arise on an overdue instalment; commission is normally disclosed but paid by the lender, not the customer.
KIND_TIMINGS = {
    "interest": {"interest"},
    "late_charge": {"on_overdue_instalment"},
    "commission": {"not_charged_to_customer", "deducted_from_disbursement", "added_to_loan"},
}
FEE_TIMINGS = {"deducted_from_disbursement", "added_to_loan", "with_first_instalment", "not_charged_to_customer"}
KIND_BASES = {"interest": {"approved"}, "late_charge": {"instalment", "outstanding"}}
RULE_FIELDS = ["kind", "name", "description", "method", "percent", "fixed_minor", "basis", "product_ids", "currencies",
               "timing", "conditions", "disclosure", "arrangement_reference", "effective_from", "effective_to", "enabled"]


def platform_caps():
    row = query("SELECT value FROM platform_settings WHERE key = 'percent_caps'", one=True)
    caps = {k: "100" for k in RULE_KINDS}
    if row:
        caps.update(json.loads(row["value"]))
    return caps


def caps():
    """Effective maximum per kind: the platform cap, tightened by the organisation's own limit if it set one."""
    out = platform_caps()
    for k, v in settings()["max_percent"].items():
        if k in out and pricing.dec(v) < pricing.dec(out[k]):
            out[k] = v
    return out


# ---------------------------------------------------------------- loan products
def _product(pid):
    p = query("SELECT * FROM loan_products WHERE id = ? AND org_id = ?", (pid, org_id()), one=True)
    if p is None:
        abort(404)
    return p


def _read_product(form):
    f = {k: (form.get(k) or "").strip() for k in ("name", "description", "finance_type", "interest_method", "repayment_frequency",
                                                   "repayment_method", "eligibility", "form_fields")}
    errors = {}
    if not 2 <= len(f["name"]) <= 120:
        errors["name"] = "Enter a product name."
    f["currencies"] = ",".join(c for c in form.getlist("currencies") if c in CURRENCIES)
    if not f["currencies"]:
        errors["currencies"] = "Choose at least one currency."
    f["required_docs"] = ",".join(d for d in form.getlist("required_docs") if d in DOC_TYPES)
    for key in ("min_amount", "max_amount"):
        f[key + "_minor"] = pricing.parse_money(form.get(key))
        if not f[key + "_minor"]:
            errors[key] = "Enter an amount."
    for key in ("min_term_months", "max_term_months", "grace_period_months"):
        raw = (form.get(key) or "0").strip()
        f[key] = int(raw) if raw.isdigit() and int(raw) <= 600 else None
        if f[key] is None or (key != "grace_period_months" and f[key] < 1):
            errors[key] = "Enter a number of months."
    if not errors:
        if f["max_amount_minor"] < f["min_amount_minor"]:
            errors["max_amount"] = "The maximum must be at least the minimum."
        if f["max_term_months"] < f["min_term_months"]:
            errors["max_term_months"] = "The maximum must be at least the minimum."
        if f["grace_period_months"] >= f["min_term_months"]:
            errors["grace_period_months"] = "The grace period must be shorter than the shortest term."
    if f["finance_type"] not in FINANCE_TYPES:
        f["finance_type"] = "loans"
    if f["interest_method"] not in INTEREST_METHODS:
        errors["interest_method"] = "Choose a method."
    if f["repayment_frequency"] not in FREQUENCIES:
        errors["repayment_frequency"] = "Choose a frequency."
    threshold = (form.get("second_approval_threshold") or "").strip()
    f["second_approval_threshold_minor"] = pricing.parse_money(threshold) if threshold else None
    if threshold and f["second_approval_threshold_minor"] is None:
        errors["second_approval_threshold"] = "Enter an amount, or leave empty to use the organisation setting."
    f["allow_above_requested"] = 1 if form.get("allow_above_requested") else 0
    f["active"] = 1 if form.get("active") else 0
    try:
        fields = json.loads(f["form_fields"] or "[]")
        assert isinstance(fields, list) and all(isinstance(x, dict) and x.get("key") and x.get("label") for x in fields)
        for x in fields:
            assert x.get("type") in ("text", "number", "select", "yesno") and len(str(x["key"])) <= 40
            assert str(x["key"]).replace("_", "").isalnum()
        f["form_fields"] = json.dumps(fields)
    except (ValueError, AssertionError):
        errors["form_fields"] = 'Use a JSON list such as [{"key": "vat_registered", "label": "VAT registered?", "type": "yesno", "required": true}].'
    f["eligibility"] = f["eligibility"][:3000]
    f["description"] = f["description"][:2000]
    return f, errors


@bp.route("/products")
@staff_required
@permission_required("view_applications")
def products():
    rows = query("SELECT p.*, (SELECT COUNT(*) FROM applications a WHERE a.product_id = p.id AND a.status != 'draft') AS apps "
                 "FROM loan_products p WHERE p.org_id = ? ORDER BY p.active DESC, p.name", (org_id(),))
    return render_template("admin/products.html", rows=rows)


@bp.route("/products/new", methods=["GET", "POST"])
@bp.route("/products/<int:pid>", methods=["GET", "POST"])
@staff_required
@permission_required("view_applications")
def edit_product(pid=None):
    p = _product(pid) if pid else None
    form, errors = (dict(p) if p else {"currencies": current_org()["default_currency"], "interest_method": "amortising",
                                          "repayment_frequency": "monthly", "grace_period_months": 0, "active": 1,
                                          "form_fields": "[]", "required_docs": "identity,bank_statements"}), {}
    if request.method == "POST":
        if not can("manage_products"):
            abort(403)
        form, errors = _read_product(request.form)
        if not errors:
            limit = _plan_limit("max_products")
            if p is None and limit is not None and len(query("SELECT id FROM loan_products WHERE org_id = ?", (org_id(),))) >= limit:
                abort(403, f"Your subscription allows up to {limit} loan products.")
            cols = ["name", "description", "finance_type", "currencies", "min_amount_minor", "max_amount_minor", "min_term_months",
                    "max_term_months", "interest_method", "repayment_frequency", "grace_period_months", "repayment_method",
                    "eligibility", "required_docs", "form_fields", "allow_above_requested", "second_approval_threshold_minor", "active"]
            try:
                if p:
                    execute(f"UPDATE loan_products SET {', '.join(c + ' = ?' for c in cols)}, updated_at = ? WHERE id = ? AND org_id = ?",
                            [form[c] for c in cols] + [now(), p["id"], org_id()])
                    pid = p["id"]
                else:
                    pid = execute(f"INSERT INTO loan_products (org_id, {', '.join(cols)}, created_at, updated_at) "
                                  f"VALUES (?, {', '.join('?' for _ in cols)}, ?, ?)", [org_id()] + [form[c] for c in cols] + [now(), now()])
            except Exception as exc:  # unique name per organisation
                if "UNIQUE" not in str(exc):
                    raise
                errors["name"] = "You already have a product with this name."
            else:
                audit.log("product.saved", "product", pid, name=form["name"], active=form["active"])
                flash("Product saved.", "success")
                return redirect(url_for("products.edit_product", pid=pid))
    return render_template("admin/product_form.html", form=form, errors=errors, product=p, CURRENCIES=CURRENCIES,
                           FREQUENCIES=FREQUENCIES, INTEREST_METHODS=INTEREST_METHODS)


def _plan_limit(key):
    sub = query("SELECT p.* FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.org_id = ? "
                "AND s.status IN ('trial', 'active', 'past_due')", (org_id(),), one=True)
    return sub[key] if sub else None


# ---------------------------------------------------------------- financial rules
def _rule(rid):
    r = query("SELECT * FROM financial_rules WHERE id = ? AND org_id = ?", (rid, org_id()), one=True)
    if r is None:
        abort(404)
    return r


def read_rule(form, existing=None):
    """Validate a rule form. Returns (values, errors)."""
    v = {k: (form.get(k) or "").strip() for k in ("kind", "name", "description", "method", "basis", "timing", "conditions",
                                                   "disclosure", "arrangement_reference", "effective_from", "effective_to")}
    errors = {}
    if v["kind"] not in RULE_KINDS:
        errors["kind"] = "Choose a type."
        v["kind"] = "custom"
    if not 2 <= len(v["name"]) <= 120:
        errors["name"] = "Enter a name."
    if v["kind"] == "interest":
        v["method"] = "percent"
    if v["method"] not in ("percent", "fixed"):
        errors["method"] = "Choose percentage or fixed amount."
    v["percent"], v["fixed_minor"] = None, None
    if v["method"] == "percent":
        try:
            v["percent"] = pricing.validate_percent(form.get("percent", ""), caps()[v["kind"]])
        except pricing.PricingError as exc:
            errors["percent"] = str(exc)
    else:
        v["fixed_minor"] = pricing.parse_money(form.get("fixed_amount"))
        if v["fixed_minor"] is None:
            errors["fixed_amount"] = "Enter an amount, e.g. 150.00."
    allowed_bases = KIND_BASES.get(v["kind"], {"requested", "approved", "disbursed"})
    if v["basis"] not in allowed_bases:
        errors["basis"] = "Choose: " + ", ".join(RULE_BASES[b] for b in sorted(allowed_bases)) + "."
    allowed_timings = KIND_TIMINGS.get(v["kind"], FEE_TIMINGS)
    if v["timing"] not in allowed_timings:
        errors["timing"] = "Choose when this applies: " + "; ".join(RULE_TIMINGS[t] for t in sorted(allowed_timings)) + "."
    if v["kind"] == "commission" and not v["arrangement_reference"]:
        errors["arrangement_reference"] = "Commission needs a reference to the documented arrangement it comes from."
    if v["kind"] != "interest" and v["timing"] != "not_charged_to_customer" and len(v["disclosure"]) < 10:
        errors["disclosure"] = "Write the disclosure text customers will see for this charge."
    for key in ("effective_from", "effective_to"):
        if v[key]:
            try:
                date.fromisoformat(v[key])
            except ValueError:
                errors[key] = "Use a valid date."
    if not v["effective_from"]:
        errors["effective_from"] = "Choose the date this takes effect."
    if v["effective_to"] and v["effective_from"] and v["effective_to"] < v["effective_from"]:
        errors["effective_to"] = "The end date must be after the start date."
    v["effective_to"] = v["effective_to"] or None
    products = {str(p["id"]) for p in query("SELECT id FROM loan_products WHERE org_id = ?", (org_id(),))}
    v["product_ids"] = ",".join(p for p in form.getlist("product_ids") if p in products)
    v["currencies"] = ",".join(c for c in form.getlist("currencies") if c in CURRENCIES)
    v["enabled"] = 1 if form.get("enabled") else 0
    for k, n in (("description", 1000), ("conditions", 1000), ("disclosure", 2000), ("arrangement_reference", 200)):
        v[k] = v[k][:n]
    return v, errors


def _snapshot(row):
    return {k: row[k] for k in RULE_FIELDS}


def save_rule(values, user, existing=None, reason=""):
    with transaction():
        if existing is None:
            rid = execute(f"INSERT INTO financial_rules (org_id, {', '.join(RULE_FIELDS)}, version, created_by, created_at, updated_at) "
                          f"VALUES (?, {', '.join('?' for _ in RULE_FIELDS)}, 1, ?, ?, ?)",
                          [org_id()] + [values[k] for k in RULE_FIELDS] + [user["id"], now(), now()])
            execute("INSERT INTO financial_rule_history (org_id, rule_id, version, action, old_values, new_values, reason, changed_by, changed_at) "
                    "VALUES (?,?,?,?,?,?,?,?,?)", (org_id(), rid, 1, "created", "{}", json.dumps(values), reason, user["id"], now()))
            audit.log("financial_rule.created", "financial_rule", rid, kind=values["kind"], percent=values["percent"])
            return rid
        old = _snapshot(existing)
        changed = {k: values[k] for k in RULE_FIELDS if str(values[k]) != str(old[k])}
        if not changed:
            return existing["id"]
        version = existing["version"] + 1
        execute(f"UPDATE financial_rules SET {', '.join(k + ' = ?' for k in RULE_FIELDS)}, version = ?, updated_by = ?, updated_at = ? "
                f"WHERE id = ? AND org_id = ?", [values[k] for k in RULE_FIELDS] + [version, user["id"], now(), existing["id"], org_id()])
        execute("INSERT INTO financial_rule_history (org_id, rule_id, version, action, old_values, new_values, reason, changed_by, changed_at) "
                "VALUES (?,?,?,?,?,?,?,?,?)", (org_id(), existing["id"], version, "updated",
                                               json.dumps({k: old[k] for k in changed}), json.dumps(changed), reason, user["id"], now()))
        audit.log("financial_rule.updated", "financial_rule", existing["id"], version=version, fields=",".join(sorted(changed)))
        return existing["id"]


@bp.route("/financial-settings")
@staff_required
@permission_required("view_applications")
def financial_settings():
    if not (can("manage_financial_settings") or can("view_reports")):
        abort(403)
    rules = query("SELECT r.*, u.full_name AS updated_name FROM financial_rules r LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.created_by) "
                  "WHERE r.org_id = ? ORDER BY r.kind, r.enabled DESC, r.name", (org_id(),))
    products = query("SELECT * FROM loan_products WHERE org_id = ? ORDER BY name", (org_id(),))
    calc = None
    calc_error = None
    if request.args.get("calc_product"):
        product = next((p for p in products if str(p["id"]) == request.args.get("calc_product")), None)
        amount = pricing.parse_money(request.args.get("calc_amount"))
        term = request.args.get("calc_term", type=int)
        currency = request.args.get("calc_currency", "")
        if product is None or not amount or not term or currency not in CURRENCIES:
            calc_error = "Choose a product, currency, amount and term."
        else:
            try:
                calc = pricing.quote(product, rules, amount, amount, term, currency)
            except pricing.PricingError as exc:
                calc_error = str(exc)
    return render_template("admin/financial_settings.html", rules=rules, products=products, caps=caps(), calc=calc,
                           calc_error=calc_error, RULE_KINDS=RULE_KINDS, RULE_BASES=RULE_BASES, RULE_TIMINGS=RULE_TIMINGS,
                           CURRENCIES=CURRENCIES)


@bp.route("/financial-settings/new", methods=["GET", "POST"])
@bp.route("/financial-settings/<int:rid>", methods=["GET", "POST"])
@staff_required
@permission_required("view_applications")
def edit_rule(rid=None):
    if not (can("manage_financial_settings") or can("view_reports")):
        abort(403)
    r = _rule(rid) if rid else None
    form = dict(r) if r else {"kind": request.args.get("kind", "processing_fee"), "method": "percent", "basis": "approved",
                              "timing": "deducted_from_disbursement", "effective_from": date.today().isoformat(), "enabled": 1}
    errors = {}
    if request.method == "POST":
        if not can("manage_financial_settings"):
            abort(403)
        values, errors = read_rule(request.form, r)
        form = values | {"fixed_amount": request.form.get("fixed_amount", "")}
        if not request.form.get("confirm"):
            errors["confirm"] = "Tick the box to confirm this setting has been approved under your pricing policy."
        if not errors:
            rid = save_rule(values, current_user(), r, request.form.get("reason", "").strip()[:500])
            flash("Setting saved. It applies to new decisions only; existing offers and agreements keep their terms.", "success")
            return redirect(url_for("products.edit_rule", rid=rid))
    history = query("SELECT h.*, u.full_name FROM financial_rule_history h JOIN users u ON u.id = h.changed_by "
                    "WHERE h.rule_id = ? AND h.org_id = ? ORDER BY h.id DESC", (rid, org_id())) if rid else []
    history = [dict(h) | {"old": json.loads(h["old_values"]), "new": json.loads(h["new_values"])} for h in history]
    products = query("SELECT id, name FROM loan_products WHERE org_id = ? ORDER BY name", (org_id(),))
    return render_template("admin/rule_form.html", form=form, errors=errors, rule=r, history=history, products=products,
                           caps=caps(), RULE_KINDS=RULE_KINDS, RULE_BASES=RULE_BASES, RULE_TIMINGS=RULE_TIMINGS,
                           CURRENCIES=CURRENCIES, KIND_TIMINGS=KIND_TIMINGS, FEE_TIMINGS=FEE_TIMINGS)


@bp.route("/financial-settings/<int:rid>/toggle", methods=["POST"])
@staff_required
@permission_required("manage_financial_settings")
def toggle_rule(rid):
    r = _rule(rid)
    if not request.form.get("confirm"):
        flash("Tick the box to confirm.", "error")
        return redirect(url_for("products.financial_settings"))
    values = _snapshot(r) | {"enabled": 0 if r["enabled"] else 1}
    save_rule(values, current_user(), r, "enabled" if values["enabled"] else "disabled")
    flash("Setting enabled." if values["enabled"] else "Setting disabled. Existing offers keep their terms.", "success")
    return redirect(url_for("products.financial_settings"))
