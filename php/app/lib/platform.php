<?php
/**
 * Platform-owner helpers: plans, subscriptions and their limits. Billing is manual (no payment provider): the owner
 * issues invoices and records a payment only after it has actually been received, with its reference.
 */
declare(strict_types=1);

function subscription(int $orgId): ?array
{
    return q1('SELECT s.*, p.name AS plan_name, p.code AS plan_code, p.max_staff, p.max_applications_month, p.max_products, p.price_minor,
               p.currency AS plan_currency, p.billing_period, p.platform_fee_percent FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.org_id = ?', [$orgId]);
}

function plan_limit(int $orgId, string $key): ?int
{
    $s = subscription($orgId);
    if ($s === null || !in_array($s['status'], ['trial', 'active', 'past_due'], true)) {
        return null;
    }
    return $s[$key] === null ? null : (int) $s[$key];
}

function month_start(): string
{
    return gmdate('Y-m-01');
}

function usage_warning(int $orgId): ?string
{
    $limit = plan_limit($orgId, 'max_applications_month');
    if (!$limit) {
        return null;
    }
    $n = (int) qv('SELECT COUNT(*) FROM applications WHERE org_id = ? AND submitted_at >= ?', [$orgId, month_start()]);
    return $n >= $limit ? "$n applications this month: at or above your plan's $limit. New applications are still accepted; consider upgrading." : null;
}

function org_usage(int $orgId): array
{
    return [
        'staff' => (int) qv("SELECT COUNT(*) FROM users WHERE org_id = ? AND role != 'client' AND is_active = 1", [$orgId]),
        'customers' => (int) qv("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'client'", [$orgId]),
        'apps_month' => (int) qv('SELECT COUNT(*) FROM applications WHERE org_id = ? AND submitted_at >= ?', [$orgId, month_start()]),
        'products' => (int) qv('SELECT COUNT(*) FROM loan_products WHERE org_id = ?', [$orgId]),
    ];
}

function period_end(string $start, string $period): string
{
    $d = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    if ($period === 'year') {
        $next = $d->format('m-d') === '02-29' ? $d->modify('+364 days')->modify('+1 day') : $d->modify('+1 year');
        return $next->modify('-1 day')->format('Y-m-d');
    }
    [$y, $m, $day] = array_map('intval', explode('-', $start));
    $m++;
    if ($m > 12) {
        [$y, $m] = [$y + 1, 1];
    }
    if (!checkdate($m, $day, $y)) {
        return sprintf('%04d-%02d-28', $y, $m);
    }
    return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day)))->modify('-1 day')->format('Y-m-d');
}

function platform_caps(): array
{
    $caps = array_fill_keys(array_keys(RULE_KINDS), '100');
    $row = qv("SELECT value FROM platform_settings WHERE key = 'percent_caps'");
    if ($row) {
        $caps = array_merge($caps, json_decode($row, true) ?: []);
    }
    return $caps;
}

/** Effective maximum per kind: the platform cap, tightened by the organisation's own limit if set. */
function percent_caps(): array
{
    $out = platform_caps();
    foreach (org_settings()['max_percent'] as $k => $v) {
        if (isset($out[$k]) && bccomp(dec($v), dec($out[$k]), BC) < 0) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** Daily: lapsed trials/periods become past due; renewal reminders 7 days ahead. */
function subscription_housekeeping(): array
{
    $today = today();
    $soon = gmdate('Y-m-d', time() + 7 * 86400);
    $lapsed = q("SELECT * FROM subscriptions WHERE (status = 'trial' AND trial_ends_on < ?) OR (status = 'active' AND current_period_end < ?)", [$today, $today]);
    foreach ($lapsed as $s) {
        x("UPDATE subscriptions SET status = 'past_due', updated_at = ? WHERE id = ?", [now(), $s['id']]);
        audit_log('platform.subscription_past_due', 'organisation', $s['org_id'], [], false);
    }
    $reminders = 0;
    foreach (q("SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE (s.status = 'trial' AND s.trial_ends_on = ?)
                OR (s.status = 'active' AND s.current_period_end = ?)", [$soon, $soon]) as $s) {
        $org = q1('SELECT * FROM organisations WHERE id = ?', [$s['org_id']]);
        set_current_org($org);
        $admins = array_column(q("SELECT email FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", [$org['id']]), 'email');
        send_mail($admins, "Your {$s['plan_name']} subscription renews soon", 'subscription_reminder', ['org_name' => $org['name'], 'sub' => $s]);
        $reminders++;
    }
    set_current_org(null);
    return ['past_due' => count($lapsed), 'reminders' => $reminders];
}
