<?php
/** Fixed choices shared by forms, validation and templates (same values as the Flask edition). */
declare(strict_types=1);

const STATUSES = [
    'draft' => 'Draft',
    'submitted' => 'Submitted',
    'initial_review' => 'Initial review',
    'documents_required' => 'Documents required',
    'document_verification' => 'Document verification in progress',
    'underwriting' => 'Underwriting review',
    'info_requested' => 'Additional information requested',
    'partially_approved' => 'Partially approved',
    'fully_approved' => 'Fully approved',
    'conditionally_approved' => 'Conditional approval',
    'agreement_issued' => 'Agreement issued',
    'agreement_signed' => 'Agreement signed',
    'funding_scheduled' => 'Funding scheduled',
    'partially_disbursed' => 'Partially disbursed',
    'fully_disbursed' => 'Fully disbursed',
    'declined' => 'Declined',
    'withdrawn' => 'Withdrawn',
    'cancelled' => 'Cancelled',
];
const APPROVED = ['partially_approved', 'fully_approved', 'conditionally_approved'];
const REVIEWING = ['submitted', 'initial_review', 'documents_required', 'document_verification', 'underwriting', 'info_requested'];
const PROGRESS = ['submitted', 'initial_review', 'underwriting', 'approved', 'agreement_issued', 'agreement_signed',
    'funding_scheduled', 'disbursed'];
const PROGRESS_LABELS = ['approved' => 'Approval decision', 'disbursed' => 'Funds disbursed'];
const FINAL_STATUSES = ['fully_disbursed', 'declined', 'withdrawn', 'cancelled'];
const CLIENT_CAN_WITHDRAW = [...REVIEWING, ...APPROVED, 'agreement_issued'];

// Status changes staff can make by hand. Approval statuses come only from issuing a decision, agreement_issued from
// issuing an agreement, funding_scheduled from scheduling a payment, and the disbursed statuses only from confirmed
// payments: each of those steps has its own checks.
const BACK_TO_REVIEW = ['initial_review', 'documents_required', 'document_verification', 'underwriting', 'info_requested'];
const CLOSE_OUT = ['declined', 'withdrawn', 'cancelled'];

function manual_transitions(string $from): array
{
    $back = BACK_TO_REVIEW;
    $map = [
        'submitted' => [...$back, ...CLOSE_OUT],
        'partially_approved' => ['underwriting', 'info_requested', ...CLOSE_OUT],
        'fully_approved' => ['underwriting', 'info_requested', ...CLOSE_OUT],
        'conditionally_approved' => ['underwriting', 'info_requested', ...CLOSE_OUT],
        'agreement_issued' => ['agreement_signed', 'underwriting', 'withdrawn', 'cancelled'],
        'agreement_signed' => ['cancelled'],
        'funding_scheduled' => ['cancelled'],
        'declined' => ['underwriting'],
    ];
    if (in_array($from, $back, true)) {
        $map[$from] = [...array_values(array_diff($back, [$from])), ...CLOSE_OUT];
    }
    $out = $map[$from] ?? [];
    // Keep the order of STATUSES.
    return array_values(array_filter(array_keys(STATUSES), fn($s) => in_array($s, $out, true)));
}

const NOTE_REQUIRED = ['declined', 'withdrawn', 'cancelled', 'agreement_signed'];
const ADMIN_ONLY_FROM = ['declined'];

const FINANCE_TYPES = ['loans' => 'Business loan', 'asset' => 'Asset finance', 'cashflow' => 'Cash-flow finance', 'general' => 'Not sure yet'];
const BUSINESS_TYPES = ['ltd' => 'Limited company', 'llp' => 'LLP', 'partnership' => 'Partnership', 'sole_trader' => 'Sole trader'];
const YEARS_TRADING = ['under_1' => 'Less than 1 year', '1_2' => '1 to 2 years', '2_5' => '2 to 5 years', '5_plus' => 'More than 5 years'];
const ROLES_IN_BUSINESS = ['director' => 'Director', 'owner' => 'Owner or partner', 'finance' => 'Finance manager', 'other' => 'Other authorised person'];
const TOPICS = ['general' => 'Not sure yet', 'loans' => 'Business loan', 'asset' => 'Asset finance', 'cashflow' => 'Cash-flow finance'];
const CURRENCIES = ['GBP' => '£', 'EUR' => '€', 'USD' => '$'];

const DOC_TYPES = [
    'identity' => 'Passport or national identity card',
    'address' => 'Proof of address',
    'bank_statements' => 'Bank statements',
    'accounts' => 'Income or business financial records',
    'registration' => 'Business registration documents',
    'tax' => 'Tax documents',
    'business_plan' => 'Business plan or supporting evidence',
    'asset_quote' => 'Supplier quote or invoice',
    'other' => 'Other',
];
const DOC_STATUSES = [
    'not_submitted' => 'Not submitted',
    'submitted' => 'Submitted',
    'under_review' => 'Under review',
    'verified' => 'Verified',
    'rejected' => 'Rejected',
    'replacement_required' => 'Replacement required',
    'expired' => 'Expired',
];
const DOC_REPLACEABLE = ['rejected', 'replacement_required', 'expired'];
const ALLOWED_UPLOADS = ['pdf', 'jpg', 'jpeg', 'png'];

