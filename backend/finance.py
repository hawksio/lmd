"""Money records: disbursements, repayment schedules, repayments and fees.

Nothing here moves money. These are records of payments that actually happened, entered by authorised finance staff
from a bank statement or a payment provider's confirmation:
- a disbursement is scheduled, then marked as paid with the bank reference, then confirmed by a different person;
  only confirmed disbursements count as "disbursed";
- a repayment can only be recorded once per payment reference (idempotency), and is corrected by reversal, never
  by editing or deleting;
- fees come from the frozen pricing of the issued decision. No fee is ever a condition of an approval or a payment.
"""
import json
import sqlite3
from datetime import date

from flask import Blueprint, abort, flash, redirect, render_template, request, url_for

from . import audit, letters, mail, pricing
from .applications import notify, reference, set_status
from .db import execute, now, query, transaction
from .options import CONFIRMATION_SOURCES, PAYMENT_METHODS, RULE_TIMINGS
from .security import current_user, permission_required, staff_required
from .tenancy import org_id
from .underwriting import current_decision, get_app

bp = Blueprint("finance", __name__, url_prefix="/admin")

PAYABLE_WHEN = {
    "deducted_from_disbursement": "Taken from the first payment to you, so you receive less than the approved amount",
    "added_to_loan": "Added to the loan and repaid through your instalments",
    "with_first_instalment": "Payable with your first instalment",
    "on_overdue_instalment": "Only if an instalment is paid late",
}


def _back(app_id, anchor="#payments"):
    return redirect(url_for("admin.application", app_id=app_id) + anchor)


def _date(text, allow_future=False):
    try:
        d = date.fromisoformat(text or "")
    except ValueError:
        return None
    return d if allow_future or d <= date.today() else None


# ---------------------------------------------------------------- fees from a decision
def create_fee_items(app_row, decision):
    """Called when the agreement is signed: the decision's customer charges become fee records (pending)."""
    if query("SELECT 1 FROM fee_items WHERE decision_id = ?", (decision["id"],), one=True):
        return
    for f in decision["pricing_data"]["fees"]:
        execute("INSERT INTO fee_items (org_id, application_id, decision_id, rule_id, rule_version, kind, name, basis, basis_minor, "
                "percent, amount_minor, currency, timing, disclosure, payable_when, created_at, updated_at) "
                "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                (org_id(), app_row["id"], decision["id"], f["rule_id"], f["version"], f["kind"], f["name"], f["basis"],
                 f["basis_amount"] or 0, f["percent"], f["amount"], decision["currency"], f["timing"], f["disclosure"],
                 PAYABLE_WHEN.get(f["timing"], ""), now(), now()))
    audit.log("fees.created", "application", app_row["id"], decision_id=decision["id"])


# ---------------------------------------------------------------- disbursements
def _totals(app_id):
    rows = {r["status"]: r for r in query("SELECT status, COALESCE(SUM(amount_pence), 0) total, COUNT(*) n FROM disbursements "
                                          "WHERE application_id = ? AND org_id = ? GROUP BY status", (app_id, org_id()))}
    return {s: (rows[s]["total"] if s in rows else 0) for s in ("scheduled", "pending", "confirmed")}


def sync_disbursement_status(app_row, actor):
    """Application status follows the confirmed money, never the other way round."""
    d = current_decision(app_row["id"])
    t = _totals(app_row["id"])
    if t["confirmed"] and t["confirmed"] >= d["approved_minor"]:
        new = "fully_disbursed"
    elif t["confirmed"]:
        new = "partially_disbursed"
    elif t["scheduled"] or t["pending"]:
        new = "funding_scheduled"
    else:
        new = "agreement_signed"
    fresh = query("SELECT * FROM applications WHERE id = ?", (app_row["id"],), one=True)
    if fresh["status"] != new:
        set_status(fresh, new, actor, note="Updated from confirmed payment records", email=False)


