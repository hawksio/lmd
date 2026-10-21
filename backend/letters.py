"""Branded PDF documents, generated from data already recorded in the system.

Safeguards:
- Approval letters and agreements use the exact figures frozen on the issued decision; nothing here invents a
  rate, fee or term. Repayment schedules use the frozen terms and the principal actually paid out.
- Every PDF is a numbered version with its SHA-256, the staff member who generated it and who issued it.
  Staff-written letters start as DRAFT (watermarked, never shown to the customer) until issued.
- Records of facts that have happened (payment receipts, disbursement confirmations, schedules) are issued
  immediately because they only restate confirmed records.
- An agreement can be issued only when the organisation has confirmed that a qualified lawyer approved its
  agreement template (Organisation settings). Until then agreements are stamped "TEMPLATE NOT LEGALLY REVIEWED".
- There is no built-in e-signature. An issued agreement is unsigned; "Agreement signed" is recorded by staff only
  after a genuinely signed copy is received through the organisation's signing process.
"""
import hashlib
import json
import os
import secrets
from datetime import datetime, timezone

from flask import current_app
from fpdf import FPDF

from .content import site
from .db import execute, now, query
from .options import BUSINESS_TYPES, LETTER_KINDS, RULE_BASES, RULE_TIMINGS
from .pricing import money
from .tenancy import current_org, org_id, ref_prefix, settings

INK = (20, 39, 43)
MUTED = (86, 103, 106)
STAFF_WRITTEN = {"acknowledgement", "info_request", "approval", "decline", "agreement", "fee_statement"}
RECORDS = {"receipt", "fee_receipt", "disbursement", "schedule"}

_REPLACE = {"‘": "'", "’": "'", "“": '"', "”": '"', "–": "-", "—": "-", "…": "...",
            " ": " ", "•": "-", "€": "EUR "}


def latin(text):
    """The built-in PDF fonts are Latin-1; swap typographic characters rather than failing."""
    text = "".join(_REPLACE.get(ch, ch) for ch in str(text or ""))
    return text.encode("latin-1", "replace").decode("latin-1")


def _rgb(hex_colour):
    h = (hex_colour or "#F08A6E").lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def ref(app_row):
    return f"{ref_prefix()}-{app_row['id']:06d}"


