"""Consent-based marketing email.

Rules this module enforces:
- Nobody is emailed unless they ticked an unticked opt-in box (registration or Account and security),
  confirmed their email address, and have not opted out since. Registering, applying or sending an
  enquiry never opts anyone in.
- Every consent change is recorded (marketing_consent_events) with the wording shown at the time.
- Opting out or unsubscribing adds the address to the suppression list, which every send checks.
- Live campaigns need LMD_MARKETING_ENABLED=1. Test sends go only to the signed-in staff member.
- Marketing uses its own SMTP settings; without them, messages go to the outbox.

Scheduled campaigns are sent by `flask --app backend send-campaigns`, run every few minutes by a
systemd timer or cron on the server (see deploy/).
"""
import time
from datetime import datetime

from flask import Blueprint, abort, current_app, flash, redirect, render_template, request, url_for
from itsdangerous import BadSignature, URLSafeSerializer

from . import audit, mail
from .db import execute, now, query, transaction
from .security import current_user, permission_required, staff_required
from .tenancy import current_org, org_id

bp = Blueprint("marketing", __name__)

SEGMENTS = {
    "all_opted_in": "Everyone who has opted in",
    "has_application": "Opted-in clients who have submitted an application",
    "no_application": "Opted-in clients who have not submitted an application",
}
# Every segment is limited to one organisation and excludes that organisation's suppression list.
_ELIGIBLE = ("SELECT u.id, u.email, u.full_name FROM users u WHERE u.org_id = :org AND u.role = 'client' AND u.is_active = 1 "
             "AND u.email_verified_at IS NOT NULL AND u.marketing_opt_in_at IS NOT NULL "
             "AND u.email NOT IN (SELECT email FROM suppressions WHERE org_id = :org)")
_SEGMENT_SQL = {
    "all_opted_in": _ELIGIBLE,
    "has_application": _ELIGIBLE + " AND EXISTS (SELECT 1 FROM applications a WHERE a.user_id = u.id AND a.status != 'draft')",
    "no_application": _ELIGIBLE + " AND NOT EXISTS (SELECT 1 FROM applications a WHERE a.user_id = u.id AND a.status != 'draft')",
}


def consent_wording(org=None):
    org = org if org is not None else current_org()
    return (f"Yes, send me occasional emails from {org['name'] if org else 'us'} about its finance services. "
            "I can unsubscribe at any time.")


# ---------------------------------------------------------------- consent
def suppressed(org, email):
    return query("SELECT 1 FROM suppressions WHERE org_id = ? AND email = ?", (org, email), one=True) is not None


def is_opted_in(user):
    if not user["marketing_opt_in_at"] or user["org_id"] is None:
        return False
    return not suppressed(user["org_id"], user["email"])


def record_consent(user, action, source, email=None, org=None):
    email = user["email"] if user else email
    org = user["org_id"] if user else org
    org_row = query("SELECT * FROM organisations WHERE id = ?", (org,), one=True)
    with transaction():
        execute("INSERT INTO marketing_consent_events (org_id, user_id, email, action, source, wording, created_at) "
                "VALUES (?,?,?,?,?,?,?)", (org, user["id"] if user else None, email, action, source,
                                           consent_wording(org_row) if action == "opt_in" else "", now()))
        if action == "opt_in":
            if user:
                execute("UPDATE users SET marketing_opt_in_at = ? WHERE id = ?", (now(), user["id"]))
            execute("DELETE FROM suppressions WHERE org_id = ? AND email = ? AND reason = 'unsubscribed'", (org, email))
        else:
            if user:
                execute("UPDATE users SET marketing_opt_in_at = NULL WHERE id = ?", (user["id"],))
            execute("INSERT OR IGNORE INTO suppressions (org_id, email, reason, created_at) VALUES (?, ?, 'unsubscribed', ?)",
                    (org, email, now()))
        audit.log(f"marketing.{action}", "user", user["id"] if user else None, actor=user, source=source)


def _serializer():
    return URLSafeSerializer(current_app.config["SECRET_KEY"], salt="lmd-unsubscribe")


def unsubscribe_url(email, org=None):
    org = org if org is not None else current_org()
    return f"{mail.base_url()}/marketing/unsubscribe/{_serializer().dumps({'o': org['id'], 'e': email.lower()})}"


