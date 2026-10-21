<?php
/**
 * Sessions, CSRF protection, staff permissions, rate limiting and TOTP two-step codes.
 *
 * Signed-in sessions are server-side records (user_sessions). The PHP session only carries a random id whose
 * hash is stored, so signing out, changing a password or "sign out other devices" really ends those sessions.
 */
declare(strict_types=1);

function is_staff(?array $user): bool
{
    return $user !== null && array_key_exists($user['role'], STAFF_ROLES);
}

function is_platform_owner(?array $user): bool
{
    return $user !== null && $user['role'] === 'platform_owner';
}

function can(string $permission, ?array $user = null): bool
{
    $user = $user ?? current_user();
    return is_staff($user) && in_array($user['role'], PERMISSIONS[$permission], true);
}

/** Server-side permission check. Hidden buttons are a convenience, never the control. */
function require_perm(string $permission): void
{
    if (!can($permission)) {
        abort(403);
    }
}

// ---------------------------------------------------------------- PHP session (transient state)
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $key = on_platform() ? 'console' : (current_org()['slug'] ?? 'x');
    $secure = (bool) config('secure_cookies');
    session_name(($secure ? '__Secure-' : '') . 'lmd_' . preg_replace('/[^a-z0-9]/', '', $key));
    session_save_path(storage_dir() . '/sessions');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (((int) config('session_hours')) * 3600));
    session_start();
}

// ---------------------------------------------------------------- signed-in sessions
function idle_cutoff(): string
{
    return gmdate('Y-m-d H:i:s', time() - ((int) config('session_idle_minutes')) * 60);
}

function current_session(): ?array
{
    if (!array_key_exists('__lmd_session_row', $GLOBALS)) {
        $row = null;
        $sid = $_SESSION['sid'] ?? null;
        if ($sid) {
            $row = q1('SELECT * FROM user_sessions WHERE sid_hash = ? AND revoked_at IS NULL AND expires_at > ? AND last_seen_at > ?',
                [sha256($sid), now(), idle_cutoff()]);
            if ($row === null) {
                unset($_SESSION['sid']);
            } elseif ($row['last_seen_at'] < gmdate('Y-m-d H:i:s', time() - 60)) {
                x('UPDATE user_sessions SET last_seen_at = ? WHERE id = ?', [now(), $row['id']]);
            }
        }
        $GLOBALS['__lmd_session_row'] = $row;
    }
    return $GLOBALS['__lmd_session_row'];
}

/** The signed-in user, but only within their own organisation (or the console for platform owners). */
function current_user(): ?array
{
    if (!array_key_exists('__lmd_user', $GLOBALS)) {
        $row = current_session();
        $user = $row ? q1('SELECT * FROM users WHERE id = ? AND is_active = 1', [$row['user_id']]) : null;
        if ($user !== null && !belongs_here($user)) {
            $user = null;
        }
        $GLOBALS['__lmd_user'] = $user;
    }
    return $GLOBALS['__lmd_user'];
}

function forget_user_cache(): void
{
    unset($GLOBALS['__lmd_user'], $GLOBALS['__lmd_session_row']);
}

function belongs_here(array $user): bool
{
    if (on_platform()) {
        return $user['role'] === 'platform_owner';
    }
    $org = current_org();
    return $org !== null && (int) $user['org_id'] === (int) $org['id'];
}

function find_user_here(string $email): ?array
{
    if (on_platform()) {
        return q1('SELECT * FROM users WHERE email = ? AND org_id IS NULL', [$email]);
    }
    return q1('SELECT * FROM users WHERE email = ? AND org_id = ?', [$email, org_id()]);
}

/** Start a fresh session (new id, so a planted session can't be reused) and record it. */
function login_user(array $user): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $sid = token_urlsafe(32);
    $stamp = now();
    x('INSERT INTO user_sessions (user_id, sid_hash, created_at, last_seen_at, expires_at, ip, user_agent) VALUES (?,?,?,?,?,?,?)',
        [$user['id'], sha256($sid), $stamp, $stamp, later(((int) config('session_hours')) * 3600), client_ip(), user_agent()]);
    x('UPDATE users SET last_login_at = ? WHERE id = ?', [$stamp, $user['id']]);
    $_SESSION['sid'] = $sid;
    forget_user_cache();
}

function logout_current(): void
{
    $row = current_session();
    if ($row) {
        x('UPDATE user_sessions SET revoked_at = ? WHERE id = ?', [now(), $row['id']]);
    }
    $_SESSION = [];
    session_regenerate_id(true);
    forget_user_cache();
}

