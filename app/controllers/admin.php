<?php
/**
 * Organisation staff area. Every route checks the staff member's permission on the server, and every query is limited
 * to the signed-in staff member's organisation (org_id()).
 */
declare(strict_types=1);

const PAGE_SIZE = 25;

// Applications needing attention: new and untouched for a day, waiting on a reply, unreviewed documents, decisions
// waiting to be issued, or payments waiting for confirmation.
const ATTENTION_SQL = "(
  (a.status = 'submitted' AND a.submitted_at < datetime('now', '-1 day'))
  OR EXISTS (SELECT 1 FROM documents d JOIN users du ON du.id = d.uploaded_by
             WHERE d.application_id = a.id AND d.review_status IN ('submitted', 'under_review') AND du.role = 'client')
  OR (SELECT u2.role FROM notes n2 JOIN users u2 ON u2.id = n2.author_id
      WHERE n2.application_id = a.id ORDER BY n2.created_at DESC, n2.id DESC LIMIT 1) = 'client'
  OR EXISTS (SELECT 1 FROM approval_decisions ad WHERE ad.application_id = a.id AND ad.status = 'proposed')
  OR EXISTS (SELECT 1 FROM disbursements ds WHERE ds.application_id = a.id AND ds.status = 'pending')
)";

/** [from, to] from ?from=&to= (inclusive). Default: the last 90 days. */
function date_range(): array
{
    return [valid_date(arg('from')) ?? gmdate('Y-m-d', time() - 90 * 86400), valid_date(arg('to')) ?? today()];
}