def _from_token(token):
    try:
        data = _serializer().loads(token)
    except BadSignature:
        abort(404)
    if isinstance(data, str):  # links sent before multi-tenancy belong to organisation 1
        data = {"o": 1, "e": data}
    if data["o"] != org_id():
        abort(404)  # a link only works on the organisation that sent it
    return data["o"], data["e"]


def _unsubscribe(org, email, source):
    user = query("SELECT * FROM users WHERE org_id = ? AND email = ?", (org, email), one=True)
    if not suppressed(org, email) or (user and user["marketing_opt_in_at"]):
        record_consent(user, "opt_out", source, email=email, org=org)


@bp.route("/marketing/unsubscribe/<token>", methods=["GET", "POST"])
def unsubscribe(token):
    org, email = _from_token(token)
    done = False
    if request.method == "POST":
        _unsubscribe(org, email, "unsubscribe link")
        done = True
    return render_template("marketing/unsubscribe.html", email=email, done=done, token=token)


@bp.route("/marketing/unsubscribe/<token>/one-click", methods=["POST"])
def unsubscribe_one_click(token):
    org, email = _from_token(token)
    _unsubscribe(org, email, "one-click unsubscribe")
    return "", 204


# ---------------------------------------------------------------- campaigns
def segment_members(segment, org=None):
    return query(_SEGMENT_SQL[segment], {"org": org if org is not None else org_id()})


def send_campaign_email(campaign, email, name, test=False):
    unsub = unsubscribe_url(email)
    subject = ("[TEST] " if test else "") + campaign["subject"]
    return mail.send(email, subject, "marketing_campaign", category="marketing",
                     headers={"List-Unsubscribe": f"<{unsub}/one-click>", "List-Unsubscribe-Post": "List-Unsubscribe=One-Click"},
                     campaign=campaign, first_name=(name or "").split(" ")[0], unsubscribe_link=unsub, test=test)


def process_due_campaigns(sleep=time.sleep):
    """Send campaigns whose time has come. Returns the number of emails sent."""
    if not current_app.config["MARKETING_ENABLED"]:
        return 0
    from flask import g
    rate = max(1, current_app.config["MARKETING_RATE_PER_MINUTE"])
    sent = 0
    for c in query("SELECT * FROM campaigns WHERE status IN ('scheduled', 'sending') AND scheduled_for <= ?", (now(),)):
        # Send as the campaign's own organisation (branding, links, sender name).
        g.org = query("SELECT * FROM organisations WHERE id = ? AND status = 'active'", (c["org_id"],), one=True)
        g.pop("site", None)
        if g.org is None:
            continue
        if c["status"] == "scheduled":
            with transaction():
                execute("UPDATE campaigns SET status = 'sending' WHERE id = ?", (c["id"],))
                for m in segment_members(c["segment"], c["org_id"]):  # snapshot of who is eligible right now
                    execute("INSERT OR IGNORE INTO campaign_recipients (campaign_id, user_id, email) VALUES (?,?,?)",
                            (c["id"], m["id"], m["email"]))
        batch = 0
        for r in query("SELECT r.*, u.full_name FROM campaign_recipients r LEFT JOIN users u ON u.id = r.user_id "
                       "WHERE r.campaign_id = ? AND r.status = 'pending'", (c["id"],)):
            # Re-check at the moment of sending: someone may have unsubscribed since the campaign started.
            if suppressed(c["org_id"], r["email"]):
                execute("UPDATE campaign_recipients SET status = 'skipped', error = 'suppressed' WHERE id = ?", (r["id"],))
                continue
            try:
                send_campaign_email(c, r["email"], r["full_name"])
                execute("UPDATE campaign_recipients SET status = 'sent', sent_at = ? WHERE id = ?", (now(), r["id"]))
                sent += 1
            except Exception as exc:
                execute("UPDATE campaign_recipients SET status = 'failed', error = ? WHERE id = ?", (type(exc).__name__, r["id"]))
            batch += 1
            if batch >= rate:
                sleep(60)
                batch = 0
        execute("UPDATE campaigns SET status = 'sent', sent_at = ? WHERE id = ?", (now(), c["id"]))
        audit.log("marketing.campaign_sent", "campaign", c["id"], actor=None, sent=sent)
    return sent