class Letter(FPDF):
    def __init__(self, watermark=""):
        super().__init__(format="A4", unit="mm")
        self.watermark = watermark
        self.footer_text = ""
        self.accent = _rgb(site().brand_color)
        self.set_margins(20, 18, 20)
        self.set_auto_page_break(True, margin=28)

    def multi_cell(self, w, h=None, text="", *args, **kwargs):
        # Return to the left margin after each block (fpdf2 otherwise leaves the cursor at the right edge).
        kwargs.setdefault("new_x", "LMARGIN")
        kwargs.setdefault("new_y", "NEXT")
        return super().multi_cell(w, h, text, *args, **kwargs)

    def header(self):
        s = site()
        org = current_org()
        logo = os.path.join(current_app.instance_path, "branding", str(org["id"]), org["logo_file"]) \
            if org is not None and org["logo_file"] else None
        if logo and os.path.isfile(logo):
            self.image(logo, x=20, y=13, h=16)
        else:
            self.set_text_color(*INK)
            self.set_font("Times", "B", 16)
            self.set_xy(20, 15)
            self.cell(85, 8, latin(s.trading_name or s.org_name))
        self.set_font("Helvetica", "", 8)
        self.set_text_color(*MUTED)
        lines = [s.show("legal_name"), s.show("registered_office"), s.show("phone"), s.show("email")]
        self.set_xy(110, 14)
        self.multi_cell(80, 3.8, latin("\n".join(lines)), align="R")
        self.set_draw_color(*self.accent)
        self.set_line_width(0.6)
        self.line(20, 34, 190, 34)
        self.set_y(40)
        if self.watermark:
            with self.local_context(fill_opacity=0.10, text_color=(200, 60, 40)):
                self.set_font("Helvetica", "B", 40 if len(self.watermark) > 10 else 60)
                with self.rotation(35, 105, 160):
                    self.text(25, 170, self.watermark)
        self.set_text_color(*INK)

    def footer(self):
        s = site()
        self.set_y(-24)
        self.set_draw_color(222, 230, 231)
        self.set_line_width(0.2)
        self.line(20, self.get_y(), 190, self.get_y())
        self.set_font("Helvetica", "", 7)
        self.set_text_color(*MUTED)
        self.ln(1.5)
        legal = (f"{s.show('legal_name')}. Company registration number {s.show('company_number')}. "
                 f"{s.show('regulatory_statement')}")
        self.multi_cell(0, 3.2, latin(legal))
        self.multi_cell(0, 3.2, latin(f"{self.footer_text}  -  Page {self.page_no()} of {{nb}}"))

    def h1(self, text):
        self.set_font("Times", "B", 16)
        self.set_text_color(*INK)
        self.multi_cell(0, 7, latin(text))
        self.ln(2)

    def h2(self, text):
        self.ln(2)
        self.set_font("Helvetica", "B", 10.5)
        self.multi_cell(0, 5.5, latin(text))

    def para(self, text, size=10):
        self.set_font("Helvetica", "", size)
        self.multi_cell(0, 5, latin(text))
        self.ln(2)

    def facts(self, rows):
        self.set_font("Helvetica", "", 9.5)
        for label, value in rows:
            y = self.get_y()
            self.set_text_color(*MUTED)
            self.multi_cell(62, 5.5, latin(label))
            h1 = self.get_y()
            self.set_xy(84, y)
            self.set_text_color(*INK)
            self.set_font("Helvetica", "B", 9.5)
            self.multi_cell(106, 5.5, latin(value if value not in (None, "") else "-"))
            self.set_font("Helvetica", "", 9.5)
            self.set_y(max(h1, self.get_y()))
            self.set_draw_color(222, 230, 231)
            self.line(20, self.get_y(), 190, self.get_y())
            self.ln(0.8)
        self.ln(3)

    def table(self, head, rows, widths):
        self.set_font("Helvetica", "B", 8.5)
        self.set_fill_color(240, 244, 244)
        for h, w in zip(head, widths):
            self.cell(w, 6, latin(h), border=0, fill=True)
        self.ln(6)
        self.set_font("Helvetica", "", 8.5)
        for r in rows:
            for v, w in zip(r, widths):
                self.cell(w, 5.5, latin(v))
            self.ln(5.5)
        self.ln(3)

    def notice(self, text):
        self.set_fill_color(253, 239, 234)
        self.set_draw_color(*self.accent)
        self.set_font("Helvetica", "B", 9)
        self.multi_cell(0, 5, latin(text), border="L", fill=True, padding=(2.5, 3))
        self.ln(3)
        self.set_font("Helvetica", "", 10)


def _address_block(pdf, app, client):
    pdf.set_font("Helvetica", "", 10)
    pdf.multi_cell(100, 5, latin("\n".join([client["full_name"], app["company_name"], app["address"]])))
    pdf.set_xy(130, 40)
    pdf.multi_cell(60, 5, latin(f"Date: {datetime.now(timezone.utc):%d %B %Y}\nOur ref: {ref(app)}"), align="R")
    pdf.set_y(max(pdf.get_y(), 66))
    pdf.ln(4)


def _pricing_rows(q):
    c = q["currency"]
    rows = [("Amount requested", money(q["requested"], c)), ("Amount approved", money(q["approved"], c))]
    if q["unapproved"]:
        rows.append(("Not approved", money(q["unapproved"], c)))
    rows += [("Term", f"{q['term_months']} months"),
             ("Interest rate", f"{q['annual_rate']}% a year ({'reducing balance' if q['product']['interest_method'] == 'amortising' else 'flat rate'})")]
    for f in q["fees"]:
        basis = f"{f['percent']}% of the {RULE_BASES[f['basis']].lower()}" if f["method"] == "percent" else "fixed amount"
        rows.append((f["name"], f"{money(f['amount'], c)} ({basis}; {RULE_TIMINGS[f['timing']].lower()})"))
    rows += [("Amount paid to you (after deductions)", money(q["net_advance"], c)),
             (f"{q['instalments']} {q['product']['repayment_frequency']} payments of", money(q["regular_payment"], c)),
             ("Total interest", money(q["total_interest"], c)),
             ("Total amount repayable", money(q["total_repayable"], c)),
             ("Total charge for credit", money(q["total_charge_for_credit"], c)),
             ("APR (calculated)", f"{q['apr_calculated']}%" if q["apr_calculated"] is not None else "n/a")]
    return rows


