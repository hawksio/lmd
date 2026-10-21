"""Website content: company details and FAQs, edited as drafts and published by authorised staff.

Each save creates a row in content_versions (the full content as JSON), so the table is the revision
history. Publishing marks the draft as published, writes instance/content/published.json and rebuilds
the static pages in public/ with tools/build.py. Content is plain text only: it is HTML-escaped when the
pages are built, and no markup or script from the editor is ever executed.
"""
import copy
import json
import os
import re

from flask import current_app, g

from .db import ROOT_DIR, execute, now, query, transaction

DEFAULTS_PATH = os.path.join(ROOT_DIR, "content", "site.json")

# key: (label, placeholder shown until verified, help text, validator name)
SETTING_FIELDS = {
    "legal_name": ("Legal company name", "[Legal company name to be confirmed]", "Exactly as registered at Companies House.", "text"),
    "trading_name": ("Trading name", "[Trading name to be confirmed]", "", "text"),
    "company_number": ("Company registration number", "[Company number to be confirmed]", "8 characters, e.g. 01234567 or SC123456.", "company_number"),
    "registered_office": ("Registered office address", "[Registered office address to be confirmed]", "", "text"),
    "phone": ("Customer telephone number", "[Telephone number to be confirmed]", "", "phone"),
    "email": ("Customer contact email", "[Contact email to be confirmed]", "A monitored inbox on the company domain.", "email"),
    "opening_hours": ("Opening hours", "[Opening hours to be confirmed]", "e.g. Monday to Friday, 9am to 5pm", "text"),
    "regulatory_statement": ("Regulatory status statement", "[Regulatory status to be confirmed: do not publish an FCA statement until the firm's permissions are verified on the FCA register]",
                             "Wording approved by your compliance adviser after checking the FCA Financial Services Register.", "text"),
    "ico_registration": ("ICO registration number", "[ICO data protection registration number to be confirmed]", "From the ICO register of fee payers.", "text"),
    "complaints_contact": ("Complaints contact", "[Complaints contact details to be confirmed]", "Who to write to, and how.", "text"),
    "example_apr": ("Representative / example APR (%)", "", "Leave empty to hide all repayment examples. Only set with compliance approval.", "apr"),
    "announcement": ("Site announcement (optional)", "", "A short notice shown at the top of every page.", "text"),
}
PLACEHOLDER_RE = re.compile(r"\[[^\]]*to be confirmed[^\]]*\]", re.I)


def defaults():
    """content/site.json for the organisation that owns the static site; for every other organisation the same
    FAQs, with company details empty (shown as placeholders) apart from its own name."""
    from .tenancy import current_org, owns_static_site
    with open(DEFAULTS_PATH, encoding="utf-8") as fh:
        data = json.load(fh)
    data.pop("_note", None)
    org = current_org()
    if org is not None and not owns_static_site(org):
        data["settings"] = {k: "" for k in data["settings"]} | {"legal_name": org["name"], "trading_name": org["name"]}
        data["faqs"] = [{"q": f["q"].replace("LMD Finance", org["name"]), "a": f["a"].replace("LMD Finance", org["name"])}
                        for f in data["faqs"]]
    return data


def _normalise(data):
    base = defaults()
    out = {"settings": dict(base["settings"]), "faqs": list(base["faqs"])}
    if data:
        out["settings"].update({k: str(v) for k, v in data.get("settings", {}).items() if k in SETTING_FIELDS})
        if "faqs" in data:
            out["faqs"] = [{"q": str(f["q"]), "a": str(f["a"])} for f in data["faqs"]]
    return out


def _org():
    from .tenancy import current_org
    org = current_org()
    return org["id"] if org is not None else 0


def _latest(status):
    return query("SELECT * FROM content_versions WHERE org_id = ? AND status = ? ORDER BY id DESC LIMIT 1",
                 (_org(), status), one=True)


def published():
    row = _latest("published")
    return _normalise(json.loads(row["data"]) if row else None)


def draft():
    row = _latest("draft")
    return (_normalise(json.loads(row["data"])) if row else published()), row


