"""Optional AI assistance (Anthropic Claude), off unless LMD_AI_API_KEY is set.

What it may do:
- answer a signed-in client's general question using ONLY the published FAQs and status explanations;
- draft a reply to a website enquiry for a staff member to edit and send (needs LMD_AI_STAFF_FEATURES=1).
What it never does: approve, decline or price anything, change any record, see application data,
identity documents or uploads. Client questions and enquiry text are redacted (emails, phone numbers,
long digit runs) before they leave the server. Answers are labelled as AI-generated.
If the service is unavailable, clients get the closest FAQ matches instead (keyword search, no AI).
Only operational facts are logged (success/failure, error type), never the question or the answer.
"""
import logging
import re

from flask import Blueprint, abort, current_app, jsonify, request

from . import audit
from .content import site
from .options import STATUSES
from .security import can, current_user, is_staff, login_required, throttled

log = logging.getLogger(__name__)
bp = Blueprint("ai", __name__)

GUARDRAILS = (
    "You are the website help assistant for {name}, a UK business finance company. "
    "Answer ONLY from the reference information below. If it does not answer the question, say you don't know "
    "and suggest contacting the team through the client portal messages or the contact page. "
    "Never give financial, legal or tax advice, never say whether an application will be approved, never quote "
    "rates, fees or repayments, never ask for passwords, codes or bank details, and never claim to be a person. "
    "Keep answers under 120 words, in plain British English.\n\n"
)


def enabled():
    return bool(current_app.config["AI_API_KEY"])


def staff_enabled():
    return enabled() and current_app.config["AI_STAFF_FEATURES"]


REDACTIONS = [
    (re.compile(r"[^@\s]+@[^@\s]+\.[^@\s]+"), "[email]"),
    (re.compile(r"(?:\+?\d[\d\s()-]{7,}\d)"), "[number]"),
    (re.compile(r"\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b"), "[iban]"),
]


def redact(text):
    for pattern, repl in REDACTIONS:
        text = pattern.sub(repl, text)
    return text


def _reference():
    s = site()
    faqs = "\n".join(f"Q: {f['q']}\nA: {f['a']}" for f in s["faqs"])
    statuses = "\n".join(f"- {label}" for key, label in STATUSES.items() if key != "draft")
    return f"FAQs:\n{faqs}\n\nApplication statuses shown in the portal:\n{statuses}"


def _ask(system, prompt, max_tokens=1024):
    """One Messages API call. Returns text, or None on any failure (callers fall back)."""
    import anthropic
    client = anthropic.Anthropic(api_key=current_app.config["AI_API_KEY"], timeout=30.0, max_retries=1)
    try:
        response = client.beta.messages.create(
            model=current_app.config["AI_MODEL"],
            max_tokens=max_tokens,
            system=system,
            output_config={"effort": "low"},
            betas=["server-side-fallback-2026-07-01"],
            fallbacks="default",  # if the model declines, the API retries on a suitable fallback model
            messages=[{"role": "user", "content": prompt}],
        )
    except anthropic.RateLimitError:
        log.warning("AI request rate limited")
        return None
    except anthropic.APIStatusError as exc:
        log.warning("AI request failed with status %s", exc.status_code)
        return None
    except anthropic.APIConnectionError:
        log.warning("AI service unreachable")
        return None
    if response.stop_reason == "refusal":
        return None
    text = "".join(b.text for b in response.content if b.type == "text").strip()
    return text or None


def faq_matches(question, limit=3):
    """Offline fallback: rank FAQs by shared words."""
    words = {w for w in re.findall(r"[a-z]{3,}", question.lower())}
    scored = []
    for f in site()["faqs"]:
        score = len(words & set(re.findall(r"[a-z]{3,}", (f["q"] + " " + f["a"]).lower())))
        if score:
            scored.append((score, f))
    return [f for _s, f in sorted(scored, key=lambda x: -x[0])[:limit]]


@bp.route("/account/help/ask", methods=["POST"])
@login_required
def ask():
    question = (request.get_json(silent=True) or {}).get("question", "")
    question = str(question).strip()[:500]
    if len(question) < 3:
        return jsonify(ok=False, error="Type a question first."), 400
    user = current_user()
    if throttled(f"ai:{user['id']}", 20, 3600):
        return jsonify(ok=False, error="You've asked a lot of questions this hour. Please message the team instead."), 429
    answer = None
    if enabled():
        answer = _ask(GUARDRAILS.format(name=site().show("legal_name")) + _reference(), redact(question), max_tokens=1024)
    audit.log("ai.help_question", "user", user["id"], answered_by_ai=bool(answer))
    if answer:
        return jsonify(ok=True, source="ai", answer=answer)
    return jsonify(ok=True, source="faq", matches=faq_matches(question))


@bp.route("/admin/enquiries/<int:enq_id>/ai-draft", methods=["POST"])
@login_required
def draft_reply(enq_id):
    from .db import query
    if not is_staff(current_user()) or not can("use_ai_drafts") or not staff_enabled():
        abort(403)
    from .tenancy import org_id
    e = query("SELECT * FROM enquiries WHERE id = ? AND org_id = ?", (enq_id, org_id()), one=True)
    if e is None:
        abort(404)
    if throttled(f"aidraft:{current_user()['id']}", 30, 3600):
        abort(429)
    system = (GUARDRAILS.format(name=site().show("legal_name")) + _reference() +
              "\n\nYou are drafting a reply for a staff member to review and edit. Do not include a signature. "
              "Do not promise a response time.")
    text = _ask(system, "Draft a polite reply to this website enquiry:\n\n" + redact(e["message"] or "(no message)"), max_tokens=2048)
    audit.log("ai.enquiry_draft", "enquiry", enq_id, ok=bool(text))
    from flask import flash
    from .admin import render_enquiry
    if not text:
        flash("The AI service is unavailable right now. Please write the reply yourself.", "error")
        return render_enquiry(e)
    flash("AI-generated draft below. Check and edit it before sending: nothing has been sent.", "info")
    return render_enquiry(e, draft=text[:4000])
