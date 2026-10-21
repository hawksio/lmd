"""Multi-organisation SaaS features: tenancy, products and financial rules, underwriting, money records,
reports, subscriptions and the platform console.

Run:  .venv\\Scripts\\python -m unittest discover -s tests -v

All email goes to an in-memory capture and all payments are records typed in by test "staff": nothing is sent or
transferred anywhere.
"""
import io
import json
import re
import unittest
from datetime import date, timedelta

from test_backend import PASSWORD, PDF
from test_features import FeatureCase, png_bytes

TODAY = date.today().isoformat()


class SaasCase(FeatureCase):
    def setUp(self):
        super().setUp()
        self.admin = self.client_for()
        self.login(self.admin, "staff@lmd.test")

    # ------------------------------------------------------------ helpers
    def make_product(self, client=None, **over):
        data = {"name": "Growth loan", "description": "For established businesses", "finance_type": "loans",
                "currencies": ["EUR", "GBP"], "min_amount": "1000", "max_amount": "100000", "min_term_months": "3",
                "max_term_months": "60", "grace_period_months": "0", "interest_method": "amortising",
                "repayment_frequency": "monthly", "repayment_method": "Direct debit", "eligibility": "Trading 2+ years",
                "required_docs": ["identity"], "form_fields": "[]", "active": "1"} | over
        r = self.post(client or self.admin, "/admin/products/new", data, page="/admin/products/new")
        self.assertEqual(r.status_code, 302, r.get_data(as_text=True)[:2000])
        return int(r.headers["Location"].rstrip("/").split("/")[-1])

    def make_rule(self, client=None, expect=302, **over):
        data = {"kind": "processing_fee", "name": "Processing fee", "method": "percent", "percent": "2", "basis": "approved",
                "timing": "deducted_from_disbursement", "disclosure": "Charged once, taken from the first payment.",
                "effective_from": "2020-01-01", "enabled": "1", "confirm": "1"} | over
        r = self.post(client or self.admin, "/admin/financial-settings/new", data, page="/admin/financial-settings")
        self.assertEqual(r.status_code, expect, r.get_data(as_text=True)[:3000])
        return int(r.headers["Location"].split("/")[-1]) if expect == 302 else r

    def standard_pricing(self, pid=None):
        self.make_rule(kind="interest", name="Standard rate", percent="12", timing="interest", basis="approved", disclosure="")
        self.make_rule()  # 2% processing fee, deducted from the first payment

    def apply(self, client, product_id, amount="50000", currency="EUR", term="12"):
        t = self.token(client, "/account/applications/new")
        data = self.full_application(t) | {"product_id": str(product_id), "currency": currency, "amount": amount, "term_months": term}
        r = client.post("/account/applications/new", data=data)
        self.assertEqual(r.status_code, 302, r.get_data(as_text=True)[:3000])
        return int(re.search(r"/applications/(\d+)", r.headers["Location"]).group(1))

    def upload_and_verify(self, customer, app_id, reviewer, doc_type="identity"):
        self.post(customer, f"/account/applications/{app_id}/documents", {"document": (io.BytesIO(PDF), "id.pdf"), "doc_type": doc_type},
                  content_type="multipart/form-data")
        doc_id = self.q("SELECT id FROM documents WHERE application_id = ? ORDER BY id DESC", (app_id,))[0]["id"]
        self.post(reviewer, f"/admin/documents/{doc_id}/review", {"review_status": "verified"}, page="/admin/documents/")
        return doc_id

    def propose(self, client, app_id, pid, amount, kind="partial", term="12", conditions="", expect=302):
        r = self.post(client, f"/admin/applications/{app_id}/decision",
                      {"product_id": str(pid), "decision_type": kind, "approved_amount": amount, "term_months": term,
                       "conditions": conditions, "reason": "Affordability checked against 12 months of statements.",
                       "action": "propose"}, page=f"/admin/applications/{app_id}")
        self.assertEqual(r.status_code, expect, r.get_data(as_text=True)[:3000])
        return self.q("SELECT * FROM approval_decisions WHERE application_id = ? ORDER BY id DESC", (app_id,))[0]

    def issue(self, client, did, expect=302):
        r = self.post(client, f"/admin/decisions/{did}/issue", {"confirm": "1"}, page="/admin/approvals")
        self.assertEqual(r.status_code, expect)
        return r

    def status(self, app_id):
        return self.q("SELECT status FROM applications WHERE id = ?", (app_id,))[0]["status"]

    def approve_template(self, org=1):
        from backend import tenancy
        with self.app.app_context():
            tenancy.update_settings(org, {"agreement_template_approved": True})


