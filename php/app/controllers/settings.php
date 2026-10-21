<?php
/**
 * Staff: loan products, Financial settings (percentage & financial rules, versioned with history), reports and
 * organisation settings. Rule changes apply to new decisions only: decisions freeze the pricing they were made with.
 */
declare(strict_types=1);

// Which timings and bases make sense for which kind of rule. There is no "pay before approval" option at all.
const KIND_TIMINGS = [
    'interest' => ['interest'],
    'late_charge' => ['on_overdue_instalment'],
    'commission' => ['not_charged_to_customer', 'deducted_from_disbursement', 'added_to_loan'],
];
const FEE_TIMINGS = ['deducted_from_disbursement', 'added_to_loan', 'with_first_instalment', 'not_charged_to_customer'];
const KIND_BASES = ['interest' => ['approved'], 'late_charge' => ['instalment', 'outstanding']];
const RULE_FIELDS = ['kind', 'name', 'description', 'method', 'percent', 'fixed_minor', 'basis', 'product_ids', 'currencies', 'timing', 'conditions',
    'disclosure', 'arrangement_reference', 'effective_from', 'effective_to', 'enabled'];
const PRODUCT_COLS = ['name', 'description', 'finance_type', 'currencies', 'min_amount_minor', 'max_amount_minor', 'min_term_months', 'max_term_months',
    'interest_method', 'repayment_frequency', 'grace_period_months', 'repayment_method', 'eligibility', 'required_docs', 'form_fields',
    'allow_above_requested', 'second_approval_threshold_minor', 'active'];

function money_input(?int $minor): string
{
    return $minor === null ? '' : number_format($minor / 100, 2, '.', '');
}

// ---------------------------------------------------------------- loan products
function read_product(): array
{
    $f = [];
    foreach (['name', 'description', 'finance_type', 'interest_method', 'repayment_frequency', 'repayment_method', 'eligibility', 'form_fields'] as $k) {
        $f[$k] = trim(form($k));
    }
    $errors = [];
    if (mb_strlen($f['name']) < 2 || mb_strlen($f['name']) > 120) {
        $errors['name'] = 'Enter a product name.';
    }
    $f['currencies'] = implode(',', array_values(array_intersect(form_list('currencies'), array_keys(CURRENCIES))));
    if ($f['currencies'] === '') {
        $errors['currencies'] = 'Choose at least one currency.';
    }
    $f['required_docs'] = implode(',', array_values(array_intersect(form_list('required_docs'), array_keys(DOC_TYPES))));
    foreach (['min_amount', 'max_amount'] as $k) {
        $f[$k] = form($k);
        $f[$k . '_minor'] = parse_money(form($k));
        if (!$f[$k . '_minor']) {
            $errors[$k] = 'Enter an amount.';
        }
    }
    foreach (['min_term_months', 'max_term_months', 'grace_period_months'] as $k) {
        $raw = trim(form($k, '0')) ?: '0';
        $f[$k] = (ctype_digit($raw) && (int) $raw <= 600) ? (int) $raw : null;
        if ($f[$k] === null || ($k !== 'grace_period_months' && $f[$k] < 1)) {
            $errors[$k] = 'Enter a number of months.';
        }
    }
    if (!$errors) {
        if ($f['max_amount_minor'] < $f['min_amount_minor']) {
            $errors['max_amount'] = 'The maximum must be at least the minimum.';
        }
        if ($f['max_term_months'] < $f['min_term_months']) {
            $errors['max_term_months'] = 'The maximum must be at least the minimum.';
        }
        if ($f['grace_period_months'] >= $f['min_term_months']) {
            $errors['grace_period_months'] = 'The grace period must be shorter than the shortest term.';
        }
    }
    if (!isset(FINANCE_TYPES[$f['finance_type']])) {
        $f['finance_type'] = 'loans';
    }
    if (!isset(INTEREST_METHODS[$f['interest_method']])) {
        $errors['interest_method'] = 'Choose a method.';
    }
    if (!isset(FREQUENCIES[$f['repayment_frequency']])) {
        $errors['repayment_frequency'] = 'Choose a frequency.';
    }
    $threshold = trim(form('second_approval_threshold'));
    $f['second_approval_threshold'] = $threshold;
    $f['second_approval_threshold_minor'] = $threshold !== '' ? parse_money($threshold) : null;
    if ($threshold !== '' && $f['second_approval_threshold_minor'] === null) {
        $errors['second_approval_threshold'] = 'Enter an amount, or leave empty to use the organisation setting.';
    }
    $f['allow_above_requested'] = form('allow_above_requested') ? 1 : 0;
    $f['active'] = form('active') ? 1 : 0;
    $fields = json_decode($f['form_fields'] ?: '[]', true);
    $ok = is_array($fields) && array_is_list($fields);
    foreach ($ok ? $fields : [] as $x) {
        if (!is_array($x) || empty($x['key']) || empty($x['label']) || !in_array($x['type'] ?? '', FIELD_TYPES, true)
            || strlen((string) $x['key']) > 40 || !ctype_alnum(str_replace('_', '', (string) $x['key']))) {
            $ok = false;
        }
    }
    if ($ok) {
        $f['form_fields'] = json_out($fields);
    } else {
        $errors['form_fields'] = 'Use a JSON list such as [{"key": "vat_registered", "label": "VAT registered?", "type": "yesno", "required": true}].';
    }
    $f['eligibility'] = mb_substr($f['eligibility'], 0, 3000);
    $f['description'] = mb_substr($f['description'], 0, 2000);
    return [$f, $errors];
}

