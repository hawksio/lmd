<?php
/** Request handling: tenant resolution, session, CSRF, routing, error pages and security headers. */
declare(strict_types=1);

foreach (glob(LMD_APP . '/controllers/*.php') as $controller) {
    require_once $controller;
}

const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-src 'self'; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

function handle_request(): void
{
    try {
        $missing = array_values(array_filter(['pdo_sqlite', 'bcmath', 'mbstring', 'openssl', 'gd', 'fileinfo', 'iconv'], fn($x) => !extension_loaded($x)));
        if ($missing) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This server is missing PHP extensions the platform needs: ' . implode(', ', $missing)
                . ". Turn them on in the hosting control panel (PHP configuration), then reload.\n";
            return;
        }
        if (!is_dir(storage_dir()) || !is_writable(storage_dir())) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "The private storage folder (lmd-storage, next to public_html) is missing or not writable.\n";
            return;
        }
        $problems = startup_errors();
        if ($problems) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Unsafe production configuration:\n- " . implode("\n- ", $problems) . "\n";
            return;
        }
        if (($pw = (string) config('staging_password')) !== '') {
            // Whole-site lock for a private preview: user "staging" and the configured password.
            $sent = $_SERVER['PHP_AUTH_PW'] ?? null;
            if ($sent === null && preg_match('/^Basic\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) {
                $sent = explode(':', (string) base64_decode($m[1]), 2)[1] ?? null;
            }
            if ($sent === null || !hash_equals($pw, (string) $sent)) {
                send_response(new Response('Private preview. Sign in with the staging password.', 401,
                    ['WWW-Authenticate' => 'Basic realm="Private preview", charset="UTF-8"', 'Content-Type' => 'text/plain; charset=utf-8']));
                return;
            }
        }
        migrate();
        resolve_tenant();
        start_session();
        check_csrf();
        $response = router()->dispatch(method(), path());
        if (is_string($response)) {
            $response = new Response($response, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
    } catch (HttpError $e) {
        $response = error_response($e->status, $e->getMessage());
    } catch (Throwable $e) {
        error_log('Unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        @file_put_contents(storage_dir() . '/logs/error.log', gmdate('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . ' at '
            . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
        $response = error_response(500, production() ? '' : $e->getMessage());
    }
    send_response($response);
}

function error_response(int $status, string $message): Response
{
    if (str_starts_with(path(), '/api/')) {
        return json_response(['ok' => false, 'error' => $message ?: 'Error'], $status);
    }
    try {
        if (session_status() !== PHP_SESSION_ACTIVE && (current_org() || on_platform())) {
            start_session();
        }
        return view('error', ['code' => $status, 'message' => $message], $status);
    } catch (Throwable $e) {
        return new Response("Error $status", $status, ['Content-Type' => 'text/plain']);
    }
}

function send_response(Response $r): void
{
    http_response_code($r->status);
    $headers = $r->headers + [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Content-Security-Policy' => CSP,
    ];
    if (config('secure_cookies')) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }
    $type = $headers['Content-Type'] ?? 'text/html; charset=utf-8';
    if (str_starts_with($type, 'text/html') || preg_match('#^/(account|admin|api|platform)#', path())) {
        $headers['Cache-Control'] ??= 'no-store';
    }
    if (config('noindex')) {
        $headers['X-Robots-Tag'] = 'noindex, nofollow';
    }
    $headers['Content-Type'] = $type;
    foreach ($headers as $k => $v) {
        header("$k: $v");
    }
    if ($r->file !== null) {
        header('Content-Length: ' . filesize($r->file));
        readfile($r->file);
    } elseif (method() !== 'HEAD') {
        echo $r->body;
    }
}
