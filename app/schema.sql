-- LMD loan platform, PHP edition: complete schema (SQLite).
-- Same tables and rules as the Flask edition after its migrations 1-9, created in one step for a fresh install.
-- Later changes go in app/migrations.php as numbered migrations; never edit this file for an existing database.

CREATE TABLE organisations (
    id               INTEGER PRIMARY KEY,
    slug             TEXT NOT NULL UNIQUE COLLATE NOCASE,
    name             TEXT NOT NULL,
    status           TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'closed')),
    brand_color      TEXT NOT NULL DEFAULT '#F08A6E',
    logo_file        TEXT,
    default_currency TEXT NOT NULL DEFAULT 'GBP',
    settings         TEXT NOT NULL DEFAULT '{}',
    created_at       TEXT NOT NULL
);

-- The organisation that owns the public website. More organisations are added from the platform console.
INSERT INTO organisations (id, slug, name, created_at) VALUES (1, 'lmd', 'LMD Finance Ltd', strftime('%Y-%m-%d %H:%M:%S', 'now'));

CREATE TABLE organisation_domains (
    host       TEXT PRIMARY KEY COLLATE NOCASE,
    org_id     INTEGER NOT NULL REFERENCES organisations(id) ON DELETE CASCADE,
    is_primary INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE users (
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
    CHECK ((role = 'platform_owner') = (org_id IS NULL))
);
CREATE UNIQUE INDEX ux_users_org_email ON users(org_id, email);
CREATE UNIQUE INDEX ux_users_platform_email ON users(email) WHERE org_id IS NULL;
CREATE INDEX ix_users_role ON users(org_id, role);

CREATE TABLE email_verifications (
    id         INTEGER PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at    TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE password_resets (
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
    sid_hash     TEXT NOT NULL UNIQUE,
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

-- Append-only, hash-chained audit trail. Triggers block UPDATE and DELETE.
CREATE TABLE audit_log (
    id          INTEGER PRIMARY KEY,
    org_id      INTEGER,
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
CREATE INDEX ix_audit_org ON audit_log(org_id, id);
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
    org_id     INTEGER,
    created_at TEXT NOT NULL,
    category   TEXT NOT NULL DEFAULT 'transactional',
    template   TEXT NOT NULL,
    recipient  TEXT NOT NULL,
    subject    TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'queued',
    error      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
);
CREATE INDEX ix_email_log_status ON email_log(status);
CREATE INDEX ix_email_org ON email_log(org_id, id);

-- Loan products and their percentage & financial rules.
CREATE TABLE loan_products (
    id                    INTEGER PRIMARY KEY,
    org_id                INTEGER NOT NULL,
    name                  TEXT NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    finance_type          TEXT NOT NULL DEFAULT 'loans',
    currencies            TEXT NOT NULL DEFAULT 'GBP',
    min_amount_minor      INTEGER NOT NULL CHECK (min_amount_minor > 0),
    max_amount_minor      INTEGER NOT NULL,
    min_term_months       INTEGER NOT NULL CHECK (min_term_months > 0),
    max_term_months       INTEGER NOT NULL,
    interest_method       TEXT NOT NULL DEFAULT 'amortising' CHECK (interest_method IN ('amortising', 'flat')),
    repayment_frequency   TEXT NOT NULL DEFAULT 'monthly' CHECK (repayment_frequency IN ('monthly', 'fortnightly', 'weekly')),
    grace_period_months   INTEGER NOT NULL DEFAULT 0,
    repayment_method      TEXT NOT NULL DEFAULT '',
    eligibility           TEXT NOT NULL DEFAULT '',
    required_docs         TEXT NOT NULL DEFAULT '',
    form_fields           TEXT NOT NULL DEFAULT '[]',
    allow_above_requested INTEGER NOT NULL DEFAULT 0,
    second_approval_threshold_minor INTEGER,
    active                INTEGER NOT NULL DEFAULT 1,
    created_at            TEXT NOT NULL,
    updated_at            TEXT NOT NULL,
    CHECK (max_amount_minor >= min_amount_minor),
    CHECK (max_term_months >= min_term_months),
    UNIQUE (org_id, name)
);

CREATE TABLE applications (
    id              INTEGER PRIMARY KEY,
    org_id          INTEGER NOT NULL,
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
    role_in_business TEXT NOT NULL DEFAULT '',
    consent         INTEGER NOT NULL DEFAULT 0,
    product_id      INTEGER REFERENCES loan_products(id),
    currency        TEXT NOT NULL DEFAULT 'GBP',
    extra_fields    TEXT NOT NULL DEFAULT '{}',
    submission_key  TEXT,
    assigned_to     INTEGER REFERENCES users(id),
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL,
    submitted_at    TEXT
);
CREATE INDEX ix_applications_user ON applications(user_id);
CREATE INDEX ix_applications_org ON applications(org_id, status);
CREATE INDEX ix_applications_updated ON applications(updated_at);
CREATE UNIQUE INDEX ux_applications_submission ON applications(user_id, submission_key) WHERE submission_key IS NOT NULL;

CREATE TABLE documents (
    id             INTEGER PRIMARY KEY,
    org_id         INTEGER NOT NULL,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    uploaded_by    INTEGER NOT NULL REFERENCES users(id),
    original_name  TEXT NOT NULL,
    stored_name    TEXT NOT NULL UNIQUE,
    size_bytes     INTEGER NOT NULL,
    doc_type       TEXT NOT NULL DEFAULT 'other',
    review_status  TEXT NOT NULL DEFAULT 'submitted',
    reviewed_by    INTEGER REFERENCES users(id),
    reviewed_at    TEXT,
    review_note    TEXT NOT NULL DEFAULT '',
    internal_note  TEXT NOT NULL DEFAULT '',
    customer_note  TEXT NOT NULL DEFAULT '',
    expires_on     TEXT,
    replaces_id    INTEGER REFERENCES documents(id),
    sha256         TEXT NOT NULL DEFAULT '',
    mime           TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL
);
CREATE INDEX ix_documents_application ON documents(application_id);
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

CREATE TABLE document_requests (
    id             INTEGER PRIMARY KEY,
    org_id         INTEGER NOT NULL,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    doc_type       TEXT NOT NULL,
    note           TEXT NOT NULL DEFAULT '',
    status         TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'fulfilled', 'cancelled')),
    requested_by   INTEGER NOT NULL REFERENCES users(id),
    created_at     TEXT NOT NULL,
    closed_at      TEXT
);
CREATE INDEX ix_document_requests_app ON document_requests(application_id);

CREATE TABLE notes (
    id                INTEGER PRIMARY KEY,
    application_id    INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    author_id         INTEGER NOT NULL REFERENCES users(id),
    body              TEXT NOT NULL,
    visible_to_client INTEGER NOT NULL DEFAULT 1,
    created_at        TEXT NOT NULL
);
CREATE INDEX ix_notes_application ON notes(application_id);

CREATE TABLE status_history (
    id             INTEGER PRIMARY KEY,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    status         TEXT NOT NULL,
    changed_by     INTEGER REFERENCES users(id),
    note           TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL
);
CREATE INDEX ix_history_application ON status_history(application_id);

CREATE TABLE financial_rules (
    id                    INTEGER PRIMARY KEY,
    org_id                INTEGER NOT NULL,
    kind                  TEXT NOT NULL CHECK (kind IN ('interest', 'processing_fee', 'admin_fee', 'document_fee',
                          'late_charge', 'commission', 'service_fee', 'custom')),
    name                  TEXT NOT NULL,
    description           TEXT NOT NULL DEFAULT '',
    method                TEXT NOT NULL DEFAULT 'percent' CHECK (method IN ('percent', 'fixed')),
    percent               TEXT,
    fixed_minor           INTEGER,
    basis                 TEXT NOT NULL CHECK (basis IN ('requested', 'approved', 'disbursed', 'outstanding', 'instalment')),
    product_ids           TEXT NOT NULL DEFAULT '',
    currencies            TEXT NOT NULL DEFAULT '',
    timing                TEXT NOT NULL CHECK (timing IN ('interest', 'deducted_from_disbursement', 'added_to_loan',
                          'with_first_instalment', 'on_overdue_instalment', 'not_charged_to_customer')),
    conditions            TEXT NOT NULL DEFAULT '',
    disclosure            TEXT NOT NULL DEFAULT '',
    arrangement_reference TEXT NOT NULL DEFAULT '',
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

-- An underwriting decision. 'pricing' freezes the rules and the full calculation used.
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
    pricing_hash TEXT NOT NULL,
    ip           TEXT NOT NULL DEFAULT '',
    accepted_at  TEXT NOT NULL
);

CREATE TABLE generated_documents (
    id             INTEGER PRIMARY KEY,
    org_id         INTEGER NOT NULL,
    application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    kind           TEXT NOT NULL,
    version        INTEGER NOT NULL,
    status         TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'issued', 'superseded')),
    title          TEXT NOT NULL,
    body           TEXT NOT NULL DEFAULT '',
    stored_name    TEXT NOT NULL UNIQUE,
    sha256         TEXT NOT NULL,
    decision_id    INTEGER REFERENCES approval_decisions(id),
    ref_id         INTEGER,
    generated_by   INTEGER NOT NULL REFERENCES users(id),
    generated_at   TEXT NOT NULL,
    issued_by      INTEGER REFERENCES users(id),
    issued_at      TEXT,
    UNIQUE (application_id, kind, version)
);

