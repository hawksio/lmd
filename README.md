# Loan management platform (multi-organisation) — with the LMD Finance Ltd website

A Flask application that finance organisations can subscribe to. Each organisation gets its own branded customer
portal and staff area: applications, document verification, underwriting with partial/full/conditional approvals,
configurable financial rules, agreements and letters, genuine disbursement and repayment records, fees, reports,
marketing consent and an audit trail. A separate platform-owner console manages organisations, plans, subscriptions
and invoices. LMD Finance Ltd is the first (default) organisation and also owns the static marketing site in `public/`.

> **Not production-ready yet.** Each organisation must verify its own regulatory permissions, products, pricing,
> disclosures and legal documents, and the platform needs real infrastructure (see
> [PRODUCTION-CHECKLIST.md](PRODUCTION-CHECKLIST.md)). Changes and reasons: [CHANGELOG.md](CHANGELOG.md).
> Commercial plan: [SAAS-ROADMAP.md](SAAS-ROADMAP.md).

> **Shared hosting?** A plain-PHP edition with the same features runs on ordinary shared hosting (for example the
> Hostinger Starter plan) without a VPS: see [php/README.md](php/README.md).

## Architecture

```
run.py / wsgi.py          development server / production entry point (gunicorn)
backend/
  __init__.py             app factory, tenant resolution order, security headers, error pages, CLI commands
  config.py               every environment variable; production refuses unsafe settings
  db.py                   SQLite + versioned migrations 1-9 (automatic backup before migrating)
  tenancy.py              organisations: host -> organisation, per-organisation settings, portal URLs
  security.py             server-side sessions bound to an organisation, CSRF, roles & permissions, rate limits, TOTP
  auth.py                 registration, email verification, sign-in (+2-step), password reset, Account and security
  client.py               customer portal: onboarding, applications, documents, financing summary, messages, privacy
  applications.py         product-aware form validation, status changes, upload checks, private file responses
  documents.py            document requirements, verification queue, review decisions and history, evidence requests
  products.py             loan products; Financial settings (percentage & fee rules with version history, calculator)
  pricing.py              pricing engine (exact decimals, minor units): fees, interest, schedules, calculated APR
  underwriting.py         decisions (full/partial/conditional), revisions, second approval, offer acceptance
  finance.py              disbursements, repayment schedules, repayments, fee records, late charges
  letters.py              branded PDFs: acknowledgement, requests, approval, decline, agreement, schedule, receipts...
  reports.py              organisation reports (per currency) and CSV export
  admin.py                staff area: overview, applications, approval queue, workspace, messages, staff, audit, email log
  orgsettings.py          branding (logo, colour), workflow, notifications, percentage limits
  platform.py             platform-owner console: organisations, plans, subscriptions, invoices, limits, health
  marketing.py, cms.py, content.py, ai.py, mail.py, audit.py, api.py   as before, now per organisation
  templates/              Jinja templates (portal, staff, console, emails)
content/site.json         default website content for the default organisation
src/, tools/build.py      static site sources and builder -> public/
instance/                 PRIVATE, git-ignored: database, uploads, generated PDFs, branding, outbox, backups
deploy/                   gunicorn, systemd, nginx and environment templates
tests/                    test_backend.py (original 8), test_features.py, test_saas.py
```

### How organisations are separated

- A request belongs to an organisation by **host name**: a custom domain registered for it, or
  `<slug>.<LMD_TENANT_BASE_DOMAIN>` (e.g. `acme.yourplatform.com`). The console is on `LMD_PLATFORM_HOST`.
- Every user belongs to one organisation (platform owners to none). A session is only valid on its own
  organisation's host; the same email can be a customer of two organisations as two separate accounts.
- Every staff query filters on the organisation; files are only served through rows that passed that filter.
  Other organisations' records are simply "not found" (404).
- Audit log, email log, content, marketing consent and suppression lists, financial rules and products are per
  organisation. The console only sees organisation-level counts and its own billing records.

## Local setup (Windows, Python 3.12)

```bash
py -3.12 -m venv .venv
```
```bash
.venv\Scripts\python -m pip install -r requirements.txt
```
```bash
.venv\Scripts\python run.py
```

- Default organisation (LMD Finance): http://localhost:4321
- Any other organisation: http://<slug>.localhost:4321 (Chrome and Edge resolve `*.localhost` automatically)
- Platform console: http://platform.localhost:4321

Create accounts (each prompts for a password of 12+ characters):

```bash
.venv\Scripts\flask --app backend create-platform-owner --email you@yourplatform.com --name "Your Name"
```
```bash
.venv\Scripts\flask --app backend create-admin --org lmd --email you@lmdglobalfinltd.com --name "Your Name"
```

Normally you create organisations from the console (Organisations → New organisation), which also sets up the
subscription and emails the first administrator an invitation. `flask --app backend create-organisation --slug acme
--name "Acme Finance Ltd"` does it from the command line.

Without SMTP settings every email is written to `instance/outbox/*.eml` and nothing is sent.

## First steps for a new organisation (in its staff area)

