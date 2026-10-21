<?php
/**
 * Staff: document verification queue and review, document requests, and underwriting decisions (propose, issue by a
 * second person, withdraw, conditions). Verifying a document never approves a loan.
 */
declare(strict_types=1);

const REVIEW_ACTIONS = ['under_review', 'verified', 'rejected', 'replacement_required', 'expired'];

route('GET', '/admin/documents/', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $status = arg('status', 'awaiting');
    $type = arg('type');
    $search = mb_substr(trim(arg('q')), 0, 100);
    $page = max(1, arg_int('page', 1));
    $where = ['d.org_id = ?', "a.status != 'draft'"];
    $args = [org_id()];
    if ($status === 'awaiting') {
        $where[] = "d.review_status IN ('submitted', 'under_review')";
    } elseif (isset(DOC_STATUSES[$status])) {
        $where[] = 'd.review_status = ?';
        $args[] = $status;
    }
    if (isset(DOC_TYPES[$type])) {
        $where[] = 'd.doc_type = ?';
        $args[] = $type;
    }
    if ($search !== '') {
        $where[] = "(u.full_name LIKE ? OR u.email LIKE ? OR a.company_name LIKE ? OR printf(? || '-%06d', a.id) LIKE ?)";
        array_push($args, "%$search%", "%$search%", "%$search%", ref_prefix(), "%$search%");
    }
    $base = 'FROM documents d JOIN applications a ON a.id = d.application_id JOIN users u ON u.id = a.user_id JOIN users up ON up.id = d.uploaded_by WHERE '
        . implode(' AND ', $where) . " AND up.role = 'client'";
    $total = (int) qv("SELECT COUNT(*) $base", $args);
    return view('admin/documents', ['rows' => q("SELECT d.*, a.company_name, u.full_name $base ORDER BY d.created_at ASC, d.id ASC LIMIT 25 OFFSET ?",
        [...$args, ($page - 1) * 25]), 'status' => $status, 'type' => $type, 'search' => $search, 'page' => $page,
        'pages' => max(1, (int) ceil($total / 25)), 'total' => $total]);
});
route('GET', '/admin/documents', fn() => redirect('/admin/documents/'));