-- Money actually paid out: scheduled -> paid (pending) -> confirmed by a different person.
CREATE TABLE disbursements (
    id                  INTEGER PRIMARY KEY,
    org_id              INTEGER NOT NULL,
    application_id      INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    decision_id         INTEGER REFERENCES approval_decisions(id),
    currency            TEXT NOT NULL DEFAULT 'GBP',
    amount_pence        INTEGER NOT NULL CHECK (amount_pence > 0),
    fees_deducted_minor INTEGER NOT NULL DEFAULT 0,
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
    terms           TEXT NOT NULL,
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
CREATE UNIQUE INDEX ux_repayment_reference ON repayments(org_id, provider_reference);
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

CREATE TABLE enquiries (
    id         INTEGER PRIMARY KEY,
    org_id     INTEGER NOT NULL,
    name       TEXT NOT NULL,
    company    TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL DEFAULT '',
    topic      TEXT NOT NULL DEFAULT 'general',
    message    TEXT NOT NULL DEFAULT '',
    handled    INTEGER NOT NULL DEFAULT 0,
    handled_by INTEGER REFERENCES users(id),
    handled_at TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX ix_enquiries_org ON enquiries(org_id);

CREATE TABLE enquiry_replies (
    id         INTEGER PRIMARY KEY,
    enquiry_id INTEGER NOT NULL REFERENCES enquiries(id) ON DELETE CASCADE,
    author_id  INTEGER NOT NULL REFERENCES users(id),
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE content_versions (
    id           INTEGER PRIMARY KEY,
    org_id       INTEGER NOT NULL,
    data         TEXT NOT NULL,
    status       TEXT NOT NULL CHECK (status IN ('draft', 'published', 'superseded', 'discarded')),
    note         TEXT NOT NULL DEFAULT '',
    created_by   INTEGER REFERENCES users(id),
    created_at   TEXT NOT NULL,
    published_by INTEGER REFERENCES users(id),
    published_at TEXT
);
CREATE INDEX ix_content_org ON content_versions(org_id, status);

CREATE TABLE marketing_consent_events (
    id         INTEGER PRIMARY KEY,
    org_id     INTEGER NOT NULL,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    email      TEXT NOT NULL COLLATE NOCASE,
    action     TEXT NOT NULL CHECK (action IN ('opt_in', 'opt_out')),
    source     TEXT NOT NULL,
    wording    TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
CREATE INDEX ix_consent_email ON marketing_consent_events(email);

CREATE TABLE suppressions (
    org_id     INTEGER NOT NULL,
    email      TEXT NOT NULL COLLATE NOCASE,
    reason     TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (org_id, email)
);

CREATE TABLE campaigns (
    id            INTEGER PRIMARY KEY,
    org_id        INTEGER NOT NULL,
    name          TEXT NOT NULL,
    subject       TEXT NOT NULL,
    preheader     TEXT NOT NULL DEFAULT '',
    body          TEXT NOT NULL,
    segment       TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled')),
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

-- Platform owner: plans, subscriptions, invoices and platform-wide settings.
CREATE TABLE plans (
    id                     INTEGER PRIMARY KEY,
    code                   TEXT NOT NULL UNIQUE,
    name                   TEXT NOT NULL,
    description            TEXT NOT NULL DEFAULT '',
    price_minor            INTEGER NOT NULL CHECK (price_minor >= 0),
    currency               TEXT NOT NULL DEFAULT 'GBP',
    billing_period         TEXT NOT NULL DEFAULT 'month' CHECK (billing_period IN ('month', 'year')),
    trial_days             INTEGER NOT NULL DEFAULT 0,
    max_staff              INTEGER,
    max_applications_month INTEGER,
    max_products           INTEGER,
    platform_fee_percent   TEXT NOT NULL DEFAULT '0',
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
