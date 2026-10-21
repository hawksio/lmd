<?php
/**
 * Client portal: overview, applications, documents, financing, offer acceptance, messages, help and privacy.
 * Every query is scoped to the signed-in client, so a client can never reach another client's records: anything else
 * is a 404.
 */
declare(strict_types=1);

/** Guard for client pages: staff and platform owners are sent to their own areas. */
function need_client(): ?Response
{
    if ($r = need_login()) {
        return $r;
    }
    $u = current_user();
    if (is_platform_owner($u)) {
        return redirect('/platform/');
    }
    if (is_staff($u)) {
        return redirect('/admin/');
    }
    return null;
}

function own_application(int $appId): array
{
    $row = q1('SELECT * FROM applications WHERE id = ? AND user_id = ? AND org_id = ?', [$appId, current_user()['id'], org_id()]);
    if ($row === null) {
        abort(404);
    }
    return $row;
}

function onboarding(array $user, array $apps): array
{
    $active = null;
    foreach ($apps as $a) {
        if (!in_array($a['status'], FINAL_STATUSES, true)) {
            $active = $a;
            break;
        }
    }
    $docsDone = $active && $active['status'] !== 'draft'
        && !array_filter(doc_requirements($active), fn($r) => !in_array($r['status'], ['submitted', 'under_review', 'verified'], true));
    $steps = [
        ['Confirm your email address', (bool) $user['email_verified_at'], null],
        ['Complete your profile', $user['full_name'] && $user['phone'] && $user['address'], '/account/profile'],
        ['Start an application', (bool) $apps, '/account/applications/new'],
        ['Submit your application', (bool) array_filter($apps, fn($a) => $a['status'] !== 'draft'),
            ($active && $active['status'] === 'draft') ? "/account/applications/{$active['id']}/edit" : null],
        ['Upload the documents requested', (bool) $docsDone, $active ? "/account/applications/{$active['id']}#documents" : null],
    ];
    $done = count(array_filter($steps, fn($s) => $s[1]));
    return ['steps' => $steps, 'done' => $done, 'total' => count($steps), 'percent' => (int) round(100 * $done / count($steps))];
}

route('GET', '/account/', function () {
    if ($r = need_client()) {
        return $r;
    }
    $user = current_user();
    $apps = q('SELECT * FROM applications WHERE user_id = ? ORDER BY updated_at DESC', [$user['id']]);
    $funded = [];
    foreach ($apps as $a) {
        if ($a['status'] !== 'draft') {
            $s = financing_summary($a);
            if ($s['approved']) {
                $funded[] = [$a, $s];
            }
        }
    }
    return view('client/overview', [
        'apps' => $apps, 'funded' => $funded, 'progress' => onboarding($user, $apps),
        'notifications' => q('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5', [$user['id']]),
        'doc_count' => (int) qv('SELECT COUNT(*) FROM documents d JOIN applications a ON a.id = d.application_id WHERE a.user_id = ?', [$user['id']]),
    ]);
});
route('GET', '/account', fn() => redirect('/account/'));

