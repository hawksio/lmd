"""Organisation settings: branding, workflow rules, notifications and percentage limits (administrators only)."""
import io
import os
import re
import secrets

from flask import Blueprint, abort, current_app, flash, redirect, render_template, request, send_file, url_for

from . import audit, pricing
from .db import execute, query
from .options import CURRENCIES, RULE_KINDS
from .security import permission_required, staff_required
from .tenancy import current_org, org_id, settings, update_settings

bp = Blueprint("orgsettings", __name__)
HEX_RE = re.compile(r"^#[0-9A-Fa-f]{6}$")


def text_on(hex_colour):
    """Dark or white text on a brand-coloured button, whichever has more contrast (WCAG relative luminance)."""
    h = (hex_colour or "#F08A6E").lstrip("#")

    def channel(c):
        c = int(c, 16) / 255
        return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4
    lum = 0.2126 * channel(h[0:2]) + 0.7152 * channel(h[2:4]) + 0.0722 * channel(h[4:6])
    return "#112327" if (lum + 0.05) / (0.0178 + 0.05) > 1.05 / (lum + 0.05) else "#FFFFFF"


@bp.route("/admin/settings", methods=["GET", "POST"])
@staff_required
@permission_required("manage_org_settings")
def edit():
    from .products import platform_caps
    org = current_org()
    s = settings()
    errors = {}
    if request.method == "POST":
        name = request.form.get("name", "").strip()[:120]
        colour = request.form.get("brand_color", "").strip()
        currency = request.form.get("default_currency", "")
        if len(name) < 2:
            errors["name"] = "Enter the organisation's name."
        if not HEX_RE.match(colour):
            errors["brand_color"] = "Use a colour such as #F08A6E."
        if currency not in CURRENCIES:
            errors["default_currency"] = "Choose a currency."
        threshold = pricing.parse_money(request.form.get("second_approval_threshold", "0") or "0")
        if threshold is None:
            errors["second_approval_threshold"] = "Enter an amount (0 = every approval needs a second person)."
        limits = {}
        caps = platform_caps()
        for k in RULE_KINDS:
            raw = request.form.get(f"max_{k}", "").strip()
            if raw:
                try:
                    limits[k] = pricing.validate_percent(raw, caps[k])
                except pricing.PricingError:
                    errors[f"max_{k}"] = f"Between 0 and the platform limit of {caps[k]}%."
        emails = request.form.get("staff_notification_emails", "").strip()
        if emails and not all("@" in e for e in emails.split(",")):
            errors["staff_notification_emails"] = "Separate addresses with commas."
        template_approved = bool(request.form.get("agreement_template_approved"))
        if template_approved and not s["agreement_template_approved"] and \
                request.form.get("template_confirmation", "").strip() != "APPROVED BY SOLICITOR":
            errors["agreement_template_approved"] = 'Type APPROVED BY SOLICITOR to confirm a qualified lawyer has approved your agreement template.'
        if not errors:
            execute("UPDATE organisations SET name = ?, brand_color = ?, default_currency = ? WHERE id = ?",
                    (name, colour.upper(), currency, org_id()))
            changes = {
                "require_verified_documents_for_underwriting": bool(request.form.get("require_verified_documents_for_underwriting")),
                "require_offer_acceptance_before_agreement": bool(request.form.get("require_offer_acceptance_before_agreement")),
                "second_approval_threshold_minor": threshold,
                "notify_staff_new_application": bool(request.form.get("notify_staff_new_application")),
                "notify_staff_client_message": bool(request.form.get("notify_staff_client_message")),
                "staff_notification_emails": emails[:500],
                "email_signature": request.form.get("email_signature", "").strip()[:300],
                "agreement_template_approved": template_approved,
                "max_percent": limits,
            }
            update_settings(org_id(), changes)
            audit.log("org.settings_updated", "organisation", org_id(), template_approved=template_approved,
                      threshold_minor=threshold, limits=",".join(f"{k}={v}" for k, v in limits.items()))
            flash("Settings saved.", "success")
            return redirect(url_for("orgsettings.edit"))
    return render_template("admin/org_settings.html", the_org=org, s=s, errors=errors, caps=platform_caps(), RULE_KINDS=RULE_KINDS,
                           CURRENCIES=CURRENCIES)


@bp.route("/admin/settings/logo", methods=["POST"])
@staff_required
@permission_required("manage_org_settings")
def upload_logo():
    f = request.files.get("logo")
    data = f.stream.read(1024 * 1024 + 1) if f else b""
    if not data or len(data) > 1024 * 1024:
        flash("Choose a PNG or JPG logo up to 1 MB.", "error")
        return redirect(url_for("orgsettings.edit"))
    from PIL import Image
    Image.MAX_IMAGE_PIXELS = 20_000_000
    try:
        with Image.open(io.BytesIO(data)) as img:
            if img.format not in ("PNG", "JPEG"):
                raise ValueError
            img.load()
            img.thumbnail((1200, 400))
            out = io.BytesIO()
            img.convert("RGBA").save(out, "PNG")  # re-encoded: nothing from the uploaded file survives but the pixels
    except Exception:
        flash("That file isn't a valid PNG or JPG image.", "error")
        return redirect(url_for("orgsettings.edit"))
    folder = os.path.join(current_app.instance_path, "branding", str(org_id()))
    os.makedirs(folder, exist_ok=True)
    name = secrets.token_hex(12) + ".png"
    with open(os.path.join(folder, name), "wb") as fh:
        fh.write(out.getvalue())
    execute("UPDATE organisations SET logo_file = ? WHERE id = ?", (name, org_id()))
    audit.log("org.logo_updated", "organisation", org_id())
    flash("Logo updated.", "success")
    return redirect(url_for("orgsettings.edit"))


@bp.route("/branding/logo")
def logo():
    org = current_org()
    if org is None or not org["logo_file"] or not re.fullmatch(r"[0-9a-f]{24}\.png", org["logo_file"]):
        abort(404)
    path = os.path.join(current_app.instance_path, "branding", str(org["id"]), org["logo_file"])
    if not os.path.isfile(path):
        abort(404)
    return send_file(path, mimetype="image/png", max_age=86400)
