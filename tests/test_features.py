"""Tests for the security, workflow, document, marketing, content and production features.

Run:  .venv\\Scripts\\python -m unittest discover -s tests -v
"""
import io
import os
import re
import sqlite3
import struct
import unittest
import zlib

from test_backend import PASSWORD, PDF, BaseCase


def png_bytes(w=4, h=4):
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)
    raw = b"".join(b"\x00" + b"\xff\x00\x00" * w for _ in range(h))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


class FeatureCase(BaseCase):
    def client_for(self, host="localhost"):
        """A test client whose requests go to `host` (each organisation, and the console, has its own host)."""
        from flask.testing import FlaskClient

        class HostClient(FlaskClient):
            def open(self, *args, **kw):
                kw.setdefault("base_url", f"http://{host}")
                return super().open(*args, **kw)
        self.app.test_client_class = HostClient
        try:
            return self.app.test_client()
        finally:
            self.app.test_client_class = None

    def add_staff(self, email, role="admin", org=1, host="localhost"):
        from backend.db import execute, now
        from werkzeug.security import generate_password_hash
        with self.app.app_context():
            execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at) "
                    "VALUES (?,?,?,?,?,?,?)", (org, email, generate_password_hash(PASSWORD), email.split("@")[0].title(), role,
                                               now(), now()))
        c = self.client_for(host)
        self.login(c, email)
        return c

    def q(self, sql, args=()):
        from backend.db import query
        with self.app.app_context():
            return query(sql, args)

    def post(self, client, path, data=None, page=None, **kw):
        data = dict(data or {})
        data["csrf_token"] = self.token(client, page or "/account/security")
        return client.post(path, data=data, **kw)

    def client_with_app(self, email="owner@example.com"):
        c = self.app.test_client()
        self.register(c, email)
        return c, self.submit_application(c)


