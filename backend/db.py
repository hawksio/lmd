"""SQLite storage. One connection per request, rows as dict-like sqlite3.Row.

Schema changes are versioned migrations (MIGRATIONS below). On start-up any pending migrations run in
order, each inside a transaction. If the database already holds data, a copy is saved to
instance/backups/ first, so a failed or unwanted migration can always be rolled back by restoring it.
Never edit a migration that has shipped; add a new one.
"""
import hashlib
import os
import sqlite3
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone

from flask import current_app, g

ROOT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# ---------------------------------------------------------------- migrations
M1_INITIAL = """
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY,
    email         TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    full_name     TEXT NOT NULL,
    phone         TEXT NOT NULL DEFAULT '',
    role          TEXT NOT NULL DEFAULT 'client' CHECK (role IN ('client', 'admin')),
    created_at    TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS applications (
    id              INTEGER PRIMARY KEY,
    user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status          TEXT NOT NULL DEFAULT 'draft',
    company_name    TEXT NOT NULL DEFAULT '',
    company_number  TEXT NOT NULL DEFAULT '',
    business_type   TEXT NOT NULL DEFAULT '',
    industry        TEXT NOT NULL DEFAULT '',
    years_trading   TEXT NOT NULL DEFAULT '',
    annual_turnover INTEGER,
    address         TEXT NOT NULL DEFAULT '',
    finance_type    TEXT NOT NULL DEFAULT '',
    amount          INTEGER,
    term_months     INTEGER,
    purpose         TEXT NOT NULL DEFAULT '',
    consent         INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL,
    submitted_at    TEXT
);
CREATE INDEX IF NOT EXISTS ix_applications_user ON applications(user_id);
CREATE INDEX IF NOT EXISTS ix_applications_status ON applications(status);
CREATE TABLE IF NOT EXISTS documents (
    id             INTEGER PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    uploaded_by    INTEGER NOT NULL REFERENCES users(id),
    original_name  TEXT NOT NULL,
    stored_name    TEXT NOT NULL UNIQUE,
    size_bytes     INTEGER NOT NULL,
    created_at     TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS notes (
    id             INTEGER PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    author_id      INTEGER NOT NULL REFERENCES users(id),
    body           TEXT NOT NULL,
    visible_to_client INTEGER NOT NULL DEFAULT 1,
    created_at     TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS status_history (
    id             INTEGER PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    status         TEXT NOT NULL,
    changed_by     INTEGER REFERENCES users(id),
    created_at     TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at    TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS enquiries (
    id         INTEGER PRIMARY KEY,
    name       TEXT NOT NULL,
    company    TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL DEFAULT '',
    topic      TEXT NOT NULL DEFAULT 'general',
    message    TEXT NOT NULL DEFAULT '',
    handled    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);
"""

# Staff roles, account security, sessions, audit, notifications and email logging.
# users is rebuilt (SQLite cannot change a CHECK constraint in place); every row is copied.
M2_SECURITY = """
CREATE TABLE users_new (
    id                  INTEGER PRIMARY KEY,
    email               TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash       TEXT NOT NULL,
    full_name           TEXT NOT NULL,
    phone               TEXT NOT NULL DEFAULT '',
    role                TEXT NOT NULL DEFAULT 'client'
                        CHECK (role IN ('client', 'admin', 'reviewer', 'support', 'auditor')),
    created_at          TEXT NOT NULL,
    is_active           INTEGER NOT NULL DEFAULT 1,
    email_verified_at   TEXT,
    password_changed_at TEXT,
    last_login_at       TEXT,
    totp_secret         TEXT,
    totp_enabled_at     TEXT,
    totp_last_step      INTEGER,
    marketing_opt_in_at TEXT
);
INSERT INTO users_new (id, email, password_hash, full_name, phone, role, created_at, email_verified_at)
    SELECT id, email, password_hash, full_name, phone, role, created_at,
           CASE WHEN role = 'admin' THEN created_at END   -- staff were created by an operator; clients must verify
    FROM users;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
CREATE INDEX ix_users_role ON users(role);

CREATE TABLE email_verifications (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at    TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE user_sessions (
    id           INTEGER PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    sid_hash     TEXT NOT NULL UNIQUE,          -- sha256 of the random id in the signed cookie
    created_at   TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at   TEXT NOT NULL,
    ip           TEXT NOT NULL DEFAULT '',
    user_agent   TEXT NOT NULL DEFAULT '',
    revoked_at   TEXT
);
CREATE INDEX ix_sessions_user ON user_sessions(user_id);

CREATE TABLE recovery_codes (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash  TEXT NOT NULL,
    used_at    TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE rate_limits (
    key_hash TEXT NOT NULL,
    at       REAL NOT NULL
);
CREATE INDEX ix_rate_limits ON rate_limits(key_hash, at);

-- Append-only, hash-chained audit trail. Triggers block UPDATE and DELETE for everyone using the app.
CREATE TABLE audit_log (
    id          INTEGER PRIMARY KEY,
    created_at  TEXT NOT NULL,
    actor_id    INTEGER,
    actor_role  TEXT NOT NULL DEFAULT '',
    event       TEXT NOT NULL,
    target_type TEXT NOT NULL DEFAULT '',
    target_id   INTEGER,
    detail      TEXT NOT NULL DEFAULT '',
    ip          TEXT NOT NULL DEFAULT '',
    prev_hash   TEXT NOT NULL,
    row_hash    TEXT NOT NULL
);
CREATE INDEX ix_audit_target ON audit_log(target_type, target_id);
CREATE INDEX ix_audit_event ON audit_log(event);
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
    BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
    BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;

CREATE TABLE notifications (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message    TEXT NOT NULL,
    link       TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    read_at    TEXT
);
CREATE INDEX ix_notifications_user ON notifications(user_id, read_at);

CREATE TABLE email_log (
    id         INTEGER PRIMARY KEY,
    created_at TEXT NOT NULL,
    category   TEXT NOT NULL DEFAULT 'transactional',
    template   TEXT NOT NULL,
    recipient  TEXT NOT NULL,
    subject    TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'queued',  -- queued | sent | outbox | captured | failed
    error      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
);
CREATE INDEX ix_email_log_status ON email_log(status);
"""

