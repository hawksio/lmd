"""Outgoing email: transactional (account, application and security messages) and marketing.

Transport, per category:
  transactional  LMD_SMTP_HOST / _PORT / _USER / _PASSWORD, sender LMD_MAIL_FROM, reply-to LMD_MAIL_REPLY_TO
  marketing      LMD_MARKETING_SMTP_HOST / ..., sender LMD_MARKETING_FROM. Never falls back to the
                 transactional server, so a marketing mistake can't damage account-email deliverability.
With no SMTP host for a category (local development), messages are written to instance/outbox/*.eml
and nothing is sent. LMD_MAIL_ALLOWED_DOMAINS (staging) diverts any message for other domains to the
outbox too, so test data can't email real customers.

Every message gets a row in email_log (queued -> sent / outbox / failed). Failures record the error
type only: never passwords, tokens or message bodies.
"""
import logging
import os
import smtplib
import sqlite3
import ssl
import threading
from datetime import datetime
from email.message import EmailMessage
from email.utils import make_msgid, parseaddr

from flask import current_app, render_template

from .db import execute, now, query

log = logging.getLogger(__name__)


def base_url():
    # From configuration and the organisation's registered domains, never from the request Host header
    # (stops poisoned reset links).
    from .tenancy import base_url as tenant_url
    return tenant_url()


def staff_recipients():
    from .tenancy import current_org, settings
    org = current_org()
    if org is None:
        return []
    configured = settings(org)["staff_notification_emails"] or current_app.config.get("STAFF_EMAILS", "")
    emails = [e.strip() for e in configured.split(",") if e.strip()]
    if emails:
        return emails
    return [r["email"] for r in query("SELECT email FROM users WHERE org_id = ? AND role IN ('admin', 'underwriter') "
                                      "AND is_active = 1", (org["id"],))]


def _clean_header(value):
    return " ".join(str(value).split())[:200]  # no CR/LF: blocks header injection


def _sender(category):
    """The platform's verified sending address, shown with the organisation's name."""
    from email.utils import formataddr
    from .tenancy import current_org
    configured = current_app.config["MARKETING_FROM" if category == "marketing" else "MAIL_FROM"]
    org = current_org()
    if org is None:
        return configured
    return formataddr((_clean_header(org["name"]), parseaddr(configured)[1]))


def build(to, subject, template, category="transactional", headers=None, **context):
    """Render <template>.txt and <template>.html into one multipart message."""
    from .content import site
    context.setdefault("base_url", base_url())
    context.setdefault("subject", subject)
    context.setdefault("site", site())
    context.setdefault("category", category)
    sender = _sender(category)
    msg = EmailMessage()
    msg["From"] = sender
    msg["To"] = ", ".join(to) if isinstance(to, (list, tuple)) else to
    msg["Subject"] = _clean_header(subject)
    domain = parseaddr(sender)[1].rpartition("@")[2] or "localhost"
    msg["Message-ID"] = make_msgid(domain=domain)
    reply_to = current_app.config.get("MAIL_REPLY_TO")
    if reply_to and category == "transactional":
        msg["Reply-To"] = reply_to
    for k, v in (headers or {}).items():
        msg[k] = _clean_header(v)
    msg.set_content(render_template(f"email/{template}.txt", **context))
    msg.add_alternative(render_template(f"email/{template}.html", **context), subtype="html")
    return msg


def _transport(category):
    c = current_app.config
    prefix = "MARKETING_SMTP_" if category == "marketing" else "MAIL_SMTP_"
    return {k: c[prefix + k] for k in ("HOST", "PORT", "USER", "PASSWORD")} | {"OUTBOX": c["MAIL_OUTBOX"]}


def _diverted(recipients):
    allowed = [d.strip().lower() for d in current_app.config.get("MAIL_ALLOWED_DOMAINS", "").split(",") if d.strip()]
    if not allowed:
        return False
    return any(r.rpartition("@")[2].lower() not in allowed for r in recipients)


def _set_status(db_path, log_id, status, error=""):
    try:
        with sqlite3.connect(db_path, timeout=10) as conn:
            conn.execute("UPDATE email_log SET status = ?, error = ?, updated_at = ? WHERE id = ?",
                         (status, error[:200], now(), log_id))
    except Exception:  # logging must never break delivery
        log.exception("Could not update email_log %s", log_id)