# ================================================================== pricing engine
class PricingTests(unittest.TestCase):
    PRODUCT = {"id": 1, "name": "T", "interest_method": "amortising", "repayment_frequency": "monthly", "grace_period_months": 0}

    @staticmethod
    def rule(**k):
        base = {"id": 1, "version": 1, "name": k.get("kind", "x"), "method": "percent", "percent": None, "fixed_minor": None,
                "basis": "approved", "timing": "interest", "disclosure": "", "description": "", "enabled": 1,
                "effective_from": "2020-01-01", "effective_to": None, "product_ids": "", "currencies": ""}
        return base | k

    def test_percentage_fee_is_exact(self):
        from backend.pricing import quote
        fee = self.rule(id=2, kind="processing_fee", percent="2", timing="deducted_from_disbursement")
        q = quote(self.PRODUCT, [fee], 1_000_000, 1_000_000, 12, "EUR")  # €10,000
        self.assertEqual(q["fees"][0]["amount"], 20_000)                  # €200
        self.assertEqual(q["net_advance"], 980_000)
        self.assertEqual(q["fees"][0]["basis"], "approved")

    def test_amortising_and_flat_interest(self):
        from backend.pricing import quote
        rate = self.rule(kind="interest", percent="12")
        q = quote(self.PRODUCT, [rate], 1_000_000, 1_000_000, 12, "EUR")
        self.assertEqual(q["regular_payment"], 88_849)                   # €888.49 a month
        self.assertEqual(q["total_interest"], 66_185)
        self.assertEqual(sum(r["principal"] for r in q["schedule"]), 1_000_000)
        self.assertEqual(q["schedule"][-1]["balance"], 0)
        flat = quote(dict(self.PRODUCT, interest_method="flat"), [rate], 1_000_000, 1_000_000, 12, "EUR")
        self.assertEqual(flat["total_interest"], 120_000)                # 12% flat for one year

    def test_basis_requested_vs_approved_and_timings(self):
        from backend.pricing import quote
        rules = [self.rule(id=3, kind="admin_fee", percent="1", basis="requested", timing="added_to_loan"),
                 self.rule(id=4, kind="document_fee", method="fixed", fixed_minor=5_000, timing="with_first_instalment"),
                 self.rule(id=5, kind="commission", percent="1.5", timing="not_charged_to_customer"),
                 self.rule(id=6, kind="late_charge", percent="3", basis="instalment", timing="on_overdue_instalment")]
        q = quote(self.PRODUCT, rules, 5_000_000, 3_000_000, 24, "EUR")
        admin_fee = next(f for f in q["fees"] if f["kind"] == "admin_fee")
        self.assertEqual(admin_fee["amount"], 50_000)                    # 1% of the €50,000 requested
        self.assertEqual(q["financed_principal"], 3_050_000)
        self.assertEqual(q["schedule"][0]["fees"], 5_000)
        self.assertEqual(q["unapproved"], 2_000_000)
        self.assertEqual({d["kind"] for d in q["disclosures"]}, {"commission", "late_charge"})  # disclosed, not charged now

    def test_rule_selection_by_product_currency_and_date(self):
        from backend.pricing import PricingError, quote, select_rules
        general = self.rule(id=1, kind="interest", percent="10")
        specific = self.rule(id=2, kind="interest", percent="8", product_ids="1")
        future = self.rule(id=3, kind="processing_fee", percent="2", timing="deducted_from_disbursement", effective_from="2999-01-01")
        gbp_only = self.rule(id=4, kind="admin_fee", percent="1", timing="added_to_loan", currencies="GBP")
        interest, others = select_rules([general, specific, future, gbp_only], 1, "EUR", TODAY)
        self.assertEqual(interest["percent"], "8")
        self.assertEqual(others, [])
        with self.assertRaises(PricingError):
            quote(self.PRODUCT, [self.rule(id=1, kind="interest", percent="10"), self.rule(id=2, kind="interest", percent="9")],
                  100_000, 100_000, 12, "EUR")

    def test_percentage_validation(self):
        from backend.pricing import PricingError, parse_money, validate_percent
        self.assertEqual(validate_percent("2.50", "100"), "2.5")
        self.assertEqual(validate_percent("0", "100"), "0")
        for bad in ("-1", "abc", "1.23456", "150", "NaN", "inf"):
            with self.assertRaises(PricingError):
                validate_percent(bad, "100")
        with self.assertRaises(PricingError):
            validate_percent("21", "20")
        self.assertEqual(parse_money("10,000.50"), 1_000_050)
        self.assertIsNone(parse_money("1.005"))
        self.assertIsNone(parse_money("-5"))


# ================================================================== financial settings
class FinancialSettingsTests(SaasCase):
    def test_rules_save_with_history_and_permissions(self):
        rid = self.make_rule()
        r = self.post(self.admin, f"/admin/financial-settings/{rid}", {
            "kind": "processing_fee", "name": "Processing fee", "method": "percent", "percent": "2.5", "basis": "approved",
            "timing": "deducted_from_disbursement", "disclosure": "Charged once, taken from the first payment.",
            "effective_from": "2020-01-01", "enabled": "1", "confirm": "1", "reason": "Board pricing review"},
            page="/admin/financial-settings")
        self.assertEqual(r.status_code, 302)
        history = self.q("SELECT * FROM financial_rule_history WHERE rule_id = ? ORDER BY id", (rid,))
        self.assertEqual([h["action"] for h in history], ["created", "updated"])
        self.assertEqual(json.loads(history[1]["old_values"])["percent"], "2")
        self.assertEqual(json.loads(history[1]["new_values"])["percent"], "2.5")
        self.assertEqual(history[1]["reason"], "Board pricing review")
        page = self.admin.get(f"/admin/financial-settings/{rid}").get_data(as_text=True)
        self.assertIn("Board pricing review", page)
        # Only administrators change settings; an underwriter can't, even by posting directly.
        uw = self.add_staff("uw@lmd.test", "underwriter")
        self.assertEqual(self.post(uw, "/admin/financial-settings/new", {"kind": "admin_fee"}, page="/admin/").status_code, 403)
        self.assertEqual(uw.get("/admin/financial-settings").status_code, 403)
        fin = self.add_staff("fin@lmd.test", "finance")
        self.assertEqual(fin.get("/admin/financial-settings").status_code, 200)  # finance may read
        self.assertEqual(self.post(fin, f"/admin/financial-settings/{rid}/toggle", {"confirm": "1"}, page="/admin/").status_code, 403)

    def test_validation_limits_and_commission_reference(self):
        r = self.make_rule(expect=200, percent="150")
        self.assertIn("limit for this setting is 100%", r.get_data(as_text=True))
        r = self.make_rule(expect=200, kind="commission", timing="not_charged_to_customer", name="Introducer commission")
        self.assertIn("documented arrangement", r.get_data(as_text=True))
        r = self.make_rule(expect=200, confirm="")
        self.assertIn("approved under your pricing policy", r.get_data(as_text=True))
        r = self.make_rule(expect=200, kind="late_charge", timing="deducted_from_disbursement")
        self.assertIn("When this applies", r.get_data(as_text=True).replace("when this applies", "When this applies"))
        # Organisation limit tighter than the platform's.
        self.post(self.admin, "/admin/settings", {"name": "LMD Finance Ltd", "brand_color": "#F08A6E", "default_currency": "GBP",
                                                  "second_approval_threshold": "0", "max_processing_fee": "3"}, page="/admin/settings")
        r = self.make_rule(expect=200, percent="4")
        self.assertIn("limit for this setting is 3%", r.get_data(as_text=True))
        self.make_rule(percent="3")

    def test_calculator_shows_breakdown(self):
        pid = self.make_product()
        self.make_rule()
        page = self.admin.get(f"/admin/financial-settings?calc_product={pid}&calc_currency=EUR&calc_amount=10000&calc_term=12").get_data(as_text=True)
        self.assertIn("€200.00", page)
        self.assertIn("2% of the approved amount", page)
        self.assertIn("€9,800.00", page)  # paid to the customer after the deducted fee


