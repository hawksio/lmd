# Changelog

## 2026-10-20: PHP edition for shared hosting (`php/`)

The whole platform rewritten in plain PHP (no Composer, no command line needed) so it runs on the Hostinger Starter
shared hosting plan. The Flask edition in `backend/` is unchanged. See `php/README.md`.

- Same schema and rules (CHECK constraints for two-person approvals and payment confirmation, unique payment references,
  append-only hash-chained audit log), created from one complete schema for a fresh install.
- Same pricing engine: 420 of 420 comparison cases identical to the Flask edition to the penny.
- Every page of the website, client portal, staff area and platform console; emails over SMTP or to an outbox; PDF
  letters from a built-in PDF writer; QR codes for two-step verification from a built-in encoder (checked against segno).
- Organisations at `/o/<slug>` and the console at `/console` on the same domain, so no DNS changes are needed.
- One-time `/setup` page and `/cron/<job>` endpoints (both token-protected) for hosting without a command line.
- `php/tests/run.php`: 117 end-to-end checks, all passing.
- Local PHP 8.3.35 for testing in `.tools/php` (official Windows build, SHA-256 checked; git-ignored).

## 2026-10-20: multi-organisation loan management platform

Backup before these changes: `Desktop\lmd-backup-20261019-234558.zip`. Your local database was migrated (5-9) with a
copy saved first to `instance/backups/lmd-pre-migration-5-*.sqlite3`.

### Database migrations
| # | Change | Existing data |
|---|---|---|
| 5 | `organisations`, `organisation_domains`; `users` rebuilt (email unique per organisation, new roles, `address`, platform owners have no organisation); `org_id` on every organisation-owned table; per-organisation suppression list | All existing rows → organisation 1 (`lmd`); `reviewer` → `underwriter`; statuses renamed (in_review → initial_review, documents_requested → info_requested, with_lender → underwriting, approved → conditionally_approved, disbursed → fully_disbursed) |
| 6 | Document verification: internal/customer notes, expiry, replacements, `document_reviews`, `document_requests` | received → submitted, accepted → verified, replacement_needed → replacement_required |
| 7 | `loan_products`, `financial_rules` (+ `financial_rule_history`), `approval_decisions` (frozen pricing), `decision_conditions`, `offer_acceptances`; applications gain product, currency, extra answers | Unchanged; the old `approved_terms` table is kept but no longer used |
| 8 | `disbursements` rebuilt (scheduled/pending/confirmed/rejected/cancelled/reversed, currency, deducted fees, unique reference); `repayment_schedules`, `instalments`, `repayments` (unique reference), `fee_items` | Existing disbursements copied |
| 9 | `plans`, `subscriptions`, `subscription_invoices`, `platform_settings` | None |

### New modules
`tenancy.py`, `documents.py`, `products.py`, `pricing.py`, `underwriting.py`, `finance.py`, `reports.py`,
`platform.py`, `orgsettings.py`, plus templates for the approval workspace, document queue and review, products,
financial settings and rule history, disbursements, repayments, fees, letters, messages, notifications, reports,
organisation settings, the customer offer page, and the platform console.

### Changed
| File | Change |
|---|---|
| `backend/security.py` | SaaS roles and permissions; sessions valid only on their organisation's host; console guard |
| `backend/auth.py` | Sign-up, sign-in, verification and reset scoped to the organisation; console has no self-registration |
| `backend/admin.py` | Rewritten: organisation-scoped queries, approval workspace, approval queue, messages, notifications, CSV exports, plan staff limits |
| `backend/client.py` | Product-based applications, document replacement, financing summary, onboarding progress, data export |
| `backend/applications.py` | Product-aware validation (currency, limits, extra questions); organisation-scoped uploads; staff notifications |
| `backend/letters.py` | Organisation branding and logo; partial/full/conditional approval, decline, agreement with schedule, schedule, fee statement, receipts, disbursement confirmation |
| `backend/mail.py`, `content.py`, `cms.py`, `marketing.py`, `audit.py`, `api.py`, `ai.py` | Per-organisation sender name, links, content, consent, suppression, audit and enquiries |
| `backend/__init__.py`, `config.py` | Tenant resolution first; new variables; CLI `create-platform-owner`, `create-organisation`, `subscription-housekeeping`, `create-admin --org`; production checks per organisation |
| `requirements.txt` | Flask 3.0.3 → 3.1.3 (fixes PYSEC-2026-2151 reported by pip-audit) |
| `tests/` | Original tests updated only for renamed statuses and organisation-owned staff; `test_saas.py` added |
| `README.md`, `PRODUCTION-CHECKLIST.md`, `SAAS-ROADMAP.md`, `deploy/` | Multi-organisation setup, wildcard hosting, roadmap |

