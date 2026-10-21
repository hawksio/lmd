<?php
/**
 * Build the upload package for shared hosting: dist/lmd-php-<date>.zip, whose contents go straight into public_html.
 *   index.php, .htaccess, assets/, brand/, images/ and app/ (application code, blocked from the web by .htaccess).
 * Nothing private is included: no database, no uploads, no configuration with secrets, no tests.
 * Usage: php tools/package.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = "$root/dist";
if (!is_dir($dist)) {
    mkdir($dist);
}
$zipPath = "$dist/lmd-php-" . gmdate('Ymd-His') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    exit("Could not create $zipPath\n");
}
$skip = '#(^|/)(\.git|config\.local\.php|.*\.sqlite3|pdf-check.*|dev-.*\.txt)$#';
$add = function (string $src, string $dest) use ($zip, $skip) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($src) + 1));
        if (!preg_match($skip, $rel)) {
            $zip->addFile($file->getPathname(), ltrim("$dest/$rel", '/'));
        }
    }
};
foreach (['index.php', '.htaccess'] as $f) {
    $zip->addFile("$root/public_html/$f", $f);
}
foreach (['assets', 'brand', 'images'] as $dir) {
    $add("$root/public_html/$dir", $dir);
}
$add("$root/app", 'app');
// Optional: the server's private configuration (php tools/package.php path/to/config.local.php).
if (isset($argv[1]) && is_file($argv[1])) {
    $zip->addFile($argv[1], 'app/config.local.php');
}
$zip->close();
printf("Package written: %s (%.1f MB)\n", $zipPath, filesize($zipPath) / 1048576);
