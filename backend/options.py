"""Fixed choices shared by forms, validation and templates."""

STATUSES = {
    "draft": "Draft",
    "submitted": "Submitted",
    "initial_review": "Initial review",
    "documents_required": "Documents required",
    "document_verification": "Document verification in progress",
    "underwriting": "Underwriting review",
    "info_requested": "Additional information requested",
    "partially_approved": "Partially approved",
    "fully_approved": "Fully approved",
    "conditionally_approved": "Conditional approval",
    "agreement_issued": "Agreement issued",
    "agreement_signed": "Agreement signed",
    "funding_scheduled": "Funding scheduled",
    "partially_disbursed": "Partially disbursed",
    "fully_disbursed": "Fully disbursed",
    "declined": "Declined",
    "withdrawn": "Withdrawn",
    "cancelled": "Cancelled",
}
APPROVED = {"partially_approved", "fully_approved", "conditionally_approved"}
REVIEWING = {"submitted", "initial_review", "documents_required", "document_verification", "underwriting", "info_requested"}
# The path a client sees on their timeline (declined / withdrawn / cancelled replace the tail).
PROGRESS = ["submitted", "initial_review", "underwriting", "approved", "agreement_issued", "agreement_signed",
            "funding_scheduled", "disbursed"]
PROGRESS_LABELS = {"approved": "Approval decision", "disbursed": "Funds disbursed"}
FINAL = {"fully_disbursed", "declined", "withdrawn", "cancelled"}
CLIENT_CAN_WITHDRAW = REVIEWING | APPROVED | {"agreement_issued"}

# Status changes staff can make by hand. Approval statuses are set only by issuing a decision, agreement_issued by
# issuing an agreement PDF, funding_scheduled by scheduling a payment, and the disbursed statuses only by confirmed
# payments: each of those steps has its own checks.
_BACK_TO_REVIEW = {"initial_review", "documents_required", "document_verification", "underwriting", "info_requested"}
MANUAL_TRANSITIONS = {
    "submitted": _BACK_TO_REVIEW | {"declined", "withdrawn", "cancelled"},
    "initial_review": (_BACK_TO_REVIEW - {"initial_review"}) | {"declined", "withdrawn", "cancelled"},
    "documents_required": (_BACK_TO_REVIEW - {"documents_required"}) | {"declined", "withdrawn", "cancelled"},
    "document_verification": (_BACK_TO_REVIEW - {"document_verification"}) | {"declined", "withdrawn", "cancelled"},
    "underwriting": (_BACK_TO_REVIEW - {"underwriting"}) | {"declined", "withdrawn", "cancelled"},
    "info_requested": (_BACK_TO_REVIEW - {"info_requested"}) | {"declined", "withdrawn", "cancelled"},
    "partially_approved": {"underwriting", "info_requested", "declined", "withdrawn", "cancelled"},
    "fully_approved": {"underwriting", "info_requested", "declined", "withdrawn", "cancelled"},
    "conditionally_approved": {"underwriting", "info_requested", "declined", "withdrawn", "cancelled"},
    "agreement_issued": {"agreement_signed", "underwriting", "withdrawn", "cancelled"},
    "agreement_signed": {"cancelled"},
    "funding_scheduled": {"cancelled"},
    "partially_disbursed": set(),
    "fully_disbursed": set(),
    "declined": {"underwriting"},
    "withdrawn": set(),
    "cancelled": set(),
}
# These decisions need a written reason, which goes into the audit trail and the status history.
NOTE_REQUIRED = {"declined", "withdrawn", "cancelled", "agreement_signed"}
# Reopening a declined application is an administrator decision.
ADMIN_ONLY_FROM = {"declined"}

