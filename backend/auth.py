"""Registration, email verification, sign-in (with optional authenticator-app codes), password reset,
and the Account and security page (password, two-step verification, active sessions, marketing choice)."""
import hashlib
import re
import secrets
import time

from flask import Blueprint, abort, current_app, flash, g, redirect, render_template, request, session, url_for
from werkzeug.security import check_password_hash, generate_password_hash

from . import audit, mail, marketing
from .db import execute, later, now, query, transaction
from .security import (active_sessions, belongs_here, clear_throttle, current_session, current_user, find_user_here,
                       is_platform_owner, is_staff, login_required, login_user, logout_current, new_recovery_codes,
                       new_totp_secret, revoke_sessions, throttled, totp_uri, use_recovery_code, verify_totp)
from .tenancy import org_id

bp = Blueprint("auth", __name__, url_prefix="/account")

EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
MIN_PASSWORD = 10
COMMON_PASSWORDS = {"password123", "password1234", "1234567890", "qwertyuiop", "letmein123", "iloveyou12",
                    "welcome123", "football12", "monkey12345", "abc1234567", "passw0rd123", "changeme123",
                    "administrator", "1q2w3e4r5t", "qwerty12345", "lmdfinance1", "lmdfinance123"}
RESET_HOURS = 1
VERIFY_HOURS = 24
# Compared against when the email is unknown, so a wrong email takes as long as a wrong password.
_DUMMY_HASH = generate_password_hash(secrets.token_hex(16))


def _hash(token):
    return hashlib.sha256(token.encode()).hexdigest()


def _safe_next(target):
    # Only follow local paths, never another site (open-redirect protection).
    if target and target.startswith("/") and not target.startswith("//") and "\\" not in target:
        return target
    return None


def password_problem(password, email=""):
    if len(password) < MIN_PASSWORD:
        return f"Use at least {MIN_PASSWORD} characters."
    if len(password) > 200:
        return "Use 200 characters or fewer."
    if password.lower() in COMMON_PASSWORDS or len(set(password)) < 4:
        return "That password is too easy to guess. Try a short phrase of unrelated words."
    if email and email.split("@")[0].lower() in password.lower() and len(email.split("@")[0]) > 3:
        return "Don't include your email address in your password."
    return None


def home_for(user):
    if is_platform_owner(user):
        return url_for("platform.dashboard")
    return url_for("admin.dashboard") if is_staff(user) else url_for("client.dashboard")


def _token_user_here(user_id):
    """A token only works on the host of the organisation that issued it."""
    user = query("SELECT * FROM users WHERE id = ?", (user_id,), one=True)
    return user if user is not None and belongs_here(user) else None


# ---------------------------------------------------------------- registration and verification
def _send_verification(user):
    token = secrets.token_urlsafe(32)
    with transaction():
        execute("UPDATE email_verifications SET used_at = ? WHERE user_id = ? AND used_at IS NULL", (now(), user["id"]))
        execute("INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)",
                (user["id"], _hash(token), later(hours=VERIFY_HOURS), now()))
    mail.send_verification(user, f"{mail.base_url()}/account/verify/{token}")


@bp.route("/register", methods=["GET", "POST"])
def register():
    if g.get("platform"):
        abort(404)  # nobody self-registers on the platform console
    if current_user():
        return redirect(home_for(current_user()))
    form = {"full_name": "", "email": "", "phone": ""}
    errors = {}
    if request.method == "POST":
        form = {k: request.form.get(k, "").strip() for k in form}
        password = request.form.get("password", "")
        if throttled(f"register:{request.remote_addr}", 10, 3600):
            errors["form"] = "Too many attempts. Please try again later."
        if not 2 <= len(form["full_name"]) <= 120:
            errors["full_name"] = "Enter your full name."
        if not EMAIL_RE.match(form["email"]) or len(form["email"]) > 200:
            errors["email"] = "Enter a valid email address."
        if len(form["phone"]) > 40 or (form["phone"] and not re.fullmatch(r"[+0-9 ()-]{6,40}", form["phone"])):
            errors["phone"] = "Enter a phone number using digits, spaces and + only."
        problem = password_problem(password, form["email"])
        if problem:
            errors["password"] = problem
        elif password != request.form.get("confirm", ""):
            errors["confirm"] = "Passwords do not match."
        if not request.form.get("terms"):
            errors["terms"] = "Please confirm you have read the privacy notice."
        if not errors:
            existing = find_user_here(form["email"])
            if existing:
                # Same response as a new sign-up, so the form can't be used to find out who has an account.
                # The real owner gets an email explaining what happened.
                if not throttled(f"exists:{form['email']}", 2, 3600):
                    mail.send_account_exists(existing)
                audit.log("auth.register_existing", "user", existing["id"], actor=None)
            else:
                uid = execute("INSERT INTO users (org_id, email, password_hash, full_name, phone, role, created_at, password_changed_at) "
                              "VALUES (?,?,?,?,?, 'client', ?, ?)",
                              (org_id(), form["email"].lower(), generate_password_hash(password), form["full_name"],
                               form["phone"], now(), now()))
                user = query("SELECT * FROM users WHERE id = ?", (uid,), one=True)
                audit.log("auth.register", "user", uid, actor=user)
                if request.form.get("marketing"):
                    marketing.record_consent(user, "opt_in", "registration form")
                _send_verification(user)
            return render_template("auth/check_email.html", email=form["email"])
    return render_template("auth/register.html", form=form, errors=errors)