class Site(dict):
    """Published settings for templates: site.phone is the value or '' ; site.show('phone') adds the placeholder."""

    def __getattr__(self, key):
        return self.get(key, "")

    def show(self, key):
        return self.get(key) or SETTING_FIELDS[key][1]


def site():
    """Published company details + FAQs, plus the organisation's branding, for templates and emails."""
    if "site" not in g:
        from .tenancy import current_org
        data = published()
        g.site = Site(data["settings"])
        g.site["faqs"] = data["faqs"]
        org = current_org()
        g.site["org_name"] = org["name"] if org else "Loan platform"
        g.site["brand_color"] = org["brand_color"] if org else "#F08A6E"
        g.site["logo_url"] = f"/branding/logo?v={org['logo_file']}" if org is not None and org["logo_file"] else ""
    return g.site


# ---------------------------------------------------------------- validation
EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


def validate(settings, faqs):
    errors = {}
    for key, (_label, _ph, _help, kind) in SETTING_FIELDS.items():
        v = settings.get(key, "").strip()
        settings[key] = v[:600]
        if not v:
            continue
        if kind == "email" and not EMAIL_RE.match(v):
            errors[key] = "Enter a valid email address."
        elif kind == "phone" and not re.fullmatch(r"[+0-9 ()-]{8,20}", v):
            errors[key] = "Enter a telephone number using digits, spaces and + only."
        elif kind == "company_number" and not re.fullmatch(r"[A-Z0-9 /-]{2,30}", v.upper()):
            errors[key] = "Use letters, numbers, spaces, / or - only."
        elif kind == "apr":
            try:
                if not 0 < float(v) < 100:
                    raise ValueError
            except ValueError:
                errors[key] = "Enter a percentage such as 12.9, or leave empty."
        if PLACEHOLDER_RE.search(v):
            errors[key] = "Remove the placeholder text, or leave the field empty."
    clean_faqs = []
    for i, f in enumerate(faqs):
        q, a = f.get("q", "").strip()[:300], f.get("a", "").strip()[:3000]
        if q or a:
            if not (q and a):
                errors[f"faq{i}"] = "Each FAQ needs a question and an answer."
            clean_faqs.append({"q": q, "a": a})
    return settings, clean_faqs, errors


def save_draft(data, user, note=""):
    with transaction():
        execute("UPDATE content_versions SET status = 'discarded' WHERE org_id = ? AND status = 'draft'", (_org(),))
        return execute("INSERT INTO content_versions (org_id, data, status, note, created_by, created_at) VALUES (?,?,?,?,?,?)",
                       (_org(), json.dumps(data, ensure_ascii=False), "draft", note[:300], user["id"], now()))


def publish(draft_row, user):
    from .tenancy import owns_static_site
    with transaction():
        execute("UPDATE content_versions SET status = 'superseded' WHERE org_id = ? AND status = 'published'", (_org(),))
        execute("UPDATE content_versions SET status = 'published', published_by = ?, published_at = ? WHERE id = ? AND org_id = ?",
                (user["id"], now(), draft_row["id"], _org()))
    data = _normalise(json.loads(draft_row["data"]))
    if owns_static_site():
        # Only this organisation's content feeds the static marketing pages in public/.
        folder = os.path.join(current_app.instance_path, "content")
        os.makedirs(folder, exist_ok=True)
        with open(os.path.join(folder, "published.json"), "w", encoding="utf-8") as fh:
            json.dump(data, fh, ensure_ascii=False, indent=2)
        rebuild_site(data)
    g.pop("site", None)
    return data


def build_module():
    import sys
    tools = os.path.join(ROOT_DIR, "tools")
    if tools not in sys.path:
        sys.path.insert(0, tools)
    import build  # tools/build.py
    return build


def rebuild_site(data):
    build_module().build(out=current_app.config["SITE_BUILD_OUT"], content=copy.deepcopy(data), quiet=True)


def unresolved_placeholders(data=None):
    data = data or published()
    required = ["legal_name", "company_number", "registered_office", "phone", "email", "regulatory_statement",
                "ico_registration", "complaints_contact"]
    return [SETTING_FIELDS[k][0] for k in required if not data["settings"].get(k)]