# Application workflow: richer statuses, document review, approved terms, generated letters, disbursements.
M3_WORKFLOW = """
ALTER TABLE applications ADD COLUMN role_in_business TEXT NOT NULL DEFAULT '';
ALTER TABLE applications ADD COLUMN submission_key TEXT;
ALTER TABLE applications ADD COLUMN assigned_to INTEGER REFERENCES users(id);
CREATE UNIQUE INDEX ux_applications_submission ON applications(user_id, submission_key) WHERE submission_key IS NOT NULL;
CREATE INDEX ix_applications_updated ON applications(updated_at);

ALTER TABLE status_history ADD COLUMN note TEXT NOT NULL DEFAULT '';

ALTER TABLE documents ADD COLUMN doc_type TEXT NOT NULL DEFAULT 'other';
ALTER TABLE documents ADD COLUMN review_status TEXT NOT NULL DEFAULT 'received';
ALTER TABLE documents ADD COLUMN reviewed_by INTEGER REFERENCES users(id);
ALTER TABLE documents ADD COLUMN reviewed_at TEXT;
ALTER TABLE documents ADD COLUMN review_note TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN sha256 TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN mime TEXT NOT NULL DEFAULT '';
CREATE INDEX ix_documents_application ON documents(application_id);
CREATE INDEX ix_notes_application ON notes(application_id);
CREATE INDEX ix_history_application ON status_history(application_id);

-- Terms come from a genuine credit decision. Entered by one staff member, authorised by a different one.
CREATE TABLE approved_terms (
    id                 INTEGER PRIMARY KEY,
    application_id     INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    lender_name        TEXT NOT NULL DEFAULT '',
    amount_pence       INTEGER NOT NULL CHECK (amount_pence > 0),
    term_months        INTEGER NOT NULL CHECK (term_months > 0),
    interest_rate      TEXT NOT NULL,
    apr                TEXT NOT NULL DEFAULT '',
    fees               TEXT NOT NULL DEFAULT '',
    repayment_pence    INTEGER NOT NULL CHECK (repayment_pence > 0),
    repayment_frequency TEXT NOT NULL DEFAULT 'monthly',
    total_repayable_pence INTEGER NOT NULL CHECK (total_repayable_pence > 0),
    security           TEXT NOT NULL DEFAULT '',
    conditions         TEXT NOT NULL DEFAULT '',
    status             TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'authorised', 'superseded')),
    entered_by         INTEGER NOT NULL REFERENCES users(id),
    entered_at         TEXT NOT NULL,
    authorised_by      INTEGER REFERENCES users(id),
    authorised_at      TEXT,
    CHECK (authorised_by IS NULL OR authorised_by != entered_by)
);
CREATE INDEX ix_terms_application ON approved_terms(application_id);

CREATE TABLE generated_documents (
    id             INTEGER PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    kind           TEXT NOT NULL,
    version        INTEGER NOT NULL,
    status         TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'issued', 'superseded')),
    title          TEXT NOT NULL,
    body           TEXT NOT NULL DEFAULT '',     -- staff-written text (e.g. what information is requested)
    stored_name    TEXT NOT NULL UNIQUE,
    sha256         TEXT NOT NULL,
    terms_id       INTEGER REFERENCES approved_terms(id),
    generated_by   INTEGER NOT NULL REFERENCES users(id),
    generated_at   TEXT NOT NULL,
    issued_by      INTEGER REFERENCES users(id),
    issued_at      TEXT,
    UNIQUE (application_id, kind, version)
);

-- Money actually paid out. Recorded by one administrator, confirmed by another against the bank record.
CREATE TABLE disbursements (
    id                INTEGER PRIMARY KEY,
    application_id    INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    amount_pence      INTEGER NOT NULL CHECK (amount_pence > 0),
    paid_on           TEXT NOT NULL,
    method            TEXT NOT NULL,
    payment_reference TEXT NOT NULL,
    note              TEXT NOT NULL DEFAULT '',
    status            TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'rejected')),
    recorded_by       INTEGER NOT NULL REFERENCES users(id),
    recorded_at       TEXT NOT NULL,
    confirmed_by      INTEGER REFERENCES users(id),
    confirmed_at      TEXT,
    CHECK (confirmed_by IS NULL OR confirmed_by != recorded_by)
);

ALTER TABLE enquiries ADD COLUMN handled_by INTEGER REFERENCES users(id);
ALTER TABLE enquiries ADD COLUMN handled_at TEXT;
CREATE TABLE enquiry_replies (
    id         INTEGER PRIMARY KEY,
    enquiry_id INTEGER NOT NULL REFERENCES enquiries(id) ON DELETE CASCADE,
    author_id  INTEGER NOT NULL REFERENCES users(id),
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);
"""

