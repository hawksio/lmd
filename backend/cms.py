"""Restricted website content editor: company details and FAQs, draft -> preview -> publish."""
import json

from flask import Blueprint, abort, current_app, flash, redirect, render_template, request, url_for

from . import audit, content
from .db import query
from .security import current_user, permission_required, staff_required
from .tenancy import org_id

bp = Blueprint("cms", __name__, url_prefix="/admin/content")
PREVIEW_PAGES = ["index", "about", "solutions", "how-it-works", "faq", "contact", "privacy", "cookies", "terms",
                 "complaints", "accessibility"]


@bp.before_request
@staff_required
@permission_required("edit_content")
def _guard():
    return None


@bp.route("/", methods=["GET", "POST"])
def edit():
    data, draft_row = content.draft()
    errors = {}
    if request.method == "POST":
        settings = {k: request.form.get(k, "") for k in content.SETTING_FIELDS}
        faqs = [{"q": q, "a": a} for q, a in zip(request.form.getlist("faq_q"), request.form.getlist("faq_a"))]
        settings, faqs, errors = content.validate(settings, faqs)
        data = {"settings": settings, "faqs": faqs}
        if not errors:
            vid = content.save_draft(data, current_user(), request.form.get("note", ""))
            audit.log("content.draft_saved", "content_version", vid)
            flash("Draft saved. Preview it, then publish when it has been checked.", "success")
            return redirect(url_for("cms.edit"))
    history = query("SELECT v.id, v.status, v.note, v.created_at, v.published_at, c.full_name AS author, p.full_name AS publisher "
                    "FROM content_versions v LEFT JOIN users c ON c.id = v.created_by LEFT JOIN users p ON p.id = v.published_by "
                    "WHERE v.org_id = ? ORDER BY v.id DESC LIMIT 30", (org_id(),))
    live = content.published()
    changed = [content.SETTING_FIELDS[k][0] for k in content.SETTING_FIELDS if data["settings"].get(k) != live["settings"].get(k)]
    if data["faqs"] != live["faqs"]:
        changed.append("FAQs")
    return render_template("admin/content.html", data=data, draft_row=draft_row, errors=errors, fields=content.SETTING_FIELDS,
                           history=history, changed=changed, pages=PREVIEW_PAGES,
                           missing=content.unresolved_placeholders(live))


@bp.route("/preview/<page>")
def preview(page):
    if page not in PREVIEW_PAGES:
        abort(404)
    data, _row = content.draft()
    html = content.build_module().render_page(f"{page}.html", data, root="/", banner="PREVIEW of unpublished changes. Not visible to the public.")
    resp = current_app.response_class(html, mimetype="text/html")
    resp.headers["Cache-Control"] = "no-store"
    return resp


@bp.route("/publish", methods=["POST"])
@permission_required("publish_content")
def publish():
    _data, draft_row = content.draft()
    if draft_row is None:
        flash("There are no unpublished changes.", "info")
        return redirect(url_for("cms.edit"))
    if not request.form.get("confirm"):
        flash("Tick the box to confirm the changes have been checked and approved.", "error")
        return redirect(url_for("cms.edit"))
    content.publish(draft_row, current_user())
    audit.log("content.published", "content_version", draft_row["id"],
              keys=",".join(sorted(json.loads(draft_row["data"]).get("settings", {}).keys()))[:300])
    flash("Published. The public pages have been rebuilt.", "success")
    return redirect(url_for("cms.edit"))


@bp.route("/discard", methods=["POST"])
def discard():
    _data, draft_row = content.draft()
    if draft_row is not None:
        from .db import execute
        execute("UPDATE content_versions SET status = 'discarded' WHERE id = ? AND org_id = ?", (draft_row["id"], org_id()))
        audit.log("content.draft_discarded", "content_version", draft_row["id"])
    flash("Draft discarded.", "success")
    return redirect(url_for("cms.edit"))
