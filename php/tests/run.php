<?php
/**
 * End-to-end tests: starts the app on PHP's built-in server with a fresh, temporary storage folder and walks through
 * the full journey over HTTP (register, verify, apply, upload, verify documents, propose and issue a decision with a
 * second person, accept the offer, agreement, disbursement with second-person confirmation, schedule, repayment),
 * plus security and multi-organisation isolation checks. Emails go to the outbox, where the tests read the links.
 *
 * Usage: php tests/run.php   (uses the PHP binary running this script)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$storage = sys_get_temp_dir() . '/lmd-test-' . bin2hex(random_bytes(4));
mkdir($storage, 0700, true);
putenv("LMD_STORAGE_DIR=$storage");
file_put_contents("$storage/config.php", "<?php return ['base_url' => 'http://127.0.0.1:8099', 'max_upload_mb' => 2];\n");

define('LMD_DOCROOT', "$root/public_html");
require "$root/app/bootstrap.php";
require "$root/app/http.php";
migrate();

$port = 8099;
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public_html", "$root/public_html/index.php"],
    [1 => ['file', "$storage/server.log", 'a'], 2 => ['file', "$storage/server.log", 'a']], $pipes, null, ['LMD_STORAGE_DIR' => $storage] + getenv());
usleep(700000);

$pass = 0;
$fail = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
    }
}

final class Browser
{
    public string $jar;
    public int $status = 0;
    public string $body = '';
    public string $location = '';
    public array $headers = [];

    public function __construct(public string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function request(string $method, string $path, array $data = [], array $files = [], array $headers = []): self
    {
        $ch = curl_init($this->base . $path);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers];
        if ($method === 'POST') {
            $fields = $data;
            foreach ($files as $name => [$filename, $content, $type]) {
                $tmp = tempnam(sys_get_temp_dir(), 'up');
                file_put_contents($tmp, $content);
                $fields[$name] = new CURLFile($tmp, $type, $filename);
            }
            $opts[CURLOPT_POSTFIELDS] = $files ? $this->flatten($fields) : (is_string($data) ? $data : http_build_query($fields));
        }
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($raw, 0, $hsize);
        $this->body = substr($raw, $hsize);
        $this->headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->headers[strtolower(trim($k))] = trim($v);
            }
        }
        $this->location = $this->headers['location'] ?? '';
        return $this;
    }

    private function flatten(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $item) {
                    $out["{$k}[$i]"] = $item;
                }
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    /** GET the form page first for a CSRF token, then POST. */
    public function post(string $path, array $data = [], array $files = [], ?string $tokenFrom = null): self
    {
        $this->get($tokenFrom ?? $path);
        $data['csrf_token'] = $this->token();
        return $this->request('POST', $path, $data, $files);
    }

    public function token(): string
    {
        return preg_match('/name="csrf_token" value="([^"]+)"/', $this->body, $m) ? html_entity_decode($m[1]) : '';
    }

    public function follow(): self
    {
        return $this->location ? $this->get(preg_replace('#^https?://[^/]+#', '', $this->location)) : $this;
    }

    public function login(string $email, string $password, string $prefix = ''): self
    {
        return $this->post("$prefix/account/login", ['email' => $email, 'password' => $password]);
    }
}

function outbox_link(string $storage, string $to, string $pattern): ?string
{
    $files = glob("$storage/outbox/*.eml");
    rsort($files);
    foreach ($files as $f) {
        $raw = file_get_contents($f);
        if (!str_contains($raw, "To: $to")) {
            continue;
        }
        // Bodies are base64; decode every part and search for the link.
        preg_match_all('/\r\n\r\n([A-Za-z0-9+\/=\r\n]+)\r\n--/', $raw, $parts);
        foreach ($parts[1] as $p) {
            if (preg_match($pattern, (string) base64_decode($p), $m)) {
                return $m[0];
            }
        }
    }
    return null;
}

function make_user(int|null $org, string $email, string $name, string $role, string $password): int
{
    return x('INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) VALUES (?,?,?,?,?,?,?,?)',
        [$org, $email, password_hash($password, PASSWORD_DEFAULT), $name, $role, now(), now(), now()]);
}

