<?php
/**
 * Money records: disbursements, repayment schedules, repayments and fees.
 *
 * Nothing here moves money. These are records of payments that actually happened, entered by authorised finance staff
 * from a bank statement or a payment provider's confirmation:
 * - a disbursement is scheduled, then marked as paid with the bank reference, then confirmed by a different person
 *   (also enforced by a database CHECK); only confirmed disbursements count as disbursed;
 * - a repayment can be recorded only once per payment reference, and is corrected by reversal, never by editing;
 * - fees come from the frozen pricing of the issued decision. No fee is ever a condition of an approval or payment.
 */
declare(strict_types=1);

const PAYABLE_WHEN = [
    'deducted_from_disbursement' => 'Taken from the first payment to you, so you receive less than the approved amount',
    'added_to_loan' => 'Added to the loan and repaid through your instalments',
    'with_first_instalment' => 'Payable with your first instalment',
    'on_overdue_instalment' => 'Only if an instalment is paid late',
];

/** When the agreement is signed, the decision's customer charges become fee records (pending). */
function create_fee_items(array $app, array $decision): void
{
    if (q1('SELECT 1 FROM fee_items WHERE decision_id = ?', [$decision['id']])) {
        return;
    }
    foreach ($decision['pricing_data']['fees'] as $f) {
        x('INSERT INTO fee_items (org_id, application_id, decision_id, rule_id, rule_version, kind, name, basis, basis_minor, percent, amount_minor,
           currency, timing, disclosure, payable_when, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [org_id(), $app['id'], $decision['id'], $f['rule_id'], $f['version'], $f['kind'], $f['name'], $f['basis'], $f['basis_amount'] ?? 0,
                $f['percent'], $f['amount'], $decision['currency'], $f['timing'], $f['disclosure'], PAYABLE_WHEN[$f['timing']] ?? '', now(), now()]);
    }
    audit_log('fees.created', 'application', $app['id'], ['decision_id' => $decision['id']]);
}

function disbursement_totals(int $appId): array
{
    $out = ['scheduled' => 0, 'pending' => 0, 'confirmed' => 0];
    foreach (q('SELECT status, COALESCE(SUM(amount_pence), 0) total FROM disbursements WHERE application_id = ? AND org_id = ? GROUP BY status',
        [$appId, org_id()]) as $r) {
        if (isset($out[$r['status']])) {
            $out[$r['status']] = (int) $r['total'];
        }
    }
    return $out;
}

/** The application status follows the confirmed money, never the other way round. */
function sync_disbursement_status(array $app, array $actor): void
{
    $d = current_decision((int) $app['id']);
    $t = disbursement_totals((int) $app['id']);
    if ($t['confirmed'] && $t['confirmed'] >= $d['approved_minor']) {
        $new = 'fully_disbursed';
    } elseif ($t['confirmed']) {
        $new = 'partially_disbursed';
    } elseif ($t['scheduled'] || $t['pending']) {
        $new = 'funding_scheduled';
    } else {
        $new = 'agreement_signed';
    }
    $fresh = q1('SELECT * FROM applications WHERE id = ?', [$app['id']]);
    if ($fresh['status'] !== $new) {
        set_status($fresh, $new, $actor, 'Updated from confirmed payment records', '', false);
    }
}

function active_schedule(int $appId): ?array
{
    return q1("SELECT * FROM repayment_schedules WHERE application_id = ? AND status = 'active'", [$appId]);
}

/** Allocate confirmed repayments, oldest first, to instalments in due order (fees, then interest, then principal). */
function loan_position(int $appId): ?array
{
    $s = active_schedule($appId);
    if ($s === null) {
        return null;
    }
    $inst = q('SELECT * FROM instalments WHERE schedule_id = ? ORDER BY number', [$s['id']]);
    $paidTotal = (int) qv("SELECT COALESCE(SUM(amount_minor), 0) FROM repayments WHERE schedule_id = ? AND status = 'confirmed'", [$s['id']]);
    $pot = $paidTotal;
    $today = today();
    $principalPaid = 0;
    $interestPaid = 0;
    foreach ($inst as &$i) {
        $take = [];
        foreach (['fees_minor', 'interest_minor', 'principal_minor'] as $part) {
            $take[$part] = min($pot, (int) $i[$part]);
            $pot -= $take[$part];
        }
        $i['paid_minor'] = array_sum($take);
        $i['unpaid_minor'] = (int) $i['amount_minor'] - $i['paid_minor'];
        $principalPaid += $take['principal_minor'];
        $interestPaid += $take['interest_minor'];
        $i['state'] = $i['unpaid_minor'] === 0 ? 'paid' : ($i['due_date'] < $today ? 'overdue' : ($i['paid_minor'] ? 'part_paid' : 'due'));
    }
    unset($i);
    $unpaid = array_values(array_filter($inst, fn($i) => $i['unpaid_minor'] > 0));
    $overdue = array_values(array_filter($inst, fn($i) => $i['state'] === 'overdue'));
    return ['schedule' => $s, 'instalments' => $inst, 'repaid' => $paidTotal, 'credit' => $pot,
        'outstanding_principal' => (int) $s['principal_minor'] - $principalPaid, 'interest_received' => $interestPaid,
        'outstanding_total' => array_sum(array_column($unpaid, 'unpaid_minor')), 'next_due' => $unpaid[0] ?? null,
        'overdue_count' => count($overdue), 'overdue_minor' => array_sum(array_column($overdue, 'unpaid_minor'))];
}

/** Fees repaid through instalments become 'paid' once the instalments that carry them are paid. */
function sync_financed_fees(int $appId, array $actor): void
{
    $pos = loan_position($appId);
    if ($pos === null) {
        return;
    }
    $firstPaid = $pos['instalments'] && $pos['instalments'][0]['state'] === 'paid';
    $allPaid = !array_filter($pos['instalments'], fn($i) => $i['state'] !== 'paid');
    foreach ([['with_first_instalment', $firstPaid], ['added_to_loan', $allPaid]] as [$timing, $done]) {
        x('UPDATE fee_items SET status = ?, paid_at = ?, payment_source = ?, updated_by = ?, updated_at = ? WHERE application_id = ? AND timing = ? AND status = ?',
            [$done ? 'paid' : 'pending', $done ? now() : null, $done ? 'repaid through instalments' : '', $actor['id'], now(), $appId, $timing,
                $done ? 'pending' : 'paid']);
    }
}
