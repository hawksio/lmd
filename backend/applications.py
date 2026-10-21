"""Application form validation, status changes and document storage, shared by client and admin views."""
import hashlib
import io
import json
import os
import re
import secrets

from flask import abort, current_app, send_file
from werkzeug.utils import secure_filename

from . import audit, mail
from .db import execute, now, query, transaction
from .options import (ALLOWED_UPLOADS, BUSINESS_TYPES, CURRENCIES, DOC_TYPES, FINANCE_TYPES, ROLES_IN_BUSINESS, STATUSES,
                      YEARS_TRADING)
from .tenancy import current_org, org_id, ref_prefix

FIELDS = ["company_name", "company_number", "business_type", "industry", "years_trading",
          "annual_turnover", "address", "finance_type", "amount", "term_months", "purpose", "role_in_business"]
REQUIRED = ["company_name", "business_type", "industry", "years_trading", "annual_turnover",
            "address", "finance_type", "amount", "term_months", "purpose"]
FIELD_TYPES = {"text", "number", "select", "yesno"}


def _int(value):
    value = (value or "").replace(",", "").replace("£", "").replace("€", "").replace("$", "").strip()
    return int(value) if value.isdigit() and len(value) <= 12 else None


def active_products():
    return query("SELECT * FROM loan_products WHERE org_id = ? AND active = 1 ORDER BY name", (org_id(),))


def product_fields(product):
    try:
        fields = json.loads(product["form_fields"] or "[]") if product else []
    except ValueError:
        return []
    return [f for f in fields if isinstance(f, dict) and f.get("key") and f.get("type") in FIELD_TYPES]


def read_form(form, submitting):
    """Return (values, errors). Drafts are saved with whatever is valid; submission needs everything.

    When the organisation has loan products, the chosen product's currency, amount and term limits and extra questions
    apply. Without products, broad general limits apply.
    """
    v = {f: (form.get(f) or "").strip() for f in FIELDS}
    errors = {}
    for f in ("annual_turnover", "amount", "term_months"):
        raw = v[f]
        v[f] = _int(raw)
        if raw and v[f] is None:
            errors[f] = "Enter a whole number."
    for f, options in (("business_type", BUSINESS_TYPES), ("finance_type", FINANCE_TYPES),
                       ("years_trading", YEARS_TRADING), ("role_in_business", ROLES_IN_BUSINESS)):
        if v[f] and v[f] not in options:
            errors[f] = "Choose an option."
            v[f] = ""
    products = active_products()
    product = None
    pid = form.get("product_id", type=int) if hasattr(form, "getlist") else None
    if products:
        product = next((p for p in products if p["id"] == pid), None)
        if pid and product is None:
            errors["product_id"] = "Choose one of the products offered."
    v["product_id"] = product["id"] if product else None
    allowed = [c.strip() for c in product["currencies"].split(",")] if product else list(CURRENCIES)
    currency = (form.get("currency") or (allowed[0] if product else current_org()["default_currency"])).upper()
    if currency not in allowed:
        errors["currency"] = "Choose a currency offered for this product."
        currency = allowed[0]
    v["currency"] = currency
    if product:
        if v["amount"] is not None and not product["min_amount_minor"] <= v["amount"] * 100 <= product["max_amount_minor"]:
            errors["amount"] = (f"Enter an amount between {product['min_amount_minor'] // 100:,} and "
                                f"{product['max_amount_minor'] // 100:,}.")
        if v["term_months"] is not None and not product["min_term_months"] <= v["term_months"] <= product["max_term_months"]:
            errors["term_months"] = f"Enter a term between {product['min_term_months']} and {product['max_term_months']} months."
        v["finance_type"] = product["finance_type"] if product["finance_type"] in FINANCE_TYPES else (v["finance_type"] or "loans")
    else:
        if v["amount"] is not None and not 1000 <= v["amount"] <= 5_000_000:
            errors["amount"] = "Enter an amount between 1,000 and 5,000,000."
        if v["term_months"] is not None and not 3 <= v["term_months"] <= 120:
            errors["term_months"] = "Enter a term between 3 and 120 months."
    if v["annual_turnover"] is not None and v["annual_turnover"] > 10_000_000_000:
        errors["annual_turnover"] = "Check the turnover figure."
    v["company_number"] = v["company_number"].upper().strip()
    if v["company_number"] and not re.fullmatch(r"[A-Z0-9 /-]{2,30}", v["company_number"]):
        errors["company_number"] = "Use letters, numbers, spaces, / or - only."
    for f in ("company_name", "industry"):
        v[f] = v[f][:200]
    v["address"] = v["address"][:500]
    v["purpose"] = v["purpose"][:2000]
    v["consent"] = 1 if form.get("consent") else 0
    extra = {}
    for f in product_fields(product):
        raw = (form.get(f"x_{f['key']}") or "").strip()[:500]
        if f["type"] == "number" and raw and _int(raw) is None:
            errors[f"x_{f['key']}"] = "Enter a whole number."
        if f["type"] == "select" and raw and raw not in f.get("options", []):
            errors[f"x_{f['key']}"] = "Choose an option."
        if f["type"] == "yesno" and raw and raw not in ("yes", "no"):
            errors[f"x_{f['key']}"] = "Choose yes or no."
        if submitting and f.get("required") and not raw:
            errors[f"x_{f['key']}"] = "Required to submit."
        extra[f["key"]] = raw
    v["extra_fields"] = json.dumps(extra)
    if submitting:
        for f in REQUIRED:
            if v[f] in ("", None) and f not in errors:
                errors[f] = "Required to submit."
        if products and not product and "product_id" not in errors:
            errors["product_id"] = "Choose a loan product."
        if v["business_type"] in ("ltd", "llp") and not v["company_number"] and "company_number" not in errors:
            errors["company_number"] = "Required for limited companies and LLPs."
        if not v["consent"]:
            errors["consent"] = "We need your consent to assess the application."
    return v, errors


