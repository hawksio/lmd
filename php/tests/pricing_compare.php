<?php
/** Check the PHP pricing engine gives exactly the Flask edition's results (cases from pricing_cases.py). */
declare(strict_types=1);
define('LMD_APP', dirname(__DIR__) . '/app');
require LMD_APP . '/lib/core.php';
require LMD_APP . '/lib/options.php';
require LMD_APP . '/lib/pricing.php';

function current_org(): ?array
{
    return null;
}

$data = json_decode(file_get_contents($argv[1]), true);
$fail = 0;
$normalise = function ($v) use (&$normalise) {
    if (is_array($v)) {
        return array_map($normalise, $v);
    }
    return is_float($v) ? round($v, 6) : $v;
};
foreach ($data['cases'] as $i => $c) {
    try {
        $q = pricing_quote($c['product'], $c['rules'], $c['requested'], $c['approved'], $c['term'], 'GBP', '2026-10-20');
        $got = ['ok' => true, 'quote' => $q, 'dated' => dated_schedule($q, $c['approved'], '2026-01-31')];
    } catch (PricingError $e) {
        $got = ['ok' => false, 'error' => $e->getMessage()];
    }
    if ($normalise($got) != $normalise($c['expected'])) {
        $fail++;
        if ($fail <= 3) {
            echo "Case $i differs\n";
            foreach (['ok', 'error'] as $k) {
                if (($got[$k] ?? null) !== ($c['expected'][$k] ?? null)) {
                    echo "  $k: " . json_encode($got[$k] ?? null) . ' vs ' . json_encode($c['expected'][$k] ?? null) . "\n";
                }
            }
            if (isset($got['dated']) && $normalise($got['dated']) != $normalise($c['expected']['dated'])) {
                foreach ($c['expected']['dated'] as $j => $row) {
                    if ($normalise($got['dated'][$j] ?? null) != $normalise($row)) {
                        echo "  dated[$j]: " . json_encode($got['dated'][$j] ?? null) . "\n   vs " . json_encode($row) . "\n";
                        break;
                    }
                }
            }
            if (isset($got['quote'], $c['expected']['quote'])) {
                foreach ($c['expected']['quote'] as $k => $v) {
                    if ($normalise($got['quote'][$k] ?? null) != $normalise($v)) {
                        echo "  $k: " . substr(json_encode($got['quote'][$k] ?? null), 0, 300) . "\n   vs " . substr(json_encode($v), 0, 300) . "\n";
                    }
                }
            }
        }
    }
}
foreach ($data['extra'] as $x) {
    if (array_key_exists('money', $x)) {
        $got = parse_money($x['money']);
        $ok = $got === $x['expected'];
        $label = "parse_money({$x['money']})";
    } elseif (array_key_exists('percent', $x)) {
        try {
            $got = validate_percent($x['percent'], $x['cap']);
            $ok = isset($x['expected']) && $got === $x['expected'];
        } catch (PricingError $e) {
            $got = 'ERR ' . $e->getMessage();
            $ok = isset($x['error']) && $e->getMessage() === $x['error'];
        }
        $label = "validate_percent({$x['percent']}, {$x['cap']})";
    } else {
        $got = money($x['format'], $x['currency']);
        $ok = $got === $x['expected'];
        $label = "money({$x['format']}, {$x['currency']})";
    }
    if (!$ok) {
        $fail++;
        echo "$label: got " . json_encode($got) . ', expected ' . json_encode($x['expected'] ?? $x['error'] ?? null) . "\n";
    }
}
$total = count($data['cases']) + count($data['extra']);
echo ($total - $fail) . " of $total pricing checks match the Flask edition.\n";
exit($fail ? 1 : 0);