def _schedule_table(pdf, rows, currency, dated=False):
    pdf.table(["No.", "Due" if dated else "", "Principal", "Interest", "Fees", "Payment", "Balance after"],
              [[str(r["number"]), r.get("due_date", ""), money(r["principal"], currency), money(r["interest"], currency),
                money(r["fees"], currency), money(r["amount"], currency), money(r["balance"], currency)] for r in rows],
              [12, 26, 27, 25, 22, 28, 30])


def render(kind, app, client, staff, version, draft, body="", decision=None, ref_id=None):
    if kind == "agreement" and not settings()["agreement_template_approved"]:
        watermark = "TEMPLATE NOT LEGALLY REVIEWED"
    else:
        watermark = "DRAFT" if draft else ""
    pdf = Letter(watermark=watermark)
    pdf.footer_text = f"{ref(app)}  -  {LETTER_KINDS[kind]} v{version}  -  generated {now()} UTC"
    pdf.alias_nb_pages()
    pdf.add_page()
    first = client["full_name"].split(" ")[0]
    q = decision["pricing_data"] if decision else None
    signature = settings()["email_signature"] or site().show("trading_name")
    if kind != "agreement":
        _address_block(pdf, app, client)

    if kind == "acknowledgement":
        pdf.h1("We have received your application")
        pdf.para(f"Dear {first},")
        pdf.para(f"Thank you for your application for {app['company_name']}, received on {(app['submitted_at'] or '')[:10]}. "
                 f"Your reference is {ref(app)}.")
        pdf.facts([("Amount requested", money((app["amount"] or 0) * 100, app["currency"])),
                   ("Term requested", f"{app['term_months']} months" if app["term_months"] else "")])
        pdf.para("This letter is not an offer of finance and does not mean your application has been approved.")
    elif kind == "info_request":
        pdf.h1("Request for documents or information")
        pdf.para(f"Dear {first},")
        pdf.para(f"To continue assessing application {ref(app)}, we need the following:")
        pdf.para(body or "(No details entered.)")
        pdf.para("Please upload documents through your secure portal rather than by email. We will never ask for your "
                 "password or authentication codes.")
    elif kind == "approval":
        title = {"full": "Approval of your application", "partial": "Partial approval of your application",
                 "conditional": "Conditional approval of your application"}[decision["decision_type"]]
        pdf.h1(title)
        pdf.para(f"Dear {first},")
        if decision["decision_type"] == "partial":
            pdf.para(f"We have approved {money(q['approved'], q['currency'])} of the {money(q['requested'], q['currency'])} "
                     "you requested, on the terms below.")
        else:
            pdf.para("We have approved your application on the terms below.")
        pdf.facts(_pricing_rows(q))
        conds = query("SELECT text FROM decision_conditions WHERE decision_id = ?", (decision["id"],))
        if conds:
            pdf.h2("Conditions")
            pdf.para("\n".join(f"- {c['text']}" for c in conds))
        pdf.notice("This letter is not a loan agreement and no money has been paid. Funds are paid only after a loan "
                   "agreement has been issued to you and signed, and any conditions have been met.")
    elif kind == "decline":
        pdf.h1("Your application")
        pdf.para(f"Dear {first},")
        pdf.para(f"Thank you for applying. We are sorry to tell you that we are unable to offer finance on application {ref(app)}.")
        if body:
            pdf.para(body)
        pdf.para("If you would like to discuss this decision, please contact us. You can also make a complaint if you "
                 "are unhappy with how your application was handled.")
    elif kind == "agreement":
        pdf.h1("Loan agreement")
        if not settings()["agreement_template_approved"]:
            pdf.notice("Template not legally reviewed: this document must not be issued or signed until a qualified "
                       "solicitor has approved the agreement wording and the disclosures required where you operate.")
        pdf.facts([("Agreement reference", f"{ref(app)}-A{version}"),
                   ("Borrower", f"{app['company_name']} ({BUSINESS_TYPES.get(app['business_type'], '')})"),
                   ("Registration number", app["company_number"] or "Not applicable"),
                   ("Borrower address", app["address"]),
                   ("Signatory for the borrower", client["full_name"]),
                   ("Lender", site().show("legal_name"))])
        pdf.h2("1. Key financial information")
        pdf.facts(_pricing_rows(q))
        pdf.h2("2. Repayment schedule (from the first payment date)")
        _schedule_table(pdf, q["schedule"], q["currency"])
        conds = query("SELECT text FROM decision_conditions WHERE decision_id = ?", (decision["id"],))
        pdf.h2("3. Conditions")
        pdf.para("\n".join(f"- {c['text']}" for c in conds) or "None.")
        for d in q["disclosures"]:
            pdf.para(f"{d['name']}: {d['disclosure'] or d['description']}", size=9)
        pdf.h2("4. Terms and conditions")
        pdf.para("[The full terms and conditions, including repayment, early settlement, default, charges, any right of "
                 "withdrawal, complaints and all information required by law, must be inserted from the organisation's "
                 "legally approved template. They are deliberately not generated by this software.]")
        pdf.h2("5. Signatures")
        pdf.para("This agreement is not binding until it has been signed by both parties through the signing process "
                 "confirmed by the lender. A typed name in this document is not a signature.")
        pdf.facts([("Signed for the borrower", ""), ("Date", ""), ("Signed for the lender", ""), ("Date", "")])
    elif kind == "schedule":
        s = query("SELECT * FROM repayment_schedules WHERE id = ?", (ref_id,), one=True)
        inst = query("SELECT * FROM instalments WHERE schedule_id = ? ORDER BY number", (ref_id,))
        pdf.h1("Repayment schedule")
        pdf.para(f"Principal: {money(s['principal_minor'], s['currency'])}. First payment due {s['first_due_date']}.")
        _schedule_table(pdf, [{"number": i["number"], "due_date": i["due_date"], "principal": i["principal_minor"],
                               "interest": i["interest_minor"], "fees": i["fees_minor"], "amount": i["amount_minor"],
                               "balance": 0} for i in inst], s["currency"], dated=True)
    elif kind == "fee_statement":
        fees = query("SELECT * FROM fee_items WHERE application_id = ? ORDER BY id", (app["id"],))
        pdf.h1("Fee statement")
        pdf.table(["Fee", "Amount", "Status", "When payable"],
                  [[f["name"], money(f["amount_minor"], f["currency"]), f["status"], f["payable_when"][:60]] for f in fees],
                  [45, 28, 22, 75])
    elif kind in ("receipt", "fee_receipt"):
        if kind == "receipt":
            r = query("SELECT * FROM repayments WHERE id = ?", (ref_id,), one=True)
            amount, cur, when, pref, what = r["amount_minor"], r["currency"], r["received_on"], r["provider_reference"], "loan repayment"
        else:
            r = query("SELECT * FROM fee_items WHERE id = ?", (ref_id,), one=True)
            amount, cur, when, pref, what = r["amount_minor"], r["currency"], (r["paid_at"] or "")[:10], r["payment_reference"], r["name"]
        pdf.h1("Payment receipt")
        pdf.facts([("Received", money(amount, cur)), ("For", what), ("Date received", when), ("Payment reference", pref)])
        pdf.para("This receipt confirms a payment that has been received and checked by our finance team.")
    elif kind == "disbursement":
        d = query("SELECT * FROM disbursements WHERE id = ?", (ref_id,), one=True)
        pdf.h1("Disbursement confirmation")
        pdf.facts([("Principal released", money(d["amount_pence"], d["currency"])),
                   ("Fees deducted from this payment", money(d["fees_deducted_minor"], d["currency"])),
                   ("Amount paid to you", money(d["amount_pence"] - d["fees_deducted_minor"], d["currency"])),
                   ("Date paid", d["paid_on"]), ("Bank reference", d["payment_reference"])])
        pdf.para("This confirms a payment that has been made and checked against our bank record.")
    pdf.ln(4)
    if kind in STAFF_WRITTEN - {"agreement", "fee_statement"}:
        pdf.para("Yours sincerely,")
        pdf.para(f"{staff['full_name']}\n{signature}")
    return bytes(pdf.output())


