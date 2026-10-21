<?php
/**
 * Core plumbing: configuration, time helpers, request access, responses, errors, templates and routing.
 *
 * Plain PHP with no dependencies so the platform runs on ordinary shared hosting (PHP 8.1+, pdo_sqlite, bcmath,
 * mbstring, openssl, gd, fileinfo). Nothing here is specific to one organisation.
 */
declare(strict_types=1);

// ---------------------------------------------------------------- configuration
/** Every setting the app reads, with its default. Override in lmd-storage/config.php (outside the web root). */
const CONFIG_DEFAULTS = [
    'env' => 'development',                 // development | staging | production (production refuses unsafe settings)
    'base_url' => 'http://localhost:8080',  // public address used in email links
    'secure_cookies' => false,              // true = cookies only over HTTPS (required in production)
    'session_idle_minutes' => 30,
    'session_hours' => 8,
    'require_staff_mfa' => false,           // staff and platform owners must use an authenticator app
    'max_upload_mb' => 10,
    'smtp_host' => '',                      // empty = emails are written to lmd-storage/outbox instead of sent
    'smtp_port' => 587,                     // 587 (STARTTLS) or 465 (TLS)
    'smtp_user' => '',
    'smtp_password' => '',
    'mail_from' => 'LMD Finance <no-reply@lmdglobalfinltd.com>',
    'mail_reply_to' => '',
    'staff_emails' => '',
    'mail_allowed_domains' => '',           // staging safety net: other recipient domains go to the outbox
    'marketing_enabled' => false,
    'marketing_smtp_host' => '',
    'marketing_smtp_port' => 587,
    'marketing_smtp_user' => '',
    'marketing_smtp_password' => '',
    'marketing_from' => 'LMD Finance <news@lmdglobalfinltd.com>',
    'marketing_rate_per_minute' => 60,
    'ai_api_key' => '',
    'ai_model' => 'claude-opus-5-5',
    'ai_staff_features' => false,
    // Multi-tenancy. On shared hosting organisations can be reached by path (/o/<slug>/) without any DNS change.
    'platform_host' => '',                  // a dedicated console host name, e.g. console.example.com (optional)
    'platform_path' => '/console',          // the console under a path on any host; empty to disable
    'tenant_base_domain' => '',             // <slug>.<this domain> picks an organisation (needs wildcard DNS)
    'tenant_path_prefix' => '/o',           // /o/<slug>/... picks an organisation; empty to disable
    'default_org' => 'lmd',                 // organisation for hosts that match nothing else
    'static_site_org' => 'lmd',             // organisation that owns the public marketing pages
    'setup_token' => '',                    // long random value: enables the one-time /setup page until accounts exist
    'cron_token' => '',                    // long random value: allows /cron/<job>?token=... for hosts without CLI cron
    'staging_password' => '',               // non-empty = whole site behind HTTP basic auth (user "staging")
    'noindex' => false,                     // true = tell search engines not to index anything
];

function config(?string $key = null)
{
    if (!isset($GLOBALS['__lmd_config'])) {
        $cfg = CONFIG_DEFAULTS;
        foreach ([storage_dir() . '/config.php', LMD_APP . '/config.local.php'] as $file) {
            if (is_file($file)) {
                $extra = require $file;
                if (is_array($extra)) {
                    $cfg = array_merge($cfg, $extra);
                }
            }
        }
        $GLOBALS['__lmd_config'] = $cfg;
    }
    return $key === null ? $GLOBALS['__lmd_config'] : ($GLOBALS['__lmd_config'][$key] ?? null);
}

/** Tests and CLI only: change settings for this process. */
function config_override(array $values): void
{
    config();
    $GLOBALS['__lmd_config'] = array_merge($GLOBALS['__lmd_config'], $values);
}

/** Private data folder (database, uploads, letters, outbox, backups, config). Never inside the web root. */
function storage_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = getenv('LMD_STORAGE_DIR') ?: (defined('LMD_DOCROOT') ? dirname(LMD_DOCROOT) . '/lmd-storage' : dirname(LMD_APP) . '/lmd-storage');
        foreach (['', '/uploads', '/generated', '/outbox', '/backups', '/branding', '/sessions', '/logs'] as $sub) {
            if (!is_dir($dir . $sub)) {
                @mkdir($dir . $sub, 0700, true);
            }
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }
    return $dir;
}

function production(): bool
{
    return in_array(config('env'), ['production', 'staging'], true);
}

/** Problems that make a production install unsafe. The app refuses to serve pages while any exist. */
function startup_errors(): array
{
    if (!production()) {
        return [];
    }
    $errors = [];
    if (!config('secure_cookies')) {
        $errors[] = "secure_cookies must be true (the site must run over HTTPS).";
    }
    if (!str_starts_with((string) config('base_url'), 'https://')) {
        $errors[] = "base_url must be an https:// address.";
    }
    return $errors;
}

function secret_key(): string
{
    static $key = null;
    if ($key === null) {
        $path = storage_dir() . '/secret_key';
        if (!is_file($path)) {
            file_put_contents($path, bin2hex(random_bytes(32)));
            @chmod($path, 0600);
        }
        $key = trim((string) file_get_contents($path));
    }
    return $key;
}

// ---------------------------------------------------------------- time (UTC everywhere)
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function later(int $seconds): string
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

function today(): string
{
    return gmdate('Y-m-d');
}

function valid_date(?string $text): ?string
{
    $text = trim((string) $text);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
        return null;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $text));
    return checkdate($m, $d, $y) ? $text : null;
}

// ---------------------------------------------------------------- small helpers
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sha256(string $value): string
{
    return hash('sha256', $value);
}

