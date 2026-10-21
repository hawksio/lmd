"""Sessions, CSRF protection, staff roles and permissions, rate limiting and TOTP two-factor codes.

Sessions are server-side records (user_sessions). The signed cookie only carries a random session id,
so signing out, changing a password or "sign out other devices" really ends those sessions.
"""
import base64
import hashlib
import hmac
import secrets
import struct
import time
from functools import wraps

from flask import abort, current_app, g, redirect, request, session, url_for

from .db import execute, later, now, query

# ---------------------------------------------------------------- roles and permissions
# Organisation staff roles. The platform owner is separate (role 'platform_owner', no organisation) and has
# no organisation permissions at all: the platform console works only with organisation-level aggregates.
STAFF_ROLES = {
    "admin": "Organisation administrator",
    "underwriter": "Underwriter",
    "document_reviewer": "Document reviewer",
    "support": "Customer support",
    "finance": "Finance officer",
    "auditor": "Read-only auditor",
}
ALL = set(STAFF_ROLES)
PERMISSIONS = {
    "view_applications":     ALL,
    "view_clients":          ALL,
    "view_enquiries":        ALL,
    "view_documents":        {"admin", "underwriter", "document_reviewer", "auditor"},   # support/finance see lists only
    "review_documents":      {"admin", "underwriter", "document_reviewer"},
    "upload_documents":      {"admin", "underwriter", "document_reviewer"},
    "message_client":        {"admin", "underwriter", "document_reviewer", "support", "finance"},
    "internal_note":         {"admin", "underwriter", "document_reviewer", "support", "finance"},
    "change_status":         {"admin", "underwriter"},
    "propose_decisions":     {"admin", "underwriter"},
    "issue_decisions":       {"admin", "underwriter"},   # above the threshold, never the proposer
    "generate_documents":    {"admin", "underwriter", "finance"},
    "issue_documents":       {"admin", "underwriter", "finance"},
    "record_disbursement":   {"admin", "finance"},
    "confirm_disbursement":  {"admin", "finance"},       # and never the person who recorded it
    "manage_repayments":     {"admin", "finance"},
    "manage_fees":           {"admin", "finance"},
    "handle_enquiries":      {"admin", "support"},
    "edit_content":          {"admin", "support"},
    "publish_content":       {"admin"},
    "manage_marketing":      {"admin"},
    "manage_staff":          {"admin"},
    "manage_products":       {"admin"},
    "manage_financial_settings": {"admin"},
    "manage_org_settings":   {"admin"},
    "view_reports":          {"admin", "finance", "auditor"},
    "export_data":           {"admin", "finance", "auditor"},
    "view_audit":            {"admin", "auditor"},
    "use_ai_drafts":         {"admin", "underwriter", "support"},
}


def is_staff(user):
    return bool(user) and user["role"] in STAFF_ROLES


def is_platform_owner(user):
    return bool(user) and user["role"] == "platform_owner"


def can(permission, user=None):
    user = user if user is not None else current_user()
    return is_staff(user) and user["role"] in PERMISSIONS[permission]


# ---------------------------------------------------------------- sessions
def _hash(value):
    return hashlib.sha256(value.encode()).hexdigest()


def _idle_cutoff():
    minutes = current_app.config["SESSION_IDLE_MINUTES"]
    return time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(time.time() - minutes * 60))


def current_session():
    if "user_session" not in g:
        sid = session.get("sid")
        row = None
        if sid:
            row = query("SELECT * FROM user_sessions WHERE sid_hash = ? AND revoked_at IS NULL AND expires_at > ? "
                        "AND last_seen_at > ?", (_hash(sid), now(), _idle_cutoff()), one=True)
            if row is None:
                session.pop("sid", None)
            elif row["last_seen_at"] < time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(time.time() - 60)):
                execute("UPDATE user_sessions SET last_seen_at = ? WHERE id = ?", (now(), row["id"]))
        g.user_session = row
    return g.user_session


def current_user():
    """The signed-in user, but only on their own organisation's host (or the platform console for owners)."""
    if "user" not in g:
        row = current_session()
        user = query("SELECT * FROM users WHERE id = ? AND is_active = 1", (row["user_id"],), one=True) if row else None
        if user is not None and not belongs_here(user):
            user = None
        g.user = user
    return g.user


def belongs_here(user):
    if g.get("platform"):
        return user["role"] == "platform_owner"
    org = g.get("org")
    return org is not None and user["org_id"] == org["id"]


def find_user_here(email):
    """Look up an account by email within the current organisation (or among platform owners on the console)."""
    if g.get("platform"):
        return query("SELECT * FROM users WHERE email = ? AND org_id IS NULL", (email,), one=True)
    return query("SELECT * FROM users WHERE email = ? AND org_id = ?", (email, g.org["id"]), one=True)


def login_user(user):
    """Start a fresh session (new id, so a planted session can't be reused) and record it."""
    session.clear()
    sid = secrets.token_urlsafe(32)
    stamp = now()
    execute("INSERT INTO user_sessions (user_id, sid_hash, created_at, last_seen_at, expires_at, ip, user_agent) "
            "VALUES (?,?,?,?,?,?,?)",
            (user["id"], _hash(sid), stamp, stamp, later(hours=current_app.config["SESSION_HOURS"]),
             (request.remote_addr or "")[:64], (request.user_agent.string or "")[:200]))
    execute("UPDATE users SET last_login_at = ? WHERE id = ?", (stamp, user["id"]))
    session["sid"] = sid
    session.permanent = True
    g.pop("user", None)
    g.pop("user_session", None)