@bp.route("/applications/<int:app_id>/disbursements", methods=["POST"])
@staff_required
@permission_required("record_disbursement")
def schedule_disbursement(app_id):
    app_row = get_app(app_id)
    if app_row["status"] not in ("agreement_signed", "funding_scheduled", "partially_disbursed"):
        abort(400, "Payments can be scheduled only after the agreement is signed.")
    d = current_decision(app_id)
    amount = pricing.parse_money(request.form.get("amount"))
    when = _date(request.form.get("scheduled_for"), allow_future=True)
    t = _totals(app_id)
    remaining = d["approved_minor"] - t["scheduled"] - t["pending"] - t["confirmed"]
    if not amount or when is None:
        flash("Enter the amount and the planned payment date.", "error")
        return _back(app_id)
    if amount > remaining:
        flash(f"Only {pricing.money(remaining, d['currency'])} of the approved amount is left to schedule.", "error")
        return _back(app_id)
    first = not query("SELECT 1 FROM disbursements WHERE application_id = ? AND status IN ('scheduled', 'pending', 'confirmed')",
                      (app_id,), one=True)
    deducted = 0
    if first:
        deducted = query("SELECT COALESCE(SUM(amount_minor), 0) n FROM fee_items WHERE application_id = ? AND timing = "
                         "'deducted_from_disbursement' AND status = 'pending'", (app_id,), one=True)["n"]
        if deducted >= amount:
            flash("The first payment must be larger than the fees deducted from it.", "error")
            return _back(app_id)
    did = execute("INSERT INTO disbursements (org_id, application_id, decision_id, currency, amount_pence, fees_deducted_minor, "
                  "scheduled_for, status, recorded_by, recorded_at, note) VALUES (?,?,?,?,?,?,?, 'scheduled', ?,?,?)",
                  (org_id(), app_id, d["id"], d["currency"], amount, deducted, when.isoformat(), current_user()["id"], now(),
                   request.form.get("note", "").strip()[:500]))
    audit.log("disbursement.scheduled", "application", app_id, disbursement_id=did, amount_minor=amount)
    sync_disbursement_status(app_row, current_user())
    flash("Payment scheduled. Record it as paid once it has actually left the bank.", "success")
    return _back(app_id)


def _disbursement(did, status):
    d = query("SELECT * FROM disbursements WHERE id = ? AND org_id = ?", (did, org_id()), one=True)
    if d is None or d["status"] not in status:
        abort(404)
    return d


@bp.route("/disbursements/<int:did>/paid", methods=["POST"])
@staff_required
@permission_required("record_disbursement")
def mark_paid(did):
    d = _disbursement(did, ("scheduled",))
    paid = _date(request.form.get("paid_on"))
    method = request.form.get("method", "")
    ref = request.form.get("payment_reference", "").strip()[:120]
    if paid is None or method not in PAYMENT_METHODS or not ref:
        flash("Enter the date it was actually paid (not in the future), the method and the bank reference.", "error")
        return _back(d["application_id"])
    try:
        execute("UPDATE disbursements SET status = 'pending', paid_on = ?, method = ?, payment_reference = ?, recorded_by = ? "
                "WHERE id = ? AND status = 'scheduled'", (paid.isoformat(), method, ref, current_user()["id"], did))
    except sqlite3.IntegrityError:
        flash("A payment with that reference has already been recorded.", "error")
        return _back(d["application_id"])
    audit.log("disbursement.marked_paid", "application", d["application_id"], disbursement_id=did)
    flash("Recorded as paid. A different authorised person must now confirm it against the bank record.", "success")
    return _back(d["application_id"])


@bp.route("/disbursements/<int:did>/confirm", methods=["POST"])
@staff_required
@permission_required("confirm_disbursement")
def confirm_disbursement(did):
    d = _disbursement(did, ("pending",))
    if d["recorded_by"] == current_user()["id"]:
        abort(403, "A different authorised person must confirm a payment you recorded.")
    source = request.form.get("confirmation_source", "")
    if not request.form.get("bank_checked") or source not in CONFIRMATION_SOURCES:
        flash("Confirm you have checked the payment, and say how.", "error")
        return _back(d["application_id"])
    app_row = get_app(d["application_id"])
    with transaction():
        execute("UPDATE disbursements SET status = 'confirmed', confirmed_by = ?, confirmed_at = ?, confirmation_source = ? WHERE id = ?",
                (current_user()["id"], now(), source, did))
        if d["fees_deducted_minor"]:
            execute("UPDATE fee_items SET status = 'paid', paid_at = ?, payment_reference = ?, payment_source = 'deducted from payment', "
                    "updated_by = ?, updated_at = ? WHERE application_id = ? AND timing = 'deducted_from_disbursement' AND status = 'pending'",
                    (now(), d["payment_reference"], current_user()["id"], now(), d["application_id"]))
        audit.log("disbursement.confirmed", "application", d["application_id"], disbursement_id=did, amount_minor=d["amount_pence"])
    sync_disbursement_status(app_row, current_user())
    fresh = query("SELECT * FROM disbursements WHERE id = ?", (did,), one=True)
    letters.generate_record("disbursement", app_row, current_user(), ref_id=did)
    notify(app_row["user_id"], f"Payment made for {reference(app_row)}", f"/account/applications/{app_row['id']}#financing")
    mail.notify_disbursement(app_row, {"email": app_row["email"], "full_name": app_row["full_name"]}, fresh)
    flash("Payment confirmed. The customer has been notified.", "success")
    return _back(d["application_id"])


