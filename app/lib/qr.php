<?php
/**
 * QR code generator (byte mode, error correction level M, versions 1-10) for the two-step verification set-up
 * page, so authenticator apps can scan the secret. Follows ISO/IEC 18004; output is an inline SVG.
 */
declare(strict_types=1);

final class Qr
{
    // version => [ec codewords per block, [[blocks, data codewords], ...]] for level M
    private const BLOCKS = [
        1 => [10, [[1, 16]]], 2 => [16, [[1, 28]]], 3 => [26, [[1, 44]]], 4 => [18, [[2, 32]]], 5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]], 7 => [18, [[4, 31]]], 8 => [22, [[2, 38], [2, 39]]], 9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38],
        8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    /** Tests only: segno adds a whole zero byte after the terminator when already byte-aligned; copy that to compare. */
    public static bool $segnoPadding = false;
    private int $size;
    private array $m = [];      // [y][x] bool
    private array $fn = [];     // function-pattern modules
    private static array $exp = [];
    private static array $log = [];

    public static function svg(string $text, int $scale = 4, string $dark = '#112327'): string
    {
        $q = new self($text);
        $n = $q->size + 8;
        $path = '';
        for ($y = 0; $y < $q->size; $y++) {
            for ($x = 0; $x < $q->size; $x++) {
                if ($q->m[$y][$x]) {
                    $path .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        $px = $n * $scale;
        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$px\" height=\"$px\" viewBox=\"0 0 $n $n\" role=\"img\" aria-label=\"QR code\">"
            . "<rect width=\"$n\" height=\"$n\" fill=\"#ffffff\"/><path d=\"$path\" fill=\"$dark\" shape-rendering=\"crispEdges\"/></svg>";
    }

    /** Rows of 0/1 (tests compare this with another encoder). */
    public static function matrix(string $text, ?int $forceMask = null): array
    {
        $q = new self($text, $forceMask);
        return array_map(fn($row) => implode("", array_map(fn($b) => $b ? "1" : "0", $row)), $q->m);
    }

    public function __construct(string $text, ?int $forceMask = null)
    {
        if (!self::$exp) {
            $x = 1;
            for ($i = 0; $i < 255; $i++) {
                self::$exp[$i] = $x;
                self::$log[$x] = $i;
                $x <<= 1;
                if ($x & 0x100) {
                    $x ^= 0x11D;
                }
            }
        }
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = 0;
        foreach (self::BLOCKS as $v => [, $groups]) {
            $capacity = array_sum(array_map(fn($g) => $g[0] * $g[1], $groups));
            $needed = (int) ceil((4 + ($v < 10 ? 8 : 16) + 8 * count($bytes)) / 8);
            if ($needed <= $capacity) {
                $version = $v;
                break;
            }
        }
        if ($version === 0) {
            throw new RuntimeException('Text too long for a QR code.');
        }
        $this->size = 17 + 4 * $version;
        for ($y = 0; $y < $this->size; $y++) {
            $this->m[$y] = array_fill(0, $this->size, false);
            $this->fn[$y] = array_fill(0, $this->size, false);
        }
        $this->drawFunctionPatterns($version);
        $codewords = $this->codewords($bytes, $version);
        $this->drawCodewords($codewords);
        $best = 0;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormat($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                [$best, $bestScore] = [$mask, $score];
            }
            $this->applyMask($mask);  // undo (XOR)
        }
        $best = $forceMask ?? $best;
        $this->applyMask($best);
        $this->drawFormat($best);
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function drawFunctionPatterns(int $version): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat(0);
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $s - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function drawFormat(int $mask): void
    {
        $data = 0 << 3 | $mask;  // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = fn($i) => (($bits >> $i) & 1) === 1;
        $s = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($s - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $s - 15 + $i, $bit($i));
        }
        $this->set(8, $s - 8, true);
    }

    private function codewords(array $bytes, int $version): array
    {
        [$ecLen, $groups] = self::BLOCKS[$version];
        $capacity = array_sum(array_map(fn($g) => $g[0] * $g[1], $groups));
        $bits = '0100' . str_pad(decbin(count($bytes)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', self::$segnoPadding ? 8 - strlen($bits) % 8 : (8 - strlen($bits) % 8) % 8);
        $data = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($data) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $data[] = $pad;
        }
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $len);
                $offset += $len;
                $blocks[] = [$chunk, $this->ecc($chunk, $ecLen)];
            }
        }
        $out = [];
        $maxLen = max(array_map(fn($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as $b) {
                if ($i < count($b[0])) {
                    $out[] = $b[0][$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($blocks as $b) {
                $out[] = $b[1][$i];
            }
        }
        return $out;
    }

    private function mul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private function ecc(array $data, int $degree): array
    {
        $gen = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= $this->mul($c, self::$exp[$i]);
            }
            $gen = $next;
        }
        $rem = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[] = 0;
            for ($j = 0; $j < $degree; $j++) {
                $rem[$j] ^= $this->mul($gen[$j + 1], $factor);
            }
        }
        return $rem;
    }

    private function drawCodewords(array $data): void
    {
        $s = $this->size;
        $i = 0;
        $total = count($data) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $s; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $s - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fn[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $s = $this->size;
        $score = 0;
        $lines = [];
        for ($y = 0; $y < $s; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $s; $x++) {
                $row .= $this->m[$y][$x] ? '1' : '0';
                $col .= $this->m[$x][$y] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $r) {
                $score += 3 + strlen($r) - 5;
            }
            $score += 40 * (substr_count($line, '10111010000') + substr_count($line, '00001011101'));
        }
        $dark = 0;
        for ($y = 0; $y < $s; $y++) {
            for ($x = 0; $x < $s; $x++) {
                $dark += $this->m[$y][$x] ? 1 : 0;
                if ($x < $s - 1 && $y < $s - 1) {
                    $c = $this->m[$y][$x];
                    if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                        $score += 3;
                    }
                }
            }
        }
        $total = $s * $s;
        $score += max(0, (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10;
        return $score;
    }
}
