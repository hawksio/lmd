"""Organisation staff area. Every route checks the staff member's permission on the server (security.PERMISSIONS),
and every query is limited to the signed-in staff member's organisation (tenancy.org_id()).

Work that needs two different people (see underwriting.py and finance.py): issuing approvals at or above the
threshold, and confirming disbursements.
"""
import csv
import hashlib
import io
import json
import secrets
from datetime import date, timedelta

from flask import Blueprint, Response, abort, current_app, flash, redirect, render_template, request, url_for
from werkzeug.security import generate_password_hash

from . import audit, letters, mail
from .applications import application_bundle, notify, private_file_response, reference, save_upload, send_document, set_status
from .db import execute, later, now, query, transaction
from .documents import outstanding, requirements
from .options import (ADMIN_ONLY_FROM, APPROVED, CONFIRMATION_SOURCES, DOC_STATUSES, DOC_TYPES, FEE_STATUSES, LETTER_KINDS,
                      MANUAL_TRANSITIONS, NOTE_REQUIRED, PAYMENT_METHODS, REVIEWING, STATUSES)
from .security import STAFF_ROLES, can, current_user, permission_required, require, revoke_sessions, staff_required
from .tenancy import org_id, ref_prefix, settings

bp = Blueprint("admin", __name__, url_prefix="/admin")
PAGE_SIZE = 25


@bp.before_request
@staff_required
def _staff_only():
    return None


def get_application(app_id):
    row = query("SELECT a.*, u.full_name, u.email, u.phone, u.email_verified_at FROM applications a "
                "JOIN users u ON u.id = a.user_id WHERE a.id = ? AND a.org_id = ?", (app_id, org_id()), one=True)
    if row is None or row["status"] == "draft":
        abort(404)  # drafts are the client's private work until submitted; other organisations' data doesn't exist here
    return row


# Applications needing attention: new and untouched for a day, waiting on a reply, unreviewed documents,
# decisions waiting to be issued, accepted offers, or payments waiting for confirmation.
ATTENTION_SQL = """(
  (a.status = 'submitted' AND a.submitted_at < datetime('now', '-1 day'))
  OR EXISTS (SELECT 1 FROM documents d JOIN users du ON du.id = d.uploaded_by
             WHERE d.application_id = a.id AND d.review_status IN ('submitted', 'under_review') AND du.role = 'client')
  OR (SELECT u2.role FROM notes n2 JOIN users u2 ON u2.id = n2.author_id
      WHERE n2.application_id = a.id ORDER BY n2.created_at DESC, n2.id DESC LIMIT 1) = 'client'
  OR EXISTS (SELECT 1 FROM approval_decisions ad WHERE ad.application_id = a.id AND ad.status = 'proposed')
  OR EXISTS (SELECT 1 FROM disbursements ds WHERE ds.application_id = a.id AND ds.status = 'pending')
)"""


def _date_range():
    """?from=YYYY-MM-DD&to=YYYY-MM-DD (inclusive). Defaults: the last 90 days."""
    try:
        start = date.fromisoformat(request.args.get("from", ""))
    except ValueError:
        start = date.today() - timedelta(days=90)
    try:
        end = date.fromisoformat(request.args.get("to", ""))
    except ValueError:
        end = date.today()
    return start.isoformat(), end.isoformat()


def csv_response(filename, header, rows):
    require("export_data")
    buf = io.StringIO()
    writer = csv.writer(buf)
    writer.writerow(header)
    for r in rows:
        # Neutralise spreadsheet formulas in exported text (CSV injection).
        writer.writerow(["'" + v if isinstance(v, str) and v[:1] in ("=", "+", "-", "@") else v for v in r])
    audit.log("data.exported", "export", None, file=filename, rows=len(rows))
    return Response(buf.getvalue(), mimetype="text/csv",
                    headers={"Content-Disposition": f"attachment; filename={filename}", "Cache-Control": "no-store"})