class AuthTests(FeatureCase):
    def test_email_verification_required_to_submit(self):
        c = self.app.test_client()
        self.register(c, "unverified@example.com", verify=False)
        t = self.token(c, "/account/applications/new")
        r = c.post("/account/applications/new", data=self.full_application(t))
        self.assertEqual(r.status_code, 200)
        self.assertIn("confirm your email address before submitting", r.get_data(as_text=True))
        link = re.search(r"(/account/verify/\S+)", self.text(self.mail_to("unverified@example.com", "Confirm")[-1])).group(1)
        self.assertEqual(c.get(link).status_code, 302)
        self.assertEqual(c.get(link).status_code, 400)  # single use
        self.assertEqual(c.get("/account/verify/made-up").status_code, 400)
        app_id = self.submit_application(c)
        self.assertEqual(self.q("SELECT status FROM applications WHERE id = ?", (app_id,))[0]["status"], "submitted")

    def test_login_throttling_and_generic_errors(self):
        c = self.app.test_client()
        r = self.login(c, "nobody@example.com", "whatever-123")
        self.assertIn("don&#39;t match an account", r.get_data(as_text=True))
        for _ in range(8):
            self.login(c, "staff@lmd.test", "wrong-password")
        r = self.login(c, "staff@lmd.test")  # even the right password is refused while throttled
        self.assertEqual(r.status_code, 200)
        self.assertIn("Too many sign-in attempts", r.get_data(as_text=True))

    def test_mfa_enrolment_login_and_recovery(self):
        from backend.security import totp_now
        c = self.app.test_client()
        self.register(c, "mfa@example.com")
        self.post(c, "/account/security/mfa/start")
        secret = re.search(r'class="key">([A-Z2-7]+)<', c.get("/account/security").get_data(as_text=True)).group(1)
        r = self.post(c, "/account/security/mfa/confirm", {"code": totp_now(secret)}, follow_redirects=True)
        codes = re.findall(r"<code>([0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4})</code>", r.get_data(as_text=True))
        self.assertEqual(len(codes), 8)
        self.assertTrue(self.mail_to("mfa@example.com", "two-step verification was turned on"))

        fresh = self.app.test_client()
        r = self.login(fresh, "mfa@example.com")
        self.assertIn("/account/login/code", r.headers["Location"])
        self.assertEqual(fresh.get("/account/").status_code, 302)  # not signed in yet
        t = self.token(fresh, "/account/login/code")
        r = fresh.post("/account/login/code", data={"csrf_token": t, "code": "000000"})
        self.assertIn("didn&#39;t work", r.get_data(as_text=True))
        r = fresh.post("/account/login/code", data={"csrf_token": t, "code": codes[0]})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(fresh.get("/account/").status_code, 200)
        # A recovery code works only once.
        again = self.app.test_client()
        self.login(again, "mfa@example.com")
        t = self.token(again, "/account/login/code")
        r = again.post("/account/login/code", data={"csrf_token": t, "code": codes[0]})
        self.assertEqual(r.status_code, 200)

    def test_sessions_logout_and_password_change(self):
        a, b = self.app.test_client(), self.app.test_client()
        self.register(a, "sess@example.com")
        self.login(b, "sess@example.com")
        self.assertEqual(b.get("/account/").status_code, 200)
        page = a.get("/account/security").get_data(as_text=True)
        self.assertEqual(page.count("Sign out</button>"), 2)  # the other device, plus the sidebar
        r = self.post(a, "/account/security/password", {"current": PASSWORD, "password": "another-good-phrase", "confirm": "another-good-phrase"})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(b.get("/account/").status_code, 302)  # other device signed out
        self.assertEqual(a.get("/account/").status_code, 200)
        self.post(a, "/account/logout")
        self.assertEqual(a.get("/account/").status_code, 302)
        self.assertTrue(self.mail_to("sess@example.com", "your password was changed"))

    def test_session_cookie_replay_after_logout(self):
        c = self.app.test_client()
        self.login(c, "staff@lmd.test")
        cookie = c.get_cookie("lmd_session").value
        self.post(c, "/account/logout")
        thief = self.app.test_client()
        thief.set_cookie("lmd_session", cookie)
        self.assertEqual(thief.get("/admin/").status_code, 302)  # the server-side session was revoked


class PermissionTests(FeatureCase):
    def test_roles_are_enforced_on_the_server(self):
        c, app_id = self.client_with_app()
        doc = self.post(c, f"/account/applications/{app_id}/documents",
                        {"document": (io.BytesIO(PDF), "s.pdf")}, content_type="multipart/form-data")
        self.assertEqual(doc.status_code, 302)
        doc_id = self.q("SELECT id FROM documents")[0]["id"]
        auditor = self.add_staff("audit@lmd.test", "auditor")
        support = self.add_staff("help@lmd.test", "support")
        reviewer = self.add_staff("review@lmd.test", "underwriter")
        page = f"/admin/applications/{app_id}"
        self.assertEqual(auditor.get(page).status_code, 200)
        self.assertEqual(self.post(auditor, f"{page}/status", {"status": "initial_review"}, page=page).status_code, 403)
        self.assertEqual(self.post(auditor, f"{page}/notes", {"body": "x"}, page=page).status_code, 403)
        self.assertEqual(auditor.get(f"/admin/documents/{doc_id}").status_code, 200)
        self.assertEqual(support.get(f"/admin/documents/{doc_id}").status_code, 403)
        self.assertEqual(support.get("/admin/staff").status_code, 403)
        self.assertEqual(reviewer.get("/admin/staff").status_code, 403)
        self.assertEqual(reviewer.get("/admin/audit").status_code, 403)
        self.assertEqual(auditor.get("/admin/audit").status_code, 200)
        self.assertEqual(reviewer.get("/admin/marketing").status_code, 403)
        self.assertEqual(c.get("/admin/content/").status_code, 403)
        self.assertEqual(self.post(reviewer, f"{page}/status", {"status": "initial_review"}, page=page).status_code, 302)
        # Drafts are private to the client.
        t = self.token(c, "/account/applications/new")
        r = c.post("/account/applications/new", data={"csrf_token": t, "action": "save", "company_name": "Draft Co"})
        draft_id = int(re.search(r"/applications/(\d+)", r.headers["Location"]).group(1))
        self.assertEqual(reviewer.get(f"/admin/applications/{draft_id}").status_code, 404)

    def test_client_cannot_download_another_clients_document(self):
        c1, app_id = self.client_with_app("a1@example.com")
        self.post(c1, f"/account/applications/{app_id}/documents", {"document": (io.BytesIO(PDF), "s.pdf")},
                  content_type="multipart/form-data")
        doc_id = self.q("SELECT id FROM documents")[0]["id"]
        c2 = self.app.test_client()
        self.register(c2, "a2@example.com")
        self.assertEqual(c2.get(f"/account/documents/{doc_id}").status_code, 404)
        self.assertEqual(c1.get(f"/account/documents/{doc_id}").status_code, 200)