$B = "http://127.0.0.1:$port";
$pw = 'correct horse battery staple';
make_user(1, 'admin@lmd.test', 'Ada Admin', 'admin', $pw);
make_user(1, 'under@lmd.test', 'Uma Underwriter', 'underwriter', $pw);
make_user(1, 'fin1@lmd.test', 'Fred Finance', 'finance', $pw);
make_user(1, 'fin2@lmd.test', 'Fay Finance', 'finance', $pw);
make_user(1, 'docs@lmd.test', 'Dee Reviewer', 'document_reviewer', $pw);
make_user(1, 'audit@lmd.test', 'Abe Auditor', 'auditor', $pw);
make_user(null, 'owner@platform.test', 'Olive Owner', 'platform_owner', $pw);

// ---------------------------------------------------------------- public site
$anon = new Browser($B);
check($anon->get('/')->status === 200 && str_contains($anon->body, 'LMD Finance'), 'home page renders');
check($anon->get('/faq.html')->status === 200 && str_contains($anon->body, 'faq-list'), 'FAQ page renders with FAQs');
check($anon->get('/nope.html')->status === 404, 'unknown page is 404');
check($anon->get('/api/health')->status === 200 && str_contains($anon->body, '"ok":true'), 'health check');
check($anon->get('/app/schema.sql')->status === 404 || $anon->status === 403, 'application code is not downloadable');
$tok = json_decode($anon->get('/api/csrf')->body, true)['token'] ?? '';
$anon->request('POST', '/api/enquiries', [], [], ['Content-Type: application/json', "X-CSRF-Token: $tok"]);
check($anon->status === 400, 'enquiry without required fields is rejected');
$ch = curl_init("$B/api/enquiries");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $anon->jar, CURLOPT_COOKIEJAR => $anon->jar,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $tok"],
    CURLOPT_POSTFIELDS => json_encode(['name' => 'Pat Example', 'company' => 'Example Ltd', 'email' => 'pat@example.com', 'topic' => 'loans', 'message' => 'Hello', 'consent' => true])]);
$resp = curl_exec($ch);
check(str_contains((string) $resp, '"ok":true'), 'website enquiry accepted', (string) $resp);

// ---------------------------------------------------------------- registration and verification
$client = new Browser($B);
$client->post('/account/register', ['full_name' => 'Casey Client', 'email' => 'casey@example.com', 'phone' => '+44 20 7946 0000',
    'password' => 'plum orbit candle river', 'confirm' => 'plum orbit candle river', 'terms' => '1']);
check($client->status === 200 && str_contains($client->body, 'Check your email'), 'registration shows check-your-email');
$link = outbox_link($storage, 'casey@example.com', '#http://[^\s"<]+/account/verify/[A-Za-z0-9_-]+#');
check($link !== null, 'verification email written to the outbox');
$client->login('casey@example.com', 'plum orbit candle river');
check(str_ends_with($client->location, '/account/'), 'client signs in', $client->location);
$client->get(parse_url((string) $link, PHP_URL_PATH));
check(str_contains($client->follow()->body, 'confirmed') || $client->status === 200, 'email verified');
$stranger = new Browser($B);
$stranger->login('casey@example.com', 'wrong password here');
check(str_contains($stranger->body, 'match an account'), 'wrong password rejected');

// ---------------------------------------------------------------- staff set up a product and pricing
$admin = new Browser($B);
$admin->login('admin@lmd.test', $pw);
check(str_ends_with($admin->location, '/admin/'), 'admin signs in to staff area');
$admin->post('/admin/products/new', ['name' => 'Growth Loan', 'description' => 'Term loans for growth', 'finance_type' => 'loans', 'currencies' => ['GBP'],
    'min_amount' => '5000', 'max_amount' => '250000', 'min_term_months' => '6', 'max_term_months' => '60', 'interest_method' => 'amortising',
    'repayment_frequency' => 'monthly', 'grace_period_months' => '0', 'required_docs' => ['bank_statements'], 'form_fields' => '[]', 'active' => '1']);