# ---------------------------------------------------------------- overview
@bp.route("/")
def dashboard():
    o = org_id()
    counts = {r["status"]: r["n"] for r in query("SELECT status, COUNT(*) n FROM applications WHERE org_id = ? GROUP BY status", (o,))}
    attention = query(f"SELECT a.*, u.full_name FROM applications a JOIN users u ON u.id = a.user_id "
                      f"WHERE a.org_id = ? AND a.status NOT IN ('draft', 'declined', 'withdrawn', 'cancelled', 'fully_disbursed') "
                      f"AND {ATTENTION_SQL} ORDER BY a.updated_at ASC LIMIT 10", (o,))
    recent = query("SELECT a.*, u.full_name FROM applications a JOIN users u ON u.id = a.user_id "
                   "WHERE a.org_id = ? AND a.status != 'draft' ORDER BY a.updated_at DESC LIMIT 8", (o,))
    stats = {
        "open_enquiries": query("SELECT COUNT(*) n FROM enquiries WHERE org_id = ? AND handled = 0", (o,), one=True)["n"],
        "clients": query("SELECT COUNT(*) n FROM users WHERE org_id = ? AND role = 'client'", (o,), one=True)["n"],
        "failed_mail": query("SELECT COUNT(*) n FROM email_log WHERE org_id = ? AND status = 'failed'", (o,), one=True)["n"],
        "docs_waiting": query("SELECT COUNT(*) n FROM documents WHERE org_id = ? AND review_status IN ('submitted', 'under_review')",
                              (o,), one=True)["n"],
        "decisions_waiting": query("SELECT COUNT(*) n FROM approval_decisions WHERE org_id = ? AND status = 'proposed'", (o,), one=True)["n"],
        "payments_waiting": query("SELECT COUNT(*) n FROM disbursements WHERE org_id = ? AND status = 'pending'", (o,), one=True)["n"],
    }
    from .platform import usage_warning
    return render_template("admin/dashboard.html", counts=counts, recent=recent, attention=attention, stats=stats,
                           usage=usage_warning(o))


SORTS = {"updated": "a.updated_at", "submitted": "a.submitted_at", "amount": "a.amount", "company": "a.company_name COLLATE NOCASE"}


def _application_filters():
    status = request.args.get("status", "")
    q = request.args.get("q", "").strip()[:100]
    attention = request.args.get("attention") == "1"
    where, args = ["a.org_id = ?", "a.status != 'draft'"], [org_id()]
    if status in STATUSES and status != "draft":
        where.append("a.status = ?")
        args.append(status)
    if q:
        like = f"%{q}%"
        where.append("(a.company_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR printf(? || '-%06d', a.id) LIKE ?)")
        args += [like, like, like, ref_prefix(), like]
    if request.args.get("from") or request.args.get("to"):
        start, end = _date_range()
        where.append("date(a.submitted_at) BETWEEN ? AND ?")
        args += [start, end]
    product = request.args.get("product", type=int)
    if product:
        where.append("a.product_id = ?")
        args.append(product)
    if attention:
        where.append(ATTENTION_SQL)
    return status, q, attention, " AND ".join(where), args