route('GET|POST', '/admin/documents/{id}/review', function (int $id) {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $doc = q1("SELECT d.*, a.user_id, a.id AS app_id, a.status AS app_status FROM documents d JOIN applications a ON a.id = d.application_id
               WHERE d.id = ? AND d.org_id = ? AND a.status != 'draft'", [$id, org_id()]);
    if ($doc === null) {
        abort(404);
    }
    if (is_post()) {
        require_perm('review_documents');
        $status = form('review_status');
        $internal = mb_substr(trim(form('internal_note')), 0, 1000);
        $customer = mb_substr(trim(form('customer_note')), 0, 1000);
        $expires = valid_date(form('expires_on'));
        if (!in_array($status, REVIEW_ACTIONS, true)) {
            abort(400);
        }
        if (in_array($status, ['rejected', 'replacement_required'], true) && $customer === '') {
            flash("Explain to the customer why the document can't be accepted.", 'error');
            return redirect("/admin/documents/$id/review");
        }
        record_review($doc, $status, current_user(), $internal, $customer, $expires);
        if (in_array($status, ['rejected', 'replacement_required', 'expired'], true)) {
            notify((int) $doc['user_id'], 'Please upload a replacement for ' . (DOC_TYPES[$doc['doc_type']] ?? 'a document'), "/account/applications/{$doc['app_id']}#documents");
        } elseif ($status === 'verified') {
            notify((int) $doc['user_id'], (DOC_TYPES[$doc['doc_type']] ?? 'Document') . ' verified', "/account/applications/{$doc['app_id']}#documents");
        }
        flash('Document marked as ' . strtolower(DOC_STATUSES[$status]) . '. This does not change the application decision.', 'success');
        $next = form('next');
        return redirect(str_starts_with($next, '/admin/') ? $next : '/admin/documents/');
    }
    return view('admin/document_review', [
        'doc' => $doc,
        'history' => q('SELECT r.*, u.full_name FROM document_reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.document_id = ? ORDER BY r.id DESC', [$id]),
        'versions' => q('SELECT * FROM documents WHERE application_id = ? AND doc_type = ? ORDER BY id DESC', [$doc['app_id'], $doc['doc_type']]),
        'applicant' => q1('SELECT full_name, email FROM users WHERE id = ?', [$doc['user_id']]),
        'app' => q1('SELECT * FROM applications WHERE id = ?', [$doc['app_id']]),
    ]);
});

route('POST', '/admin/documents/request/{id}', function (int $id) {
    if ($r = need_staff('review_documents')) {
        return $r;
    }
    $app = q1("SELECT * FROM applications WHERE id = ? AND org_id = ? AND status != 'draft'", [$id, org_id()]);
    if ($app === null) {
        abort(404);
    }
    $type = form('doc_type');
    $note = mb_substr(trim(form('note')), 0, 1000);
    if (!isset(DOC_TYPES[$type]) || $note === '') {
        flash('Choose the type of document and explain what is needed.', 'error');
    } else {
        $rid = x('INSERT INTO document_requests (org_id, application_id, doc_type, note, requested_by, created_at) VALUES (?,?,?,?,?,?)',
            [org_id(), $id, $type, $note, current_user()['id'], now()]);
        audit_log('document.requested', 'application', $id, ['request_id' => $rid, 'doc_type' => $type]);
        notify((int) $app['user_id'], 'We need: ' . DOC_TYPES[$type], "/account/applications/$id#documents");
        notify_client_message($app, q1('SELECT * FROM users WHERE id = ?', [$app['user_id']]));
        flash('Request sent to the customer.', 'success');
    }
    return back_to($id, '#documents');
});

route('POST', '/admin/documents/request/{id}/cancel', function (int $id) {
    if ($r = need_staff('review_documents')) {
        return $r;
    }
    $req = q1('SELECT * FROM document_requests WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($req === null) {
        abort(404);
    }
    x("UPDATE document_requests SET status = 'cancelled', closed_at = ? WHERE id = ?", [now(), $id]);
    audit_log('document.request_cancelled', 'application', $req['application_id'], ['request_id' => $id]);
    return back_to((int) $req['application_id'], '#documents');
});

// ---------------------------------------------------------------- decisions
route('POST', '/admin/applications/{id}/decision', function (int $id) {
    if ($r = need_staff('propose_decisions')) {
        return $r;
    }
    $app = staff_app($id);
    if (!in_array($app['status'], DECIDABLE, true)) {
        abort(400, 'No approval decision can be made while the application is ' . STATUSES[$app['status']] . '.');
    }
    if (org_settings()['require_verified_documents_for_underwriting'] && doc_outstanding($app)) {
        flash('Required documents are not all verified yet. Verify them, or change this in Organisation settings.', 'error');
        return back_to($id, '#decision');
    }
    [$values, $quote, $errors] = read_decision($app);
    if ($errors || form('action') === 'preview') {
        return render_workspace($app, $values, $quote, $errors);
    }
    $previous = current_decision($id);
    $me = current_user();
    $needsSecond = $values['approved_minor'] >= approval_threshold($values['product']) ? 1 : 0;
    transaction(function () use ($id, $app, $values, $quote, $previous, $me, $needsSecond) {
        x("UPDATE approval_decisions SET status = 'superseded' WHERE application_id = ? AND org_id = ? AND status = 'proposed'", [$id, org_id()]);
        $revision = (int) qv('SELECT MAX(revision) FROM approval_decisions WHERE application_id = ?', [$id]) + 1;
        $did = x("INSERT INTO approval_decisions (org_id, application_id, revision, decision_type, status, currency, requested_minor, approved_minor,
                  previous_approved_minor, term_months, product_id, reason, internal_note, pricing, needs_second_approval, proposed_by, proposed_at)
                  VALUES (?,?,?,?, 'proposed', ?,?,?,?,?,?,?,?,?,?,?,?)",
            [org_id(), $id, $revision, $values['decision_type'], $app['currency'], $values['requested_minor'], $values['approved_minor'],
                $previous['approved_minor'] ?? null, $values['term_months'], $values['product_id'], $values['reason'], $values['internal_note'],
                json_out($quote), $needsSecond, $me['id'], now()]);
        foreach ($values['conditions'] as $text) {
            x('INSERT INTO decision_conditions (org_id, decision_id, text) VALUES (?,?,?)', [org_id(), $did, $text]);
        }
        if (in_array($app['status'], REVIEWING, true) && $app['status'] !== 'underwriting') {
            x("UPDATE applications SET status = 'underwriting', updated_at = ? WHERE id = ?", [now(), $id]);
            record_status($id, 'underwriting', (int) $me['id'], 'Decision proposed');
        }
        audit_log('decision.proposed', 'application', $id, ['decision_id' => $did, 'revision' => $revision, 'approved_minor' => $values['approved_minor'],
            'previous_minor' => $previous['approved_minor'] ?? null, 'decision_type' => $values['decision_type']]);
    });
    flash('Decision proposed. It is not visible to the customer until it is issued' . ($needsSecond ? ' by a different authorised person.' : '.'), 'success');
    return back_to($id, '#decision');
});