@bp.route("/verify/<token>")
def verify_email(token):
    row = query("SELECT * FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?",
                (_hash(token), now()), one=True)
    if row is None or _token_user_here(row["user_id"]) is None:
        return render_template("auth/verify_failed.html"), 400
    with transaction():
        execute("UPDATE email_verifications SET used_at = ? WHERE user_id = ? AND used_at IS NULL", (now(), row["user_id"]))
        execute("UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?", (now(), row["user_id"]))
        audit.log("auth.email_verified", "user", row["user_id"], actor=None)
    flash("Thank you, your email address is confirmed." + ("" if current_user() else " Please sign in."), "success")
    return redirect(home_for(current_user()) if current_user() else url_for("auth.login"))


@bp.route("/verify/resend", methods=["POST"])
@login_required
def resend_verification():
    user = current_user()
    if user["email_verified_at"]:
        flash("Your email address is already confirmed.", "info")
    elif throttled(f"verify:{user['id']}", 3, 3600):
        flash("We've sent several links recently. Please check your inbox and spam folder, or try again in an hour.", "error")
    else:
        _send_verification(user)
        flash("We've sent a new confirmation link. It works for 24 hours.", "success")
    return redirect(_safe_next(request.form.get("next")) or home_for(user))


# ---------------------------------------------------------------- sign in
@bp.route("/login", methods=["GET", "POST"])
def login():
    if current_user():
        return redirect(home_for(current_user()))
    email = ""
    error = None
    if request.method == "POST":
        email = request.form.get("email", "").strip()[:200]
        password = request.form.get("password", "")
        if throttled(f"login:{request.remote_addr}", 20, 900) or throttled(f"login:{email}", 8, 900):
            error = "Too many sign-in attempts. Please wait 15 minutes and try again."
            audit.log("auth.login_throttled", "user", None, actor=None)
        else:
            user = find_user_here(email)
            ok = check_password_hash(user["password_hash"] if user else _DUMMY_HASH, password)
            if user and ok and user["is_active"]:
                nxt = _safe_next(request.args.get("next"))
                if user["totp_enabled_at"]:
                    session.clear()
                    session["mfa_uid"], session["mfa_at"], session["mfa_next"] = user["id"], time.time(), nxt
                    return redirect(url_for("auth.login_code"))
                return _finish_login(user, nxt)
            audit.log("auth.login_failed", "user", user["id"] if user else None, actor=None)
            error = "That email and password don't match an account."
    return render_template("auth/login.html", email=email, error=error)


def _finish_login(user, nxt, mfa=False):
    login_user(user)
    clear_throttle(f"login:{user['email']}")
    audit.log("auth.login", "user", user["id"], actor=user, mfa=mfa)
    if is_platform_owner(user):
        return redirect(nxt if nxt and nxt.startswith("/platform") else url_for("platform.dashboard"))
    if is_staff(user):
        return redirect(nxt if nxt and nxt.startswith("/admin") else url_for("admin.dashboard"))
    return redirect(nxt if nxt and nxt.startswith("/account") else url_for("client.dashboard"))


@bp.route("/login/code", methods=["GET", "POST"])
def login_code():
    uid = session.get("mfa_uid")
    if not uid or time.time() - session.get("mfa_at", 0) > 300:
        session.clear()
        flash("Please sign in again.", "info")
        return redirect(url_for("auth.login"))
    error = None
    if request.method == "POST":
        user = _token_user_here(uid)
        user = user if user is not None and user["is_active"] else None
        code = request.form.get("code", "")
        if user is None or throttled(f"mfa:{uid}", 5, 900):
            error = "Too many attempts. Please wait 15 minutes and sign in again."
        else:
            step = verify_totp(user["totp_secret"], code, user["totp_last_step"])
            if step is not None:
                execute("UPDATE users SET totp_last_step = ? WHERE id = ?", (step, uid))
            if step is not None or use_recovery_code(uid, code):
                if step is None:
                    audit.log("auth.recovery_code_used", "user", uid, actor=user)
                    mail.send_security_alert(user, "a recovery code was used to sign in")
                return _finish_login(user, session.get("mfa_next"), mfa=True)
            audit.log("auth.mfa_failed", "user", uid, actor=None)
            error = "That code didn't work. Check your authenticator app and try again."
    return render_template("auth/login_code.html", error=error)