# ================================================================== the full lifecycle
class LifecycleTests(SaasCase):
    def test_partial_approval_to_repayment(self):
        pid = self.make_product()
        self.standard_pricing()
        uw2 = self.add_staff("second@lmd.test", "underwriter")
        reviewer = self.add_staff("docs@lmd.test", "document_reviewer")
        fin1 = self.add_staff("fin1@lmd.test", "finance")
        fin2 = self.add_staff("fin2@lmd.test", "finance")
        customer = self.client_for()
        self.register(customer, "borrower@example.com")
        app_id = self.apply(customer, pid, amount="50000")
        self.assertEqual(self.q("SELECT currency, product_id FROM applications WHERE id = ?", (app_id,))[0]["currency"], "EUR")

        # Decisions wait for verified documents (organisation setting, on by default).
        r = self.post(self.admin, f"/admin/applications/{app_id}/decision", {"product_id": str(pid), "decision_type": "partial",
                      "approved_amount": "30000", "term_months": "12", "reason": "x" * 20, "action": "propose"},
                      page=f"/admin/applications/{app_id}", follow_redirects=True)
        self.assertIn("not all verified", r.get_data(as_text=True))
        # The reviewer can verify documents but not make lending decisions.
        self.upload_and_verify(customer, app_id, reviewer)
        self.assertEqual(self.status(app_id), "submitted")  # verifying a document is not a decision
        self.assertEqual(self.post(reviewer, f"/admin/applications/{app_id}/decision", {}, page="/admin/").status_code, 403)

        # Preview calculates without saving; validation rejects nonsense.
        r = self.post(self.admin, f"/admin/applications/{app_id}/decision", {"product_id": str(pid), "decision_type": "partial",
                      "approved_amount": "30000", "term_months": "12", "reason": "Affordability checked thoroughly.",
                      "action": "preview"}, page=f"/admin/applications/{app_id}")
        self.assertIn("Preview (not saved)", r.get_data(as_text=True))
        self.assertIn("€600.00", r.get_data(as_text=True))  # 2% of €30,000
        self.assertFalse(self.q("SELECT 1 FROM approval_decisions"))
        r = self.post(self.admin, f"/admin/applications/{app_id}/decision", {"product_id": str(pid), "decision_type": "partial",
                      "approved_amount": "60000", "term_months": "12", "reason": "Affordability checked thoroughly.",
                      "action": "propose"}, page=f"/admin/applications/{app_id}")
        self.assertIn("can&#39;t exceed the requested amount", r.get_data(as_text=True))
        r = self.post(self.admin, f"/admin/applications/{app_id}/decision", {"product_id": str(pid), "decision_type": "full",
                      "approved_amount": "30000", "term_months": "12", "reason": "Affordability checked thoroughly.",
                      "action": "propose"}, page=f"/admin/applications/{app_id}")
        self.assertIn("must approve the whole requested amount", r.get_data(as_text=True))

        # Propose a partial approval. The customer sees nothing until it is issued, by someone else.
        d = self.propose(self.admin, app_id, pid, "30000")
        self.assertEqual((d["requested_minor"], d["approved_minor"], d["status"]), (5_000_000, 3_000_000, "proposed"))
        self.assertNotIn("Financing approved", customer.get(f"/account/applications/{app_id}").get_data(as_text=True))
        self.issue(self.admin, d["id"], expect=403)
        self.issue(uw2, d["id"])
        self.assertEqual(self.status(app_id), "partially_approved")
        page = customer.get(f"/account/applications/{app_id}").get_data(as_text=True)
        for text in ("€50,000.00", "€30,000.00", "€20,000.00 not approved", "Partially approved", "Funds actually disbursed",
                     "not money in your account"):
            self.assertIn(text, page)
        self.assertTrue(self.mail_to("borrower@example.com", "Partially approved"))

        # Revise the approval (needs re-issuing); the history keeps both revisions.
        d2 = self.propose(self.admin, app_id, pid, "35000")
        self.assertEqual(d2["previous_approved_minor"], 3_000_000)
        self.issue(uw2, d2["id"])
        revs = self.q("SELECT revision, status, approved_minor FROM approval_decisions WHERE application_id = ? ORDER BY revision", (app_id,))
        self.assertEqual([(r["revision"], r["status"]) for r in revs], [(1, "superseded"), (2, "issued")])

        # Changing the fee setting now must not change the offer that was made.
        rid = self.q("SELECT id FROM financial_rules WHERE kind = 'processing_fee'")[0]["id"]
        self.post(self.admin, f"/admin/financial-settings/{rid}", {"kind": "processing_fee", "name": "Processing fee", "method": "percent",
                  "percent": "5", "basis": "approved", "timing": "deducted_from_disbursement", "disclosure": "Charged once, from the payment.",
                  "effective_from": "2020-01-01", "enabled": "1", "confirm": "1"}, page="/admin/financial-settings")
        frozen = json.loads(self.q("SELECT pricing FROM approval_decisions WHERE id = ?", (d2["id"],))[0]["pricing"])
        self.assertEqual(frozen["fees"][0]["percent"], "2")
        self.assertEqual(frozen["fees"][0]["amount"], 70_000)  # 2% of €35,000, not 5%

        # The customer reviews the full breakdown, then accepts; the agreement needs that acceptance.
        offer = customer.get(f"/account/applications/{app_id}/offer").get_data(as_text=True)
        for text in ("12% a year", "€700.00", "Total amount repayable", "This is an offer, not a payment"):
            self.assertIn(text, offer)
        r = self.post(self.admin, f"/admin/applications/{app_id}/letters", {"kind": "agreement"}, page="/admin/", follow_redirects=True)
        self.assertIn("has not yet accepted the offer", r.get_data(as_text=True))
        self.post(customer, f"/account/applications/{app_id}/offer", {"accept": "1"})
        self.assertEqual(len(self.q("SELECT * FROM offer_acceptances")), 1)

        # Agreement: drafted, but only issued once the organisation confirms its template is legally approved.
        self.post(self.admin, f"/admin/applications/{app_id}/letters", {"kind": "agreement"}, page="/admin/")
        agreement = self.q("SELECT * FROM generated_documents WHERE kind = 'agreement'")[0]
        self.assertTrue(self.admin.get(f"/admin/letters/{agreement['id']}").data.startswith(b"%PDF"))
        self.post(self.admin, f"/admin/letters/{agreement['id']}/issue", {"confirm": "1"}, page="/admin/")
        self.assertEqual(self.q("SELECT status FROM generated_documents WHERE id = ?", (agreement["id"],))[0]["status"], "draft")
        self.approve_template()
        self.post(self.admin, f"/admin/letters/{agreement['id']}/issue", {"confirm": "1"}, page="/admin/")
        self.assertEqual(self.status(app_id), "agreement_issued")
        self.post(self.admin, f"/admin/applications/{app_id}/status", {"status": "agreement_signed", "reason": "Signed copy received by post"},
                  page="/admin/")
        fee = self.q("SELECT * FROM fee_items WHERE application_id = ?", (app_id,))[0]
        self.assertEqual((fee["amount_minor"], fee["status"], fee["timing"]), (70_000, "pending", "deducted_from_disbursement"))

        # Disbursement 1 (tranche): scheduled -> paid -> confirmed by a different person.
        r = self.post(fin1, f"/admin/applications/{app_id}/disbursements", {"amount": "40000", "scheduled_for": TODAY}, page="/admin/",
                      follow_redirects=True)
        self.assertIn("left to schedule", r.get_data(as_text=True))
        self.post(fin1, f"/admin/applications/{app_id}/disbursements", {"amount": "20000", "scheduled_for": TODAY}, page="/admin/")
        self.assertEqual(self.status(app_id), "funding_scheduled")
        did = self.q("SELECT * FROM disbursements WHERE application_id = ?", (app_id,))[0]
        self.assertEqual(did["fees_deducted_minor"], 70_000)
        self.assertIn("€0.00", customer.get(f"/account/applications/{app_id}").get_data(as_text=True))  # scheduled is not disbursed
        future = (date.today() + timedelta(days=3)).isoformat()
        r = self.post(fin1, f"/admin/disbursements/{did['id']}/paid", {"paid_on": future, "method": "sepa", "payment_reference": "SEPA-1"},
                      page="/admin/", follow_redirects=True)
        self.assertIn("not in the future", r.get_data(as_text=True))
        self.post(fin1, f"/admin/disbursements/{did['id']}/paid", {"paid_on": TODAY, "method": "sepa", "payment_reference": "SEPA-1"}, page="/admin/")
        self.assertEqual(self.status(app_id), "funding_scheduled")  # paid but unconfirmed still isn't disbursed
        self.assertEqual(self.post(fin1, f"/admin/disbursements/{did['id']}/confirm", {"bank_checked": "1",
                                   "confirmation_source": "bank_statement"}, page="/admin/").status_code, 403)
        self.post(fin2, f"/admin/disbursements/{did['id']}/confirm", {"bank_checked": "1", "confirmation_source": "bank_statement"}, page="/admin/")
        self.assertEqual(self.status(app_id), "partially_disbursed")
        self.assertEqual(self.q("SELECT status FROM fee_items WHERE id = ?", (fee["id"],))[0]["status"], "paid")
        self.assertTrue(self.q("SELECT 1 FROM generated_documents WHERE kind = 'disbursement' AND status = 'issued'"))

        # Disbursement 2 completes the approved amount.
        self.post(fin1, f"/admin/applications/{app_id}/disbursements", {"amount": "15000", "scheduled_for": TODAY}, page="/admin/")
        d_two = self.q("SELECT id FROM disbursements WHERE application_id = ? ORDER BY id DESC", (app_id,))[0]["id"]
        r = self.post(fin1, f"/admin/disbursements/{d_two}/paid", {"paid_on": TODAY, "method": "sepa", "payment_reference": "SEPA-1"},
                      page="/admin/", follow_redirects=True)
        self.assertIn("already been recorded", r.get_data(as_text=True))  # same bank reference twice
        self.post(fin1, f"/admin/disbursements/{d_two}/paid", {"paid_on": TODAY, "method": "sepa", "payment_reference": "SEPA-2"}, page="/admin/")
        self.post(fin2, f"/admin/disbursements/{d_two}/confirm", {"bank_checked": "1", "confirmation_source": "bank_statement"}, page="/admin/")
        self.assertEqual(self.status(app_id), "fully_disbursed")
        summary = customer.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("€35,000.00", summary)

        # Repayment schedule from the frozen terms, then repayments with idempotency and reversal.
        first_due = (date.today() + timedelta(days=30)).isoformat()
        self.post(fin1, f"/admin/applications/{app_id}/schedule", {"first_due_date": first_due}, page="/admin/")
        inst = self.q("SELECT * FROM instalments ORDER BY number")
        self.assertEqual(len(inst), 12)
        self.assertEqual(sum(i["principal_minor"] for i in inst), 3_500_000)
        self.assertEqual(inst[0]["due_date"], first_due)
        pay = {"amount": f"{inst[0]['amount_minor'] / 100:.2f}", "received_on": TODAY, "method": "direct_debit",
               "provider_reference": "DD-0001", "confirmation_source": "bank_statement"}
        self.post(fin1, f"/admin/applications/{app_id}/repayments", pay, page="/admin/")
        r = self.post(fin1, f"/admin/applications/{app_id}/repayments", pay, page="/admin/", follow_redirects=True)
        self.assertIn("no duplicate", r.get_data(as_text=True))
        self.assertEqual(len(self.q("SELECT * FROM repayments")), 1)
        rep = self.q("SELECT * FROM repayments")[0]
        receipt = self.q("SELECT * FROM generated_documents WHERE kind = 'receipt' AND ref_id = ?", (rep["id"],))[0]
        self.assertEqual(customer.get(f"/account/letters/{receipt['id']}").status_code, 200)
        page = customer.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("Outstanding principal", page)
        self.assertIn("Paid", page)
        self.post(fin1, f"/admin/repayments/{rep['id']}/reverse", {"reason": "Bank recall of the payment", "confirm": "1"}, page="/admin/")
        self.assertEqual(self.q("SELECT status FROM repayments")[0]["status"], "reversed")
        self.assertEqual(customer.get(f"/account/letters/{receipt['id']}").status_code, 404)  # receipt withdrawn
        # No late charge on an instalment that isn't overdue.
        self.assertEqual(self.post(self.admin, f"/admin/instalments/{inst[0]['id']}/late-charge", {}, page="/admin/").status_code, 400)

        history = [h["status"] for h in self.q("SELECT status FROM status_history WHERE application_id = ? ORDER BY id", (app_id,))]
        self.assertEqual(history, ["submitted", "underwriting", "partially_approved", "partially_approved", "agreement_issued",
                                   "agreement_signed", "funding_scheduled", "partially_disbursed", "fully_disbursed"])
        events = {r["event"] for r in self.q("SELECT event FROM audit_log")}
        for e in ("decision.proposed", "decision.issued", "decision.offer_accepted", "disbursement.confirmed", "repayment.recorded",
                  "repayment.reversed", "financial_rule.updated", "letter.issued"):
            self.assertIn(e, events)

    def test_full_and_conditional_approvals_and_decline(self):
        pid = self.make_product(required_docs=[])
        self.standard_pricing()
        uw2 = self.add_staff("second@lmd.test", "underwriter")
        c1, c2, c3 = self.client_for(), self.client_for(), self.client_for()
        self.register(c1, "full@example.com")
        self.register(c2, "cond@example.com")
        self.register(c3, "decline@example.com")
        a1, a2, a3 = self.apply(c1, pid, "20000"), self.apply(c2, pid, "20000"), self.apply(c3, pid, "20000")
        d = self.propose(self.admin, a1, pid, "20000", kind="full")
        self.issue(uw2, d["id"])
        self.assertEqual(self.status(a1), "fully_approved")
        # Conditional approval needs its conditions written down.
        r = self.post(self.admin, f"/admin/applications/{a2}/decision", {"product_id": str(pid), "decision_type": "conditional",
                      "approved_amount": "15000", "term_months": "12", "reason": "Subject to director guarantee.", "action": "propose"},
                      page="/admin/")
        self.assertIn("List the conditions", r.get_data(as_text=True))
        d = self.propose(self.admin, a2, pid, "15000", kind="conditional", conditions="Signed director guarantee\nLatest VAT return")
        self.issue(uw2, d["id"])
        self.assertEqual(self.status(a2), "conditionally_approved")
        page = c2.get(f"/account/applications/{a2}").get_data(as_text=True)
        self.assertIn("Signed director guarantee", page)
        cond = self.q("SELECT id FROM decision_conditions WHERE text = 'Latest VAT return'")[0]["id"]
        self.post(self.admin, f"/admin/conditions/{cond}", {"status": "satisfied"}, page="/admin/")
        self.assertNotIn("Latest VAT return;", c2.get(f"/account/applications/{a2}").get_data(as_text=True))
        # Decline with a recorded reason, then a decline letter.
        r = self.post(self.admin, f"/admin/applications/{a3}/status", {"status": "declined"}, page="/admin/", follow_redirects=True)
        self.assertIn("Record the reason", r.get_data(as_text=True))
        self.post(self.admin, f"/admin/applications/{a3}/status", {"status": "declined", "reason": "Insufficient trading history",
                                                                   "message": "We can't offer finance at this time."}, page="/admin/")
        self.assertEqual(self.status(a3), "declined")
        self.post(self.admin, f"/admin/applications/{a3}/letters", {"kind": "decline", "body": "We were unable to verify affordability."},
                  page="/admin/")
        letter = self.q("SELECT * FROM generated_documents WHERE application_id = ? AND kind = 'decline'", (a3,))[0]
        self.assertTrue(self.admin.get(f"/admin/letters/{letter['id']}").data.startswith(b"%PDF"))
        # Approval letter PDF for the partial/full decision.
        self.post(self.admin, f"/admin/applications/{a1}/letters", {"kind": "approval"}, page="/admin/")
        self.assertTrue(self.q("SELECT 1 FROM generated_documents WHERE application_id = ? AND kind = 'approval'", (a1,)))

    def test_document_rejection_and_resubmission(self):
        pid = self.make_product()
        reviewer = self.add_staff("docs@lmd.test", "document_reviewer")
        customer = self.client_for()
        self.register(customer, "resubmit@example.com")
        app_id = self.apply(customer, pid)
        page = customer.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("Not submitted", page)  # required identity document
        self.post(customer, f"/account/applications/{app_id}/documents", {"document": (io.BytesIO(png_bytes()), "id.png"), "doc_type": "identity"},
                  content_type="multipart/form-data")
        doc_id = self.q("SELECT id FROM documents")[0]["id"]
        self.assertTrue(self.q("SELECT 1 FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email = 'docs@lmd.test'"))
        queue = reviewer.get("/admin/documents/").get_data(as_text=True)
        self.assertIn("id.png", queue)
        self.post(reviewer, f"/admin/documents/{doc_id}/review", {"review_status": "rejected", "customer_note": "The photo is blurred."},
                  page="/admin/documents/")
        self.post(customer, f"/account/applications/{app_id}/documents", {"document": (io.BytesIO(PDF), "id-new.pdf"), "replaces": str(doc_id)},
                  content_type="multipart/form-data")
        new = self.q("SELECT * FROM documents ORDER BY id DESC")[0]
        self.assertEqual((new["replaces_id"], new["doc_type"], new["review_status"]), (doc_id, "identity", "submitted"))
        # Only rejected/expired/replacement-required documents can be replaced.
        r = self.post(customer, f"/account/applications/{app_id}/documents", {"document": (io.BytesIO(PDF), "x.pdf"), "replaces": str(new["id"])},
                      content_type="multipart/form-data")
        self.assertEqual(r.status_code, 400)
        self.post(reviewer, f"/admin/documents/{new['id']}/review", {"review_status": "verified"}, page="/admin/documents/")
        history = self.q("SELECT status FROM document_reviews WHERE document_id = ? ORDER BY id", (doc_id,))
        self.assertEqual([h["status"] for h in history], ["rejected", "rejected"])  # second entry records the replacement
        page = customer.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("Verified", page)
        self.assertEqual(self.status(app_id), "submitted")  # verification never approves anything


