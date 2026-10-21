"""Underwriting decisions: full, partial and conditional approvals, revisions, second approval, offer acceptance.

The requested and approved amounts are stored separately. A decision is first *proposed*; it becomes visible to the
customer only when *issued*. Approvals at or above the threshold (product setting, else organisation setting; 0
means every approval) must be issued by a different person from the one who proposed them. Each decision freezes
its pricing calculation, which the agreement and repayment schedule then use.
"""
import json

from flask import Blueprint, abort, flash, redirect, render_template, request, url_for

from . import audit, pricing
from .applications import reference, set_status
from .db import execute, now, query, transaction
from .documents import outstanding
from .options import APPROVED, REVIEWING, STATUSES
from .security import current_user, login_required, permission_required, staff_required
from .tenancy import org_id, settings

bp = Blueprint("underwriting", __name__)

DECIDABLE = REVIEWING | APPROVED | {"agreement_issued"}


def get_app(app_id):
    a = query("SELECT a.*, u.full_name, u.email FROM applications a JOIN users u ON u.id = a.user_id "
              "WHERE a.id = ? AND a.org_id = ? AND a.status != 'draft'", (app_id, org_id()), one=True)
    if a is None:
        abort(404)
    return a


def decisions(app_id):
    rows = query("SELECT d.*, p.full_name AS proposer, i.full_name AS issuer FROM approval_decisions d "
                 "JOIN users p ON p.id = d.proposed_by LEFT JOIN users i ON i.id = d.issued_by "
                 "WHERE d.application_id = ? AND d.org_id = ? ORDER BY d.revision DESC", (app_id, org_id()))
    return [dict(r) | {"pricing_data": json.loads(r["pricing"])} for r in rows]


def current_decision(app_id):
    row = query("SELECT * FROM approval_decisions WHERE application_id = ? AND org_id = ? AND status = 'issued' "
                "ORDER BY revision DESC LIMIT 1", (app_id, org_id()), one=True)
    return (dict(row) | {"pricing_data": json.loads(row["pricing"])}) if row else None


def conditions(decision_id):
    return query("SELECT * FROM decision_conditions WHERE decision_id = ? AND org_id = ? ORDER BY id", (decision_id, org_id()))


def threshold_for(product):
    if product is not None and product["second_approval_threshold_minor"] is not None:
        return product["second_approval_threshold_minor"]
    return int(settings()["second_approval_threshold_minor"] or 0)


def _product(app_row, pid=None):
    pid = pid or app_row["product_id"]
    p = query("SELECT * FROM loan_products WHERE id = ? AND org_id = ?", (pid, org_id()), one=True) if pid else None
    return p


