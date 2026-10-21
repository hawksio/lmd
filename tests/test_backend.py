"""End-to-end backend tests: client journey, admin journey and access control.

Run:  .venv\\Scripts\\python -m unittest discover -s tests -v

These are the original eight tests. Where behaviour deliberately changed, the assertion was updated and
the reason is noted: registration now confirms the email address before sign-in and gives the same
answer for existing emails, "Documents requested" is now "Additional information requested" (status key
info_requested), and every staff account belongs to an organisation (1 = the default organisation).
"""
import io
import os
import re
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

PASSWORD = "correct-horse-battery"
PDF = b"%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"


class BaseCase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(ignore_cleanup_errors=True)
        os.environ["LMD_INSTANCE_DIR"] = self.tmp.name
        from backend import create_app
        from backend.db import execute, now
        from werkzeug.security import generate_password_hash
        self.app = create_app()
        self.app.config["TESTING"] = True
        self.app.config["MAIL_CAPTURE"] = self.outbox = []
        self.app.config["SITE_BUILD_OUT"] = os.path.join(self.tmp.name, "site")
        with self.app.app_context():
            execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at) VALUES (1,?,?,?, 'admin', ?, ?)",
                    ("staff@lmd.test", generate_password_hash(PASSWORD), "Staff Member", now(), now()))

    def tearDown(self):
        os.environ.pop("LMD_INSTANCE_DIR", None)
        self.tmp.cleanup()

    # -- helpers
    def token(self, client, path):
        html = client.get(path).get_data(as_text=True)
        return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

    @staticmethod
    def text(msg):
        return msg.get_body(preferencelist=("plain",)).get_content()

    def mail_to(self, address, subject_part=""):
        return [m for m in self.outbox if m["To"] == address and subject_part in m["Subject"]]

    def register(self, client, email="owner@example.com", verify=True, login=True):
        """Register, follow the emailed confirmation link, then sign in. Returns the registration response."""
        t = self.token(client, "/account/register")
        r = client.post("/account/register", data={
            "csrf_token": t, "full_name": "Sam Owner", "email": email, "phone": "07000 000000",
            "password": PASSWORD, "confirm": PASSWORD, "terms": "1"})
        if verify:
            link = re.search(r"https?://[^/\s]+(/account/verify/\S+)", self.text(self.mail_to(email, "Confirm")[-1])).group(1)
            client.get(link)
        if login:
            self.login(client, email)
        return r

    def login(self, client, email, password=PASSWORD):
        t = self.token(client, "/account/login")
        return client.post("/account/login", data={"csrf_token": t, "email": email, "password": password})

    def full_application(self, t, action="submit"):
        return {"csrf_token": t, "action": action, "company_name": "Sample Engineering Ltd", "business_type": "ltd",
                "company_number": "01234567", "industry": "Engineering", "years_trading": "5_plus",
                "annual_turnover": "1,200,000", "address": "1 High Street\nLeeds", "finance_type": "asset",
                "amount": "48000", "term_months": "48", "purpose": "Two CNC machines", "consent": "1"}

    def submit_application(self, client):
        t = self.token(client, "/account/applications/new")
        r = client.post("/account/applications/new", data=self.full_application(t))
        return int(re.search(r"/applications/(\d+)", r.headers["Location"]).group(1))