check($admin->status === 303, 'product created', substr(strip_tags($admin->body), 0, 300));
$pid = (int) qv("SELECT id FROM loan_products WHERE name = 'Growth Loan'");
$admin->post('/admin/financial-settings/new', ['kind' => 'interest', 'name' => 'Standard rate', 'method' => 'percent', 'percent' => '12.5', 'basis' => 'approved',
    'timing' => 'interest', 'effective_from' => '2026-01-01', 'enabled' => '1', 'confirm' => '1']);
check($admin->status === 303, 'interest rate saved', substr(strip_tags($admin->body), 0, 400));
$admin->post('/admin/financial-settings/new', ['kind' => 'processing_fee', 'name' => 'Arrangement fee', 'method' => 'percent', 'percent' => '2', 'basis' => 'approved',
    'timing' => 'deducted_from_disbursement', 'disclosure' => 'Taken from the amount we pay you.', 'effective_from' => '2026-01-01', 'enabled' => '1', 'confirm' => '1']);
check($admin->status === 303, 'processing fee saved');
$admin->post('/admin/financial-settings/new', ['kind' => 'processing_fee', 'name' => 'Too high', 'method' => 'percent', 'percent' => '150', 'basis' => 'approved',
    'timing' => 'added_to_loan', 'disclosure' => 'Should never be saved.', 'effective_from' => '2026-01-01', 'confirm' => '1']);
check($admin->status === 200 && str_contains($admin->body, 'limit for this setting is 100%'), 'percentage above the cap is refused');
$admin->get("/admin/financial-settings?calc_product=$pid&calc_currency=GBP&calc_amount=10000&calc_term=12");
check(str_contains($admin->body, 'Total amount repayable'), 'financial settings calculator works');

// ---------------------------------------------------------------- client applies
$client->get('/account/applications/new');
$key = preg_match('/name="submission_key" value="([^"]+)"/', $client->body, $m) ? $m[1] : '';
$form = ['product_id' => $pid, 'company_name' => 'Casey Widgets Ltd', 'business_type' => 'ltd', 'company_number' => '01234567', 'industry' => 'Manufacturing',
    'years_trading' => '2_5', 'annual_turnover' => '450000', 'address' => '1 High Street, Leeds', 'currency' => 'GBP', 'amount' => '20000', 'term_months' => '24',
    'purpose' => 'New machinery', 'consent' => '1', 'action' => 'submit', 'submission_key' => $key];
$client->post('/account/applications/new', $form);
check($client->status === 303, 'application submitted', substr(strip_tags($client->body), 0, 500));
$appId = (int) qv("SELECT id FROM applications WHERE company_name = 'Casey Widgets Ltd'");
check(qv('SELECT status FROM applications WHERE id = ?', [$appId]) === 'submitted', 'status is submitted');
$client->post('/account/applications/new', $form);  // double submission with the same key
check((int) qv("SELECT COUNT(*) FROM applications WHERE company_name = 'Casey Widgets Ltd'") === 1, 'double submission does not duplicate');
$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$client->post("/account/applications/$appId/documents", ['doc_type' => 'bank_statements'], ['document' => ['statement.pdf', $pdf, 'application/pdf']], "/account/applications/$appId");
check((int) qv('SELECT COUNT(*) FROM documents WHERE application_id = ?', [$appId]) === 1, 'document uploaded');
$client->post("/account/applications/$appId/documents", ['doc_type' => 'other'], ['document' => ['evil.pdf', "%PDF-1.4 /JavaScript (x)\n%%EOF", 'application/pdf']], "/account/applications/$appId");
check((int) qv('SELECT COUNT(*) FROM documents WHERE application_id = ?', [$appId]) === 1, 'PDF with JavaScript refused');
$client->post("/account/applications/$appId/documents", ['doc_type' => 'other'], ['document' => ['fake.png', 'not an image', 'image/png']], "/account/applications/$appId");
check((int) qv('SELECT COUNT(*) FROM documents WHERE application_id = ?', [$appId]) === 1, 'fake image refused');

// ---------------------------------------------------------------- access control
$client->get('/admin/');
check($client->status === 303 || $client->status === 403, 'client cannot use the staff area');
$other = new Browser($B);
make_user(1, 'other@example.com', 'Other Client', 'client', $pw);
$other->login('other@example.com', $pw);
check($other->get("/account/applications/$appId")->status === 404, "another client cannot see this application");
$docId = (int) qv('SELECT id FROM documents WHERE application_id = ?', [$appId]);
check($other->get("/account/documents/$docId")->status === 404, "another client cannot download this document");
$admin->request('POST', "/admin/applications/$appId/notes", ['body' => 'no token']);
check($admin->status === 400, 'POST without CSRF token is refused');

