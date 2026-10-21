<?php
/**
 * One-time web set-up for hosting without a command line. Available only while config 'setup_token' is set and no
 * platform owner exists yet; the person installing opens /setup?token=<setup_token> and chooses the passwords for the
 * platform owner and the first administrator of the default organisation. Remove setup_token afterwards.
 */
declare(strict_types=1);

route('GET|POST', '/setup', function () {
    $token = (string) (config('setup_token') ?? '');
    if (strlen($token) < 32 || !hash_equals($token, arg('token')) || q1("SELECT 1 FROM users WHERE role = 'platform_owner'")) {
        abort(404);
    }
    $org = q1('SELECT * FROM organisations WHERE slug = ?', [config('default_org') ?: 'lmd']);
    $f = ['owner_name' => '', 'owner_email' => '', 'admin_name' => '', 'admin_email' => ''];
    $errors = [];
    if (is_post()) {
        foreach ($f as $k => $_) {
            $f[$k] = trim(form($k));
        }
        foreach (['owner', 'admin'] as $who) {
            if (mb_strlen($f["{$who}_name"]) < 2 || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $f["{$who}_email"])) {
                $errors["{$who}_email"] = 'Enter a name and a valid email.';
            }
            $pw = form("{$who}_password");
            if (mb_strlen($pw) < 12 || ($p = password_problem($pw, $f["{$who}_email"]))) {
                $errors["{$who}_password"] = $p ?? 'Use at least 12 characters.';
            }
        }
        if (!$errors) {
            transaction(function () use ($f, $org) {
                $uid = x("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) VALUES (NULL,?,?,?, 'platform_owner', ?,?,?)",
                    [strtolower($f['owner_email']), hash_password(form('owner_password')), $f['owner_name'], now(), now(), now()]);
                audit_log('platform.owner_created', 'user', $uid, ['via' => 'setup'], false);
                $aid = x("INSERT INTO users (org_id, email, password_hash, full_name, role, created_at, email_verified_at, password_changed_at) VALUES (?,?,?,?, 'admin', ?,?,?)",
                    [$org['id'], strtolower($f['admin_email']), hash_password(form('admin_password')), $f['admin_name'], now(), now(), now()]);
                audit_log('staff.created', 'user', $aid, ['role' => 'admin', 'via' => 'setup'], false);
            });
            return view('public/setup_done', ['org' => $org]);
        }
    }
    return view('public/setup', ['f' => $f, 'errors' => $errors, 'org' => $org]);
});