route('POST', '/admin/decisions/{id}/issue', function (int $id) {
    if ($r = need_staff('issue_decisions')) {
        return $r;
    }
    $d = q1("SELECT * FROM approval_decisions WHERE id = ? AND org_id = ? AND status = 'proposed'", [$id, org_id()]);
    if ($d === null) {
        abort(404);
    }
    $app = staff_app((int) $d['application_id']);
    $me = current_user();
    if ($d['needs_second_approval'] && (int) $d['proposed_by'] === (int) $me['id']) {
        abort(403, 'This approval needs a second authorised person: someone other than the proposer must issue it.');
    }
    if (!form('confirm')) {
        flash('Tick the box to confirm you have reviewed the decision and its figures.', 'error');
        return back_to((int) $app['id'], '#decision');
    }
    $hasConditions = (bool) decision_conditions($id);
    $new = ($d['decision_type'] === 'conditional' || $hasConditions) ? 'conditionally_approved' : ($d['decision_type'] === 'full' ? 'fully_approved' : 'partially_approved');
    transaction(function () use ($d, $id, $me, $new) {
        x("UPDATE approval_decisions SET status = 'superseded' WHERE application_id = ? AND org_id = ? AND status = 'issued'", [$d['application_id'], org_id()]);
        x("UPDATE approval_decisions SET status = 'issued', issued_by = ?, issued_at = ? WHERE id = ?", [$me['id'], now(), $id]);
        // A revised decision replaces any agreement already prepared on the old one.
        x("UPDATE generated_documents SET status = 'superseded' WHERE application_id = ? AND kind IN ('agreement', 'approval') AND status IN ('issued', 'draft')",
            [$d['application_id']]);
        audit_log('decision.issued', 'application', $d['application_id'], ['decision_id' => $id, 'status' => $new, 'approved_minor' => (int) $d['approved_minor'],
            'second_person' => (bool) $d['needs_second_approval']]);
    });
    $message = 'Decision: ' . STATUSES[$new] . '. Approved ' . money($d['approved_minor'], $d['currency']) . ' of ' . money($d['requested_minor'], $d['currency'])
        . ' requested. This is not a payment: nothing has been paid out.';
    set_status($app, $new, $me, "Decision revision {$d['revision']} issued", $message);
    flash('Decision issued: ' . STATUSES[$new] . '. The customer has been notified.', 'success');
    return back_to((int) $app['id'], '#decision');
});

route('POST', '/admin/decisions/{id}/withdraw', function (int $id) {
    if ($r = need_staff('propose_decisions')) {
        return $r;
    }
    $d = q1("SELECT * FROM approval_decisions WHERE id = ? AND org_id = ? AND status = 'proposed'", [$id, org_id()]);
    if ($d === null) {
        abort(404);
    }
    x("UPDATE approval_decisions SET status = 'withdrawn' WHERE id = ?", [$id]);
    audit_log('decision.withdrawn', 'application', $d['application_id'], ['decision_id' => $id]);
    flash('Proposed decision withdrawn.', 'success');
    return back_to((int) $d['application_id'], '#decision');
});

route('POST', '/admin/conditions/{id}', function (int $id) {
    if ($r = need_staff('propose_decisions')) {
        return $r;
    }
    $c = q1('SELECT c.*, d.application_id FROM decision_conditions c JOIN approval_decisions d ON d.id = c.decision_id WHERE c.id = ? AND c.org_id = ?', [$id, org_id()]);
    $status = form('status');
    if ($c === null || !in_array($status, ['outstanding', 'satisfied', 'waived'], true)) {
        abort(404);
    }
    x('UPDATE decision_conditions SET status = ?, updated_by = ?, updated_at = ? WHERE id = ?', [$status, current_user()['id'], now(), $id]);
    audit_log('decision.condition', 'application', $c['application_id'], ['condition_id' => $id, 'status' => $status]);
    return back_to((int) $c['application_id'], '#decision');
});
