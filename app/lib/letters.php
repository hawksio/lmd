<?php
/**
 * Branded PDF documents, generated only from data already recorded in the system.
 *
 * - Approval letters and agreements use the exact figures frozen on the issued decision; nothing here invents a rate,
 *   fee or term. Repayment schedules use the frozen terms and the principal actually paid out.
 * - Every PDF is a numbered version with its SHA-256, who generated it and who issued it. Staff-written letters start
 *   as DRAFT (watermarked, never shown to the customer) until issued.
 * - Records of facts that have happened (receipts, disbursement confirmations, schedules) are issued immediately.
 * - An agreement can be issued only once the organisation confirms a qualified lawyer approved its agreement template;
 *   until then agreements are stamped "TEMPLATE NOT LEGALLY REVIEWED".
 * - There is no built-in e-signature. "Agreement signed" is recorded by staff after a genuinely signed copy arrives.
 */
declare(strict_types=1);

const INK = [20, 39, 43];
const MUTED = [86, 103, 106];

function hex_rgb(?string $hex): array
{
    $h = ltrim($hex ?: '#F08A6E', '#');
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

final class Letter extends Pdf
{
    public string $footerText = '';
    public array $accent;

    public function __construct(public string $mark = '')
    {
        parent::__construct();
        $this->accent = hex_rgb(site()['brand_color']);
        $this->headerFn = fn(Pdf $p) => $this->head();
        $this->footerFn = fn(Pdf $p) => $this->foot();
    }

    private function head(): void
    {
        $s = site();
        $org = current_org();
        $logo = ($org && $org['logo_file']) ? storage_dir() . "/branding/{$org['id']}/{$org['logo_file']}" : null;
        if ($logo && is_file($logo)) {
            $this->image(file_get_contents($logo), 20, 13, 16);
        } else {
            $this->textColor(...INK);
            $this->setFont('times', 'B', 16);
            $this->text(20, 21, $s['trading_name'] ?: $s['org_name']);
        }
        $this->setFont('helvetica', '', 8);
        $this->textColor(...MUTED);
        $this->x = 110;
        $this->y = 14;
        $this->multiCell(80, 3.8, implode("\n", [$s->show('legal_name'), $s->show('registered_office'), $s->show('phone'), $s->show('email')]), 'R');
        $this->drawColor(...$this->accent);
        $this->lineWidth = 0.6;
        $this->line(20, 34, 190, 34);
        $this->lineWidth = 0.2;
        $this->y = 40;
        $this->x = 20;
        if ($this->mark !== '') {
            $this->watermark($this->mark, strlen($this->mark) > 10 ? 30 : 60);
        }
        $this->textColor(...INK);
    }

    private function foot(): void
    {
        $s = site();
        $this->y = $this->h - 24;
        $this->drawColor(222, 230, 231);
        $this->line(20, $this->y, 190, $this->y);
        $this->setFont('helvetica', '', 7);
        $this->textColor(...MUTED);
        $this->y += 1.5;
        $this->x = 20;
        $this->multiCell(0, 3.2, $s->show('legal_name') . '. Company registration number ' . $s->show('company_number') . '. ' . $s->show('regulatory_statement'));
        $this->multiCell(0, 3.2, $this->footerText . '  -  Page ' . $this->pageNo() . ' of {nb}');
    }

    public function h1(string $t): void
    {
        $this->setFont('times', 'B', 16);
        $this->textColor(...INK);
        $this->multiCell(0, 7, $t);
        $this->y += 2;
    }

    public function h2(string $t): void
    {
        $this->y += 2;
        $this->setFont('helvetica', 'B', 10.5);
        $this->multiCell(0, 5.5, $t);
    }

    public function para(string $t, float $size = 10): void
    {
        $this->setFont('helvetica', '', $size);
        $this->multiCell(0, 5, $t);
        $this->y += 2;
    }

    public function facts(array $rows): void
    {
        foreach ($rows as [$label, $value]) {
            $y = $this->y;
            $page = $this->pageNo();
            $this->setFont('helvetica', '', 9.5);
            $this->textColor(...MUTED);
            $this->multiCell(62, 5.5, (string) $label);
            $h1 = $this->y;
            if ($this->pageNo() !== $page) {
                $y = $this->tMargin + 22;  // label broke onto a new page: keep the value beside it
            }
            $this->x = 84;
            $this->y = $y;
            $this->textColor(...INK);
            $this->setFont('helvetica', 'B', 9.5);
            $this->multiCell(106, 5.5, ($value === null || $value === '') ? '-' : (string) $value);
            $this->y = max($h1, $this->y);
            $this->drawColor(222, 230, 231);
            $this->line(20, $this->y, 190, $this->y);
            $this->y += 0.8;
        }
        $this->y += 3;
    }

    public function table(array $head, array $rows, array $widths): void
    {
        $this->setFont('helvetica', 'B', 8.5);
        $this->fillColor(240, 244, 244);
        foreach ($head as $i => $h) {
            $this->cell($widths[$i], 6, $h, 'L', true);
        }
        $this->ln(6);
        $this->setFont('helvetica', '', 8.5);
        foreach ($rows as $r) {
            foreach ($r as $i => $v) {
                $this->cell($widths[$i], 5.5, (string) $v);
            }
            $this->ln(5.5);
        }
        $this->y += 3;
    }

    public function notice(string $t): void
    {
        $this->fillColor(253, 239, 234);
        $this->drawColor(...$this->accent);
        $this->setFont('helvetica', 'B', 9);
        $this->multiCell(0, 5, $t, 'L', true, true, 2.5);
        $this->y += 3;
        $this->setFont('helvetica', '', 10);
    }
}

function letter_address(Letter $pdf, array $app, array $client): void
{
    $pdf->setFont('helvetica', '', 10);
    $pdf->multiCell(100, 5, implode("\n", [$client['full_name'], $app['company_name'], $app['address']]));
    $after = $pdf->y;
    $pdf->x = 130;
    $pdf->y = 40;
    $pdf->multiCell(60, 5, 'Date: ' . gmdate('j F Y') . "\nOur ref: " . ref_str((int) $app['id']), 'R');
    $pdf->y = max($after, $pdf->y, 66) + 4;
}

function pricing_rows(array $q): array
{
    $c = $q['currency'];
    $rows = [['Amount requested', money($q['requested'], $c)], ['Amount approved', money($q['approved'], $c)]];
    if ($q['unapproved']) {
        $rows[] = ['Not approved', money($q['unapproved'], $c)];
    }
    $rows[] = ['Term', $q['term_months'] . ' months'];
    $rows[] = ['Interest rate', $q['annual_rate'] . '% a year (' . ($q['product']['interest_method'] === 'amortising' ? 'reducing balance' : 'flat rate') . ')'];
    foreach ($q['fees'] as $f) {
        $basis = $f['method'] === 'percent' ? $f['percent'] . '% of the ' . strtolower(RULE_BASES[$f['basis']]) : 'fixed amount';
        $rows[] = [$f['name'], money($f['amount'], $c) . " ($basis; " . strtolower(RULE_TIMINGS[$f['timing']]) . ')'];
    }
    $rows[] = ['Amount paid to you (after deductions)', money($q['net_advance'], $c)];
    $rows[] = ["{$q['instalments']} {$q['product']['repayment_frequency']} payments of", money($q['regular_payment'], $c)];
    $rows[] = ['Total interest', money($q['total_interest'], $c)];
    $rows[] = ['Total amount repayable', money($q['total_repayable'], $c)];
    $rows[] = ['Total charge for credit', money($q['total_charge_for_credit'], $c)];
    $rows[] = ['APR (calculated)', $q['apr_calculated'] !== null ? $q['apr_calculated'] . '%' : 'n/a'];
    return $rows;
}

function schedule_table(Letter $pdf, array $rows, string $currency, bool $dated = false): void
{
    $pdf->table(['No.', $dated ? 'Due' : '', 'Principal', 'Interest', 'Fees', 'Payment', 'Balance after'],
        array_map(fn($r) => [(string) $r['number'], $r['due_date'] ?? '', money($r['principal'], $currency), money($r['interest'], $currency),
            money($r['fees'], $currency), money($r['amount'], $currency), money($r['balance'], $currency)], $rows),
        [12, 26, 27, 25, 22, 28, 30]);
}

function decision_conditions_text(int $decisionId): array
{
    return array_column(q('SELECT text FROM decision_conditions WHERE decision_id = ? ORDER BY id', [$decisionId]), 'text');
}

function render_letter(string $kind, array $app, array $client, array $staff, int $version, bool $draft, string $body = '',
                       ?array $decision = null, ?int $refId = null): string
{
    $settings = org_settings();
    $mark = ($kind === 'agreement' && !$settings['agreement_template_approved']) ? 'TEMPLATE NOT LEGALLY REVIEWED' : ($draft ? 'DRAFT' : '');
    $pdf = new Letter($mark);
    $ref = ref_str((int) $app['id']);
    $pdf->footerText = "$ref  -  " . LETTER_KINDS[$kind] . " v$version  -  generated " . now() . ' UTC';
    $pdf->addPage();
    $first = first_name($client['full_name']);
    $q = $decision['pricing_data'] ?? null;
    $signature = $settings['email_signature'] ?: site()->show('trading_name');
    if ($kind !== 'agreement') {
        letter_address($pdf, $app, $client);
    }
    switch ($kind) {
        case 'acknowledgement':
            $pdf->h1('We have received your application');
            $pdf->para("Dear $first,");
            $pdf->para("Thank you for your application for {$app['company_name']}, received on " . substr((string) $app['submitted_at'], 0, 10) . ". Your reference is $ref.");
            $pdf->facts([['Amount requested', money(((int) $app['amount']) * 100, $app['currency'])],
                ['Term requested', $app['term_months'] ? $app['term_months'] . ' months' : '']]);
            $pdf->para('This letter is not an offer of finance and does not mean your application has been approved.');
            break;
        case 'info_request':
            $pdf->h1('Request for documents or information');
            $pdf->para("Dear $first,");
            $pdf->para("To continue assessing application $ref, we need the following:");
            $pdf->para($body ?: '(No details entered.)');
            $pdf->para('Please upload documents through your secure portal rather than by email. We will never ask for your password or authentication codes.');
            break;
        case 'approval':
            $pdf->h1(['full' => 'Approval of your application', 'partial' => 'Partial approval of your application',
                'conditional' => 'Conditional approval of your application'][$decision['decision_type']]);
            $pdf->para("Dear $first,");
            $pdf->para($decision['decision_type'] === 'partial'
                ? 'We have approved ' . money($q['approved'], $q['currency']) . ' of the ' . money($q['requested'], $q['currency']) . ' you requested, on the terms below.'
                : 'We have approved your application on the terms below.');
            $pdf->facts(pricing_rows($q));
            $conds = decision_conditions_text((int) $decision['id']);
            if ($conds) {
                $pdf->h2('Conditions');
                $pdf->para(implode("\n", array_map(fn($c) => "- $c", $conds)));
            }
            $pdf->notice('This letter is not a loan agreement and no money has been paid. Funds are paid only after a loan agreement has been issued to you and signed, and any conditions have been met.');
            break;
        case 'decline':
            $pdf->h1('Your application');
            $pdf->para("Dear $first,");
            $pdf->para("Thank you for applying. We are sorry to tell you that we are unable to offer finance on application $ref.");
            if ($body) {
                $pdf->para($body);
            }
            $pdf->para('If you would like to discuss this decision, please contact us. You can also make a complaint if you are unhappy with how your application was handled.');
            break;
        case 'agreement':
            $pdf->h1('Loan agreement');
            if (!$settings['agreement_template_approved']) {
                $pdf->notice('Template not legally reviewed: this document must not be issued or signed until a qualified solicitor has approved the agreement wording and the disclosures required where you operate.');
            }
            $pdf->facts([['Agreement reference', "$ref-A$version"],
                ['Borrower', $app['company_name'] . ' (' . (BUSINESS_TYPES[$app['business_type']] ?? '') . ')'],
                ['Registration number', $app['company_number'] ?: 'Not applicable'], ['Borrower address', $app['address']],
                ['Signatory for the borrower', $client['full_name']], ['Lender', site()->show('legal_name')]]);
            $pdf->h2('1. Key financial information');
            $pdf->facts(pricing_rows($q));
            $pdf->h2('2. Repayment schedule (from the first payment date)');
            schedule_table($pdf, $q['schedule'], $q['currency']);
            $pdf->h2('3. Conditions');
            $conds = decision_conditions_text((int) $decision['id']);
            $pdf->para($conds ? implode("\n", array_map(fn($c) => "- $c", $conds)) : 'None.');
            foreach ($q['disclosures'] as $d) {
                $pdf->para($d['name'] . ': ' . ($d['disclosure'] ?: $d['description']), 9);
            }
            $pdf->h2('4. Terms and conditions');
            $pdf->para("[The full terms and conditions, including repayment, early settlement, default, charges, any right of withdrawal, complaints and all information required by law, must be inserted from the organisation's legally approved template. They are deliberately not generated by this software.]");
            $pdf->h2('5. Signatures');
            $pdf->para('This agreement is not binding until it has been signed by both parties through the signing process confirmed by the lender. A typed name in this document is not a signature.');
            $pdf->facts([['Signed for the borrower', ''], ['Date', ''], ['Signed for the lender', ''], ['Date', '']]);
            break;
        case 'schedule':
            $s = q1('SELECT * FROM repayment_schedules WHERE id = ?', [$refId]);
            $inst = q('SELECT * FROM instalments WHERE schedule_id = ? ORDER BY number', [$refId]);
            $pdf->h1('Repayment schedule');
            $pdf->para('Principal: ' . money($s['principal_minor'], $s['currency']) . ". First payment due {$s['first_due_date']}.");
            $balance = (int) $s['principal_minor'];
            $rows = [];
            foreach ($inst as $i) {
                $balance -= (int) $i['principal_minor'];
                $rows[] = ['number' => $i['number'], 'due_date' => $i['due_date'], 'principal' => $i['principal_minor'], 'interest' => $i['interest_minor'],
                    'fees' => $i['fees_minor'], 'amount' => $i['amount_minor'], 'balance' => $balance];
            }
            schedule_table($pdf, $rows, $s['currency'], true);
            break;
        case 'fee_statement':
            $fees = q('SELECT * FROM fee_items WHERE application_id = ? ORDER BY id', [$app['id']]);
            $pdf->h1('Fee statement');
            $pdf->table(['Fee', 'Amount', 'Status', 'When payable'],
                array_map(fn($f) => [$f['name'], money($f['amount_minor'], $f['currency']), $f['status'], mb_substr($f['payable_when'], 0, 60)], $fees),
                [45, 28, 22, 75]);
            break;
        case 'receipt':
        case 'fee_receipt':
            if ($kind === 'receipt') {
                $r = q1('SELECT * FROM repayments WHERE id = ?', [$refId]);
                [$amount, $cur, $when, $pref, $what] = [$r['amount_minor'], $r['currency'], $r['received_on'], $r['provider_reference'], 'loan repayment'];
            } else {
                $r = q1('SELECT * FROM fee_items WHERE id = ?', [$refId]);
                [$amount, $cur, $when, $pref, $what] = [$r['amount_minor'], $r['currency'], substr((string) $r['paid_at'], 0, 10), $r['payment_reference'], $r['name']];
            }
            $pdf->h1('Payment receipt');
            $pdf->facts([['Received', money($amount, $cur)], ['For', $what], ['Date received', $when], ['Payment reference', $pref]]);
            $pdf->para('This receipt confirms a payment that has been received and checked by our finance team.');
            break;
        case 'disbursement':
            $d = q1('SELECT * FROM disbursements WHERE id = ?', [$refId]);
            $pdf->h1('Disbursement confirmation');
            $pdf->facts([['Principal released', money($d['amount_pence'], $d['currency'])],
                ['Fees deducted from this payment', money($d['fees_deducted_minor'], $d['currency'])],
                ['Amount paid to you', money($d['amount_pence'] - $d['fees_deducted_minor'], $d['currency'])],
                ['Date paid', $d['paid_on']], ['Bank reference', $d['payment_reference']]]);
            $pdf->para('This confirms a payment that has been made and checked against our bank record.');
            break;
    }
    $pdf->y += 4;
    if (in_array($kind, ['acknowledgement', 'info_request', 'approval', 'decline'], true)) {
        $pdf->para('Yours sincerely,');
        $pdf->para($staff['full_name'] . "\n" . $signature);
    }
    return $pdf->output();
}

function store_letter(string $kind, array $app, array $staff, int $version, string $status, string $data, string $title, string $body = '',
                      ?int $decisionId = null, ?int $refId = null): int
{
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    file_put_contents(storage_dir() . '/generated/' . $stored, $data);
    $issued = $status === 'issued';
    return x('INSERT INTO generated_documents (org_id, application_id, kind, version, status, title, body, stored_name, sha256, decision_id, ref_id,
              generated_by, generated_at, issued_by, issued_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [org_id(), $app['id'], $kind, $version, $status, $title, mb_substr($body, 0, 4000), $stored, hash('sha256', $data), $decisionId, $refId,
            $staff['id'], now(), $issued ? $staff['id'] : null, $issued ? now() : null]);
}

function next_letter_version(int $appId, string $kind): int
{
    return (int) qv('SELECT MAX(version) FROM generated_documents WHERE application_id = ? AND kind = ?', [$appId, $kind]) + 1;
}

/** A new DRAFT of a staff-written document. Throws InvalidArgumentException with a message for staff. */
function generate_letter(string $kind, array $app, array $staff, string $extra = ''): int
{
    if (!in_array($kind, STAFF_WRITTEN, true)) {
        throw new InvalidArgumentException('Receipts, schedules and payment confirmations are produced automatically from confirmed records.');
    }
    $client = q1('SELECT * FROM users WHERE id = ?', [$app['user_id']]);
    $decision = null;
    if (in_array($kind, ['approval', 'agreement'], true)) {
        $decision = current_decision((int) $app['id']);
        if ($decision === null) {
            throw new InvalidArgumentException('Approval letters and agreements need an issued approval decision.');
        }
        if ($kind === 'agreement') {
            if (!in_array($app['status'], [...APPROVED, 'agreement_issued'], true)) {
                throw new InvalidArgumentException('An agreement can be prepared once an approval decision has been issued.');
            }
            $accepted = q1('SELECT 1 FROM offer_acceptances WHERE decision_id = ?', [$decision['id']]);
            if (org_settings()['require_offer_acceptance_before_agreement'] && !$accepted) {
                throw new InvalidArgumentException('The customer has not yet accepted the offer in the portal (Organisation settings require it).');
            }
        }
    }
    if ($kind === 'decline' && $app['status'] !== 'declined') {
        throw new InvalidArgumentException('Set the application to Declined, with the reason, before preparing a decline letter.');
    }
    $version = next_letter_version((int) $app['id'], $kind);
    $data = render_letter($kind, $app, $client, $staff, $version, true, $extra, $decision);
    return store_letter($kind, $app, $staff, $version, 'draft', $data, LETTER_KINDS[$kind] . " (v$version)", $extra, $decision['id'] ?? null);
}

/** Issue a record of a confirmed fact straight away (receipt, schedule, disbursement confirmation). */
function generate_record(string $kind, array $app, array $staff, int $refId): int
{
    $client = q1('SELECT * FROM users WHERE id = ?', [$app['user_id']]);
    $version = next_letter_version((int) $app['id'], $kind);
    $data = render_letter($kind, $app, $client, $staff, $version, false, '', null, $refId);
    return store_letter($kind, $app, $staff, $version, 'issued', $data, LETTER_KINDS[$kind] . " (v$version)", '', null, $refId);
}

/** Re-render the draft without the DRAFT mark and make it visible to the client. */
function issue_letter(array $doc, array $app, array $staff): void
{
    if ($doc['kind'] === 'agreement' && !org_settings()['agreement_template_approved']) {
        throw new InvalidArgumentException("Agreements can't be issued until the organisation confirms its agreement template has been legally approved (Organisation settings).");
    }
    $decision = null;
    if ($doc['decision_id']) {
        $decision = current_decision((int) $app['id']);
        if ($decision === null || (int) $decision['id'] !== (int) $doc['decision_id']) {
            throw new InvalidArgumentException('The decision behind this document has been replaced. Generate a new version.');
        }
    }
    $client = q1('SELECT * FROM users WHERE id = ?', [$app['user_id']]);
    $author = q1('SELECT * FROM users WHERE id = ?', [$doc['generated_by']]);
    $data = render_letter($doc['kind'], $app, $client, $author, (int) $doc['version'], false, $doc['body'], $decision);
    file_put_contents(storage_dir() . '/generated/' . $doc['stored_name'], $data);
    x("UPDATE generated_documents SET status = 'superseded' WHERE application_id = ? AND kind = ? AND status = 'issued'", [$app['id'], $doc['kind']]);
    x("UPDATE generated_documents SET status = 'issued', issued_by = ?, issued_at = ?, sha256 = ? WHERE id = ?",
        [$staff['id'], now(), hash('sha256', $data), $doc['id']]);
}
