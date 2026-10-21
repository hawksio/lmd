<?php
/**
 * Applications, documents and underwriting decisions: shared logic used by the client portal and the staff area.
 *
 * The requested and approved amounts are kept separate. A decision is first proposed; it becomes visible to the
 * customer only when issued. Approvals at or above the threshold (product setting, else organisation setting; 0 means
 * every approval) must be issued by a different person from the proposer, which a database CHECK also enforces.
 * Each decision freezes its pricing calculation, which the agreement and repayment schedule then use.
 */
declare(strict_types=1);

const APP_FIELDS = ['company_name', 'company_number', 'business_type', 'industry', 'years_trading', 'annual_turnover', 'address',
    'finance_type', 'amount', 'term_months', 'purpose', 'role_in_business'];
const APP_REQUIRED = ['company_name', 'business_type', 'industry', 'years_trading', 'annual_turnover', 'address', 'finance_type', 'amount',
    'term_months', 'purpose'];
const FIELD_TYPES = ['text', 'number', 'select', 'yesno'];

function whole_number(?string $value): ?int
{
    $value = trim(str_replace([',', '£', '€', '$'], '', (string) $value));
    return (ctype_digit($value) && strlen($value) <= 12) ? (int) $value : null;
}

function active_products(): array
{
    return q('SELECT * FROM loan_products WHERE org_id = ? AND active = 1 ORDER BY name', [org_id()]);
}

function product_fields(?array $product): array
{
    if (!$product) {
        return [];
    }
    $fields = json_decode($product['form_fields'] ?: '[]', true);
    if (!is_array($fields)) {
        return [];
    }
    return array_values(array_filter($fields, fn($f) => is_array($f) && !empty($f['key']) && in_array($f['type'] ?? '', FIELD_TYPES, true)));
}