# Website content (draft / published versions) and consent-based marketing.
M4_CONTENT_MARKETING = """
CREATE TABLE content_versions (
    id           INTEGER PRIMARY KEY,
    data         TEXT NOT NULL,                 -- JSON: settings + FAQs
    status       TEXT NOT NULL CHECK (status IN ('draft', 'published', 'superseded', 'discarded')),
    note         TEXT NOT NULL DEFAULT '',
    created_by   INTEGER REFERENCES users(id),
    created_at   TEXT NOT NULL,
    published_by INTEGER REFERENCES users(id),
    published_at TEXT
);

CREATE TABLE marketing_consent_events (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email      TEXT NOT NULL COLLATE NOCASE,
    action     TEXT NOT NULL CHECK (action IN ('opt_in', 'opt_out')),
    source     TEXT NOT NULL,
    wording    TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX ix_consent_email ON marketing_consent_events(email);

CREATE TABLE suppressions (
    email      TEXT PRIMARY KEY COLLATE NOCASE,
    reason     TEXT NOT NULL,                   -- unsubscribed | bounced | complaint | manual
    created_at TEXT NOT NULL
);

CREATE TABLE campaigns (
    id            INTEGER PRIMARY KEY,
    name          TEXT NOT NULL,
    subject       TEXT NOT NULL,
    preheader     TEXT NOT NULL DEFAULT '',
    body          TEXT NOT NULL,                -- plain text; rendered into the branded template
    segment       TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'draft'
                  CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled')),
    scheduled_for TEXT,
    created_by    INTEGER NOT NULL REFERENCES users(id),
    created_at    TEXT NOT NULL,
    launched_by   INTEGER REFERENCES users(id),
    sent_at       TEXT
);

CREATE TABLE campaign_recipients (
    id          INTEGER PRIMARY KEY,
    campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
    user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email       TEXT NOT NULL COLLATE NOCASE,
    status      TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed', 'skipped')),
    sent_at     TEXT,
    error       TEXT NOT NULL DEFAULT '',
    UNIQUE (campaign_id, email)
);
"""

