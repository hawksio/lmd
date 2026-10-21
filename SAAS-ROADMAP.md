# Roadmap: selling and licensing the platform

A practical sequence from "working software" to "product organisations pay for". Nothing here has been done yet
unless the README says so.

## 1. Before the first paying organisation (must do)

| Area | What | Why |
|---|---|---|
| Legal entity | Decide which company sells the software (it may not be LMD Finance Ltd) and get a software licence / SaaS agreement drafted | Your contract defines liability, uptime, data handling and fees |
| Data protection | You are a **processor** for each organisation's customer data: data-processing agreement (DPA), sub-processor list (hosting, email, optional AI), records of processing, breach procedure | UK GDPR/EU GDPR Article 28 |
| Security | Independent penetration test including **cross-organisation** tests; fix findings; virus scanning for uploads; secrets in a vault; encrypted off-site backups; restore drill | Lenders will ask for evidence |
| Database | Move to managed PostgreSQL with point-in-time recovery; consider row-level security as a second isolation layer | Concurrency and recoverability once several organisations write at once |
| Infrastructure | Production + staging, monitoring and alerting, uptime target you can actually meet, status page | Service commitments |
| Customer due diligence | Check each organisation is entitled to lend or broker where it operates before onboarding; keep the evidence | The platform must not be used by unauthorised lenders |
| Support | Support email, response targets, onboarding guide for administrators | Expected by buyers |

## 2. Product work that makes it sellable (next 3-6 months)

1. **Payment provider integration** (e.g. Stripe, GoCardless) for subscription billing, and optionally for repayments
   (direct debit) with webhook reconciliation. Replace manual mode only when webhooks are verified.
2. **E-signature integration** (e.g. DocuSign, Adobe Sign, Yousign) so "Agreement signed" is set from the provider's
   signed callback, with the signed PDF stored.
3. **Per-organisation agreement and letter templates**: let each organisation upload its solicitor-approved wording
   (with placeholders filled from frozen terms) instead of the generic structure.
4. **Self-service sign-up and trial** for organisations, with checks before going live.
5. **Background job queue** (e.g. RQ/Celery with Redis) for email, PDFs and campaigns.
6. **Configurable approval workflows**: named stages, required roles per stage, multi-level approval limits.
7. **Credit and identity checks**: integrations with credit reference agencies and KYC/KYB providers, under each
   organisation's own contracts, with results stored as documents and audit events.
8. **Accounting exports** (Xero, QuickBooks, Sage) for disbursements, repayments, fees and interest.
9. **Collections tools**: arrears reminders, payment plans, forbearance records.
10. **Customer support access**: time-limited, organisation-approved, fully audited access for platform staff.

## 3. Pricing and packaging (your decision)

The console supports any plan names, prices, billing periods, limits (staff, applications per month, products) and an
optional usage fee on confirmed disbursements. Common models: tiered subscription (Starter / Professional /
Enterprise), per-seat, or subscription plus a small usage fee. Keep platform revenue separate from the organisations'
lending revenue (the software already does in its reports and invoices).

## 4. Sales readiness

- A demo organisation with clearly fictional data, on its own host, reset nightly.
- Security pack: architecture summary, data-flow diagram, pen-test summary, DPA, sub-processors, backup/restore
  evidence, incident process.
- Documentation for administrators (setting up products and financial rules, roles, workflow) and for customers.
- References only from real, consenting customers. No invented logos, testimonials or certifications.

## 5. Compliance you can't outsource to the software

Each organisation remains responsible for its regulatory permissions, product terms, rates and fees, disclosures,
financial promotions, affordability and creditworthiness assessments, complaints handling and record keeping. The
software records and calculates; it does not make any of those lawful. Say so plainly in your contracts and sales
material.