// ---------------------------------------------------------------- document verification and decision
$docs = new Browser($B);
$docs->login('docs@lmd.test', $pw);
$docs->post("/admin/documents/$docId/review", ['review_status' => 'verified', 'customer_note' => '', 'internal_note' => 'Matches bank']);
check(qv('SELECT review_status FROM documents WHERE id = ?', [$docId]) === 'verified', 'reviewer verifies the document');
check($docs->post("/admin/applications/$appId/decision", ['approved_amount' => '15000'], [], "/admin/applications/$appId")->status === 403,
    'document reviewer cannot propose decisions');

$under = new Browser($B);
$under->login('under@lmd.test', $pw);
$decision = ['product_id' => $pid, 'decision_type' => 'partial', 'approved_amount' => '15000', 'term_months' => '24', 'conditions' => '',
    'reason' => 'Affordability supports a smaller amount than requested.', 'action' => 'preview'];
$under->post("/admin/applications/$appId/decision", $decision, [], "/admin/applications/$appId");
check($under->status === 200 && str_contains($under->body, 'Preview (not saved)'), 'decision preview shows the calculation');
$decision['action'] = 'propose';
$under->post("/admin/applications/$appId/decision", $decision, [], "/admin/applications/$appId");
$did = (int) qv("SELECT id FROM approval_decisions WHERE application_id = ? AND status = 'proposed'", [$appId]);
check($did > 0, 'decision proposed');
$pricing = json_decode((string) qv('SELECT pricing FROM approval_decisions WHERE id = ?', [$did]), true);
check(($pricing['fees'][0]['amount'] ?? 0) === 30000 && $pricing['approved'] === 1500000 && $pricing['requested'] === 2000000, 'pricing frozen: 2% fee of £15,000 = £300; requested kept separate');
$under->post("/admin/decisions/$did/issue", ['confirm' => '1'], [], "/admin/applications/$appId");
check($under->status === 403, 'the proposer cannot issue their own decision');
$admin->post("/admin/decisions/$did/issue", ['confirm' => '1'], [], "/admin/applications/$appId");
check(qv('SELECT status FROM applications WHERE id = ?', [$appId]) === 'partially_approved', 'second person issues: partially approved');
$client->get('/account/');
check(str_contains($client->body, '£15,000.00') && str_contains($client->body, '£20,000.00') && str_contains($client->body, 'not money in your account'),
    'customer sees requested and approved amounts separately, and that nothing is paid');

// Settings changes are not applied retroactively.
$ruleId = (int) qv("SELECT id FROM financial_rules WHERE name = 'Standard rate'");
$admin->post("/admin/financial-settings/$ruleId", ['kind' => 'interest', 'name' => 'Standard rate', 'method' => 'percent', 'percent' => '19', 'basis' => 'approved',
    'timing' => 'interest', 'effective_from' => '2026-01-01', 'enabled' => '1', 'confirm' => '1', 'reason' => 'Annual review']);
check((int) qv('SELECT version FROM financial_rules WHERE id = ?', [$ruleId]) === 2 && (int) qv('SELECT COUNT(*) FROM financial_rule_history WHERE rule_id = ?', [$ruleId]) === 2,
    'rule change creates version 2 with history');
$still = json_decode((string) qv('SELECT pricing FROM approval_decisions WHERE id = ?', [$did]), true);
check($still['annual_rate'] === '12.5', 'issued decision keeps its frozen rate after the rule changes');