def read_decision(app_row, form):
    """Validate the decision form and calculate pricing. Returns (values, quote, errors)."""
    errors = {}
    product = _product(app_row, form.get("product_id", type=int))
    if product is None:
        errors["product_id"] = "Choose the loan product the approval is under (set up products in Loan products)."
    requested = (app_row["amount"] or 0) * 100
    approved = pricing.parse_money(form.get("approved_amount"))
    term = form.get("term_months", type=int)
    decision_type = form.get("decision_type", "")
    cond_text = [c.strip()[:500] for c in (form.get("conditions") or "").splitlines() if c.strip()]
    reason = (form.get("reason") or "").strip()[:2000]
    if not approved or approved <= 0:
        errors["approved_amount"] = "Enter the approved amount (more than zero)."
    elif product is not None:
        if approved > requested and not product["allow_above_requested"]:
            errors["approved_amount"] = "The approved amount can't exceed the requested amount under this product's policy."
        if not product["min_amount_minor"] <= approved <= product["max_amount_minor"]:
            errors["approved_amount"] = "The amount is outside this product's minimum and maximum."
        if not term or not product["min_term_months"] <= term <= product["max_term_months"]:
            errors["term_months"] = f"Enter a term between {product['min_term_months']} and {product['max_term_months']} months."
        if app_row["currency"] not in [c.strip() for c in product["currencies"].split(",")]:
            errors["product_id"] = "This product isn't offered in the application's currency."
    if decision_type not in ("full", "partial", "conditional"):
        errors["decision_type"] = "Choose the type of approval."
    elif approved and not errors.get("approved_amount"):
        if decision_type == "full" and approved < requested:
            errors["decision_type"] = "A full approval must approve the whole requested amount. Choose partial approval."
        if decision_type == "partial" and approved >= requested:
            errors["decision_type"] = "A partial approval approves less than the requested amount."
        if decision_type == "conditional" and not cond_text:
            errors["conditions"] = "List the conditions, one per line."
    if len(reason) < 10:
        errors["reason"] = "Record the basis for this decision (at least a sentence)."
    q = None
    if not errors:
        rules = query("SELECT * FROM financial_rules WHERE org_id = ?", (org_id(),))
        try:
            q = pricing.quote(product, rules, requested, approved, term, app_row["currency"])
        except pricing.PricingError as exc:
            errors["pricing"] = str(exc)
    values = {"product_id": product["id"] if product else None, "approved_minor": approved, "term_months": term,
              "decision_type": decision_type, "conditions": cond_text, "reason": reason,
              "internal_note": (form.get("internal_note") or "").strip()[:2000], "requested_minor": requested,
              "approved_amount": form.get("approved_amount", ""), "product": product}
    return values, q, errors


@bp.route("/admin/applications/<int:app_id>/decision", methods=["POST"])
@staff_required
@permission_required("propose_decisions")
def propose(app_id):
    app_row = get_app(app_id)
    if app_row["status"] not in DECIDABLE:
        abort(400, f"No approval decision can be made while the application is {STATUSES[app_row['status']]}.")
    if settings()["require_verified_documents_for_underwriting"] and outstanding(app_row):
        flash("Required documents are not all verified yet. Verify them, or change this in Organisation settings.", "error")
        return redirect(url_for("admin.application", app_id=app_id) + "#decision")
    values, q, errors = read_decision(app_row, request.form)
    if errors or request.form.get("action") == "preview":
        from .admin import render_workspace
        return render_workspace(app_row, decision_form=values, preview=q, decision_errors=errors)
    previous = current_decision(app_id)
    with transaction():
        execute("UPDATE approval_decisions SET status = 'superseded' WHERE application_id = ? AND org_id = ? AND status = 'proposed'",
                (app_id, org_id()))
        revision = (query("SELECT MAX(revision) r FROM approval_decisions WHERE application_id = ?", (app_id,), one=True)["r"] or 0) + 1
        needs_second = 1 if values["approved_minor"] >= threshold_for(values["product"]) else 0
        did = execute("INSERT INTO approval_decisions (org_id, application_id, revision, decision_type, status, currency, requested_minor, "
                      "approved_minor, previous_approved_minor, term_months, product_id, reason, internal_note, pricing, "
                      "needs_second_approval, proposed_by, proposed_at) VALUES (?,?,?,?, 'proposed', ?,?,?,?,?,?,?,?,?,?,?,?)",
                      (org_id(), app_id, revision, values["decision_type"], app_row["currency"], values["requested_minor"],
                       values["approved_minor"], previous["approved_minor"] if previous else None, values["term_months"],
                       values["product_id"], values["reason"], values["internal_note"], json.dumps(q), needs_second,
                       current_user()["id"], now()))
        for text in values["conditions"]:
            execute("INSERT INTO decision_conditions (org_id, decision_id, text) VALUES (?,?,?)", (org_id(), did, text))
        if app_row["status"] in REVIEWING and app_row["status"] != "underwriting":
            execute("UPDATE applications SET status = 'underwriting', updated_at = ? WHERE id = ?", (now(), app_id))
            execute("INSERT INTO status_history (application_id, status, changed_by, note, created_at) VALUES (?,?,?,?,?)",
                    (app_id, "underwriting", current_user()["id"], "Decision proposed", now()))
        audit.log("decision.proposed", "application", app_id, decision_id=did, revision=revision,
                  approved_minor=values["approved_minor"], previous_minor=previous["approved_minor"] if previous else None,
                  decision_type=values["decision_type"])
    flash("Decision proposed. It is not visible to the customer until it is issued"
          + (" by a different authorised person." if needs_second else "."), "success")
    return redirect(url_for("admin.application", app_id=app_id) + "#decision")