@bp.route("/disbursements/<int:did>/<action>", methods=["POST"])
@staff_required
@permission_required("record_disbursement")
def change_disbursement(did, action):
    if action not in ("cancel", "reject", "reverse"):
        abort(404)
    d = _disbursement(did, {"cancel": ("scheduled",), "reject": ("pending",), "reverse": ("confirmed",)}[action])
    reason = request.form.get("reason", "").strip()[:500]
    if action == "reverse":
        if not request.form.get("confirm") or len(reason) < 5:
            flash("Give the reason for the reversal and tick the box.", "error")
            return _back(d["application_id"])
        if query("SELECT 1 FROM repayments WHERE application_id = ? AND status = 'confirmed'", (d["application_id"],), one=True):
            abort(400, "Repayments have been recorded against this loan. Reverse those first.")
    app_row = get_app(d["application_id"])
    with transaction():
        if action == "reverse":
            execute("UPDATE disbursements SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ? WHERE id = ?",
                    (current_user()["id"], now(), reason, did))
            if d["fees_deducted_minor"]:
                execute("UPDATE fee_items SET status = 'pending', paid_at = NULL, payment_reference = '', payment_source = '', "
                        "updated_by = ?, updated_at = ? WHERE application_id = ? AND timing = 'deducted_from_disbursement' AND status = 'paid'",
                        (current_user()["id"], now(), d["application_id"]))
        else:
            execute("UPDATE disbursements SET status = ?, reversal_reason = ? WHERE id = ?",
                    ("cancelled" if action == "cancel" else "rejected", reason, did))
        audit.log(f"disbursement.{action}", "application", d["application_id"], disbursement_id=did)
    sync_disbursement_status(app_row, current_user())
    flash({"cancel": "Scheduled payment cancelled.", "reject": "Payment record rejected.", "reverse": "Payment reversed."}[action], "success")
    return _back(d["application_id"])


# ---------------------------------------------------------------- repayment schedule and position
def active_schedule(app_id):
    return query("SELECT * FROM repayment_schedules WHERE application_id = ? AND status = 'active'", (app_id,), one=True)


def loan_position(app_id):
    """Allocate confirmed repayments, oldest first, to instalments in due order (fees, then interest, then principal)."""
    s = active_schedule(app_id)
    if s is None:
        return None
    inst = [dict(i) for i in query("SELECT * FROM instalments WHERE schedule_id = ? ORDER BY number", (s["id"],))]
    paid_total = query("SELECT COALESCE(SUM(amount_minor), 0) n FROM repayments WHERE schedule_id = ? AND status = 'confirmed'",
                       (s["id"],), one=True)["n"]
    pot = paid_total
    today = date.today().isoformat()
    principal_paid = interest_paid = 0
    for i in inst:
        take = {}
        for part in ("fees_minor", "interest_minor", "principal_minor"):
            take[part] = min(pot, i[part])
            pot -= take[part]
        i["paid_minor"] = sum(take.values())
        i["unpaid_minor"] = i["amount_minor"] - i["paid_minor"]
        principal_paid += take["principal_minor"]
        interest_paid += take["interest_minor"]
        if i["unpaid_minor"] == 0:
            i["state"] = "paid"
        elif i["due_date"] < today:
            i["state"] = "overdue"
        elif i["paid_minor"]:
            i["state"] = "part_paid"
        else:
            i["state"] = "due"
    unpaid = [i for i in inst if i["unpaid_minor"]]
    overdue = [i for i in inst if i["state"] == "overdue"]
    return {"schedule": s, "instalments": inst, "repaid": paid_total, "credit": pot,
            "outstanding_principal": s["principal_minor"] - principal_paid, "interest_received": interest_paid,
            "outstanding_total": sum(i["unpaid_minor"] for i in inst),
            "next_due": unpaid[0] if unpaid else None, "overdue_count": len(overdue),
            "overdue_minor": sum(i["unpaid_minor"] for i in overdue)}