// ---------------------------------------------------------------- offer, agreement, signing
$client->post("/account/applications/$appId/offer", ['accept' => '1']);
check((int) qv('SELECT COUNT(*) FROM offer_acceptances WHERE decision_id = ?', [$did]) === 1, 'customer accepts the offer');
$admin->post("/admin/applications/$appId/letters", ['kind' => 'agreement'], [], "/admin/applications/$appId");
$agreement = q1("SELECT * FROM generated_documents WHERE application_id = ? AND kind = 'agreement'", [$appId]);
check($agreement !== null && $agreement['status'] === 'draft', 'agreement draft generated');
$admin->get("/admin/letters/{$agreement['id']}");
check(str_starts_with($admin->body, '%PDF-') && $admin->headers['content-type'] === 'application/pdf', 'agreement PDF downloads', substr($admin->body, 0, 40));
$admin->post("/admin/letters/{$agreement['id']}/issue", ['confirm' => '1'], [], "/admin/applications/$appId");
check(qv('SELECT status FROM generated_documents WHERE id = ?', [$agreement['id']]) === 'draft', 'agreement cannot be issued before the template is legally approved');
$admin->post('/admin/settings', ['name' => 'LMD Finance Ltd', 'brand_color' => '#F08A6E', 'default_currency' => 'GBP', 'second_approval_threshold' => '0',
    'require_verified_documents_for_underwriting' => '1', 'require_offer_acceptance_before_agreement' => '1', 'agreement_template_approved' => '1',
    'template_confirmation' => 'APPROVED BY SOLICITOR', 'notify_staff_new_application' => '1']);
check(org_settings(q1('SELECT * FROM organisations WHERE id = 1'))['agreement_template_approved'] === true, 'template approval recorded');
$admin->post("/admin/applications/$appId/letters", ['kind' => 'agreement'], [], "/admin/applications/$appId");
$agreement = q1("SELECT * FROM generated_documents WHERE application_id = ? AND kind = 'agreement' ORDER BY id DESC LIMIT 1", [$appId]);
$admin->post("/admin/letters/{$agreement['id']}/issue", ['confirm' => '1'], [], "/admin/applications/$appId");
check(qv('SELECT status FROM applications WHERE id = ?', [$appId]) === 'agreement_issued', 'agreement issued');
$admin->post("/admin/applications/$appId/status", ['status' => 'agreement_signed', 'reason' => 'Signed copy received by post 20 Oct', 'message' => '']);
check(qv('SELECT status FROM applications WHERE id = ?', [$appId]) === 'agreement_signed', 'agreement signed recorded');
check((int) qv("SELECT COUNT(*) FROM fee_items WHERE application_id = ? AND status = 'pending'", [$appId]) === 1, 'fee record created from the frozen pricing');

// ---------------------------------------------------------------- disbursement with two people
$fin1 = new Browser($B);
$fin1->login('fin1@lmd.test', $pw);
$fin2 = new Browser($B);
$fin2->login('fin2@lmd.test', $pw);
$fin1->post("/admin/applications/$appId/disbursements", ['amount' => '20000', 'scheduled_for' => today()], [], "/admin/applications/$appId");
check((int) qv('SELECT COUNT(*) FROM disbursements WHERE application_id = ?', [$appId]) === 0, 'cannot schedule more than approved');
$fin1->post("/admin/applications/$appId/disbursements", ['amount' => '15000', 'scheduled_for' => today()], [], "/admin/applications/$appId");
$dis = q1('SELECT * FROM disbursements WHERE application_id = ?', [$appId]);
check($dis && (int) $dis['fees_deducted_minor'] === 30000, 'payment scheduled with the £300 fee deducted');
check(qv('SELECT status FROM applications WHERE id = ?', [$appId]) === 'funding_scheduled', 'status funding scheduled');
$fin1->post("/admin/disbursements/{$dis['id']}/paid", ['paid_on' => today(), 'method' => 'faster_payment', 'payment_reference' => 'FP-0001'], [], "/admin/applications/$appId");
$fin1->post("/admin/disbursements/{$dis['id']}/confirm", ['confirmation_source' => 'bank_statement', 'bank_checked' => '1'], [], "/admin/applications/$appId");
check($fin1->status === 403, 'the recorder cannot confirm their own payment');
$fin2->post("/admin/disbursements/{$dis['id']}/confirm", ['confirmation_source' => 'bank_statement', 'bank_checked' => '1'], [], "/admin/applications/$appId");
check(qv('SELECT status FROM disbursements WHERE id = ?', [$dis['id']]) === 'confirmed', 'second person confirms the payment');
check(qv('SELECT status FROM applications WHERE id = ?', [$appId]) === 'fully_disbursed', 'status fully disbursed only after confirmation');
check(qv("SELECT status FROM fee_items WHERE application_id = ?", [$appId]) === 'paid', 'deducted fee marked paid');
check((int) qv("SELECT COUNT(*) FROM generated_documents WHERE application_id = ? AND kind = 'disbursement'", [$appId]) === 1, 'disbursement confirmation PDF issued');