@bp.route("/admin/decisions/<int:did>/issue", methods=["POST"])
@staff_required
@permission_required("issue_decisions")
def issue(did):
    d = query("SELECT * FROM approval_decisions WHERE id = ? AND org_id = ? AND status = 'proposed'", (did, org_id()), one=True)
    if d is None:
        abort(404)
    app_row = get_app(d["application_id"])
    if d["needs_second_approval"] and d["proposed_by"] == current_user()["id"]:
        abort(403, "This approval needs a second authorised person: someone other than the proposer must issue it.")
    if not request.form.get("confirm"):
        flash("Tick the box to confirm you have reviewed the decision and its figures.", "error")
        return redirect(url_for("admin.application", app_id=app_row["id"]) + "#decision")
    has_conditions = bool(conditions(did))
    new_status = "conditionally_approved" if (d["decision_type"] == "conditional" or has_conditions) else \
        ("fully_approved" if d["decision_type"] == "full" else "partially_approved")
    with transaction():
        execute("UPDATE approval_decisions SET status = 'superseded' WHERE application_id = ? AND org_id = ? AND status = 'issued'",
                (d["application_id"], org_id()))
        execute("UPDATE approval_decisions SET status = 'issued', issued_by = ?, issued_at = ? WHERE id = ?",
                (current_user()["id"], now(), did))
        # A revised decision replaces any agreement already issued on the old one.
        execute("UPDATE generated_documents SET status = 'superseded' WHERE application_id = ? AND kind IN ('agreement', 'approval') "
                "AND status IN ('issued', 'draft')", (d["application_id"],))
        audit.log("decision.issued", "application", d["application_id"], decision_id=did, status=new_status,
                  approved_minor=d["approved_minor"], second_person=bool(d["needs_second_approval"]))
    message = (f"Decision: {STATUSES[new_status]}. Approved {pricing.money(d['approved_minor'], d['currency'])} of "
               f"{pricing.money(d['requested_minor'], d['currency'])} requested. This is not a payment: nothing has been paid out.")
    set_status(app_row, new_status, current_user(), note=f"Decision revision {d['revision']} issued", message=message)
    flash(f"Decision issued: {STATUSES[new_status]}. The customer has been notified.", "success")
    return redirect(url_for("admin.application", app_id=app_row["id"]) + "#decision")


@bp.route("/admin/decisions/<int:did>/withdraw", methods=["POST"])
@staff_required
@permission_required("propose_decisions")
def withdraw_proposal(did):
    d = query("SELECT * FROM approval_decisions WHERE id = ? AND org_id = ? AND status = 'proposed'", (did, org_id()), one=True)
    if d is None:
        abort(404)
    execute("UPDATE approval_decisions SET status = 'withdrawn' WHERE id = ?", (did,))
    audit.log("decision.withdrawn", "application", d["application_id"], decision_id=did)
    flash("Proposed decision withdrawn.", "success")
    return redirect(url_for("admin.application", app_id=d["application_id"]) + "#decision")


@bp.route("/admin/conditions/<int:cid>", methods=["POST"])
@staff_required
@permission_required("propose_decisions")
def update_condition(cid):
    c = query("SELECT c.*, d.application_id FROM decision_conditions c JOIN approval_decisions d ON d.id = c.decision_id "
              "WHERE c.id = ? AND c.org_id = ?", (cid, org_id()), one=True)
    status = request.form.get("status")
    if c is None or status not in ("outstanding", "satisfied", "waived"):
        abort(404)
    execute("UPDATE decision_conditions SET status = ?, updated_by = ?, updated_at = ? WHERE id = ?",
            (status, current_user()["id"], now(), cid))
    audit.log("decision.condition", "application", c["application_id"], condition_id=cid, status=status)
    return redirect(url_for("admin.application", app_id=c["application_id"]) + "#decision")


