"""Multi-organisation loan management platform: the static site for the default organisation (public/),
customer portals and staff areas for every organisation, and the platform-owner console.

Run locally:   .venv\\Scripts\\python run.py          (http://localhost:4321, console: http://platform.localhost:4321)
Production:    gunicorn -c deploy/gunicorn.conf.py wsgi:app   (see deploy/ and the README)
Create staff:  .venv\\Scripts\\flask --app backend create-admin --org lmd --email you@example.com --name "Your Name"
"""
import logging
import os
import secrets
from datetime import datetime, timedelta

import click
from flask import Flask, g, jsonify, redirect, render_template, request, send_from_directory, url_for
from werkzeug.security import generate_password_hash

from . import (admin, ai, api, auth, client, cms, config, documents, finance, marketing, orgsettings, platform, products, reports,
               tenancy, underwriting)
from .db import ROOT_DIR, backup_database, execute, now, query
from .options import (BUSINESS_TYPES, CONFIRMATION_SOURCES, CURRENCIES, DOC_REVIEW, DOC_STATUSES, DOC_TYPES, FEE_STATUSES,
                      FINANCE_TYPES, FREQUENCIES, PAYMENT_METHODS, PROGRESS, PROGRESS_LABELS, ROLES_IN_BUSINESS, RULE_BASES,
                      RULE_KINDS, RULE_TIMINGS, STATUSES, TOPICS, YEARS_TRADING)
from .security import STAFF_ROLES, can, check_csrf, csrf_token, current_user, is_platform_owner, is_staff

PUBLIC = os.path.join(ROOT_DIR, "public")
DEV_ADMIN_EMAIL = "admin@lmd.test"

CSP = ("default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
       "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-src 'self'; "
       "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'")