## 2026-10-19: production-readiness work

Backup of the project before these changes: `Desktop\lmd-backup-20261019-205057.zip`.
Database migrations 2-4 were added; your local database was migrated automatically and its previous state saved
to `instance/backups/lmd-pre-migration-1-*.sqlite3`.

### Backend
| File | Change |
|---|---|
| `backend/config.py` (new) | All environment variables in one place; production refuses to start with unsafe settings |
| `backend/db.py` | Versioned migrations with automatic pre-migration backup, WAL mode, transactions; new tables for sessions, audit, notifications, email log, terms, generated documents, disbursements, content versions, marketing |
| `backend/security.py` | Server-side sessions with idle/absolute expiry and revocation; staff roles and permissions; database-backed rate limits; TOTP and recovery codes |
| `backend/auth.py` | Email verification; generic registration response; 2-step sign-in; password policy; sign-out everywhere on reset; Account and security page |
| `backend/applications.py` | Shared status-change logic with history, notification, email and audit; content-based upload validation (PDF active content, Pillow image check); private, sandboxed file responses |
| `backend/client.py` | Overview, documents, messages, help pages; duplicate-submission protection; verification before submit; issued letters and confirmed payments |
| `backend/admin.py` | Permission checks per route; status transition rules with required reasons; filtering, sorting, pagination, "needs attention"; document review; two-person terms and disbursements; letters; enquiry replies; staff management; audit and email logs |
| `backend/letters.py` (new) | Branded PDF letters and agreement drafts from authorised terms only, versioned, DRAFT-marked until issued |
| `backend/marketing.py` (new) | Recorded opt-in consent, unsubscribe (including one-click), suppression list, segments, test sends, scheduled campaigns |
| `backend/content.py`, `backend/cms.py` (new) | Website content drafts, preview, publish with history; rebuilds the static pages |
| `backend/ai.py` (new) | Optional Claude help assistant and staff reply drafts, with redaction and FAQ fallback |
| `backend/audit.py` (new) | Append-only, hash-chained audit log |
| `backend/mail.py` | Separate marketing transport, delivery log, staging diversion, reply-to, new notifications |
| `backend/api.py` | Stricter enquiry validation, staff notification, `/api/health` |
| `backend/__init__.py` | CSP/HSTS/permissions headers, `__Host-` cookie, proxy support; CLI: `check-production`, `remove-dev-admin`, `verify-audit`, `backup`, `send-campaigns`, `purge-expired`, `migrations`, `create-admin --role` |
| `backend/options.py` | Full status lifecycle (approved, agreement issued/signed, funding scheduled, funds disbursed), transitions, document types |
| `backend/templates/` | New sidebar portal layout; new client, staff, auth, marketing and email templates |

### Website
| File | Change |
|---|---|
| `tools/build.py` | Content tokens with visible placeholders, FAQ rendering, canonical/Open Graph tags, sitemap.xml, robots.txt |
| `content/site.json` (new) | Default content and FAQs (no invented facts) |
| `src/layout.html` | Legal links, SEO tags, placeholders replace the fake phone number, email and FCA wording |
| `src/pages/*` | Invented history, staff-implying alt text and unverified product claims removed; 9.9% APR removed; new FAQ, privacy, cookies, terms, complaints and accessibility pages (drafts for legal review) |
| `public/assets/home.js`, `contact.js` (new), `app.js` (new) | Repayment examples only with an approved APR; inline scripts moved to files for CSP |
| `public/assets/site.css`, `app.css` | Placeholder, FAQ, legal-page and portal styles |
| `public/assets/scrollcraft/` | Unchanged (MIT licence kept) |

### Tests, docs, deployment
- `tests/test_backend.py`: the original 8 tests, kept. Assertions changed only where behaviour deliberately changed
  (email confirmation before sign-in, generic duplicate-email response, renamed status).
- `tests/test_features.py` (new): 24 tests covering the new features.
- `README.md` rewritten; `PRODUCTION-CHECKLIST.md`, `deploy/`, `wsgi.py` added; `requirements.txt` updated.
