<?php
/**
 * Website content: company details and FAQs, edited as drafts and published by authorised staff, and the public
 * marketing pages built from them.
 *
 * Each save is a row in content_versions (the full content as JSON), so the table is the revision history. The public
 * pages (app/site/pages/*.html, the same sources as the Flask edition's src/) are rendered on request from the
 * published content, so publishing takes effect immediately. Content is plain text: every value is HTML-escaped and
 * missing (unverified) details show as visible "[... to be confirmed]" placeholders.
 */
declare(strict_types=1);

// key => [label, placeholder until verified, help text, validator]
const SETTING_FIELDS = [
    'legal_name' => ['Legal company name', '[Legal company name to be confirmed]', 'Exactly as registered at Companies House.', 'text'],
    'trading_name' => ['Trading name', '[Trading name to be confirmed]', '', 'text'],
    'company_number' => ['Company registration number', '[Company number to be confirmed]', '8 characters, e.g. 01234567 or SC123456.', 'company_number'],
    'registered_office' => ['Registered office address', '[Registered office address to be confirmed]', '', 'text'],
    'phone' => ['Customer telephone number', '[Telephone number to be confirmed]', '', 'phone'],
    'email' => ['Customer contact email', '[Contact email to be confirmed]', 'A monitored inbox on the company domain.', 'email'],
    'opening_hours' => ['Opening hours', '[Opening hours to be confirmed]', 'e.g. Monday to Friday, 9am to 5pm', 'text'],
    'regulatory_statement' => ['Regulatory status statement', '[Regulatory status to be confirmed: do not publish an FCA statement until the firm\'s permissions are verified on the FCA register]',
        'Wording approved by your compliance adviser after checking the FCA Financial Services Register.', 'text'],
    'ico_registration' => ['ICO registration number', '[ICO data protection registration number to be confirmed]', 'From the ICO register of fee payers.', 'text'],
    'complaints_contact' => ['Complaints contact', '[Complaints contact details to be confirmed]', 'Who to write to, and how.', 'text'],
    'example_apr' => ['Representative / example APR (%)', '', 'Leave empty to hide all repayment examples. Only set with compliance approval.', 'apr'],
    'announcement' => ['Site announcement (optional)', '', 'A short notice shown at the top of every page.', 'text'],
];
const PLACEHOLDER_RE = '/\[[^\]]*to be confirmed[^\]]*\]/i';
const PREVIEW_PAGES = ['index', 'about', 'solutions', 'how-it-works', 'faq', 'contact', 'privacy', 'cookies', 'terms', 'complaints', 'accessibility'];

/** Published company details + FAQs + branding, for templates and emails. site()['phone'] or site()->show('phone'). */
final class Site implements ArrayAccess
{
    public function __construct(public array $data)
    {
    }

    public function offsetExists($k): bool
    {
        return isset($this->data[$k]);
    }

    public function offsetGet($k): mixed
    {
        return $this->data[$k] ?? '';
    }

    public function offsetSet($k, $v): void
    {
        $this->data[$k] = $v;
    }

    public function offsetUnset($k): void
    {
        unset($this->data[$k]);
    }

    public function show(string $key): string
    {
        $v = (string) ($this->data[$key] ?? '');
        return $v !== '' ? $v : SETTING_FIELDS[$key][1];
    }
}

/** Default content: app/site/site.json for the website's owner; for other organisations, the same FAQs with their name. */
function content_defaults(): array
{
    $data = json_decode(file_get_contents(LMD_APP . '/site/site.json'), true);
    unset($data['_note']);
    $org = current_org();
    if ($org !== null && !owns_static_site($org)) {
        $data['settings'] = array_map(fn() => '', $data['settings']);
        $data['settings']['legal_name'] = $data['settings']['trading_name'] = $org['name'];
        foreach ($data['faqs'] as &$f) {
            $f = ['q' => str_replace('LMD Finance', $org['name'], $f['q']), 'a' => str_replace('LMD Finance', $org['name'], $f['a'])];
        }
    }
    return $data;
}