def create_app():
    problems = config.startup_errors()
    if problems:
        raise RuntimeError("Unsafe production configuration:\n- " + "\n- ".join(problems))
    instance = config.env("LMD_INSTANCE_DIR") or os.path.join(ROOT_DIR, "instance")
    for sub in ("uploads", "generated", "outbox", "backups", "content"):
        os.makedirs(os.path.join(instance, sub), exist_ok=True)

    app = Flask(__name__, static_folder=PUBLIC, static_url_path="", instance_path=instance)
    max_mb = int(config.env("LMD_MAX_UPLOAD_MB"))
    production = config.env("LMD_ENV") in ("production", "staging")
    app.config.update(
        ENV_NAME=config.env("LMD_ENV"),
        SECRET_KEY=config.secret_key(instance),
        DATABASE=os.path.join(instance, "lmd.sqlite3"),
        UPLOAD_DIR=os.path.join(instance, "uploads"),
        GENERATED_DIR=os.path.join(instance, "generated"),
        MAX_UPLOAD_BYTES=max_mb * 1024 * 1024,
        MAX_CONTENT_LENGTH=(max_mb + 1) * 1024 * 1024,  # whole request, a little above one file
        SESSION_COOKIE_NAME="__Host-lmd" if production else "lmd_session",
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SAMESITE="Lax",
        SESSION_COOKIE_SECURE=config.flag("LMD_SECURE_COOKIES"),
        PERMANENT_SESSION_LIFETIME=timedelta(hours=int(config.env("LMD_SESSION_HOURS"))),
        SESSION_HOURS=int(config.env("LMD_SESSION_HOURS")),
        SESSION_IDLE_MINUTES=int(config.env("LMD_SESSION_IDLE_MINUTES")),
        REQUIRE_STAFF_MFA=config.flag("LMD_REQUIRE_STAFF_MFA"),
        BASE_URL=config.env("LMD_BASE_URL"),
        MAIL_FROM=config.env("LMD_MAIL_FROM"),
        MAIL_REPLY_TO=config.env("LMD_MAIL_REPLY_TO"),
        STAFF_EMAILS=config.env("LMD_STAFF_EMAILS"),
        MAIL_ALLOWED_DOMAINS=config.env("LMD_MAIL_ALLOWED_DOMAINS"),
        MAIL_SMTP_HOST=config.env("LMD_SMTP_HOST"),
        MAIL_SMTP_PORT=config.env("LMD_SMTP_PORT"),
        MAIL_SMTP_USER=config.env("LMD_SMTP_USER"),
        MAIL_SMTP_PASSWORD=config.env("LMD_SMTP_PASSWORD"),
        MAIL_OUTBOX=os.path.join(instance, "outbox"),
        MARKETING_ENABLED=config.flag("LMD_MARKETING_ENABLED"),
        MARKETING_FROM=config.env("LMD_MARKETING_FROM"),
        MARKETING_SMTP_HOST=config.env("LMD_MARKETING_SMTP_HOST"),
        MARKETING_SMTP_PORT=config.env("LMD_MARKETING_SMTP_PORT"),
        MARKETING_SMTP_USER=config.env("LMD_MARKETING_SMTP_USER"),
        MARKETING_SMTP_PASSWORD=config.env("LMD_MARKETING_SMTP_PASSWORD"),
        MARKETING_RATE_PER_MINUTE=int(config.env("LMD_MARKETING_RATE_PER_MINUTE")),
        AI_API_KEY=config.env("LMD_AI_API_KEY"),
        AI_MODEL=config.env("LMD_AI_MODEL"),
        AI_STAFF_FEATURES=config.flag("LMD_AI_STAFF_FEATURES"),
        PLATFORM_HOST=config.env("LMD_PLATFORM_HOST"),
        TENANT_BASE_DOMAIN=config.env("LMD_TENANT_BASE_DOMAIN"),
        DEFAULT_ORG=config.env("LMD_DEFAULT_ORG"),
        STATIC_SITE_ORG=config.env("LMD_STATIC_SITE_ORG"),
        SITE_BUILD_OUT=PUBLIC,
    )
    if int(config.env("LMD_TRUST_PROXY") or 0):
        from werkzeug.middleware.proxy_fix import ProxyFix
        n = int(config.env("LMD_TRUST_PROXY"))
        app.wsgi_app = ProxyFix(app.wsgi_app, x_for=n, x_proto=n, x_host=n)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")

    from .db import init_db
    init_db(app)

    for bp in (auth.bp, client.bp, admin.bp, api.bp, cms.bp, marketing.bp, ai.bp, documents.bp, products.bp, underwriting.bp,
               finance.bp, reports.bp, platform.bp, orgsettings.bp):
        app.register_blueprint(bp)

    # Order matters: the organisation is resolved from the host before anything reads the session or the database.
    app.before_request(tenancy.resolve)
    app.before_request(check_csrf)

    @app.before_request
    def _static_site_only_for_its_owner():
        # The marketing pages in public/ belong to one organisation; other organisations' hosts go to their portal.
        if (request.endpoint == "static" and request.path.endswith(".html") and not g.get("platform")
                and not tenancy.owns_static_site()):
            return redirect(url_for("auth.login"))
        if g.get("platform") and request.endpoint == "static" and request.path.endswith(".html"):
            return redirect(url_for("platform.dashboard"))
        return None

    from .content import site
    from .pricing import money as money_fmt
    # Jinja globals (not a context processor) so imported macros can see them too.
    app.jinja_env.globals.update(csrf_token=csrf_token, current_user=current_user, can=can, is_staff=is_staff,
                                 is_platform_owner=is_platform_owner, site=site, org=tenancy.current_org,
                                 org_settings=tenancy.settings, text_on=orgsettings.text_on, money=money_fmt,
                                 STATUSES=STATUSES, PROGRESS=PROGRESS, PROGRESS_LABELS=PROGRESS_LABELS, FINANCE_TYPES=FINANCE_TYPES,
                                 BUSINESS_TYPES=BUSINESS_TYPES, YEARS_TRADING=YEARS_TRADING, TOPICS=TOPICS,
                                 DOC_TYPES=DOC_TYPES, DOC_REVIEW=DOC_REVIEW, DOC_STATUSES=DOC_STATUSES,
                                 ROLES_IN_BUSINESS=ROLES_IN_BUSINESS, STAFF_ROLES=STAFF_ROLES, CURRENCIES=CURRENCIES,
                                 RULE_KINDS=RULE_KINDS, RULE_BASES=RULE_BASES, RULE_TIMINGS=RULE_TIMINGS,
                                 FEE_STATUSES=FEE_STATUSES, FREQUENCIES=FREQUENCIES, PAYMENT_METHODS=PAYMENT_METHODS,
                                 CONFIRMATION_SOURCES=CONFIRMATION_SOURCES)

    @app.template_filter("gbp")
    def gbp(value, currency=None):
        """Whole currency units (requested amounts on the application form)."""
        if value in (None, ""):
            return ""
        cur = currency or (tenancy.current_org()["default_currency"] if tenancy.current_org() else "GBP")
        return money_fmt(int(value) * 100, cur)[:-3]

    @app.template_filter("pence")
    def pence(value, currency=None):
        if value in (None, ""):
            return ""
        return money_fmt(value, currency or (tenancy.current_org()["default_currency"] if tenancy.current_org() else "GBP"))

    @app.template_filter("ref")
    def ref(app_id):
        return f"{tenancy.ref_prefix()}-{int(app_id):06d}"

    @app.template_filter("date")
    def date(value):
        return (value or "")[:16].replace("T", " ")

    @app.after_request
    def headers(resp):
        resp.headers.setdefault("X-Content-Type-Options", "nosniff")
        resp.headers.setdefault("X-Frame-Options", "SAMEORIGIN")
        resp.headers.setdefault("Referrer-Policy", "strict-origin-when-cross-origin")
        resp.headers.setdefault("Permissions-Policy", "camera=(), microphone=(), geolocation=(), payment=()")
        resp.headers.setdefault("Cross-Origin-Opener-Policy", "same-origin")
        resp.headers.setdefault("Content-Security-Policy", CSP)
        if app.config["SESSION_COOKIE_SECURE"]:
            resp.headers.setdefault("Strict-Transport-Security", "max-age=31536000; includeSubDomains")
        if resp.mimetype == "text/html" or request.path.startswith(("/account", "/admin", "/api")):
            resp.headers.setdefault("Cache-Control", "no-store")
        return resp

    @app.route("/")
    def home():
        if g.get("platform"):
            return redirect(url_for("platform.dashboard"))
        if not tenancy.owns_static_site():
            return redirect(url_for("auth.login"))
        return send_from_directory(PUBLIC, "index.html")

    @app.route("/portal.html")
    def old_portal():
        return redirect(url_for("client.dashboard"))

    def error_page(e, code):
        if request.path.startswith("/api/"):
            return jsonify(ok=False, error=getattr(e, "description", "Error")), code
        return render_template("error.html", error=e, code=code), code

    for code in (400, 403, 404, 405, 413, 429, 500, 503):
        app.register_error_handler(code, lambda e, c=code: error_page(e, c))

    register_cli(app)
    return app