# Multi-tenancy: every organisation's data is tagged with org_id. Existing data becomes organisation 1.
# users is rebuilt so an email is unique per organisation, and roles become the SaaS role set.
M5_TENANCY = """
CREATE TABLE organisations (
    id               INTEGER PRIMARY KEY,
    slug             TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name             TEXT NOT NULL,
    status           TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'closed')),
    brand_color      TEXT NOT NULL DEFAULT '#F08A6E',
    logo_file        TEXT,
    default_currency TEXT NOT NULL DEFAULT 'GBP',
    settings         TEXT NOT NULL DEFAULT '{}',   -- JSON: workflow, notification and limit settings
    created_at       TEXT NOT NULL
);
INSERT INTO organisations (id, slug, name, created_at) VALUES (1, 'lmd', 'LMD Finance Ltd', strftime('%Y-%m-%d %H:%M:%S', 'now'));

CREATE TABLE organisation_domains (
    host       TEXT PRIMARY KEY COLLATE NOCASE,
    org_id     INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    is_primary INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE users_new (
    id                  INTEGER PRIMARY KEY,
    org_id              INTEGER REFERENCES organisations(id),
    email               TEXT NOT NULL COLLATE NOCASE,
    password_hash       TEXT NOT NULL,
    full_name           TEXT NOT NULL,
    phone               TEXT NOT NULL DEFAULT '',
    address             TEXT NOT NULL DEFAULT '',
    role                TEXT NOT NULL DEFAULT 'client' CHECK (role IN ('client', 'admin', 'underwriter', 'document_reviewer',
                        'support', 'finance', 'auditor', 'platform_owner')),
    created_at          TEXT NOT NULL,
    is_active           INTEGER NOT NULL DEFAULT 1,
    email_verified_at   TEXT,
    password_changed_at TEXT,
    last_login_at       TEXT,
    totp_secret         TEXT,
    totp_enabled_at     TEXT,
    totp_last_step      INTEGER,
    marketing_opt_in_at TEXT,
    CHECK ((role = 'platform_owner') = (org_id IS NULL))   -- platform owners belong to no organisation
);
INSERT INTO users_new (id, org_id, email, password_hash, full_name, phone, role, created_at, is_active, email_verified_at,
                       password_changed_at, last_login_at, totp_secret, totp_enabled_at, totp_last_step, marketing_opt_in_at)
    SELECT id, 1, email, password_hash, full_name, phone, CASE role WHEN 'reviewer' THEN 'underwriter' ELSE role END,
           created_at, is_active, email_verified_at, password_changed_at, last_login_at, totp_secret, totp_enabled_at,
           totp_last_step, marketing_opt_in_at
    FROM users;
DROP TABLE users;
ALTER TABLE users_new RENAME TO users;
CREATE UNIQUE INDEX ux_users_org_email ON users(org_id, email);
CREATE UNIQUE INDEX ux_users_platform_email ON users(email) WHERE org_id IS NULL;
CREATE INDEX ix_users_role ON users(org_id, role);

ALTER TABLE applications ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE documents ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE generated_documents ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE disbursements ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE enquiries ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE content_versions ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE campaigns ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE marketing_consent_events ADD COLUMN org_id INTEGER NOT NULL DEFAULT 1;
ALTER TABLE email_log ADD COLUMN org_id INTEGER;
ALTER TABLE audit_log ADD COLUMN org_id INTEGER;
UPDATE email_log SET org_id = 1;
-- One-off backfill of the new column (org_id is not part of the hash chain), then append-only again.
DROP TRIGGER audit_log_no_update;
UPDATE audit_log SET org_id = 1;
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
    BEGIN SELECT RAISE(ABORT, 'audit_log is append-only'); END;
CREATE INDEX ix_applications_org ON applications(org_id, status);
CREATE INDEX ix_documents_org ON documents(org_id);
CREATE INDEX ix_enquiries_org ON enquiries(org_id);
CREATE INDEX ix_content_org ON content_versions(org_id, status);
CREATE INDEX ix_audit_org ON audit_log(org_id, id);
CREATE INDEX ix_email_org ON email_log(org_id, id);

CREATE TABLE suppressions_new (
    org_id     INTEGER NOT NULL,
    email      TEXT NOT NULL COLLATE NOCASE,
    reason     TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (org_id, email)
);
INSERT INTO suppressions_new SELECT 1, email, reason, created_at FROM suppressions;
DROP TABLE suppressions;
ALTER TABLE suppressions_new RENAME TO suppressions;

-- Status set for the underwriting workflow.
UPDATE applications SET status = CASE status WHEN 'in_review' THEN 'initial_review' WHEN 'documents_requested' THEN 'info_requested'
    WHEN 'with_lender' THEN 'underwriting' WHEN 'approved' THEN 'conditionally_approved' WHEN 'disbursed' THEN 'fully_disbursed'
    ELSE status END;
UPDATE status_history SET status = CASE status WHEN 'in_review' THEN 'initial_review' WHEN 'documents_requested' THEN 'info_requested'
    WHEN 'with_lender' THEN 'underwriting' WHEN 'approved' THEN 'conditionally_approved' WHEN 'disbursed' THEN 'fully_disbursed'
    ELSE status END;
"""

# Document verification workflow: statuses, separate internal and customer notes, review history, requests.
M6_DOCUMENTS = """
ALTER TABLE documents ADD COLUMN internal_note TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN customer_note TEXT NOT NULL DEFAULT '';
ALTER TABLE documents ADD COLUMN expires_on TEXT;
ALTER TABLE documents ADD COLUMN replaces_id INTEGER REFERENCES documents(id);
UPDATE documents SET customer_note = review_note,
    review_status = CASE review_status WHEN 'received' THEN 'submitted' WHEN 'accepted' THEN 'verified'
                    WHEN 'replacement_needed' THEN 'replacement_required' ELSE review_status END;
CREATE INDEX ix_documents_review ON documents(org_id, review_status);

CREATE TABLE document_reviews (
    id            INTEGER PRIMARY KEY,
    org_id        INTEGER NOT NULL,
    document_id   INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    status        TEXT NOT NULL,
    internal_note TEXT NOT NULL DEFAULT '',
    customer_note TEXT NOT NULL DEFAULT '',
    reviewer_id   INTEGER NOT NULL REFERENCES users(id),
    created_at    TEXT NOT NULL
);
CREATE INDEX ix_document_reviews_doc ON document_reviews(document_id);

-- Ad-hoc requests for evidence, on top of the documents a loan product always requires.
CREATE TABLE document_requests (
    id             INTEGER PRIMARY KEY,
    org_id         INTEGER NOT NULL,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    doc_type       TEXT NOT NULL,
    note           TEXT NOT NULL DEFAULT '',          -- shown to the customer
    status         TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'fulfilled', 'cancelled')),
    requested_by   INTEGER NOT NULL REFERENCES users(id),
    created_at     TEXT NOT NULL,
    closed_at      TEXT
);
CREATE INDEX ix_document_requests_app ON document_requests(application_id);
"""