function content_normalise(?array $data): array
{
    $base = content_defaults();
    $out = ['settings' => $base['settings'], 'faqs' => $base['faqs']];
    if ($data) {
        foreach ($data['settings'] ?? [] as $k => $v) {
            if (isset(SETTING_FIELDS[$k])) {
                $out['settings'][$k] = (string) $v;
            }
        }
        if (isset($data['faqs'])) {
            $out['faqs'] = array_map(fn($f) => ['q' => (string) $f['q'], 'a' => (string) $f['a']], $data['faqs']);
        }
    }
    return $out;
}

function content_org(): int
{
    return (int) (current_org()['id'] ?? 0);
}

function content_latest(string $status): ?array
{
    return q1('SELECT * FROM content_versions WHERE org_id = ? AND status = ? ORDER BY id DESC LIMIT 1', [content_org(), $status]);
}

function content_published(): array
{
    $row = content_latest('published');
    return content_normalise($row ? json_decode($row['data'], true) : null);
}

/** [data, draft row or null]. */
function content_draft(): array
{
    $row = content_latest('draft');
    return [$row ? content_normalise(json_decode($row['data'], true)) : content_published(), $row];
}

function site(): Site
{
    if (!isset($GLOBALS['__lmd_site'])) {
        $data = content_published();
        $org = current_org();
        $s = $data['settings'];
        $s['faqs'] = $data['faqs'];
        $s['org_name'] = $org['name'] ?? 'Loan platform';
        $s['brand_color'] = $org['brand_color'] ?? '#F08A6E';
        $s['logo_url'] = ($org && $org['logo_file']) ? url('/branding/logo') . '?v=' . $org['logo_file'] : '';
        $GLOBALS['__lmd_site'] = new Site($s);
    }
    return $GLOBALS['__lmd_site'];
}

/** [settings, faqs, errors]. */
function content_validate(array $settings, array $faqs): array
{
    $errors = [];
    foreach (SETTING_FIELDS as $key => [, , , $kind]) {
        $v = trim((string) ($settings[$key] ?? ''));
        $settings[$key] = mb_substr($v, 0, 600);
        if ($v === '') {
            continue;
        }
        if ($kind === 'email' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $v)) {
            $errors[$key] = 'Enter a valid email address.';
        } elseif ($kind === 'phone' && !preg_match('/^[+0-9 ()-]{8,20}$/', $v)) {
            $errors[$key] = 'Enter a telephone number using digits, spaces and + only.';
        } elseif ($kind === 'company_number' && !preg_match('#^[A-Z0-9 /-]{2,30}$#', strtoupper($v))) {
            $errors[$key] = 'Use letters, numbers, spaces, / or - only.';
        } elseif ($kind === 'apr' && !(is_numeric($v) && (float) $v > 0 && (float) $v < 100)) {
            $errors[$key] = 'Enter a percentage such as 12.9, or leave empty.';
        }
        if (preg_match(PLACEHOLDER_RE, $v)) {
            $errors[$key] = 'Remove the placeholder text, or leave the field empty.';
        }
    }
    $clean = [];
    foreach ($faqs as $i => $f) {
        $qq = mb_substr(trim((string) ($f['q'] ?? '')), 0, 300);
        $a = mb_substr(trim((string) ($f['a'] ?? '')), 0, 3000);
        if ($qq !== '' || $a !== '') {
            if ($qq === '' || $a === '') {
                $errors["faq$i"] = 'Each FAQ needs a question and an answer.';
            }
            $clean[] = ['q' => $qq, 'a' => $a];
        }
    }
    return [$settings, $clean, $errors];
}

function content_save_draft(array $data, array $user, string $note = ''): int
{
    return transaction(function () use ($data, $user, $note) {
        x("UPDATE content_versions SET status = 'discarded' WHERE org_id = ? AND status = 'draft'", [content_org()]);
        return x('INSERT INTO content_versions (org_id, data, status, note, created_by, created_at) VALUES (?,?,?,?,?,?)',
            [content_org(), json_out($data), 'draft', mb_substr($note, 0, 300), $user['id'], now()]);
    });
}