function token_urlsafe(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function json_out($data, int $flags = 0): string
{
    return json_encode($data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

/** JSON with object keys sorted, for hashing frozen pricing snapshots. */
function json_sorted($data): string
{
    $sort = function ($v) use (&$sort) {
        if (is_array($v)) {
            if (array_is_list($v)) {
                return array_map($sort, $v);
            }
            ksort($v, SORT_STRING);
            return array_map($sort, $v);
        }
        return $v;
    };
    return json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function truncate(?string $s, int $n): string
{
    return mb_substr((string) $s, 0, $n);
}

function csv_list(?string $text): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) $text)), fn($x) => $x !== ''));
}

function ref_str(int $id): string
{
    return ref_prefix() . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function fmt_date(?string $value): string
{
    return str_replace('T', ' ', mb_substr((string) $value, 0, 16));
}

function pager_args(array $changes): string
{
    return http_build_query(array_merge($_GET, $changes));
}

// ---------------------------------------------------------------- request
function method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return method() === 'POST';
}

/** The request path inside the current organisation or console (prefix such as /o/acme removed). */
function path(): string
{
    return $GLOBALS['__lmd_path'] ?? '/';
}

function raw_path(): string
{
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return '/' . ltrim(rawurldecode($p), '/');
}

function host(): string
{
    return strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
}

function form(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function form_list(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? array_values(array_filter($v, 'is_string')) : (is_string($v) ? [$v] : []);
}

function form_int(string $key): ?int
{
    $v = trim(form($key));
    return preg_match('/^-?\d{1,12}$/', $v) ? (int) $v : null;
}

function arg(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? $v : $default;
}

function arg_int(string $key, ?int $default = null): ?int
{
    $v = trim(arg($key));
    return preg_match('/^-?\d{1,12}$/', $v) ? (int) $v : $default;
}

function json_body(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function safe_next(?string $target): ?string
{
    // Only local paths, never another site (open-redirect protection).
    $target = (string) $target;
    if ($target !== '' && $target[0] === '/' && !str_starts_with($target, '//') && !str_contains($target, '\\')) {
        return $target;
    }
    return null;
}

// ---------------------------------------------------------------- responses and errors
final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?string $file = null,
    ) {
    }
}

final class HttpError extends Exception
{
    public function __construct(public int $status, string $message = '')
    {
        parent::__construct($message);
    }
}

function abort(int $status, string $message = ''): never
{
    throw new HttpError($status, $message);
}

/** Redirect to a path inside the current organisation (or console). Absolute URLs are not accepted. */
function redirect(string $to): Response
{
    return new Response('', 303, ['Location' => url($to)]);
}

function json_response($data, int $status = 200): Response
{
    return new Response(json_out($data), $status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
}

/** Path inside the current organisation or console, e.g. url('/admin/') -> /o/acme/admin/. */
function url(string $path): string
{
    if (preg_match('#^https?://#', $path)) {
        return $path;
    }
    return ($GLOBALS['__lmd_base'] ?? '') . $path;
}

function flash(string $message, string $category = 'info'): void
{
    $_SESSION['_flash'][] = [$category, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

// ---------------------------------------------------------------- templates
/** Render app/views/<name>.php inside the layout (unless the view sets $layout = null). */
function render(string $name, array $vars = []): string
{
    $__file = LMD_APP . '/views/' . $name . '.php';
    if (!is_file($__file)) {
        throw new RuntimeException("Missing view $name");
    }
    $layout = 'layout';
    $title = '';
    $body_class = '';
    extract($vars, EXTR_SKIP);
    ob_start();
    include $__file;
    $content = ob_get_clean();
    if ($layout === null) {
        return $content;
    }
    ob_start();
    include LMD_APP . '/views/' . $layout . '.php';
    return ob_get_clean();
}

function view(string $name, array $vars = [], int $status = 200): Response
{
    return new Response(render($name, $vars), $status, ['Content-Type' => 'text/html; charset=utf-8']);
}

/** Render a partial (no layout) and return its HTML. */
function partial(string $name, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include LMD_APP . '/views/' . $name . '.php';
    return ob_get_clean();
}

// ---------------------------------------------------------------- routing
final class Router
{
    private array $routes = [];

    public function add(string $methods, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($m) {
            $re = $m[2] ?? ($m[1] === 'token' ? '[A-Za-z0-9_.-]+' : ($m[1] === 'action' || $m[1] === 'page' ? '[a-z_-]+' : '\d+'));
            return '(?P<' . $m[1] . '>' . $re . ')';
        }, $pattern);
        $this->routes[] = [explode('|', $methods), '#^' . $regex . '$#', $handler];
    }

    public function dispatch(string $method, string $path)
    {
        $allowed = false;
        foreach ($this->routes as [$methods, $regex, $handler]) {
            if (preg_match($regex, $path, $m)) {
                if (!in_array($method, $methods, true) && !($method === 'HEAD' && in_array('GET', $methods, true))) {
                    $allowed = true;
                    continue;
                }
                $params = [];
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $params[] = ctype_digit($v) && strlen($v) < 12 ? (int) $v : $v;
                    }
                }
                return $handler(...$params);
            }
        }
        abort($allowed ? 405 : 404);
    }
}

function router(): Router
{
    static $r = null;
    return $r ??= new Router();
}

function route(string $methods, string $pattern, callable $handler): void
{
    router()->add($methods, $pattern, $handler);
}

/** Navigation helper: is this the current page (or one of its sub-pages)? */
function is_current(string $path, array $prefixes = []): bool
{
    $p = path();
    if ($p === $path) {
        return true;
    }
    foreach ($prefixes as $prefix) {
        if (str_starts_with($p, $prefix)) {
            return true;
        }
    }
    return false;
}