class WorkflowTests(FeatureCase):
    # The full approval-to-repayment lifecycle is in test_saas.py (LifecycleTests).

    def test_duplicate_submission_creates_one_application(self):
        c = self.app.test_client()
        self.register(c, "dbl@example.com")
        page = c.get("/account/applications/new").get_data(as_text=True)
        key = re.search(r'name="submission_key" value="([^"]+)"', page).group(1)
        t = re.search(r'name="csrf_token" value="([^"]+)"', page).group(1)
        data = self.full_application(t) | {"submission_key": key}
        r1 = c.post("/account/applications/new", data=data)
        r2 = c.post("/account/applications/new", data=data)
        self.assertEqual(r1.headers["Location"], r2.headers["Location"])
        self.assertEqual(len(self.q("SELECT id FROM applications")), 1)

    def test_admin_list_filters_sort_and_pages(self):
        c = self.app.test_client()
        self.register(c, "many@example.com")
        for _ in range(27):
            self.submit_application(c)
        a = self.app.test_client()
        self.login(a, "staff@lmd.test")
        p1 = a.get("/admin/applications?sort=amount&dir=asc").get_data(as_text=True)
        self.assertIn("27 found", p1)
        self.assertIn("Page 1 of 2", p1)
        self.assertIn("LMD-000027", a.get("/admin/applications?page=2&sort=updated&dir=desc").get_data(as_text=True) +
                      a.get("/admin/applications").get_data(as_text=True))
        self.assertIn("1 found", a.get("/admin/applications?q=LMD-000005").get_data(as_text=True))
        self.assertEqual(a.get("/admin/applications?sort=evil;DROP").status_code, 200)  # unknown sort ignored