class BackendTest(BaseCase):
    def test_client_and_admin_journey(self):
        c = self.app.test_client()
        r = self.register(c, login=False)
        self.assertEqual(r.status_code, 200)  # changed: "check your email" page instead of an instant sign-in
        r = self.login(c, "owner@example.com")
        self.assertEqual(r.status_code, 302)
        self.assertIn("/account/", r.headers["Location"])

        # Incomplete submission is rejected with field errors, nothing saved.
        t = self.token(c, "/account/applications/new")
        r = c.post("/account/applications/new", data={"csrf_token": t, "action": "submit", "company_name": "X"})
        self.assertEqual(r.status_code, 200)
        self.assertIn("Required to submit", r.get_data(as_text=True))

        # Save a draft, then submit it.
        r = c.post("/account/applications/new", data={"csrf_token": t, "action": "save", "company_name": "Sample Engineering Ltd"})
        self.assertEqual(r.status_code, 302)
        app_id = int(re.search(r"/applications/(\d+)", r.headers["Location"]).group(1))
        t = self.token(c, f"/account/applications/{app_id}/edit")
        r = c.post(f"/account/applications/{app_id}/edit", data=self.full_application(t))
        self.assertEqual(r.status_code, 302)
        page = c.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("Submitted", page)
        self.assertIn("£1,200,000", page)

        # Submitted applications are locked for editing.
        self.assertEqual(c.get(f"/account/applications/{app_id}/edit").status_code, 302)

        # Upload a real PDF; a fake one is refused.
        r = c.post(f"/account/applications/{app_id}/documents",
                   data={"csrf_token": t, "document": (io.BytesIO(PDF), "statements.pdf")}, content_type="multipart/form-data")
        self.assertEqual(r.status_code, 302)
        r = c.post(f"/account/applications/{app_id}/documents",
                   data={"csrf_token": t, "document": (io.BytesIO(b"MZ fake"), "virus.pdf")},
                   content_type="multipart/form-data", follow_redirects=True)
        self.assertIn("doesn&#39;t look like a real PDF", r.get_data(as_text=True))
        c.post(f"/account/applications/{app_id}/messages", data={"csrf_token": t, "body": "When will I hear back?"})

        # Clients cannot reach the admin area.
        self.assertEqual(c.get("/admin/").status_code, 403)

        # Admin sees it, requests documents with a message, adds an internal note.
        a = self.app.test_client()
        self.assertEqual(self.login(a, "staff@lmd.test").status_code, 302)
        listing = a.get("/admin/applications").get_data(as_text=True)
        self.assertIn("Sample Engineering Ltd", listing)
        detail = a.get(f"/admin/applications/{app_id}").get_data(as_text=True)
        self.assertIn("statements.pdf", detail)
        self.assertIn("When will I hear back?", detail)
        at = self.token(a, f"/admin/applications/{app_id}")
        a.post(f"/admin/applications/{app_id}/status", data={"csrf_token": at, "status": "info_requested",
                                                             "message": "Please upload photo ID."})
        a.post(f"/admin/applications/{app_id}/notes", data={"csrf_token": at, "body": "Strong case, check director history."})
        doc_id = int(re.search(r"/admin/documents/(\d+)", detail).group(1))
        self.assertEqual(a.get(f"/admin/documents/{doc_id}").data, PDF)

        # Client sees the status and the message, but not the internal note.
        page = c.get(f"/account/applications/{app_id}").get_data(as_text=True)
        self.assertIn("Additional information requested", page)  # renamed from "Documents requested"
        self.assertIn("Please upload photo ID.", page)
        self.assertNotIn("director history", page)

    def test_clients_cannot_see_each_other(self):
        c1, c2 = self.app.test_client(), self.app.test_client()
        self.register(c1, "one@example.com")
        self.register(c2, "two@example.com")
        app_id = self.submit_application(c1)
        self.assertEqual(c2.get(f"/account/applications/{app_id}").status_code, 404)
        t2 = self.token(c2, "/account/")
        self.assertEqual(c2.post(f"/account/applications/{app_id}/withdraw", data={"csrf_token": t2}).status_code, 404)

    def test_csrf_and_auth_guards(self):
        c = self.app.test_client()
        self.assertEqual(c.get("/account/").status_code, 302)  # must sign in
        self.assertEqual(c.post("/account/login", data={"email": "x", "password": "y"}).status_code, 400)  # no token
        self.assertEqual(self.login(c, "staff@lmd.test", "wrong-password").status_code, 200)
        r = self.login(c, "staff@lmd.test")
        self.assertEqual(r.status_code, 302)
        # Login redirect never leaves the site.
        c2 = self.app.test_client()
        t = self.token(c2, "/account/login?next=//evil.example")
        r = c2.post("/account/login?next=//evil.example", data={"csrf_token": t, "email": "staff@lmd.test", "password": PASSWORD})
        self.assertNotIn("evil", r.headers["Location"])

    def test_duplicate_email_and_weak_password(self):
        c = self.app.test_client()
        self.register(c, "dup@example.com")
        c2 = self.app.test_client()
        t = self.token(c2, "/account/register")
        r = c2.post("/account/register", data={"csrf_token": t, "full_name": "A", "email": "DUP@example.com",
                                              "password": "short", "confirm": "short", "terms": "1"})
        self.assertIn("at least 10 characters", r.get_data(as_text=True))
        # Changed: a duplicate email gets the same "check your email" answer as a new one (no account probing),
        # and the real owner is told by email.
        r = c2.post("/account/register", data={"csrf_token": t, "full_name": "Al Other", "email": "DUP@example.com",
                                              "password": PASSWORD, "confirm": PASSWORD, "terms": "1"})
        body = r.get_data(as_text=True)
        self.assertIn("Check your email", body)
        self.assertNotIn("already exists", body)
        self.assertEqual(len(self.mail_to("dup@example.com", "Someone tried to register")), 1)

    def test_enquiry_api(self):
        c = self.app.test_client()
        token = c.get("/api/csrf").get_json()["token"]
        bad = c.post("/api/enquiries", json={"name": "A"}, headers={"X-CSRF-Token": token})
        self.assertEqual(bad.status_code, 400)
        no_token = c.post("/api/enquiries", json={"name": "Pat"})
        self.assertEqual(no_token.status_code, 400)
        ok = c.post("/api/enquiries", json={"name": "Pat Jones", "company": "Jones Ltd", "email": "pat@example.com",
                                           "topic": "loans", "message": "Hello", "consent": True},
                    headers={"X-CSRF-Token": token})
        self.assertEqual(ok.get_json(), {"ok": True})
        a = self.app.test_client()
        self.login(a, "staff@lmd.test")
        self.assertIn("Jones Ltd", a.get("/admin/enquiries").get_data(as_text=True))

    def test_notification_emails(self):
        c = self.app.test_client()
        self.register(c, "notify@example.com")
        app_id = self.submit_application(c)

        # Staff (every admin, as LMD_STAFF_EMAILS is unset) hear about the new application.
        staff = self.mail_to("staff@lmd.test")
        self.assertEqual(len(staff), 1)
        self.assertIn("Sample Engineering Ltd", staff[0]["Subject"])
        self.assertIn(f"/admin/applications/{app_id}", self.text(staff[0]))
        html = staff[0].get_body(preferencelist=("html",)).get_content()
        self.assertIn("Review application", html)
        # New: the client gets an acknowledgement.
        self.assertEqual(len(self.mail_to("notify@example.com", "We have received")), 1)

        # A status change emails the client, with the adviser's message, escaped in HTML.
        a = self.app.test_client()
        self.login(a, "staff@lmd.test")
        at = self.token(a, f"/admin/applications/{app_id}")
        a.post(f"/admin/applications/{app_id}/status",
               data={"csrf_token": at, "status": "info_requested", "message": "Upload <b>ID</b> please"})
        client_mail = self.mail_to("notify@example.com", "Your application")
        self.assertEqual(len(client_mail), 1)
        self.assertIn("Additional information requested", client_mail[0]["Subject"])
        self.assertIn("Upload <b>ID</b> please", self.text(client_mail[0]))
        self.assertIn("Upload &lt;b&gt;ID&lt;/b&gt; please", client_mail[0].get_body(preferencelist=("html",)).get_content())

        # Re-saving the same status sends nothing new.
        a.post(f"/admin/applications/{app_id}/status", data={"csrf_token": at, "status": "info_requested"})
        self.assertEqual(len(self.mail_to("notify@example.com", "Your application")), 1)

        # Withdrawal notifies staff.
        ct = self.token(c, f"/account/applications/{app_id}")
        c.post(f"/account/applications/{app_id}/withdraw", data={"csrf_token": ct})
        self.assertTrue(any(m["Subject"].startswith("Withdrawn") for m in self.outbox))

    def test_password_reset(self):
        c = self.app.test_client()
        self.register(c, "reset@example.com")
        self.outbox.clear()

        # Unknown email: same page, no email (no account probing).
        anon = self.app.test_client()
        t = self.token(anon, "/account/forgot")
        r = anon.post("/account/forgot", data={"csrf_token": t, "email": "nobody@example.com"})
        self.assertIn("If an account exists", r.get_data(as_text=True))
        self.assertEqual(self.outbox, [])

        r = anon.post("/account/forgot", data={"csrf_token": t, "email": "reset@example.com"})
        self.assertEqual(len(self.outbox), 1)
        link = re.search(r"http://localhost:4321(/account/reset/\S+)", self.text(self.outbox[0])).group(1)

        # Mismatched passwords are rejected; then a valid change works and the old password stops working.
        t = self.token(anon, link)
        r = anon.post(link, data={"csrf_token": t, "password": "brand-new-secret-1", "confirm": "different-one-22"})
        self.assertIn("do not match", r.get_data(as_text=True))
        r = anon.post(link, data={"csrf_token": t, "password": "brand-new-secret-1", "confirm": "brand-new-secret-1"})
        self.assertEqual(r.status_code, 302)
        self.assertEqual(self.login(self.app.test_client(), "reset@example.com").status_code, 200)  # old password fails
        self.assertEqual(self.login(self.app.test_client(), "reset@example.com", "brand-new-secret-1").status_code, 302)

        # The link is single-use, and a made-up token is refused.
        self.assertEqual(anon.get(link).status_code, 400)
        self.assertEqual(anon.get("/account/reset/not-a-real-token").status_code, 400)

        # Asking twice invalidates the first link.
        self.outbox.clear()
        t = self.token(anon, "/account/forgot")
        anon.post("/account/forgot", data={"csrf_token": t, "email": "reset@example.com"})
        anon.post("/account/forgot", data={"csrf_token": t, "email": "reset@example.com"})
        resets = [m for m in self.outbox if "Reset" in m["Subject"]]
        first = re.search(r"(/account/reset/\S+)", self.text(resets[0])).group(1)
        second = re.search(r"(/account/reset/\S+)", self.text(resets[1])).group(1)
        self.assertEqual(anon.get(first).status_code, 400)
        self.assertEqual(anon.get(second).status_code, 200)

    def test_static_site_still_served(self):
        c = self.app.test_client()
        for path in ("/", "/contact.html", "/assets/site.css"):
            r = c.get(path)
            self.assertEqual(r.status_code, 200)
            r.close()
        self.assertEqual(c.get("/portal.html").status_code, 302)


if __name__ == "__main__":
    unittest.main()