# Loan products, percentage & financial rules (with history), approval decisions and offer acceptance.
M7_PRODUCTS_PRICING = """
CREATE TABLE loan_products (
    id                    INTEGER PRIMARY KEY,
    org_id                INTEGER NOT NULL,
    name                  TEXT NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    finance_type          TEXT NOT NULL DEFAULT 'loans',
    currencies            TEXT NOT NULL DEFAULT 'GBP',          -- comma-separated ISO codes
    min_amount_minor      INTEGER NOT NULL CHECK (min_amount_minor > 0),
    max_amount_minor      INTEGER NOT NULL,
    min_term_months       INTEGER NOT NULL CHECK (min_term_months > 0),
    max_term_months       INTEGER NOT NULL,
    interest_method       TEXT NOT NULL DEFAULT 'amortising' CHECK (interest_method IN ('amortising', 'flat')),
    repayment_frequency   TEXT NOT NULL DEFAULT 'monthly' CHECK (repayment_frequency IN ('monthly', 'fortnightly', 'weekly')),
    grace_period_months   INTEGER NOT NULL DEFAULT 0,
    repayment_method      TEXT NOT NULL DEFAULT '',
    eligibility           TEXT NOT NULL DEFAULT '',
    required_docs         TEXT NOT NULL DEFAULT '',             -- comma-separated document types
    form_fields           TEXT NOT NULL DEFAULT '[]',           -- JSON list of extra questions
    allow_above_requested INTEGER NOT NULL DEFAULT 0,
    second_approval_threshold_minor INTEGER,                    -- NULL = use the organisation setting
    active                INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL,
    CHECK (max_amount_minor >= min_amount_minor),
    CHECK (max_term_months >= min_term_months),
    UNIQUE (org_id, name)
);

ALTER TABLE applications ADD COLUMN product_id INTEGER REFERENCES loan_products(id);
ALTER TABLE applications ADD COLUMN currency TEXT NOT NULL DEFAULT 'GBP';
ALTER TABLE applications ADD COLUMN extra_fields TEXT NOT NULL DEFAULT '{}';

CREATE TABLE financial_rules (
    id                    INTEGER PRIMARY KEY,
    org_id                INTEGER NOT NULL,
    kind                  TEXT NOT NULL CHECK (kind IN ('interest', 'processing_fee', 'admin_fee', 'document_fee',
                          'late_charge', 'commission', 'service_fee', 'custom')),
    name                  TEXT NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    method                TEXT NOT NULL DEFAULT 'percent' CHECK (method IN ('percent', 'fixed')),
    percent               TEXT,                                 -- exact decimal string, e.g. '2.5'
    fixed_minor           INTEGER,
    basis                 TEXT NOT NULL CHECK (basis IN ('requested', 'approved', 'disbursed', 'outstanding', 'instalment')),
    product_ids           TEXT NOT NULL DEFAULT '',             -- empty = every product
    currencies            TEXT NOT NULL DEFAULT '',             -- empty = every currency
    timing                TEXT NOT NULL CHECK (timing IN ('interest', 'deducted_from_disbursement', 'added_to_loan',
                          'with_first_instalment', 'on_overdue_instalment', 'not_charged_to_customer')),
    conditions            TEXT NOT NULL DEFAULT '',
    disclosure            TEXT NOT NULL DEFAULT '',
    arrangement_reference TEXT NOT NULL DEFAULT '',             -- commission: the documented arrangement
    effective_from        TEXT NOT NULL,
    effective_to          TEXT,
    enabled               INTEGER NOT NULL DEFAULT 1,
    version               INTEGER NOT NULL DEFAULT 1,
    created_by            INTEGER NOT NULL REFERENCES users(id),
    created_at            TEXT NOT NULL,
    updated_by            INTEGER REFERENCES users(id),
    updated_at            TEXT NOT NULL,
    CHECK ((method = 'percent' AND percent IS NOT NULL) OR (method = 'fixed' AND fixed_minor IS NOT NULL))
);
CREATE INDEX ix_rules_org ON financial_rules(org_id, kind, enabled);

CREATE TABLE financial_rule_history (
    id         INTEGER PRIMARY KEY,
    org_id     INTEGER NOT NULL,
    rule_id    INTEGER NOT NULL REFERENCES financial_rules(id),
    version    INTEGER NOT NULL,
    action     TEXT NOT NULL,
    old_values TEXT NOT NULL DEFAULT '{}',
    new_values TEXT NOT NULL DEFAULT '{}',
    reason     TEXT NOT NULL DEFAULT '',
    changed_by INTEGER NOT NULL REFERENCES users(id),
    changed_at TEXT NOT NULL
);
CREATE INDEX ix_rule_history ON financial_rule_history(rule_id);

-- An underwriting decision. 'pricing' freezes the rules and the full calculation used, so later changes to the
-- organisation's settings never alter an offer that has been made.
CREATE TABLE approval_decisions (
    id                      INTEGER PRIMARY KEY,
    org_id                  INTEGER NOT NULL,
    application_id          INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    revision                INTEGER NOT NULL,
    decision_type           TEXT NOT NULL CHECK (decision_type IN ('full', 'partial', 'conditional')),
    status                  TEXT NOT NULL CHECK (status IN ('proposed', 'issued', 'superseded', 'withdrawn')),
    currency                TEXT NOT NULL,
    requested_minor         INTEGER NOT NULL,
    approved_minor          INTEGER NOT NULL CHECK (approved_minor > 0),
    previous_approved_minor INTEGER,
    term_months             INTEGER NOT NULL CHECK (term_months > 0),
    product_id              INTEGER REFERENCES loan_products(id),
    reason                  TEXT NOT NULL,
    internal_note           TEXT NOT NULL DEFAULT '',
    pricing                 TEXT NOT NULL,
    needs_second_approval   INTEGER NOT NULL DEFAULT 1,
    proposed_by             INTEGER NOT NULL REFERENCES users(id),
    proposed_at             TEXT NOT NULL,
    issued_by               INTEGER REFERENCES users(id),
    issued_at               TEXT,
    UNIQUE (application_id, revision),
    CHECK (needs_second_approval = 0 OR issued_by IS NULL OR issued_by != proposed_by)
);
CREATE INDEX ix_decisions_app ON approval_decisions(application_id, status);

CREATE TABLE decision_conditions (
    id          INTEGER PRIMARY KEY,
    org_id      INTEGER NOT NULL,
    decision_id INTEGER NOT NULL REFERENCES approval_decisions(id) ON DELETE CASCADE,
    text        TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'outstanding' CHECK (status IN ('outstanding', 'satisfied', 'waived')),
    updated_by  INTEGER REFERENCES users(id),
    updated_at  TEXT
);

CREATE TABLE offer_acceptances (
    id           INTEGER PRIMARY KEY,
    org_id       INTEGER NOT NULL,
    decision_id  INTEGER NOT NULL UNIQUE REFERENCES approval_decisions(id),
    user_id      INTEGER NOT NULL REFERENCES users(id),
    pricing_hash TEXT NOT NULL,                     -- proves which exact figures were shown and accepted
    ip           TEXT NOT NULL DEFAULT '',
    accepted_at  TEXT NOT NULL
);

ALTER TABLE generated_documents ADD COLUMN decision_id INTEGER REFERENCES approval_decisions(id);
ALTER TABLE generated_documents ADD COLUMN ref_id INTEGER;   -- e.g. the repayment or fee a receipt is for
"""