class DocumentTests(FeatureCase):
    def upload(self, c, app_id, data, name, doc_type="bank_statements"):
        return self.post(c, f"/account/applications/{app_id}/documents",
                         {"document": (io.BytesIO(data), name), "doc_type": doc_type},
                         content_type="multipart/form-data", follow_redirects=True).get_data(as_text=True)

    def test_upload_validation(self):
        c, app_id = self.client_with_app()
        self.assertIn("Upload a PDF, JPG or PNG", self.upload(c, app_id, b"hello", "notes.txt"))
        self.assertIn("contains scripts", self.upload(c, app_id, PDF.replace(b"<<>>", b"<</JavaScript (x)>>", 1), "s.pdf"))
        self.assertIn("damaged", self.upload(c, app_id, b"\x89PNG\r\n\x1a\nnot really", "id.png"))
        self.assertIn("real PDF or image", self.upload(c, app_id, png_bytes(), "id.jpg"))  # PNG content, JPG name
        self.assertIn("empty", self.upload(c, app_id, b"", "empty.pdf"))
        self.assertIn("larger than", self.upload(c, app_id, b"%PDF-1.4\n" + b"0" * (10 * 1024 * 1024) + b"%%EOF", "big.pdf"))
        self.assertIn("Document uploaded", self.upload(c, app_id, png_bytes(), "../../etc/passwd.png"))
        doc = self.q("SELECT * FROM documents")[0]
        self.assertRegex(doc["stored_name"], r"^[0-9a-f]{32}\.png$")
        self.assertNotIn("/", doc["original_name"])
        self.assertEqual(doc["doc_type"], "bank_statements")
        self.assertEqual(len(doc["sha256"]), 64)
        r = c.get(f"/account/documents/{doc['id']}")
        self.assertIn("attachment", r.headers["Content-Disposition"])
        self.assertIn("sandbox", r.headers["Content-Security-Policy"])
        self.assertEqual(r.headers["Cache-Control"], "private, no-store")
        self.assertIn("inline", c.get(f"/account/documents/{doc['id']}?view=1").headers["Content-Disposition"])
        self.assertEqual(c.get("/uploads/" + doc["stored_name"]).status_code, 404)  # never publicly served
        self.assertTrue(self.q("SELECT 1 FROM audit_log WHERE event = 'document.download'"))

    def test_staff_review_flags_replacement(self):
        c, app_id = self.client_with_app()
        self.upload(c, app_id, PDF, "s.pdf")
        doc_id = self.q("SELECT id FROM documents")[0]["id"]
        a = self.app.test_client()
        self.login(a, "staff@lmd.test")
        # A rejection must explain itself to the customer.
        r = self.post(a, f"/admin/documents/{doc_id}/review", {"review_status": "replacement_required"},
                      page=f"/admin/documents/{doc_id}/review", follow_redirects=True)
        self.assertIn("Explain to the customer", r.get_data(as_text=True))
        self.post(a, f"/admin/documents/{doc_id}/review", {"review_status": "replacement_required", "customer_note": "Page 2 missing",
                                                           "internal_note": "Looks cropped"}, page=f"/admin/documents/{doc_id}/review")
        page = c.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("Replacement required", page)
        self.assertIn("Page 2 missing", page)
        self.assertNotIn("Looks cropped", page)  # internal notes stay internal


class AuditTests(FeatureCase):
    def test_audit_is_append_only_and_clean(self):
        c = self.app.test_client()
        self.register(c, "aud@example.com")
        events = [r["event"] for r in self.q("SELECT event FROM audit_log")]
        self.assertIn("auth.register", events)
        self.assertIn("auth.login", events)
        from backend import audit
        from backend.db import execute
        with self.app.app_context():
            ok, n, _bad = audit.verify_chain()
            self.assertTrue(ok)
            self.assertGreater(n, 2)
            audit.log("test.event", password="hunter2", token="abc", note="kept")
            with self.assertRaises(sqlite3.DatabaseError):
                execute("UPDATE audit_log SET event = 'x'")
            with self.assertRaises(sqlite3.DatabaseError):
                execute("DELETE FROM audit_log")
        detail = self.q("SELECT detail FROM audit_log WHERE event = 'test.event'")[0]["detail"]
        self.assertNotIn("hunter2", detail)
        self.assertNotIn("abc", detail)
        self.assertIn("kept", detail)
        self.assertFalse(any(PASSWORD in r["detail"] for r in self.q("SELECT detail FROM audit_log")))

    def test_tampering_is_detected(self):
        c = self.app.test_client()
        self.register(c, "tamper@example.com")
        db = sqlite3.connect(self.app.config["DATABASE"])
        db.execute("DROP TRIGGER audit_log_no_update")  # someone with direct database access
        db.execute("UPDATE audit_log SET event = 'edited' WHERE id = 1")
        db.commit()
        db.close()
        from backend import audit
        with self.app.app_context():
            self.assertFalse(audit.verify_chain()[0])