@bp.route("/logout", methods=["POST"])
def logout():
    user = current_user()
    if user:
        audit.log("auth.logout", "user", user["id"], actor=user)
    logout_current()
    flash("You have signed out.", "success")
    return redirect(url_for("auth.login"))


# ---------------------------------------------------------------- password reset
def _valid_reset(token):
    row = query("SELECT r.*, u.email FROM password_resets r JOIN users u ON u.id = r.user_id "
                "WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?", (_hash(token), now()), one=True)
    return row if row is not None and _token_user_here(row["user_id"]) is not None else None


@bp.route("/forgot", methods=["GET", "POST"])
def forgot():
    sent = False
    email = ""
    if request.method == "POST":
        email = request.form.get("email", "").strip()[:200]
        sent = True  # same answer whether or not the account exists, so emails can't be probed
        limited = (throttled(f"forgot:{request.remote_addr}", 10, 3600) or
                   throttled(f"forgot:{email}", 3, 3600))
        user = None if limited else find_user_here(email)
        if user and user["is_active"]:
            token = secrets.token_urlsafe(32)
            stamp = now()
            with transaction():
                # One live link at a time: older unused links stop working.
                execute("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL", (stamp, user["id"]))
                execute("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)",
                        (user["id"], _hash(token), later(hours=RESET_HOURS), stamp))
                audit.log("auth.reset_requested", "user", user["id"], actor=None)
            mail.send_password_reset(user, f"{mail.base_url()}/account/reset/{token}")
    return render_template("auth/forgot.html", sent=sent, email=email)


@bp.route("/reset/<token>", methods=["GET", "POST"])
def reset(token):
    row = _valid_reset(token)
    if row is None:
        return render_template("auth/reset.html", invalid=True, errors={}), 400
    errors = {}
    if request.method == "POST":
        password = request.form.get("password", "")
        problem = password_problem(password, row["email"])
        if problem:
            errors["password"] = problem
        elif password != request.form.get("confirm", ""):
            errors["confirm"] = "Passwords do not match."
        if not errors:
            stamp = now()
            with transaction():
                execute("UPDATE users SET password_hash = ?, password_changed_at = ?, "
                        "email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?",  # the link proved the address
                        (generate_password_hash(password), stamp, stamp, row["user_id"]))
                execute("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL", (stamp, row["user_id"]))
                revoke_sessions(row["user_id"])  # sign out everywhere: whoever had the old password is locked out
                audit.log("auth.password_reset", "user", row["user_id"], actor=None)
            clear_throttle(f"login:{row['email']}")
            session.clear()
            mail.send_security_alert(query("SELECT * FROM users WHERE id = ?", (row["user_id"],), one=True),
                                     "your password was changed")
            flash("Your password has been changed. Please sign in.", "success")
            return redirect(url_for("auth.login"))
    return render_template("auth/reset.html", invalid=False, errors=errors, email=row["email"])


# ---------------------------------------------------------------- account and security
@bp.route("/security", methods=["GET"])
@login_required
def security():
    user = current_user()
    setup_secret = session.get("totp_setup")
    qr_svg = None
    if setup_secret:
        import segno
        qr_svg = segno.make(totp_uri(setup_secret, user["email"]), error="m").svg_inline(scale=4, dark="#112327", light="#ffffff")
    codes = session.pop("recovery_codes", None)
    remaining = query("SELECT COUNT(*) n FROM recovery_codes WHERE user_id = ? AND used_at IS NULL", (user["id"],), one=True)["n"]
    return render_template("auth/security.html", user=user, sessions=active_sessions(user["id"]),
                           this_session=current_session()["id"], setup_secret=setup_secret, qr_svg=qr_svg,
                           recovery_codes=codes, recovery_left=remaining, opted_in=marketing.is_opted_in(user),
                           must_setup=bool(request.args.get("setup_mfa")), errors={})