route('GET', '/account/applications', function () {
    if ($r = need_client()) {
        return $r;
    }
    return view('client/applications', ['apps' => q('SELECT a.*, p.name AS product_name FROM applications a LEFT JOIN loan_products p ON p.id = a.product_id
        WHERE a.user_id = ? ORDER BY a.updated_at DESC', [current_user()['id']])]);
});

const SUBMITTED_MSG = "Application submitted. We've emailed you a confirmation. This is not a decision: we'll be in touch, and you can follow progress here.";

/** Save a draft or submit. Returns [values, errors, application id]. */
function save_application(?array $existing, bool $submitting): array
{
    $user = current_user();
    [$values, $errors] = read_application_form($submitting);
    if ($submitting && !$user['email_verified_at']) {
        $errors['form'] = "Please confirm your email address before submitting. We've sent you a link; you can request a new one above.";
    }
    if ($errors) {
        return [$values, $errors, null];
    }
    $stamp = now();
    try {
        $appId = transaction(function () use ($existing, $values, $submitting, $user, $stamp) {
            if ($existing === null) {
                $key = mb_substr(form('submission_key'), 0, 64) ?: null;
                $cols = array_keys($values);
                $appId = x('INSERT INTO applications (org_id, user_id, created_at, updated_at, submission_key, ' . implode(', ', $cols) . ') VALUES (?,?,?,?,?,'
                    . implode(',', array_fill(0, count($cols), '?')) . ')', [org_id(), $user['id'], $stamp, $stamp, $key, ...array_values($values)]);
            } else {
                $appId = (int) $existing['id'];
                x('UPDATE applications SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($values))) . ', updated_at = ? WHERE id = ? AND user_id = ?',
                    [...array_values($values), $stamp, $appId, $user['id']]);
            }
            if ($submitting) {
                x("UPDATE applications SET status = 'submitted', submitted_at = ? WHERE id = ? AND status = 'draft'", [$stamp, $appId]);
                record_status($appId, 'submitted', (int) $user['id']);
                audit_log('application.submitted', 'application', $appId);
            }
            return $appId;
        });
    } catch (PDOException $e) {
        if (!is_unique_violation($e)) {
            throw $e;
        }
        // The same form was sent twice (double click, refresh): use the application already created.
        return [$values, [], (int) qv('SELECT id FROM applications WHERE user_id = ? AND submission_key = ?', [$user['id'], form('submission_key')])];
    }
    if ($submitting) {
        $app = q1('SELECT * FROM applications WHERE id = ?', [$appId]);
        notify_staff_new_application($app, $user);
        send_application_received($app, $user);
        notify_staff('view_applications', 'New application ' . ref_str($appId), "/admin/applications/$appId");
    }
    return [$values, [], $appId];
}

function application_form_view(array $values, array $errors, ?array $app, string $key): Response
{
    $products = active_products();
    $pid = $values['product_id'] ?? arg_int('product');
    $selected = null;
    foreach ($products as $p) {
        if ((int) $p['id'] === (int) $pid) {
            $selected = $p;
        }
    }
    $extra = is_string($values['extra_fields'] ?? null) ? (json_decode($values['extra_fields'], true) ?: []) : [];
    return view('client/application_form', ['values' => $values, 'errors' => $errors, 'app' => $app, 'submission_key' => $key,
        'products' => $products, 'product' => $selected, 'fields' => product_fields($selected), 'extra' => $extra]);
}

route('GET|POST', '/account/applications/new', function () {
    if ($r = need_client()) {
        return $r;
    }
    $values = [];
    $errors = [];
    if (is_post() && in_array(form('action'), ['save', 'submit'], true)) {
        $submitting = form('action') === 'submit';
        [$values, $errors, $appId] = save_application(null, $submitting);
        if (!$errors) {
            flash($submitting ? SUBMITTED_MSG : 'Draft saved. You can come back to it any time.', 'success');
            return redirect("/account/applications/$appId");
        }
    } elseif (is_post()) {  // product chosen: show that product's questions
        $values = array_diff_key($_POST, ['csrf_token' => 1]);
        $values['product_id'] = form_int('product_id');
    }
    return application_form_view($values, $errors, null, form('submission_key') ?: token_urlsafe(16));
});

route('GET|POST', '/account/applications/{id}/edit', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    if ($app['status'] !== 'draft') {
        flash('This application has been submitted, so it can no longer be edited. Message the team instead.', 'info');
        return redirect("/account/applications/$id");
    }
    $values = $app;
    $errors = [];
    if (is_post() && in_array(form('action'), ['save', 'submit'], true)) {
        $submitting = form('action') === 'submit';
        [$values, $errors] = save_application($app, $submitting);
        if (!$errors) {
            flash($submitting ? SUBMITTED_MSG : 'Draft saved.', 'success');
            return redirect("/account/applications/$id");
        }
    } elseif (is_post()) {
        $values = array_merge($app, array_diff_key($_POST, ['csrf_token' => 1]));
        $values['product_id'] = form_int('product_id');
    }
    return application_form_view($values, $errors, $app, '');
});

route('GET', '/account/applications/{id}', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    [$docs, $notes, $history, $letters] = application_bundle($id, false);
    x('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND (link = ? OR link LIKE ?)',
        [now(), current_user()['id'], "/account/applications/$id", "/account/applications/$id#%"]);
    $receipts = [];
    foreach ($letters as $l) {
        if ($l['kind'] === 'receipt') {
            $receipts[(int) $l['ref_id']] = (int) $l['id'];
        }
    }
    return view('client/application', [
        'app' => $app, 'docs' => $docs, 'notes' => $notes, 'history' => $history, 'letters' => $letters,
        'summary' => financing_summary($app), 'position' => loan_position($id),
        'fees' => q("SELECT * FROM fee_items WHERE application_id = ? AND status != 'cancelled' ORDER BY id", [$id]),
        'repayments' => q('SELECT * FROM repayments WHERE application_id = ? ORDER BY received_on DESC, id DESC', [$id]),
        'receipts' => $receipts, 'requirements' => doc_requirements($app),
        'product' => $app['product_id'] ? q1('SELECT * FROM loan_products WHERE id = ?', [$app['product_id']]) : null,
        'can_withdraw' => in_array($app['status'], CLIENT_CAN_WITHDRAW, true), 'final' => in_array($app['status'], FINAL_STATUSES, true),
    ]);
});

route('POST', '/account/applications/{id}/documents', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    if (in_array($app['status'], FINAL_STATUSES, true)) {
        abort(400, 'This application is closed.');
    }
    $replaced = null;
    if ($rid = form_int('replaces')) {
        $replaced = q1('SELECT * FROM documents WHERE id = ? AND application_id = ?', [$rid, $id]);
        if ($replaced === null || !in_array($replaced['review_status'], DOC_REPLACEABLE, true)) {
            abort(400, 'Only rejected, expired or replacement-required documents can be replaced.');
        }
    }
    $type = $replaced ? $replaced['doc_type'] : (form('doc_type') ?: 'other');
    [$error, $docId] = save_upload($_FILES['document'] ?? null, $id, (int) current_user()['id'], $type, $replaced ? (int) $replaced['id'] : null);
    if ($error) {
        flash($error, 'error');
    } else {
        x('UPDATE applications SET updated_at = ? WHERE id = ?', [now(), $id]);
        if ($app['status'] !== 'draft') {
            on_customer_upload($app, $docId, $type, $replaced);
        }
        flash('Document uploaded. It will be reviewed; uploading a document is not a decision on your application.', 'success');
    }
    return redirect("/account/applications/$id#documents");
});

