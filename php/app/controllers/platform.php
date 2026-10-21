<?php
/**
 * Platform-owner console: organisations, plans, subscriptions, invoices and platform limits. It works only with
 * organisation-level facts and aggregate counts; it has no route that reads an organisation's customers,
 * applications, documents or messages. Billing is manual: payments are recorded only once actually received.
 */
declare(strict_types=1);

const RESERVED_SLUGS = ['platform', 'www', 'admin', 'api', 'app', 'mail', 'console', 'o'];

function org_row(int $id): array
{
    $o = q1('SELECT * FROM organisations WHERE id = ?', [$id]);
    if ($o === null) {
        abort(404);
    }
    return $o;
}

/** Where an organisation's portal lives (for display in the console). */
function org_address(array $org): string
{
    return tenant_base_url($org);
}

route('GET', '/platform', fn() => redirect('/platform/'));

route('GET', '/platform/', function () {
    if ($r = need_platform()) {
        return $r;
    }
    $start = valid_date(arg('from')) ?? gmdate('Y-m-01', strtotime('-12 months'));
    $end = valid_date(arg('to')) ?? today();
    $count = fn(string $sql) => array_column(q($sql), 'n', 'status');
    $backups = glob(storage_dir() . '/backups/lmd-backup-*') ?: [];
    return view('platform/dashboard', [
        'orgs' => $count('SELECT status, COUNT(*) n FROM organisations GROUP BY status'),
        'subs' => $count('SELECT status, COUNT(*) n FROM subscriptions GROUP BY status'),
        'revenue' => q("SELECT currency, SUM(plan_amount_minor + usage_fee_minor) total, COUNT(*) n FROM subscription_invoices WHERE status = 'paid'
            AND date(paid_at) BETWEEN ? AND ? GROUP BY currency", [$start, $end]),
        'outstanding' => q("SELECT currency, SUM(plan_amount_minor + usage_fee_minor) total, COUNT(*) n FROM subscription_invoices WHERE status = 'issued' GROUP BY currency"),
        'activity' => q("SELECT o.id, o.name, o.status, (SELECT COUNT(*) FROM applications a WHERE a.org_id = o.id AND a.submitted_at >= ?) AS apps_30d,
            (SELECT MAX(l.created_at) FROM audit_log l WHERE l.org_id = o.id) AS last_activity FROM organisations o ORDER BY apps_30d DESC", [gmdate('Y-m-d', time() - 30 * 86400)]),
        'health' => [
            'database' => qv('PRAGMA quick_check'),
            'migrations' => qv('SELECT MAX(version) FROM schema_migrations'),
            'failed_emails_24h' => (int) qv("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at >= ?", [gmdate('Y-m-d H:i:s', time() - 86400)]),
            'last_backup' => $backups ? gmdate('Y-m-d H:i', max(array_map('filemtime', $backups))) : null,
            'php' => PHP_VERSION,
        ],
        'start' => $start, 'end' => $end,
    ]);
});

route('GET', '/platform/organisations', function () {
    if ($r = need_platform()) {
        return $r;
    }
    $rows = q('SELECT o.*, s.status AS sub_status, p.name AS plan_name FROM organisations o LEFT JOIN subscriptions s ON s.org_id = o.id
               LEFT JOIN plans p ON p.id = s.plan_id ORDER BY o.name');
    foreach ($rows as &$row) {
        $row['usage'] = org_usage((int) $row['id']);
    }
    return view('platform/organisations', ['rows' => $rows]);
});

route('GET|POST', '/platform/organisations/new', function () {
    if ($r = need_platform()) {
        return $r;
    }
    $plans = q('SELECT * FROM plans WHERE active = 1 ORDER BY price_minor');
    $f = ['currency' => 'GBP'];
    $errors = [];
    if (is_post()) {
        foreach (['name', 'slug', 'currency', 'admin_name', 'admin_email', 'plan_id'] as $k) {
            $f[$k] = trim(form($k));
        }
        $f['slug'] = strtolower($f['slug']);
        if (mb_strlen($f['name']) < 2 || mb_strlen($f['name']) > 120) {
            $errors['name'] = "Enter the organisation's name.";
        }
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $f['slug']) || in_array($f['slug'], RESERVED_SLUGS, true)) {
            $errors['slug'] = '2-31 lower-case letters, numbers or hyphens, starting with a letter.';
        } elseif (q1('SELECT 1 FROM organisations WHERE slug = ?', [$f['slug']])) {
            $errors['slug'] = 'That address is taken.';
        }
        if (!isset(CURRENCIES[$f['currency']])) {
            $errors['currency'] = 'Choose a currency.';
        }
        if (mb_strlen($f['admin_name']) < 2 || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $f['admin_email'])) {
            $errors['admin_email'] = "Enter the first administrator's name and email.";
        }
        $plan = null;
        foreach ($plans as $p) {
            if ((string) $p['id'] === $f['plan_id']) {
                $plan = $p;
            }
        }
        if ($plan === null) {
            $errors['plan_id'] = 'Choose a plan (create one under Plans first).';
        }
        if (!$errors) {
            $oid = transaction(function () use ($f, $plan) {
                $oid = create_org($f['slug'], $f['name'], $f['currency']);
                $today = today();
                x("INSERT INTO subscriptions (org_id, plan_id, status, billing_mode, trial_ends_on, current_period_start, current_period_end, created_at, updated_at)
                   VALUES (?,?,?,'manual',?,?,?,?,?)", [$oid, $plan['id'], $plan['trial_days'] ? 'trial' : 'active',
                    $plan['trial_days'] ? gmdate('Y-m-d', time() + $plan['trial_days'] * 86400) : null, $today, period_end($today, $plan['billing_period']), now(), now()]);
                audit_log('platform.org_created', 'organisation', $oid, ['slug' => $f['slug'], 'plan' => $plan['code']]);
                return $oid;
            });
            $org = org_row($oid);
            $wasPlatform = $GLOBALS['__lmd_platform'];
            set_current_org($org);  // the invite is sent as the organisation, with its portal address
            $GLOBALS['__lmd_platform'] = false;
            invite_staff($oid, strtolower($f['admin_email']), $f['admin_name'], 'admin');
            set_current_org(null);
            $GLOBALS['__lmd_platform'] = $wasPlatform;
            flash("Organisation created. {$f['admin_email']} has been emailed a link to set up the administrator account.", 'success');
            return redirect("/platform/organisations/$oid");
        }
    }
    return view('platform/organisation_form', ['f' => $f, 'errors' => $errors, 'plans' => $plans]);
});