def sync_financed_fees(app_id, actor):
    """Fees repaid through instalments become 'paid' once the instalments that carry them are paid."""
    pos = loan_position(app_id)
    if pos is None:
        return
    first_paid = pos["instalments"] and pos["instalments"][0]["state"] == "paid"
    all_paid = all(i["state"] == "paid" for i in pos["instalments"])
    for timing, done in (("with_first_instalment", first_paid), ("added_to_loan", all_paid)):
        execute("UPDATE fee_items SET status = ?, paid_at = ?, payment_source = ?, updated_by = ?, updated_at = ? "
                "WHERE application_id = ? AND timing = ? AND status = ?",
                ("paid" if done else "pending", now() if done else None, "repaid through instalments" if done else "",
                 actor["id"], now(), app_id, timing, "pending" if done else "paid"))


@bp.route("/applications/<int:app_id>/schedule", methods=["POST"])
@staff_required
@permission_required("manage_repayments")
def create_schedule(app_id):
    app_row = get_app(app_id)
    d = current_decision(app_id)
    confirmed = _totals(app_id)["confirmed"]
    first_due = _date(request.form.get("first_due_date"), allow_future=True)
    if d is None or not confirmed:
        abort(400, "A repayment schedule can be created after a payment to the customer has been confirmed.")
    if first_due is None:
        flash("Choose the first due date.", "error")
        return _back(app_id, "#repayments")
    existing = active_schedule(app_id)
    if existing and query("SELECT 1 FROM repayments WHERE schedule_id = ? AND status = 'confirmed'", (existing["id"],), one=True):
        abort(400, "Repayments exist on the current schedule. Reverse them before replacing it.")
    q = d["pricing_data"]
    principal = confirmed + q["fees_added_to_loan"]
    rows = pricing.dated_schedule(q, principal, first_due.isoformat())
    with transaction():
        if existing:
            execute("UPDATE repayment_schedules SET status = 'superseded' WHERE id = ?", (existing["id"],))
        sid = execute("INSERT INTO repayment_schedules (org_id, application_id, decision_id, currency, principal_minor, first_due_date, "
                      "frequency, terms, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)",
                      (org_id(), app_id, d["id"], d["currency"], principal, first_due.isoformat(),
                       q["product"]["repayment_frequency"], json.dumps({k: q[k] for k in ("annual_rate", "term_months", "product")}),
                       current_user()["id"], now()))
        for r in rows:
            execute("INSERT INTO instalments (org_id, schedule_id, number, due_date, principal_minor, interest_minor, fees_minor, amount_minor) "
                    "VALUES (?,?,?,?,?,?,?,?)", (org_id(), sid, r["number"], r["due_date"], r["principal"], r["interest"], r["fees"], r["amount"]))
        audit.log("schedule.created", "application", app_id, schedule_id=sid, principal_minor=principal, instalments=len(rows))
    letters.generate_record("schedule", app_row, current_user(), ref_id=sid)
    notify(app_row["user_id"], f"Your repayment schedule for {reference(app_row)} is ready", f"/account/applications/{app_id}#repayments")
    flash(f"Repayment schedule created: {len(rows)} instalments, based on the frozen terms of the issued decision.", "success")
    return _back(app_id, "#repayments")


@bp.route("/applications/<int:app_id>/repayments", methods=["POST"])
@staff_required
@permission_required("manage_repayments")
def record_repayment(app_id):
    app_row = get_app(app_id)
    s = active_schedule(app_id)
    if s is None:
        abort(400, "Create the repayment schedule first.")
    amount = pricing.parse_money(request.form.get("amount"))
    received = _date(request.form.get("received_on"))
    method = request.form.get("method", "")
    ref = request.form.get("provider_reference", "").strip()[:120]
    source = request.form.get("confirmation_source", "")
    if not amount or received is None or method not in PAYMENT_METHODS or not ref or source not in CONFIRMATION_SOURCES:
        flash("Enter the amount, the date it was received (not in the future), the method, the payment reference and how it was confirmed.", "error")
        return _back(app_id, "#repayments")
    try:
        with transaction():
            rid = execute("INSERT INTO repayments (org_id, application_id, schedule_id, amount_minor, currency, received_on, method, "
                          "provider_reference, confirmation_source, note, recorded_by, recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                          (org_id(), app_id, s["id"], amount, s["currency"], received.isoformat(), method, ref, source,
                           request.form.get("note", "").strip()[:500], current_user()["id"], now()))
            audit.log("repayment.recorded", "application", app_id, repayment_id=rid, amount_minor=amount)
    except sqlite3.IntegrityError:
        flash("That payment reference has already been recorded, so nothing was added (no duplicate).", "info")
        return _back(app_id, "#repayments")
    sync_financed_fees(app_id, current_user())
    letters.generate_record("receipt", app_row, current_user(), ref_id=rid)
    notify(app_row["user_id"], f"Payment received: {pricing.money(amount, s['currency'])}", f"/account/applications/{app_id}#repayments")
    flash("Repayment recorded and a receipt issued.", "success")
    return _back(app_id, "#repayments")


