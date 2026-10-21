<?php
/**
 * Staff: disbursements, repayment schedules, repayments, late charges and fees. Records of real payments only; see
 * lib/finance.php for the rules.
 */
declare(strict_types=1);

function past_date(string $text, bool $allowFuture = false): ?string
{
    $d = valid_date($text);
    return ($d !== null && ($allowFuture || $d <= today())) ? $d : null;
}

route('POST', '/admin/applications/{id}/disbursements', function (int $id) {
    if ($r = need_staff('record_disbursement')) {
        return $r;
    }
    $app = staff_app($id);
    if (!in_array($app['status'], ['agreement_signed', 'funding_scheduled', 'partially_disbursed'], true)) {
        abort(400, 'Payments can be scheduled only after the agreement is signed.');
    }
    $d = current_decision($id);
    $amount = parse_money(form('amount'));
    $when = past_date(form('scheduled_for'), true);
    $t = disbursement_totals($id);
    $remaining = (int) $d['approved_minor'] - $t['scheduled'] - $t['pending'] - $t['confirmed'];
    if (!$amount || $when === null) {
        flash('Enter the amount and the planned payment date.', 'error');
        return back_to($id, '#payments');
    }
    if ($amount > $remaining) {
        flash('Only ' . money($remaining, $d['currency']) . ' of the approved amount is left to schedule.', 'error');
        return back_to($id, '#payments');
    }
    $first = !q1("SELECT 1 FROM disbursements WHERE application_id = ? AND status IN ('scheduled', 'pending', 'confirmed')", [$id]);
    $deducted = 0;
    if ($first) {
        $deducted = (int) qv("SELECT COALESCE(SUM(amount_minor), 0) FROM fee_items WHERE application_id = ? AND timing = 'deducted_from_disbursement' AND status = 'pending'", [$id]);
        if ($deducted >= $amount) {
            flash('The first payment must be larger than the fees deducted from it.', 'error');
            return back_to($id, '#payments');
        }
    }
    $did = x("INSERT INTO disbursements (org_id, application_id, decision_id, currency, amount_pence, fees_deducted_minor, scheduled_for, status, recorded_by, recorded_at, note)
              VALUES (?,?,?,?,?,?,?, 'scheduled', ?,?,?)", [org_id(), $id, $d['id'], $d['currency'], $amount, $deducted, $when, current_user()['id'], now(), mb_substr(trim(form('note')), 0, 500)]);
    audit_log('disbursement.scheduled', 'application', $id, ['disbursement_id' => $did, 'amount_minor' => $amount]);
    sync_disbursement_status($app, current_user());
    flash('Payment scheduled. Record it as paid once it has actually left the bank.', 'success');
    return back_to($id, '#payments');
});

function disbursement_row(int $id, array $statuses): array
{
    $d = q1('SELECT * FROM disbursements WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($d === null || !in_array($d['status'], $statuses, true)) {
        abort(404);
    }
    return $d;
}

route('POST', '/admin/disbursements/{id}/paid', function (int $id) {
    if ($r = need_staff('record_disbursement')) {
        return $r;
    }
    $d = disbursement_row($id, ['scheduled']);
    $paid = past_date(form('paid_on'));
    $method = form('method');
    $ref = mb_substr(trim(form('payment_reference')), 0, 120);
    if ($paid === null || !isset(PAYMENT_METHODS[$method]) || $ref === '') {
        flash('Enter the date it was actually paid (not in the future), the method and the bank reference.', 'error');
        return back_to((int) $d['application_id'], '#payments');
    }
    try {
        x("UPDATE disbursements SET status = 'pending', paid_on = ?, method = ?, payment_reference = ?, recorded_by = ? WHERE id = ? AND status = 'scheduled'",
            [$paid, $method, $ref, current_user()['id'], $id]);
    } catch (PDOException $e) {
        if (!is_unique_violation($e)) {
            throw $e;
        }
        flash('A payment with that reference has already been recorded.', 'error');
        return back_to((int) $d['application_id'], '#payments');
    }
    audit_log('disbursement.marked_paid', 'application', $d['application_id'], ['disbursement_id' => $id]);
    flash('Recorded as paid. A different authorised person must now confirm it against the bank record.', 'success');
    return back_to((int) $d['application_id'], '#payments');
});

route('POST', '/admin/disbursements/{id}/confirm', function (int $id) {
    if ($r = need_staff('confirm_disbursement')) {
        return $r;
    }
    $d = disbursement_row($id, ['pending']);
    $me = current_user();
    if ((int) $d['recorded_by'] === (int) $me['id']) {
        abort(403, 'A different authorised person must confirm a payment you recorded.');
    }
    $source = form('confirmation_source');
    if (!form('bank_checked') || !isset(CONFIRMATION_SOURCES[$source])) {
        flash('Confirm you have checked the payment, and say how.', 'error');
        return back_to((int) $d['application_id'], '#payments');
    }
    $app = staff_app((int) $d['application_id']);
    transaction(function () use ($d, $id, $me, $source) {
        x("UPDATE disbursements SET status = 'confirmed', confirmed_by = ?, confirmed_at = ?, confirmation_source = ? WHERE id = ?", [$me['id'], now(), $source, $id]);
        if ($d['fees_deducted_minor']) {
            x("UPDATE fee_items SET status = 'paid', paid_at = ?, payment_reference = ?, payment_source = 'deducted from payment', updated_by = ?, updated_at = ?
               WHERE application_id = ? AND timing = 'deducted_from_disbursement' AND status = 'pending'", [now(), $d['payment_reference'], $me['id'], now(), $d['application_id']]);
        }
        audit_log('disbursement.confirmed', 'application', $d['application_id'], ['disbursement_id' => $id, 'amount_minor' => (int) $d['amount_pence']]);
    });
    sync_disbursement_status($app, $me);
    generate_record('disbursement', $app, $me, $id);
    notify((int) $app['user_id'], 'Payment made for ' . ref_str((int) $app['id']), "/account/applications/{$app['id']}#financing");
    notify_disbursement($app, ['email' => $app['email'], 'full_name' => $app['full_name']], q1('SELECT * FROM disbursements WHERE id = ?', [$id]));
    flash('Payment confirmed. The customer has been notified.', 'success');
    return back_to((int) $app['id'], '#payments');
});

route('POST', '/admin/disbursements/{id}/{action}', function (int $id, string $action) {
    if ($r = need_staff('record_disbursement')) {
        return $r;
    }
    $allowed = ['cancel' => ['scheduled'], 'reject' => ['pending'], 'reverse' => ['confirmed']];
    if (!isset($allowed[$action])) {
        abort(404);
    }
    $d = disbursement_row($id, $allowed[$action]);
    $reason = mb_substr(trim(form('reason')), 0, 500);
    if ($action === 'reverse') {
        if (!form('confirm') || mb_strlen($reason) < 5) {
            flash('Give the reason for the reversal and tick the box.', 'error');
            return back_to((int) $d['application_id'], '#payments');
        }
        if (q1("SELECT 1 FROM repayments WHERE application_id = ? AND status = 'confirmed'", [$d['application_id']])) {
            abort(400, 'Repayments have been recorded against this loan. Reverse those first.');
        }
    }
    $app = staff_app((int) $d['application_id']);
    $me = current_user();
    transaction(function () use ($action, $d, $id, $me, $reason) {
        if ($action === 'reverse') {
            x("UPDATE disbursements SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ? WHERE id = ?", [$me['id'], now(), $reason, $id]);
            if ($d['fees_deducted_minor']) {
                x("UPDATE fee_items SET status = 'pending', paid_at = NULL, payment_reference = '', payment_source = '', updated_by = ?, updated_at = ?
                   WHERE application_id = ? AND timing = 'deducted_from_disbursement' AND status = 'paid'", [$me['id'], now(), $d['application_id']]);
            }
        } else {
            x('UPDATE disbursements SET status = ?, reversal_reason = ? WHERE id = ?', [$action === 'cancel' ? 'cancelled' : 'rejected', $reason, $id]);
        }
        audit_log("disbursement.$action", 'application', $d['application_id'], ['disbursement_id' => $id]);
    });
    sync_disbursement_status($app, $me);
    flash(['cancel' => 'Scheduled payment cancelled.', 'reject' => 'Payment record rejected.', 'reverse' => 'Payment reversed.'][$action], 'success');
    return back_to((int) $d['application_id'], '#payments');
});

route('POST', '/admin/applications/{id}/schedule', function (int $id) {
    if ($r = need_staff('manage_repayments')) {
        return $r;
    }
    $app = staff_app($id);
    $d = current_decision($id);
    $confirmed = disbursement_totals($id)['confirmed'];
    $firstDue = past_date(form('first_due_date'), true);
    if ($d === null || !$confirmed) {
        abort(400, 'A repayment schedule can be created after a payment to the customer has been confirmed.');
    }
    if ($firstDue === null) {
        flash('Choose the first due date.', 'error');
        return back_to($id, '#repayments');
    }
    $existing = active_schedule($id);
    if ($existing && q1("SELECT 1 FROM repayments WHERE schedule_id = ? AND status = 'confirmed'", [$existing['id']])) {
        abort(400, 'Repayments exist on the current schedule. Reverse them before replacing it.');
    }
    $q = $d['pricing_data'];
    $principal = $confirmed + (int) $q['fees_added_to_loan'];
    $rows = dated_schedule($q, $principal, $firstDue);
    $me = current_user();
    $sid = transaction(function () use ($existing, $id, $d, $principal, $firstDue, $q, $rows, $me) {
        if ($existing) {
            x("UPDATE repayment_schedules SET status = 'superseded' WHERE id = ?", [$existing['id']]);
        }
        $sid = x('INSERT INTO repayment_schedules (org_id, application_id, decision_id, currency, principal_minor, first_due_date, frequency, terms, created_by, created_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?)', [org_id(), $id, $d['id'], $d['currency'], $principal, $firstDue, $q['product']['repayment_frequency'],
            json_out(['annual_rate' => $q['annual_rate'], 'term_months' => $q['term_months'], 'product' => $q['product']]), $me['id'], now()]);
        foreach ($rows as $r) {
            x('INSERT INTO instalments (org_id, schedule_id, number, due_date, principal_minor, interest_minor, fees_minor, amount_minor) VALUES (?,?,?,?,?,?,?,?)',
                [org_id(), $sid, $r['number'], $r['due_date'], $r['principal'], $r['interest'], $r['fees'], $r['amount']]);
        }
        audit_log('schedule.created', 'application', $id, ['schedule_id' => $sid, 'principal_minor' => $principal, 'instalments' => count($rows)]);
        return $sid;
    });
    generate_record('schedule', $app, $me, $sid);
    notify((int) $app['user_id'], 'Your repayment schedule for ' . ref_str($id) . ' is ready', "/account/applications/$id#repayments");
    flash('Repayment schedule created: ' . count($rows) . ' instalments, based on the frozen terms of the issued decision.', 'success');
    return back_to($id, '#repayments');
});

route('POST', '/admin/applications/{id}/repayments', function (int $id) {
    if ($r = need_staff('manage_repayments')) {
        return $r;
    }
    $app = staff_app($id);
    $s = active_schedule($id);
    if ($s === null) {
        abort(400, 'Create the repayment schedule first.');
    }
    $amount = parse_money(form('amount'));
    $received = past_date(form('received_on'));
    $method = form('method');
    $ref = mb_substr(trim(form('provider_reference')), 0, 120);
    $source = form('confirmation_source');
    if (!$amount || $received === null || !isset(PAYMENT_METHODS[$method]) || $ref === '' || !isset(CONFIRMATION_SOURCES[$source])) {
        flash('Enter the amount, the date it was received (not in the future), the method, the payment reference and how it was confirmed.', 'error');
        return back_to($id, '#repayments');
    }
    $me = current_user();
    try {
        $rid = transaction(function () use ($id, $s, $amount, $received, $method, $ref, $source, $me) {
            $rid = x('INSERT INTO repayments (org_id, application_id, schedule_id, amount_minor, currency, received_on, method, provider_reference, confirmation_source,
                      note, recorded_by, recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [org_id(), $id, $s['id'], $amount, $s['currency'], $received, $method, $ref, $source, mb_substr(trim(form('note')), 0, 500), $me['id'], now()]);
            audit_log('repayment.recorded', 'application', $id, ['repayment_id' => $rid, 'amount_minor' => $amount]);
            return $rid;
        });
    } catch (PDOException $e) {
        if (!is_unique_violation($e)) {
            throw $e;
        }
        flash('That payment reference has already been recorded, so nothing was added (no duplicate).', 'info');
        return back_to($id, '#repayments');
    }
    sync_financed_fees($id, $me);
    generate_record('receipt', $app, $me, $rid);
    notify((int) $app['user_id'], 'Payment received: ' . money($amount, $s['currency']), "/account/applications/$id#repayments");
    flash('Repayment recorded and a receipt issued.', 'success');
    return back_to($id, '#repayments');
});

route('POST', '/admin/repayments/{id}/reverse', function (int $id) {
    if ($r = need_staff('manage_repayments')) {
        return $r;
    }
    $rep = q1("SELECT * FROM repayments WHERE id = ? AND org_id = ? AND status = 'confirmed'", [$id, org_id()]);
    if ($rep === null) {
        abort(404);
    }
    $reason = mb_substr(trim(form('reason')), 0, 500);
    if (!form('confirm') || mb_strlen($reason) < 5) {
        flash('Give the reason for the reversal and tick the box.', 'error');
        return back_to((int) $rep['application_id'], '#repayments');
    }
    x("UPDATE repayments SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ? WHERE id = ?", [current_user()['id'], now(), $reason, $id]);
    x("UPDATE generated_documents SET status = 'superseded' WHERE kind = 'receipt' AND ref_id = ? AND application_id = ?", [$id, $rep['application_id']]);
    audit_log('repayment.reversed', 'application', $rep['application_id'], ['repayment_id' => $id]);
    sync_financed_fees((int) $rep['application_id'], current_user());
    flash('Repayment reversed. Its receipt has been withdrawn.', 'success');
    return back_to((int) $rep['application_id'], '#repayments');
});

route('POST', '/admin/instalments/{id}/late-charge', function (int $id) {
    if ($r = need_staff('manage_fees')) {
        return $r;
    }
    $inst = q1("SELECT i.*, s.application_id FROM instalments i JOIN repayment_schedules s ON s.id = i.schedule_id WHERE i.id = ? AND i.org_id = ? AND s.status = 'active'", [$id, org_id()]);
    if ($inst === null) {
        abort(404);
    }
    $app = staff_app((int) $inst['application_id']);
    $row = null;
    foreach (loan_position((int) $app['id'])['instalments'] as $i) {
        if ((int) $i['id'] === $id) {
            $row = $i;
        }
    }
    if ($row['state'] !== 'overdue') {
        abort(400, 'Late charges apply only to overdue instalments.');
    }
    if (q1("SELECT 1 FROM fee_items WHERE instalment_id = ? AND kind = 'late_charge' AND status != 'cancelled'", [$id])) {
        abort(400, 'A late charge has already been applied to this instalment.');
    }
    $rules = array_values(array_filter(q("SELECT * FROM financial_rules WHERE org_id = ? AND kind = 'late_charge'", [org_id()]),
        fn($rule) => rule_applies($rule, $app['product_id'], $app['currency'], today())));
    if (!$rules) {
        abort(400, 'No late repayment charge is enabled for this product.');
    }
    $rule = $rules[0];
    $amount = rule_amount($rule, (int) $row['unpaid_minor']);
    $fid = x("INSERT INTO fee_items (org_id, application_id, instalment_id, rule_id, rule_version, kind, name, basis, basis_minor, percent, amount_minor, currency, timing,
              disclosure, payable_when, created_at, updated_at, updated_by) VALUES (?,?,?,?,?, 'late_charge', ?,?,?,?,?,?, 'on_overdue_instalment', ?,?,?,?,?)",
        [org_id(), $app['id'], $id, $rule['id'], $rule['version'], $rule['name'], $rule['basis'], $row['unpaid_minor'], $rule['percent'], $amount, $app['currency'],
            $rule['disclosure'], PAYABLE_WHEN['on_overdue_instalment'], now(), now(), current_user()['id']]);
    audit_log('fee.late_charge', 'application', $app['id'], ['fee_id' => $fid, 'amount_minor' => $amount, 'instalment' => (int) $row['number']]);
    notify((int) $app['user_id'], 'A late payment charge of ' . money($amount, $app['currency']) . ' was added', "/account/applications/{$app['id']}#fees");
    flash('Late charge of ' . money($amount, $app['currency']) . " recorded ({$rule['name']}).", 'success');
    return back_to((int) $app['id'], '#repayments');
});

route('POST', '/admin/fees/{id}/{action}', function (int $id, string $action) {
    if ($r = need_staff('manage_fees')) {
        return $r;
    }
    $f = q1('SELECT * FROM fee_items WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($f === null) {
        abort(404);
    }
    $allowed = ['paid' => 'pending', 'waive' => 'pending', 'cancel' => 'pending', 'refund' => 'paid'];
    if (!isset($allowed[$action]) || $f['status'] !== $allowed[$action]) {
        abort(400);
    }
    $reason = mb_substr(trim(form('reason')), 0, 500);
    $ref = mb_substr(trim(form('payment_reference')), 0, 120);
    $appId = (int) $f['application_id'];
    if ($action === 'paid') {
        if (in_array($f['timing'], ['deducted_from_disbursement', 'added_to_loan', 'with_first_instalment'], true)) {
            abort(400, 'This fee is collected through the payment or instalments and updates automatically.');
        }
        $source = form('confirmation_source');
        if ($ref === '' || !isset(CONFIRMATION_SOURCES[$source])) {
            flash('Enter the payment reference and how the payment was confirmed.', 'error');
            return back_to($appId, '#fees');
        }
        x("UPDATE fee_items SET status = 'paid', paid_at = ?, payment_reference = ?, payment_source = ?, updated_by = ?, updated_at = ? WHERE id = ?",
            [now(), $ref, $source, current_user()['id'], now(), $id]);
        generate_record('fee_receipt', staff_app($appId), current_user(), $id);
    } else {
        if (mb_strlen($reason) < 5) {
            flash('Record the reason.', 'error');
            return back_to($appId, '#fees');
        }
        if ($action === 'refund' && $ref === '') {
            flash('Enter the refund payment reference.', 'error');
            return back_to($appId, '#fees');
        }
        $new = ['waive' => 'waived', 'cancel' => 'cancelled', 'refund' => 'refunded'][$action];
        x("UPDATE fee_items SET status = ?, status_reason = ?, payment_reference = CASE WHEN ? != '' THEN ? ELSE payment_reference END, updated_by = ?, updated_at = ? WHERE id = ?",
            [$new, $reason, $ref, $ref, current_user()['id'], now(), $id]);
    }
    audit_log("fee.$action", 'application', $appId, ['fee_id' => $id]);
    flash('Fee record updated.', 'success');
    return back_to($appId, '#fees');
});

// ---------------------------------------------------------------- organisation-wide lists
route('GET', '/admin/disbursements', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $status = arg('status');
    return view('admin/disbursements', ['status' => $status, 'rows' => q('SELECT d.*, a.company_name, u.full_name FROM disbursements d JOIN applications a ON a.id = d.application_id
        JOIN users u ON u.id = a.user_id WHERE d.org_id = ? ' . ($status !== '' ? 'AND d.status = ? ' : '') . 'ORDER BY d.id DESC LIMIT 300',
        $status !== '' ? [org_id(), $status] : [org_id()])]);
});

route('GET', '/admin/repayments', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $rows = [];
    foreach (q("SELECT s.application_id, a.company_name, u.full_name, s.currency FROM repayment_schedules s JOIN applications a ON a.id = s.application_id
                JOIN users u ON u.id = a.user_id WHERE s.org_id = ? AND s.status = 'active' ORDER BY s.id DESC LIMIT 300", [org_id()]) as $l) {
        $rows[] = $l + ['pos' => loan_position((int) $l['application_id'])];
    }
    return view('admin/repayments', ['rows' => $rows, 'recent' => q('SELECT r.*, a.company_name FROM repayments r JOIN applications a ON a.id = r.application_id
        WHERE r.org_id = ? ORDER BY r.id DESC LIMIT 50', [org_id()])]);
});

route('GET', '/admin/fees', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $status = arg('status');
    return view('admin/fees', ['status' => $status, 'rows' => q('SELECT f.*, a.company_name FROM fee_items f JOIN applications a ON a.id = f.application_id WHERE f.org_id = ? '
        . ($status !== '' ? 'AND f.status = ? ' : '') . 'ORDER BY f.id DESC LIMIT 300', $status !== '' ? [org_id(), $status] : [org_id()])]);
});