function revoke_sessions(int $userId, bool $keepCurrent = false): void
{
    $keep = ($keepCurrent && current_session()) ? (int) current_session()['id'] : -1;
    x('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id != ?', [now(), $userId, $keep]);
}

function active_sessions(int $userId): array
{
    return q('SELECT * FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? AND last_seen_at > ? ORDER BY last_seen_at DESC',
        [$userId, now(), idle_cutoff()]);
}

// ---------------------------------------------------------------- route guards (return a Response to stop, or null)
function need_login(): ?Response
{
    if (current_user() === null) {
        return redirect('/account/login?next=' . rawurlencode(url(path())));
    }
    return null;
}

function need_staff(?string $permission = null): ?Response
{
    $user = current_user();
    if ($user === null) {
        return redirect('/account/login?next=' . rawurlencode(url(path())));
    }
    if (!is_staff($user)) {
        abort(403);
    }
    if (config('require_staff_mfa') && !$user['totp_enabled_at']) {
        return redirect('/account/security?setup_mfa=1');
    }
    if ($permission !== null) {
        require_perm($permission);
    }
    return null;
}

function need_platform(): ?Response
{
    if (!on_platform()) {
        abort(404);
    }
    $user = current_user();
    if ($user === null) {
        return redirect('/account/login?next=' . rawurlencode(url(path())));
    }
    if (!is_platform_owner($user)) {
        abort(403);
    }
    if (config('require_staff_mfa') && !$user['totp_enabled_at']) {
        return redirect('/account/security?setup_mfa=1');
    }
    return null;
}

// ---------------------------------------------------------------- CSRF
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = token_urlsafe(32);
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!in_array(method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }
    // RFC 8058 one-click unsubscribe: the signed token in the address is the proof.
    if (preg_match('#^/marketing/unsubscribe/[^/]+/one-click$#', path())) {
        return;
    }
    $sent = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
        abort(400, 'Your session expired. Please go back, refresh the page and try again.');
    }
}

// ---------------------------------------------------------------- rate limiting
/** Record an attempt for $key; true if it is over $limit within $window seconds. Keys are hashed. */
function throttled(string $key, int $limit, int $window): bool
{
    $t = microtime(true);
    $hash = sha256(strtolower($key));
    if (random_int(0, 49) === 0) {
        x('DELETE FROM rate_limits WHERE at < ?', [$t - 86400]);
    }
    x('INSERT INTO rate_limits (key_hash, at) VALUES (?, ?)', [$hash, $t]);
    return (int) qv('SELECT COUNT(*) FROM rate_limits WHERE key_hash = ? AND at > ?', [$hash, $t - $window]) > $limit;
}

function clear_throttle(string $key): void
{
    x('DELETE FROM rate_limits WHERE key_hash = ?', [sha256(strtolower($key))]);
}

// ---------------------------------------------------------------- TOTP (RFC 6238; any authenticator app)
function base32_encode(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($text, '='))) as $c) {
        $i = strpos($alphabet, $c);
        if ($i === false) {
            return '';
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function new_totp_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_at(string $secret, int $step): string
{
    $digest = hash_hmac('sha1', pack('J', $step), base32_decode($secret), true);
    $offset = ord($digest[19]) & 0x0F;
    $code = (unpack('N', substr($digest, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

/** The matched time step (stored to block replay), or null. Accepts one step of clock drift. */
function verify_totp(?string $secret, ?string $code, ?int $lastStep = null): ?int
{
    $code = preg_replace('/\D/', '', (string) $code);
    if (strlen($code) !== 6 || !$secret) {
        return null;
    }
    $step = intdiv(time(), 30);
    foreach ([$step, $step - 1, $step + 1] as $candidate) {
        if ($lastStep !== null && $candidate <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_at($secret, $candidate), $code)) {
            return $candidate;
        }
    }
    return null;
}

function totp_uri(string $secret, string $email): string
{
    $issuer = rawurlencode(current_org()['name'] ?? 'Loan platform');
    return "otpauth://totp/$issuer:" . rawurlencode($email) . "?secret=$secret&issuer=$issuer";
}

function new_recovery_codes(int $userId): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = implode('-', [bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2))]);
    }
    x('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
    foreach ($codes as $c) {
        x('INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (?,?,?)', [$userId, sha256($c), now()]);
    }
    return $codes;
}

function use_recovery_code(int $userId, ?string $code): bool
{
    $code = strtolower(trim((string) $code));
    $row = q1('SELECT id FROM recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$userId, sha256($code)]);
    if ($row) {
        x('UPDATE recovery_codes SET used_at = ? WHERE id = ?', [now(), $row['id']]);
        return true;
    }
    return false;
}
