<?php
/**
 * Command-line tasks (local machine, or hosting with SSH / cron):
 *   php app/cli.php create-platform-owner --email=you@example.com --name="Your Name"
 *   php app/cli.php create-admin --org=lmd --email=... --name=... [--role=admin]
 *   php app/cli.php create-organisation --slug=acme --name="Acme Finance" [--currency=GBP]
 *   php app/cli.php check-production
 *   php app/cli.php send-campaigns | subscription-housekeeping | purge-expired | verify-audit | backup
 *   php app/cli.php seed-dev        (development only: a local admin, written to lmd-storage/dev-admin.txt)
 *   php app/cli.php remove-dev-admin
 * Passwords are typed at a hidden prompt, never passed as arguments.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
// Works both locally (php/app/cli.php next to php/public_html) and on the server (public_html/app/cli.php).
define('LMD_DOCROOT', is_dir(dirname(__DIR__) . '/public_html') ? dirname(__DIR__) . '/public_html' : dirname(__DIR__));
require __DIR__ . '/bootstrap.php';
require LMD_APP . '/http.php';

const DEV_ADMIN_EMAIL = 'admin@lmd.test';

function opt(array $argv, string $name, ?string $default = null): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function ask_password(string $email): string
{
    if (getenv('LMD_NEW_PASSWORD')) {
        $pw = getenv('LMD_NEW_PASSWORD');
    } else {
        echo 'Password (at least 12 characters): ';
        if (DIRECTORY_SEPARATOR === '/') {
            shell_exec('stty -echo');
        }
        $pw = rtrim((string) fgets(STDIN), "\r\n");
        if (DIRECTORY_SEPARATOR === '/') {
            shell_exec('stty echo');
        }
        echo "\n";
    }
    if (mb_strlen($pw) < 12 || password_problem($pw, $email)) {
        fail('Use at least 12 characters, not a common password, and not containing the email.');
    }
    return $pw;
}

migrate();
$cmd = $argv[1] ?? 'help';
switch ($cmd) {
    case 'create-platform-owner':
        $email = strtolower((string) opt($argv, 'email'));
        $name = (string) opt($argv, 'name');
        if (!str_contains($email, '@') || $name === '') {
            fail('Give --email and --name.');
        }
        if (q1('SELECT 1 FROM users WHERE email = ? AND org_id IS NULL', [$email])) {
            fail('A platform owner with that email already exists.');
        }
        $uid = x("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) VALUES (NULL,?,?,?, 'platform_owner', ?,?,?)",
            [$email, hash_password(ask_password($email)), $name, now(), now(), now()]);
        audit_log('platform.owner_created', 'user', $uid, [], false);
        echo "Platform owner $email created. Sign in at " . tenant_base_url(null) . "/account/login\n";
        break;
    case 'create-admin':
        $slug = opt($argv, 'org', (string) config('default_org'));
        $org = q1('SELECT * FROM organisations WHERE slug = ?', [$slug]) ?? fail("No organisation '$slug'.");
        set_current_org($org);
        $email = strtolower((string) opt($argv, 'email'));
        $name = (string) opt($argv, 'name');
        $role = opt($argv, 'role', 'admin');
        if (!str_contains($email, '@') || $name === '' || !isset(STAFF_ROLES[$role])) {
            fail('Give --email, --name and a valid --role.');
        }
        $hash = hash_password(ask_password($email));
        $existing = q1('SELECT id FROM users WHERE email = ? AND org_id = ?', [$email, $org['id']]);
        if ($existing) {
            x('UPDATE users SET role = ?, password_hash = ?, full_name = ?, is_active = 1, password_changed_at = ? WHERE id = ?', [$role, $hash, $name, now(), $existing['id']]);
            audit_log('staff.cli_updated', 'user', $existing['id'], ['role' => $role], false);
            echo "Updated $email in {$org['name']}: role $role.\n";
        } else {
            $uid = x('INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) VALUES (?,?,?,?,?,?,?,?)',
                [$org['id'], $email, $hash, $name, $role, now(), now(), now()]);
            audit_log('staff.cli_created', 'user', $uid, ['role' => $role], false);
            echo 'Created ' . strtolower(STAFF_ROLES[$role]) . " $email in {$org['name']}.\n";
        }
        break;
    case 'create-organisation':
        $slug = strtolower((string) opt($argv, 'slug'));
        $name = (string) opt($argv, 'name');
        $currency = opt($argv, 'currency', 'GBP');
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $slug) || $name === '' || !isset(CURRENCIES[$currency])) {
            fail('Give --slug (lower-case letters, numbers, hyphens), --name and optionally --currency.');
        }
        if (q1('SELECT 1 FROM organisations WHERE slug = ?', [$slug])) {
            fail('That slug is taken.');
        }
        $oid = create_org($slug, $name, $currency);
        audit_log('platform.org_created', 'organisation', $oid, ['slug' => $slug], false);
        echo "Created $name: " . tenant_base_url(q1('SELECT * FROM organisations WHERE id = ?', [$oid])) . "\n";
        break;
    case 'send-campaigns':
    case 'subscription-housekeeping':
    case 'purge-expired':
    case 'verify-audit':
    case 'backup':
        echo run_job($cmd) . "\n";
        break;
    case 'seed-dev':
        if (production()) {
            fail('seed-dev is disabled outside development.');
        }
        $org = q1('SELECT * FROM organisations WHERE slug = ?', [config('default_org')]);
        $pw = token_urlsafe(12);
        $existing = q1('SELECT id FROM users WHERE email = ? AND org_id = ?', [DEV_ADMIN_EMAIL, $org['id']]);
        if ($existing) {
            x("UPDATE users SET password_hash = ?, role = 'admin', is_active = 1 WHERE id = ?", [hash_password($pw), $existing['id']]);
        } else {
            x("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at) VALUES (?,?,?,?, 'admin', ?, ?)",
                [$org['id'], DEV_ADMIN_EMAIL, hash_password($pw), 'Dev Admin', now(), now()]);
        }
        file_put_contents(storage_dir() . '/dev-admin.txt', "Local dev admin (do not use in production)\nemail: " . DEV_ADMIN_EMAIL . "\npassword: $pw\n");
        echo "Dev admin written to lmd-storage/dev-admin.txt\n";
        break;
    case 'remove-dev-admin':
        foreach (q("SELECT id FROM users WHERE email LIKE '%@lmd.test' OR email LIKE '%@platform.test'") as $row) {
            x("UPDATE users SET is_active = 0, password_hash = ? WHERE id = ?", [hash_password(bin2hex(random_bytes(24))), $row['id']]);
            x('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [now(), $row['id']]);
        }
        foreach (glob(storage_dir() . '/dev-*.txt') as $f) {
            unlink($f);
            echo 'Deleted ' . basename($f) . "\n";
        }
        echo "Development accounts disabled.\n";
        break;
    case 'check-production':
        $checks = [
            ['env is production', config('env') === 'production'],
            ['secure_cookies on (HTTPS)', (bool) config('secure_cookies')],
            ['base_url is https', str_starts_with((string) config('base_url'), 'https://')],
            ['Transactional SMTP configured', (bool) config('smtp_host')],
            ['mail_allowed_domains empty (no staging diversion)', !config('mail_allowed_domains')],
            ['Staff two-step verification required', (bool) config('require_staff_mfa')],
            ['Staging password lock removed', !config('staging_password')],
            ['Search engines allowed (noindex off)', !config('noindex')],
            ['No development credential files', !glob(storage_dir() . '/dev-*.txt')],
            ['No test accounts (@example.com / .test) active', !q1("SELECT 1 FROM users WHERE is_active = 1 AND (email LIKE '%@example.com' OR email LIKE '%.test')")],
            ['A platform owner account exists', (bool) q1("SELECT 1 FROM users WHERE role = 'platform_owner' AND is_active = 1")],
            ['Storage folder outside the web root', !str_starts_with(realpath(storage_dir()) ?: '', realpath(LMD_DOCROOT) ?: '#')],
        ];
        foreach (q("SELECT * FROM organisations WHERE status = 'active' ORDER BY slug") as $org) {
            set_current_org($org);
            $admins = (int) qv("SELECT COUNT(*) FROM users WHERE org_id = ? AND role = 'admin' AND is_active = 1", [$org['id']]);
            $checks[] = ["[{$org['slug']}] at least two active administrators (two-person approvals)", $admins >= 2];
            $checks[] = ["[{$org['slug']}] company details published (no placeholders)", !unresolved_placeholders()];
        }
        $failed = 0;
        foreach ($checks as [$label, $ok]) {
            $failed += $ok ? 0 : 1;
            echo '[' . ($ok ? 'ok' : 'FAIL') . "] $label\n";
        }
        echo "$failed blocking item(s).\n";
        exit($failed ? 1 : 0);
    default:
        echo file_get_contents(__FILE__, false, null, 0, 1200);
}