function content_publish(array $draft, array $user): void
{
    transaction(function () use ($draft, $user) {
        x("UPDATE content_versions SET status = 'superseded' WHERE org_id = ? AND status = 'published'", [content_org()]);
        x("UPDATE content_versions SET status = 'published', published_by = ?, published_at = ? WHERE id = ? AND org_id = ?",
            [$user['id'], now(), $draft['id'], content_org()]);
    });
    unset($GLOBALS['__lmd_site']);
}

function unresolved_placeholders(?array $data = null): array
{
    $data = $data ?? content_published();
    $out = [];
    foreach (['legal_name', 'company_number', 'registered_office', 'phone', 'email', 'regulatory_statement', 'ico_registration', 'complaints_contact'] as $k) {
        if (!($data['settings'][$k] ?? '')) {
            $out[] = SETTING_FIELDS[$k][0];
        }
    }
    return $out;
}

// ---------------------------------------------------------------- public marketing pages
const SITE_NAV = [['home', 'index.html', 'Home'], ['about', 'about.html', 'About Us'], ['solutions', 'solutions.html', 'Business Finance'],
    ['how', 'how-it-works.html', 'How It Works'], ['faq', 'faq.html', 'FAQs'], ['contact', 'contact.html', 'Contact Us']];
const SITE_PLACEHOLDERS = [
    'legal_name' => '[Legal company name to be confirmed]', 'trading_name' => '[Trading name to be confirmed]',
    'company_number' => '[Company number to be confirmed]', 'registered_office' => '[Registered office address to be confirmed]',
    'phone' => '[Telephone number to be confirmed]', 'email' => '[Contact email to be confirmed]',
    'opening_hours' => '[Opening hours to be confirmed]', 'regulatory_statement' => '[Regulatory status to be confirmed]',
    'ico_registration' => '[ICO registration number to be confirmed]', 'complaints_contact' => '[Complaints contact details to be confirmed]',
];

function site_page_exists(string $name): bool
{
    return preg_match('/^[a-z-]+$/', $name) && is_file(LMD_APP . "/site/pages/$name.html");
}

function site_picture(array $attrs, string $prefix): string
{
    static $images = null;
    $images ??= array_column(json_decode(file_get_contents(LMD_APP . '/site/images.json'), true)['images'], null, 'file');
    $name = $attrs['src'];
    $img = $images[$name] ?? null;
    if ($img === null) {
        return '';
    }
    [$dw, $dh] = $img['desktop'];
    $base = "{$prefix}images/$name";
    $sizes = e($attrs['sizes'] ?? '100vw');
    $alt = isset($attrs['decorative']) ? '' : e($attrs['alt'] ?? '');
    $cls = e(trim('pic ' . ($attrs['class'] ?? '')));
    $mdH = (int) round(960 * $dh / $dw);
    $loading = isset($attrs['eager']) ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"';
    return "<picture class=\"$cls\" style=\"--ar:$dw/$dh\">"
        . "<source media=\"(max-width: 640px)\" type=\"image/avif\" srcset=\"$base-sm.avif\">"
        . "<source media=\"(max-width: 640px)\" type=\"image/webp\" srcset=\"$base-sm.webp\">"
        . "<source type=\"image/avif\" srcset=\"$base-md.avif 960w, $base-lg.avif 1600w\" sizes=\"$sizes\">"
        . "<source type=\"image/webp\" srcset=\"$base-md.webp 960w, $base-lg.webp 1600w\" sizes=\"$sizes\">"
        . "<img src=\"$base-md.jpg\" width=\"960\" height=\"$mdH\" alt=\"$alt\" $loading decoding=\"async\"></picture>";
}