const LETTER_KINDS = [
    'acknowledgement' => 'Application acknowledgement',
    'info_request' => 'Request for documents or information',
    'approval' => 'Approval letter',
    'decline' => 'Decline letter',
    'agreement' => 'Loan agreement',
    'schedule' => 'Repayment schedule',
    'fee_statement' => 'Fee statement',
    'receipt' => 'Payment receipt',
    'fee_receipt' => 'Fee payment receipt',
    'disbursement' => 'Disbursement confirmation',
];
const STAFF_WRITTEN = ['acknowledgement', 'info_request', 'approval', 'decline', 'agreement', 'fee_statement'];

const RULE_KINDS = [
    'interest' => 'Loan interest rate (annual %)',
    'processing_fee' => 'Loan processing fee',
    'admin_fee' => 'Administration fee',
    'document_fee' => 'Document-processing fee',
    'late_charge' => 'Late repayment charge',
    'commission' => 'Commission',
    'service_fee' => 'Platform or service fee',
    'custom' => 'Other custom percentage',
];
const RULE_BASES = [
    'requested' => 'Requested amount',
    'approved' => 'Approved amount',
    'disbursed' => 'Disbursed amount',
    'outstanding' => 'Outstanding balance',
    'instalment' => 'Overdue instalment',
];
// There is deliberately no "pay before approval" or "pay to release funds" option: no fee can be a condition of an
// approval or of a payment out, which is the pattern used by advance-fee loan fraud.
const RULE_TIMINGS = [
    'interest' => 'Interest, charged through the repayment schedule',
    'deducted_from_disbursement' => 'Deducted from the amount paid out',
    'added_to_loan' => 'Added to the loan and repaid through instalments',
    'with_first_instalment' => 'Payable with the first instalment',
    'on_overdue_instalment' => 'Charged only on an overdue instalment',
    'not_charged_to_customer' => 'Not charged to the customer (disclosed only)',
];
const FEE_STATUSES = ['pending' => 'Pending', 'paid' => 'Paid', 'waived' => 'Waived', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled'];
const FREQUENCIES = ['monthly' => 'Monthly', 'fortnightly' => 'Fortnightly', 'weekly' => 'Weekly'];
const INTEREST_METHODS = ['amortising' => 'Reducing balance (amortising)', 'flat' => 'Flat rate'];
const PAYMENT_METHODS = ['faster_payment' => 'Faster Payment', 'sepa' => 'SEPA transfer', 'chaps' => 'CHAPS', 'bank_transfer' => 'Bank transfer',
    'direct_debit' => 'Direct debit', 'card' => 'Card (via payment provider)', 'other' => 'Other'];
const CONFIRMATION_SOURCES = ['bank_statement' => 'Checked on bank statement', 'payment_provider' => 'Payment provider confirmation',
    'other' => 'Other documented evidence'];
const SUB_STATUSES = ['trial' => 'Trial', 'active' => 'Active', 'past_due' => 'Past due', 'cancelled' => 'Cancelled', 'suspended' => 'Suspended'];

const STAFF_ROLES = [
    'admin' => 'Organisation administrator',
    'underwriter' => 'Underwriter',
    'document_reviewer' => 'Document reviewer',
    'support' => 'Customer support',
    'finance' => 'Finance officer',
    'auditor' => 'Read-only auditor',
];
const ALL_STAFF = ['admin', 'underwriter', 'document_reviewer', 'support', 'finance', 'auditor'];
const PERMISSIONS = [
    'view_applications' => ALL_STAFF,
    'view_clients' => ALL_STAFF,
    'view_enquiries' => ALL_STAFF,
    'view_documents' => ['admin', 'underwriter', 'document_reviewer', 'auditor'],
    'review_documents' => ['admin', 'underwriter', 'document_reviewer'],
    'upload_documents' => ['admin', 'underwriter', 'document_reviewer'],
    'message_client' => ['admin', 'underwriter', 'document_reviewer', 'support', 'finance'],
    'internal_note' => ['admin', 'underwriter', 'document_reviewer', 'support', 'finance'],
    'change_status' => ['admin', 'underwriter'],
    'propose_decisions' => ['admin', 'underwriter'],
    'issue_decisions' => ['admin', 'underwriter'],
    'generate_documents' => ['admin', 'underwriter', 'finance'],
    'issue_documents' => ['admin', 'underwriter', 'finance'],
    'record_disbursement' => ['admin', 'finance'],
    'confirm_disbursement' => ['admin', 'finance'],
    'manage_repayments' => ['admin', 'finance'],
    'manage_fees' => ['admin', 'finance'],
    'handle_enquiries' => ['admin', 'support'],
    'edit_content' => ['admin', 'support'],
    'publish_content' => ['admin'],
    'manage_marketing' => ['admin'],
    'manage_staff' => ['admin'],
    'manage_products' => ['admin'],
    'manage_financial_settings' => ['admin'],
    'manage_org_settings' => ['admin'],
    'view_reports' => ['admin', 'finance', 'auditor'],
    'export_data' => ['admin', 'finance', 'auditor'],
    'view_audit' => ['admin', 'auditor'],
    'use_ai_drafts' => ['admin', 'underwriter', 'support'],
];