# ================================================================== tenancy and isolation
class TenancyTests(SaasCase):
    def make_org(self, slug="beta", name="Beta Lending Ltd"):
        from backend import tenancy
        with self.app.app_context():
            oid = tenancy.create(slug, name, "EUR")
        staff = self.add_staff(f"admin@{slug}.test", "admin", org=oid, host=f"{slug}.localhost")
        return oid, staff

    def test_organisations_are_isolated(self):
        oid, beta_admin = self.make_org()
        beta_customer = self.client_for("beta.localhost")
        self.register(beta_customer, "same@example.com")
        lmd_customer = self.client_for()
        self.register(lmd_customer, "same@example.com")  # the same email can be a customer of two organisations
        self.assertEqual(len(self.q("SELECT id FROM users WHERE email = 'same@example.com'")), 2)
        beta_app = self.apply(beta_customer, self.make_product(beta_admin), currency="EUR")
        lmd_app = self.submit_application(lmd_customer)
        self.assertEqual(self.q("SELECT org_id FROM applications WHERE id = ?", (beta_app,))[0]["org_id"], oid)
        self.assertEqual(self.q("SELECT org_id FROM applications WHERE id = ?", (lmd_app,))[0]["org_id"], 1)
        # Staff see only their organisation.
        self.assertEqual(self.admin.get(f"/admin/applications/{beta_app}").status_code, 404)
        self.assertEqual(beta_admin.get(f"/admin/applications/{lmd_app}").status_code, 404)
        beta_list = beta_admin.get("/admin/applications").get_data(as_text=True)
        self.assertIn("1 found", beta_list)
        self.assertNotIn(f"LMD-{lmd_app:06d}", beta_list)
        lmd_list = self.admin.get("/admin/applications").get_data(as_text=True)
        self.assertIn("1 found", lmd_list)
        self.assertNotIn(f"BETA-{beta_app:06d}", lmd_list)
        self.assertIn("1 registered", beta_admin.get("/admin/clients").get_data(as_text=True))
        # Documents: an id from another organisation is a 404, even for an administrator.
        self.post(beta_customer, f"/account/applications/{beta_app}/documents", {"document": (io.BytesIO(PDF), "beta.pdf"),
                  "doc_type": "identity"}, content_type="multipart/form-data")
        beta_doc = self.q("SELECT id FROM documents WHERE org_id = ?", (oid,))[0]["id"]
        self.assertEqual(self.admin.get(f"/admin/documents/{beta_doc}").status_code, 404)
        self.assertEqual(self.admin.get(f"/admin/documents/{beta_doc}/review").status_code, 404)
        self.assertEqual(beta_admin.get(f"/admin/documents/{beta_doc}").status_code, 200)
        # Customers: the LMD account's session means nothing on Beta's host, and its password doesn't sign in there either.
        self.assertEqual(lmd_customer.get(f"/account/applications/{lmd_app}").status_code, 200)
        other_host = self.client_for("beta.localhost")
        self.assertIn("don&#39;t match", self.login(other_host, "staff@lmd.test").get_data(as_text=True))
        # References use each organisation's prefix.
        self.assertIn(f"BETA-{beta_app:06d}", beta_admin.get(f"/admin/applications/{beta_app}").get_data(as_text=True))
        # Audit logs and email logs are per organisation.
        audit_lmd = self.admin.get("/admin/audit").get_data(as_text=True)
        self.assertNotIn("admin@beta.test", audit_lmd)
        self.assertTrue(all(r["org_id"] in (None, 1) for r in self.q(
            "SELECT org_id FROM audit_log WHERE event = 'application.submitted' AND target_id = ?", (lmd_app,))))

    def test_financial_settings_and_pricing_are_per_organisation(self):
        oid, beta_admin = self.make_org()
        lmd_pid = self.make_product()
        self.make_rule(percent="2")
        self.make_rule(client=beta_admin, percent="7", name="Beta fee")
        lmd_rules = self.admin.get("/admin/financial-settings").get_data(as_text=True)
        self.assertNotIn("Beta fee", lmd_rules)
        page = self.admin.get(f"/admin/financial-settings?calc_product={lmd_pid}&calc_currency=EUR&calc_amount=10000&calc_term=12").get_data(as_text=True)
        self.assertIn("€200.00", page)
        self.assertNotIn("€700.00", page)
        beta_rule = self.q("SELECT id FROM financial_rules WHERE org_id = ?", (oid,))[0]["id"]
        self.assertEqual(self.admin.get(f"/admin/financial-settings/{beta_rule}").status_code, 404)
        # Branding is per organisation too.
        self.post(beta_admin, "/admin/settings", {"name": "Beta Lending Ltd", "brand_color": "#1F6FEB", "default_currency": "EUR",
                                                  "second_approval_threshold": "0"}, page="/admin/settings")
        self.assertIn("#1F6FEB", beta_admin.get("/admin/").get_data(as_text=True))
        self.assertNotIn("#1F6FEB", self.admin.get("/admin/").get_data(as_text=True))
        r = self.post(beta_admin, "/admin/settings", {"name": "Beta", "brand_color": "red;}body{display:none", "default_currency": "EUR",
                                                      "second_approval_threshold": "0"}, page="/admin/settings")
        self.assertIn("Use a colour such as", r.get_data(as_text=True))

    def test_static_site_belongs_to_one_organisation(self):
        self.make_org()
        beta = self.client_for("beta.localhost")
        r = beta.get("/about.html")
        self.assertEqual(r.status_code, 302)
        self.assertIn("/account/login", r.headers["Location"])
        r = self.client_for().get("/about.html")
        self.assertEqual(r.status_code, 200)
        r.close()
        r = self.client_for("unknown.example").get("/")  # development: unknown hosts use LMD_DEFAULT_ORG
        self.assertEqual(r.status_code, 200)
        r.close()
        self.app.config["DEFAULT_ORG"] = ""                 # production setting: unknown hosts are refused
        self.assertEqual(self.client_for("unknown.example").get("/").status_code, 404)

    def test_suspended_organisation_is_unavailable(self):
        oid, beta_admin = self.make_org()
        from backend.db import execute
        with self.app.app_context():
            execute("UPDATE organisations SET status = 'suspended' WHERE id = ?", (oid,))
        self.assertEqual(beta_admin.get("/admin/").status_code, 503)
        self.assertEqual(self.client_for("beta.localhost").get("/account/login").status_code, 503)