@bp.route("/repayments/<int:rid>/reverse", methods=["POST"])
@staff_required
@permission_required("manage_repayments")
def reverse_repayment(rid):
    r = query("SELECT * FROM repayments WHERE id = ? AND org_id = ? AND status = 'confirmed'", (rid, org_id()), one=True)
    if r is None:
        abort(404)
    reason = request.form.get("reason", "").strip()[:500]
    if not request.form.get("confirm") or len(reason) < 5:
        flash("Give the reason for the reversal and tick the box.", "error")
        return _back(r["application_id"], "#repayments")
    execute("UPDATE repayments SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ? WHERE id = ?",
            (current_user()["id"], now(), reason, rid))
    execute("UPDATE generated_documents SET status = 'superseded' WHERE kind = 'receipt' AND ref_id = ? AND application_id = ?",
            (rid, r["application_id"]))
    audit.log("repayment.reversed", "application", r["application_id"], repayment_id=rid)
    sync_financed_fees(r["application_id"], current_user())
    flash("Repayment reversed. Its receipt has been withdrawn.", "success")
    return _back(r["application_id"], "#repayments")


# ---------------------------------------------------------------- fees
def _fee(fid):
    f = query("SELECT * FROM fee_items WHERE id = ? AND org_id = ?", (fid, org_id()), one=True)
    if f is None:
        abort(404)
    return f


@bp.route("/instalments/<int:iid>/late-charge", methods=["POST"])
@staff_required
@permission_required("manage_fees")
def late_charge(iid):
    inst = query("SELECT i.*, s.application_id FROM instalments i JOIN repayment_schedules s ON s.id = i.schedule_id "
                 "WHERE i.id = ? AND i.org_id = ? AND s.status = 'active'", (iid, org_id()), one=True)
    if inst is None:
        abort(404)
    app_row = get_app(inst["application_id"])
    pos = loan_position(app_row["id"])
    row = next(i for i in pos["instalments"] if i["id"] == iid)
    if row["state"] != "overdue":
        abort(400, "Late charges apply only to overdue instalments.")
    if query("SELECT 1 FROM fee_items WHERE instalment_id = ? AND kind = 'late_charge' AND status != 'cancelled'", (iid,), one=True):
        abort(400, "A late charge has already been applied to this instalment.")
    rules = [r for r in query("SELECT * FROM financial_rules WHERE org_id = ? AND kind = 'late_charge'", (org_id(),))
             if pricing.rule_applies(r, app_row["product_id"], app_row["currency"], date.today().isoformat())]
    if not rules:
        abort(400, "No late repayment charge is enabled for this product.")
    rule = rules[0]
    amount = pricing.rule_amount(rule, row["unpaid_minor"])
    fid = execute("INSERT INTO fee_items (org_id, application_id, instalment_id, rule_id, rule_version, kind, name, basis, basis_minor, "
                  "percent, amount_minor, currency, timing, disclosure, payable_when, created_at, updated_at, updated_by) "
                  "VALUES (?,?,?,?,?, 'late_charge', ?,?,?,?,?,?, 'on_overdue_instalment', ?,?,?,?,?)",
                  (org_id(), app_row["id"], iid, rule["id"], rule["version"], rule["name"], rule["basis"], row["unpaid_minor"],
                   rule["percent"], amount, app_row["currency"], rule["disclosure"], PAYABLE_WHEN["on_overdue_instalment"],
                   now(), now(), current_user()["id"]))
    audit.log("fee.late_charge", "application", app_row["id"], fee_id=fid, amount_minor=amount, instalment=row["number"])
    notify(app_row["user_id"], f"A late payment charge of {pricing.money(amount, app_row['currency'])} was added",
           f"/account/applications/{app_row['id']}#fees")
    flash(f"Late charge of {pricing.money(amount, app_row['currency'])} recorded ({rule['name']}).", "success")
    return _back(app_row["id"], "#repayments")