def _archive_documents(instance, dest, stamp):
    """tar.gz of the private document folders (uploads, generated letters, published content)."""
    import tarfile
    path = os.path.join(dest, f"lmd-files-{stamp}.tar.gz")
    with tarfile.open(path, "w:gz") as tar:
        for sub in ("uploads", "generated", "content", "branding"):
            folder = os.path.join(instance, sub)
            if os.path.isdir(folder):
                tar.add(folder, arcname=sub)
    return path


def _check_password(password, email):
    from .auth import password_problem
    if len(password) < 12 or password_problem(password, email):
        raise click.ClickException("Use at least 12 characters, not a common password, and not containing the email.")


def _org_by_slug(slug):
    org = query("SELECT * FROM organisations WHERE slug = ?", (slug,), one=True)
    if org is None:
        raise click.ClickException(f"No organisation with slug '{slug}'. Create it with create-organisation.")
    return org


def register_cli(app):
    @app.cli.command("create-admin")
    @click.option("--org", "slug", default=None, help="Organisation slug (default: LMD_DEFAULT_ORG).")
    @click.option("--email", required=True)
    @click.option("--name", required=True)
    @click.option("--role", default="admin", type=click.Choice(list(STAFF_ROLES)), show_default=True)
    @click.password_option()
    def create_admin(slug, email, name, role, password):
        """Create an organisation staff account (default: administrator), or change an existing account's role."""
        from . import audit
        _check_password(password, email)
        org = _org_by_slug(slug or app.config["DEFAULT_ORG"])
        g.org = org
        existing = query("SELECT id, role FROM users WHERE email = ? AND org_id = ?", (email, org["id"]), one=True)
        if existing:
            execute("UPDATE users SET role = ?, password_hash = ?, full_name = ?, is_active = 1, password_changed_at = ? WHERE id = ?",
                    (role, generate_password_hash(password), name, now(), existing["id"]))
            audit.log("staff.cli_updated", "user", existing["id"], actor=None, role=role)
            click.echo(f"Updated {email} in {org['name']}: role {role}.")
        else:
            uid = execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) "
                          "VALUES (?,?,?,?,?,?,?,?)", (org["id"], email.lower(), generate_password_hash(password), name, role,
                                                       now(), now(), now()))
            audit.log("staff.cli_created", "user", uid, actor=None, role=role)
            click.echo(f"Created {STAFF_ROLES[role].lower()} {email} in {org['name']}.")

    @app.cli.command("create-platform-owner")
    @click.option("--email", required=True)
    @click.option("--name", required=True)
    @click.password_option()
    def create_platform_owner(email, name, password):
        """Create the platform owner account used on the console host (LMD_PLATFORM_HOST)."""
        from . import audit
        _check_password(password, email)
        if query("SELECT 1 FROM users WHERE email = ? AND org_id IS NULL", (email,), one=True):
            raise click.ClickException("A platform owner with that email already exists.")
        uid = execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) "
                      "VALUES (NULL,?,?,?, 'platform_owner', ?,?,?)", (email.lower(), generate_password_hash(password), name,
                                                                        now(), now(), now()))
        audit.log("platform.owner_created", "user", uid, actor=None)
        click.echo(f"Platform owner {email} created. Sign in at http(s)://{app.config['PLATFORM_HOST']}/account/login")

    @app.cli.command("create-organisation")
    @click.option("--slug", required=True)
    @click.option("--name", required=True)
    @click.option("--currency", default="GBP", type=click.Choice(list(CURRENCIES)))
    def create_organisation(slug, name, currency):
        """Create an organisation without a subscription (the console is the usual way)."""
        from . import audit
        if query("SELECT 1 FROM organisations WHERE slug = ?", (slug,), one=True):
            raise click.ClickException("That slug is taken.")
        oid = tenancy.create(slug, name, currency)
        audit.log("platform.org_created", "organisation", oid, actor=None, slug=slug)
        click.echo(f"Created {name}: http://{slug}.{app.config['TENANT_BASE_DOMAIN']}  (add staff with create-admin --org {slug})")

    @app.cli.command("subscription-housekeeping")
    def subscription_housekeeping():
        """Daily: lapsed trials/periods become past due; renewal reminders 7 days ahead."""
        result = platform.subscription_housekeeping()
        click.echo(f"{result['past_due']} subscription(s) now past due, {result['reminders']} reminder(s) sent.")

    @app.cli.command("seed-dev")
    def seed_dev():
        """Local testing only: create a dev admin and write its login to instance/dev-admin.txt."""
        if app.config["ENV_NAME"] in ("production", "staging"):
            raise click.ClickException("seed-dev is disabled outside development.")
        password = secrets.token_urlsafe(12)
        org = _org_by_slug(app.config["DEFAULT_ORG"])
        existing = query("SELECT id FROM users WHERE email = ? AND org_id = ?", (DEV_ADMIN_EMAIL, org["id"]), one=True)
        if existing:
            execute("UPDATE users SET password_hash = ?, role = 'admin', is_active = 1 WHERE id = ?",
                    (generate_password_hash(password), existing["id"]))
        else:
            execute("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at) "
                    "VALUES (?,?,?,?, 'admin', ?, ?)", (org["id"], DEV_ADMIN_EMAIL, generate_password_hash(password), "Dev Admin",
                                                        now(), now()))
        with open(os.path.join(app.instance_path, "dev-admin.txt"), "w", encoding="utf-8") as fh:
            fh.write(f"Local dev admin (do not use in production)\nemail: {DEV_ADMIN_EMAIL}\npassword: {password}\n")
        click.echo("Dev admin written to instance/dev-admin.txt")

    @app.cli.command("remove-dev-admin")
    def remove_dev_admin():
        """Before go-live: disable the development admin and delete instance/dev-admin.txt."""
        row = query("SELECT id FROM users WHERE email = ?", (DEV_ADMIN_EMAIL,), one=True)
        if row:
            # Deactivate rather than delete, so any notes it wrote keep their author; the password becomes unusable.
            execute("UPDATE users SET is_active = 0, password_hash = ?, role = 'auditor' WHERE id = ?",
                    (generate_password_hash(secrets.token_urlsafe(32)), row["id"]))
            execute("UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL", (now(), row["id"]))
            click.echo(f"Disabled {DEV_ADMIN_EMAIL}.")
        for name in os.listdir(app.instance_path):
            if name.startswith("dev-") and name.endswith(".txt"):  # local test logins (admin, client, platform owner)
                os.remove(os.path.join(app.instance_path, name))
                click.echo(f"Deleted instance/{name}.")

    @app.cli.command("check-production")
    def check_production():
        """Report everything that is not ready for go-live. Exit code 1 if anything blocks launch."""
        from .content import unresolved_placeholders
        c = app.config
        checks = [
            ("LMD_ENV is production", c["ENV_NAME"] == "production"),
            ("LMD_SECRET_KEY set (32+ characters)", len(config.env("LMD_SECRET_KEY")) >= 32),
            ("LMD_SECURE_COOKIES=1", c["SESSION_COOKIE_SECURE"]),
            ("LMD_BASE_URL is https", c["BASE_URL"].startswith("https://")),
            ("LMD_TRUST_PROXY set (behind nginx)", bool(int(config.env("LMD_TRUST_PROXY") or 0))),
            ("Transactional SMTP configured", bool(c["MAIL_SMTP_HOST"])),
            ("LMD_MAIL_ALLOWED_DOMAINS empty (no staging diversion)", not c["MAIL_ALLOWED_DOMAINS"]),
            ("Staff two-step verification required (LMD_REQUIRE_STAFF_MFA=1)", c["REQUIRE_STAFF_MFA"]),
            ("instance/dev-*.txt credential files removed",
             not [f for f in os.listdir(app.instance_path) if f.startswith("dev-") and f.endswith(".txt")]),
            ("Development admin account disabled",
             not query("SELECT 1 FROM users WHERE email = ? AND is_active = 1", (DEV_ADMIN_EMAIL,), one=True)),
            ("No test accounts (@example.com / .test) active",
             not query("SELECT 1 FROM users WHERE is_active = 1 AND (email LIKE '%@example.com' OR email LIKE '%.test')", one=True)),
            ("LMD_DEFAULT_ORG empty (unknown hosts get 404)", not c["DEFAULT_ORG"]),
            ("A platform owner account exists", bool(query("SELECT 1 FROM users WHERE role = 'platform_owner' AND is_active = 1", one=True))),
            ("Debug mode off", not app.debug),
        ]
        info = [
            ("Marketing enabled (only after the marketing checklist)", c["MARKETING_ENABLED"]),
            ("AI assistant configured (optional)", bool(c["AI_API_KEY"])),
        ]
        org_lines = []
        for org in query("SELECT * FROM organisations WHERE status = 'active' ORDER BY slug"):
            g.org = org
            g.pop("site", None)
            admins = query("SELECT COUNT(*) n FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", (org["id"],), one=True)["n"]
            missing = unresolved_placeholders()
            s = tenancy.settings(org)
            checks.append((f"[{org['slug']}] at least two active administrators (two-person approvals)", admins >= 2))
            checks.append((f"[{org['slug']}] company details published (no placeholders)", not missing))
            org_lines.append(f"[{org['slug']}] agreement template approved: {'yes' if s['agreement_template_approved'] else 'no'}; "
                             f"financial rules: {query('SELECT COUNT(*) n FROM financial_rules WHERE org_id = ? AND enabled = 1', (org['id'],), one=True)['n']}"
                             + (f"; unconfirmed: {', '.join(missing)}" if missing else ""))
        g.org = None
        failed = 0
        for label, ok in checks:
            failed += not ok
            click.echo(f"[{'ok' if ok else 'FAIL'}] {label}")
        for label, ok in info:
            click.echo(f"[{'on' if ok else 'off'}] {label}")
        for line in org_lines:
            click.echo(line)
        click.echo(f"{failed} blocking item(s).")
        if failed:
            raise SystemExit(1)

    @app.cli.command("verify-audit")
    def verify_audit():
        """Check the audit log's hash chain has not been altered."""
        from .audit import verify_chain
        ok, n, bad = verify_chain()
        click.echo(f"Checked {n} audit entries: " + ("chain intact." if ok else f"CHAIN BROKEN at entry {bad}."))
        if not ok:
            raise SystemExit(1)

    @app.cli.command("backup")
    @click.option("--dest", default=None, help="Folder for the backup (default: instance/backups).")
    @click.option("--keep-days", default=30, show_default=True, help="Delete backups in --dest older than this.")
    def backup(dest, keep_days):
        """Online database backup plus an archive of uploaded and generated documents."""
        dest = dest or os.path.join(app.instance_path, "backups")
        db_copy = backup_database(app.config["DATABASE"], dest, "backup")
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        archive = _archive_documents(app.instance_path, dest, stamp)
        cutoff = datetime.now() - timedelta(days=keep_days)
        for name in os.listdir(dest):
            path = os.path.join(dest, name)
            if name.startswith("lmd-") and datetime.fromtimestamp(os.path.getmtime(path)) < cutoff:
                os.remove(path)
        click.echo(f"Database: {db_copy}\nFiles:    {archive}")

    @app.cli.command("send-campaigns")
    def send_campaigns():
        """Send marketing campaigns that are due. Run every few minutes from a timer."""
        n = marketing.process_due_campaigns()
        click.echo(f"Sent {n} marketing email(s).")

    @app.cli.command("purge-expired")
    def purge_expired():
        """Retention housekeeping: remove expired tokens, old sessions and old rate-limit records."""
        old = (datetime.utcnow() - timedelta(days=30)).strftime("%Y-%m-%d %H:%M:%S")
        older = (datetime.utcnow() - timedelta(days=90)).strftime("%Y-%m-%d %H:%M:%S")
        execute("DELETE FROM password_resets WHERE expires_at < ?", (old,))
        execute("DELETE FROM email_verifications WHERE expires_at < ?", (old,))
        execute("DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL OR expires_at < ?) AND last_seen_at < ?", (now(), older))
        execute("DELETE FROM rate_limits WHERE at < strftime('%s','now') - 86400")
        click.echo("Expired tokens, sessions and rate-limit records removed.")

    @app.cli.command("migrations")
    def migrations():
        """List database migrations and whether each has been applied."""
        from .db import MIGRATIONS
        done = {r["version"]: r["applied_at"] for r in query("SELECT * FROM schema_migrations")}
        for version, name, _sql, _r in MIGRATIONS:
            click.echo(f"{version:>3}  {'applied ' + done[version] if version in done else 'PENDING':<28}  {name}")