route('GET|POST', '/platform/organisations/{id}', function (int $id) {
    if ($r = need_platform()) {
        return $r;
    }
    $org = org_row($id);
    if (is_post()) {
        $action = form('action');
        if (in_array($action, ['suspend', 'activate'], true)) {
            if (!form('confirm')) {
                flash('Tick the box to confirm.', 'error');
            } else {
                x('UPDATE organisations SET status = ? WHERE id = ?', [$action === 'suspend' ? 'suspended' : 'active', $id]);
                audit_log("platform.org_{$action}d", 'organisation', $id);
                flash('Organisation updated.', 'success');
            }
        } elseif ($action === 'add_domain') {
            $host = strtolower(trim(form('host')));
            if (!preg_match('/^[a-z0-9.-]{4,253}$/', $host) || !str_contains($host, '.') || $host === strtolower((string) config('platform_host'))) {
                flash('Enter a host name such as portal.example.com.', 'error');
            } elseif (q1('SELECT 1 FROM organisation_domains WHERE host = ?', [$host])) {
                flash('That host is already in use.', 'error');
            } else {
                x('INSERT INTO organisation_domains (host, org_id, is_primary) VALUES (?,?,?)', [$host, $id, q1('SELECT 1 FROM organisation_domains WHERE org_id = ?', [$id]) ? 0 : 1]);
                audit_log('platform.domain_added', 'organisation', $id, ['host' => $host]);
                flash('Domain added. Point its DNS at the hosting account and add it to the SSL certificate before use.', 'success');
            }
        } elseif ($action === 'remove_domain') {
            x('DELETE FROM organisation_domains WHERE host = ? AND org_id = ?', [form('host'), $id]);
            audit_log('platform.domain_removed', 'organisation', $id, ['host' => form('host')]);
        } elseif ($action === 'subscription') {
            $planId = form_int('plan_id');
            $status = form('status');
            $periodEnd = trim(form('current_period_end'));
            if (!q1('SELECT 1 FROM plans WHERE id = ?', [$planId]) || !isset(SUB_STATUSES[$status])) {
                flash('Choose a plan and status.', 'error');
            } elseif ($periodEnd !== '' && valid_date($periodEnd) === null) {
                flash('Enter a valid period end date.', 'error');
            } else {
                $periodEnd = $periodEnd ?: null;
                if (subscription($id) === null) {
                    x("INSERT INTO subscriptions (org_id, plan_id, status, billing_mode, current_period_start, current_period_end, created_at, updated_at) VALUES (?,?,?,'manual',?,?,?,?)",
                        [$id, $planId, $status, today(), $periodEnd, now(), now()]);
                } else {
                    x("UPDATE subscriptions SET plan_id = ?, status = ?, current_period_end = COALESCE(?, current_period_end),
                       cancelled_at = CASE WHEN ? = 'cancelled' THEN COALESCE(cancelled_at, ?) ELSE NULL END, updated_at = ? WHERE org_id = ?",
                        [$planId, $status, $periodEnd, $status, now(), now(), $id]);
                }
                audit_log('platform.subscription_updated', 'organisation', $id, ['plan_id' => $planId, 'status' => $status]);
                flash('Subscription updated.', 'success');
            }
        }
        return redirect("/platform/organisations/$id");
    }
    return view('platform/organisation', ['the_org' => $org, 'sub' => subscription($id), 'usage' => org_usage($id), 'address' => org_address($org),
        'invoices' => q('SELECT * FROM subscription_invoices WHERE org_id = ? ORDER BY period_start DESC', [$id]),
        'domains' => q('SELECT * FROM organisation_domains WHERE org_id = ? ORDER BY is_primary DESC, host', [$id]),
        'plans' => q('SELECT * FROM plans ORDER BY price_minor'),
        'admins' => q("SELECT full_name, email, last_login_at, totp_enabled_at FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", [$id])]);
});