1. **Organisation settings**: name, logo, brand colour, workflow (second-approval threshold, document verification
   before decisions, offer acceptance before agreements), notifications, optional lower percentage limits.
2. **Website content**: legal name, registration number, registered office, contact details, regulatory statement,
   FAQs. Unconfirmed details show as visible placeholders.
3. **Loan products**: currencies, amount and term limits, interest method, repayment frequency, grace period,
   required documents, extra application questions, eligibility text.
4. **Financial settings**: interest rate, processing/administration/document fees, late charges, commission (with its
   documented arrangement), service fee, custom rules: percentage or fixed, calculation basis, products, currencies,
   effective dates. Use the calculator to check the breakdown.
5. **Staff and permissions**: invite colleagues by role.
6. When a solicitor has approved the agreement template, confirm it in Organisation settings so agreements can be issued.

## Roles

| Role | Can |
|---|---|
| Platform owner (console only) | Organisations, plans, subscriptions, invoices, platform limits. No organisation customer data. |
| Organisation administrator | Everything in the organisation (never confirming their own payment records) |
| Underwriter | Review, propose and issue decisions (large approvals need a different person), letters |
| Document reviewer | Open, verify or reject documents, request evidence. No lending decisions. |
| Customer support | Messages, enquiries, website content drafts. Cannot open customer documents. |
| Finance officer | Disbursements (record/confirm, never both for the same payment), schedules, repayments, fees, reports, exports |
| Read-only auditor | View applications, documents, reports and the audit log |

Every permission is checked on the server for every route (`security.PERMISSIONS`).

## The lending workflow

1. **Application**: the customer chooses a product, answers its questions, and submits (email must be confirmed).
   Duplicate submissions are de-duplicated.
2. **Documents**: required documents (from the product) and ad-hoc requests. Statuses: not submitted, submitted,
   under review, verified, rejected, replacement required, expired. Rejections need a customer-facing explanation;
   internal notes stay internal. Customers replace rejected documents. Verifying a document never approves anything.
3. **Decision**: an underwriter proposes a full, partial or conditional approval. Requested and approved amounts are
   stored separately; the approved amount can't exceed the request unless the product allows it. The pricing is
   calculated from Financial settings and **frozen on the decision**. Above the threshold (default: every approval)
   a different authorised person must issue it. Revisions keep the previous amount, who, when and why. Declines need
   a recorded reason.
4. **Offer**: the customer sees the interest rate, every fee (what it's calculated on and when it's payable), the
   amount paid to them, total repayable, calculated APR and the schedule, and accepts in the portal.
5. **Agreement**: generated from the frozen terms; issued only once the organisation has confirmed legal approval of
   its template; "Agreement signed" is recorded by staff only after a genuinely signed copy is received (no built-in
   e-signature). Fee records are created at signature.
6. **Disbursement**: scheduled → recorded as paid (date, method, bank reference) → **confirmed by a different person**
   against the bank record. Only confirmed payments count. Partial and full disbursement statuses follow the money.
   Fees "deducted from the payment" are taken from the first payment and recorded as paid.