FINANCE_TYPES = {
    "loans": "Business loan",
    "asset": "Asset finance",
    "cashflow": "Cash-flow finance",
    "general": "Not sure yet",
}
BUSINESS_TYPES = {
    "ltd": "Limited company",
    "llp": "LLP",
    "partnership": "Partnership",
    "sole_trader": "Sole trader",
}
YEARS_TRADING = {
    "under_1": "Less than 1 year",
    "1_2": "1 to 2 years",
    "2_5": "2 to 5 years",
    "5_plus": "More than 5 years",
}
ROLES_IN_BUSINESS = {
    "director": "Director",
    "owner": "Owner or partner",
    "finance": "Finance manager",
    "other": "Other authorised person",
}
TOPICS = {"general": "Not sure yet", "loans": "Business loan", "asset": "Asset finance", "cashflow": "Cash-flow finance"}
CURRENCIES = {"GBP": "£", "EUR": "€", "USD": "$"}

DOC_TYPES = {
    "identity": "Passport or national identity card",
    "address": "Proof of address",
    "bank_statements": "Bank statements",
    "accounts": "Income or business financial records",
    "registration": "Business registration documents",
    "tax": "Tax documents",
    "business_plan": "Business plan or supporting evidence",
    "asset_quote": "Supplier quote or invoice",
    "other": "Other",
}
DOC_STATUSES = {
    "not_submitted": "Not submitted",
    "submitted": "Submitted",
    "under_review": "Under review",
    "verified": "Verified",
    "rejected": "Rejected",
    "replacement_required": "Replacement required",
    "expired": "Expired",
}
DOC_REVIEW = DOC_STATUSES  # backwards-compatible name used by templates
DOC_REPLACEABLE = {"rejected", "replacement_required", "expired"}
ALLOWED_UPLOADS = {".pdf", ".jpg", ".jpeg", ".png"}

LETTER_KINDS = {
    "acknowledgement": "Application acknowledgement",
    "info_request": "Request for documents or information",
    "approval": "Approval letter",
    "decline": "Decline letter",
    "agreement": "Loan agreement",
    "schedule": "Repayment schedule",
    "fee_statement": "Fee statement",
    "receipt": "Payment receipt",
    "fee_receipt": "Fee payment receipt",
    "disbursement": "Disbursement confirmation",
}

RULE_KINDS = {
    "interest": "Loan interest rate (annual %)",
    "processing_fee": "Loan processing fee",
    "admin_fee": "Administration fee",
    "document_fee": "Document-processing fee",
    "late_charge": "Late repayment charge",
    "commission": "Commission",
    "service_fee": "Platform or service fee",
    "custom": "Other custom percentage",
}
RULE_BASES = {
    "requested": "Requested amount",
    "approved": "Approved amount",
    "disbursed": "Disbursed amount",
    "outstanding": "Outstanding balance",
    "instalment": "Overdue instalment",
}
RULE_TIMINGS = {
    # There is deliberately no "pay before approval" or "pay to release funds" option: no fee can be a condition
    # of an approval or a payment out, which is the pattern used by advance-fee loan fraud.
    "interest": "Interest, charged through the repayment schedule",
    "deducted_from_disbursement": "Deducted from the amount paid out",
    "added_to_loan": "Added to the loan and repaid through instalments",
    "with_first_instalment": "Payable with the first instalment",
    "on_overdue_instalment": "Charged only on an overdue instalment",
    "not_charged_to_customer": "Not charged to the customer (disclosed only)",
}
FEE_STATUSES = {"pending": "Pending", "paid": "Paid", "waived": "Waived", "refunded": "Refunded", "cancelled": "Cancelled"}
FREQUENCIES = {"monthly": "Monthly", "fortnightly": "Fortnightly", "weekly": "Weekly"}
INTEREST_METHODS = {"amortising": "Reducing balance (amortising)", "flat": "Flat rate"}
PAYMENT_METHODS = {"faster_payment": "Faster Payment", "sepa": "SEPA transfer", "chaps": "CHAPS", "bank_transfer": "Bank transfer",
                   "direct_debit": "Direct debit", "card": "Card (via payment provider)", "other": "Other"}
CONFIRMATION_SOURCES = {"bank_statement": "Checked on bank statement", "payment_provider": "Payment provider confirmation",
                        "other": "Other documented evidence"}