def readiness():
    """What still blocks live campaigns. Shown on the marketing page."""
    from .content import site
    s = site()
    checks = [
        ("LMD_MARKETING_ENABLED is 1", current_app.config["MARKETING_ENABLED"]),
        ("A separate marketing SMTP service is configured", bool(current_app.config["MARKETING_SMTP_HOST"])),
        ("The legal company name and registered office are published", bool(s.legal_name and s.registered_office)),
        ("A privacy notice covering marketing has been approved and published", False),
        ("PECR / UK GDPR review of the campaign content and audience completed", False),
    ]
    return checks


# ---------------------------------------------------------------- admin screens
def _campaign(cid):
    c = query("SELECT * FROM campaigns WHERE id = ? AND org_id = ?", (cid, org_id()), one=True)
    if c is None:
        abort(404)
    return c


def _read_campaign_form():
    f = {k: request.form.get(k, "").strip() for k in ("name", "subject", "preheader", "body", "segment")}
    errors = {}
    for k, limit in (("name", 120), ("subject", 150), ("preheader", 200), ("body", 8000)):
        f[k] = f[k][:limit]
    for k in ("name", "subject", "body"):
        if not f[k]:
            errors[k] = "Required."
    if f["segment"] not in SEGMENTS:
        errors["segment"] = "Choose an audience."
    return f, errors


@bp.route("/admin/marketing")
@staff_required
@permission_required("manage_marketing")
def campaigns():
    rows = query("SELECT c.*, (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent_n "
                 "FROM campaigns c WHERE c.org_id = ? ORDER BY c.created_at DESC", (org_id(),))
    counts = {k: len(segment_members(k)) for k in SEGMENTS}
    n_suppressed = query("SELECT COUNT(*) n FROM suppressions WHERE org_id = ?", (org_id(),), one=True)["n"]
    return render_template("admin/marketing.html", campaigns=rows, counts=counts, suppressed=n_suppressed,
                           checks=readiness(), SEGMENTS=SEGMENTS)


@bp.route("/admin/marketing/new", methods=["GET", "POST"])
@bp.route("/admin/marketing/<int:cid>/edit", methods=["GET", "POST"])
@staff_required
@permission_required("manage_marketing")
def edit_campaign(cid=None):
    c = _campaign(cid) if cid else None
    if c and c["status"] != "draft":
        abort(400, "Only draft campaigns can be edited.")
    form, errors = (dict(c) if c else {"segment": "all_opted_in"}), {}
    if request.method == "POST":
        form, errors = _read_campaign_form()
        if not errors:
            if c:
                execute("UPDATE campaigns SET name = ?, subject = ?, preheader = ?, body = ?, segment = ? WHERE id = ?",
                        (form["name"], form["subject"], form["preheader"], form["body"], form["segment"], c["id"]))
                cid = c["id"]
            else:
                cid = execute("INSERT INTO campaigns (org_id, name, subject, preheader, body, segment, created_by, created_at) "
                              "VALUES (?,?,?,?,?,?,?,?)", (org_id(), form["name"], form["subject"], form["preheader"],
                                                           form["body"], form["segment"], current_user()["id"], now()))
            audit.log("marketing.campaign_saved", "campaign", cid)
            flash("Campaign saved as a draft.", "success")
            return redirect(url_for("marketing.campaign", cid=cid))
    return render_template("admin/campaign_form.html", form=form, errors=errors, campaign=c, SEGMENTS=SEGMENTS)


@bp.route("/admin/marketing/<int:cid>")
@staff_required
@permission_required("manage_marketing")
def campaign(cid):
    c = _campaign(cid)
    stats = {r["status"]: r["n"] for r in query("SELECT status, COUNT(*) n FROM campaign_recipients WHERE campaign_id = ? GROUP BY status", (cid,))}
    return render_template("admin/campaign.html", c=c, stats=stats, audience=len(segment_members(c["segment"])),
                           SEGMENTS=SEGMENTS, checks=readiness())