route('GET', '/account/documents', function () {
    if ($r = need_client()) {
        return $r;
    }
    $uid = current_user()['id'];
    return view('client/documents', [
        'docs' => q('SELECT d.*, a.company_name, a.id AS app_id FROM documents d JOIN applications a ON a.id = d.application_id WHERE a.user_id = ? ORDER BY d.created_at DESC', [$uid]),
        'letters' => q("SELECT g.*, a.company_name FROM generated_documents g JOIN applications a ON a.id = g.application_id WHERE a.user_id = ? AND g.status = 'issued'
                        ORDER BY g.issued_at DESC", [$uid]),
    ]);
});

route('GET', '/account/documents/{id}', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $doc = q1('SELECT d.* FROM documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ? AND a.user_id = ?', [$id, current_user()['id']]);
    return send_document($doc, arg('view') === '1');
});

route('GET', '/account/letters/{id}', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $doc = q1("SELECT g.* FROM generated_documents g JOIN applications a ON a.id = g.application_id WHERE g.id = ? AND a.user_id = ? AND g.status = 'issued'",
        [$id, current_user()['id']]);
    if ($doc === null) {
        abort(404);
    }
    audit_log('letter.download', 'generated_document', $id, ['application_id' => $doc['application_id']]);
    return private_file('generated', $doc['stored_name'], ref_str((int) $doc['application_id']) . "-{$doc['kind']}-v{$doc['version']}.pdf");
});