/** [values, errors]. Drafts keep whatever is valid; submission needs everything. */
function read_application_form(bool $submitting): array
{
    $v = [];
    foreach (APP_FIELDS as $f) {
        $v[$f] = trim(form($f));
    }
    $errors = [];
    foreach (['annual_turnover', 'amount', 'term_months'] as $f) {
        $raw = $v[$f];
        $v[$f] = whole_number($raw);
        if ($raw !== '' && $v[$f] === null) {
            $errors[$f] = 'Enter a whole number.';
        }
    }
    foreach (['business_type' => BUSINESS_TYPES, 'finance_type' => FINANCE_TYPES, 'years_trading' => YEARS_TRADING, 'role_in_business' => ROLES_IN_BUSINESS] as $f => $options) {
        if ($v[$f] !== '' && !isset($options[$v[$f]])) {
            $errors[$f] = 'Choose an option.';
            $v[$f] = '';
        }
    }
    $products = active_products();
    $product = null;
    $pid = form_int('product_id');
    if ($products) {
        foreach ($products as $p) {
            if ((int) $p['id'] === $pid) {
                $product = $p;
            }
        }
        if ($pid && $product === null) {
            $errors['product_id'] = 'Choose one of the products offered.';
        }
    }
    $v['product_id'] = $product ? (int) $product['id'] : null;
    $allowed = $product ? csv_list($product['currencies']) : array_keys(CURRENCIES);
    $currency = strtoupper(form('currency') ?: ($product ? $allowed[0] : current_org()['default_currency']));
    if (!in_array($currency, $allowed, true)) {
        $errors['currency'] = 'Choose a currency offered for this product.';
        $currency = $allowed[0];
    }
    $v['currency'] = $currency;
    if ($product) {
        if ($v['amount'] !== null && !($product['min_amount_minor'] <= $v['amount'] * 100 && $v['amount'] * 100 <= $product['max_amount_minor'])) {
            $errors['amount'] = 'Enter an amount between ' . number_format(intdiv((int) $product['min_amount_minor'], 100)) . ' and '
                . number_format(intdiv((int) $product['max_amount_minor'], 100)) . '.';
        }
        if ($v['term_months'] !== null && !($product['min_term_months'] <= $v['term_months'] && $v['term_months'] <= $product['max_term_months'])) {
            $errors['term_months'] = "Enter a term between {$product['min_term_months']} and {$product['max_term_months']} months.";
        }
        $v['finance_type'] = isset(FINANCE_TYPES[$product['finance_type']]) ? $product['finance_type'] : ($v['finance_type'] ?: 'loans');
    } else {
        if ($v['amount'] !== null && !(1000 <= $v['amount'] && $v['amount'] <= 5000000)) {
            $errors['amount'] = 'Enter an amount between 1,000 and 5,000,000.';
        }
        if ($v['term_months'] !== null && !(3 <= $v['term_months'] && $v['term_months'] <= 120)) {
            $errors['term_months'] = 'Enter a term between 3 and 120 months.';
        }
    }
    if ($v['annual_turnover'] !== null && $v['annual_turnover'] > 10000000000) {
        $errors['annual_turnover'] = 'Check the turnover figure.';
    }
    $v['company_number'] = strtoupper(trim($v['company_number']));
    if ($v['company_number'] !== '' && !preg_match('#^[A-Z0-9 /-]{2,30}$#', $v['company_number'])) {
        $errors['company_number'] = 'Use letters, numbers, spaces, / or - only.';
    }
    $v['company_name'] = mb_substr($v['company_name'], 0, 200);
    $v['industry'] = mb_substr($v['industry'], 0, 200);
    $v['address'] = mb_substr($v['address'], 0, 500);
    $v['purpose'] = mb_substr($v['purpose'], 0, 2000);
    $v['consent'] = form('consent') ? 1 : 0;
    $extra = [];
    foreach (product_fields($product) as $f) {
        $key = 'x_' . $f['key'];
        $raw = mb_substr(trim(form($key)), 0, 500);
        if ($f['type'] === 'number' && $raw !== '' && whole_number($raw) === null) {
            $errors[$key] = 'Enter a whole number.';
        }
        if ($f['type'] === 'select' && $raw !== '' && !in_array($raw, $f['options'] ?? [], true)) {
            $errors[$key] = 'Choose an option.';
        }
        if ($f['type'] === 'yesno' && $raw !== '' && !in_array($raw, ['yes', 'no'], true)) {
            $errors[$key] = 'Choose yes or no.';
        }
        if ($submitting && !empty($f['required']) && $raw === '') {
            $errors[$key] = 'Required to submit.';
        }
        $extra[$f['key']] = $raw;
    }
    $v['extra_fields'] = json_out((object) $extra);
    if ($submitting) {
        foreach (APP_REQUIRED as $f) {
            if (($v[$f] === '' || $v[$f] === null) && !isset($errors[$f])) {
                $errors[$f] = 'Required to submit.';
            }
        }
        if ($products && !$product && !isset($errors['product_id'])) {
            $errors['product_id'] = 'Choose a loan product.';
        }
        if (in_array($v['business_type'], ['ltd', 'llp'], true) && $v['company_number'] === '' && !isset($errors['company_number'])) {
            $errors['company_number'] = 'Required for limited companies and LLPs.';
        }
        if (!$v['consent']) {
            $errors['consent'] = 'We need your consent to assess the application.';
        }
    }
    return [$v, $errors];
}

function record_status(int $appId, string $status, ?int $userId, string $note = ''): void
{
    x('INSERT INTO status_history (application_id, status, changed_by, note, created_at) VALUES (?,?,?,?,?)',
        [$appId, $status, $userId, mb_substr($note, 0, 2000), now()]);
}

function notify(int $userId, string $message, string $link = ''): void
{
    x('INSERT INTO notifications (user_id, message, link, created_at) VALUES (?,?,?,?)', [$userId, mb_substr($message, 0, 300), $link, now()]);
}

/** In-portal notification for every active staff member of this organisation who holds $permission. */
function notify_staff(string $permission, string $message, string $link = ''): void
{
    $roles = PERMISSIONS[$permission];
    $marks = implode(',', array_fill(0, count($roles), '?'));
    foreach (q("SELECT id FROM users WHERE org_id = ? AND is_active = 1 AND role IN ($marks)", [org_id(), ...$roles]) as $u) {
        notify((int) $u['id'], $message, $link);
    }
}

