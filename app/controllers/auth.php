<?php
/**
 * Registration, email verification, sign-in (with optional authenticator-app codes), password reset and the Account
 * and security page (password, two-step verification, active sessions, marketing choice).
 */
declare(strict_types=1);

const MIN_PASSWORD = 10;
const COMMON_PASSWORDS = ['password123', 'password1234', '1234567890', 'qwertyuiop', 'letmein123', 'iloveyou12', 'welcome123', 'football12',
    'monkey12345', 'abc1234567', 'passw0rd123', 'changeme123', 'administrator', '1q2w3e4r5t', 'qwerty12345', 'lmdfinance1', 'lmdfinance123'];
const EMAIL_RE = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

function password_problem(string $password, string $email = ''): ?string
{
    if (mb_strlen($password) < MIN_PASSWORD) {
        return 'Use at least ' . MIN_PASSWORD . ' characters.';
    }
    if (mb_strlen($password) > 200) {
        return 'Use 200 characters or fewer.';
    }
    if (in_array(strtolower($password), COMMON_PASSWORDS, true) || count(array_unique(mb_str_split($password))) < 4) {
        return 'That password is too easy to guess. Try a short phrase of unrelated words.';
    }
    $local = strtolower(explode('@', $email)[0]);
    if ($email !== '' && strlen($local) > 3 && str_contains(strtolower($password), $local)) {
        return "Don't include your email address in your password.";
    }
    return null;
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function home_for(array $user): string
{
    return is_platform_owner($user) ? '/platform/' : (is_staff($user) ? '/admin/' : '/account/');
}

/** A token only works for the organisation that issued it. */
function token_user_here(int $userId): ?array
{
    $user = q1('SELECT * FROM users WHERE id = ?', [$userId]);
    return ($user !== null && belongs_here($user)) ? $user : null;
}

function send_verification_link(array $user): void
{
    $token = token_urlsafe(32);
    transaction(function () use ($user, $token) {
        x('UPDATE email_verifications SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $user['id']]);
        x('INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)', [$user['id'], sha256($token), later(86400), now()]);
    });
    send_verification($user, tenant_base_url() . "/account/verify/$token");
}

function finish_login(array $user, ?string $next, bool $mfa = false): Response
{
    login_user($user);
    clear_throttle('login:' . $user['email']);
    audit_log('auth.login', 'user', $user['id'], ['mfa' => $mfa], $user);
    $base = $GLOBALS['__lmd_base'] ?? '';
    // $next is a full local path (it may include the organisation prefix); strip the prefix before redirecting.
    $local = ($next && $base !== '' && str_starts_with($next, $base)) ? substr($next, strlen($base)) : $next;
    $area = is_platform_owner($user) ? '/platform' : (is_staff($user) ? '/admin' : '/account');
    return redirect($local && str_starts_with($local, $area) ? $local : home_for($user));
}

route('GET|POST', '/account/register', function () {
    if (on_platform()) {
        abort(404);  // nobody self-registers on the platform console
    }
    if (current_user()) {
        return redirect(home_for(current_user()));
    }
    $f = ['full_name' => '', 'email' => '', 'phone' => ''];
    $errors = [];
    if (is_post()) {
        foreach ($f as $k => $_) {
            $f[$k] = trim(form($k));
        }
        $password = form('password');
        if (throttled('register:' . client_ip(), 10, 3600)) {
            $errors['form'] = 'Too many attempts. Please try again later.';
        }
        if (mb_strlen($f['full_name']) < 2 || mb_strlen($f['full_name']) > 120) {
            $errors['full_name'] = 'Enter your full name.';
        }
        if (!preg_match(EMAIL_RE, $f['email']) || strlen($f['email']) > 200) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($f['phone']) > 40 || ($f['phone'] !== '' && !preg_match('/^[+0-9 ()-]{6,40}$/', $f['phone']))) {
            $errors['phone'] = 'Enter a phone number using digits, spaces and + only.';
        }
        if ($problem = password_problem($password, $f['email'])) {
            $errors['password'] = $problem;
        } elseif ($password !== form('confirm')) {
            $errors['confirm'] = 'Passwords do not match.';
        }
        if (!form('terms')) {
            $errors['terms'] = 'Please confirm you have read the privacy notice.';
        }
        if (!$errors) {
            $existing = find_user_here($f['email']);
            if ($existing) {
                // Same response as a new sign-up, so the form can't reveal who has an account; the owner gets an email.
                if (!throttled('exists:' . $f['email'], 2, 3600)) {
                    send_account_exists($existing);
                }
                audit_log('auth.register_existing', 'user', $existing['id'], [], false);
            } else {
                $uid = x("INSERT INTO users (org_id, email, password_hash, full_name, phone, role, created_at, password_changed_at) VALUES (?,?,?,?,?, 'client', ?, ?)",
                    [org_id(), strtolower($f['email']), hash_password($password), $f['full_name'], $f['phone'], now(), now()]);
                $user = q1('SELECT * FROM users WHERE id = ?', [$uid]);
                audit_log('auth.register', 'user', $uid, [], $user);
                if (form('marketing')) {
                    record_consent($user, 'opt_in', 'registration form');
                }
                send_verification_link($user);
            }
            return view('auth/check_email', ['email' => $f['email']]);
        }
    }
    return view('auth/register', ['f' => $f, 'errors' => $errors]);
});

route('GET', '/account/verify/{token}', function (string $token) {
    $row = q1('SELECT * FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [sha256($token), now()]);
    if ($row === null || token_user_here((int) $row['user_id']) === null) {
        return view('auth/verify_failed', [], 400);
    }
    transaction(function () use ($row) {
        x('UPDATE email_verifications SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $row['user_id']]);
        x('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $row['user_id']]);
        audit_log('auth.email_verified', 'user', $row['user_id'], [], false);
    });
    forget_user_cache();
    flash('Thank you, your email address is confirmed.' . (current_user() ? '' : ' Please sign in.'), 'success');
    return redirect(current_user() ? home_for(current_user()) : '/account/login');
});

route('POST', '/account/verify/resend', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    if ($user['email_verified_at']) {
        flash('Your email address is already confirmed.', 'info');
    } elseif (throttled('verify:' . $user['id'], 3, 3600)) {
        flash("We've sent several links recently. Please check your inbox and spam folder, or try again in an hour.", 'error');
    } else {
        send_verification_link($user);
        flash("We've sent a new confirmation link. It works for 24 hours.", 'success');
    }
    return redirect(home_for($user));
});

route('GET|POST', '/account/login', function () {
    if (current_user()) {
        return redirect(home_for(current_user()));
    }
    $email = '';
    $error = null;
    if (is_post()) {
        $email = mb_substr(trim(form('email')), 0, 200);
        $password = form('password');
        if (throttled('login:' . client_ip(), 20, 900) || throttled('login:' . $email, 8, 900)) {
            $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
            audit_log('auth.login_throttled', 'user', null, [], false);
        } else {
            $user = find_user_here($email);
            // Compared against a dummy hash when the email is unknown, so both take the same time.
            $ok = password_verify($password, $user['password_hash'] ?? hash_password(bin2hex(random_bytes(16))));
            if ($user && $ok && $user['is_active']) {
                $next = safe_next(arg('next'));
                if ($user['totp_enabled_at']) {
                    $_SESSION = ['mfa_uid' => (int) $user['id'], 'mfa_at' => time(), 'mfa_next' => $next];
                    session_regenerate_id(true);
                    return redirect('/account/login/code');
                }
                return finish_login($user, $next);
            }
            audit_log('auth.login_failed', 'user', $user['id'] ?? null, [], false);
            $error = "That email and password don't match an account.";
        }
    }
    return view('auth/login', ['email' => $email, 'error' => $error]);
});

route('GET|POST', '/account/login/code', function () {
    $uid = $_SESSION['mfa_uid'] ?? null;
    if (!$uid || time() - ($_SESSION['mfa_at'] ?? 0) > 300) {
        $_SESSION = [];
        flash('Please sign in again.', 'info');
        return redirect('/account/login');
    }
    $error = null;
    if (is_post()) {
        $user = token_user_here((int) $uid);
        $user = ($user && $user['is_active']) ? $user : null;
        $code = form('code');
        if ($user === null || throttled("mfa:$uid", 5, 900)) {
            $error = 'Too many attempts. Please wait 15 minutes and sign in again.';
        } else {
            $step = verify_totp($user['totp_secret'], $code, $user['totp_last_step'] === null ? null : (int) $user['totp_last_step']);
            if ($step !== null) {
                x('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $uid]);
            }
            if ($step !== null || use_recovery_code((int) $uid, $code)) {
                if ($step === null) {
                    audit_log('auth.recovery_code_used', 'user', $uid, [], $user);
                    send_security_alert($user, 'a recovery code was used to sign in');
                }
                return finish_login($user, $_SESSION['mfa_next'] ?? null, true);
            }
            audit_log('auth.mfa_failed', 'user', $uid, [], false);
            $error = "That code didn't work. Check your authenticator app and try again.";
        }
    }
    return view('auth/login_code', ['error' => $error]);
});

route('POST', '/account/logout', function () {
    if ($user = current_user()) {
        audit_log('auth.logout', 'user', $user['id'], [], $user);
    }
    logout_current();
    flash('You have signed out.', 'success');
    return redirect('/account/login');
});

route('GET|POST', '/account/forgot', function () {
    $sent = false;
    $email = '';
    if (is_post()) {
        $email = mb_substr(trim(form('email')), 0, 200);
        $sent = true;  // same answer whether or not the account exists
        $limited = throttled('forgot:' . client_ip(), 10, 3600) || throttled("forgot:$email", 3, 3600);
        $user = $limited ? null : find_user_here($email);
        if ($user && $user['is_active']) {
            $token = token_urlsafe(32);
            transaction(function () use ($user, $token) {
                // One live link at a time: older unused links stop working.
                x('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $user['id']]);
                x('INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)', [$user['id'], sha256($token), later(3600), now()]);
                audit_log('auth.reset_requested', 'user', $user['id'], [], false);
            });
            send_password_reset($user, tenant_base_url() . "/account/reset/$token");
        }
    }
    return view('auth/forgot', ['sent' => $sent, 'email' => $email]);
});

route('GET|POST', '/account/reset/{token}', function (string $token) {
    $row = q1('SELECT r.*, u.email FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ?',
        [sha256($token), now()]);
    if ($row === null || token_user_here((int) $row['user_id']) === null) {
        return view('auth/reset', ['invalid' => true, 'errors' => []], 400);
    }
    $errors = [];
    if (is_post()) {
        $password = form('password');
        if ($problem = password_problem($password, $row['email'])) {
            $errors['password'] = $problem;
        } elseif ($password !== form('confirm')) {
            $errors['confirm'] = 'Passwords do not match.';
        }
        if (!$errors) {
            transaction(function () use ($row, $password) {
                $stamp = now();
                // The link proved the address, so it also confirms the email.
                x('UPDATE users SET password_hash = ?, password_changed_at = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?',
                    [hash_password($password), $stamp, $stamp, $row['user_id']]);
                x('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$stamp, $row['user_id']]);
                revoke_sessions((int) $row['user_id']);  // sign out everywhere
                audit_log('auth.password_reset', 'user', $row['user_id'], [], false);
            });
            clear_throttle('login:' . $row['email']);
            $_SESSION = [];
            send_security_alert(q1('SELECT * FROM users WHERE id = ?', [$row['user_id']]), 'your password was changed');
            flash('Your password has been changed. Please sign in.', 'success');
            return redirect('/account/login');
        }
    }
    return view('auth/reset', ['invalid' => false, 'errors' => $errors, 'email' => $row['email']]);
});

route('GET', '/account/security', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    $secret = $_SESSION['totp_setup'] ?? null;
    $codes = $_SESSION['recovery_codes'] ?? null;
    unset($_SESSION['recovery_codes']);
    return view('auth/security', [
        'user' => $user, 'sessions' => active_sessions((int) $user['id']), 'this_session' => (int) current_session()['id'],
        'setup_secret' => $secret, 'qr_svg' => $secret ? Qr::svg(totp_uri($secret, $user['email'])) : null, 'recovery_codes' => $codes,
        'recovery_left' => (int) qv('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$user['id']]),
        'opted_in' => !is_staff($user) && !is_platform_owner($user) && is_opted_in($user), 'must_setup' => (bool) arg('setup_mfa'),
    ]);
});

route('POST', '/account/security/password', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    $errors = [];
    if (throttled('pwchange:' . $user['id'], 5, 900)) {
        $errors[] = 'Too many attempts. Please try again in 15 minutes.';
    } elseif (!password_verify(form('current'), $user['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    }
    $new = form('password');
    if ($problem = password_problem($new, $user['email'])) {
        $errors[] = $problem;
    } elseif ($new !== form('confirm')) {
        $errors[] = 'Passwords do not match.';
    }
    if ($errors) {
        foreach ($errors as $m) {
            flash($m, 'error');
        }
        return redirect('/account/security#password');
    }
    transaction(function () use ($user, $new) {
        x('UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?', [hash_password($new), now(), $user['id']]);
        revoke_sessions((int) $user['id'], true);
        audit_log('auth.password_changed', 'user', $user['id']);
    });
    send_security_alert($user, 'your password was changed');
    flash('Password changed. Any other devices have been signed out.', 'success');
    return redirect('/account/security');
});

route('POST', '/account/security/mfa/start', function () {
    if ($r = need_login()) {
        return $r;
    }
    if (current_user()['totp_enabled_at']) {
        abort(400, 'Two-step verification is already on.');
    }
    $_SESSION['totp_setup'] = new_totp_secret();
    return redirect('/account/security#mfa');
});

route('POST', '/account/security/mfa/confirm', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    $secret = $_SESSION['totp_setup'] ?? null;
    $step = $secret ? verify_totp($secret, form('code')) : null;
    if ($step === null || throttled('mfasetup:' . $user['id'], 8, 900)) {
        flash("That code didn't match. Make sure your phone's clock is set automatically, then try the newest code.", 'error');
        return redirect('/account/security#mfa');
    }
    transaction(function () use ($user, $secret, $step) {
        x('UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?', [$secret, now(), $step, $user['id']]);
        $_SESSION['recovery_codes'] = new_recovery_codes((int) $user['id']);
        audit_log('auth.mfa_enabled', 'user', $user['id']);
    });
    unset($_SESSION['totp_setup']);
    send_security_alert($user, 'two-step verification was turned on');
    flash('Two-step verification is on. Save your recovery codes somewhere safe now: they are shown only once.', 'success');
    return redirect('/account/security#mfa');
});

route('POST', '/account/security/mfa/disable', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    if ((is_staff($user) || is_platform_owner($user)) && config('require_staff_mfa')) {
        abort(403, 'Staff accounts must keep two-step verification on.');
    }
    $ok = password_verify(form('password'), $user['password_hash'])
        && verify_totp($user['totp_secret'], form('code'), $user['totp_last_step'] === null ? null : (int) $user['totp_last_step']) !== null;
    if (!$ok || throttled('mfaoff:' . $user['id'], 5, 900)) {
        flash('Enter your password and a current code from your authenticator app to turn this off.', 'error');
        return redirect('/account/security#mfa');
    }
    transaction(function () use ($user) {
        x('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?', [$user['id']]);
        x('DELETE FROM recovery_codes WHERE user_id = ?', [$user['id']]);
        audit_log('auth.mfa_disabled', 'user', $user['id']);
    });
    send_security_alert($user, 'two-step verification was turned off');
    flash('Two-step verification is off.', 'success');
    return redirect('/account/security#mfa');
});

route('POST', '/account/security/sessions/{sid}/revoke', function (int $sid) {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    if (!q1('SELECT 1 FROM user_sessions WHERE id = ? AND user_id = ?', [$sid, $user['id']])) {
        abort(404);  // own sessions only
    }
    x('UPDATE user_sessions SET revoked_at = ? WHERE id = ?', [now(), $sid]);
    audit_log('auth.session_revoked', 'user', $user['id'], ['session_id' => $sid]);
    if ($sid === (int) current_session()['id']) {
        $_SESSION = [];
        return redirect('/account/login');
    }
    flash('That session has been signed out.', 'success');
    return redirect('/account/security#sessions');
});

route('POST', '/account/security/sessions/revoke-others', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    revoke_sessions((int) $user['id'], true);
    audit_log('auth.sessions_revoked', 'user', $user['id']);
    flash('All other sessions have been signed out.', 'success');
    return redirect('/account/security#sessions');
});

route('POST', '/account/security/marketing', function () {
    if ($r = need_login()) {
        return $r;
    }
    $user = current_user();
    if (is_staff($user) || is_platform_owner($user)) {
        abort(400);
    }
    $want = (bool) form('marketing');
    if ($want !== is_opted_in($user)) {
        record_consent($user, $want ? 'opt_in' : 'opt_out', 'account settings');
    }
    flash('Marketing preference saved.' . ($want ? ' You can unsubscribe at any time.' : ''), 'success');
    return redirect('/account/security#marketing');
});
