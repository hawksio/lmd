"""Document verification: requirements, the reviewer queue, decisions and history.

Verifying a document never approves a loan; it only records that the evidence was checked.
"""
from flask import Blueprint, abort, flash, redirect, render_template, request, url_for

from . import audit
from .applications import notify, notify_staff, reference
from .db import execute, now, query, transaction
from .options import DOC_STATUSES, DOC_TYPES
from .security import can, current_user, permission_required, staff_required
from .tenancy import org_id, ref_prefix

bp = Blueprint("documents", __name__, url_prefix="/admin/documents")

REVIEW_ACTIONS = {"under_review", "verified", "rejected", "replacement_required", "expired"}


def requirements(app_row):
    """What the application still needs: the product's required types plus open requests, with the newest document
    for each type. Status 'not_submitted' where nothing has been uploaded."""
    product = query("SELECT required_docs FROM loan_products WHERE id = ?", (app_row["product_id"],), one=True) \
        if app_row["product_id"] else None
    needed = [t for t in (product["required_docs"].split(",") if product else []) if t.strip()]
    requests = query("SELECT * FROM document_requests WHERE application_id = ? AND status = 'open' ORDER BY id", (app_row["id"],))
    for r in requests:
        if r["doc_type"] not in needed:
            needed.append(r["doc_type"])
    out = []
    for doc_type in needed:
        latest = query("SELECT * FROM documents WHERE application_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1",
                       (app_row["id"], doc_type), one=True)
        note = next((r["note"] for r in requests if r["doc_type"] == doc_type), "")
        out.append({"doc_type": doc_type, "label": DOC_TYPES.get(doc_type, doc_type), "request_note": note,
                    "status": latest["review_status"] if latest else "not_submitted", "document": latest})
    return out


def outstanding(app_row):
    return [r for r in requirements(app_row) if r["status"] != "verified"]


def record_review(doc, status, reviewer, internal_note="", customer_note="", expires_on=None):
    with transaction():
        execute("UPDATE documents SET review_status = ?, internal_note = ?, customer_note = ?, review_note = ?, reviewed_by = ?, "
                "reviewed_at = ?, expires_on = COALESCE(?, expires_on) WHERE id = ? AND org_id = ?",
                (status, internal_note, customer_note, customer_note, reviewer["id"], now(), expires_on, doc["id"], org_id()))
        execute("INSERT INTO document_reviews (org_id, document_id, status, internal_note, customer_note, reviewer_id, created_at) "
                "VALUES (?,?,?,?,?,?,?)", (org_id(), doc["id"], status, internal_note, customer_note, reviewer["id"], now()))
        if status == "verified":
            execute("UPDATE document_requests SET status = 'fulfilled', closed_at = ? WHERE application_id = ? AND doc_type = ? "
                    "AND status = 'open'", (now(), doc["application_id"], doc["doc_type"]))
        audit.log("document.reviewed", "document", doc["id"], application_id=doc["application_id"], result=status)


def _doc(doc_id):
    doc = query("SELECT d.*, a.user_id, a.id AS app_id, a.status AS app_status FROM documents d "
                "JOIN applications a ON a.id = d.application_id WHERE d.id = ? AND d.org_id = ? AND a.status != 'draft'",
                (doc_id, org_id()), one=True)
    if doc is None:
        abort(404)
    return doc


