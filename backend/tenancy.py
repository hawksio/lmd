"""Organisations (tenants).

Each request belongs to exactly one organisation, chosen by host name:
  1. an exact match in organisation_domains (custom domains, e.g. portal.acmefinance.co.uk);
  2. <slug>.<LMD_TENANT_BASE_DOMAIN> (e.g. acme.yourplatform.com);
  3. LMD_DEFAULT_ORG for anything else (development only; leave it empty in production to get a 404).
The platform-owner console lives on LMD_PLATFORM_HOST and belongs to no organisation.

Isolation rules enforced elsewhere: users carry org_id; a session is only valid on its own organisation's host
(security.current_user); every staff query filters by org_id(); files are fetched through rows that were
themselves filtered by org_id. The platform console never reads organisation customer data.
"""
import json

from flask import abort, current_app, g, request

from .db import execute, now, query

DEFAULT_SETTINGS = {
    # Workflow
    "require_verified_documents_for_underwriting": True,
    "require_offer_acceptance_before_agreement": True,
    "second_approval_threshold_minor": 0,        # 0 = every approval needs a second person; set e.g. 2500000 for 25,000.00
    "agreement_template_approved": False,         # set only after a solicitor approves the agreement template
    # Notifications
    "staff_notification_emails": "",
    "notify_staff_new_application": True,
    "notify_staff_client_message": True,
    "email_signature": "",
    # Limits for percentage settings (an organisation can tighten, never loosen, the platform caps)
    "max_percent": {},
}


def resolve():
    """before_request: set g.org (or g.platform) from the host name."""
    host = (request.host or "").split(":")[0].lower()
    g.platform = False
    g.org = None
    cfg = current_app.config
    if host == cfg["PLATFORM_HOST"].lower():
        g.platform = True
        return None
    row = query("SELECT o.* FROM organisation_domains d JOIN organisations o ON o.id = d.org_id WHERE d.host = ?", (host,), one=True)
    base = cfg["TENANT_BASE_DOMAIN"].lower()
    if row is None and base and host.endswith("." + base):
        row = query("SELECT * FROM organisations WHERE slug = ?", (host[: -len(base) - 1],), one=True)
    if row is None and cfg["DEFAULT_ORG"]:
        row = query("SELECT * FROM organisations WHERE slug = ?", (cfg["DEFAULT_ORG"],), one=True)
    if row is None:
        abort(404)
    if row["status"] != "active" and not request.path.startswith(("/assets/", "/brand/", "/api/health")):
        abort(503, "This service is not available at the moment.")
    g.org = row
    return None


def current_org():
    return g.get("org")


def org_id():
    org = g.get("org")
    if org is None:
        abort(404)
    return org["id"]


def settings(org=None):
    org = org if org is not None else current_org()
    merged = json.loads(json.dumps(DEFAULT_SETTINGS))
    if org is not None:
        merged.update(json.loads(org["settings"] or "{}"))
    return merged


def update_settings(org_id_, changes):
    row = query("SELECT settings FROM organisations WHERE id = ?", (org_id_,), one=True)
    data = json.loads(row["settings"] or "{}")
    data.update(changes)
    execute("UPDATE organisations SET settings = ? WHERE id = ?", (json.dumps(data), org_id_))
    if g.get("org") is not None and g.org["id"] == org_id_:
        g.org = query("SELECT * FROM organisations WHERE id = ?", (org_id_,), one=True)


def base_url(org=None):
    """Public address of an organisation's portal, used in email links (never taken from the request)."""
    cfg = current_app.config
    configured = cfg["BASE_URL"].rstrip("/")
    org = org if org is not None else current_org()
    if org is None:
        return configured
    domain = query("SELECT host FROM organisation_domains WHERE org_id = ? ORDER BY is_primary DESC, host LIMIT 1", (org["id"],), one=True)
    scheme = configured.split("://")[0]
    if domain:
        return f"{scheme}://{domain['host']}"
    if org["slug"] == cfg["DEFAULT_ORG"]:
        return configured
    port = configured.split("://", 1)[1].split("/")[0].partition(":")[2]
    return f"{scheme}://{org['slug']}.{cfg['TENANT_BASE_DOMAIN']}" + (f":{port}" if port else "")


def ref_prefix(org=None):
    """Application references look like LMD-000042: the organisation's slug, upper-cased, as the prefix."""
    org = org if org is not None else current_org()
    return (org["slug"] if org is not None else "APP").upper()[:8]


def owns_static_site(org=None):
    org = org if org is not None else current_org()
    return org is not None and org["slug"] == current_app.config["STATIC_SITE_ORG"]


def create(slug, name, currency="GBP"):
    return execute("INSERT INTO organisations (slug, name, default_currency, created_at) VALUES (?,?,?,?)",
                   (slug.lower(), name, currency, now()))
