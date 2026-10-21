<?php
/** Loads the platform. Included by public_html/index.php, cli.php and the tests. */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('This application needs PHP 8.1 or newer.');
}
define('LMD_APP', __DIR__);
define('LMD_ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');

foreach (['core', 'db', 'options', 'tenancy', 'security', 'audit', 'pricing', 'mail', 'content', 'pdf', 'qr', 'letters', 'workflow',
             'finance', 'platform', 'marketing', 'ai', 'html'] as $lib) {
    require_once LMD_APP . "/lib/$lib.php";
}