def _deliver(cfg, msg, log_id, db_path, divert=False):
    try:
        if cfg["HOST"] and not divert:
            port = int(cfg["PORT"])
            context = ssl.create_default_context()
            if port == 465:
                server = smtplib.SMTP_SSL(cfg["HOST"], port, timeout=20, context=context)
            else:
                server = smtplib.SMTP(cfg["HOST"], port, timeout=20)
                server.starttls(context=context)
            with server:
                if cfg["USER"]:
                    server.login(cfg["USER"], cfg["PASSWORD"])
                server.send_message(msg)
            _set_status(db_path, log_id, "sent")
        else:
            os.makedirs(cfg["OUTBOX"], exist_ok=True)
            name = datetime.now().strftime("%Y%m%d-%H%M%S-%f") + ".eml"
            with open(os.path.join(cfg["OUTBOX"], name), "wb") as fh:
                fh.write(bytes(msg))
            _set_status(db_path, log_id, "outbox", "diverted: recipient outside LMD_MAIL_ALLOWED_DOMAINS" if divert else "")
    except Exception as exc:  # never let a mail failure break the request that triggered it
        log.warning("Email %s (%s) failed: %s", log_id, msg["Subject"], type(exc).__name__)
        _set_status(db_path, log_id, "failed", type(exc).__name__)


def send(to, subject, template, category="transactional", headers=None, **context):
    """Queue one message. Returns the email_log id (or None when there is no recipient)."""
    recipients = [to] if isinstance(to, str) else list(to or [])
    recipients = [r for r in recipients if r]
    if not recipients:
        return None
    msg = build(recipients, subject, template, category=category, headers=headers, **context)
    from .tenancy import current_org
    stamp = now()
    org = current_org()
    log_id = execute("INSERT INTO email_log (org_id, created_at, category, template, recipient, subject, status, updated_at) "
                     "VALUES (?,?,?,?,?,?,?,?)", (org["id"] if org else None, stamp, category, template,
                                                  ", ".join(recipients)[:500], msg["Subject"], "queued", stamp))
    capture = current_app.config.get("MAIL_CAPTURE")
    db_path = current_app.config["DATABASE"]
    if capture is not None:  # tests
        capture.append(msg)
        _set_status(db_path, log_id, "captured")
        return log_id
    args = (_transport(category), msg, log_id, db_path, _diverted(recipients))
    if current_app.config.get("MAIL_SYNC"):
        _deliver(*args)
    else:
        # Send off the request thread so a slow mail server never delays the page.
        threading.Thread(target=_deliver, args=args, daemon=True).start()
    return log_id


def ref(app_row):
    from .tenancy import ref_prefix
    return f"{ref_prefix()}-{app_row['id']:06d}"


# ------------------------------------------------------------------ staff notifications
def _org_setting(key):
    from .tenancy import settings
    return settings()[key]


def notify_staff_new_application(app_row, client):
    if _org_setting("notify_staff_new_application"):
        send(staff_recipients(), f"New application: {app_row['company_name']} ({ref(app_row)})",
             "staff_new_application", app=app_row, client=client)


def notify_staff_withdrawn(app_row, client):
    send(staff_recipients(), f"Withdrawn: {app_row['company_name']} ({ref(app_row)})",
         "staff_withdrawn", app=app_row, client=client)


def notify_staff_client_message(app_row, client):
    if not _org_setting("notify_staff_client_message"):
        return
    send(staff_recipients(), f"New client message on {ref(app_row)}", "staff_client_message", app=app_row, client=client)


def notify_staff_enquiry(enquiry):
    send(staff_recipients(), "New website enquiry", "staff_enquiry", enquiry=enquiry)


# ------------------------------------------------------------------ client messages
def notify_client_status(app_row, client, status_label, message):
    send(client["email"], f"Your application {ref(app_row)}: {status_label}",
         "client_status", app=app_row, client=client, status_label=status_label, message=message)


def send_application_received(app_row, client):
    send(client["email"], f"We have received your application {ref(app_row)}", "application_received",
         app=app_row, client=client)


def notify_client_message(app_row, client):
    # The message itself stays in the portal; email only says there is one.
    send(client["email"], f"New message about your application {ref(app_row)}", "client_new_message",
         app=app_row, client=client)


def notify_document_issued(app_row, client, title):
    send(client["email"], f"A document is ready for you: {title}", "document_issued", app=app_row, client=client, title=title)


def notify_disbursement(app_row, client, disbursement):
    send(client["email"], f"Payment made for application {ref(app_row)}", "disbursement_confirmed",
         app=app_row, client=client, d=disbursement)


def send_password_reset(user, link):
    send(user["email"], "Reset your LMD Finance password", "password_reset", user=user, link=link)


def send_verification(user, link):
    send(user["email"], "Confirm your email address", "verify_email", user=user, link=link)


def send_account_exists(user):
    send(user["email"], "Someone tried to register with your email", "account_exists", user=user)


def send_security_alert(user, what):
    send(user["email"], f"Security alert: {what}", "security_alert", user=user, what=what, now=now())


def send_enquiry_reply(enquiry, body, staff):
    return send(enquiry["email"], "Re: your enquiry to LMD Finance", "enquiry_reply", enquiry=enquiry, body=body, staff=staff)