@bp.route("/fees/<int:fid>/<action>", methods=["POST"])
@staff_required
@permission_required("manage_fees")
def change_fee(fid, action):
    f = _fee(fid)
    reason = request.form.get("reason", "").strip()[:500]
    ref = request.form.get("payment_reference", "").strip()[:120]
    allowed = {"paid": ("pending",), "waive": ("pending",), "cancel": ("pending",), "refund": ("paid",)}
    if action not in allowed or f["status"] not in allowed[action]:
        abort(400)
    if action == "paid":
        if f["timing"] in ("deducted_from_disbursement", "added_to_loan", "with_first_instalment"):
            abort(400, "This fee is collected through the payment or instalments and updates automatically.")
        source = request.form.get("confirmation_source", "")
        if not ref or source not in CONFIRMATION_SOURCES:
            flash("Enter the payment reference and how the payment was confirmed.", "error")
            return _back(f["application_id"], "#fees")
        execute("UPDATE fee_items SET status = 'paid', paid_at = ?, payment_reference = ?, payment_source = ?, updated_by = ?, updated_at = ? "
                "WHERE id = ?", (now(), ref, source, current_user()["id"], now(), fid))
        letters.generate_record("fee_receipt", get_app(f["application_id"]), current_user(), ref_id=fid)
    else:
        if len(reason) < 5:
            flash("Record the reason.", "error")
            return _back(f["application_id"], "#fees")
        new = {"waive": "waived", "cancel": "cancelled", "refund": "refunded"}[action]
        if action == "refund" and not ref:
            flash("Enter the refund payment reference.", "error")
            return _back(f["application_id"], "#fees")
        execute("UPDATE fee_items SET status = ?, status_reason = ?, payment_reference = CASE WHEN ? != '' THEN ? ELSE payment_reference END, "
                "updated_by = ?, updated_at = ? WHERE id = ?", (new, reason, ref, ref, current_user()["id"], now(), fid))
    audit.log(f"fee.{action}", "application", f["application_id"], fee_id=fid)
    flash("Fee record updated.", "success")
    return _back(f["application_id"], "#fees")


# ---------------------------------------------------------------- organisation-wide lists
@bp.route("/disbursements")
@staff_required
@permission_required("view_applications")
def disbursements():
    status = request.args.get("status", "")
    rows = query("SELECT d.*, a.company_name, u.full_name FROM disbursements d JOIN applications a ON a.id = d.application_id "
                 "JOIN users u ON u.id = a.user_id WHERE d.org_id = ? " + ("AND d.status = ? " if status else "") +
                 "ORDER BY d.id DESC LIMIT 300", (org_id(), status) if status else (org_id(),))
    return render_template("admin/disbursements.html", rows=rows, status=status)


@bp.route("/repayments")
@staff_required
@permission_required("view_applications")
def repayments():
    loans = query("SELECT s.application_id, a.company_name, u.full_name, s.currency FROM repayment_schedules s "
                  "JOIN applications a ON a.id = s.application_id JOIN users u ON u.id = a.user_id WHERE s.org_id = ? AND s.status = 'active' "
                  "ORDER BY s.id DESC LIMIT 300", (org_id(),))
    rows = []
    for l in loans:
        pos = loan_position(l["application_id"])
        rows.append(dict(l) | {"pos": pos})
    recent = query("SELECT r.*, a.company_name FROM repayments r JOIN applications a ON a.id = r.application_id WHERE r.org_id = ? "
                   "ORDER BY r.id DESC LIMIT 50", (org_id(),))
    return render_template("admin/repayments.html", rows=rows, recent=recent)


@bp.route("/fees")
@staff_required
@permission_required("view_applications")
def fees():
    status = request.args.get("status", "")
    rows = query("SELECT f.*, a.company_name FROM fee_items f JOIN applications a ON a.id = f.application_id WHERE f.org_id = ? "
                 + ("AND f.status = ? " if status else "") + "ORDER BY f.id DESC LIMIT 300", (org_id(), status) if status else (org_id(),))
    return render_template("admin/fees.html", rows=rows, status=status, RULE_TIMINGS=RULE_TIMINGS)