class EmailTests(FeatureCase):
    def test_templates_render_and_delivery_failure_is_logged(self):
        from backend import mail
        with self.app.test_request_context():
            msg = mail.build("x@example.com", "Subject\r\nBcc: evil@example.com", "security_alert",
                             user={"full_name": "Pat Example", "email": "x@example.com"}, what="your password was changed", now="now")
            self.assertNotIn("\n", msg["Subject"])
            self.assertIn("your password was changed", self.text(msg))
            self.assertNotIn("0000 0000", msg.get_body(preferencelist=("html",)).get_content())
            self.app.config.update(MAIL_CAPTURE=None, MAIL_SYNC=True, MAIL_SMTP_HOST="127.0.0.1", MAIL_SMTP_PORT="9",
                                   MAIL_SMTP_PASSWORD="secret-smtp-password")
            log_id = mail.send("x@example.com", "Test", "verify_email", user={"full_name": "Pat"}, link="http://l")
        row = self.q("SELECT * FROM email_log WHERE id = ?", (log_id,))[0]
        self.assertEqual(row["status"], "failed")
        self.assertNotIn("secret-smtp-password", row["error"])

    def test_outbox_and_staging_diversion(self):
        from backend import mail
        self.app.config.update(MAIL_CAPTURE=None, MAIL_SYNC=True, MAIL_SMTP_HOST="smtp.invalid", MAIL_ALLOWED_DOMAINS="lmd.test")
        with self.app.test_request_context():
            log_id = mail.send("customer@example.com", "Hi", "verify_email", user={"full_name": "Pat"}, link="http://l")
        row = self.q("SELECT * FROM email_log WHERE id = ?", (log_id,))[0]
        self.assertEqual(row["status"], "outbox")  # diverted, never sent to the real customer
        self.assertTrue(os.listdir(self.app.config["MAIL_OUTBOX"]))


class MarketingTests(FeatureCase):
    def test_consent_segments_and_unsubscribe(self):
        from backend import marketing
        a = self.app.test_client()
        self.register(a, "noconsent@example.com")  # registering never opts anyone in
        b = self.app.test_client()
        t = self.token(b, "/account/register")
        b.post("/account/register", data={"csrf_token": t, "full_name": "Opt In", "email": "yes@example.com",
                                          "password": PASSWORD, "confirm": PASSWORD, "terms": "1", "marketing": "1"})
        with self.app.app_context():
            self.assertEqual(marketing.segment_members("all_opted_in", 1), [])  # not verified yet
        link = re.search(r"(/account/verify/\S+)", self.text(self.mail_to("yes@example.com", "Confirm")[-1])).group(1)
        b.get(link)
        with self.app.test_request_context():
            self.app.preprocess_request()  # resolves the organisation from the host, as a real request would
            members = [m["email"] for m in marketing.segment_members("all_opted_in", 1)]
            self.assertEqual(members, ["yes@example.com"])
            unsub = marketing.unsubscribe_url("yes@example.com").replace("http://localhost:4321", "")
        consent = self.q("SELECT * FROM marketing_consent_events")[0]
        self.assertEqual((consent["action"], consent["source"]), ("opt_in", "registration form"))
        self.assertIn("unsubscribe", consent["wording"])

        anon = self.app.test_client()
        self.assertEqual(anon.post(unsub + "/one-click").status_code, 204)  # RFC 8058, no CSRF token needed
        self.assertEqual(self.q("SELECT reason FROM suppressions")[0]["reason"], "unsubscribed")
        with self.app.app_context():
            self.assertEqual(marketing.segment_members("all_opted_in", 1), [])
        self.assertEqual(anon.get("/marketing/unsubscribe/forged-token").status_code, 404)

    def test_campaign_test_send_and_live_send(self):
        from backend import marketing
        c = self.app.test_client()
        self.register(c, "fan@example.com")
        self.post(c, "/account/security/marketing", {"marketing": "1"})
        a = self.app.test_client()
        self.login(a, "staff@lmd.test")
        self.post(a, "/admin/marketing/new", {"name": "Autumn", "subject": "News", "body": "Hello\n\nSecond para",
                                              "segment": "all_opted_in"}, page="/admin/marketing/new")
        cid = self.q("SELECT id FROM campaigns")[0]["id"]
        self.post(a, f"/admin/marketing/{cid}/test", page=f"/admin/marketing/{cid}")
        test_mail = self.mail_to("staff@lmd.test", "[TEST]")
        self.assertEqual(len(test_mail), 1)
        self.assertFalse(self.mail_to("fan@example.com", "News"))
        self.assertEqual(self.post(a, f"/admin/marketing/{cid}/launch", {"confirm": "1"}, page=f"/admin/marketing/{cid}").status_code, 400)
        self.app.config["MARKETING_ENABLED"] = True
        self.post(a, f"/admin/marketing/{cid}/launch", {"confirm": "1"}, page=f"/admin/marketing/{cid}")
        with self.app.test_request_context():
            sent = marketing.process_due_campaigns(sleep=lambda s: None)
        self.assertEqual(sent, 1)
        msg = self.mail_to("fan@example.com", "News")[0]
        self.assertIn("one-click", msg["List-Unsubscribe"])
        self.assertIn("/marketing/unsubscribe/", self.text(msg))
        self.assertEqual(self.q("SELECT status FROM campaigns")[0]["status"], "sent")
        self.assertEqual(self.q("SELECT category FROM email_log WHERE template = 'marketing_campaign' LIMIT 1")[0]["category"], "marketing")


