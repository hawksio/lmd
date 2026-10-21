<?php
/** Public pages: the marketing website, the enquiry API, logos, unsubscribe links, robots/sitemap and web cron. */
declare(strict_types=1);

route('GET', '/', function () {
    if (on_platform()) {
        return redirect('/platform/');
    }
    if (!owns_static_site()) {
        return redirect('/account/login');  // other organisations use the portal only
    }
    return new Response(site_render('index', content_published()), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
});

route('GET', '/{page:[a-z-]+}.html', function (string $page) {
    if (on_platform()) {
        return redirect('/platform/');
    }
    if ($page === 'portal') {
        return redirect('/account/');
    }
    if (!owns_static_site()) {
        return redirect('/account/login');
    }
    if ($page === 'index') {
        return redirect('/');
    }
    if (!site_page_exists($page)) {
        abort(404);
    }
    return new Response(site_render($page, content_published()), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
});

route('GET', '/robots.txt', function () {
    $base = rtrim((string) config('base_url'), '/');
    $body = config('noindex') ? "User-agent: *\nDisallow: /\n"
        : "User-agent: *\nDisallow: /account/\nDisallow: /admin/\nDisallow: /api/\nDisallow: /o/\nDisallow: /console\n\nSitemap: $base/sitemap.xml\n";
    return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});

route('GET', '/sitemap.xml', function () {
    $base = rtrim((string) config('base_url'), '/');
    $urls = '';
    foreach (PREVIEW_PAGES as $p) {
        $urls .= '  <url><loc>' . e($base . '/' . ($p === 'index' ? '' : "$p.html")) . "</loc></url>\n";
    }
    return new Response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n$urls</urlset>\n",
        200, ['Content-Type' => 'application/xml']);
});

// ---------------------------------------------------------------- JSON API for the static pages
route('GET', '/api/csrf', fn() => json_response(['token' => csrf_token()]));

route('GET', '/api/health', function () {
    qv('SELECT 1');
    return json_response(['ok' => true]);
});

route('POST', '/api/enquiries', function () {
    $data = json_body();
    if (!empty($data['website'])) {  // honeypot field, invisible to people
        return json_response(['ok' => true]);
    }
    if (throttled('enquiry:' . client_ip(), 5, 3600)) {
        return json_response(['ok' => false, 'error' => 'Too many enquiries from this connection. Please try again later.'], 429);
    }
    $f = [];
    foreach (['name', 'company', 'email', 'phone', 'topic', 'message'] as $k) {
        $f[$k] = trim((string) ($data[$k] ?? ''));
    }
    $errors = [];
    if (mb_strlen($f['name']) < 2 || mb_strlen($f['name']) > 120) {
        $errors['name'] = 'Enter your name.';
    }
    if (mb_strlen($f['company']) < 2 || mb_strlen($f['company']) > 200) {
        $errors['company'] = 'Enter your business name.';
    }
    if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $f['email']) || strlen($f['email']) > 200) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($f['phone'] !== '' && !preg_match('/^[+0-9 ()-]{6,40}$/', $f['phone'])) {
        $errors['phone'] = 'Enter a phone number using digits, spaces and + only.';
    }
    if (mb_strlen($f['message']) > 4000) {
        $errors['message'] = 'Please keep your message under 4,000 characters.';
    }
    if (empty($data['consent'])) {
        $errors['consent'] = 'Please agree so we can contact you about your enquiry.';
    }
    if ($errors) {
        return json_response(['ok' => false, 'errors' => $errors], 400);
    }
    $topic = isset(TOPICS[$f['topic']]) ? $f['topic'] : 'general';
    $id = x('INSERT INTO enquiries (org_id, name, company, email, phone, topic, message, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [org_id(), $f['name'], $f['company'], $f['email'], $f['phone'], $topic, $f['message'], now()]);
    audit_log('enquiry.received', 'enquiry', $id, [], false);
    notify_staff_enquiry(q1('SELECT * FROM enquiries WHERE id = ?', [$id]));
    notify_staff('handle_enquiries', 'New website enquiry from ' . $f['name'], "/admin/enquiries/$id");
    return json_response(['ok' => true]);
});

route('GET', '/branding/logo', function () {
    $org = current_org();
    if ($org === null || !$org['logo_file'] || !preg_match('/^[0-9a-f]{24}\.png$/', $org['logo_file'])) {
        abort(404);
    }
    $path = storage_dir() . "/branding/{$org['id']}/{$org['logo_file']}";
    if (!is_file($path)) {
        abort(404);
    }
    return new Response('', 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400'], $path);
});

// ---------------------------------------------------------------- unsubscribe (signed links in marketing emails)
route('GET|POST', '/marketing/unsubscribe/{token}', function (string $token) {
    [$org, $email] = unsubscribe_from_token($token);
    $done = false;
    if (is_post()) {
        do_unsubscribe($org, $email, 'unsubscribe link');
        $done = true;
    }
    return view('public/unsubscribe', ['email' => $email, 'done' => $done, 'token' => $token]);
});

route('POST', '/marketing/unsubscribe/{token}/one-click', function (string $token) {
    [$org, $email] = unsubscribe_from_token($token);
    do_unsubscribe($org, $email, 'one-click unsubscribe');
    return new Response('', 204);
});

// ---------------------------------------------------------------- scheduled jobs over HTTPS (for hosts without CLI cron)
route('GET|POST', '/cron/{job}', function (string $job) {
    $token = (string) config('cron_token');
    if (strlen($token) < 32 || !hash_equals($token, arg('token'))) {
        abort(404);
    }
    return new Response(run_job($job) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
});

/** Scheduled housekeeping, shared by cli.php and /cron/<job>. */
function run_job(string $job): string
{
    switch ($job) {
        case 'send-campaigns':
            return 'Sent ' . process_due_campaigns() . ' marketing email(s).';
        case 'subscription-housekeeping':
            $r = subscription_housekeeping();
            return "{$r['past_due']} subscription(s) now past due, {$r['reminders']} reminder(s) sent.";
        case 'purge-expired':
            $old = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
            $older = gmdate('Y-m-d H:i:s', time() - 90 * 86400);
            x('DELETE FROM password_resets WHERE expires_at < ?', [$old]);
            x('DELETE FROM email_verifications WHERE expires_at < ?', [$old]);
            x('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL OR expires_at < ?) AND last_seen_at < ?', [now(), $older]);
            x('DELETE FROM rate_limits WHERE at < ?', [microtime(true) - 86400]);
            return 'Expired tokens, sessions and rate-limit records removed.';
        case 'verify-audit':
            [$ok, $n, $bad] = verify_chain();
            return "Checked $n audit entries: " . ($ok ? 'chain intact.' : "CHAIN BROKEN at entry $bad.");
        case 'backup':
            $file = backup_database('backup');
            $cut = time() - 30 * 86400;
            foreach (glob(storage_dir() . '/backups/lmd-*') as $f) {
                if (filemtime($f) < $cut) {
                    @unlink($f);
                }
            }
            return 'Database backup written: ' . basename($file) . '. Copy backups off the server regularly.';
    }
    abort(404);
}
