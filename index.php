<?php
/** Front controller: every request that isn't a static file comes here. */
declare(strict_types=1);

define('LMD_DOCROOT', __DIR__);
$app = is_dir(__DIR__ . '/app') ? __DIR__ . '/app' : dirname(__DIR__) . '/app';

// PHP's built-in development server (local testing only): serve existing static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file) && !str_contains($file, '..')) {
        return false;
    }
}

require $app . '/bootstrap.php';
require $app . '/http.php';
handle_request();