def logout_current():
    row = current_session()
    if row:
        execute("UPDATE user_sessions SET revoked_at = ? WHERE id = ?", (now(), row["id"]))
    session.clear()
    g.pop("user", None)
    g.pop("user_session", None)


def revoke_sessions(user_id, keep_current=False):
    keep = current_session()["id"] if keep_current and current_session() else -1
    execute("UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id != ?",
            (now(), user_id, keep))


def active_sessions(user_id):
    return query("SELECT * FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? "
                 "AND last_seen_at > ? ORDER BY last_seen_at DESC", (user_id, now(), _idle_cutoff()))


# ---------------------------------------------------------------- route guards
def login_required(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        if current_user() is None:
            return redirect(url_for("auth.login", next=request.path))
        return view(*args, **kwargs)
    return wrapped


def staff_required(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        user = current_user()
        if user is None:
            return redirect(url_for("auth.login", next=request.path))
        if not is_staff(user):
            abort(403)
        if current_app.config["REQUIRE_STAFF_MFA"] and not user["totp_enabled_at"]:
            return redirect(url_for("auth.security", setup_mfa=1))
        return view(*args, **kwargs)
    return wrapped


admin_required = staff_required  # backwards-compatible name


def platform_required(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        if not g.get("platform"):
            abort(404)
        user = current_user()
        if user is None:
            return redirect(url_for("auth.login", next=request.path))
        if not is_platform_owner(user):
            abort(403)
        if current_app.config["REQUIRE_STAFF_MFA"] and not user["totp_enabled_at"]:
            return redirect(url_for("auth.security", setup_mfa=1))
        return view(*args, **kwargs)
    return wrapped


def require(permission):
    """Server-side permission check. Hidden buttons are a convenience, never the control."""
    if not can(permission):
        abort(403)


def permission_required(permission):
    def deco(view):
        @wraps(view)
        def wrapped(*args, **kwargs):
            require(permission)
            return view(*args, **kwargs)
        return wrapped
    return deco


# ---------------------------------------------------------------- CSRF
CSRF_EXEMPT = {"marketing.unsubscribe_one_click"}  # RFC 8058 one-click unsubscribe: the signed token is the proof


def csrf_token():
    if "csrf" not in session:
        session["csrf"] = secrets.token_urlsafe(32)
    return session["csrf"]


def check_csrf():
    if request.method in ("POST", "PUT", "PATCH", "DELETE") and request.endpoint not in CSRF_EXEMPT:
        sent = request.form.get("csrf_token") or request.headers.get("X-CSRF-Token", "")
        if not sent or not secrets.compare_digest(sent, session.get("csrf", "")):
            abort(400, "Your session expired. Please go back, refresh the page and try again.")


# ---------------------------------------------------------------- rate limiting
def throttled(key, limit, window):
    """Record an attempt for `key`; True if it is over `limit` within `window` seconds.

    Stored in the database (keys hashed, so no email addresses sit in this table), which works across
    several worker processes on one server.
    """
    t = time.time()
    key_hash = _hash(key.lower())
    if secrets.randbelow(50) == 0:  # occasional housekeeping
        execute("DELETE FROM rate_limits WHERE at < ?", (t - 86400,))
    execute("INSERT INTO rate_limits (key_hash, at) VALUES (?, ?)", (key_hash, t))
    n = query("SELECT COUNT(*) n FROM rate_limits WHERE key_hash = ? AND at > ?", (key_hash, t - window), one=True)["n"]
    return n > limit


def clear_throttle(key):
    execute("DELETE FROM rate_limits WHERE key_hash = ?", (_hash(key.lower()),))


# ---------------------------------------------------------------- TOTP (RFC 6238, works with any authenticator app)
def new_totp_secret():
    return base64.b32encode(secrets.token_bytes(20)).decode().rstrip("=")


def _totp_at(secret, step):
    key = base64.b32decode(secret + "=" * (-len(secret) % 8))
    digest = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code = (struct.unpack(">I", digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1_000_000
    return f"{code:06d}"


def totp_now(secret, at=None):
    return _totp_at(secret, int((at or time.time()) // 30))


def verify_totp(secret, code, last_step=None):
    """Return the matched time step (to block replay), or None. Accepts one step of clock drift."""
    code = "".join(ch for ch in (code or "") if ch.isdigit())
    if len(code) != 6 or not secret:
        return None
    step = int(time.time() // 30)
    for candidate in (step, step - 1, step + 1):
        if last_step is not None and candidate <= last_step:
            continue
        if hmac.compare_digest(_totp_at(secret, candidate), code):
            return candidate
    return None


def totp_uri(secret, email):
    from urllib.parse import quote
    return f"otpauth://totp/{quote('LMD Finance')}:{quote(email)}?secret={secret}&issuer={quote('LMD Finance')}"


def new_recovery_codes(user_id):
    codes = ["-".join(secrets.token_hex(2) for _ in range(3)) for _ in range(8)]
    execute("DELETE FROM recovery_codes WHERE user_id = ?", (user_id,))
    for c in codes:
        execute("INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (?,?,?)", (user_id, _hash(c), now()))
    return codes


def use_recovery_code(user_id, code):
    code = (code or "").strip().lower()
    row = query("SELECT id FROM recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL",
                (user_id, _hash(code)), one=True)
    if row:
        execute("UPDATE recovery_codes SET used_at = ? WHERE id = ?", (now(), row["id"]))
        return True
    return False
