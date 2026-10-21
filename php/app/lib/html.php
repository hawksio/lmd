<?php
/** Reusable pieces of page markup (the Flask edition's _macros.html). Every value is escaped. */
declare(strict_types=1);

function field_error(array $errors, string $name): string
{
    return isset($errors[$name]) ? '<p class="field-error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
}

function described(array $errors, string $name, ?string $hint): string
{
    if (isset($errors[$name])) {
        return ' aria-invalid="true" aria-describedby="' . e($name) . '-error"';
    }
    return $hint ? ' aria-describedby="' . e($name) . '-hint"' : '';
}

/** Text input. Options: type, required, autocomplete, hint, money, cls, maxlength, value. */
function field_input(string $name, string $label, array $values, array $errors, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $value = array_key_exists('value', $o) ? $o['value'] : ($values[$name] ?? '');
    $h = '<div class="' . e($o['cls'] ?? '') . '"><label for="' . e($name) . '">' . e($label) . (!empty($o['required']) ? ' <span class="muted">*</span>' : '')
        . '</label><input id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value ?? '') . '"'
        . (!empty($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '')
        . (!empty($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '')
        . ($type === 'number' ? ' inputmode="numeric" min="0" step="1"' : '') . (!empty($o['money']) ? ' inputmode="decimal"' : '')
        . described($errors, $name, $o['hint'] ?? null) . '>';
    if (!empty($o['hint'])) {
        $h .= '<p class="hint" id="' . e($name) . '-hint">' . e($o['hint']) . '</p>';
    }
    return $h . field_error($errors, $name) . '</div>';
}

function field_textarea(string $name, string $label, array $values, array $errors, array $o = []): string
{
    return '<div class="' . e($o['cls'] ?? '') . '"><label for="' . e($name) . '">' . e($label) . (!empty($o['required']) ? ' <span class="muted">*</span>' : '')
        . '</label><textarea id="' . e($name) . '" name="' . e($name) . '" rows="' . (int) ($o['rows'] ?? 4) . '"'
        . (!empty($o['maxlength']) ? ' maxlength="' . (int) $o['maxlength'] . '"' : '') . described($errors, $name, $o['hint'] ?? null) . '>'
        . e($values[$name] ?? '') . '</textarea>' . (!empty($o['hint']) ? '<p class="hint" id="' . e($name) . '-hint">' . e($o['hint']) . '</p>' : '')
        . field_error($errors, $name) . '</div>';
}

function field_select(string $name, string $label, array $options, array $values, array $errors, array $o = []): string
{
    $current = (string) ($values[$name] ?? '');
    $h = '<div class="' . e($o['cls'] ?? '') . '"><label for="' . e($name) . '">' . e($label) . (!empty($o['required']) ? ' <span class="muted">*</span>' : '')
        . '</label><select id="' . e($name) . '" name="' . e($name) . '"' . described($errors, $name, $o['hint'] ?? null) . '>';
    if ($o['blank'] ?? true) {
        $h .= '<option value="">Choose…</option>';
    }
    foreach ($options as $k => $text) {
        $h .= '<option value="' . e($k) . '"' . ((string) $k === $current ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    $h .= '</select>';
    if (!empty($o['hint'])) {
        $h .= '<p class="hint" id="' . e($name) . '-hint">' . e($o['hint']) . '</p>';
    }
    return $h . field_error($errors, $name) . '</div>';
}

function options_html(array $options, $selected = null, bool $blank = false, string $blankLabel = 'Choose…'): string
{
    $h = $blank ? '<option value="">' . e($blankLabel) . '</option>' : '';
    foreach ($options as $k => $text) {
        $h .= '<option value="' . e($k) . '"' . ((string) $k === (string) $selected ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    return $h;
}

function checked($v): string
{
    return $v ? ' checked' : '';
}

function badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(STATUSES[$status] ?? $status) . '</span>';
}

function review_badge(string $status): string
{
    return '<span class="tag-sm tag-' . e($status) . '">' . e(DOC_STATUSES[$status] ?? $status) . '</span>';
}

function fee_badge(string $status): string
{
    return '<span class="tag-sm tag-fee-' . e($status) . '">' . e(FEE_STATUSES[$status] ?? $status) . '</span>';
}

function pager(int $page, int $pages): string
{
    if ($pages <= 1) {
        return '';
    }
    $h = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) {
        $h .= '<a class="btn btn-outline btn-sm" href="?' . e(pager_args(['page' => $page - 1])) . '">Previous</a>';
    }
    $h .= "<span>Page $page of $pages</span>";
    if ($page < $pages) {
        $h .= '<a class="btn btn-outline btn-sm" href="?' . e(pager_args(['page' => $page + 1])) . '">Next</a>';
    }
    return $h . '</nav>';
}

function daterange(string $start, string $end): string
{
    return '<div><label for="from">From</label><input id="from" name="from" type="date" value="' . e($start) . '"></div>'
        . '<div><label for="to">To</label><input id="to" name="to" type="date" value="' . e($end) . '"></div>';
}

/** Customer-facing progress. "approved" and "disbursed" stand for any approval / disbursement status. */
function timeline(array $app, array $history): string
{
    $seen = array_column($history, 'status');
    $h = '<ol class="timeline">';
    if (in_array($app['status'], ['declined', 'withdrawn', 'cancelled'], true)) {
        foreach ($history as $row) {
            $bad = in_array($row['status'], ['declined', 'withdrawn', 'cancelled'], true);
            $h .= '<li class="' . ($bad ? 'bad' : 'done') . '"><span class="dot"></span><div><strong>' . e(STATUSES[$row['status']] ?? $row['status'])
                . '</strong><small>' . e(fmt_date($row['created_at'])) . '</small></div></li>';
        }
        return $h . '</ol>';
    }
    foreach (PROGRESS as $step) {
        $members = $step === 'approved' ? APPROVED : ($step === 'disbursed' ? ['partially_disbursed', 'fully_disbursed'] : [$step]);
        $reached = (bool) array_intersect($members, $seen);
        $now = in_array($app['status'], $members, true)
            || ($step === 'underwriting' && in_array($app['status'], ['documents_required', 'document_verification', 'info_requested'], true));
        $when = '';
        foreach ($history as $row) {
            if (in_array($row['status'], $members, true)) {
                $when = fmt_date($row['created_at']);
            }
        }
        $label = $now ? STATUSES[$app['status']] : (PROGRESS_LABELS[$step] ?? STATUSES[$step] ?? $step);
        $h .= '<li class="' . ($now ? 'now' : ($reached ? 'done' : '')) . '"><span class="dot"></span><div><strong>' . e($label) . '</strong><small>'
            . e($when) . (!$reached && !$now ? 'Not yet' : '') . '</small></div></li>';
    }
    return $h . '</ol>';
}

/** Requested, approved, scheduled and paid are always separate figures. */
function financing_html(array $s): string
{
    $c = $s['currency'];
    $h = '<dl class="facts finance-facts"><dt>Financing requested</dt><dd>' . e(money($s['requested'], $c)) . '</dd><dt>Financing approved</dt><dd>';
    if ($s['approved']) {
        $h .= e(money($s['approved'], $c));
        if ($s['approved'] < $s['requested']) {
            $h .= ' <span class="tag-sm">' . e(money($s['requested'] - $s['approved'], $c)) . ' not approved</span>';
        }
    } else {
        $h .= 'No approval decision yet';
    }
    $h .= '</dd>';
    if ($s['decision']) {
        $h .= '<dt>Approval decision</dt><dd>' . e(['full' => 'Fully approved', 'partial' => 'Partially approved', 'conditional' => 'Conditional approval'][$s['decision']['decision_type']]) . '</dd>';
    }
    $open = array_filter($s['conditions'], fn($x) => $x['status'] === 'outstanding');
    $h .= '<dt>Conditions outstanding</dt><dd>' . ($open ? e(implode('; ', array_column($open, 'text'))) : 'None') . '</dd>'
        . '<dt>Agreement</dt><dd>' . e($s['agreement_status']) . '</dd>'
        . '<dt>Scheduled for payment</dt><dd>' . e(money($s['scheduled'], $c)) . '</dd>'
        . '<dt>Funds actually disbursed</dt><dd>' . e(money($s['disbursed'], $c))
        . ($s['fees_deducted'] ? ' <small class="block muted">' . e(money($s['disbursed'] - $s['fees_deducted'], $c)) . ' paid to you after '
            . e(money($s['fees_deducted'], $c)) . ' fees deducted</small>' : '') . '</dd>'
        . '<dt>Repaid</dt><dd>' . e(money($s['repaid'], $c)) . '</dd>';
    if ($s['outstanding_principal'] !== null) {
        $h .= '<dt>Outstanding principal</dt><dd>' . e(money($s['outstanding_principal'], $c)) . '</dd>';
    }
    if ($s['next_due']) {
        $h .= '<dt>Next repayment</dt><dd>' . e(money($s['next_due']['unpaid_minor'], $c)) . ' due ' . e($s['next_due']['due_date']) . '</dd>';
    }
    $h .= '</dl>';
    if ($s['approved'] && !$s['disbursed']) {
        $h .= '<p class="notice small">Approved financing is not money in your account. Nothing has been paid out yet.</p>';
    }
    return $h;
}

/** The pricing breakdown frozen on a decision. */
function breakdown_html(array $q, bool $showSchedule = true): string
{
    $c = $q['currency'];
    $m = fn($v) => e(money($v, $c));
    $h = '<dl class="facts"><dt>Amount approved</dt><dd>' . $m($q['approved'])
        . ($q['unapproved'] ? ' <small class="block muted">of ' . $m($q['requested']) . ' requested</small>' : '') . '</dd>'
        . '<dt>Term</dt><dd>' . e($q['term_months']) . ' months, ' . e($q['instalments']) . ' ' . e(strtolower(FREQUENCIES[$q['product']['repayment_frequency']])) . ' payments</dd>'
        . '<dt>Interest rate</dt><dd>' . e($q['annual_rate']) . '% a year (' . ($q['product']['interest_method'] === 'amortising' ? 'reducing balance' : 'flat rate') . ')'
        . ($q['interest'] ? '<small class="block muted">' . e($q['interest']['name']) . '</small>' : '') . '</dd>';
    foreach ($q['fees'] as $f) {
        $h .= '<dt>' . e($f['name']) . '</dt><dd>' . $m($f['amount']) . '<small class="block muted">'
            . ($f['method'] === 'percent' ? e($f['percent']) . '% of the ' . e(strtolower(RULE_BASES[$f['basis']])) . ' (' . $m($f['basis_amount']) . ')' : 'Fixed amount')
            . ' · ' . e(RULE_TIMINGS[$f['timing']]) . '</small>' . ($f['disclosure'] ? '<small class="block">' . e($f['disclosure']) . '</small>' : '') . '</dd>';
    }
    $h .= '<dt>Paid to you</dt><dd>' . $m($q['net_advance']) . ($q['fees_deducted'] ? '<small class="block muted">after ' . $m($q['fees_deducted']) . ' deducted fees</small>' : '') . '</dd>'
        . '<dt>Regular payment</dt><dd>' . $m($q['regular_payment']) . '</dd><dt>Total interest</dt><dd>' . $m($q['total_interest']) . '</dd>'
        . '<dt>Total fees</dt><dd>' . $m($q['total_fees']) . '</dd><dt>Total amount repayable</dt><dd><strong>' . $m($q['total_repayable']) . '</strong></dd>'
        . '<dt>Total charge for credit</dt><dd>' . $m($q['total_charge_for_credit']) . '</dd>'
        . '<dt>APR (calculated)</dt><dd>' . e($q['apr_calculated'] ?? 'n/a') . '%<small class="block muted">Calculated from the payments above</small></dd></dl>';
    if ($q['disclosures']) {
        $h .= '<h3 class="small-h">Other charges and disclosures</h3><ul class="plain-list">';
        foreach ($q['disclosures'] as $d) {
            $h .= '<li><strong>' . e($d['name']) . '</strong>: ' . ($d['method'] === 'percent' ? e($d['percent']) . '% of the ' . e(strtolower(RULE_BASES[$d['basis']])) : $m($d['fixed']))
                . ' · ' . e(RULE_TIMINGS[$d['timing']]) . ($d['disclosure'] ? '. ' . e($d['disclosure']) : '') . '</li>';
        }
        $h .= '</ul>';
    }
    if ($showSchedule) {
        $h .= '<details class="inline-details"><summary>Repayment schedule (' . e($q['instalments']) . ' payments)</summary><div class="table-wrap"><table class="data compact">'
            . '<thead><tr><th>No.</th><th>Principal</th><th>Interest</th><th>Fees</th><th>Payment</th><th>Balance after</th></tr></thead><tbody>';
        foreach ($q['schedule'] as $r) {
            $h .= '<tr><td>' . e($r['number']) . '</td><td>' . $m($r['principal']) . '</td><td>' . $m($r['interest']) . '</td><td>' . $m($r['fees']) . '</td><td>'
                . $m($r['amount']) . '</td><td>' . $m($r['balance']) . '</td></tr>';
        }
        $h .= '</tbody></table></div><p class="hint">Due dates are set from the date funds are paid out.</p></details>';
    }
    return $h;
}

/** A small POST form with CSRF token. $body is trusted markup built by the caller. */
function post_form(string $action, string $body, string $class = 'inline-form'): string
{
    return '<form method="post" action="' . e(url($action)) . '" class="' . e($class) . '">' . csrf_field() . $body . '</form>';
}

function unread_notifications(): int
{
    $u = current_user();
    return $u ? (int) qv('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']]) : 0;
}

function text_on(?string $hex): string
{
    $h = ltrim($hex ?: '#F08A6E', '#');
    $ch = function (string $c): float {
        $c = hexdec($c) / 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $lum = 0.2126 * $ch(substr($h, 0, 2)) + 0.7152 * $ch(substr($h, 2, 2)) + 0.0722 * $ch(substr($h, 4, 2));
    return ($lum + 0.05) / (0.0178 + 0.05) > 1.05 / ($lum + 0.05) ? '#112327' : '#FFFFFF';
}