# Money movements: disbursements (scheduled -> paid -> confirmed by a second person), repayment schedules,
# repayments and fee records. disbursements is rebuilt to widen its status set.
M8_FINANCE = """
CREATE TABLE disbursements_new (
    id                  INTEGER PRIMARY KEY,
    org_id              INTEGER NOT NULL DEFAULT 1,
    application_id      INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    decision_id         INTEGER REFERENCES approval_decisions(id),
    currency            TEXT NOT NULL DEFAULT 'GBP',
    amount_pence        INTEGER NOT NULL CHECK (amount_pence > 0),     -- principal released (minor units)
    fees_deducted_minor INTEGER NOT NULL DEFAULT 0,                    -- fees taken from this payment
    scheduled_for       TEXT,
    paid_on             TEXT,
    method              TEXT NOT NULL DEFAULT '',
    payment_reference   TEXT NOT NULL DEFAULT '',
    confirmation_source TEXT NOT NULL DEFAULT '',
    note                TEXT NOT NULL DEFAULT '',
    status              TEXT NOT NULL DEFAULT 'scheduled'
                        CHECK (status IN ('scheduled', 'pending', 'confirmed', 'rejected', 'cancelled', 'reversed')),
    recorded_by         INTEGER NOT NULL REFERENCES users(id),
    recorded_at         TEXT NOT NULL,
    confirmed_by        INTEGER REFERENCES users(id),
    confirmed_at        TEXT,
    reversed_by         INTEGER REFERENCES users(id),
    reversed_at         TEXT,
    reversal_reason     TEXT NOT NULL DEFAULT '',
    CHECK (confirmed_by IS NULL OR confirmed_by != recorded_by)
);
INSERT INTO disbursements_new (id, org_id, application_id, amount_pence, paid_on, method, payment_reference, note, status,
                               recorded_by, recorded_at, confirmed_by, confirmed_at, confirmation_source)
    SELECT id, org_id, application_id, amount_pence, paid_on, method, payment_reference, note, status, recorded_by,
           recorded_at, confirmed_by, confirmed_at, CASE WHEN status = 'confirmed' THEN 'bank_statement' ELSE '' END
    FROM disbursements;
DROP TABLE disbursements;
ALTER TABLE disbursements_new RENAME TO disbursements;
CREATE INDEX ix_disbursements_app ON disbursements(application_id);
CREATE INDEX ix_disbursements_org ON disbursements(org_id, status);
CREATE UNIQUE INDEX ux_disbursement_reference ON disbursements(org_id, payment_reference)
    WHERE payment_reference != '' AND status IN ('pending', 'confirmed');

CREATE TABLE repayment_schedules (
    id              INTEGER PRIMARY KEY,
    org_id          INTEGER NOT NULL,
    application_id  INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    decision_id     INTEGER NOT NULL REFERENCES approval_decisions(id),
    currency        TEXT NOT NULL,
    principal_minor INTEGER NOT NULL CHECK (principal_minor > 0),
    first_due_date  TEXT NOT NULL,
    frequency       TEXT NOT NULL,
    terms           TEXT NOT NULL,                  -- JSON copy of the frozen terms used
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'superseded', 'closed')),
    created_by      INTEGER NOT NULL REFERENCES users(id),
    created_at      TEXT NOT NULL
);
CREATE UNIQUE INDEX ux_active_schedule ON repayment_schedules(application_id) WHERE status = 'active';

CREATE TABLE instalments (
    id              INTEGER PRIMARY KEY,
    org_id          INTEGER NOT NULL,
    schedule_id     INTEGER NOT NULL REFERENCES repayment_schedules(id) ON DELETE CASCADE,
    number          INTEGER NOT NULL,
    due_date        TEXT NOT NULL,
    principal_minor INTEGER NOT NULL,
    interest_minor  INTEGER NOT NULL,
    fees_minor      INTEGER NOT NULL DEFAULT 0,
    amount_minor    INTEGER NOT NULL,
    UNIQUE (schedule_id, number)
);

CREATE TABLE repayments (
    id                  INTEGER PRIMARY KEY,
    org_id              INTEGER NOT NULL,
    application_id      INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    schedule_id         INTEGER REFERENCES repayment_schedules(id),
    amount_minor        INTEGER NOT NULL CHECK (amount_minor > 0),
    currency            TEXT NOT NULL,
    received_on         TEXT NOT NULL,
    method              TEXT NOT NULL,
    provider_reference  TEXT NOT NULL,
    confirmation_source TEXT NOT NULL CHECK (confirmation_source IN ('bank_statement', 'payment_provider', 'other')),
    status              TEXT NOT NULL DEFAULT 'confirmed' CHECK (status IN ('confirmed', 'reversed')),
    note                TEXT NOT NULL DEFAULT '',
    recorded_by         INTEGER NOT NULL REFERENCES users(id),
    recorded_at         TEXT NOT NULL,
    reversed_by         INTEGER REFERENCES users(id),
    reversed_at         TEXT,
    reversal_reason     TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX ux_repayment_reference ON repayments(org_id, provider_reference);   -- the same payment can't be recorded twice
CREATE INDEX ix_repayments_app ON repayments(application_id);

CREATE TABLE fee_items (
    id                INTEGER PRIMARY KEY,
    org_id            INTEGER NOT NULL,
    application_id    INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    decision_id       INTEGER REFERENCES approval_decisions(id),
    instalment_id     INTEGER REFERENCES instalments(id),
    rule_id           INTEGER REFERENCES financial_rules(id),
    rule_version      INTEGER,
    kind              TEXT NOT NULL,
    name              TEXT NOT NULL,
    basis             TEXT NOT NULL,
    basis_minor       INTEGER NOT NULL DEFAULT 0,
    percent           TEXT,
    amount_minor      INTEGER NOT NULL CHECK (amount_minor >= 0),
    currency          TEXT NOT NULL,
    timing            TEXT NOT NULL,
    disclosure        TEXT NOT NULL DEFAULT '',
    payable_when      TEXT NOT NULL DEFAULT '',
    status            TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'waived', 'refunded', 'cancelled')),
    paid_at           TEXT,
    payment_reference TEXT NOT NULL DEFAULT '',
    payment_source    TEXT NOT NULL DEFAULT '',
    status_reason     TEXT NOT NULL DEFAULT '',
    updated_by        INTEGER REFERENCES users(id),
    created_at        TEXT NOT NULL,
    updated_at        TEXT NOT NULL
);
CREATE INDEX ix_fee_items_app ON fee_items(application_id);
CREATE INDEX ix_fee_items_org ON fee_items(org_id, status);
"""