@bp.route("/admin/marketing/<int:cid>/preview")
@staff_required
@permission_required("manage_marketing")
def preview(cid):
    c = _campaign(cid)
    msg = mail.build("preview@example.com", c["subject"], "marketing_campaign", category="marketing", campaign=c,
                     first_name="Alex", unsubscribe_link="#unsubscribe-preview", test=True)
    resp = current_app.response_class(msg.get_body(preferencelist=("html",)).get_content(), mimetype="text/html")
    resp.headers["Content-Security-Policy"] = "default-src 'none'; img-src 'self' https:; style-src 'unsafe-inline'; sandbox"
    return resp


@bp.route("/admin/marketing/<int:cid>/test", methods=["POST"])
@staff_required
@permission_required("manage_marketing")
def test_send(cid):
    c = _campaign(cid)
    user = current_user()
    send_campaign_email(c, user["email"], user["full_name"], test=True)  # only ever to the signed-in staff member
    audit.log("marketing.test_send", "campaign", cid)
    flash(f"Test email sent to {user['email']}.", "success")
    return redirect(url_for("marketing.campaign", cid=cid))


@bp.route("/admin/marketing/<int:cid>/launch", methods=["POST"])
@staff_required
@permission_required("manage_marketing")
def launch(cid):
    c = _campaign(cid)
    if c["status"] != "draft":
        abort(400, "This campaign has already been scheduled or sent.")
    if not current_app.config["MARKETING_ENABLED"]:
        abort(400, "Live campaigns are switched off (LMD_MARKETING_ENABLED is not 1). Complete the marketing checklist first.")
    if not request.form.get("confirm"):
        flash("Tick the confirmation box to schedule the campaign.", "error")
        return redirect(url_for("marketing.campaign", cid=cid))
    when = request.form.get("scheduled_for", "").strip()
    try:
        when = datetime.strptime(when, "%Y-%m-%dT%H:%M").strftime("%Y-%m-%d %H:%M:%S") if when else now()
    except ValueError:
        abort(400, "Enter a valid date and time.")
    execute("UPDATE campaigns SET status = 'scheduled', scheduled_for = ?, launched_by = ? WHERE id = ?",
            (when, current_user()["id"], cid))
    audit.log("marketing.campaign_scheduled", "campaign", cid, scheduled_for=when)
    flash("Campaign scheduled. It is sent by the scheduled send job (send-campaigns).", "success")
    return redirect(url_for("marketing.campaign", cid=cid))


@bp.route("/admin/marketing/<int:cid>/cancel", methods=["POST"])
@staff_required
@permission_required("manage_marketing")
def cancel(cid):
    c = _campaign(cid)
    if c["status"] not in ("draft", "scheduled"):
        abort(400, "Only draft or scheduled campaigns can be cancelled.")
    execute("UPDATE campaigns SET status = 'cancelled' WHERE id = ?", (cid,))
    audit.log("marketing.campaign_cancelled", "campaign", cid)
    flash("Campaign cancelled.", "success")
    return redirect(url_for("marketing.campaigns"))


@bp.route("/admin/marketing/suppressions", methods=["GET", "POST"])
@staff_required
@permission_required("manage_marketing")
def suppressions():
    if request.method == "POST":
        email = request.form.get("email", "").strip().lower()[:200]
        reason = request.form.get("reason", "manual")
        if "@" in email and reason in ("bounced", "complaint", "manual"):
            execute("INSERT OR IGNORE INTO suppressions (org_id, email, reason, created_at) VALUES (?,?,?,?)",
                    (org_id(), email, reason, now()))
            user = query("SELECT * FROM users WHERE org_id = ? AND email = ?", (org_id(), email), one=True)
            if user and user["marketing_opt_in_at"]:
                record_consent(user, "opt_out", f"staff: {reason}")
            audit.log("marketing.suppressed", "user", user["id"] if user else None, reason=reason)
            flash("Address added to the suppression list.", "success")
        else:
            flash("Enter a valid email address and reason.", "error")
        return redirect(url_for("marketing.suppressions"))
    rows = query("SELECT * FROM suppressions WHERE org_id = ? ORDER BY created_at DESC LIMIT 500", (org_id(),))
    return render_template("admin/suppressions.html", rows=rows)