@bp.route("/applications")
@permission_required("view_applications")
def applications():
    status, q, attention, clause, args = _application_filters()
    sort = request.args.get("sort", "updated")
    direction = "ASC" if request.args.get("dir") == "asc" else "DESC"
    page = max(1, request.args.get("page", 1, type=int))
    order = SORTS.get(sort, SORTS["updated"])
    if request.args.get("export") == "csv":
        rows = query(f"SELECT a.*, u.full_name, u.email FROM applications a JOIN users u ON u.id = a.user_id WHERE {clause} "
                     f"ORDER BY {order} {direction}", args)
        return csv_response("applications.csv", ["reference", "business", "applicant", "email", "status", "currency",
                                                 "requested", "term_months", "submitted_at", "updated_at"],
                            [[reference(r), r["company_name"], r["full_name"], r["email"], STATUSES[r["status"]], r["currency"],
                              r["amount"], r["term_months"], r["submitted_at"], r["updated_at"]] for r in rows])
    total = query(f"SELECT COUNT(*) n FROM applications a JOIN users u ON u.id = a.user_id WHERE {clause}", args, one=True)["n"]
    rows = query(f"SELECT a.*, u.full_name, u.email, {ATTENTION_SQL} AS needs_attention FROM applications a "
                 f"JOIN users u ON u.id = a.user_id WHERE {clause} ORDER BY {order} {direction}, a.id DESC "
                 f"LIMIT ? OFFSET ?", args + [PAGE_SIZE, (page - 1) * PAGE_SIZE])
    products = query("SELECT id, name FROM loan_products WHERE org_id = ? ORDER BY name", (org_id(),))
    return render_template("admin/applications.html", apps=rows, status=status, q=q, sort=sort, dir=direction.lower(),
                           attention=attention, page=page, pages=max(1, -(-total // PAGE_SIZE)), total=total, products=products)


@bp.route("/approvals")
@permission_required("view_applications")
def approvals():
    o = org_id()
    proposed = query("SELECT d.*, a.company_name, u.full_name AS proposer FROM approval_decisions d JOIN applications a ON a.id = d.application_id "
                     "JOIN users u ON u.id = d.proposed_by WHERE d.org_id = ? AND d.status = 'proposed' ORDER BY d.proposed_at", (o,))
    underwriting = query("SELECT a.*, u.full_name FROM applications a JOIN users u ON u.id = a.user_id WHERE a.org_id = ? "
                         "AND a.status IN ('underwriting', 'document_verification', 'initial_review') ORDER BY a.submitted_at", (o,))
    accepted = query("SELECT a.*, u.full_name, oa.accepted_at FROM offer_acceptances oa JOIN approval_decisions d ON d.id = oa.decision_id "
                     "JOIN applications a ON a.id = d.application_id JOIN users u ON u.id = a.user_id WHERE a.org_id = ? "
                     "AND d.status = 'issued' AND a.status IN ('partially_approved', 'fully_approved', 'conditionally_approved') "
                     "ORDER BY oa.accepted_at", (o,))
    return render_template("admin/approvals.html", proposed=proposed, underwriting=underwriting, accepted=accepted,
                           me=current_user()["id"])


# ---------------------------------------------------------------- the application workspace
def render_workspace(app, decision_form=None, preview=None, decision_errors=None):
    from .finance import active_schedule, loan_position
    from .underwriting import conditions, current_decision, decisions, financing_summary, threshold_for
    app_id = app["id"]
    docs, notes, history, generated = application_bundle(app_id, include_internal=True)
    product = query("SELECT * FROM loan_products WHERE id = ? AND org_id = ?", (app["product_id"], org_id()), one=True) \
        if app["product_id"] else None
    products = query("SELECT * FROM loan_products WHERE org_id = ? AND active = 1 ORDER BY name", (org_id(),))
    payments = query("SELECT d.*, r.full_name AS recorded_name, c.full_name AS confirmed_name FROM disbursements d "
                     "JOIN users r ON r.id = d.recorded_by LEFT JOIN users c ON c.id = d.confirmed_by "
                     "WHERE d.application_id = ? AND d.org_id = ? ORDER BY d.id DESC", (app_id, org_id()))
    staff = query("SELECT id, full_name FROM users WHERE org_id = ? AND role IN ('admin', 'underwriter', 'document_reviewer') "
                  "AND is_active = 1 ORDER BY full_name", (org_id(),))
    allowed = sorted(MANUAL_TRANSITIONS.get(app["status"], set()), key=list(STATUSES).index)
    if app["status"] in ADMIN_ONLY_FROM and current_user()["role"] != "admin":
        allowed = []
    trail = query("SELECT l.*, u.full_name FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id WHERE l.org_id = ? AND "
                  "((l.target_type = 'application' AND l.target_id = ?) OR l.detail LIKE ?) ORDER BY l.id DESC LIMIT 40",
                  (org_id(), app_id, f'%"application_id": {app_id}%')) if can("view_audit") else []
    decision = current_decision(app_id)
    all_decisions = decisions(app_id)
    accepted = query("SELECT * FROM offer_acceptances WHERE decision_id = ?", (decision["id"],), one=True) if decision else None
    requests_ = query("SELECT r.*, u.full_name FROM document_requests r JOIN users u ON u.id = r.requested_by "
                      "WHERE r.application_id = ? AND r.org_id = ? ORDER BY r.id DESC", (app_id, org_id()))
    fees = query("SELECT * FROM fee_items WHERE application_id = ? AND org_id = ? ORDER BY id", (app_id, org_id()))
    repayments = query("SELECT r.*, u.full_name FROM repayments r JOIN users u ON u.id = r.recorded_by "
                       "WHERE r.application_id = ? AND r.org_id = ? ORDER BY r.id DESC", (app_id, org_id()))
    try:
        extra = json.loads(app["extra_fields"] or "{}")
    except ValueError:
        extra = {}
    from .applications import product_fields
    return render_template("admin/application.html", app=app, docs=docs, notes=notes, history=history, generated=generated,
                           payments=payments, staff_members=staff, allowed=allowed, trail=trail, product=product,
                           products=products, decision=decision, decisions=all_decisions, accepted=accepted,
                           conditions=conditions(decision["id"]) if decision else [], requirements=requirements(app),
                           outstanding=outstanding(app), doc_requests=requests_, summary=financing_summary(app),
                           schedule=active_schedule(app_id), position=loan_position(app_id), fees=fees, repayments=repayments,
                           decision_form=decision_form or {}, preview=preview, decision_errors=decision_errors or {},
                           extra=extra, extra_fields=product_fields(product), NOTE_REQUIRED=NOTE_REQUIRED,
                           LETTER_KINDS={k: v for k, v in LETTER_KINDS.items() if k in letters.STAFF_WRITTEN},
                           DOC_STATUSES=DOC_STATUSES, FEE_STATUSES=FEE_STATUSES, PAYMENT_METHODS=PAYMENT_METHODS,
                           CONFIRMATION_SOURCES=CONFIRMATION_SOURCES, settings=settings(), me=current_user()["id"],
                           can_decide=app["status"] in (REVIEWING | APPROVED | {"agreement_issued"}),
                           threshold=threshold_for(product))


@bp.route("/applications/<int:app_id>")
@permission_required("view_applications")
def application(app_id):
    return render_workspace(get_application(app_id))


def _back(app_id, anchor=""):
    return redirect(url_for("admin.application", app_id=app_id) + anchor)


@bp.route("/applications/<int:app_id>/status", methods=["POST"])
@permission_required("change_status")
def change_status(app_id):
    app = get_application(app_id)
    new = request.form.get("status", "")
    if new not in STATUSES or new == "draft":
        abort(400, "Unknown status.")
    message = request.form.get("message", "").strip()[:4000]
    reason = request.form.get("reason", "").strip()[:2000]
    if new == app["status"]:
        if message:
            execute("INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,1,?)",
                    (app_id, current_user()["id"], message, now()))
            notify(app["user_id"], f"New message about {reference(app)}", f"/account/applications/{app_id}")
        flash("Status unchanged." + (" Message added to the application." if message else ""), "success")
        return _back(app_id)
    if new not in MANUAL_TRANSITIONS.get(app["status"], set()):
        abort(400, f"An application can't move from {STATUSES[app['status']]} to {STATUSES[new]} by hand.")
    if app["status"] in ADMIN_ONLY_FROM and current_user()["role"] != "admin":
        abort(403)
    if (new in NOTE_REQUIRED or app["status"] in ADMIN_ONLY_FROM) and not reason:
        flash(f"Record the reason for this decision before setting {STATUSES[new]}.", "error")
        return _back(app_id)
    if new == "agreement_signed":
        from .underwriting import current_decision
        if not query("SELECT 1 FROM generated_documents WHERE application_id = ? AND kind = 'agreement' AND status = 'issued'",
                     (app_id,), one=True):
            abort(400, "No agreement has been issued for this application.")
        from .finance import create_fee_items
        decision = current_decision(app_id)
        with transaction():
            create_fee_items(app, decision)
    if new in ("underwriting", "info_requested") and app["status"] in APPROVED | {"agreement_issued"}:
        # Returning an approved application for review withdraws the issued decision; the history keeps it.
        execute("UPDATE approval_decisions SET status = 'superseded' WHERE application_id = ? AND status = 'issued'", (app_id,))
        execute("UPDATE generated_documents SET status = 'superseded' WHERE application_id = ? AND kind IN ('agreement', 'approval') "
                "AND status IN ('issued', 'draft')", (app_id,))
    set_status(app, new, current_user(), note=reason, message=message)
    flash(f"Status set to {STATUSES[new]} and the client has been emailed." + (" Message added." if message else ""), "success")
    return _back(app_id)


@bp.route("/applications/<int:app_id>/assign", methods=["POST"])
@permission_required("change_status")
def assign(app_id):
    get_application(app_id)
    staff_id = request.form.get("assigned_to", type=int)
    if staff_id and not query("SELECT 1 FROM users WHERE id = ? AND org_id = ? AND role IN ('admin', 'underwriter', 'document_reviewer')",
                              (staff_id, org_id()), one=True):
        abort(400)
    execute("UPDATE applications SET assigned_to = ? WHERE id = ? AND org_id = ?", (staff_id or None, app_id, org_id()))
    if staff_id:
        notify(staff_id, f"Application {reference(get_application(app_id))} has been assigned to you", f"/admin/applications/{app_id}")
    audit.log("application.assigned", "application", app_id, assigned_to=staff_id)
    flash("Assignment saved.", "success")
    return _back(app_id)


@bp.route("/applications/<int:app_id>/notes", methods=["POST"])
@permission_required("view_applications")
def add_note(app_id):
    app = get_application(app_id)
    body = request.form.get("body", "").strip()[:4000]
    visible = 1 if request.form.get("visible_to_client") else 0
    require("message_client" if visible else "internal_note")
    if body:
        execute("INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,?,?)",
                (app_id, current_user()["id"], body, visible, now()))
        execute("UPDATE applications SET updated_at = ? WHERE id = ?", (now(), app_id))
        if visible:
            notify(app["user_id"], f"New message about {reference(app)}", f"/account/applications/{app_id}")
            mail.notify_client_message(app, {"email": app["email"], "full_name": app["full_name"]})
        audit.log("application.message" if visible else "application.internal_note", "application", app_id)
        flash("Message sent to the client." if visible else "Internal note added.", "success")
    return _back(app_id, "#notes")


# ---------------------------------------------------------------- documents
@bp.route("/applications/<int:app_id>/documents", methods=["POST"])
@permission_required("upload_documents")
def upload_document(app_id):
    get_application(app_id)
    error, _doc = save_upload(request.files.get("document"), app_id, current_user()["id"], request.form.get("doc_type", "other"))
    flash(error or "Document uploaded.", "error" if error else "success")
    return _back(app_id, "#documents")


@bp.route("/documents/<int:doc_id>")
@permission_required("view_documents")
def download_document(doc_id):
    doc = query("SELECT d.* FROM documents d JOIN applications a ON a.id = d.application_id "
                "WHERE d.id = ? AND d.org_id = ? AND a.status != 'draft'", (doc_id, org_id()), one=True)
    return send_document(doc, inline=request.args.get("view") == "1")


# ---------------------------------------------------------------- generated letters and agreements
@bp.route("/applications/<int:app_id>/letters", methods=["POST"])
@permission_required("generate_documents")
def generate_letter(app_id):
    app = get_application(app_id)
    kind = request.form.get("kind", "")
    if kind not in letters.STAFF_WRITTEN:
        abort(400)
    extra = request.form.get("body", "").strip()[:4000]
    if kind == "info_request" and not extra:
        flash("Describe the information you need before generating the request.", "error")
        return _back(app_id, "#letters")
    try:
        doc_id = letters.generate(kind, app, current_user(), extra)
    except ValueError as exc:
        flash(str(exc), "error")
        return _back(app_id, "#letters")
    audit.log("letter.generated", "generated_document", doc_id, application_id=app_id, kind=kind)
    flash("Draft generated. Check it, then issue it to make it visible to the client.", "success")
    return _back(app_id, "#letters")


@bp.route("/letters/<int:doc_id>")
@permission_required("view_documents")
def download_letter(doc_id):
    doc = query("SELECT * FROM generated_documents WHERE id = ? AND org_id = ?", (doc_id, org_id()), one=True)
    if doc is None:
        abort(404)
    audit.log("letter.download", "generated_document", doc_id, application_id=doc["application_id"])
    return private_file_response(current_app.config["GENERATED_DIR"], doc["stored_name"],
                                 f"{ref_prefix()}-{doc['application_id']:06d}-{doc['kind']}-v{doc['version']}"
                                 f"{'-DRAFT' if doc['status'] == 'draft' else ''}.pdf")


@bp.route("/letters/<int:doc_id>/issue", methods=["POST"])
@permission_required("issue_documents")
def issue_letter(doc_id):
    doc = query("SELECT * FROM generated_documents WHERE id = ? AND org_id = ?", (doc_id, org_id()), one=True)
    if doc is None or doc["status"] != "draft":
        abort(404)
    app = get_application(doc["application_id"])
    if not request.form.get("confirm"):
        flash("Tick the box to confirm you have checked the document.", "error")
        return _back(app["id"], "#letters")
    try:
        letters.issue(doc, app, current_user())
    except ValueError as exc:
        flash(str(exc), "error")
        return _back(app["id"], "#letters")
    audit.log("letter.issued", "generated_document", doc_id, application_id=app["id"], kind=doc["kind"])
    client = {"email": app["email"], "full_name": app["full_name"]}
    notify(app["user_id"], f"New document: {doc['title']}", f"/account/applications/{app['id']}#letters")
    if doc["kind"] == "agreement" and app["status"] in APPROVED:
        set_status(app, "agreement_issued", current_user(), note=f"Agreement v{doc['version']} issued", email=False)
    mail.notify_document_issued(app, client, doc["title"])
    flash("Document issued. The client can now see it and has been emailed.", "success")
    return _back(app["id"], "#letters")


@bp.route("/letters")
@permission_required("view_applications")
def letters_list():
    kind = request.args.get("kind", "")
    rows = query("SELECT g.*, a.company_name, u.full_name AS generated_by_name FROM generated_documents g "
                 "JOIN applications a ON a.id = g.application_id JOIN users u ON u.id = g.generated_by WHERE g.org_id = ? "
                 + ("AND g.kind = ? " if kind in LETTER_KINDS else "") + "ORDER BY g.id DESC LIMIT 300",
                 (org_id(), kind) if kind in LETTER_KINDS else (org_id(),))
    return render_template("admin/letters.html", rows=rows, kind=kind, LETTER_KINDS=LETTER_KINDS)


# ---------------------------------------------------------------- enquiries, messages, notifications
@bp.route("/enquiries")
@permission_required("view_enquiries")
def enquiries():
    show = request.args.get("show", "open")
    rows = query("SELECT * FROM enquiries WHERE org_id = ? " + ("" if show == "all" else "AND handled = 0 ") +
                 "ORDER BY created_at DESC LIMIT 300", (org_id(),))
    return render_template("admin/enquiries.html", enquiries=rows, show=show)


def _enquiry(enq_id):
    e = query("SELECT * FROM enquiries WHERE id = ? AND org_id = ?", (enq_id, org_id()), one=True)
    if e is None:
        abort(404)
    return e


@bp.route("/enquiries/<int:enq_id>", methods=["GET", "POST"])
@permission_required("view_enquiries")
def enquiry(enq_id):
    e = _enquiry(enq_id)
    if request.method == "POST":
        require("handle_enquiries")
        body = request.form.get("body", "").strip()[:6000]
        if not body:
            flash("Write a reply first.", "error")
        else:
            with transaction():
                execute("INSERT INTO enquiry_replies (enquiry_id, author_id, body, created_at) VALUES (?,?,?,?)",
                        (enq_id, current_user()["id"], body, now()))
                execute("UPDATE enquiries SET handled = 1, handled_by = ?, handled_at = ? WHERE id = ?",
                        (current_user()["id"], now(), enq_id))
                audit.log("enquiry.replied", "enquiry", enq_id)
            mail.send_enquiry_reply(e, body, current_user())
            flash("Reply sent and the enquiry marked as handled.", "success")
            return redirect(url_for("admin.enquiry", enq_id=enq_id))
    return render_enquiry(e)


def render_enquiry(e, draft=""):
    from . import ai
    replies = query("SELECT r.*, u.full_name FROM enquiry_replies r JOIN users u ON u.id = r.author_id "
                    "WHERE r.enquiry_id = ? ORDER BY r.created_at", (e["id"],))
    return render_template("admin/enquiry.html", e=e, replies=replies, ai_drafts=ai.staff_enabled() and can("use_ai_drafts"),
                           draft=draft)


@bp.route("/enquiries/<int:enq_id>/handled", methods=["POST"])
@permission_required("handle_enquiries")
def enquiry_handled(enq_id):
    _enquiry(enq_id)
    execute("UPDATE enquiries SET handled = 1 - handled, handled_by = ?, handled_at = ? WHERE id = ? AND org_id = ?",
            (current_user()["id"], now(), enq_id, org_id()))
    audit.log("enquiry.toggled", "enquiry", enq_id)
    return redirect(url_for("admin.enquiries", show=request.args.get("show", "open")))


@bp.route("/messages")
@permission_required("view_applications")
def messages():
    rows = query("SELECT a.id, a.company_name, u.full_name, n.body, n.created_at, au.role AS last_role, a.assigned_to FROM applications a "
                 "JOIN users u ON u.id = a.user_id JOIN notes n ON n.id = (SELECT n2.id FROM notes n2 WHERE n2.application_id = a.id "
                 "AND n2.visible_to_client = 1 ORDER BY n2.created_at DESC, n2.id DESC LIMIT 1) JOIN users au ON au.id = n.author_id "
                 "WHERE a.org_id = ? AND a.status != 'draft' ORDER BY (au.role = 'client') DESC, n.created_at DESC LIMIT 200", (org_id(),))
    return render_template("admin/messages.html", rows=rows)


@bp.route("/notifications", methods=["GET", "POST"])
def notifications():
    uid = current_user()["id"]
    if request.method == "POST":
        execute("UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL", (now(), uid))
        return redirect(url_for("admin.notifications"))
    rows = query("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 200", (uid,))
    return render_template("admin/notifications.html", rows=rows)


# ---------------------------------------------------------------- customers
@bp.route("/clients")
@permission_required("view_clients")
def clients():
    q = request.args.get("q", "").strip()[:100]
    page = max(1, request.args.get("page", 1, type=int))
    where, args = "WHERE u.org_id = ? AND u.role = 'client'", [org_id()]
    if q:
        where += " AND (u.full_name LIKE ? OR u.email LIKE ?)"
        args += [f"%{q}%", f"%{q}%"]
    if request.args.get("export") == "csv":
        rows = query(f"SELECT u.* FROM users u {where} ORDER BY u.created_at DESC", args)
        return csv_response("customers.csv", ["name", "email", "phone", "registered", "email_confirmed", "marketing_opt_in"],
                            [[r["full_name"], r["email"], r["phone"], r["created_at"], bool(r["email_verified_at"]),
                              bool(r["marketing_opt_in_at"])] for r in rows])
    total = query(f"SELECT COUNT(*) n FROM users u {where}", args, one=True)["n"]
    rows = query(f"SELECT u.*, COUNT(a.id) AS apps FROM users u LEFT JOIN applications a ON a.user_id = u.id AND a.status != 'draft' "
                 f"{where} GROUP BY u.id ORDER BY u.created_at DESC LIMIT ? OFFSET ?", args + [PAGE_SIZE, (page - 1) * PAGE_SIZE])
    return render_template("admin/clients.html", clients=rows, q=q, page=page, pages=max(1, -(-total // PAGE_SIZE)), total=total)


# ---------------------------------------------------------------- staff accounts
def _active_admins():
    return query("SELECT COUNT(*) n FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", (org_id(),), one=True)["n"]


@bp.route("/staff", methods=["GET", "POST"])
@permission_required("manage_staff")
def staff():
    from .platform import plan_limit
    if request.method == "POST":
        email = request.form.get("email", "").strip().lower()[:200]
        name = request.form.get("full_name", "").strip()[:120]
        role = request.form.get("role", "")
        limit = plan_limit(org_id(), "max_staff")
        count = query("SELECT COUNT(*) n FROM users WHERE org_id = ? AND role != 'client' AND is_active = 1", (org_id(),), one=True)["n"]
        if "@" not in email or len(name) < 2 or role not in STAFF_ROLES:
            flash("Enter a name, a valid email and a role.", "error")
        elif limit is not None and count >= limit:
            flash(f"Your subscription allows {limit} active staff accounts. Deactivate one or change plan.", "error")
        elif query("SELECT 1 FROM users WHERE org_id = ? AND email = ?", (org_id(), email), one=True):
            flash("An account with that email already exists here. Change its role below instead.", "error")
        else:
            invite_staff(org_id(), email, name, role)
            flash(f"Staff account created. {email} has been emailed a link (valid 24 hours) to choose a password.", "success")
        return redirect(url_for("admin.staff"))
    rows = query("SELECT * FROM users WHERE org_id = ? AND role != 'client' ORDER BY is_active DESC, full_name", (org_id(),))
    return render_template("admin/staff.html", rows=rows, ROLES=STAFF_ROLES, limit=plan_limit(org_id(), "max_staff"))


def invite_staff(org, email, name, role):
    """Create a staff account with an unusable password and email a one-time link to set one."""
    uid = execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at) VALUES (?,?,?,?,?,?)",
                  (org, email, generate_password_hash(secrets.token_urlsafe(32)), name, role, now()))
    token = secrets.token_urlsafe(32)
    execute("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)",
            (uid, hashlib.sha256(token.encode()).hexdigest(), later(hours=24), now()))
    mail.send(email, "Your staff account", "staff_invite", user={"full_name": name},
              link=f"{mail.base_url()}/account/reset/{token}", role=STAFF_ROLES[role])
    audit.log("staff.created", "user", uid, role=role)
    return uid


@bp.route("/staff/<int:uid>", methods=["POST"])
@permission_required("manage_staff")
def update_staff(uid):
    user = query("SELECT * FROM users WHERE id = ? AND org_id = ? AND role != 'client'", (uid, org_id()), one=True)
    if user is None:
        abort(404)
    if uid == current_user()["id"]:
        abort(400, "You can't change your own role or deactivate yourself. Ask another administrator.")
    action = request.form.get("action")
    if action == "role":
        role = request.form.get("role", "")
        if role not in STAFF_ROLES:
            abort(400)
        if user["role"] == "admin" and role != "admin" and _active_admins() <= 1:
            abort(400, "There must always be at least one active administrator.")
        execute("UPDATE users SET role = ? WHERE id = ?", (role, uid))
        revoke_sessions(uid)  # new permissions take effect at the next sign-in
        audit.log("staff.role_changed", "user", uid, old=user["role"], new=role)
        flash("Role changed. They will need to sign in again.", "success")
    elif action in ("deactivate", "activate"):
        if action == "deactivate" and user["role"] == "admin" and _active_admins() <= 1:
            abort(400, "There must always be at least one active administrator.")
        execute("UPDATE users SET is_active = ? WHERE id = ?", (1 if action == "activate" else 0, uid))
        revoke_sessions(uid)
        audit.log(f"staff.{action}d", "user", uid)
        flash("Account updated.", "success")
    else:
        abort(400)
    return redirect(url_for("admin.staff"))


# ---------------------------------------------------------------- oversight
@bp.route("/audit")
@permission_required("view_audit")
def audit_log():
    event = request.args.get("event", "").strip()[:60]
    page = max(1, request.args.get("page", 1, type=int))
    where, args = "WHERE l.org_id = ?", [org_id()]
    if event:
        where += " AND l.event LIKE ?"
        args.append(f"{event}%")
    if request.args.get("export") == "csv":
        rows = query(f"SELECT l.*, u.email FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id {where} ORDER BY l.id", args)
        return csv_response("audit-log.csv", ["id", "created_at", "actor", "role", "event", "target_type", "target_id", "detail", "ip"],
                            [[r["id"], r["created_at"], r["email"], r["actor_role"], r["event"], r["target_type"], r["target_id"],
                              r["detail"], r["ip"]] for r in rows])
    total = query(f"SELECT COUNT(*) n FROM audit_log l {where}", args, one=True)["n"]
    rows = query(f"SELECT l.*, u.full_name, u.email FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id {where} "
                 f"ORDER BY l.id DESC LIMIT 50 OFFSET ?", args + [(page - 1) * 50])
    ok, checked, bad = audit.verify_chain()
    return render_template("admin/audit.html", rows=rows, event=event, page=page, pages=max(1, -(-total // 50)),
                           chain_ok=ok, checked=checked, bad=bad)


@bp.route("/emails")
@permission_required("view_audit")
def email_log():
    status = request.args.get("status", "")
    rows = query("SELECT * FROM email_log WHERE org_id = ? " + ("AND status = ? " if status else "") + "ORDER BY id DESC LIMIT 200",
                 (org_id(), status) if status else (org_id(),))
    return render_template("admin/emails.html", rows=rows, status=status)
