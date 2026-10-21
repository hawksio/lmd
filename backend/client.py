"""Client portal: overview, applications, documents, financing, messages, help and privacy.

Every query is scoped to the signed-in client (who belongs to exactly one organisation), so a client can never
reach another client's records: anything else is a 404.
"""
import json
import secrets
import sqlite3

from flask import Blueprint, Response, abort, current_app, flash, redirect, render_template, request, url_for

from . import audit, mail
from .applications import (active_products, application_bundle, private_file_response, product_fields, read_form, record_status,
                           save_upload, send_document)
from .db import execute, now, query, transaction
from .options import CLIENT_CAN_WITHDRAW, DOC_REPLACEABLE, DOC_TYPES, FINAL
from .security import current_user, is_platform_owner, is_staff, login_required
from .tenancy import org_id, ref_prefix

bp = Blueprint("client", __name__, url_prefix="/account")


@bp.before_request
@login_required
def _clients_only():
    # Staff use the admin area; this keeps the two experiences separate.
    if is_platform_owner(current_user()):
        return redirect(url_for("platform.dashboard"))
    if is_staff(current_user()):
        return redirect(url_for("admin.dashboard"))
    return None


def own_application(app_id):
    row = query("SELECT * FROM applications WHERE id = ? AND user_id = ? AND org_id = ?",
                (app_id, current_user()["id"], org_id()), one=True)
    if row is None:
        abort(404)
    return row


@bp.app_context_processor
def _unread():
    user = current_user()
    if user:
        n = query("SELECT COUNT(*) n FROM notifications WHERE user_id = ? AND read_at IS NULL", (user["id"],), one=True)["n"]
        return {"unread_notifications": n}
    return {}


def onboarding(user, apps):
    """The customer's remaining steps, in order, for the progress indicator."""
    from .documents import requirements
    active = next((a for a in apps if a["status"] not in FINAL), None)
    docs_done = bool(active) and active["status"] != "draft" and all(r["status"] in ("submitted", "under_review", "verified")
                                                                     for r in requirements(active))
    steps = [
        ("Confirm your email address", bool(user["email_verified_at"]), None),
        ("Complete your profile", bool(user["full_name"] and user["phone"] and user["address"]), url_for("client.profile")),
        ("Start an application", bool(apps), url_for("client.new_application")),
        ("Submit your application", any(a["status"] != "draft" for a in apps),
         url_for("client.edit_application", app_id=active["id"]) if active and active["status"] == "draft" else None),
        ("Upload the documents requested", docs_done,
         url_for("client.view_application", app_id=active["id"]) + "#documents" if active else None),
    ]
    done = sum(1 for _, ok, _ in steps if ok)
    return {"steps": steps, "done": done, "total": len(steps), "percent": round(100 * done / len(steps))}


@bp.route("/")
def dashboard():
    from .underwriting import financing_summary
    user = current_user()
    apps = query("SELECT * FROM applications WHERE user_id = ? ORDER BY updated_at DESC", (user["id"],))
    notes = query("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5", (user["id"],))
    doc_count = query("SELECT COUNT(*) n FROM documents d JOIN applications a ON a.id = d.application_id WHERE a.user_id = ?",
                      (user["id"],), one=True)["n"]
    funded = [(a, financing_summary(a)) for a in apps if a["status"] not in ("draft",)]
    funded = [(a, s) for a, s in funded if s["approved"]]
    return render_template("client/overview.html", apps=apps, notifications=notes, doc_count=doc_count,
                           progress=onboarding(user, apps), funded=funded)


@bp.route("/applications")
def applications():
    apps = query("SELECT a.*, p.name AS product_name FROM applications a LEFT JOIN loan_products p ON p.id = a.product_id "
                 "WHERE a.user_id = ? ORDER BY a.updated_at DESC", (current_user()["id"],))
    return render_template("client/dashboard.html", apps=apps)