// ---------------------------------------------------------------- repayment schedule and repayments
$fin1->post("/admin/applications/$appId/schedule", ['first_due_date' => gmdate('Y-m-d', time() + 30 * 86400)], [], "/admin/applications/$appId");
$sched = q1("SELECT * FROM repayment_schedules WHERE application_id = ? AND status = 'active'", [$appId]);
check($sched && (int) qv('SELECT COUNT(*) FROM instalments WHERE schedule_id = ?', [$sched['id']]) === 24, 'repayment schedule of 24 instalments');
$first = (int) qv('SELECT amount_minor FROM instalments WHERE schedule_id = ? AND number = 1', [$sched['id']]);
$expected = pricing_quote(q1('SELECT * FROM loan_products WHERE id = ?', [$pid]), [array_merge(q1('SELECT * FROM financial_rules WHERE id = ?', [$ruleId]), ['percent' => '12.5'])],
    1500000, 1500000, 24, 'GBP')['schedule'][0]['amount'];
check($first === $expected, "instalment uses the frozen 12.5% rate (got $first, expected $expected)");
$repay = ['amount' => number_format($first / 100, 2, '.', ''), 'received_on' => today(), 'method' => 'bank_transfer', 'provider_reference' => 'BACS-77', 'confirmation_source' => 'bank_statement'];
$fin1->post("/admin/applications/$appId/repayments", $repay, [], "/admin/applications/$appId");
$fin1->post("/admin/applications/$appId/repayments", $repay, [], "/admin/applications/$appId");
check((int) qv('SELECT COUNT(*) FROM repayments WHERE application_id = ?', [$appId]) === 1, 'the same payment reference is recorded once');
$pos = loan_position($appId);
check($pos['instalments'][0]['state'] === 'paid' && $pos['repaid'] === $first, 'first instalment shown as paid');
$client->get("/account/applications/$appId");
check(str_contains($client->body, 'Receipt') && str_contains($client->body, 'Funds actually disbursed'), 'customer sees the receipt and the disbursed amount');

// ---------------------------------------------------------------- staff pages, reports, audit
foreach (['/admin/', '/admin/applications', '/admin/approvals', '/admin/documents/', '/admin/clients', '/admin/products', "/admin/products/$pid",
             '/admin/disbursements', '/admin/repayments', '/admin/fees', '/admin/letters', '/admin/messages', '/admin/notifications', '/admin/enquiries',
             '/admin/marketing', '/admin/marketing/new', '/admin/marketing/suppressions', '/admin/reports/', '/admin/financial-settings', "/admin/financial-settings/$ruleId",
             '/admin/content/', '/admin/staff', '/admin/settings', '/admin/audit', '/admin/emails', "/admin/applications/$appId", "/admin/documents/$docId/review",
             '/admin/content/preview/index', '/account/security'] as $p) {
    check($admin->get($p)->status === 200, "admin page $p loads", "status {$admin->status}: " . substr(strip_tags($admin->body), 0, 200));
}
check(str_starts_with($admin->get('/admin/applications?export=csv')->body, 'reference,'), 'CSV export works');
$auditor = new Browser($B);
$auditor->login('audit@lmd.test', $pw);
check($auditor->get('/admin/audit')->status === 200 && str_contains($auditor->body, 'hash chain intact'), 'auditor sees an intact audit chain');
check($auditor->post('/admin/staff', ['full_name' => 'X', 'email' => 'x@lmd.test', 'role' => 'admin'], [], '/admin/')->status === 403, 'auditor cannot manage staff');
[$ok] = verify_chain();
check($ok, 'audit chain verifies');
foreach (['/account/', '/account/applications', '/account/documents', '/account/messages', '/account/help', '/account/profile', "/account/applications/$appId/offer"] as $p) {
    check($client->get($p)->status === 200, "client page $p loads", "status {$client->status}");
}