def reference(app_row):
    return f"{ref_prefix()}-{app_row['id']:06d}"


def record_status(app_id, status, user_id, note=""):
    execute("INSERT INTO status_history (application_id, status, changed_by, note, created_at) VALUES (?,?,?,?,?)",
            (app_id, status, user_id, note[:2000], now()))


def notify(user_id, message, link=""):
    execute("INSERT INTO notifications (user_id, message, link, created_at) VALUES (?,?,?,?)",
            (user_id, message[:300], link, now()))


def notify_staff(permission, message, link=""):
    """In-portal notification for every active staff member of this organisation who holds `permission`."""
    from .security import PERMISSIONS
    roles = sorted(PERMISSIONS[permission])
    for u in query(f"SELECT id FROM users WHERE org_id = ? AND is_active = 1 AND role IN ({','.join('?' * len(roles))})",
                   [org_id(), *roles]):
        notify(u["id"], message, link)


def set_status(app_row, new, actor, note="", message="", email=True):
    """Change status with history, client notification, email and audit entry, all or nothing."""
    with transaction():
        execute("UPDATE applications SET status = ?, updated_at = ? WHERE id = ? AND org_id = ?",
                (new, now(), app_row["id"], org_id()))
        record_status(app_row["id"], new, actor["id"], note)
        if message:
            execute("INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,1,?)",
                    (app_row["id"], actor["id"], message[:4000], now()))
        notify(app_row["user_id"], f"{reference(app_row)} is now: {STATUSES[new]}", f"/account/applications/{app_row['id']}")
        audit.log("application.status", "application", app_row["id"], actor=actor, old=app_row["status"], new=new)
    if email:
        client = query("SELECT * FROM users WHERE id = ?", (app_row["user_id"],), one=True)
        fresh = query("SELECT * FROM applications WHERE id = ?", (app_row["id"],), one=True)
        mail.notify_client_status(fresh, client, STATUSES[new], message)


# ---------------------------------------------------------------- documents
STORED_RE = re.compile(r"^[0-9a-f]{32}\.(pdf|png|jpg|jpeg)$")
MIME = {".pdf": "application/pdf", ".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg"}
# Active content has no place in a bank statement or an ID scan.
PDF_ACTIVE = (b"/JavaScript", b"/JS", b"/Launch", b"/EmbeddedFile", b"/RichMedia", b"/SubmitForm")


def _check_content(data, ext):
    """Validate by content, never by the name or the browser's MIME type. Returns an error or None."""
    if not data:
        return "That file is empty."
    if ext == ".pdf":
        if not data.startswith(b"%PDF-") or b"%%EOF" not in data[-2048:]:
            return "That file doesn't look like a real PDF."
        if any(tag in data for tag in PDF_ACTIVE):
            return "That PDF contains scripts or embedded files, which we can't accept. Please print it to a new PDF and upload that."
        return None
    expected = "PNG" if ext == ".png" else "JPEG"
    signature = b"\x89PNG\r\n\x1a\n" if ext == ".png" else b"\xff\xd8\xff"
    if not data.startswith(signature):
        return "That file doesn't look like a real PDF or image."
    from PIL import Image
    Image.MAX_IMAGE_PIXELS = 60_000_000  # refuse decompression bombs
    try:
        with Image.open(io.BytesIO(data)) as img:
            if img.format != expected:
                return "That file doesn't look like a real PDF or image."
            img.verify()
    except Exception:
        return "That image is damaged or too large to process. Please try another copy."
    return None


