<?php
/**
 * Loan pricing: applies an organisation's configured financial rules to an amount and term.
 *
 * Pure calculation (no database writes). Money is integers in minor units (pence/cents); percentages are exact
 * decimal strings and all arithmetic uses bcmath at 30 decimal places, so results match the Flask edition. The quote
 * is stored unchanged on every approval decision ("pricing snapshot"): editing a rule later never changes an offer,
 * agreement or schedule that already exists.
 *
 * Calculations are arithmetic only. They do not make any rate, fee or APR lawful or suitable: each organisation is
 * responsible for the values it configures, and the calculated APR must be checked against the rules that apply to it
 * before it is shown to customers.
 */
declare(strict_types=1);

const PERIODS_PER_YEAR = ['monthly' => 12, 'fortnightly' => 26, 'weekly' => 52];
const BC = 30;

final class PricingError extends Exception
{
}

/** Validate and normalise a decimal string ("2.50" -> "2.50"; rejects anything that isn't a plain number). */
function dec($value): string
{
    $s = trim((string) $value);
    if (!preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $s)) {
        throw new PricingError('Enter a number.');
    }
    if ($s[0] === '+') {
        $s = substr($s, 1);
    }
    if ($s[0] === '.' || str_starts_with($s, '-.')) {
        $s = str_replace('.', '0.', $s);
    }
    return rtrim($s, '.');
}

function dec_cmp(string $a, string $b): int
{
    return bccomp($a, $b, BC);
}

// Decimal arithmetic with the same rules as Python's decimal module (28 significant digits, round half even after
// every operation), so every figure matches the Flask edition to the penny.
const WORK = 80;

function d28(string $x): string
{
    $neg = str_starts_with($x, '-');
    [$int, $frac] = array_pad(explode('.', ltrim($x, '-'), 2), 2, '');
    // x = $digits * 10^$exp, with $digits a whole number.
    $digits = ltrim($int . $frac, '0');
    $exp = -strlen($frac);
    if ($digits === '') {
        return '0';
    }
    if (strlen($digits) > 28) {
        $rest = substr($digits, 28);
        $keep = substr($digits, 0, 28);
        $exp += strlen($rest);
        $roundUp = $rest[0] > '5' || ($rest[0] === '5' && (trim(substr($rest, 1), '0') !== '' || ((int) $keep[27]) % 2 === 1));
        $digits = $roundUp ? bcadd($keep, '1', 0) : $keep;
    }
    if ($exp >= 0) {
        $out = $digits . str_repeat('0', $exp);
    } else {
        $digits = str_pad($digits, -$exp + 1, '0', STR_PAD_LEFT);
        $out = substr($digits, 0, $exp) . '.' . substr($digits, $exp);
    }
    return ($neg ? '-' : '') . $out;
}

function dadd(string $a, string $b): string
{
    return d28(bcadd($a, $b, WORK));
}

function dsub(string $a, string $b): string
{
    return d28(bcsub($a, $b, WORK));
}

function dmul(string $a, string $b): string
{
    return d28(bcmul($a, $b, WORK));
}

function ddiv(string $a, string $b): string
{
    return d28(bcdiv($a, $b, WORK));
}

/** Remove trailing zeros: "2.500" -> "2.5", "100" -> "100", "0.0" -> "0". */
function dec_normal(string $d): string
{
    if (str_contains($d, '.')) {
        $d = rtrim(rtrim($d, '0'), '.');
    }
    if ($d === '' || $d === '-0' || bccomp($d, '0', BC) === 0) {
        return '0';
    }
    return $d;
}

/** Round half away from zero to a whole number of minor units. */
function to_minor(string $amount): int
{
    $neg = bccomp($amount, '0', WORK) < 0;
    $abs = $neg ? bcmul($amount, '-1', WORK) : $amount;
    $r = bcadd($abs, '0.5', 0);  // bcadd with scale 0 truncates: floor(x + 0.5) for positives
    return $neg ? -(int) $r : (int) $r;
}

