<?php
// Compare the PHP QR encoder with segno (cases from qr_cases.py).
define('LMD_APP', dirname(__DIR__) . '/app');
require LMD_APP . '/lib/qr.php';
Qr::$segnoPadding = true;
$cases = json_decode((string) @file_get_contents($argv[1]), true);
if (!$cases) {
    exit("No cases found.\n");
}
$fail = 0;
foreach ($cases as $c) {
    if (Qr::matrix($c['text'], $c['mask']) !== $c['rows']) {
        $fail++;
        echo "mismatch: v{$c['version']} mask {$c['mask']} len " . strlen($c['text']) . "\n";
    }
}
echo $fail ? "$fail of " . count($cases) . " QR codes differ\n" : count($cases) . " QR codes identical to segno\n";
exit($fail ? 1 : 0);