def _store(kind, app, staff, version, status, data, title, body="", decision_id=None, ref_id=None):
    stored = secrets.token_hex(16) + ".pdf"
    with open(os.path.join(current_app.config["GENERATED_DIR"], stored), "xb") as fh:
        fh.write(data)
    return execute("INSERT INTO generated_documents (org_id, application_id, kind, version, status, title, body, stored_name, sha256, "
                   "decision_id, ref_id, generated_by, generated_at, issued_by, issued_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                   (org_id(), app["id"], kind, version, status, title, body[:4000], stored, hashlib.sha256(data).hexdigest(),
                    decision_id, ref_id, staff["id"], now(), staff["id"] if status == "issued" else None,
                    now() if status == "issued" else None))


def _next_version(app_id, kind):
    return (query("SELECT MAX(version) v FROM generated_documents WHERE application_id = ? AND kind = ?",
                  (app_id, kind), one=True)["v"] or 0) + 1


def generate(kind, app, staff, extra=""):
    """Create a new DRAFT of a staff-written document. Returns the generated_documents id."""
    from .underwriting import current_decision
    if kind not in STAFF_WRITTEN:
        raise ValueError("Receipts, schedules and payment confirmations are produced automatically from confirmed records.")
    client = query("SELECT * FROM users WHERE id = ?", (app["user_id"],), one=True)
    decision = None
    if kind in ("approval", "agreement"):
        decision = current_decision(app["id"])
        if decision is None:
            raise ValueError("Approval letters and agreements need an issued approval decision.")
        if kind == "agreement":
            if app["status"] not in ("partially_approved", "fully_approved", "conditionally_approved", "agreement_issued"):
                raise ValueError("An agreement can be prepared once an approval decision has been issued.")
            accepted = query("SELECT 1 FROM offer_acceptances WHERE decision_id = ?", (decision["id"],), one=True)
            if settings()["require_offer_acceptance_before_agreement"] and not accepted:
                raise ValueError("The customer has not yet accepted the offer in the portal (Organisation settings require it).")
    if kind == "decline" and app["status"] != "declined":
        raise ValueError("Set the application to Declined, with the reason, before preparing a decline letter.")
    version = _next_version(app["id"], kind)
    data = render(kind, app, client, staff, version, draft=True, body=extra, decision=decision)
    return _store(kind, app, staff, version, "draft", data, f"{LETTER_KINDS[kind]} (v{version})", body=extra,
                  decision_id=decision["id"] if decision else None)