/** Change status with history, client notification, email and audit entry, all or nothing. */
function set_status(array $app, string $new, array $actor, string $note = '', string $message = '', bool $email = true): void
{
    transaction(function () use ($app, $new, $actor, $note, $message) {
        x('UPDATE applications SET status = ?, updated_at = ? WHERE id = ? AND org_id = ?', [$new, now(), $app['id'], org_id()]);
        record_status((int) $app['id'], $new, (int) $actor['id'], $note);
        if ($message !== '') {
            x('INSERT INTO notes (application_id, author_id, body, visible_to_client, created_at) VALUES (?,?,?,1,?)',
                [$app['id'], $actor['id'], mb_substr($message, 0, 4000), now()]);
        }
        notify((int) $app['user_id'], ref_str((int) $app['id']) . ' is now: ' . STATUSES[$new], "/account/applications/{$app['id']}");
        audit_log('application.status', 'application', $app['id'], ['old' => $app['status'], 'new' => $new], $actor);
    });
    if ($email) {
        $client = q1('SELECT * FROM users WHERE id = ?', [$app['user_id']]);
        $fresh = q1('SELECT * FROM applications WHERE id = ?', [$app['id']]);
        notify_client_status($fresh, $client, STATUSES[$new], $message);
    }
}

// ---------------------------------------------------------------- uploads
const PDF_ACTIVE = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/RichMedia', '/SubmitForm'];
const MIME_BY_EXT = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

/** Validate by content, never by the name or the browser's type. Returns an error message or null. */
function check_upload_content(string $data, string $ext): ?string
{
    if ($data === '') {
        return 'That file is empty.';
    }
    if ($ext === 'pdf') {
        if (!str_starts_with($data, '%PDF-') || !str_contains(substr($data, -2048), '%%EOF')) {
            return "That file doesn't look like a real PDF.";
        }
        foreach (PDF_ACTIVE as $tag) {
            if (str_contains($data, $tag)) {
                return "That PDF contains scripts or embedded files, which we can't accept. Please print it to a new PDF and upload that.";
            }
        }
        return null;
    }
    $png = $ext === 'png';
    if (!str_starts_with($data, $png ? "\x89PNG\r\n\x1a\n" : "\xFF\xD8\xFF")) {
        return "That file doesn't look like a real PDF or image.";
    }
    $info = @getimagesizefromstring($data);
    if (!$info || $info[2] !== ($png ? IMAGETYPE_PNG : IMAGETYPE_JPEG) || $info[0] * $info[1] > 60000000) {
        return 'That image is damaged or too large to process. Please try another copy.';
    }
    if (@imagecreatefromstring($data) === false) {
        return 'That image is damaged or too large to process. Please try another copy.';
    }
    return null;
}

/** Validate and store one uploaded file. Returns [error, document id]. New documents start as 'submitted'. */
function save_upload(?array $file, int $appId, int $userId, string $docType = 'other', ?int $replacesId = null): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') {
        return ['Choose a file to upload.', null];
    }
    $limit = ((int) config('max_upload_mb')) * 1024 * 1024;
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > $limit) {
        return ['That file is larger than ' . config('max_upload_mb') . ' MB.', null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) && !defined('LMD_TESTING')) {
        return ['The upload did not complete. Please try again.', null];
    }
    $original = mb_substr(preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string) $file['name'])), 0, 120) ?: 'document';
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_UPLOADS, true)) {
        return ['Upload a PDF, JPG or PNG file.', null];
    }
    if (!isset(DOC_TYPES[$docType])) {
        $docType = 'other';
    }
    $data = (string) file_get_contents($file['tmp_name']);
    if (strlen($data) > $limit) {
        return ['That file is larger than ' . config('max_upload_mb') . ' MB.', null];
    }
    $error = check_upload_content($data, $ext);
    if ($error) {
        return [$error, null];
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;  // random, never derived from the client's file name
    file_put_contents(storage_dir() . '/uploads/' . $stored, $data);
    $docId = x("INSERT INTO documents (org_id, application_id, uploaded_by, original_name, stored_name, size_bytes, created_at, doc_type, sha256, mime,
                review_status, replaces_id) VALUES (?,?,?,?,?,?,?,?,?,?,'submitted',?)",
        [org_id(), $appId, $userId, $original, $stored, strlen($data), now(), $docType, hash('sha256', $data), MIME_BY_EXT[$ext], $replacesId]);
    audit_log('document.upload', 'document', $docId, ['application_id' => $appId, 'doc_type' => $docType, 'size' => strlen($data), 'replaces' => $replacesId]);
    return [null, $docId];
}