route('POST', '/platform/organisations/{id}/invoices', function (int $id) {
    if ($r = need_platform()) {
        return $r;
    }
    $sub = subscription($id);
    if ($sub === null || $sub['status'] === 'cancelled') {
        abort(400, 'No active subscription to invoice.');
    }
    $start = valid_date(form('period_start'));
    if ($start === null) {
        abort(400, 'Choose the period start.');
    }
    $end = period_end($start, $sub['billing_period']);
    // Optional usage fee: a percentage of payments the organisation actually confirmed to its customers in the period.
    $usage = (int) qv("SELECT COALESCE(SUM(amount_pence), 0) FROM disbursements WHERE org_id = ? AND status = 'confirmed' AND currency = ? AND date(confirmed_at) BETWEEN ? AND ?",
        [$id, $sub['plan_currency'], $start, $end]);
    $fee = to_minor(ddiv(dmul((string) $usage, dec($sub['platform_fee_percent'])), '100'));
    $number = sprintf('INV-%04d-%s', $id, str_replace('-', '', $start));
    try {
        x("INSERT INTO subscription_invoices (org_id, subscription_id, number, period_start, period_end, plan_amount_minor, usage_fee_minor, currency, status, issued_at, recorded_by)
           VALUES (?,?,?,?,?,?,?,?, 'issued', ?, ?)", [$id, $sub['id'], $number, $start, $end, $sub['price_minor'], $fee, $sub['plan_currency'], now(), current_user()['id']]);
    } catch (PDOException $e) {
        if (!is_unique_violation($e)) {
            throw $e;
        }
        flash('An invoice for that period already exists.', 'error');
        return redirect("/platform/organisations/$id");
    }
    audit_log('platform.invoice_issued', 'organisation', $id, ['number' => $number, 'amount_minor' => (int) $sub['price_minor'] + $fee]);
    flash("Invoice $number issued (manual billing: record the payment only once it has been received).", 'success');
    return redirect("/platform/organisations/$id");
});

route('POST', '/platform/invoices/{id}/{action}', function (int $id, string $action) {
    if ($r = need_platform()) {
        return $r;
    }
    $inv = q1("SELECT * FROM subscription_invoices WHERE id = ? AND status = 'issued'", [$id]);
    if ($inv === null || !in_array($action, ['paid', 'void'], true)) {
        abort(404);
    }
    if ($action === 'paid') {
        $ref = mb_substr(trim(form('payment_reference')), 0, 120);
        if ($ref === '' || !form('confirm')) {
            flash('Enter the payment reference and confirm the money has been received.', 'error');
            return redirect("/platform/organisations/{$inv['org_id']}");
        }
        x("UPDATE subscription_invoices SET status = 'paid', paid_at = ?, payment_reference = ?, recorded_by = ? WHERE id = ?", [now(), $ref, current_user()['id'], $id]);
        x("UPDATE subscriptions SET status = 'active', current_period_end = MAX(COALESCE(current_period_end, ''), ?), updated_at = ? WHERE id = ? AND status IN ('trial', 'past_due', 'active')",
            [$inv['period_end'], now(), $inv['subscription_id']]);
    } else {
        x("UPDATE subscription_invoices SET status = 'void' WHERE id = ?", [$id]);
    }
    audit_log("platform.invoice_$action", 'organisation', $inv['org_id'], ['number' => $inv['number']]);
    flash('Invoice updated.', 'success');
    return redirect("/platform/organisations/{$inv['org_id']}");
});

route('GET|POST', '/platform/plans', function () {
    if ($r = need_platform()) {
        return $r;
    }
    $errors = [];
    if (is_post()) {
        $f = [];
        foreach (['id', 'code', 'name', 'description', 'currency', 'billing_period', 'features', 'platform_fee_percent'] as $k) {
            $f[$k] = trim(form($k));
        }
        $price = parse_money(form('price'));
        $ints = [];
        foreach (['trial_days', 'max_staff', 'max_applications_month', 'max_products'] as $k) {
            $raw = trim(form($k));
            $ints[$k] = ctype_digit($raw) ? (int) $raw : null;
        }
        $ints['trial_days'] = $ints['trial_days'] ?: 0;
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $f['code'])) {
            $errors['code'] = 'Use 2-30 lower-case letters, numbers, - or _ for the code.';
        }
        if (mb_strlen($f['name']) < 2) {
            $errors['name'] = 'Enter a name.';
        }
        if ($price === null) {
            $errors['price'] = 'Enter the price, e.g. 99.00.';
        }
        if (!isset(CURRENCIES[$f['currency']]) || !in_array($f['billing_period'], ['month', 'year'], true)) {
            $errors['currency'] = 'Choose a currency and billing period.';
        }
        try {
            $f['platform_fee_percent'] = validate_percent($f['platform_fee_percent'] ?: '0', '10');
        } catch (PricingError $e) {
            $errors['platform_fee_percent'] = $e->getMessage();
        }
        if (!$errors) {
            $values = [$f['code'], $f['name'], mb_substr($f['description'], 0, 500), $price, $f['currency'], $f['billing_period'], $ints['trial_days'],
                $ints['max_staff'], $ints['max_applications_month'], $ints['max_products'], $f['platform_fee_percent'], mb_substr($f['features'], 0, 1000),
                form('active') ? 1 : 0];
            try {
                if (ctype_digit($f['id'])) {
                    x('UPDATE plans SET code=?, name=?, description=?, price_minor=?, currency=?, billing_period=?, trial_days=?, max_staff=?, max_applications_month=?,
                       max_products=?, platform_fee_percent=?, features=?, active=? WHERE id = ?', [...$values, (int) $f['id']]);
                    $pid = (int) $f['id'];
                } else {
                    $pid = x('INSERT INTO plans (code, name, description, price_minor, currency, billing_period, trial_days, max_staff, max_applications_month, max_products,
                              platform_fee_percent, features, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$values, now()]);
                }
                audit_log('platform.plan_saved', 'plan', $pid, ['code' => $f['code'], 'price_minor' => $price]);
                flash('Plan saved. Existing invoices are unchanged.', 'success');
                return redirect('/platform/plans');
            } catch (PDOException $e) {
                if (!is_unique_violation($e)) {
                    throw $e;
                }
                $errors['code'] = 'That code is already used.';
            }
        }
    }
    return view('platform/plans', ['errors' => $errors, 'rows' => q("SELECT p.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.plan_id = p.id AND s.status != 'cancelled') AS subs
        FROM plans p ORDER BY p.price_minor")]);
});

route('GET|POST', '/platform/settings', function () {
    if ($r = need_platform()) {
        return $r;
    }
    if (is_post()) {
        $caps = [];
        $errors = [];
        foreach (RULE_KINDS as $k => $label) {
            try {
                $caps[$k] = validate_percent(form($k, '100'), '100');
            } catch (PricingError $e) {
                $errors[] = "$label: " . $e->getMessage();
            }
        }
        if ($errors || !form('confirm')) {
            flash(implode('; ', $errors) ?: 'Tick the box to confirm.', 'error');
        } else {
            x("INSERT INTO platform_settings (key, value) VALUES ('percent_caps', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", [json_out($caps)]);
            audit_log('platform.caps_updated', 'platform', null, ['caps' => json_out($caps)]);
            flash('Platform limits saved. Organisations can set lower limits, never higher.', 'success');
        }
        return redirect('/platform/settings');
    }
    return view('platform/settings', ['caps' => platform_caps()]);
});
