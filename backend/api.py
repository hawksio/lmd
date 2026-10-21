"""JSON endpoints used by the static marketing pages."""
import re

from flask import Blueprint, jsonify, request

from . import audit, mail
from .db import execute, now, query
from .options import TOPICS
from .security import csrf_token, throttled
from .tenancy import org_id

bp = Blueprint("api", __name__, url_prefix="/api")
EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


@bp.route("/csrf")
def csrf():
    # Static pages fetch a token before posting, so the enquiry form gets CSRF protection too.
    resp = jsonify(token=csrf_token())
    resp.headers["Cache-Control"] = "no-store"
    return resp


@bp.route("/health")
def health():
    """For uptime monitors and the deploy script: checks the database answers."""
    query("SELECT 1", one=True)
    return jsonify(ok=True)


@bp.route("/enquiries", methods=["POST"])
def create_enquiry():
    data = request.get_json(silent=True) or {}
    if data.get("website"):  # honeypot field, invisible to people
        return jsonify(ok=True)
    if throttled(f"enquiry:{request.remote_addr}", 5, 3600):
        return jsonify(ok=False, error="Too many enquiries from this connection. Please try again later."), 429
    fields = {k: str(data.get(k, "")).strip() for k in ("name", "company", "email", "phone", "topic", "message")}
    errors = {}
    if not 2 <= len(fields["name"]) <= 120:
        errors["name"] = "Enter your name."
    if not 2 <= len(fields["company"]) <= 200:
        errors["company"] = "Enter your business name."
    if not EMAIL_RE.match(fields["email"]) or len(fields["email"]) > 200:
        errors["email"] = "Enter a valid email address."
    if fields["phone"] and not re.fullmatch(r"[+0-9 ()-]{6,40}", fields["phone"]):
        errors["phone"] = "Enter a phone number using digits, spaces and + only."
    if len(fields["message"]) > 4000:
        errors["message"] = "Please keep your message under 4,000 characters."
    if not data.get("consent"):
        errors["consent"] = "Please agree so we can contact you about your enquiry."
    if errors:
        return jsonify(ok=False, errors=errors), 400
    topic = fields["topic"] if fields["topic"] in TOPICS else "general"
    eid = execute("INSERT INTO enquiries (org_id, name, company, email, phone, topic, message, created_at) VALUES (?,?,?,?,?,?,?,?)",
                  (org_id(), fields["name"], fields["company"], fields["email"], fields["phone"], topic, fields["message"], now()))
    audit.log("enquiry.received", "enquiry", eid, actor=None)
    mail.notify_staff_enquiry(query("SELECT * FROM enquiries WHERE id = ?", (eid,), one=True))
    return jsonify(ok=True)