def generate_record(kind, app, staff, ref_id):
    """Issue a record of a confirmed fact straight away (receipt, schedule, disbursement confirmation)."""
    client = query("SELECT * FROM users WHERE id = ?", (app["user_id"],), one=True)
    version = _next_version(app["id"], kind)
    data = render(kind, app, client, staff, version, draft=False, ref_id=ref_id)
    return _store(kind, app, staff, version, "issued", data, f"{LETTER_KINDS[kind]} (v{version})", ref_id=ref_id)


def issue(doc, app, staff):
    """Re-render the draft without the DRAFT mark and make it visible to the client."""
    from .underwriting import current_decision
    if doc["kind"] == "agreement" and not settings()["agreement_template_approved"]:
        raise ValueError("Agreements can't be issued until the organisation confirms its agreement template has been "
                         "legally approved (Organisation settings).")
    decision = None
    if doc["decision_id"]:
        decision = current_decision(app["id"])
        if decision is None or decision["id"] != doc["decision_id"]:
            raise ValueError("The decision behind this document has been replaced. Generate a new version.")
    client = query("SELECT * FROM users WHERE id = ?", (app["user_id"],), one=True)
    author = query("SELECT * FROM users WHERE id = ?", (doc["generated_by"],), one=True)
    data = render(doc["kind"], app, client, author, doc["version"], draft=False, body=doc["body"], decision=decision)
    with open(os.path.join(current_app.config["GENERATED_DIR"], doc["stored_name"]), "wb") as fh:
        fh.write(data)
    execute("UPDATE generated_documents SET status = 'superseded' WHERE application_id = ? AND kind = ? AND status = 'issued'",
            (app["id"], doc["kind"]))
    execute("UPDATE generated_documents SET status = 'issued', issued_by = ?, issued_at = ?, sha256 = ? WHERE id = ?",
            (staff["id"], now(), hashlib.sha256(data).hexdigest(), doc["id"]))


def pricing_json(decision):
    return json.dumps(decision["pricing_data"], indent=1)