@bp.route("/")
@staff_required
@permission_required("view_applications")
def queue():
    status = request.args.get("status", "awaiting")
    doc_type = request.args.get("type", "")
    q = request.args.get("q", "").strip()[:100]
    page = max(1, request.args.get("page", 1, type=int))
    where, args = ["d.org_id = ?", "a.status != 'draft'"], [org_id()]
    if status == "awaiting":
        where.append("d.review_status IN ('submitted', 'under_review')")
    elif status in DOC_STATUSES:
        where.append("d.review_status = ?")
        args.append(status)
    if doc_type in DOC_TYPES:
        where.append("d.doc_type = ?")
        args.append(doc_type)
    if q:
        where.append("(u.full_name LIKE ? OR u.email LIKE ? OR a.company_name LIKE ? OR printf(? || '-%06d', a.id) LIKE ?)")
        args += [f"%{q}%"] * 3 + [ref_prefix(), f"%{q}%"]
    clause = " AND ".join(where)
    base = (f"FROM documents d JOIN applications a ON a.id = d.application_id JOIN users u ON u.id = a.user_id "
            f"JOIN users up ON up.id = d.uploaded_by WHERE {clause} AND up.role = 'client'")
    total = query(f"SELECT COUNT(*) n {base}", args, one=True)["n"]
    rows = query(f"SELECT d.*, a.company_name, u.full_name {base} ORDER BY d.created_at ASC, d.id ASC LIMIT 25 OFFSET ?",
                 args + [(page - 1) * 25])
    return render_template("admin/documents.html", rows=rows, status=status, doc_type=doc_type, q=q, page=page,
                           pages=max(1, -(-total // 25)), total=total)


@bp.route("/<int:doc_id>/review", methods=["GET", "POST"])
@staff_required
@permission_required("view_applications")
def review(doc_id):
    doc = _doc(doc_id)
    if request.method == "POST":
        if not can("review_documents"):
            abort(403)
        status = request.form.get("review_status", "")
        internal = request.form.get("internal_note", "").strip()[:1000]
        customer = request.form.get("customer_note", "").strip()[:1000]
        expires = request.form.get("expires_on", "").strip() or None
        if status not in REVIEW_ACTIONS:
            abort(400)
        if status in ("rejected", "replacement_required") and not customer:
            flash("Explain to the customer why the document can't be accepted.", "error")
            return redirect(url_for("documents.review", doc_id=doc_id))
        record_review(doc, status, current_user(), internal, customer, expires)
        if status in ("rejected", "replacement_required", "expired"):
            notify(doc["user_id"], f"Please upload a replacement for {DOC_TYPES.get(doc['doc_type'], 'a document')}",
                   f"/account/applications/{doc['app_id']}#documents")
        elif status == "verified":
            notify(doc["user_id"], f"{DOC_TYPES.get(doc['doc_type'], 'Document')} verified", f"/account/applications/{doc['app_id']}#documents")
        flash(f"Document marked as {DOC_STATUSES[status].lower()}. This does not change the application decision.", "success")
        return redirect(request.form.get("next") if request.form.get("next", "").startswith("/admin/") else url_for("documents.queue"))
    history = query("SELECT r.*, u.full_name FROM document_reviews r JOIN users u ON u.id = r.reviewer_id "
                    "WHERE r.document_id = ? ORDER BY r.id DESC", (doc_id,))
    versions = query("SELECT * FROM documents WHERE application_id = ? AND doc_type = ? ORDER BY id DESC", (doc["app_id"], doc["doc_type"]))
    applicant = query("SELECT full_name, email FROM users WHERE id = ?", (doc["user_id"],), one=True)
    app_row = query("SELECT * FROM applications WHERE id = ?", (doc["app_id"],), one=True)
    return render_template("admin/document_review.html", doc=doc, history=history, versions=versions, applicant=applicant,
                           app=app_row)


@bp.route("/request/<int:app_id>", methods=["POST"])
@staff_required
@permission_required("review_documents")
def request_document(app_id):
    app_row = query("SELECT * FROM applications WHERE id = ? AND org_id = ? AND status != 'draft'", (app_id, org_id()), one=True)
    if app_row is None:
        abort(404)
    doc_type = request.form.get("doc_type", "")
    note = request.form.get("note", "").strip()[:1000]
    if doc_type not in DOC_TYPES or not note:
        flash("Choose the type of document and explain what is needed.", "error")
    else:
        rid = execute("INSERT INTO document_requests (org_id, application_id, doc_type, note, requested_by, created_at) "
                      "VALUES (?,?,?,?,?,?)", (org_id(), app_id, doc_type, note, current_user()["id"], now()))
        audit.log("document.requested", "application", app_id, request_id=rid, doc_type=doc_type)
        notify(app_row["user_id"], f"We need: {DOC_TYPES[doc_type]}", f"/account/applications/{app_id}#documents")
        from . import mail
        client = query("SELECT * FROM users WHERE id = ?", (app_row["user_id"],), one=True)
        mail.notify_client_message(app_row, client)
        flash("Request sent to the customer.", "success")
    return redirect(url_for("admin.application", app_id=app_id) + "#documents")


@bp.route("/request/<int:rid>/cancel", methods=["POST"])
@staff_required
@permission_required("review_documents")
def cancel_request(rid):
    r = query("SELECT * FROM document_requests WHERE id = ? AND org_id = ?", (rid, org_id()), one=True)
    if r is None:
        abort(404)
    execute("UPDATE document_requests SET status = 'cancelled', closed_at = ? WHERE id = ?", (now(), rid))
    audit.log("document.request_cancelled", "application", r["application_id"], request_id=rid)
    return redirect(url_for("admin.application", app_id=r["application_id"]) + "#documents")


def on_customer_upload(app_row, doc_id, doc_type, replaced):
    """After a customer upload: tell the reviewers, and mark the replaced document as superseded in its history."""
    if replaced:
        execute("INSERT INTO document_reviews (org_id, document_id, status, internal_note, customer_note, reviewer_id, created_at) "
                "VALUES (?,?,?,?,?,?,?)", (org_id(), replaced["id"], replaced["review_status"],
                                           f"Replaced by document {doc_id}", "", app_row["user_id"], now()))
    notify_staff("review_documents", f"New document to review on {reference(app_row)}: {DOC_TYPES.get(doc_type, doc_type)}",
                 f"/admin/documents/{doc_id}/review")