/** '10,000.50' -> 1000050 minor units. null if invalid. */
function parse_money(?string $text): ?int
{
    $text = trim(str_replace([',', '£', '€', '$'], '', (string) $text));
    if (!preg_match('/^(\d+(\.\d*)?|\.\d+)$/', $text)) {
        return null;
    }
    $parts = explode('.', $text);
    if (isset($parts[1]) && strlen($parts[1]) > 2) {
        return null;
    }
    $d = dec($text);
    if (bccomp($d, '1000000000', BC) > 0) {
        return null;
    }
    return to_minor(bcmul($d, '100', BC));
}

function money($minor, string $currency = 'GBP'): string
{
    $minor = (int) $minor;
    $symbol = CURRENCIES[$currency] ?? ($currency . ' ');
    $sign = $minor < 0 ? '-' : '';
    $abs = abs($minor);
    return $sign . $symbol . number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** Whole currency units (requested amounts on the application form). */
function money_whole($units, ?string $currency = null): string
{
    if ($units === null || $units === '') {
        return '';
    }
    $currency = $currency ?? (current_org()['default_currency'] ?? 'GBP');
    return substr(money(((int) $units) * 100, $currency), 0, -3);
}

/** A normalised percentage string, or PricingError. $cap is the maximum allowed. */
function validate_percent($text, $cap): string
{
    $p = dec($text);
    if (bccomp($p, '0', BC) < 0) {
        throw new PricingError("A percentage can't be negative.");
    }
    if (str_contains($p, '.') && strlen(explode('.', $p)[1]) > 4) {
        throw new PricingError('Use at most 4 decimal places.');
    }
    if (bccomp($p, dec($cap), BC) > 0) {
        throw new PricingError("This organisation's limit for this setting is {$cap}%.");
    }
    return dec_normal($p);
}

// ---------------------------------------------------------------- rule selection
function rule_applies(array $rule, $productId, string $currency, string $onDate): bool
{
    if (!$rule['enabled']) {
        return false;
    }
    if ($rule['effective_from'] > $onDate || ($rule['effective_to'] && $rule['effective_to'] < $onDate)) {
        return false;
    }
    $products = csv_list($rule['product_ids']);
    $currencies = csv_list($rule['currencies']);
    return (!$products || in_array((string) $productId, $products, true)) && (!$currencies || in_array($currency, $currencies, true));
}

/** [interest rule or null, other rules]. A product-specific interest rate beats an all-products one. */
function select_rules(array $rules, $productId, string $currency, string $onDate): array
{
    $live = array_values(array_filter($rules, fn($r) => rule_applies($r, $productId, $currency, $onDate)));
    $interest = array_values(array_filter($live, fn($r) => $r['kind'] === 'interest'));
    if (count($interest) > 1) {
        $specific = array_values(array_filter($interest, fn($r) => csv_list($r['product_ids'])));
        $interest = $specific ?: $interest;
        if (count($interest) > 1) {
            throw new PricingError('More than one interest rate applies to this product. Disable or date-limit one of them.');
        }
    }
    return [$interest[0] ?? null, array_values(array_filter($live, fn($r) => $r['kind'] !== 'interest'))];
}

function rule_amount(array $rule, int $basisMinor): int
{
    if ($rule['method'] === 'fixed') {
        return (int) $rule['fixed_minor'];
    }
    return to_minor(ddiv(dmul((string) $basisMinor, dec($rule['percent'])), '100'));
}

// ---------------------------------------------------------------- schedule
/** Instalments [{number, principal, interest, fees, amount, balance}]. Rounding differences go to the last one. */
function build_schedule(int $principalMinor, $annualPercent, int $termMonths, string $frequency, string $method,
                        int $graceMonths = 0, int $firstFeeMinor = 0): array
{
    $ppy = PERIODS_PER_YEAR[$frequency];
    $n = max(1, to_minor(ddiv(dmul((string) $termMonths, (string) $ppy), '12')));
    $grace = min($n - 1, to_minor(ddiv(dmul((string) $graceMonths, (string) $ppy), '12')));
    $annual = dec($annualPercent);
    $rate = ddiv(ddiv($annual, '100'), (string) $ppy);
    $principal = (string) $principalMinor;
    $repaying = $n - $grace;
    $rows = [];
    if ($method === 'flat') {
        $totalInterest = ddiv(dmul(ddiv(dmul($principal, $annual), '100'), (string) $termMonths), '12');
        $interestEach = ddiv($totalInterest, (string) $n);
        $principalEach = ddiv($principal, (string) $repaying);
        for ($k = 1; $k <= $n; $k++) {
            $rows[] = [$k <= $grace ? '0' : $principalEach, $interestEach];
        }
    } else {
        if (bccomp($rate, '0', WORK) === 0) {
            $level = ddiv($principal, (string) $repaying);
        } else {
            $factor = d28(bcdiv('1', bcpow(dadd('1', $rate), (string) $repaying, 160), 160));  // (1+r)^-n
            $level = ddiv(dmul($principal, $rate), dsub('1', $factor));
        }
        $balance = $principal;
        for ($k = 1; $k <= $n; $k++) {
            $interest = dmul($balance, $rate);
            $p = $k <= $grace ? '0' : dsub($level, $interest);
            $balance = dsub($balance, $p);
            $rows[] = [$p, $interest];
        }
    }
    $interestTotal = '0';
    foreach ($rows as $r) {
        $interestTotal = dadd($interestTotal, $r[1]);
    }
    $out = [];
    $remaining = $principalMinor;
    $interestPaid = 0;
    foreach ($rows as $i => [$p, $int]) {
        $k = $i + 1;
        if ($k === $n) {
            $pMinor = $remaining;
            $iMinor = to_minor($interestTotal) - $interestPaid;
        } else {
            $pMinor = to_minor($p);
            $iMinor = to_minor($int);
        }
        $remaining -= $pMinor;
        $interestPaid += $iMinor;
        $fees = $k === 1 ? $firstFeeMinor : 0;
        $out[] = ['number' => $k, 'principal' => $pMinor, 'interest' => $iMinor, 'fees' => $fees,
            'amount' => $pMinor + $iMinor + $fees, 'balance' => $remaining];
    }
    return $out;
}

/** Annual rate X solving: net advance = sum(payment_k / (1+X)^t_k), t_k in years. Bisection. */
function calculated_apr(int $netAdvance, array $schedule, string $frequency): ?float
{
    if ($netAdvance <= 0 || !$schedule) {
        return null;
    }
    $ppy = PERIODS_PER_YEAR[$frequency];
    $flows = array_map(fn($r) => [$r['number'] / $ppy, (float) $r['amount']], $schedule);
    $pv = function (float $x) use ($flows): float {
        $s = 0.0;
        foreach ($flows as [$t, $a]) {
            $s += $a / (1 + $x) ** $t;
        }
        return $s;
    };
    if ($pv(0.0) < $netAdvance) {
        return 0.0;
    }
    $lo = 0.0;
    $hi = 50.0;
    for ($i = 0; $i < 200; $i++) {
        $mid = ($lo + $hi) / 2;
        if ($pv($mid) > $netAdvance) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return round($lo * 100, 1);
}

// ---------------------------------------------------------------- the quote
function rule_view(array $r, ?int $basisMinor): array
{
    return ['rule_id' => (int) $r['id'], 'version' => (int) $r['version'], 'kind' => $r['kind'], 'name' => $r['name'],
        'method' => $r['method'], 'percent' => $r['percent'], 'fixed' => $r['fixed_minor'] === null ? null : (int) $r['fixed_minor'],
        'basis' => $r['basis'], 'basis_amount' => $basisMinor, 'timing' => $r['timing'], 'disclosure' => $r['disclosure'],
        'description' => $r['description'], 'arrangement_reference' => $r['arrangement_reference'] ?? ''];
}

/** Full breakdown for an approval. Returns a JSON-safe array, frozen on the decision. */
function pricing_quote(array $product, array $rules, int $requested, int $approved, int $termMonths, string $currency,
                       ?string $onDate = null): array
{
    $onDate = $onDate ?? today();
    if ($approved <= 0) {
        throw new PricingError('The approved amount must be more than zero.');
    }
    [$interestRule, $others] = select_rules($rules, $product['id'], $currency, $onDate);
    $annual = $interestRule ? dec($interestRule['percent']) : '0';
    $fees = [];
    $disclosures = [];
    foreach ($others as $r) {
        if (in_array($r['basis'], ['outstanding', 'instalment'], true) || $r['timing'] === 'on_overdue_instalment') {
            $disclosures[] = rule_view($r, null);  // late charges: disclosed now, calculated only if they arise
            continue;
        }
        $basis = $r['basis'] === 'requested' ? $requested : $approved;
        $item = rule_view($r, $basis);
        $item['amount'] = rule_amount($r, $basis);
        if ($r['timing'] === 'not_charged_to_customer') {
            $disclosures[] = $item;
        } else {
            $fees[] = $item;
        }
    }
    $sum = fn(string $timing) => array_sum(array_map(fn($f) => $f['timing'] === $timing ? $f['amount'] : 0, $fees));
    $added = $sum('added_to_loan');
    $deducted = $sum('deducted_from_disbursement');
    $first = $sum('with_first_instalment');
    if ($deducted >= $approved) {
        throw new PricingError('Fees deducted from the payment would be more than the amount approved.');
    }
    $financed = $approved + $added;
    $schedule = build_schedule($financed, $annual, $termMonths, $product['repayment_frequency'], $product['interest_method'],
        (int) $product['grace_period_months'], $first);
    $totalRepayable = array_sum(array_column($schedule, 'amount'));
    $net = $approved - $deducted;
    return [
        'currency' => $currency,
        'requested' => $requested,
        'approved' => $approved,
        'unapproved' => max(0, $requested - $approved),
        'term_months' => $termMonths,
        'product' => ['id' => (int) $product['id'], 'name' => $product['name'], 'interest_method' => $product['interest_method'],
            'repayment_frequency' => $product['repayment_frequency'], 'grace_period_months' => (int) $product['grace_period_months']],
        'interest' => $interestRule ? rule_view($interestRule, null) : null,
        'annual_rate' => dec_normal($annual),
        'fees' => $fees,
        'disclosures' => $disclosures,
        'fees_added_to_loan' => $added,
        'fees_deducted' => $deducted,
        'fees_first_instalment' => $first,
        'financed_principal' => $financed,
        'net_advance' => $net,
        'total_interest' => array_sum(array_column($schedule, 'interest')),
        'total_fees' => $added + $deducted + $first,
        'total_repayable' => $totalRepayable,
        'total_charge_for_credit' => $totalRepayable - $net,
        'instalments' => count($schedule),
        'regular_payment' => count($schedule) > 1 ? $schedule[1]['amount'] : $schedule[0]['amount'],
        'apr_calculated' => calculated_apr($net, $schedule, $product['repayment_frequency']),
        'schedule' => $schedule,
        'calculated_on' => $onDate,
    ];
}

/** Proves which exact figures a customer saw when accepting an offer. */
function snapshot_hash(array $pricing): string
{
    return hash('sha256', json_sorted($pricing));
}

/** Turn the frozen terms into real due dates, for the principal actually lent. */
function dated_schedule(array $pricing, int $principalMinor, string $firstDue): array
{
    $p = $pricing['product'];
    $rows = build_schedule($principalMinor, $pricing['annual_rate'], (int) $pricing['term_months'], $p['repayment_frequency'],
        $p['interest_method'], (int) $p['grace_period_months'], (int) ($pricing['fees_first_instalment'] ?? 0));
    foreach ($rows as $i => &$row) {
        $row['due_date'] = schedule_step($firstDue, $p['repayment_frequency'], $i);
    }
    return $rows;
}

function schedule_step(string $start, string $frequency, int $i): string
{
    $d = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    if ($frequency === 'weekly') {
        return $d->modify("+$i week")->format('Y-m-d');
    }
    if ($frequency === 'fortnightly') {
        return $d->modify('+' . (2 * $i) . ' week')->format('Y-m-d');
    }
    [$y, $m, $day] = array_map('intval', explode('-', $start));
    $month = $m - 1 + $i;
    $year = $y + intdiv($month, 12);
    $month = $month % 12 + 1;
    $last = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
}