def save_upload(file_storage, app_id, user_id, doc_type="other", replaces_id=None):
    """Validate and store one uploaded file. Returns (error, document_id). New documents start as 'submitted'."""
    if not file_storage or not file_storage.filename:
        return "Choose a file to upload.", None
    original = secure_filename(file_storage.filename)[:120] or "document"
    ext = os.path.splitext(original)[1].lower()
    if ext not in ALLOWED_UPLOADS:
        return "Upload a PDF, JPG or PNG file.", None
    if doc_type not in DOC_TYPES:
        doc_type = "other"
    limit = current_app.config["MAX_UPLOAD_BYTES"]
    data = file_storage.stream.read(limit + 1)
    if len(data) > limit:
        return f"That file is larger than {limit // (1024 * 1024)} MB.", None
    error = _check_content(data, ext)
    if error:
        return error, None
    stored = secrets.token_hex(16) + ext  # random, unguessable, never derived from the client's file name
    with open(os.path.join(current_app.config["UPLOAD_DIR"], stored), "xb") as fh:
        fh.write(data)
    doc_id = execute("INSERT INTO documents (org_id, application_id, uploaded_by, original_name, stored_name, size_bytes, "
                     "created_at, doc_type, sha256, mime, review_status, replaces_id) VALUES (?,?,?,?,?,?,?,?,?,?,'submitted',?)",
                     (org_id(), app_id, user_id, original, stored, len(data), now(), doc_type,
                      hashlib.sha256(data).hexdigest(), MIME[ext], replaces_id))
    audit.log("document.upload", "document", doc_id, application_id=app_id, doc_type=doc_type, size=len(data),
              replaces=replaces_id)
    return None, doc_id


def private_file_response(folder, stored_name, download_name, inline=False):
    if not STORED_RE.match(stored_name or ""):
        abort(404)
    path = os.path.join(folder, stored_name)
    if not os.path.isfile(path):
        abort(404)
    ext = os.path.splitext(stored_name)[1]
    resp = send_file(path, mimetype=MIME[ext], as_attachment=not inline, download_name=download_name, max_age=0)
    resp.headers["Content-Security-Policy"] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox"
    resp.headers["Cache-Control"] = "private, no-store"
    resp.headers["X-Content-Type-Options"] = "nosniff"
    return resp


def send_document(doc, inline=False):
    """Caller must already have checked that the current user may see this document."""
    if doc is None:
        abort(404)
    inline = inline and doc["mime"] in ("image/png", "image/jpeg")  # PDFs always download: no in-browser script surface
    audit.log("document.view" if inline else "document.download", "document", doc["id"], application_id=doc["application_id"])
    return private_file_response(current_app.config["UPLOAD_DIR"], doc["stored_name"], doc["original_name"], inline)


def application_bundle(app_id, include_internal):
    """Everything a detail page shows. Callers have already checked the application belongs to this organisation."""
    docs = query("SELECT d.*, u.role AS uploader_role, r.full_name AS reviewer_name FROM documents d "
                 "JOIN users u ON u.id = d.uploaded_by LEFT JOIN users r ON r.id = d.reviewed_by "
                 "WHERE d.application_id = ? AND d.org_id = ? ORDER BY d.created_at DESC, d.id DESC", (app_id, org_id()))
    notes_sql = ("SELECT n.*, u.full_name, u.role FROM notes n JOIN users u ON u.id = n.author_id "
                 "WHERE n.application_id = ? {} ORDER BY n.created_at ASC, n.id ASC")
    notes = query(notes_sql.format("" if include_internal else "AND n.visible_to_client = 1"), (app_id,))
    history = query("SELECT h.*, u.full_name AS actor_name, u.role AS actor_role FROM status_history h "
                    "LEFT JOIN users u ON u.id = h.changed_by WHERE h.application_id = ? ORDER BY h.created_at ASC, h.id ASC",
                    (app_id,))
    generated = query("SELECT gd.*, u.full_name AS generated_by_name, i.full_name AS issued_by_name FROM generated_documents gd "
                      "JOIN users u ON u.id = gd.generated_by LEFT JOIN users i ON i.id = gd.issued_by "
                      "WHERE gd.application_id = ? {} ORDER BY gd.generated_at DESC, gd.id DESC"
                      .format("" if include_internal else "AND gd.status = 'issued'"), (app_id,))
    return docs, notes, history, generated
