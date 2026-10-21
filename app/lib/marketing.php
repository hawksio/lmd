<?php
/**
 * Consent-based marketing email.
 * - Nobody is emailed unless they ticked an unticked opt-in box, confirmed their email address and have not opted out
 *   since. Registering, applying or sending an enquiry never opts anyone in.
 * - Every consent change is recorded with the wording shown at the time.
 * - Opting out or unsubscribing adds the address to the suppression list, which every send checks.
 * - Live campaigns need marketing_enabled. Test sends go only to the signed-in staff member.
 * Scheduled campaigns are sent by the send-campaigns job (cron).
 */
declare(strict_types=1);

const SEGMENTS = [
    'all_opted_in' => 'Everyone who has opted in',
    'has_application' => 'Opted-in clients who have submitted an application',
    'no_application' => 'Opted-in clients who have not submitted an application',
];

function consent_wording(?array $org = null): string
{
    $org = $org ?? current_org();
    return 'Yes, send me occasional emails from ' . ($org['name'] ?? 'us') . ' about its finance services. I can unsubscribe at any time.';
}

function suppressed(int $org, string $email): bool
{
    return q1('SELECT 1 FROM suppressions WHERE org_id = ? AND email = ?', [$org, $email]) !== null;
}

function is_opted_in(array $user): bool
{
    return $user['marketing_opt_in_at'] && $user['org_id'] !== null && !suppressed((int) $user['org_id'], $user['email']);
}

function record_consent(?array $user, string $action, string $source, ?string $email = null, ?int $org = null): void
{
    $email = $user['email'] ?? $email;
    $org = $user ? (int) $user['org_id'] : $org;
    $orgRow = q1('SELECT * FROM organisations WHERE id = ?', [$org]);
    transaction(function () use ($user, $action, $source, $email, $org, $orgRow) {
        x('INSERT INTO marketing_consent_events (org_id, user_id, email, action, source, wording, created_at) VALUES (?,?,?,?,?,?,?)',
            [$org, $user['id'] ?? null, $email, $action, $source, $action === 'opt_in' ? consent_wording($orgRow) : '', now()]);
        if ($action === 'opt_in') {
            if ($user) {
                x('UPDATE users SET marketing_opt_in_at = ? WHERE id = ?', [now(), $user['id']]);
            }
            x("DELETE FROM suppressions WHERE org_id = ? AND email = ? AND reason = 'unsubscribed'", [$org, $email]);
        } else {
            if ($user) {
                x('UPDATE users SET marketing_opt_in_at = NULL WHERE id = ?', [$user['id']]);
            }
            x("INSERT OR IGNORE INTO suppressions (org_id, email, reason, created_at) VALUES (?, ?, 'unsubscribed', ?)", [$org, $email, now()]);
        }
        audit_log("marketing.$action", 'user', $user['id'] ?? null, ['source' => $source], $user ?: false);
    });
}

function unsubscribe_token(int $org, string $email): string
{
    $payload = rtrim(strtr(base64_encode(json_out(['o' => $org, 'e' => strtolower($email)])), '+/', '-_'), '=');
    return $payload . '.' . substr(hash_hmac('sha256', 'unsubscribe|' . $payload, secret_key()), 0, 32);
}

/** [org id, email] from a signed token; 404 if forged or for another organisation. */
function unsubscribe_from_token(string $token): array
{
    [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');
    if (!hash_equals(substr(hash_hmac('sha256', 'unsubscribe|' . $payload, secret_key()), 0, 32), $sig)) {
        abort(404);
    }
    $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
    if (!is_array($data) || (int) ($data['o'] ?? 0) !== org_id()) {
        abort(404);  // a link only works on the organisation that sent it
    }
    return [(int) $data['o'], (string) $data['e']];
}

function unsubscribe_url(string $email): string
{
    return tenant_base_url() . '/marketing/unsubscribe/' . unsubscribe_token(org_id(), $email);
}

function do_unsubscribe(int $org, string $email, string $source): void
{
    $user = q1('SELECT * FROM users WHERE org_id = ? AND email = ?', [$org, $email]);
    if (!suppressed($org, $email) || ($user && $user['marketing_opt_in_at'])) {
        record_consent($user, 'opt_out', $source, $email, $org);
    }
}

function segment_members(string $segment, ?int $org = null): array
{
    $org = $org ?? org_id();
    $sql = "SELECT u.id, u.email, u.full_name FROM users u WHERE u.org_id = ? AND u.role = 'client' AND u.is_active = 1
            AND u.email_verified_at IS NOT NULL AND u.marketing_opt_in_at IS NOT NULL AND u.email NOT IN (SELECT email FROM suppressions WHERE org_id = ?)";
    if ($segment === 'has_application') {
        $sql .= " AND EXISTS (SELECT 1 FROM applications a WHERE a.user_id = u.id AND a.status != 'draft')";
    } elseif ($segment === 'no_application') {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM applications a WHERE a.user_id = u.id AND a.status != 'draft')";
    }
    return q($sql, [$org, $org]);
}