/** A private file as a download (or inline image). Callers must already have checked access. */
function private_file(string $folder, string $stored, string $downloadName, bool $inline = false): Response
{
    if (!preg_match('/^[0-9a-f]{32}\.(pdf|png|jpg|jpeg)$/', $stored)) {
        abort(404);
    }
    $path = storage_dir() . "/$folder/$stored";
    if (!is_file($path)) {
        abort(404);
    }
    $ext = pathinfo($stored, PATHINFO_EXTENSION);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    return new Response('', 200, [
        'Content-Type' => MIME_BY_EXT[$ext],
        'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        'Cache-Control' => 'private, no-store',
        'X-Content-Type-Options' => 'nosniff',
    ], $path);
}

function send_document(?array $doc, bool $inline = false): Response
{
    if ($doc === null) {
        abort(404);
    }
    $inline = $inline && in_array($doc['mime'], ['image/png', 'image/jpeg'], true);  // PDFs always download
    audit_log($inline ? 'document.view' : 'document.download', 'document', $doc['id'], ['application_id' => $doc['application_id']]);
    return private_file('uploads', $doc['stored_name'], $doc['original_name'], $inline);
}

/** [documents, notes, history, letters] for a detail page. */
function application_bundle(int $appId, bool $internal): array
{
    $docs = q('SELECT d.*, u.role AS uploader_role, r.full_name AS reviewer_name FROM documents d JOIN users u ON u.id = d.uploaded_by
               LEFT JOIN users r ON r.id = d.reviewed_by WHERE d.application_id = ? AND d.org_id = ? ORDER BY d.created_at DESC, d.id DESC', [$appId, org_id()]);
    $notes = q('SELECT n.*, u.full_name, u.role FROM notes n JOIN users u ON u.id = n.author_id WHERE n.application_id = ? '
        . ($internal ? '' : 'AND n.visible_to_client = 1 ') . 'ORDER BY n.created_at ASC, n.id ASC', [$appId]);
    $history = q('SELECT h.*, u.full_name AS actor_name, u.role AS actor_role FROM status_history h LEFT JOIN users u ON u.id = h.changed_by
                  WHERE h.application_id = ? ORDER BY h.created_at ASC, h.id ASC', [$appId]);
    $letters = q('SELECT gd.*, u.full_name AS generated_by_name, i.full_name AS issued_by_name FROM generated_documents gd JOIN users u ON u.id = gd.generated_by
                  LEFT JOIN users i ON i.id = gd.issued_by WHERE gd.application_id = ? ' . ($internal ? '' : "AND gd.status = 'issued' ")
        . 'ORDER BY gd.generated_at DESC, gd.id DESC', [$appId]);
    return [$docs, $notes, $history, $letters];
}

// ---------------------------------------------------------------- document verification
/** What the application still needs: product-required types plus open requests, with the newest document of each. */
function doc_requirements(array $app): array
{
    $product = $app['product_id'] ? q1('SELECT required_docs FROM loan_products WHERE id = ?', [$app['product_id']]) : null;
    $needed = $product ? csv_list($product['required_docs']) : [];
    $requests = q("SELECT * FROM document_requests WHERE application_id = ? AND status = 'open' ORDER BY id", [$app['id']]);
    foreach ($requests as $r) {
        if (!in_array($r['doc_type'], $needed, true)) {
            $needed[] = $r['doc_type'];
        }
    }
    $out = [];
    foreach ($needed as $type) {
        $latest = q1('SELECT * FROM documents WHERE application_id = ? AND doc_type = ? ORDER BY id DESC LIMIT 1', [$app['id'], $type]);
        $note = '';
        foreach ($requests as $r) {
            if ($r['doc_type'] === $type) {
                $note = $r['note'];
                break;
            }
        }
        $out[] = ['doc_type' => $type, 'label' => DOC_TYPES[$type] ?? $type, 'request_note' => $note,
            'status' => $latest['review_status'] ?? 'not_submitted', 'document' => $latest];
    }
    return $out;
}

function doc_outstanding(array $app): array
{
    return array_values(array_filter(doc_requirements($app), fn($r) => $r['status'] !== 'verified'));
}

function record_review(array $doc, string $status, array $reviewer, string $internal = '', string $customer = '', ?string $expires = null): void
{
    transaction(function () use ($doc, $status, $reviewer, $internal, $customer, $expires) {
        x('UPDATE documents SET review_status = ?, internal_note = ?, customer_note = ?, review_note = ?, reviewed_by = ?, reviewed_at = ?,
           expires_on = COALESCE(?, expires_on) WHERE id = ? AND org_id = ?',
            [$status, $internal, $customer, $customer, $reviewer['id'], now(), $expires, $doc['id'], org_id()]);
        x('INSERT INTO document_reviews (org_id, document_id, status, internal_note, customer_note, reviewer_id, created_at) VALUES (?,?,?,?,?,?,?)',
            [org_id(), $doc['id'], $status, $internal, $customer, $reviewer['id'], now()]);
        if ($status === 'verified') {
            x("UPDATE document_requests SET status = 'fulfilled', closed_at = ? WHERE application_id = ? AND doc_type = ? AND status = 'open'",
                [now(), $doc['application_id'], $doc['doc_type']]);
        }
        audit_log('document.reviewed', 'document', $doc['id'], ['application_id' => $doc['application_id'], 'result' => $status]);
    });
}

function on_customer_upload(array $app, int $docId, string $docType, ?array $replaced): void
{
    if ($replaced) {
        x('INSERT INTO document_reviews (org_id, document_id, status, internal_note, customer_note, reviewer_id, created_at) VALUES (?,?,?,?,?,?,?)',
            [org_id(), $replaced['id'], $replaced['review_status'], "Replaced by document $docId", '', $app['user_id'], now()]);
    }
    notify_staff('review_documents', 'New document to review on ' . ref_str((int) $app['id']) . ': ' . (DOC_TYPES[$docType] ?? $docType),
        "/admin/documents/$docId/review");
}

// ---------------------------------------------------------------- underwriting decisions
const DECIDABLE = [...REVIEWING, ...APPROVED, 'agreement_issued'];

function staff_app(int $appId): array
{
    $a = q1("SELECT a.*, u.full_name, u.email, u.phone, u.email_verified_at FROM applications a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? AND a.org_id = ? AND a.status != 'draft'", [$appId, org_id()]);
    if ($a === null) {
        abort(404);  // drafts are the client's private work; other organisations' data doesn't exist here
    }
    return $a;
}

function with_pricing(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    $row['pricing_data'] = json_decode($row['pricing'], true);
    return $row;
}

function all_decisions(int $appId): array
{
    return array_map('with_pricing', q('SELECT d.*, p.full_name AS proposer, i.full_name AS issuer FROM approval_decisions d JOIN users p ON p.id = d.proposed_by
        LEFT JOIN users i ON i.id = d.issued_by WHERE d.application_id = ? AND d.org_id = ? ORDER BY d.revision DESC', [$appId, org_id()]));
}

function current_decision(int $appId): ?array
{
    return with_pricing(q1("SELECT * FROM approval_decisions WHERE application_id = ? AND org_id = ? AND status = 'issued' ORDER BY revision DESC LIMIT 1",
        [$appId, org_id()]));
}

function decision_conditions(int $decisionId): array
{
    return q('SELECT * FROM decision_conditions WHERE decision_id = ? AND org_id = ? ORDER BY id', [$decisionId, org_id()]);
}

function approval_threshold(?array $product): int
{
    if ($product !== null && $product['second_approval_threshold_minor'] !== null) {
        return (int) $product['second_approval_threshold_minor'];
    }
    return (int) (org_settings()['second_approval_threshold_minor'] ?: 0);
}

/** [values, quote, errors] from the decision form. */
function read_decision(array $app): array
{
    $errors = [];
    $pid = form_int('product_id') ?: $app['product_id'];
    $product = $pid ? q1('SELECT * FROM loan_products WHERE id = ? AND org_id = ?', [$pid, org_id()]) : null;
    if ($product === null) {
        $errors['product_id'] = 'Choose the loan product the approval is under (set up products in Loan products).';
    }
    $requested = ((int) $app['amount']) * 100;
    $approved = parse_money(form('approved_amount'));
    $term = form_int('term_months');
    $type = form('decision_type');
    $conds = array_values(array_filter(array_map(fn($c) => mb_substr(trim($c), 0, 500), preg_split('/\R/', form('conditions'))), fn($c) => $c !== ''));
    $reason = mb_substr(trim(form('reason')), 0, 2000);
    if (!$approved || $approved <= 0) {
        $errors['approved_amount'] = 'Enter the approved amount (more than zero).';
    } elseif ($product !== null) {
        if ($approved > $requested && !$product['allow_above_requested']) {
            $errors['approved_amount'] = "The approved amount can't exceed the requested amount under this product's policy.";
        }
        if (!($product['min_amount_minor'] <= $approved && $approved <= $product['max_amount_minor'])) {
            $errors['approved_amount'] = "The amount is outside this product's minimum and maximum.";
        }
        if (!$term || !($product['min_term_months'] <= $term && $term <= $product['max_term_months'])) {
            $errors['term_months'] = "Enter a term between {$product['min_term_months']} and {$product['max_term_months']} months.";
        }
        if (!in_array($app['currency'], csv_list($product['currencies']), true)) {
            $errors['product_id'] = "This product isn't offered in the application's currency.";
        }
    }
    if (!in_array($type, ['full', 'partial', 'conditional'], true)) {
        $errors['decision_type'] = 'Choose the type of approval.';
    } elseif ($approved && !isset($errors['approved_amount'])) {
        if ($type === 'full' && $approved < $requested) {
            $errors['decision_type'] = 'A full approval must approve the whole requested amount. Choose partial approval.';
        }
        if ($type === 'partial' && $approved >= $requested) {
            $errors['decision_type'] = 'A partial approval approves less than the requested amount.';
        }
        if ($type === 'conditional' && !$conds) {
            $errors['conditions'] = 'List the conditions, one per line.';
        }
    }
    if (mb_strlen($reason) < 10) {
        $errors['reason'] = 'Record the basis for this decision (at least a sentence).';
    }
    $quote = null;
    if (!$errors) {
        try {
            $quote = pricing_quote($product, q('SELECT * FROM financial_rules WHERE org_id = ?', [org_id()]), $requested, $approved, $term, $app['currency']);
        } catch (PricingError $e) {
            $errors['pricing'] = $e->getMessage();
        }
    }
    $values = ['product_id' => $product['id'] ?? null, 'approved_minor' => $approved, 'term_months' => $term, 'decision_type' => $type,
        'conditions' => $conds, 'conditions_text' => form('conditions'), 'reason' => $reason, 'internal_note' => mb_substr(trim(form('internal_note')), 0, 2000),
        'requested_minor' => $requested, 'approved_amount' => form('approved_amount'), 'product' => $product];
    return [$values, $quote, $errors];
}

/** Separate, genuine figures for the customer. Nothing here is a balance or available cash. */
function financing_summary(array $app): array
{
    $d = current_decision((int) $app['id']);
    $by = [];
    foreach (q('SELECT status, SUM(amount_pence) total, SUM(fees_deducted_minor) fees FROM disbursements WHERE application_id = ? AND org_id = ? GROUP BY status',
        [$app['id'], $app['org_id']]) as $r) {
        $by[$r['status']] = $r;
    }
    $repaid = (int) qv("SELECT COALESCE(SUM(amount_minor), 0) FROM repayments WHERE application_id = ? AND status = 'confirmed'", [$app['id']]);
    $position = loan_position((int) $app['id']);
    $agreement = q1("SELECT status FROM generated_documents WHERE application_id = ? AND kind = 'agreement' AND status IN ('issued', 'draft') ORDER BY id DESC LIMIT 1",
        [$app['id']]);
    if (in_array($app['status'], ['agreement_signed', 'funding_scheduled', 'partially_disbursed', 'fully_disbursed'], true)) {
        $agreementStatus = 'Signed';
    } elseif ($agreement && $agreement['status'] === 'issued') {
        $agreementStatus = 'Issued, awaiting signature';
    } else {
        $agreementStatus = 'Not issued';
    }
    return [
        'currency' => $app['currency'],
        'requested' => ((int) $app['amount']) * 100,
        'decision' => $d,
        'approved' => $d ? (int) $d['approved_minor'] : null,
        'conditions' => $d ? decision_conditions((int) $d['id']) : [],
        'agreement_status' => $agreementStatus,
        'scheduled' => (int) (($by['scheduled']['total'] ?? 0) + ($by['pending']['total'] ?? 0)),
        'disbursed' => (int) ($by['confirmed']['total'] ?? 0),
        'fees_deducted' => (int) ($by['confirmed']['fees'] ?? 0),
        'repaid' => $repaid,
        'outstanding_principal' => $position['outstanding_principal'] ?? null,
        'next_due' => $position['next_due'] ?? null,
    ];
}