# ---------------------------------------------------------------- customer side
def financing_summary(app_row):
    """Separate, genuine figures for the customer dashboard. Nothing here is a balance or available cash."""
    d = current_decision(app_row["id"])
    disb = query("SELECT status, SUM(amount_pence) total, SUM(fees_deducted_minor) fees FROM disbursements "
                 "WHERE application_id = ? AND org_id = ? GROUP BY status", (app_row["id"], app_row["org_id"]))
    by = {r["status"]: r for r in disb}
    repaid = query("SELECT COALESCE(SUM(amount_minor), 0) n FROM repayments WHERE application_id = ? AND status = 'confirmed'",
                   (app_row["id"],), one=True)["n"]
    from .finance import loan_position
    position = loan_position(app_row["id"])
    agreement = query("SELECT status FROM generated_documents WHERE application_id = ? AND kind = 'agreement' "
                      "AND status IN ('issued', 'draft') ORDER BY id DESC LIMIT 1", (app_row["id"],), one=True)
    if app_row["status"] in ("agreement_signed", "funding_scheduled", "partially_disbursed", "fully_disbursed"):
        agreement_status = "Signed"
    elif agreement and agreement["status"] == "issued":
        agreement_status = "Issued, awaiting signature"
    else:
        agreement_status = "Not issued"
    return {
        "currency": app_row["currency"],
        "requested": (app_row["amount"] or 0) * 100,
        "decision": d,
        "approved": d["approved_minor"] if d else None,
        "conditions": [c for c in conditions(d["id"])] if d else [],
        "agreement_status": agreement_status,
        "scheduled": sum(by[s]["total"] or 0 for s in ("scheduled", "pending") if s in by),
        "disbursed": by["confirmed"]["total"] if "confirmed" in by else 0,
        "fees_deducted": by["confirmed"]["fees"] if "confirmed" in by else 0,
        "repaid": repaid,
        "outstanding_principal": position["outstanding_principal"] if position else None,
        "next_due": position["next_due"] if position else None,
    }


@bp.route("/account/applications/<int:app_id>/offer", methods=["GET", "POST"])
@login_required
def offer(app_id):
    from .client import own_application
    app_row = own_application(app_id)
    d = current_decision(app_id)
    if d is None:
        abort(404)
    accepted = query("SELECT * FROM offer_acceptances WHERE decision_id = ?", (d["id"],), one=True)
    if request.method == "POST":
        if accepted:
            flash("You have already accepted this offer.", "info")
        elif app_row["status"] not in APPROVED:
            abort(400, "This offer can no longer be accepted.")
        elif not request.form.get("accept"):
            flash("Tick the box to confirm you have read the figures and conditions.", "error")
        else:
            with transaction():
                execute("INSERT INTO offer_acceptances (org_id, decision_id, user_id, pricing_hash, ip, accepted_at) VALUES (?,?,?,?,?,?)",
                        (org_id(), d["id"], current_user()["id"], pricing.snapshot_hash(d["pricing_data"]),
                         (request.remote_addr or "")[:64], now()))
                audit.log("decision.offer_accepted", "application", app_id, decision_id=d["id"])
            from .applications import notify_staff
            notify_staff("issue_decisions", f"Offer accepted on {reference(app_row)}", f"/admin/applications/{app_id}#decision")
            flash("Thank you. We've recorded that you accept the offer. Your agreement will follow; nothing is binding until it is signed.", "success")
        return redirect(url_for("underwriting.offer", app_id=app_id))
    return render_template("client/offer.html", app=app_row, d=d, q=d["pricing_data"], accepted=accepted,
                           conditions=conditions(d["id"]))
