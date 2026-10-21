# Production-readiness checklist

Nothing below has been verified on anyone's behalf. Items marked **Legal/compliance** need a qualified adviser.
`flask --app backend check-production` checks the technical items automatically, for the platform and for every
active organisation.

## A. The platform (you, as the software provider)
- [ ] Which legal entity sells and operates the software; SaaS/licence agreement drafted. **Legal**
- [ ] Data-processing agreement for organisations, sub-processor list (hosting, email, optional AI), breach
      procedure, records of processing. **Legal/compliance**
- [ ] Platform privacy notice and terms for staff users of the software. **Legal**
- [ ] Due-diligence process before onboarding an organisation: confirm its legal entity and that it is permitted to
      lend or broker where it operates; keep the evidence. The software does not verify licences.
- [ ] Platform limits (console → Platform limits) reviewed; they are technical ceilings, not legal limits.
- [ ] Plans and prices created in the console; manual billing process agreed (who issues invoices, how payments are
      reconciled). No payment provider is integrated.
- [ ] Independent penetration test, including cross-organisation access tests.
- [ ] Decide when to move from SQLite to managed PostgreSQL (see README).

## B. Each organisation (before its staff and customers use it)
- [ ] Organisation settings: legal name, branding, workflow (second-approval threshold, document verification before
      decisions, offer acceptance), notification recipients.
- [ ] Website content: registration number, registered office, contact details, complaints contact, data-protection
      registration, regulatory statement — only verified facts.
- [ ] **Regulatory permissions** checked on the relevant register for the exact entity before any regulatory statement
      is published. **Compliance**
- [ ] Loan products, eligibility, limits and required documents approved under its lending policy.
- [ ] Financial settings (rates, fees, late charges, commission arrangements, service fees) approved under its pricing
      policy and lawful where it lends; disclosures written. **Compliance**
- [ ] Calculated APR checked against the method required in its jurisdiction before it is shown to customers.
      **Compliance**
- [ ] Agreement template and letter wording approved by a solicitor, then confirmed in Organisation settings.
      **Legal**
- [ ] Signing process chosen (no e-signature is integrated).
- [ ] Privacy notice, retention periods, complaints procedure, financial-promotion review. **Legal/compliance**
- [ ] Marketing only after PECR/GDPR review (and `LMD_MARKETING_ENABLED=1` platform-wide).
- [ ] At least two active administrators (two-person rules) with two-step verification.

## C. LMD Finance Ltd (the default organisation) — still open from the website work
- [ ] Exact legal company name. The domain is `lmdglobalfinltd.com` while the brand is "LMD Finance Ltd": confirm which
      entity operates the site and lends or arranges finance.
- [ ] Company registration number, registered office, telephone, contact email, opening hours, ICO number,
      complaints contact.
- [ ] **Business model:** lender, broker, or both. **FCA status** and permissions (lending to sole traders and small
      partnerships can be regulated credit; the form accepts them). **Compliance**
- [ ] Marketing claims, team photos (real staff only, with consent), testimonials (none published).
- [ ] Legal pages (privacy, cookies, terms, complaints) reviewed and published; decide on self-hosting fonts.

## D. Technical go-live
- [ ] VPS ordered and hardened; `/etc/lmd/lmd.env` (mode 600) with `LMD_DEFAULT_ORG` **empty**.
- [ ] HTTPS: wildcard certificate for `*.<base domain>`, console host, and each custom domain; HSTS active.
- [ ] DNS records approved by you and then created (base, wildcard, console, custom domains).
- [ ] SPF, DKIM and DMARC for the sending domain; test emails reach inboxes.
- [ ] `LMD_REQUIRE_STAFF_MFA=1`; platform owner and all staff use two-step verification.
- [ ] `flask --app backend remove-dev-admin` (deletes all `instance/dev-*.txt` local logins); no test accounts or
      local test data on the server.
- [ ] Virus scanning for uploads (not yet implemented).
- [ ] Off-site, encrypted backups, and one restore actually tested.
- [ ] Uptime monitoring on `/api/health`; alerts reach someone.
- [ ] Staging tried end to end (`LMD_ENV=staging`, `LMD_MAIL_ALLOWED_DOMAINS`).
- [ ] Dependency scan (`pip-audit -r requirements.txt`) clean at release time.
- [ ] `flask --app backend check-production` reports 0 blocking items.

## Actions that need two different people
- Issuing an approval at or above the threshold (default: every approval) — not the person who proposed it.
- Confirming a disbursement — not the person who recorded it as paid.
- Reopening a declined application — administrators only.
- Publishing website content — support can draft; an administrator publishes.