function send_campaign_email(array $campaign, string $email, ?string $name, bool $test = false): ?int
{
    $unsub = unsubscribe_url($email);
    return send_mail($email, ($test ? '[TEST] ' : '') . $campaign['subject'], 'marketing_campaign',
        ['campaign' => $campaign, 'first_name' => first_name($name), 'unsubscribe_link' => $unsub, 'test' => $test], 'marketing',
        ['List-Unsubscribe' => "<$unsub/one-click>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
}

/** Send campaigns whose time has come (up to the per-minute rate per run). Returns the number sent. */
function process_due_campaigns(): int
{
    if (!config('marketing_enabled')) {
        return 0;
    }
    $budget = max(1, (int) config('marketing_rate_per_minute'));
    $sent = 0;
    foreach (q("SELECT * FROM campaigns WHERE status IN ('scheduled', 'sending') AND scheduled_for <= ?", [now()]) as $c) {
        $org = q1("SELECT * FROM organisations WHERE id = ? AND status = 'active'", [$c['org_id']]);
        if ($org === null) {
            continue;
        }
        set_current_org($org);
        if ($c['status'] === 'scheduled') {
            transaction(function () use ($c) {
                x("UPDATE campaigns SET status = 'sending' WHERE id = ?", [$c['id']]);
                foreach (segment_members($c['segment'], (int) $c['org_id']) as $m) {
                    x('INSERT OR IGNORE INTO campaign_recipients (campaign_id, user_id, email) VALUES (?,?,?)', [$c['id'], $m['id'], $m['email']]);
                }
            });
        }
        foreach (q("SELECT r.*, u.full_name FROM campaign_recipients r LEFT JOIN users u ON u.id = r.user_id WHERE r.campaign_id = ? AND r.status = 'pending'", [$c['id']]) as $r) {
            if ($sent >= $budget) {
                break 2;  // the next cron run continues
            }
            if (suppressed((int) $c['org_id'], $r['email'])) {
                x("UPDATE campaign_recipients SET status = 'skipped', error = 'suppressed' WHERE id = ?", [$r['id']]);
                continue;
            }
            $logId = send_campaign_email($c, $r['email'], $r['full_name']);
            $status = qv('SELECT status FROM email_log WHERE id = ?', [$logId]);
            x('UPDATE campaign_recipients SET status = ?, sent_at = ?, error = ? WHERE id = ?',
                [$status === 'failed' ? 'failed' : 'sent', now(), $status === 'failed' ? 'delivery failed' : '', $r['id']]);
            $sent++;
        }
        if (!qv("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$c['id']])) {
            x("UPDATE campaigns SET status = 'sent', sent_at = ? WHERE id = ?", [now(), $c['id']]);
            audit_log('marketing.campaign_sent', 'campaign', $c['id'], [], false);
        }
    }
    set_current_org(null);
    return $sent;
}

function marketing_readiness(): array
{
    $s = site();
    return [
        ['marketing_enabled is on', (bool) config('marketing_enabled')],
        ['A separate marketing SMTP service is configured', (bool) config('marketing_smtp_host')],
        ['The legal company name and registered office are published', $s['legal_name'] && $s['registered_office']],
        ['A privacy notice covering marketing has been approved and published', false],
        ['PECR / UK GDPR review of the campaign content and audience completed', false],
    ];
}