def _save(existing, submitting):
    user = current_user()
    values, errors = read_form(request.form, submitting)
    if submitting and not user["email_verified_at"]:
        errors["form"] = "Please confirm your email address before submitting. We've sent you a link; you can request a new one above."
    if errors:
        return values, errors, None
    stamp = now()
    cols = ", ".join(f"{k} = ?" for k in values)
    with transaction():
        if existing is None:
            key = request.form.get("submission_key", "")[:64] or None
            try:
                app_id = execute(
                    f"INSERT INTO applications (org_id, user_id, created_at, updated_at, submission_key, {', '.join(values)}) "
                    f"VALUES (?, ?, ?, ?, ?, {', '.join('?' for _ in values)})",
                    (org_id(), user["id"], stamp, stamp, key, *values.values()))
            except sqlite3.IntegrityError:
                # The same form was sent twice (double click, refresh): use the application already created.
                dup = query("SELECT id FROM applications WHERE user_id = ? AND submission_key = ?", (user["id"], key), one=True)
                return values, {}, dup["id"]
        else:
            app_id = existing["id"]
            execute(f"UPDATE applications SET {cols}, updated_at = ? WHERE id = ? AND user_id = ?", (*values.values(), stamp, app_id, user["id"]))
        if submitting:
            execute("UPDATE applications SET status = 'submitted', submitted_at = ? WHERE id = ? AND status = 'draft'", (stamp, app_id))
            record_status(app_id, "submitted", user["id"])
            audit.log("application.submitted", "application", app_id)
    if submitting:
        app_row = query("SELECT * FROM applications WHERE id = ?", (app_id,), one=True)
        mail.notify_staff_new_application(app_row, user)
        mail.send_application_received(app_row, user)
        from .applications import notify_staff, reference
        notify_staff("view_applications", f"New application {reference(app_row)}", f"/admin/applications/{app_id}")
    return values, {}, app_id


SUBMITTED_MSG = ("Application submitted. We've emailed you a confirmation. This is not a decision: "
                 "we'll be in touch, and you can follow progress here.")


def _form(values, errors, app, key):
    products = active_products()
    selected = next((p for p in products if p["id"] == (values.get("product_id") or request.args.get("product", type=int))), None)
    try:
        extra = json.loads(values.get("extra_fields") or "{}") if isinstance(values.get("extra_fields"), str) else {}
    except ValueError:
        extra = {}
    return render_template("client/application_form.html", values=values, errors=errors, app=app, submission_key=key,
                           products=products, product=selected, fields=product_fields(selected), extra=extra)


@bp.route("/applications/new", methods=["GET", "POST"])
def new_application():
    values, errors = {}, {}
    if request.method == "POST" and request.form.get("action") in ("save", "submit"):
        submitting = request.form.get("action") == "submit"
        values, errors, app_id = _save(None, submitting)
        if not errors:
            flash(SUBMITTED_MSG if submitting else "Draft saved. You can come back to it any time.", "success")
            return redirect(url_for("client.view_application", app_id=app_id))
    elif request.method == "POST":  # product chosen: redisplay with that product's questions
        values = {k: v for k, v in request.form.items() if k not in ("csrf_token",)}
        values["product_id"] = request.form.get("product_id", type=int)
    key = request.form.get("submission_key") or secrets.token_urlsafe(16)
    return _form(values, errors, None, key)


@bp.route("/applications/<int:app_id>/edit", methods=["GET", "POST"])
def edit_application(app_id):
    app = own_application(app_id)
    if app["status"] != "draft":
        flash("This application has been submitted, so it can no longer be edited. Message the team instead.", "info")
        return redirect(url_for("client.view_application", app_id=app_id))
    values, errors = dict(app), {}
    if request.method == "POST" and request.form.get("action") in ("save", "submit"):
        submitting = request.form.get("action") == "submit"
        values, errors, _ = _save(app, submitting)
        if not errors:
            flash(SUBMITTED_MSG if submitting else "Draft saved.", "success")
            return redirect(url_for("client.view_application", app_id=app_id))
    elif request.method == "POST":
        values = dict(app) | {k: v for k, v in request.form.items() if k != "csrf_token"}
        values["product_id"] = request.form.get("product_id", type=int)
    return _form(values, errors, app, "")


@bp.route("/applications/<int:app_id>")
def view_application(app_id):
    from .documents import requirements
    from .finance import loan_position
    from .underwriting import financing_summary
    app = own_application(app_id)
    docs, notes, history, letters = application_bundle(app_id, include_internal=False)
    execute("UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND link LIKE ?",
            (now(), current_user()["id"], f"/account/applications/{app_id}%"))
    fees = query("SELECT * FROM fee_items WHERE application_id = ? AND status != 'cancelled' ORDER BY id", (app_id,))
    repayments = query("SELECT * FROM repayments WHERE application_id = ? ORDER BY received_on DESC, id DESC", (app_id,))
    receipts = {r["ref_id"]: r["id"] for r in letters if r["kind"] == "receipt"}
    product = query("SELECT * FROM loan_products WHERE id = ?", (app["product_id"],), one=True) if app["product_id"] else None
    return render_template("client/application.html", app=app, docs=docs, notes=notes, history=history, letters=letters,
                           summary=financing_summary(app), position=loan_position(app_id), fees=fees, repayments=repayments,
                           receipts=receipts, requirements=requirements(app), product=product,
                           can_withdraw=app["status"] in CLIENT_CAN_WITHDRAW, final=app["status"] in FINAL,
                           DOC_REPLACEABLE=DOC_REPLACEABLE)