@bp.route("/security/password", methods=["POST"])
@login_required
def change_password():
    user = current_user()
    errors = {}
    if throttled(f"pwchange:{user['id']}", 5, 900):
        errors["current"] = "Too many attempts. Please try again in 15 minutes."
    elif not check_password_hash(user["password_hash"], request.form.get("current", "")):
        errors["current"] = "Your current password is not correct."
    new = request.form.get("password", "")
    problem = password_problem(new, user["email"])
    if problem:
        errors["password"] = problem
    elif new != request.form.get("confirm", ""):
        errors["confirm"] = "Passwords do not match."
    if errors:
        for msg in errors.values():
            flash(msg, "error")
        return redirect(url_for("auth.security") + "#password")
    with transaction():
        execute("UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?",
                (generate_password_hash(new), now(), user["id"]))
        revoke_sessions(user["id"], keep_current=True)
        audit.log("auth.password_changed", "user", user["id"])
    mail.send_security_alert(user, "your password was changed")
    flash("Password changed. Any other devices have been signed out.", "success")
    return redirect(url_for("auth.security"))


@bp.route("/security/mfa/start", methods=["POST"])
@login_required
def mfa_start():
    if current_user()["totp_enabled_at"]:
        abort(400, "Two-step verification is already on.")
    session["totp_setup"] = new_totp_secret()
    return redirect(url_for("auth.security") + "#mfa")


@bp.route("/security/mfa/confirm", methods=["POST"])
@login_required
def mfa_confirm():
    user = current_user()
    secret = session.get("totp_setup")
    step = verify_totp(secret, request.form.get("code", "")) if secret else None
    if step is None or throttled(f"mfasetup:{user['id']}", 8, 900):
        flash("That code didn't match. Make sure your phone's clock is set automatically, then try the newest code.", "error")
        return redirect(url_for("auth.security") + "#mfa")
    with transaction():
        execute("UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?",
                (secret, now(), step, user["id"]))
        session["recovery_codes"] = new_recovery_codes(user["id"])
        audit.log("auth.mfa_enabled", "user", user["id"])
    session.pop("totp_setup", None)
    mail.send_security_alert(user, "two-step verification was turned on")
    flash("Two-step verification is on. Save your recovery codes somewhere safe now: they are shown only once.", "success")
    return redirect(url_for("auth.security") + "#mfa")


@bp.route("/security/mfa/disable", methods=["POST"])
@login_required
def mfa_disable():
    user = current_user()
    if (is_staff(user) or is_platform_owner(user)) and current_app.config["REQUIRE_STAFF_MFA"]:
        abort(403, "Staff accounts must keep two-step verification on.")
    ok = check_password_hash(user["password_hash"], request.form.get("password", "")) and \
        verify_totp(user["totp_secret"], request.form.get("code", ""), user["totp_last_step"]) is not None
    if not ok or throttled(f"mfaoff:{user['id']}", 5, 900):
        flash("Enter your password and a current code from your authenticator app to turn this off.", "error")
        return redirect(url_for("auth.security") + "#mfa")
    with transaction():
        execute("UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?", (user["id"],))
        execute("DELETE FROM recovery_codes WHERE user_id = ?", (user["id"],))
        audit.log("auth.mfa_disabled", "user", user["id"])
    mail.send_security_alert(user, "two-step verification was turned off")
    flash("Two-step verification is off.", "success")
    return redirect(url_for("auth.security") + "#mfa")


@bp.route("/security/sessions/<int:sid>/revoke", methods=["POST"])
@login_required
def revoke_session(sid):
    user = current_user()
    row = query("SELECT * FROM user_sessions WHERE id = ? AND user_id = ?", (sid, user["id"]), one=True)  # own sessions only
    if row is None:
        abort(404)
    execute("UPDATE user_sessions SET revoked_at = ? WHERE id = ?", (now(), sid))
    audit.log("auth.session_revoked", "user", user["id"], session_id=sid)
    if sid == current_session()["id"]:
        session.clear()
        return redirect(url_for("auth.login"))
    flash("That session has been signed out.", "success")
    return redirect(url_for("auth.security") + "#sessions")


@bp.route("/security/sessions/revoke-others", methods=["POST"])
@login_required
def revoke_other_sessions():
    user = current_user()
    revoke_sessions(user["id"], keep_current=True)
    audit.log("auth.sessions_revoked", "user", user["id"])
    flash("All other sessions have been signed out.", "success")
    return redirect(url_for("auth.security") + "#sessions")


@bp.route("/security/marketing", methods=["POST"])
@login_required
def marketing_preference():
    user = current_user()
    want = bool(request.form.get("marketing"))
    if want != marketing.is_opted_in(user):
        marketing.record_consent(user, "opt_in" if want else "opt_out", "account settings")
    flash("Marketing preference saved." + (" You can unsubscribe at any time." if want else ""), "success")
    return redirect(url_for("auth.security") + "#marketing")