function site_faqs(array $faqs): string
{
    $items = '';
    foreach ($faqs as $f) {
        $paras = '';
        foreach (explode("\n", $f['a']) as $p) {
            if (trim($p) !== '') {
                $paras .= '<p>' . e($p) . '</p>';
            }
        }
        $items .= '<details class="faq"><summary>' . e($f['q']) . "</summary>$paras</details>\n";
    }
    $ld = json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => [
        '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqs)],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    return "<div class=\"faq-list\">$items</div>\n<script type=\"application/ld+json\">$ld</script>";
}

function site_fill(string $text, array $data): string
{
    $s = $data['settings'];
    $text = preg_replace_callback('/\{\{(if|ifnot):(\w+)\}\}(.*?)\{\{\/\1:\2\}\}/s',
        fn($m) => (trim((string) ($s[$m[2]] ?? '')) !== '') === ($m[1] === 'if') ? $m[3] : '', $text);
    $text = preg_replace_callback('/\{\{(site|tel|mailto|raw):(\w+)\}\}/', function ($m) use ($s) {
        [$kind, $key] = [$m[1], $m[2]];
        $value = trim((string) ($s[$key] ?? ''));
        if ($value === '') {
            return $kind === 'raw' ? '' : '<span class="placeholder">' . e(SITE_PLACEHOLDERS[$key] ?? '[To be confirmed]') . '</span>';
        }
        return match ($kind) {
            'tel' => '<a href="tel:' . e(preg_replace('/[^+0-9]/', '', $value)) . '">' . e($value) . '</a>',
            'mailto' => '<a href="mailto:' . e($value) . '">' . e($value) . '</a>',
            'raw' => e($value),
            default => str_replace("\n", '<br>', e($value)),
        };
    }, $text);
    return str_replace('{{faqs}}', site_faqs($data['faqs']), $text);
}

/** Render one public page. $banner marks staff previews of unpublished changes. */
function site_render(string $name, array $data, string $banner = ''): string
{
    $src = file_get_contents(LMD_APP . "/site/pages/$name.html");
    $meta = [];
    $body = $src;
    if (str_starts_with($src, '---')) {
        [, $fm, $body] = explode('---', $src, 3);
        foreach (preg_split('/\R/', trim($fm)) as $line) {
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $meta[trim($k)] = trim($v);
        }
    }
    $prefix = '/';
    $tags = function (string $key, string $fmt) use ($meta, $prefix) {
        $out = [];
        foreach (csv_list($meta[$key] ?? '') as $p) {
            $out[] = sprintf($fmt, $prefix . $p);
        }
        return implode("\n", $out);
    };
    $siteUrl = rtrim((string) config('base_url'), '/');
    $nav = implode("\n", array_map(fn($n) => '<li><a href="' . $prefix . $n[1] . '"' . (($meta['nav'] ?? '') === $n[0] ? ' aria-current="page"' : '')
        . '>' . $n[2] . '</a></li>', SITE_NAV));
    $notices = ($banner ? '<p class="site-notice preview">' . e($banner) . '</p>' : '')
        . (trim($data['settings']['announcement'] ?? '') !== '' ? '<p class="site-notice">' . e(trim($data['settings']['announcement'])) . '</p>' : '');
    $robots = config('noindex') ? 'noindex, nofollow' : ($meta['robots'] ?? 'index, follow');
    $layout = file_get_contents(LMD_APP . '/site/layout.html');
    $out = strtr($layout, [
        '{{styles_before}}' => $tags('styles_before', '<link rel="stylesheet" href="%s">'),
        '{{styles}}' => $tags('styles', '<link rel="stylesheet" href="%s">'),
        '{{scripts}}' => $tags('scripts', '<script src="%s" defer></script>'),
        '{{title}}' => e($meta['title'] ?? site()['org_name']),
        '{{description}}' => e($meta['description'] ?? ''),
        '{{canonical}}' => $siteUrl . '/' . ($name === 'index' ? '' : "$name.html"),
        '{{og_image}}' => $siteUrl . '/images/' . ($meta['og_image'] ?? 'consultations/one-to-one') . '-md.jpg',
        '{{robots}}' => $robots,
        '{{body_class}}' => $meta['body_class'] ?? '',
        '{{notices}}' => $notices,
        '{{nav}}' => $nav,
        '{{content}}' => $body,
    ]);
    $out = site_fill($out, $data);
    $out = preg_replace_callback('/<x-img\s+([^>]*?)\/?>/', function ($m) use ($prefix) {
        preg_match_all('/([\w-]+)(?:="([^"]*)")?/', $m[1], $mm, PREG_SET_ORDER);
        $attrs = [];
        foreach ($mm as $a) {
            $attrs[$a[1]] = $a[2] ?? true;
        }
        return site_picture($attrs, $prefix);
    }, $out);
    return trim(str_replace('{{root}}', $prefix, $out)) . "\n";
}