route('GET', '/account/messages', function () {
    if ($r = need_client()) {
        return $r;
    }
    $uid = current_user()['id'];
    $notes = q('SELECT n.*, a.company_name FROM notes n JOIN applications a ON a.id = n.application_id WHERE a.user_id = ? AND n.visible_to_client = 1
                ORDER BY n.created_at DESC, n.id DESC LIMIT 200', [$uid]);
    $notifications = q('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100', [$uid]);
    x('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $uid]);
    return view('client/messages', ['notes' => $notes, 'notifications' => $notifications]);
});

route('POST', '/account/applications/{id}/messages', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    $body = mb_substr(trim(form('body')), 0, 4000);
    if ($body !== '') {
        x('INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,1,?)', [$id, current_user()['id'], $body, now()]);
        x('UPDATE applications SET updated_at = ? WHERE id = ?', [now(), $id]);
        if ($app['status'] !== 'draft') {
            notify_staff_client_message($app, current_user());
            notify_staff('message_client', 'New message on ' . ref_str($id), "/admin/applications/$id#notes");
        }
        flash('Message sent to the team.', 'success');
    }
    return redirect("/account/applications/$id#messages");
});

route('POST', '/account/applications/{id}/withdraw', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    if (!in_array($app['status'], CLIENT_CAN_WITHDRAW, true)) {
        abort(400, "This application can't be withdrawn.");
    }
    transaction(function () use ($id) {
        x("UPDATE applications SET status = 'withdrawn', updated_at = ? WHERE id = ?", [now(), $id]);
        x("UPDATE approval_decisions SET status = 'withdrawn' WHERE application_id = ? AND status = 'proposed'", [$id]);
        record_status($id, 'withdrawn', (int) current_user()['id'], 'Withdrawn by the client');
        audit_log('application.withdrawn', 'application', $id);
    });
    notify_staff_withdrawn($app, current_user());
    flash('Application withdrawn.', 'success');
    return redirect("/account/applications/$id");
});

route('POST', '/account/applications/{id}/delete', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    if ($app['status'] !== 'draft') {
        abort(400, 'Only drafts can be deleted.');
    }
    if (q1('SELECT 1 FROM documents WHERE application_id = ?', [$id])) {
        abort(400, 'This draft has documents attached. Please contact us to remove it.');
    }
    x('DELETE FROM applications WHERE id = ?', [$id]);
    audit_log('application.draft_deleted', 'application', $id);
    flash('Draft deleted.', 'success');
    return redirect('/account/applications');
});

route('GET|POST', '/account/applications/{id}/offer', function (int $id) {
    if ($r = need_client()) {
        return $r;
    }
    $app = own_application($id);
    $d = current_decision($id);
    if ($d === null) {
        abort(404);
    }
    $accepted = q1('SELECT * FROM offer_acceptances WHERE decision_id = ?', [$d['id']]);
    if (is_post()) {
        if ($accepted) {
            flash('You have already accepted this offer.', 'info');
        } elseif (!in_array($app['status'], APPROVED, true)) {
            abort(400, 'This offer can no longer be accepted.');
        } elseif (!form('accept')) {
            flash('Tick the box to confirm you have read the figures and conditions.', 'error');
        } else {
            transaction(function () use ($d, $id) {
                x('INSERT INTO offer_acceptances (org_id, decision_id, user_id, pricing_hash, ip, accepted_at) VALUES (?,?,?,?,?,?)',
                    [org_id(), $d['id'], current_user()['id'], snapshot_hash($d['pricing_data']), client_ip(), now()]);
                audit_log('decision.offer_accepted', 'application', $id, ['decision_id' => $d['id']]);
            });
            notify_staff('issue_decisions', 'Offer accepted on ' . ref_str($id), "/admin/applications/$id#decision");
            flash("Thank you. We've recorded that you accept the offer. Your agreement will follow; nothing is binding until it is signed.", 'success');
        }
        return redirect("/account/applications/$id/offer");
    }
    return view('client/offer', ['app' => $app, 'd' => $d, 'q' => $d['pricing_data'], 'accepted' => $accepted, 'conditions' => decision_conditions((int) $d['id'])]);
});

