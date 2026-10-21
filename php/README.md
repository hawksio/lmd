# LMD loan platform: PHP edition (for shared hosting)

The same multi-organisation loan management platform as the Flask edition in `backend/`, rewritten in plain PHP so it
runs on ordinary shared hosting such as the Hostinger Starter plan: no VPS, no Python, no Composer, no command line
needed. Every feature of the Flask edition is here (see the table below), with the same database rules and the same
money calculations.

## Requirements

- PHP 8.1 or newer (8.2/8.3 recommended), with `pdo_sqlite`, `bcmath`, `mbstring`, `openssl`, `gd`, `fileinfo`, `iconv`
  (all standard on Hostinger; the site tells you if one is missing).
- Apache or LiteSpeed with `.htaccess` (mod_rewrite).
- A folder next to `public_html` the PHP user can write to. The app creates `../lmd-storage/` itself and keeps the
  database, uploads, letters, email outbox, backups, logs and configuration there, outside the web root.

## Layout

```
php/
  public_html/        what goes into the hosting account's public_html
    index.php         front controller (every non-file request)
    .htaccess         routing, blocks app/ and private file types
    assets/ brand/ images/   copied from the website in public/
  app/                application code (deployed as public_html/app, blocked from the web)
    bootstrap.php http.php cli.php schema.sql
    lib/              core, db, tenancy, security, audit, pricing, mail, content, pdf, qr, letters, workflow,
                      finance, platform, marketing, ai, html
    controllers/      routes: public, auth, client, admin, underwriting, finance, settings, content_marketing,
                      platform, setup
    views/            page templates
    site/             the public website pages (same sources as src/), rendered from published content
  tests/              run.php (end-to-end), pricing_compare.php, qr_compare.php
  tools/package.php   builds dist/lmd-php-<date>.zip for upload
  config.example.php
```

## Run locally

```bash
.tools\php\php.exe -S 127.0.0.1:8090 -t php\public_html php\public_html\index.php
```

Then open http://127.0.0.1:8090. Local data goes to `php/lmd-storage/` (git-ignored). Useful commands:

```bash
.tools\php\php.exe php\app\cli.php seed-dev
```

(writes a local admin login to `php/lmd-storage/dev-admin.txt`; development only), and `create-platform-owner`,
`create-admin`, `create-organisation`, `check-production`, `backup`, `verify-audit`, `remove-dev-admin`.

## Tests

```bash
.tools\php\php.exe php\tests\run.php
```

117 end-to-end checks over HTTP against a throwaway copy: registration and email verification, product and pricing
set-up, percentage caps, application with double-submit protection, upload validation (PDF scripts and fake images
refused), cross-customer isolation, CSRF, document verification, decision preview/propose, the proposer unable to
issue their own decision, partial approval with requested and approved kept separate, rule versioning without
retroactive change, offer acceptance, agreement blocked until the template is legally approved, signing, fees from
the frozen pricing, disbursement scheduling capped at the approved amount, the recorder unable to confirm their own
payment, repayment schedule from the frozen rate, duplicate payment references ignored, every staff and client page,
CSV export, audit chain, two-step verification with QR code, the platform console, a second organisation and
isolation between organisations, and the closed cron endpoint.

```bash
.venv\Scripts\python php\tests\pricing_cases.py php\tests\pricing_cases.json
.tools\php\php.exe php\tests\pricing_compare.php php\tests\pricing_cases.json
```

checks 400 random loans plus edge cases against the Flask edition's engine: all figures identical to the penny
(decimal arithmetic with the same 28-digit rounding rules). `qr_compare.php` checks the QR encoder against segno.

## Addresses on one domain (no DNS changes)

| Address | What |
|---|---|
| `/`, `/about.html`, ... | public website of the default organisation (LMD) |
| `/account/...`, `/admin/...` | LMD's customer portal and staff area |
| `/o/<slug>/account/...` | another organisation's portal and staff area |
| `/console/...` | platform-owner console |

Custom domains per organisation still work (add them in the console, point their DNS at the hosting account, add SSL).
`tenant_base_domain` enables `<slug>.<domain>` if you ever have wildcard DNS.

## Deploying to Hostinger shared hosting

1. hPanel > Advanced > PHP configuration: PHP 8.2 or 8.3.
2. hPanel > Security > SSL: make sure the free certificate is active for the domain.
3. Build the package: `.tools\php\php.exe php\tools\package.php`, then upload and extract the zip into `public_html`
   (File Manager, or the Hostinger API). This replaces what is there.
4. Create `public_html/app/config.local.php` from `config.example.php` with your own random values (staging lock,
   setup token, cron token). It is blocked from the web and never committed.
5. Open `https://<domain>/setup?token=<setup_token>` and create the platform owner and LMD's first administrator,
   choosing your own passwords. Then remove `setup_token` from the config.
6. Sign in, turn on two-step verification, add staff, products, Financial settings and verified website content.
7. Scheduled jobs (hPanel > Advanced > Cron Jobs), e.g. daily backup and housekeeping:
   `curl -s "https://<domain>/cron/backup?token=<cron_token>"`, also `purge-expired`, `subscription-housekeeping`,
   `verify-audit` daily and `send-campaigns` every 5 minutes if marketing is used.
8. Before launch: `env` = production, remove `staging_password` and `noindex`, SMTP configured, `require_staff_mfa` on,
   at least two administrators, company details published, and copy backups off the server regularly.

## Differences from the Flask edition

- Emails are sent during the request (no background thread on shared hosting), so a page that sends email may take
  a second longer. Without SMTP settings they go to `lmd-storage/outbox/`.
- PDFs come from a small built-in PDF writer (Helvetica/Times, A4) instead of fpdf2; same content and safeguards.
- Image uploads are checked with GD instead of Pillow; logos are re-encoded as PNG.
- Organisations live at `/o/<slug>` by default instead of subdomains, because shared hosting usually has no wildcard DNS.
- The public pages are rendered on request from the published content, so publishing takes effect immediately.
- New installs start from one complete schema (`app/schema.sql`); later changes go in `MIGRATIONS` in `app/lib/db.php`.
  The Flask edition's SQLite file is not reused: start the PHP edition with a fresh database (no test data).

Nothing here makes the business compliant by itself: the regulatory, legal and content items in
`PRODUCTION-CHECKLIST.md` still apply.