# Platform owner: plans, subscriptions, subscription invoices and platform-wide settings.
M9_PLATFORM = """
CREATE TABLE plans (
    id                     INTEGER PRIMARY KEY,
    code                   TEXT NOT NULL UNIQUE,
    name                   TEXT NOT NULL,
    description            TEXT NOT NULL DEFAULT '',
    price_minor            INTEGER NOT NULL CHECK (price_minor >= 0),
    currency               TEXT NOT NULL DEFAULT 'GBP',
    billing_period         TEXT NOT NULL DEFAULT 'month' CHECK (billing_period IN ('month', 'year')),
    trial_days             INTEGER NOT NULL DEFAULT 0,
    max_staff              INTEGER,                 -- NULL = unlimited
    max_applications_month INTEGER,
    max_products           INTEGER,
    platform_fee_percent   TEXT NOT NULL DEFAULT '0',   -- optional usage fee on confirmed disbursements, billed to the organisation
    features               TEXT NOT NULL DEFAULT '',
    active                 INTEGER NOT NULL DEFAULT 1,
    created_at             TEXT NOT NULL
);

CREATE TABLE subscriptions (
    id                   INTEGER PRIMARY KEY,
    org_id               INTEGER NOT NULL UNIQUE,
    plan_id              INTEGER NOT NULL REFERENCES plans(id),
    status               TEXT NOT NULL CHECK (status IN ('trial', 'active', 'past_due', 'cancelled', 'suspended')),
    billing_mode         TEXT NOT NULL DEFAULT 'manual' CHECK (billing_mode IN ('manual', 'provider')),
    trial_ends_on        TEXT,
    current_period_start TEXT,
    current_period_end   TEXT,
    cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
    cancelled_at         TEXT,
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL
);

CREATE TABLE subscription_invoices (
    id                INTEGER PRIMARY KEY,
    org_id            INTEGER NOT NULL,
    subscription_id   INTEGER NOT NULL REFERENCES subscriptions(id),
    number            TEXT NOT NULL UNIQUE,
    period_start      TEXT NOT NULL,
    period_end        TEXT NOT NULL,
    plan_amount_minor INTEGER NOT NULL,
    usage_fee_minor   INTEGER NOT NULL DEFAULT 0,
    currency          TEXT NOT NULL,
    status            TEXT NOT NULL DEFAULT 'issued' CHECK (status IN ('issued', 'paid', 'void')),
    issued_at         TEXT NOT NULL,
    paid_at           TEXT,
    payment_reference TEXT NOT NULL DEFAULT '',
    recorded_by       INTEGER REFERENCES users(id),
    UNIQUE (subscription_id, period_start)
);

CREATE TABLE platform_settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
"""