// ---------------------------------------------------------------- two-step verification
$client->post('/account/security/mfa/start', [], [], '/account/security');
$client->get('/account/security');
check(str_contains($client->body, '<svg') && preg_match('/class="key">([A-Z2-7]+)</', $client->body, $km) === 1, 'two-step set-up shows a QR code and key');
$secret = $km[1] ?? '';
$client->post('/account/security/mfa/confirm', ['code' => totp_at($secret, intdiv(time(), 30))], [], '/account/security');
check(qv("SELECT totp_enabled_at FROM users WHERE email = 'casey@example.com'") !== null, 'two-step verification turned on');
$fresh = new Browser($B);
$fresh->login('casey@example.com', 'plum orbit candle river');
check(str_ends_with($fresh->location, '/account/login/code'), 'sign-in now asks for a code');

// ---------------------------------------------------------------- platform console and a second organisation
$owner = new Browser($B);
$owner->login('owner@platform.test', $pw, '/console');
check(str_ends_with($owner->location, '/console/platform/'), 'platform owner signs in on the console', $owner->location);
check($owner->get('/console/platform/')->status === 200, 'console dashboard loads');
$owner->post('/console/platform/plans', ['code' => 'starter', 'name' => 'Starter', 'price' => '99.00', 'currency' => 'GBP', 'billing_period' => 'month',
    'trial_days' => '14', 'max_staff' => '5', 'platform_fee_percent' => '0.5', 'active' => '1']);
$planId = (int) qv("SELECT id FROM plans WHERE code = 'starter'");
check($planId > 0, 'plan created');
$owner->post('/console/platform/organisations/new', ['name' => 'Acme Finance', 'slug' => 'acme', 'currency' => 'EUR', 'admin_name' => 'Ann Acme',
    'admin_email' => 'ann@acme.test', 'plan_id' => (string) $planId]);
$acme = q1("SELECT * FROM organisations WHERE slug = 'acme'");
check($acme !== null && qv('SELECT status FROM subscriptions WHERE org_id = ?', [$acme['id']]) === 'trial', 'organisation created with a trial subscription');
$invite = outbox_link($storage, 'ann@acme.test', '#http://[^\s"<]+/account/reset/[A-Za-z0-9_-]+#');
check($invite !== null && str_contains($invite, '/o/acme/account/reset/'), 'invite link points at the organisation address', (string) $invite);
$ann = new Browser($B);
$ann->post(parse_url((string) $invite, PHP_URL_PATH), ['password' => 'saffron tunnel meadow lamp', 'confirm' => 'saffron tunnel meadow lamp']);
$ann->login('ann@acme.test', 'saffron tunnel meadow lamp', '/o/acme');
check(str_ends_with($ann->location, '/o/acme/admin/'), 'acme admin signs in at /o/acme', $ann->location);
check($ann->get("/o/acme/admin/applications/$appId")->status === 404, "acme admin cannot open LMD's application");
check($ann->get('/o/acme/admin/applications')->status === 200 && !str_contains($ann->body, 'Casey Widgets'), "acme's list does not include LMD data");
check($admin->get('/o/acme/admin/')->status === 303, 'an LMD session is not valid inside acme');
check($ann->get('/admin/')->status === 303, 'an acme session is not valid inside LMD');
check($owner->get("/console/platform/organisations/{$acme['id']}")->status === 200, 'console shows the organisation');
check($owner->get('/console/admin/')->status !== 200, 'console has no organisation staff area');
check($admin->get('/console/platform/')->status === 303, 'organisation staff cannot use the console');

// ---------------------------------------------------------------- web cron is closed without a token
check($anon->get('/cron/backup')->status === 404, 'cron endpoint is closed without a token');
check(str_contains(run_job('backup'), 'backup written'), 'backup job writes a database copy');

$pid = proc_get_status($server)['pid'];
if (DIRECTORY_SEPARATOR === '\\') { exec("taskkill /F /T /PID $pid 2>NUL"); } else { proc_terminate($server); }
echo "\n$pass passed, $fail failed.\n";
if ($fail) {
    echo "Server log: $storage/server.log\n";
    echo "App errors:\n" . @file_get_contents("$storage/logs/error.log");
}
exit($fail ? 1 : 0);