# ================================================================== platform console and subscriptions
class PlatformTests(SaasCase):
    def owner(self):
        from backend.db import execute, now
        from werkzeug.security import generate_password_hash
        with self.app.app_context():
            execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at) "
                    "VALUES (NULL, 'owner@platform.test', ?, 'Platform Owner', 'platform_owner', ?, ?)",
                    (generate_password_hash(PASSWORD), now(), now()))
        c = self.client_for("platform.localhost")
        self.login(c, "owner@platform.test")
        return c

    def test_console_access_is_separate(self):
        owner = self.owner()
        self.assertEqual(owner.get("/platform/").status_code, 200)
        # The owner account doesn't exist on organisation hosts, and organisation staff can't use the console.
        self.assertIn("don&#39;t match", self.login(self.client_for(), "owner@platform.test").get_data(as_text=True))
        staff_on_console = self.client_for("platform.localhost")
        self.assertIn("don&#39;t match", self.login(staff_on_console, "staff@lmd.test").get_data(as_text=True))
        self.assertEqual(self.admin.get("/platform/").status_code, 404)
        # The console has no organisation data screens.
        self.assertEqual(owner.get("/admin/applications").status_code, 403)
        self.assertEqual(owner.get("/account/register").status_code, 404)

    def test_plans_organisations_subscriptions_and_invoices(self):
        owner = self.owner()
        r = self.post(owner, "/platform/plans", {"code": "starter", "name": "Starter", "price": "99.00", "currency": "GBP",
                      "billing_period": "month", "trial_days": "14", "max_staff": "2", "max_applications_month": "50",
                      "platform_fee_percent": "0.5", "active": "1"}, page="/platform/plans")
        self.assertEqual(r.status_code, 302)
        plan = self.q("SELECT * FROM plans")[0]
        self.assertEqual((plan["price_minor"], plan["max_staff"]), (9900, 2))
        r = self.post(owner, "/platform/organisations/new", {"name": "Gamma Capital Ltd", "slug": "gamma", "currency": "GBP",
                      "admin_name": "Gail Admin", "admin_email": "gail@gamma.test", "plan_id": str(plan["id"])}, page="/platform/plans")
        self.assertEqual(r.status_code, 302)
        org = self.q("SELECT * FROM organisations WHERE slug = 'gamma'")[0]
        sub = self.q("SELECT * FROM subscriptions WHERE org_id = ?", (org["id"],))[0]
        self.assertEqual((sub["status"], sub["billing_mode"]), ("trial", "manual"))
        invite = self.mail_to("gail@gamma.test")[0]
        link = re.search(r"https?://([^/\s]+)(/account/reset/\S+)", self.text(invite))
        self.assertEqual(link.group(1).split(":")[0], "gamma.localhost")  # the link goes to the organisation's own host
        self.assertIn("Gamma Capital Ltd", invite["From"])
        # The invited administrator sets a password on their host and signs in.
        gamma = self.client_for("gamma.localhost")
        t = self.token(gamma, link.group(2))
        gamma.post(link.group(2), data={"csrf_token": t, "password": "gamma-admin-phrase-1", "confirm": "gamma-admin-phrase-1"})
        self.assertEqual(self.login(gamma, "gail@gamma.test", "gamma-admin-phrase-1").status_code, 302)
        self.assertEqual(gamma.get("/admin/").status_code, 200)
        # Plan limits: 2 staff.
        self.post(gamma, "/admin/staff", {"full_name": "Second", "email": "two@gamma.test", "role": "underwriter"}, page="/admin/staff")
        r = self.post(gamma, "/admin/staff", {"full_name": "Third", "email": "three@gamma.test", "role": "finance"}, page="/admin/staff",
                      follow_redirects=True)
        self.assertIn("allows 2 active staff", r.get_data(as_text=True))
        # Invoices: manual mode, paid only with a reference; revenue counts paid invoices only.
        start = date.today().replace(day=1).isoformat()
        self.post(owner, f"/platform/organisations/{org['id']}/invoices", {"period_start": start}, page="/platform/")
        inv = self.q("SELECT * FROM subscription_invoices")[0]
        self.assertEqual((inv["status"], inv["plan_amount_minor"], inv["usage_fee_minor"]), ("issued", 9900, 0))
        dash = owner.get("/platform/").get_data(as_text=True)
        self.assertIn("outstanding", dash)
        self.post(owner, f"/platform/invoices/{inv['id']}/paid", {"payment_reference": ""}, page="/platform/")
        self.assertEqual(self.q("SELECT status FROM subscription_invoices")[0]["status"], "issued")
        self.post(owner, f"/platform/invoices/{inv['id']}/paid", {"payment_reference": "BACS-77", "confirm": "1"}, page="/platform/")
        self.assertEqual(self.q("SELECT status FROM subscription_invoices")[0]["status"], "paid")
        self.assertEqual(self.q("SELECT status FROM subscriptions WHERE org_id = ?", (org["id"],))[0]["status"], "active")
        self.assertIn("£99.00", owner.get("/platform/").get_data(as_text=True))
        # The console shows organisation counts, never their customers.
        detail = owner.get(f"/platform/organisations/{org['id']}").get_data(as_text=True)
        self.assertIn("Applications this month", detail)
        events = {r["event"] for r in self.q("SELECT event FROM audit_log")}
        self.assertTrue({"platform.org_created", "platform.invoice_issued", "platform.invoice_paid"} <= events)

    def test_subscription_housekeeping(self):
        from backend import platform as plat
        from backend.db import execute, now
        with self.app.app_context():
            pid = execute("INSERT INTO plans (code, name, price_minor, currency, billing_period, created_at) VALUES ('p','P',1000,'GBP','month',?)", (now(),))
            execute("INSERT INTO subscriptions (org_id, plan_id, status, trial_ends_on, created_at, updated_at) VALUES (1,?, 'trial', ?, ?, ?)",
                    (pid, (date.today() - timedelta(days=1)).isoformat(), now(), now()))
        with self.app.test_request_context():
            result = plat.subscription_housekeeping()
        self.assertEqual(result["past_due"], 1)
        self.assertEqual(self.q("SELECT status FROM subscriptions")[0]["status"], "past_due")

    def test_platform_caps_bound_organisations(self):
        owner = self.owner()
        from backend.options import RULE_KINDS
        caps = {k: "100" for k in RULE_KINDS} | {"processing_fee": "5"}
        self.post(owner, "/platform/settings", caps | {"confirm": "1"}, page="/platform/settings")
        r = self.make_rule(expect=200, percent="6")
        self.assertIn("limit for this setting is 5%", r.get_data(as_text=True))
        # An organisation can't raise its own limit above the platform cap.
        r = self.post(self.admin, "/admin/settings", {"name": "LMD Finance Ltd", "brand_color": "#F08A6E", "default_currency": "GBP",
                      "second_approval_threshold": "0", "max_processing_fee": "9"}, page="/admin/settings")
        self.assertIn("platform limit of 5%", r.get_data(as_text=True))