class ContentTests(FeatureCase):
    def test_draft_preview_publish_and_escaping(self):
        support = self.add_staff("writer@lmd.test", "support")
        form = {"phone": "020 7946 0000", "email": "hello@lmdglobalfinltd.com", "faq_q": ["<script>alert(1)</script>?"],
                "faq_a": ["Plain answer"], "legal_name": "LMD Finance Ltd"}
        r = self.post(support, "/admin/content/", form, page="/admin/content/")
        self.assertEqual(r.status_code, 302)
        bad = self.post(support, "/admin/content/", {"email": "not-an-email"}, page="/admin/content/")
        self.assertIn("valid email", bad.get_data(as_text=True))
        preview = support.get("/admin/content/preview/faq").get_data(as_text=True)
        self.assertIn("PREVIEW", preview)
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;", preview)
        self.assertNotIn("<script>alert(1)", preview)
        self.assertEqual(self.post(support, "/admin/content/publish", {"confirm": "1"}, page="/admin/content/").status_code, 403)
        a = self.app.test_client()
        self.login(a, "staff@lmd.test")
        self.post(a, "/admin/content/publish", {"confirm": "1"}, page="/admin/content/")
        out = os.path.join(self.app.config["SITE_BUILD_OUT"], "faq.html")
        built = open(out, encoding="utf-8").read()
        self.assertIn("&lt;script&gt;alert(1)&lt;/script&gt;?", built)
        contact = open(os.path.join(self.app.config["SITE_BUILD_OUT"], "contact.html"), encoding="utf-8").read()
        self.assertIn('href="tel:02079460000"', contact)
        self.assertIn("[Registered office address to be confirmed]", contact)
        self.assertTrue(self.q("SELECT 1 FROM audit_log WHERE event = 'content.published'"))