MIGRATIONS = [
    (1, "initial schema", M1_INITIAL, False),
    (2, "staff roles, sessions, audit log, notifications, email log", M2_SECURITY, True),
    (3, "application workflow, terms, generated documents, disbursements", M3_WORKFLOW, False),
    (4, "website content versions and marketing consent", M4_CONTENT_MARKETING, False),
    (5, "organisations (multi-tenancy), SaaS roles, underwriting statuses", M5_TENANCY, True),
    (6, "document verification workflow", M6_DOCUMENTS, False),
    (7, "loan products, financial rules, approval decisions", M7_PRODUCTS_PRICING, False),
    (8, "disbursements, repayment schedules, repayments, fees", M8_FINANCE, True),
    (9, "plans, subscriptions, platform settings", M9_PLATFORM, False),
]


def now():
    return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def later(**delta):
    return (datetime.now(timezone.utc) + timedelta(**delta)).strftime("%Y-%m-%d %H:%M:%S")


def _sha256(value):
    return hashlib.sha256(("" if value is None else str(value)).encode()).hexdigest()


def _connect(path):
    conn = sqlite3.connect(path, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.create_function("lmd_sha256", 1, _sha256, deterministic=True)  # used by the audit hash chain
    return conn


def get_db():
    if "db" not in g:
        g.db = _connect(current_app.config["DATABASE"])
        g.db.execute("PRAGMA foreign_keys = ON")
        g.db.execute("PRAGMA busy_timeout = 5000")
    return g.db


def close_db(_exc=None):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def applied_versions(conn):
    conn.execute("CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)")
    return {r[0] for r in conn.execute("SELECT version FROM schema_migrations")}


def backup_database(src_path, dest_dir, label):
    """Consistent online copy using SQLite's backup API (safe while the app is running)."""
    os.makedirs(dest_dir, exist_ok=True)
    dest = os.path.join(dest_dir, f"lmd-{label}-{datetime.now().strftime('%Y%m%d-%H%M%S')}.sqlite3")
    src = sqlite3.connect(src_path)
    try:
        with sqlite3.connect(dest) as out:
            src.backup(out)
    finally:
        src.close()
    return dest


def migrate(db_path, backup_dir):
    """Apply pending migrations. Returns the list of versions applied."""
    conn = _connect(db_path)
    try:
        conn.execute("PRAGMA journal_mode = WAL")
        done = applied_versions(conn)
        pending = [m for m in MIGRATIONS if m[0] not in done]
        if not pending:
            return []
        has_data = conn.execute("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'").fetchone()[0]
        if has_data:
            backup_database(db_path, backup_dir, f"pre-migration-{pending[0][0]}")
        for version, name, sql, rebuilds in pending:
            if rebuilds:
                conn.execute("PRAGMA foreign_keys = OFF")  # required for a table rebuild; checked afterwards
            try:
                conn.executescript(f"BEGIN;\n{sql}\nINSERT INTO schema_migrations VALUES ({version}, '{name}', '{now()}');\nCOMMIT;")
            except Exception:
                if conn.in_transaction:
                    conn.rollback()
                raise
            if rebuilds:
                problems = conn.execute("PRAGMA foreign_key_check").fetchall()
                if problems:
                    raise RuntimeError(f"Migration {version} left broken foreign keys: {problems[:5]}")
                conn.execute("PRAGMA foreign_keys = ON")
        return [m[0] for m in pending]
    finally:
        conn.close()


def init_db(app):
    migrate(app.config["DATABASE"], os.path.join(app.instance_path, "backups"))
    app.teardown_appcontext(close_db)


def query(sql, args=(), one=False):
    cur = get_db().execute(sql, args)
    rows = cur.fetchall()
    return (rows[0] if rows else None) if one else rows


def execute(sql, args=()):
    db = get_db()
    cur = db.execute(sql, args)
    if not g.get("tx_depth"):
        db.commit()
    return cur.lastrowid


@contextmanager
def transaction():
    """Group several writes so they all happen or none do."""
    db = get_db()
    g.tx_depth = g.get("tx_depth", 0) + 1
    try:
        yield db
        g.tx_depth -= 1
        if g.tx_depth == 0:
            db.commit()
    except Exception:
        g.tx_depth -= 1
        if g.tx_depth == 0:
            db.rollback()
        raise