7. **Repayments**: the schedule is built from the frozen terms and the principal actually paid out. Repayments are
   recorded with a unique payment reference (the same payment can't be recorded twice), produce receipts, and are
   corrected by reversal. Late charges are applied by finance staff to overdue instalments only.

There is deliberately no fee that must be paid before a decision or to "release" funds, no wallet or balance, and no
simulated payment anywhere in the software.

## Tests and the static site

```bash
.venv\Scripts\python -m unittest discover -s tests -v
```
```bash
.venv\Scripts\python tools\build.py
```

52 tests (no real email, payment or network calls). Dependency scan: `pip-audit -r requirements.txt` (run from a
separate environment).

## Database migrations

`backend/db.py` holds migrations 1-9; they run automatically at start-up, after copying a database that already has
data to `instance/backups/lmd-pre-migration-*.sqlite3`. `flask --app backend migrations` lists them.
Migration 5 moved existing data into organisation 1 and renamed statuses (e.g. "in review" → "initial review",
"documents requested" → "additional information requested", "approved" → "conditional approval",
"disbursed" → "fully disbursed"); `reviewer` staff became `underwriter`.

**SQLite or PostgreSQL?** SQLite (WAL) is fine for a single server with a handful of organisations and modest
traffic. Move to PostgreSQL before selling widely: concurrent writes from many organisations, point-in-time recovery,
managed backups and row-level security as a second isolation layer. The SQL is standard apart from a few SQLite
functions (`printf`, `strftime`), so the port is a contained project.

## Environment variables

All are defined in `backend/config.py` and `deploy/env.example`. New for multi-organisation use:

| Variable | Purpose |
|---|---|
| `LMD_PLATFORM_HOST` | Console host, e.g. `console.yourplatform.com` |
| `LMD_TENANT_BASE_DOMAIN` | Organisations get `<slug>.<this>`, e.g. `yourplatform.com` |
| `LMD_DEFAULT_ORG` | Development fallback organisation for unknown hosts. **Empty in production.** |
| `LMD_STATIC_SITE_ORG` | Organisation that owns the static site in `public/` (`lmd`) |

The others are unchanged: `LMD_ENV`, `LMD_SECRET_KEY`, `LMD_SECURE_COOKIES`, `LMD_BASE_URL`, `LMD_INSTANCE_DIR`,
`LMD_TRUST_PROXY`, `LMD_SESSION_*`, `LMD_REQUIRE_STAFF_MFA`, `LMD_MAX_UPLOAD_MB`, `LMD_SMTP_*`, `LMD_MAIL_*`,
`LMD_STAFF_EMAILS`, `LMD_MARKETING_*`, `LMD_AI_*`. `LMD_AGREEMENT_TEMPLATE_APPROVED` was replaced by a per-organisation
setting.

## Email

Transactional email uses one platform sending address shown with each organisation's name (e.g. "Acme Finance
<no-reply@yourplatform.com>"); links point to the organisation's own portal. Per-organisation notification toggles,
recipients and signature are in Organisation settings. Every message is logged per organisation with its delivery
status. Set up SPF, DKIM and DMARC for the sending domain.

## Platform console and billing

Plans (names, prices, billing period, trial days, staff/application/product limits, optional usage fee on confirmed
disbursements) are created by the owner. Subscriptions run in **manual billing mode**: the owner issues invoices and
records a payment only after receiving it, with its reference. `flask --app backend subscription-housekeeping` (daily)
marks lapsed trials/periods past due and emails renewal reminders. No payment provider is integrated (see the roadmap).

## Production deployment

Hostinger shared hosting can't run this app. Your Hostinger account has `lmdglobalfinltd.com` and no VPS yet; a
Hostinger KVM VPS (KVM 2: 2 vCPU, 8 GB, a sensible start for several organisations plus a staging copy) running Ubuntu
24.04 fits. Check current plans and prices before ordering.

1. Harden the server: non-root `lmd` user, firewall (`ufw allow OpenSSH`, `ufw allow 'Nginx Full'`), SSH keys only,
   unattended security upgrades.
2. Copy the project (without `.venv` and `instance/`) to `/srv/lmd/app`; create `/srv/lmd/instance` and
   `/srv/lmd/backups` (owner `lmd`, mode 700).
3. `python3.12 -m venv .venv && .venv/bin/pip install -r requirements.txt`, then `.venv/bin/python tools/build.py`.
4. Fill in `/etc/lmd/lmd.env` from `deploy/env.example` (mode 600). Leave `LMD_DEFAULT_ORG` empty.
5. Install `deploy/lmd.service`, `deploy/lmd-jobs.*` (campaign sends) and `deploy/lmd-backup.*` (nightly backup,
   retention, audit-chain check, subscription housekeeping) into `/etc/systemd/system/` and enable them.
6. nginx from `deploy/nginx-lmd.conf`; HTTPS with certbot: a wildcard certificate for `*.<base domain>` (DNS challenge)
   plus one per custom domain and the console host.
7. **DNS (your approval needed; nothing has been changed):** `A`/`AAAA` records for the console host, the base domain,
   the `*` wildcard and each organisation's custom domain, pointing at the VPS. Keep the nameservers as they are.
   SPF/DKIM/DMARC for the sending domain.
8. `create-platform-owner`, then create organisations from the console; `remove-dev-admin`; never copy local test data.
9. `flask --app backend check-production` must report 0 blocking items; `GET /api/health` must return `{"ok": true}`.

Logs: `journalctl -u lmd`, `/var/log/nginx/`. Restart: `systemctl restart lmd`. Rollback: keep the previous release in
`/srv/lmd/releases/<date>`, switch the `/srv/lmd/app` symlink back, and restore the matching `lmd-pre-migration-*`
database copy if the new release migrated. Staging: a second copy with its own instance folder, database and secret,
`LMD_ENV=staging` and `LMD_MAIL_ALLOWED_DOMAINS` set.

**Backup and restore**: unchanged (`flask --app backend backup`); the archive now also includes `instance/branding/`.
Restore by stopping the service, copying the database and extracting the archive, then `verify-audit`.

## Security notes

- Server-side sessions bound to one organisation; CSRF on every change; strict CSP; HSTS; rate limits; optional/required
  2-step verification; generic sign-in, registration and reset answers.
- Database constraints back the important rules: users can't be platform owner and organisation member at once; a
  decision above the threshold can't be issued by its proposer; a payment can't be confirmed by its recorder; payment
  references are unique per organisation.
- Uploads are content-checked and stored privately; logos are re-encoded; previews are sandboxed; CSV exports neutralise
  spreadsheet formulas and are audited.
- Brand colours are validated hex values (no CSS injection); all customer and staff text is escaped.
- No virus scanning yet (add ClamAV or a scanning service before accepting real documents).
- Automated tests and these controls don't make the platform "secure" or "compliant" on their own: commission an
  independent penetration test (including cross-tenant testing) before onboarding real organisations.

## Home page, images and brand

Unchanged: scroll-craft engine in `public/assets/scrollcraft/` (MIT, unedited), Unsplash stock photos never described as
staff, `<x-img>` component with required alt text, and repayment examples on the home page only when the default
organisation publishes an approved representative APR.