class ProductionTests(FeatureCase):
    def test_production_refuses_unsafe_settings(self):
        from backend import create_app
        os.environ["LMD_ENV"] = "production"
        try:
            with self.assertRaises(RuntimeError):
                create_app()
            os.environ.update(LMD_SECRET_KEY="x" * 40, LMD_SECURE_COOKIES="1", LMD_BASE_URL="https://lmdglobalfinltd.com")
            app = create_app()
            self.assertTrue(app.config["SESSION_COOKIE_SECURE"])
            self.assertEqual(app.config["SESSION_COOKIE_NAME"], "__Host-lmd")
            result = app.test_cli_runner().invoke(args=["check-production"])
            self.assertEqual(result.exit_code, 1)  # no SMTP, placeholders, a single admin...
            self.assertIn("[FAIL] Transactional SMTP configured", result.output)
            self.assertIn("seed-dev is disabled", app.test_cli_runner().invoke(args=["seed-dev"]).output)
        finally:
            for k in ("LMD_ENV", "LMD_SECRET_KEY", "LMD_SECURE_COOKIES", "LMD_BASE_URL"):
                os.environ.pop(k, None)

    def test_security_headers_and_health(self):
        c = self.app.test_client()
        r = c.get("/api/health")
        self.assertEqual(r.get_json(), {"ok": True})
        r = c.get("/account/login")
        self.assertIn("script-src 'self'", r.headers["Content-Security-Policy"])
        self.assertEqual(r.headers["X-Content-Type-Options"], "nosniff")
        self.assertEqual(r.headers["Cache-Control"], "no-store")
        self.assertEqual(c.get("/no-such-page").status_code, 404)

    def test_remove_dev_admin(self):
        runner = self.app.test_cli_runner()
        runner.invoke(args=["seed-dev"])
        self.assertTrue(os.path.exists(os.path.join(self.app.instance_path, "dev-admin.txt")))
        runner.invoke(args=["remove-dev-admin"])
        self.assertFalse(os.path.exists(os.path.join(self.app.instance_path, "dev-admin.txt")))
        self.assertEqual(self.q("SELECT is_active FROM users WHERE email = 'admin@lmd.test'")[0]["is_active"], 0)
        self.assertIn("chain intact", runner.invoke(args=["verify-audit"]).output)
        out = runner.invoke(args=["backup", "--dest", os.path.join(self.tmp.name, "bk")]).output
        self.assertIn("Database:", out)

    def test_ai_falls_back_to_faqs_without_a_key(self):
        c = self.app.test_client()
        self.register(c, "ai@example.com")
        token = self.token(c, "/account/help")
        r = c.post("/account/help/ask", json={"question": "How are my documents protected?"}, headers={"X-CSRF-Token": token})
        body = r.get_json()
        self.assertEqual(body["source"], "faq")
        self.assertTrue(any("documents" in m["q"].lower() for m in body["matches"]))
        from backend.ai import redact
        self.assertEqual(redact("call 07700 900123 or mail a@b.com"), "call [number] or mail [email]")


class MigrationTests(unittest.TestCase):
    def test_legacy_database_upgrades_with_backup(self):
        import tempfile
        from backend.db import M1_INITIAL, migrate
        with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as tmp:
            path = os.path.join(tmp, "legacy.sqlite3")
            db = sqlite3.connect(path)
            db.executescript(M1_INITIAL)  # the schema as shipped before these changes, with no migrations table
            db.execute("INSERT INTO users (email, password_hash, full_name, role, created_at) VALUES ('old@example.com','h','Old','client','2026-01-01')")
            db.execute("INSERT INTO users (email, password_hash, full_name, role, created_at) VALUES ('boss@example.com','h','Boss','admin','2026-01-01')")
            db.execute("INSERT INTO applications (user_id, status, created_at, updated_at) VALUES (1, 'submitted', 'x', 'x')")
            db.commit()
            db.close()
            applied = migrate(path, os.path.join(tmp, "backups"))
            self.assertEqual(applied, [1, 2, 3, 4, 5, 6, 7, 8, 9])
            self.assertTrue(os.listdir(os.path.join(tmp, "backups")))
            db = sqlite3.connect(path)
            rows = db.execute("SELECT email, role, email_verified_at FROM users ORDER BY id").fetchall()
            self.assertEqual(rows[0][:2], ("old@example.com", "client"))
            self.assertIsNone(rows[0][2])
            self.assertIsNotNone(rows[1][2])
            self.assertEqual(db.execute("SELECT COUNT(*) FROM applications").fetchone()[0], 1)
            db.close()
            self.assertEqual(migrate(path, os.path.join(tmp, "backups")), [])  # idempotent


if __name__ == "__main__":
    unittest.main()