# ================================================================== reports
class ReportTests(SaasCase):
    def test_reports_separate_requested_approved_and_disbursed(self):
        pid = self.make_product(required_docs=[])
        self.standard_pricing()
        uw2 = self.add_staff("second@lmd.test", "underwriter")
        customer = self.client_for()
        self.register(customer, "report@example.com")
        app_id = self.apply(customer, pid, amount="50000")
        d = self.propose(self.admin, app_id, pid, "30000")
        self.issue(uw2, d["id"])
        page = self.admin.get("/admin/reports/").get_data(as_text=True)
        self.assertIn("€50,000.00", page)   # requested
        self.assertIn("€30,000.00", page)   # approved
        self.assertIn("Actually disbursed (confirmed)", page)
        self.assertRegex(page, r"Actually disbursed \(confirmed\)</dt><dd>€0\.00")
        csv = self.admin.get("/admin/reports/?export=csv")
        self.assertEqual(csv.mimetype, "text/csv")
        self.assertIn("approved,3000000", csv.get_data(as_text=True))
        support = self.add_staff("help@lmd.test", "support")
        self.assertEqual(support.get("/admin/reports/").status_code, 403)
        self.assertEqual(support.get("/admin/applications?export=csv").status_code, 403)
        self.assertTrue(self.q("SELECT 1 FROM audit_log WHERE event = 'data.exported'"))


# ================================================================== customer privacy
class PrivacyTests(SaasCase):
    def test_customer_data_export_and_onboarding(self):
        customer = self.client_for()
        self.register(customer, "mine@example.com")
        page = customer.get("/account/").get_data(as_text=True)
        self.assertIn("Your next steps", page)
        self.assertIn("Complete your profile", page)
        r = customer.get("/account/privacy/export")
        data = json.loads(r.get_data(as_text=True))
        self.assertEqual(data["account"]["email"], "mine@example.com")
        self.assertNotIn("password_hash", r.get_data(as_text=True))
        self.assertNotIn("totp_secret", r.get_data(as_text=True))


if __name__ == "__main__":
    unittest.main()