function csv_response(string $filename, array $header, array $rows): Response
{
    require_perm('export_data');
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $header);
    foreach ($rows as $r) {
        // Neutralise spreadsheet formulas in exported text (CSV injection).
        fputcsv($fh, array_map(fn($v) => (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'$v" : $v, $r));
    }
    rewind($fh);
    audit_log('data.exported', 'export', null, ['file' => $filename, 'rows' => count($rows)]);
    return new Response(stream_get_contents($fh), 200, ['Content-Type' => 'text/csv; charset=utf-8',
        'Content-Disposition' => "attachment; filename=$filename", 'Cache-Control' => 'no-store']);
}

function back_to(int $appId, string $anchor = ''): Response
{
    return redirect("/admin/applications/$appId$anchor");
}

route('GET', '/admin', fn() => redirect('/admin/'));

route('GET', '/admin/', function () {
    if ($r = need_staff()) {
        return $r;
    }
    $o = org_id();
    $counts = [];
    foreach (q('SELECT status, COUNT(*) n FROM applications WHERE org_id = ? GROUP BY status', [$o]) as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    return view('admin/dashboard', [
        'counts' => $counts,
        'attention' => q('SELECT a.*, u.full_name FROM applications a JOIN users u ON u.id = a.user_id WHERE a.org_id = ? AND a.status NOT IN
            (\'draft\', \'declined\', \'withdrawn\', \'cancelled\', \'fully_disbursed\') AND ' . ATTENTION_SQL . ' ORDER BY a.updated_at ASC LIMIT 10', [$o]),
        'recent' => q("SELECT a.*, u.full_name FROM applications a JOIN users u ON u.id = a.user_id WHERE a.org_id = ? AND a.status != 'draft' ORDER BY a.updated_at DESC LIMIT 8", [$o]),
        'stats' => [
            'open_enquiries' => (int) qv('SELECT COUNT(*) FROM enquiries WHERE org_id = ? AND handled = 0', [$o]),
            'clients' => (int) qv("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'client'", [$o]),
            'failed_mail' => (int) qv("SELECT COUNT(*) FROM email_log WHERE org_id = ? AND status = 'failed'", [$o]),
            'docs_waiting' => (int) qv("SELECT COUNT(*) FROM documents WHERE org_id = ? AND review_status IN ('submitted', 'under_review')", [$o]),
            'decisions_waiting' => (int) qv("SELECT COUNT(*) FROM approval_decisions WHERE org_id = ? AND status = 'proposed'", [$o]),
            'payments_waiting' => (int) qv("SELECT COUNT(*) FROM disbursements WHERE org_id = ? AND status = 'pending'", [$o]),
        ],
        'usage' => usage_warning($o),
    ]);
});

const APP_SORTS = ['updated' => 'a.updated_at', 'submitted' => 'a.submitted_at', 'amount' => 'a.amount', 'company' => 'a.company_name COLLATE NOCASE'];

route('GET', '/admin/applications', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $status = arg('status');
    $search = mb_substr(trim(arg('q')), 0, 100);
    $attention = arg('attention') === '1';
    $where = ["a.org_id = ?", "a.status != 'draft'"];
    $args = [org_id()];
    if (isset(STATUSES[$status]) && $status !== 'draft') {
        $where[] = 'a.status = ?';
        $args[] = $status;
    }
    if ($search !== '') {
        $like = "%$search%";
        $where[] = "(a.company_name LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR printf(? || '-%06d', a.id) LIKE ?)";
        array_push($args, $like, $like, $like, ref_prefix(), $like);
    }
    if (arg('from') || arg('to')) {
        [$start, $end] = date_range();
        $where[] = 'date(a.submitted_at) BETWEEN ? AND ?';
        array_push($args, $start, $end);
    }
    if ($product = arg_int('product')) {
        $where[] = 'a.product_id = ?';
        $args[] = $product;
    }
    if ($attention) {
        $where[] = ATTENTION_SQL;
    }
    $clause = implode(' AND ', $where);
    $sort = isset(APP_SORTS[arg('sort')]) ? arg('sort') : 'updated';
    $dir = arg('dir') === 'asc' ? 'ASC' : 'DESC';
    $order = APP_SORTS[$sort];
    if (arg('export') === 'csv') {
        $rows = q("SELECT a.*, u.full_name, u.email FROM applications a JOIN users u ON u.id = a.user_id WHERE $clause ORDER BY $order $dir", $args);
        return csv_response('applications.csv', ['reference', 'business', 'applicant', 'email', 'status', 'currency', 'requested', 'term_months', 'submitted_at', 'updated_at'],
            array_map(fn($r) => [ref_str((int) $r['id']), $r['company_name'], $r['full_name'], $r['email'], STATUSES[$r['status']], $r['currency'], $r['amount'],
                $r['term_months'], $r['submitted_at'], $r['updated_at']], $rows));
    }
    $page = max(1, arg_int('page', 1));
    $total = (int) qv("SELECT COUNT(*) FROM applications a JOIN users u ON u.id = a.user_id WHERE $clause", $args);
    $apps = q("SELECT a.*, u.full_name, u.email, " . ATTENTION_SQL . " AS needs_attention FROM applications a JOIN users u ON u.id = a.user_id
               WHERE $clause ORDER BY $order $dir, a.id DESC LIMIT ? OFFSET ?", [...$args, PAGE_SIZE, ($page - 1) * PAGE_SIZE]);
    return view('admin/applications', ['apps' => $apps, 'status' => $status, 'search' => $search, 'sort' => $sort, 'dir' => strtolower($dir),
        'attention' => $attention, 'page' => $page, 'pages' => max(1, (int) ceil($total / PAGE_SIZE)), 'total' => $total,
        'products' => q('SELECT id, name FROM loan_products WHERE org_id = ? ORDER BY name', [org_id()])]);
});

route('GET', '/admin/approvals', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $o = org_id();
    return view('admin/approvals', [
        'proposed' => q("SELECT d.*, a.company_name, u.full_name AS proposer FROM approval_decisions d JOIN applications a ON a.id = d.application_id
            JOIN users u ON u.id = d.proposed_by WHERE d.org_id = ? AND d.status = 'proposed' ORDER BY d.proposed_at", [$o]),
        'underwriting' => q("SELECT a.*, u.full_name FROM applications a JOIN users u ON u.id = a.user_id WHERE a.org_id = ?
            AND a.status IN ('underwriting', 'document_verification', 'initial_review') ORDER BY a.submitted_at", [$o]),
        'accepted' => q("SELECT a.*, u.full_name, oa.accepted_at FROM offer_acceptances oa JOIN approval_decisions d ON d.id = oa.decision_id
            JOIN applications a ON a.id = d.application_id JOIN users u ON u.id = a.user_id WHERE a.org_id = ? AND d.status = 'issued'
            AND a.status IN ('partially_approved', 'fully_approved', 'conditionally_approved') ORDER BY oa.accepted_at", [$o]),
        'me' => (int) current_user()['id'],
    ]);
});

/** The application workspace, optionally with a decision form being redisplayed. */
function render_workspace(array $app, array $decisionForm = [], ?array $preview = null, array $decisionErrors = []): Response
{
    $id = (int) $app['id'];
    [$docs, $notes, $history, $generated] = application_bundle($id, true);
    $product = $app['product_id'] ? q1('SELECT * FROM loan_products WHERE id = ? AND org_id = ?', [$app['product_id'], org_id()]) : null;
    $allowed = manual_transitions($app['status']);
    if (in_array($app['status'], ADMIN_ONLY_FROM, true) && current_user()['role'] !== 'admin') {
        $allowed = [];
    }
    $decision = current_decision($id);
    $extra = json_decode($app['extra_fields'] ?: '{}', true) ?: [];
    return view('admin/application', [
        'app' => $app, 'docs' => $docs, 'notes' => $notes, 'history' => $history, 'generated' => $generated, 'product' => $product,
        'products' => q('SELECT * FROM loan_products WHERE org_id = ? AND active = 1 ORDER BY name', [org_id()]),
        'payments' => q('SELECT d.*, r.full_name AS recorded_name, c.full_name AS confirmed_name FROM disbursements d JOIN users r ON r.id = d.recorded_by
            LEFT JOIN users c ON c.id = d.confirmed_by WHERE d.application_id = ? AND d.org_id = ? ORDER BY d.id DESC', [$id, org_id()]),
        'staff_members' => q("SELECT id, full_name FROM users WHERE org_id = ? AND role IN ('admin', 'underwriter', 'document_reviewer') AND is_active = 1 ORDER BY full_name", [org_id()]),
        'allowed' => $allowed,
        'trail' => can('view_audit') ? q('SELECT l.*, u.full_name FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id WHERE l.org_id = ? AND
            ((l.target_type = \'application\' AND l.target_id = ?) OR l.detail LIKE ? OR l.detail LIKE ?) ORDER BY l.id DESC LIMIT 40',
            [org_id(), $id, '%"application_id":' . $id . ',%', '%"application_id":' . $id . '}%']) : [],
        'decision' => $decision, 'decisions' => all_decisions($id),
        'accepted' => $decision ? q1('SELECT * FROM offer_acceptances WHERE decision_id = ?', [$decision['id']]) : null,
        'conditions' => $decision ? decision_conditions((int) $decision['id']) : [],
        'requirements' => doc_requirements($app), 'outstanding' => doc_outstanding($app),
        'doc_requests' => q('SELECT r.*, u.full_name FROM document_requests r JOIN users u ON u.id = r.requested_by WHERE r.application_id = ? AND r.org_id = ? ORDER BY r.id DESC', [$id, org_id()]),
        'summary' => financing_summary($app), 'position' => loan_position($id),
        'fees' => q('SELECT * FROM fee_items WHERE application_id = ? AND org_id = ? ORDER BY id', [$id, org_id()]),
        'repayments' => q('SELECT r.*, u.full_name FROM repayments r JOIN users u ON u.id = r.recorded_by WHERE r.application_id = ? AND r.org_id = ? ORDER BY r.id DESC', [$id, org_id()]),
        'decision_form' => $decisionForm, 'preview' => $preview, 'decision_errors' => $decisionErrors, 'extra' => $extra,
        'extra_fields' => product_fields($product), 'settings' => org_settings(), 'me' => (int) current_user()['id'],
        'can_decide' => in_array($app['status'], DECIDABLE, true), 'threshold' => approval_threshold($product),
    ]);
}

route('GET', '/admin/applications/{id}', function (int $id) {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    return render_workspace(staff_app($id));
});

route('POST', '/admin/applications/{id}/status', function (int $id) {
    if ($r = need_staff('change_status')) {
        return $r;
    }
    $app = staff_app($id);
    $new = form('status');
    if (!isset(STATUSES[$new]) || $new === 'draft') {
        abort(400, 'Unknown status.');
    }
    $message = mb_substr(trim(form('message')), 0, 4000);
    $reason = mb_substr(trim(form('reason')), 0, 2000);
    $me = current_user();
    if ($new === $app['status']) {
        if ($message !== '') {
            x('INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,1,?)', [$id, $me['id'], $message, now()]);
            notify((int) $app['user_id'], 'New message about ' . ref_str($id), "/account/applications/$id");
        }
        flash('Status unchanged.' . ($message !== '' ? ' Message added to the application.' : ''), 'success');
        return back_to($id);
    }
    if (!in_array($new, manual_transitions($app['status']), true)) {
        abort(400, "An application can't move from " . STATUSES[$app['status']] . ' to ' . STATUSES[$new] . ' by hand.');
    }
    if (in_array($app['status'], ADMIN_ONLY_FROM, true) && $me['role'] !== 'admin') {
        abort(403);
    }
    if ((in_array($new, NOTE_REQUIRED, true) || in_array($app['status'], ADMIN_ONLY_FROM, true)) && $reason === '') {
        flash('Record the reason for this decision before setting ' . STATUSES[$new] . '.', 'error');
        return back_to($id);
    }
    if ($new === 'agreement_signed') {
        if (!q1("SELECT 1 FROM generated_documents WHERE application_id = ? AND kind = 'agreement' AND status = 'issued'", [$id])) {
            abort(400, 'No agreement has been issued for this application.');
        }
        transaction(fn() => create_fee_items($app, current_decision($id)));
    }
    if (in_array($new, ['underwriting', 'info_requested'], true) && in_array($app['status'], [...APPROVED, 'agreement_issued'], true)) {
        // Returning an approved application for review withdraws the issued decision; the history keeps it.
        x("UPDATE approval_decisions SET status = 'superseded' WHERE application_id = ? AND status = 'issued'", [$id]);
        x("UPDATE generated_documents SET status = 'superseded' WHERE application_id = ? AND kind IN ('agreement', 'approval') AND status IN ('issued', 'draft')", [$id]);
    }
    set_status($app, $new, $me, $reason, $message);
    flash('Status set to ' . STATUSES[$new] . ' and the client has been emailed.' . ($message !== '' ? ' Message added.' : ''), 'success');
    return back_to($id);
});

route('POST', '/admin/applications/{id}/assign', function (int $id) {
    if ($r = need_staff('change_status')) {
        return $r;
    }
    staff_app($id);
    $staffId = form_int('assigned_to');
    if ($staffId && !q1("SELECT 1 FROM users WHERE id = ? AND org_id = ? AND role IN ('admin', 'underwriter', 'document_reviewer')", [$staffId, org_id()])) {
        abort(400);
    }
    x('UPDATE applications SET assigned_to = ? WHERE id = ? AND org_id = ?', [$staffId ?: null, $id, org_id()]);
    if ($staffId) {
        notify($staffId, 'Application ' . ref_str($id) . ' has been assigned to you', "/admin/applications/$id");
    }
    audit_log('application.assigned', 'application', $id, ['assigned_to' => $staffId]);
    flash('Assignment saved.', 'success');
    return back_to($id);
});

route('POST', '/admin/applications/{id}/notes', function (int $id) {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $app = staff_app($id);
    $body = mb_substr(trim(form('body')), 0, 4000);
    $visible = form('visible_to_client') ? 1 : 0;
    require_perm($visible ? 'message_client' : 'internal_note');
    if ($body !== '') {
        x('INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,?,?)', [$id, current_user()['id'], $body, $visible, now()]);
        x('UPDATE applications SET updated_at = ? WHERE id = ?', [now(), $id]);
        if ($visible) {
            notify((int) $app['user_id'], 'New message about ' . ref_str($id), "/account/applications/$id");
            notify_client_message($app, ['email' => $app['email'], 'full_name' => $app['full_name']]);
        }
        audit_log($visible ? 'application.message' : 'application.internal_note', 'application', $id);
        flash($visible ? 'Message sent to the client.' : 'Internal note added.', 'success');
    }
    return back_to($id, '#notes');
});

route('POST', '/admin/applications/{id}/documents', function (int $id) {
    if ($r = need_staff('upload_documents')) {
        return $r;
    }
    staff_app($id);
    [$error] = save_upload($_FILES['document'] ?? null, $id, (int) current_user()['id'], form('doc_type') ?: 'other');
    flash($error ?: 'Document uploaded.', $error ? 'error' : 'success');
    return back_to($id, '#documents');
});

route('GET', '/admin/document-files/{id}', function (int $id) {
    if ($r = need_staff('view_documents')) {
        return $r;
    }
    $doc = q1("SELECT d.* FROM documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ? AND d.org_id = ? AND a.status != 'draft'", [$id, org_id()]);
    return send_document($doc, arg('view') === '1');
});

// ---------------------------------------------------------------- letters and agreements
route('POST', '/admin/applications/{id}/letters', function (int $id) {
    if ($r = need_staff('generate_documents')) {
        return $r;
    }
    $app = staff_app($id);
    $kind = form('kind');
    if (!in_array($kind, STAFF_WRITTEN, true)) {
        abort(400);
    }
    $extra = mb_substr(trim(form('body')), 0, 4000);
    if ($kind === 'info_request' && $extra === '') {
        flash('Describe the information you need before generating the request.', 'error');
        return back_to($id, '#letters');
    }
    try {
        $docId = generate_letter($kind, $app, current_user(), $extra);
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        return back_to($id, '#letters');
    }
    audit_log('letter.generated', 'generated_document', $docId, ['application_id' => $id, 'kind' => $kind]);
    flash('Draft generated. Check it, then issue it to make it visible to the client.', 'success');
    return back_to($id, '#letters');
});

route('GET', '/admin/letters/{id}', function (int $id) {
    if ($r = need_staff('view_documents')) {
        return $r;
    }
    $doc = q1('SELECT * FROM generated_documents WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($doc === null) {
        abort(404);
    }
    audit_log('letter.download', 'generated_document', $id, ['application_id' => $doc['application_id']]);
    return private_file('generated', $doc['stored_name'], ref_str((int) $doc['application_id']) . "-{$doc['kind']}-v{$doc['version']}"
        . ($doc['status'] === 'draft' ? '-DRAFT' : '') . '.pdf');
});

route('POST', '/admin/letters/{id}/issue', function (int $id) {
    if ($r = need_staff('issue_documents')) {
        return $r;
    }
    $doc = q1('SELECT * FROM generated_documents WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($doc === null || $doc['status'] !== 'draft') {
        abort(404);
    }
    $app = staff_app((int) $doc['application_id']);
    if (!form('confirm')) {
        flash('Tick the box to confirm you have checked the document.', 'error');
        return back_to((int) $app['id'], '#letters');
    }
    try {
        issue_letter($doc, $app, current_user());
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
        return back_to((int) $app['id'], '#letters');
    }
    audit_log('letter.issued', 'generated_document', $id, ['application_id' => $app['id'], 'kind' => $doc['kind']]);
    notify((int) $app['user_id'], 'New document: ' . $doc['title'], "/account/applications/{$app['id']}#letters");
    if ($doc['kind'] === 'agreement' && in_array($app['status'], APPROVED, true)) {
        set_status($app, 'agreement_issued', current_user(), "Agreement v{$doc['version']} issued", '', false);
    }
    notify_document_issued($app, ['email' => $app['email'], 'full_name' => $app['full_name']], $doc['title']);
    flash('Document issued. The client can now see it and has been emailed.', 'success');
    return back_to((int) $app['id'], '#letters');
});

route('GET', '/admin/letters', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $kind = isset(LETTER_KINDS[arg('kind')]) ? arg('kind') : '';
    return view('admin/letters', ['kind' => $kind, 'rows' => q('SELECT g.*, a.company_name, u.full_name AS generated_by_name FROM generated_documents g
        JOIN applications a ON a.id = g.application_id JOIN users u ON u.id = g.generated_by WHERE g.org_id = ? ' . ($kind ? 'AND g.kind = ? ' : '')
        . 'ORDER BY g.id DESC LIMIT 300', $kind ? [org_id(), $kind] : [org_id()])]);
});

// ---------------------------------------------------------------- enquiries, messages, notifications
route('GET', '/admin/enquiries', function () {
    if ($r = need_staff('view_enquiries')) {
        return $r;
    }
    $show = arg('show', 'open');
    return view('admin/enquiries', ['show' => $show, 'enquiries' => q('SELECT * FROM enquiries WHERE org_id = ? ' . ($show === 'all' ? '' : 'AND handled = 0 ')
        . 'ORDER BY created_at DESC LIMIT 300', [org_id()])]);
});

function enquiry_row(int $id): array
{
    $e = q1('SELECT * FROM enquiries WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($e === null) {
        abort(404);
    }
    return $e;
}

function render_enquiry(array $e, string $draft = ''): Response
{
    return view('admin/enquiry', ['e' => $e, 'draft' => $draft, 'ai_drafts' => ai_staff_enabled() && can('use_ai_drafts'),
        'replies' => q('SELECT r.*, u.full_name FROM enquiry_replies r JOIN users u ON u.id = r.author_id WHERE r.enquiry_id = ? ORDER BY r.created_at', [$e['id']])]);
}

route('GET|POST', '/admin/enquiries/{id}', function (int $id) {
    if ($r = need_staff('view_enquiries')) {
        return $r;
    }
    $e = enquiry_row($id);
    if (is_post()) {
        require_perm('handle_enquiries');
        $body = mb_substr(trim(form('body')), 0, 6000);
        if ($body === '') {
            flash('Write a reply first.', 'error');
        } else {
            transaction(function () use ($id, $body) {
                x('INSERT INTO enquiry_replies (enquiry_id, author_id, body, created_at) VALUES (?,?,?,?)', [$id, current_user()['id'], $body, now()]);
                x('UPDATE enquiries SET handled = 1, handled_by = ?, handled_at = ? WHERE id = ?', [current_user()['id'], now(), $id]);
                audit_log('enquiry.replied', 'enquiry', $id);
            });
            send_enquiry_reply($e, $body, current_user());
            flash('Reply sent and the enquiry marked as handled.', 'success');
            return redirect("/admin/enquiries/$id");
        }
    }
    return render_enquiry($e);
});

route('POST', '/admin/enquiries/{id}/handled', function (int $id) {
    if ($r = need_staff('handle_enquiries')) {
        return $r;
    }
    enquiry_row($id);
    x('UPDATE enquiries SET handled = 1 - handled, handled_by = ?, handled_at = ? WHERE id = ? AND org_id = ?', [current_user()['id'], now(), $id, org_id()]);
    audit_log('enquiry.toggled', 'enquiry', $id);
    return redirect('/admin/enquiries?show=' . rawurlencode(arg('show', 'open')));
});

route('POST', '/admin/enquiries/{id}/ai-draft', function (int $id) {
    if ($r = need_staff('use_ai_drafts')) {
        return $r;
    }
    if (!ai_staff_enabled()) {
        abort(403);
    }
    $e = enquiry_row($id);
    if (throttled('aidraft:' . current_user()['id'], 30, 3600)) {
        abort(429);
    }
    $system = ai_guardrails() . ai_reference() . "\n\nYou are drafting a reply for a staff member to review and edit. Do not include a signature. Do not promise a response time.";
    $text = ai_ask($system, "Draft a polite reply to this website enquiry:\n\n" . ai_redact($e['message'] ?: '(no message)'), 2048);
    audit_log('ai.enquiry_draft', 'enquiry', $id, ['ok' => (bool) $text]);
    if (!$text) {
        flash('The AI service is unavailable right now. Please write the reply yourself.', 'error');
        return render_enquiry($e);
    }
    flash('AI-generated draft below. Check and edit it before sending: nothing has been sent.', 'info');
    return render_enquiry($e, mb_substr($text, 0, 4000));
});

route('GET', '/admin/messages', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    return view('admin/messages', ['rows' => q("SELECT a.id, a.company_name, u.full_name, n.body, n.created_at, au.role AS last_role, a.assigned_to FROM applications a
        JOIN users u ON u.id = a.user_id JOIN notes n ON n.id = (SELECT n2.id FROM notes n2 WHERE n2.application_id = a.id AND n2.visible_to_client = 1
        ORDER BY n2.created_at DESC, n2.id DESC LIMIT 1) JOIN users au ON au.id = n.author_id WHERE a.org_id = ? AND a.status != 'draft'
        ORDER BY (au.role = 'client') DESC, n.created_at DESC LIMIT 200", [org_id()])]);
});

route('GET|POST', '/admin/notifications', function () {
    if ($r = need_staff()) {
        return $r;
    }
    $uid = current_user()['id'];
    if (is_post()) {
        x('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $uid]);
        return redirect('/admin/notifications');
    }
    return view('admin/notifications', ['rows' => q('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 200', [$uid])]);
});

// ---------------------------------------------------------------- customers
route('GET', '/admin/clients', function () {
    if ($r = need_staff('view_clients')) {
        return $r;
    }
    $search = mb_substr(trim(arg('q')), 0, 100);
    $where = "WHERE u.org_id = ? AND u.role = 'client'";
    $args = [org_id()];
    if ($search !== '') {
        $where .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
        array_push($args, "%$search%", "%$search%");
    }
    if (arg('export') === 'csv') {
        return csv_response('customers.csv', ['name', 'email', 'phone', 'registered', 'email_confirmed', 'marketing_opt_in'],
            array_map(fn($r) => [$r['full_name'], $r['email'], $r['phone'], $r['created_at'], $r['email_verified_at'] ? 'yes' : 'no', $r['marketing_opt_in_at'] ? 'yes' : 'no'],
                q("SELECT u.* FROM users u $where ORDER BY u.created_at DESC", $args)));
    }
    $page = max(1, arg_int('page', 1));
    $total = (int) qv("SELECT COUNT(*) FROM users u $where", $args);
    $rows = q("SELECT u.*, COUNT(a.id) AS apps FROM users u LEFT JOIN applications a ON a.user_id = u.id AND a.status != 'draft' $where
               GROUP BY u.id ORDER BY u.created_at DESC LIMIT ? OFFSET ?", [...$args, PAGE_SIZE, ($page - 1) * PAGE_SIZE]);
    return view('admin/clients', ['clients' => $rows, 'search' => $search, 'page' => $page, 'pages' => max(1, (int) ceil($total / PAGE_SIZE)), 'total' => $total]);
});

// ---------------------------------------------------------------- staff accounts
function active_admins(): int
{
    return (int) qv("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", [org_id()]);
}

/** A staff account with an unusable password, and an emailed one-time link to choose one. */
function invite_staff(int $org, string $email, string $name, string $role): int
{
    $uid = x('INSERT INTO users (org_id, email, password_hash, full_name, role, created_at) VALUES (?,?,?,?,?,?)',
        [$org, $email, hash_password(bin2hex(random_bytes(24))), $name, $role, now()]);
    $token = token_urlsafe(32);
    x('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)', [$uid, sha256($token), later(86400), now()]);
    send_mail($email, 'Your staff account', 'staff_invite', ['user' => ['full_name' => $name], 'link' => tenant_base_url() . "/account/reset/$token", 'role' => STAFF_ROLES[$role]]);
    audit_log('staff.created', 'user', $uid, ['role' => $role]);
    return $uid;
}

route('GET|POST', '/admin/staff', function () {
    if ($r = need_staff('manage_staff')) {
        return $r;
    }
    $limit = plan_limit(org_id(), 'max_staff');
    if (is_post()) {
        $email = strtolower(mb_substr(trim(form('email')), 0, 200));
        $name = mb_substr(trim(form('full_name')), 0, 120);
        $role = form('role');
        $count = (int) qv("SELECT COUNT(*) FROM users WHERE org_id = ? AND role != 'client' AND is_active = 1", [org_id()]);
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) || mb_strlen($name) < 2 || !isset(STAFF_ROLES[$role])) {
            flash('Enter a name, a valid email and a role.', 'error');
        } elseif ($limit !== null && $count >= $limit) {
            flash("Your subscription allows $limit active staff accounts. Deactivate one or change plan.", 'error');
        } elseif (q1('SELECT 1 FROM users WHERE org_id = ? AND email = ?', [org_id(), $email])) {
            flash('An account with that email already exists here. Change its role below instead.', 'error');
        } else {
            invite_staff(org_id(), $email, $name, $role);
            flash("Staff account created. $email has been emailed a link (valid 24 hours) to choose a password.", 'success');
        }
        return redirect('/admin/staff');
    }
    return view('admin/staff', ['rows' => q("SELECT * FROM users WHERE org_id = ? AND role != 'client' ORDER BY is_active DESC, full_name", [org_id()]), 'limit' => $limit]);
});

route('POST', '/admin/staff/{id}', function (int $id) {
    if ($r = need_staff('manage_staff')) {
        return $r;
    }
    $user = q1("SELECT * FROM users WHERE id = ? AND org_id = ? AND role != 'client'", [$id, org_id()]);
    if ($user === null) {
        abort(404);
    }
    if ($id === (int) current_user()['id']) {
        abort(400, "You can't change your own role or deactivate yourself. Ask another administrator.");
    }
    $action = form('action');
    if ($action === 'role') {
        $role = form('role');
        if (!isset(STAFF_ROLES[$role])) {
            abort(400);
        }
        if ($user['role'] === 'admin' && $role !== 'admin' && active_admins() <= 1) {
            abort(400, 'There must always be at least one active administrator.');
        }
        x('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        revoke_sessions($id);  // new permissions take effect at the next sign-in
        audit_log('staff.role_changed', 'user', $id, ['old' => $user['role'], 'new' => $role]);
        flash('Role changed. They will need to sign in again.', 'success');
    } elseif (in_array($action, ['deactivate', 'activate'], true)) {
        if ($action === 'deactivate' && $user['role'] === 'admin' && active_admins() <= 1) {
            abort(400, 'There must always be at least one active administrator.');
        }
        x('UPDATE users SET is_active = ? WHERE id = ?', [$action === 'activate' ? 1 : 0, $id]);
        revoke_sessions($id);
        audit_log("staff.{$action}d", 'user', $id);
        flash('Account updated.', 'success');
    } else {
        abort(400);
    }
    return redirect('/admin/staff');
});

// ---------------------------------------------------------------- oversight
route('GET', '/admin/audit', function () {
    if ($r = need_staff('view_audit')) {
        return $r;
    }
    $event = mb_substr(trim(arg('event')), 0, 60);
    $where = 'WHERE l.org_id = ?';
    $args = [org_id()];
    if ($event !== '') {
        $where .= ' AND l.event LIKE ?';
        $args[] = "$event%";
    }
    if (arg('export') === 'csv') {
        return csv_response('audit-log.csv', ['id', 'created_at', 'actor', 'role', 'event', 'target_type', 'target_id', 'detail', 'ip'],
            array_map(fn($r) => [$r['id'], $r['created_at'], $r['email'], $r['actor_role'], $r['event'], $r['target_type'], $r['target_id'], $r['detail'], $r['ip']],
                q("SELECT l.*, u.email FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id $where ORDER BY l.id", $args)));
    }
    $page = max(1, arg_int('page', 1));
    $total = (int) qv("SELECT COUNT(*) FROM audit_log l $where", $args);
    [$ok, $checked, $bad] = verify_chain();
    return view('admin/audit', ['rows' => q("SELECT l.*, u.full_name, u.email FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id $where ORDER BY l.id DESC LIMIT 50 OFFSET ?",
        [...$args, ($page - 1) * 50]), 'event' => $event, 'page' => $page, 'pages' => max(1, (int) ceil($total / 50)), 'chain_ok' => $ok, 'checked' => $checked, 'bad' => $bad]);
});

route('GET', '/admin/emails', function () {
    if ($r = need_staff('view_audit')) {
        return $r;
    }
    $status = arg('status');
    return view('admin/emails', ['status' => $status, 'rows' => q('SELECT * FROM email_log WHERE org_id = ? ' . ($status !== '' ? 'AND status = ? ' : '')
        . 'ORDER BY id DESC LIMIT 200', $status !== '' ? [org_id(), $status] : [org_id()])]);
});