@bp.route("/applications/<int:app_id>/documents", methods=["POST"])
def upload_document(app_id):
    from .documents import on_customer_upload
    app = own_application(app_id)
    if app["status"] in FINAL:
        abort(400, "This application is closed.")
    replaces = request.form.get("replaces", type=int)
    replaced = None
    if replaces:
        replaced = query("SELECT * FROM documents WHERE id = ? AND application_id = ?", (replaces, app_id), one=True)
        if replaced is None or replaced["review_status"] not in DOC_REPLACEABLE:
            abort(400, "Only rejected, expired or replacement-required documents can be replaced.")
    doc_type = replaced["doc_type"] if replaced else request.form.get("doc_type", "other")
    error, doc_id = save_upload(request.files.get("document"), app_id, current_user()["id"], doc_type, replaces)
    if error:
        flash(error, "error")
    else:
        execute("UPDATE applications SET updated_at = ? WHERE id = ?", (now(), app_id))
        if app["status"] != "draft":
            on_customer_upload(app, doc_id, doc_type, replaced)
        flash("Document uploaded. It will be reviewed; uploading a document is not a decision on your application.", "success")
    return redirect(url_for("client.view_application", app_id=app_id) + "#documents")


@bp.route("/documents")
def documents():
    uid = current_user()["id"]
    docs = query("SELECT d.*, a.company_name, a.id AS app_id FROM documents d JOIN applications a ON a.id = d.application_id "
                 "WHERE a.user_id = ? ORDER BY d.created_at DESC", (uid,))
    letters = query("SELECT g.*, a.company_name FROM generated_documents g JOIN applications a ON a.id = g.application_id "
                    "WHERE a.user_id = ? AND g.status = 'issued' ORDER BY g.issued_at DESC", (uid,))
    return render_template("client/documents.html", docs=docs, letters=letters)


@bp.route("/documents/<int:doc_id>")
def download_document(doc_id):
    doc = query("SELECT d.* FROM documents d JOIN applications a ON a.id = d.application_id "
                "WHERE d.id = ? AND a.user_id = ?", (doc_id, current_user()["id"]), one=True)
    return send_document(doc, inline=request.args.get("view") == "1")


@bp.route("/letters/<int:doc_id>")
def download_letter(doc_id):
    doc = query("SELECT g.* FROM generated_documents g JOIN applications a ON a.id = g.application_id "
                "WHERE g.id = ? AND a.user_id = ? AND g.status = 'issued'", (doc_id, current_user()["id"]), one=True)
    if doc is None:
        abort(404)
    audit.log("letter.download", "generated_document", doc_id, application_id=doc["application_id"])
    return private_file_response(current_app.config["GENERATED_DIR"], doc["stored_name"],
                                 f"{ref_prefix()}-{doc['application_id']:06d}-{doc['kind']}-v{doc['version']}.pdf")


@bp.route("/messages")
def messages():
    uid = current_user()["id"]
    notes = query("SELECT n.*, a.company_name FROM notes n JOIN applications a ON a.id = n.application_id "
                  "WHERE a.user_id = ? AND n.visible_to_client = 1 ORDER BY n.created_at DESC, n.id DESC LIMIT 200", (uid,))
    notifications = query("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100", (uid,))
    execute("UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL", (now(), uid))
    return render_template("client/messages.html", notes=notes, notifications=notifications)


@bp.route("/applications/<int:app_id>/messages", methods=["POST"])
def send_message(app_id):
    from .applications import notify_staff, reference
    app = own_application(app_id)
    body = request.form.get("body", "").strip()[:4000]
    if body:
        execute("INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,1,?)",
                (app_id, current_user()["id"], body, now()))
        execute("UPDATE applications SET updated_at = ? WHERE id = ?", (now(), app_id))
        if app["status"] != "draft":
            mail.notify_staff_client_message(app, current_user())
            notify_staff("message_client", f"New message on {reference(app)}", f"/admin/applications/{app_id}#notes")
        flash("Message sent to the team.", "success")
    return redirect(url_for("client.view_application", app_id=app_id) + "#messages")