route('GET', '/admin/products', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    return view('admin/products', ['rows' => q("SELECT p.*, (SELECT COUNT(*) FROM applications a WHERE a.product_id = p.id AND a.status != 'draft') AS apps
        FROM loan_products p WHERE p.org_id = ? ORDER BY p.active DESC, p.name", [org_id()])]);
});

$productForm = function (?int $pid = null) {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    $p = null;
    if ($pid) {
        $p = q1('SELECT * FROM loan_products WHERE id = ? AND org_id = ?', [$pid, org_id()]);
        if ($p === null) {
            abort(404);
        }
    }
    $f = $p ?? ['currencies' => current_org()['default_currency'], 'interest_method' => 'amortising', 'repayment_frequency' => 'monthly',
        'grace_period_months' => 0, 'active' => 1, 'form_fields' => '[]', 'required_docs' => 'identity,bank_statements', 'finance_type' => 'loans'];
    if (!isset($f['min_amount'])) {
        $f['min_amount'] = money_input(isset($f['min_amount_minor']) ? (int) $f['min_amount_minor'] : null);
        $f['max_amount'] = money_input(isset($f['max_amount_minor']) ? (int) $f['max_amount_minor'] : null);
        $f['second_approval_threshold'] = money_input(isset($f['second_approval_threshold_minor']) ? (int) $f['second_approval_threshold_minor'] : null);
    }
    $errors = [];
    if (is_post()) {
        require_perm('manage_products');
        [$f, $errors] = read_product();
        if (!$errors) {
            $limit = plan_limit(org_id(), 'max_products');
            if ($p === null && $limit !== null && (int) qv('SELECT COUNT(*) FROM loan_products WHERE org_id = ?', [org_id()]) >= $limit) {
                abort(403, "Your subscription allows up to $limit loan products.");
            }
            $values = array_map(fn($c) => $f[$c], PRODUCT_COLS);
            try {
                if ($p) {
                    x('UPDATE loan_products SET ' . implode(', ', array_map(fn($c) => "$c = ?", PRODUCT_COLS)) . ', updated_at = ? WHERE id = ? AND org_id = ?',
                        [...$values, now(), $p['id'], org_id()]);
                    $pid = (int) $p['id'];
                } else {
                    $pid = x('INSERT INTO loan_products (org_id, ' . implode(', ', PRODUCT_COLS) . ', created_at, updated_at) VALUES (?, '
                        . implode(', ', array_fill(0, count(PRODUCT_COLS), '?')) . ', ?, ?)', [org_id(), ...$values, now(), now()]);
                }
                audit_log('product.saved', 'product', $pid, ['name' => $f['name'], 'active' => $f['active']]);
                flash('Product saved.', 'success');
                return redirect("/admin/products/$pid");
            } catch (PDOException $e) {
                if (!is_unique_violation($e)) {
                    throw $e;
                }
                $errors['name'] = 'You already have a product with this name.';
            }
        }
    }
    return view('admin/product_form', ['f' => $f, 'errors' => $errors, 'product' => $p]);
};
route('GET|POST', '/admin/products/new', $productForm);
route('GET|POST', '/admin/products/{id}', $productForm);

// ---------------------------------------------------------------- financial settings
function read_rule(): array
{
    $v = [];
    foreach (['kind', 'name', 'description', 'method', 'basis', 'timing', 'conditions', 'disclosure', 'arrangement_reference', 'effective_from', 'effective_to'] as $k) {
        $v[$k] = trim(form($k));
    }
    $errors = [];
    if (!isset(RULE_KINDS[$v['kind']])) {
        $errors['kind'] = 'Choose a type.';
        $v['kind'] = 'custom';
    }
    if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 120) {
        $errors['name'] = 'Enter a name.';
    }
    if ($v['kind'] === 'interest') {
        $v['method'] = 'percent';
    }
    if (!in_array($v['method'], ['percent', 'fixed'], true)) {
        $errors['method'] = 'Choose percentage or fixed amount.';
    }
    $v['percent'] = null;
    $v['fixed_minor'] = null;
    if ($v['method'] === 'percent') {
        try {
            $v['percent'] = validate_percent(form('percent'), percent_caps()[$v['kind']]);
        } catch (PricingError $e) {
            $errors['percent'] = $e->getMessage();
        }
    } else {
        $v['fixed_minor'] = parse_money(form('fixed_amount'));
        if ($v['fixed_minor'] === null) {
            $errors['fixed_amount'] = 'Enter an amount, e.g. 150.00.';
        }
    }
    $bases = KIND_BASES[$v['kind']] ?? ['requested', 'approved', 'disbursed'];
    if (!in_array($v['basis'], $bases, true)) {
        $errors['basis'] = 'Choose: ' . implode(', ', array_map(fn($b) => RULE_BASES[$b], $bases)) . '.';
    }
    $timings = KIND_TIMINGS[$v['kind']] ?? FEE_TIMINGS;
    if (!in_array($v['timing'], $timings, true)) {
        $errors['timing'] = 'Choose when this applies: ' . implode('; ', array_map(fn($t) => RULE_TIMINGS[$t], $timings)) . '.';
    }
    if ($v['kind'] === 'commission' && $v['arrangement_reference'] === '') {
        $errors['arrangement_reference'] = 'Commission needs a reference to the documented arrangement it comes from.';
    }
    if ($v['kind'] !== 'interest' && $v['timing'] !== 'not_charged_to_customer' && mb_strlen($v['disclosure']) < 10) {
        $errors['disclosure'] = 'Write the disclosure text customers will see for this charge.';
    }
    foreach (['effective_from', 'effective_to'] as $k) {
        if ($v[$k] !== '' && valid_date($v[$k]) === null) {
            $errors[$k] = 'Use a valid date.';
        }
    }
    if ($v['effective_from'] === '') {
        $errors['effective_from'] = 'Choose the date this takes effect.';
    }
    if ($v['effective_to'] !== '' && $v['effective_from'] !== '' && $v['effective_to'] < $v['effective_from']) {
        $errors['effective_to'] = 'The end date must be after the start date.';
    }
    $v['effective_to'] = $v['effective_to'] ?: null;
    $products = array_map('strval', array_column(q('SELECT id FROM loan_products WHERE org_id = ?', [org_id()]), 'id'));
    $v['product_ids'] = implode(',', array_values(array_intersect(form_list('product_ids'), $products)));
    $v['currencies'] = implode(',', array_values(array_intersect(form_list('currencies'), array_keys(CURRENCIES))));
    $v['enabled'] = form('enabled') ? 1 : 0;
    foreach (['description' => 1000, 'conditions' => 1000, 'disclosure' => 2000, 'arrangement_reference' => 200] as $k => $n) {
        $v[$k] = mb_substr($v[$k], 0, $n);
    }
    return [$v, $errors];
}

function save_rule(array $values, array $user, ?array $existing, string $reason): int
{
    return transaction(function () use ($values, $user, $existing, $reason) {
        $fields = array_map(fn($k) => $values[$k], RULE_FIELDS);
        if ($existing === null) {
            $rid = x('INSERT INTO financial_rules (org_id, ' . implode(', ', RULE_FIELDS) . ', version, created_by, created_at, updated_at) VALUES (?, '
                . implode(', ', array_fill(0, count(RULE_FIELDS), '?')) . ', 1, ?, ?, ?)', [org_id(), ...$fields, $user['id'], now(), now()]);
            x('INSERT INTO financial_rule_history (org_id, rule_id, version, action, old_values, new_values, reason, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [org_id(), $rid, 1, 'created', '{}', json_out($values), $reason, $user['id'], now()]);
            audit_log('financial_rule.created', 'financial_rule', $rid, ['kind' => $values['kind'], 'percent' => $values['percent']]);
            return $rid;
        }
        $old = array_intersect_key($existing, array_flip(RULE_FIELDS));
        $changed = [];
        foreach (RULE_FIELDS as $k) {
            if ((string) $values[$k] !== (string) $old[$k]) {
                $changed[$k] = $values[$k];
            }
        }
        if (!$changed) {
            return (int) $existing['id'];
        }
        $version = (int) $existing['version'] + 1;
        x('UPDATE financial_rules SET ' . implode(', ', array_map(fn($k) => "$k = ?", RULE_FIELDS)) . ', version = ?, updated_by = ?, updated_at = ? WHERE id = ? AND org_id = ?',
            [...$fields, $version, $user['id'], now(), $existing['id'], org_id()]);
        x('INSERT INTO financial_rule_history (org_id, rule_id, version, action, old_values, new_values, reason, changed_by, changed_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [org_id(), $existing['id'], $version, 'updated', json_out(array_intersect_key($old, $changed)), json_out($changed), $reason, $user['id'], now()]);
        audit_log('financial_rule.updated', 'financial_rule', $existing['id'], ['version' => $version, 'fields' => implode(',', array_keys($changed))]);
        return (int) $existing['id'];
    });
}

function rule_row(int $id): array
{
    $r = q1('SELECT * FROM financial_rules WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($r === null) {
        abort(404);
    }
    return $r;
}

route('GET', '/admin/financial-settings', function () {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    if (!can('manage_financial_settings') && !can('view_reports')) {
        abort(403);
    }
    $rules = q('SELECT r.*, u.full_name AS updated_name FROM financial_rules r LEFT JOIN users u ON u.id = COALESCE(r.updated_by, r.created_by)
                WHERE r.org_id = ? ORDER BY r.kind, r.enabled DESC, r.name', [org_id()]);
    $products = q('SELECT * FROM loan_products WHERE org_id = ? ORDER BY name', [org_id()]);
    $calc = null;
    $calcError = null;
    if (arg('calc_product') !== '') {
        $product = null;
        foreach ($products as $p) {
            if ((string) $p['id'] === arg('calc_product')) {
                $product = $p;
            }
        }
        $amount = parse_money(arg('calc_amount'));
        $term = arg_int('calc_term');
        $currency = arg('calc_currency');
        if ($product === null || !$amount || !$term || !isset(CURRENCIES[$currency])) {
            $calcError = 'Choose a product, currency, amount and term.';
        } else {
            try {
                $calc = pricing_quote($product, $rules, $amount, $amount, $term, $currency);
            } catch (PricingError $e) {
                $calcError = $e->getMessage();
            }
        }
    }
    return view('admin/financial_settings', ['rules' => $rules, 'products' => $products, 'caps' => percent_caps(), 'calc' => $calc, 'calc_error' => $calcError]);
});

$ruleForm = function (?int $rid = null) {
    if ($r = need_staff('view_applications')) {
        return $r;
    }
    if (!can('manage_financial_settings') && !can('view_reports')) {
        abort(403);
    }
    $rule = $rid ? rule_row($rid) : null;
    $f = $rule ?? ['kind' => isset(RULE_KINDS[arg('kind')]) ? arg('kind') : 'processing_fee', 'method' => 'percent', 'basis' => 'approved',
        'timing' => arg('kind') === 'interest' ? 'interest' : 'deducted_from_disbursement', 'effective_from' => today(), 'enabled' => 1];
    if ($f['kind'] === 'interest' && !$rule) {
        $f['timing'] = 'interest';
    }
    $f['fixed_amount'] = money_input(isset($f['fixed_minor']) && $f['fixed_minor'] !== null ? (int) $f['fixed_minor'] : null);
    $errors = [];
    if (is_post()) {
        require_perm('manage_financial_settings');
        [$values, $errors] = read_rule();
        $f = $values + ['fixed_amount' => form('fixed_amount')];
        if (!form('confirm')) {
            $errors['confirm'] = 'Tick the box to confirm this setting has been approved under your pricing policy.';
        }
        if (!$errors) {
            $rid = save_rule($values, current_user(), $rule, mb_substr(trim(form('reason')), 0, 500));
            flash('Setting saved. It applies to new decisions only; existing offers and agreements keep their terms.', 'success');
            return redirect("/admin/financial-settings/$rid");
        }
    }
    $history = $rid ? q('SELECT h.*, u.full_name FROM financial_rule_history h JOIN users u ON u.id = h.changed_by WHERE h.rule_id = ? AND h.org_id = ? ORDER BY h.id DESC',
        [$rid, org_id()]) : [];
    foreach ($history as &$h) {
        $h['old'] = json_decode($h['old_values'], true) ?: [];
        $h['new'] = json_decode($h['new_values'], true) ?: [];
    }
    unset($h);
    return view('admin/rule_form', ['f' => $f, 'errors' => $errors, 'rule' => $rule, 'history' => $history,
        'products' => q('SELECT id, name FROM loan_products WHERE org_id = ? ORDER BY name', [org_id()]), 'caps' => percent_caps()]);
};
route('GET|POST', '/admin/financial-settings/new', $ruleForm);
route('GET|POST', '/admin/financial-settings/{id}', $ruleForm);

route('POST', '/admin/financial-settings/{id}/toggle', function (int $id) {
    if ($r = need_staff('manage_financial_settings')) {
        return $r;
    }
    $rule = rule_row($id);
    if (!form('confirm')) {
        flash('Tick the box to confirm.', 'error');
        return redirect('/admin/financial-settings');
    }
    $values = array_intersect_key($rule, array_flip(RULE_FIELDS));
    $values['enabled'] = $rule['enabled'] ? 0 : 1;
    save_rule($values, current_user(), $rule, $values['enabled'] ? 'enabled' : 'disabled');
    flash($values['enabled'] ? 'Setting enabled.' : 'Setting disabled. Existing offers keep their terms.', 'success');
    return redirect('/admin/financial-settings');
});

// ---------------------------------------------------------------- reports
/** Interest portion of confirmed repayments received in the period: allocation replayed per loan, oldest first. */
function interest_received(string $start, string $end): array
{
    $out = [];
    foreach (q("SELECT * FROM repayment_schedules WHERE org_id = ? AND status = 'active'", [org_id()]) as $s) {
        $remaining = array_map(fn($i) => [(int) $i['fees_minor'], (int) $i['interest_minor'], (int) $i['principal_minor']],
            q('SELECT * FROM instalments WHERE schedule_id = ? ORDER BY number', [$s['id']]));
        foreach (q("SELECT * FROM repayments WHERE schedule_id = ? AND status = 'confirmed' ORDER BY received_on, id", [$s['id']]) as $r) {
            $pot = (int) $r['amount_minor'];
            $interest = 0;
            foreach ($remaining as &$parts) {
                for ($idx = 0; $idx < 3; $idx++) {
                    $take = min($pot, $parts[$idx]);
                    $parts[$idx] -= $take;
                    $pot -= $take;
                    if ($idx === 1) {
                        $interest += $take;
                    }
                }
            }
            unset($parts);
            if ($start <= $r['received_on'] && $r['received_on'] <= $end) {
                $out[$s['currency']] = ($out[$s['currency']] ?? 0) + $interest;
            }
        }
    }
    return $out;
}

function build_report(string $start, string $end): array
{
    $o = org_id();
    $rng = [$o, $start, $end];
    $apps = q("SELECT * FROM applications WHERE org_id = ? AND status != 'draft' AND date(submitted_at) BETWEEN ? AND ?", $rng);
    $latest = [];
    foreach (q("SELECT d.* FROM approval_decisions d WHERE d.org_id = ? AND d.status IN ('issued', 'superseded') AND d.issued_at IS NOT NULL
                AND date(d.issued_at) BETWEEN ? AND ?", $rng) as $d) {
        if (!isset($latest[$d['application_id']]) || $d['revision'] > $latest[$d['application_id']]['revision']) {
            $latest[$d['application_id']] = $d;
        }
    }
    $declined = array_filter($apps, fn($a) => $a['status'] === 'declined');
    $decided = count($latest) + count($declined);
    $cur = [];
    $add = function (string $c, string $k, int $n) use (&$cur) {
        $cur[$c][$k] = ($cur[$c][$k] ?? 0) + $n;
    };
    foreach ($apps as $a) {
        $add($a['currency'], 'requested', ((int) $a['amount']) * 100);
    }
    foreach ($latest as $d) {
        $add($d['currency'], 'approved', (int) $d['approved_minor']);
        $add($d['currency'], 'approved_n', 1);
    }
    foreach (q("SELECT currency, SUM(amount_pence) total FROM disbursements WHERE org_id = ? AND status = 'confirmed' AND date(confirmed_at) BETWEEN ? AND ? GROUP BY currency", $rng) as $r) {
        $add($r['currency'], 'disbursed', (int) $r['total']);
    }
    foreach (q("SELECT currency, SUM(amount_minor) total FROM repayments WHERE org_id = ? AND status = 'confirmed' AND received_on BETWEEN ? AND ? GROUP BY currency", $rng) as $r) {
        $add($r['currency'], 'repaid', (int) $r['total']);
    }
    foreach (q('SELECT currency, status, SUM(amount_minor) total FROM fee_items WHERE org_id = ? AND date(created_at) BETWEEN ? AND ? GROUP BY currency, status', $rng) as $r) {
        if ($r['status'] !== 'cancelled') {
            $add($r['currency'], 'fees_invoiced', (int) $r['total']);
        }
        $add($r['currency'], 'fees_' . $r['status'], (int) $r['total']);
    }
    foreach (q("SELECT currency, SUM(amount_minor) total FROM fee_items WHERE org_id = ? AND status = 'paid' AND date(paid_at) BETWEEN ? AND ? GROUP BY currency", $rng) as $r) {
        $add($r['currency'], 'fees_collected', (int) $r['total']);
    }
    foreach (interest_received($start, $end) as $c => $v) {
        $add($c, 'interest_income', $v);
    }
    $book = [];
    $due = 0;
    $paidOnTime = 0;
    $upto = min($end, today());
    foreach (q("SELECT application_id, currency FROM repayment_schedules WHERE org_id = ? AND status = 'active'", [$o]) as $s) {
        $pos = loan_position((int) $s['application_id']);
        $c = $s['currency'];
        foreach (['outstanding_principal' => $pos['outstanding_principal'], 'receivable' => $pos['outstanding_total'], 'overdue' => $pos['overdue_minor'],
                     'overdue_loans' => $pos['overdue_count'] ? 1 : 0, 'loans' => 1] as $k => $n) {
            $book[$c][$k] = ($book[$c][$k] ?? 0) + $n;
        }
        foreach ($pos['instalments'] as $i) {
            if ($start <= $i['due_date'] && $i['due_date'] <= $upto) {
                $due++;
                $paidOnTime += $i['state'] === 'paid' ? 1 : 0;
            }
        }
    }
    $reviewDays = [];
    foreach ($apps as $a) {
        if (isset($latest[$a['id']])) {
            $reviewDays[] = (strtotime($latest[$a['id']]['issued_at']) - strtotime($a['submitted_at'])) / 86400;
        }
    }
    $months = [];
    foreach ($apps as $a) {
        $k = substr((string) $a['submitted_at'], 0, 7);
        $months[$k] = ($months[$k] ?? 0) + 1;
    }
    ksort($months);
    $statuses = [];
    foreach (q("SELECT status, COUNT(*) n FROM applications WHERE org_id = ? AND status != 'draft' GROUP BY status", [$o]) as $r) {
        $statuses[STATUSES[$r['status']]] = (int) $r['n'];
    }
    return [
        'applications' => count($apps), 'approved' => count($latest), 'declined' => count($declined),
        'approval_rate' => $decided ? (int) round(100 * count($latest) / $decided) : null,
        'partial' => count(array_filter($latest, fn($d) => $d['decision_type'] === 'partial')),
        'full' => count(array_filter($latest, fn($d) => $d['decision_type'] === 'full')),
        'conditional' => count(array_filter($latest, fn($d) => $d['decision_type'] === 'conditional')),
        'by_currency' => $cur, 'book' => $book,
        'avg_review_days' => $reviewDays ? round(array_sum($reviewDays) / count($reviewDays), 1) : null,
        'verification_backlog' => (int) qv("SELECT COUNT(*) FROM documents WHERE org_id = ? AND review_status IN ('submitted', 'under_review')", [$o]),
        'instalments_due' => $due, 'instalments_paid' => $paidOnTime,
        'products' => q("SELECT p.name, a.currency, COUNT(a.id) n, SUM(a.amount) requested FROM applications a LEFT JOIN loan_products p ON p.id = a.product_id
            WHERE a.org_id = ? AND a.status != 'draft' AND date(a.submitted_at) BETWEEN ? AND ? GROUP BY p.name, a.currency ORDER BY n DESC", $rng),
        'staff' => q("SELECT u.full_name, u.role, (SELECT COUNT(*) FROM applications a WHERE a.assigned_to = u.id AND a.status NOT IN
            ('draft', 'declined', 'withdrawn', 'cancelled', 'fully_disbursed')) AS open_assigned, (SELECT COUNT(*) FROM approval_decisions d WHERE d.proposed_by = u.id
            AND date(d.proposed_at) BETWEEN ? AND ?) AS proposed, (SELECT COUNT(*) FROM document_reviews r WHERE r.reviewer_id = u.id AND date(r.created_at) BETWEEN ? AND ?)
            AS reviews FROM users u WHERE u.org_id = ? AND u.role != 'client' AND u.is_active = 1 ORDER BY u.full_name", [$start, $end, $start, $end, $o]),
        'months' => $months, 'statuses' => $statuses,
    ];
}

route('GET', '/admin/reports/', function () {
    if ($r = need_staff('view_reports')) {
        return $r;
    }
    [$start, $end] = date_range();
    $data = build_report($start, $end);
    if (arg('export') === 'csv') {
        $rows = [];
        foreach ($data['by_currency'] as $c => $v) {
            ksort($v);
            foreach ($v as $k => $n) {
                $rows[] = [$c, $k, $n, str_ends_with($k, '_n') ? $n : money($n, $c)];
            }
        }
        foreach ($data['book'] as $c => $v) {
            ksort($v);
            foreach ($v as $k => $n) {
                $rows[] = [$c, "book_$k", $n, str_ends_with($k, 'loans') ? $n : money($n, $c)];
            }
        }
        return csv_response("report-$start-to-$end.csv", ['currency', 'measure', 'minor_units_or_count', 'formatted'], $rows);
    }
    return view('admin/reports', ['r' => $data, 'start' => $start, 'end' => $end]);
});
route('GET', '/admin/reports', fn() => redirect('/admin/reports/'));

// ---------------------------------------------------------------- organisation settings
route('GET|POST', '/admin/settings', function () {
    if ($r = need_staff('manage_org_settings')) {
        return $r;
    }
    $org = current_org();
    $s = org_settings();
    $errors = [];
    if (is_post()) {
        $name = mb_substr(trim(form('name')), 0, 120);
        $colour = trim(form('brand_color'));
        $currency = form('default_currency');
        if (mb_strlen($name) < 2) {
            $errors['name'] = "Enter the organisation's name.";
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $colour)) {
            $errors['brand_color'] = 'Use a colour such as #F08A6E.';
        }
        if (!isset(CURRENCIES[$currency])) {
            $errors['default_currency'] = 'Choose a currency.';
        }
        $threshold = parse_money(form('second_approval_threshold', '0') ?: '0');
        if ($threshold === null) {
            $errors['second_approval_threshold'] = 'Enter an amount (0 = every approval needs a second person).';
        }
        $limits = [];
        $caps = platform_caps();
        foreach (RULE_KINDS as $k => $_) {
            $raw = trim(form("max_$k"));
            if ($raw !== '') {
                try {
                    $limits[$k] = validate_percent($raw, $caps[$k]);
                } catch (PricingError $e) {
                    $errors["max_$k"] = "Between 0 and the platform limit of {$caps[$k]}%.";
                }
            }
        }
        $emails = trim(form('staff_notification_emails'));
        if ($emails !== '' && array_filter(explode(',', $emails), fn($x) => !str_contains($x, '@'))) {
            $errors['staff_notification_emails'] = 'Separate addresses with commas.';
        }
        $templateApproved = (bool) form('agreement_template_approved');
        if ($templateApproved && !$s['agreement_template_approved'] && trim(form('template_confirmation')) !== 'APPROVED BY SOLICITOR') {
            $errors['agreement_template_approved'] = 'Type APPROVED BY SOLICITOR to confirm a qualified lawyer has approved your agreement template.';
        }
        if (!$errors) {
            x('UPDATE organisations SET name = ?, brand_color = ?, default_currency = ? WHERE id = ?', [$name, strtoupper($colour), $currency, org_id()]);
            update_org_settings(org_id(), [
                'require_verified_documents_for_underwriting' => (bool) form('require_verified_documents_for_underwriting'),
                'require_offer_acceptance_before_agreement' => (bool) form('require_offer_acceptance_before_agreement'),
                'second_approval_threshold_minor' => $threshold,
                'notify_staff_new_application' => (bool) form('notify_staff_new_application'),
                'notify_staff_client_message' => (bool) form('notify_staff_client_message'),
                'staff_notification_emails' => mb_substr($emails, 0, 500),
                'email_signature' => mb_substr(trim(form('email_signature')), 0, 300),
                'agreement_template_approved' => $templateApproved,
                'max_percent' => (object) $limits,
            ]);
            audit_log('org.settings_updated', 'organisation', org_id(), ['template_approved' => $templateApproved, 'threshold_minor' => $threshold,
                'limits' => implode(',', array_map(fn($k, $v) => "$k=$v", array_keys($limits), $limits))]);
            flash('Settings saved.', 'success');
            return redirect('/admin/settings');
        }
    }
    return view('admin/org_settings', ['the_org' => $org, 's' => $s, 'errors' => $errors, 'caps' => platform_caps()]);
});

route('POST', '/admin/settings/logo', function () {
    if ($r = need_staff('manage_org_settings')) {
        return $r;
    }
    $file = $_FILES['logo'] ?? null;
    $data = ($file && $file['error'] === UPLOAD_ERR_OK && $file['size'] <= 1024 * 1024) ? (string) file_get_contents($file['tmp_name']) : '';
    $info = $data !== '' ? @getimagesizefromstring($data) : false;
    $img = ($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) && $info[0] * $info[1] <= 20000000) ? @imagecreatefromstring($data) : false;
    if (!$img) {
        flash('Choose a PNG or JPG logo up to 1 MB.', 'error');
        return redirect('/admin/settings');
    }
    // Re-encoded: nothing from the uploaded file survives but the pixels.
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, 1200 / $w, 400 / $h);
    $out = imagecreatetruecolor(max(1, (int) ($w * $scale)), max(1, (int) ($h * $scale)));
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
    $folder = storage_dir() . '/branding/' . org_id();
    if (!is_dir($folder)) {
        mkdir($folder, 0700, true);
    }
    $name = bin2hex(random_bytes(12)) . '.png';
    imagepng($out, "$folder/$name");
    x('UPDATE organisations SET logo_file = ? WHERE id = ?', [$name, org_id()]);
    audit_log('org.logo_updated', 'organisation', org_id());
    flash('Logo updated.', 'success');
    return redirect('/admin/settings');
});