route('GET|POST', '/account/profile', function () {
    if ($r = need_client()) {
        return $r;
    }
    $user = current_user();
    $errors = [];
    if (is_post()) {
        $name = trim(form('full_name'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['full_name'] = 'Enter your full name.';
            $user = array_merge($user, ['full_name' => $name]);
        } else {
            x('UPDATE users SET full_name = ?, phone = ?, address = ? WHERE id = ?', [$name, mb_substr(trim(form('phone')), 0, 40), mb_substr(trim(form('address')), 0, 500), $user['id']]);
            audit_log('account.details_changed', 'user', $user['id']);
            flash('Details updated.', 'success');
            return redirect('/account/profile');
        }
    }
    return view('client/profile', ['user' => $user, 'errors' => $errors]);
});

route('GET', '/account/privacy/export', function () {
    if ($r = need_client()) {
        return $r;
    }
    $user = current_user();
    $uid = $user['id'];
    $apps = q('SELECT * FROM applications WHERE user_id = ?', [$uid]);
    foreach ($apps as &$a) {
        unset($a['submission_key']);
    }
    $data = [
        'account' => array_intersect_key($user, array_flip(['email', 'full_name', 'phone', 'address', 'created_at', 'email_verified_at', 'marketing_opt_in_at', 'last_login_at'])),
        'applications' => $apps,
        'documents' => q('SELECT d.original_name, d.doc_type, d.review_status, d.customer_note, d.created_at, d.size_bytes FROM documents d
                          JOIN applications a ON a.id = d.application_id WHERE a.user_id = ?', [$uid]),
        'messages' => q('SELECT n.application_id, n.body, n.created_at FROM notes n JOIN applications a ON a.id = n.application_id
                         WHERE a.user_id = ? AND n.visible_to_client = 1', [$uid]),
        'repayments' => q('SELECT r.amount_minor, r.currency, r.received_on, r.provider_reference, r.status FROM repayments r
                           JOIN applications a ON a.id = r.application_id WHERE a.user_id = ?', [$uid]),
        'marketing_consent' => q('SELECT action, source, wording, created_at FROM marketing_consent_events WHERE user_id = ?', [$uid]),
    ];
    audit_log('privacy.data_exported', 'user', $uid);
    return new Response(json_out($data, JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json',
        'Content-Disposition' => 'attachment; filename=my-data.json', 'Cache-Control' => 'no-store']);
});

route('GET', '/account/help', function () {
    if ($r = need_client()) {
        return $r;
    }
    return view('client/help', ['ai_enabled' => ai_enabled()]);
});

route('POST', '/account/help/ask', function () {
    if (current_user() === null) {
        return json_response(['ok' => false, 'error' => 'Please sign in.'], 401);
    }
    $question = mb_substr(trim((string) (json_body()['question'] ?? '')), 0, 500);
    if (mb_strlen($question) < 3) {
        return json_response(['ok' => false, 'error' => 'Type a question first.'], 400);
    }
    $user = current_user();
    if (throttled('ai:' . $user['id'], 20, 3600)) {
        return json_response(['ok' => false, 'error' => "You've asked a lot of questions this hour. Please message the team instead."], 429);
    }
    $answer = ai_enabled() ? ai_ask(ai_guardrails() . ai_reference(), ai_redact($question)) : null;
    audit_log('ai.help_question', 'user', $user['id'], ['answered_by_ai' => (bool) $answer]);
    return $answer ? json_response(['ok' => true, 'source' => 'ai', 'answer' => $answer])
        : json_response(['ok' => true, 'source' => 'faq', 'matches' => faq_matches($question)]);
});