@bp.route("/applications/<int:app_id>/withdraw", methods=["POST"])
def withdraw(app_id):
    app = own_application(app_id)
    if app["status"] not in CLIENT_CAN_WITHDRAW:
        abort(400, "This application can't be withdrawn.")
    with transaction():
        execute("UPDATE applications SET status = 'withdrawn', updated_at = ? WHERE id = ?", (now(), app_id))
        execute("UPDATE approval_decisions SET status = 'withdrawn' WHERE application_id = ? AND status = 'proposed'", (app_id,))
        record_status(app_id, "withdrawn", current_user()["id"], "Withdrawn by the client")
        audit.log("application.withdrawn", "application", app_id)
    mail.notify_staff_withdrawn(app, current_user())
    flash("Application withdrawn.", "success")
    return redirect(url_for("client.view_application", app_id=app_id))


@bp.route("/applications/<int:app_id>/delete", methods=["POST"])
def delete_draft(app_id):
    app = own_application(app_id)
    if app["status"] != "draft":
        abort(400, "Only drafts can be deleted.")
    if query("SELECT 1 FROM documents WHERE application_id = ?", (app_id,), one=True):
        abort(400, "This draft has documents attached. Please contact us to remove it.")
    execute("DELETE FROM applications WHERE id = ?", (app_id,))
    audit.log("application.draft_deleted", "application", app_id)
    flash("Draft deleted.", "success")
    return redirect(url_for("client.applications"))


@bp.route("/profile", methods=["GET", "POST"])
def profile():
    user = current_user()
    errors = {}
    if request.method == "POST":
        name = request.form.get("full_name", "").strip()
        phone = request.form.get("phone", "").strip()[:40]
        address = request.form.get("address", "").strip()[:500]
        if not 2 <= len(name) <= 120:
            errors["full_name"] = "Enter your full name."
        else:
            execute("UPDATE users SET full_name = ?, phone = ?, address = ? WHERE id = ?", (name, phone, address, user["id"]))
            audit.log("account.details_changed", "user", user["id"])
            flash("Details updated.", "success")
            return redirect(url_for("client.profile"))
    return render_template("client/profile.html", user=dict(user), errors=errors)


@bp.route("/privacy/export")
def export_my_data():
    """Everything we hold about the signed-in customer, as JSON (file contents are listed, not included)."""
    user = current_user()
    apps = query("SELECT * FROM applications WHERE user_id = ?", (user["id"],))
    data = {
        "account": {k: user[k] for k in ("email", "full_name", "phone", "address", "created_at", "email_verified_at",
                                         "marketing_opt_in_at", "last_login_at")},
        "applications": [dict(a) for a in apps],
        "documents": [{k: d[k] for k in ("original_name", "doc_type", "review_status", "customer_note", "created_at", "size_bytes")}
                      for d in query("SELECT d.* FROM documents d JOIN applications a ON a.id = d.application_id WHERE a.user_id = ?",
                                     (user["id"],))],
        "messages": [dict(n) for n in query("SELECT n.application_id, n.body, n.created_at FROM notes n JOIN applications a "
                                            "ON a.id = n.application_id WHERE a.user_id = ? AND n.visible_to_client = 1", (user["id"],))],
        "repayments": [dict(r) for r in query("SELECT r.amount_minor, r.currency, r.received_on, r.provider_reference, r.status FROM "
                                              "repayments r JOIN applications a ON a.id = r.application_id WHERE a.user_id = ?", (user["id"],))],
        "marketing_consent": [dict(c) for c in query("SELECT action, source, wording, created_at FROM marketing_consent_events "
                                                     "WHERE user_id = ?", (user["id"],))],
    }
    for a in data["applications"]:
        a.pop("submission_key", None)
    audit.log("privacy.data_exported", "user", user["id"])
    return Response(json.dumps(data, indent=2, default=str), mimetype="application/json",
                    headers={"Content-Disposition": "attachment; filename=my-data.json", "Cache-Control": "no-store"})


@bp.route("/help")
def help_page():
    from . import ai
    return render_template("client/help.html", ai_enabled=ai.enabled(), DOC_TYPES=DOC_TYPES)
