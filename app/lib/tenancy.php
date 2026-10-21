<?php
/**
 * Organisations (tenants). Each request belongs to exactly one organisation, or to the platform console:
 *   1. a custom domain registered for an organisation (organisation_domains);
 *   2. the console: config platform_host, or config platform_path (default /console) on the main host;
 *   3. /o/<slug>/... on the main host (works on shared hosting without any DNS change);
 *   4. <slug>.<tenant_base_domain> when wildcard DNS is available;
 *   5. default_org for anything else (empty in a strict production setup = 404).
 *
 * Isolation rules enforced elsewhere: users carry org_id; a session is valid only for its own organisation
 * (belongs_here); every staff query filters by org_id(); files are served only through rows already filtered by
 * org_id. The console never reads an organisation's customer data.
 */
declare(strict_types=1);

const DEFAULT_SETTINGS = [
    'require_verified_documents_for_underwriting' => true,
    'require_offer_acceptance_before_agreement' => true,
    'second_approval_threshold_minor' => 0,   // 0 = every approval needs a second person
    'agreement_template_approved' => false,   // set only after a solicitor approves the agreement template
    'staff_notification_emails' => '',
    'notify_staff_new_application' => true,
    'notify_staff_client_message' => true,
    'email_signature' => '',
    'max_percent' => [],                      // organisation limits, never above the platform caps
];

/** Decide the organisation (or console) from the host and path. Sets the base path used by url(). */
function resolve_tenant(): void
{
    $GLOBALS['__lmd_org'] = null;
    $GLOBALS['__lmd_platform'] = false;
    $GLOBALS['__lmd_base'] = '';
    $path = raw_path();
    $host = host();
    $org = q1('SELECT o.* FROM organisation_domains d JOIN organisations o ON o.id = d.org_id WHERE d.host = ?', [$host]);
    if ($org === null) {
        $phost = strtolower((string) config('platform_host'));
        $ppath = rtrim((string) config('platform_path'), '/');
        if ($phost !== '' && $host === $phost) {
            $GLOBALS['__lmd_platform'] = true;
        } elseif ($ppath !== '' && ($path === $ppath || str_starts_with($path, $ppath . '/'))) {
            $GLOBALS['__lmd_platform'] = true;
            $GLOBALS['__lmd_base'] = $ppath;
            $path = substr($path, strlen($ppath)) ?: '/';
        }
        if ($GLOBALS['__lmd_platform']) {
            $GLOBALS['__lmd_path'] = $path;
            return;
        }
        $prefix = rtrim((string) config('tenant_path_prefix'), '/');
        if ($prefix !== '' && preg_match('#^' . preg_quote($prefix, '#') . '/([a-z][a-z0-9-]{1,30})(/.*)?$#', $path, $m)) {
            $org = q1('SELECT * FROM organisations WHERE slug = ?', [$m[1]]);
            if ($org === null) {
                abort(404);
            }
            $GLOBALS['__lmd_base'] = $prefix . '/' . $org['slug'];
            $path = ($m[2] ?? '') ?: '/';
        }
        $base = strtolower((string) config('tenant_base_domain'));
        if ($org === null && $base !== '' && str_ends_with($host, '.' . $base)) {
            $org = q1('SELECT * FROM organisations WHERE slug = ?', [substr($host, 0, -strlen($base) - 1)]);
        }
        if ($org === null && config('default_org')) {
            $org = q1('SELECT * FROM organisations WHERE slug = ?', [config('default_org')]);
        }
    }
    if ($org === null) {
        abort(404);
    }
    $GLOBALS['__lmd_path'] = $path;
    if ($org['status'] !== 'active' && !str_starts_with($path, '/api/health')) {
        abort(503, 'This service is not available at the moment.');
    }
    $GLOBALS['__lmd_org'] = $org;
}

function current_org(): ?array
{
    return $GLOBALS['__lmd_org'] ?? null;
}

function set_current_org(?array $org): void
{
    // Used by jobs and the console to act as one organisation (its branding, links and sender name).
    $GLOBALS['__lmd_org'] = $org;
    unset($GLOBALS['__lmd_site']);
}

function on_platform(): bool
{
    return (bool) ($GLOBALS['__lmd_platform'] ?? false);
}

function org_id(): int
{
    $org = current_org();
    if ($org === null) {
        abort(404);
    }
    return (int) $org['id'];
}

function org_settings(?array $org = null): array
{
    $org = $org ?? current_org();
    $merged = DEFAULT_SETTINGS;
    if ($org !== null) {
        $saved = json_decode($org['settings'] ?: '{}', true);
        if (is_array($saved)) {
            $merged = array_merge($merged, $saved);
        }
    }
    if (!is_array($merged['max_percent'])) {
        $merged['max_percent'] = [];
    }
    return $merged;
}

function update_org_settings(int $orgId, array $changes): void
{
    $row = q1('SELECT settings FROM organisations WHERE id = ?', [$orgId]);
    $data = json_decode($row['settings'] ?: '{}', true) ?: [];
    $data = array_merge($data, $changes);
    x('UPDATE organisations SET settings = ? WHERE id = ?', [json_out($data), $orgId]);
    if (current_org() !== null && (int) current_org()['id'] === $orgId) {
        set_current_org(q1('SELECT * FROM organisations WHERE id = ?', [$orgId]));
    }
}

/** Public address of an organisation's portal for email links (from configuration, never the request). */
function tenant_base_url(?array $org = null): string
{
    $configured = rtrim((string) config('base_url'), '/');
    $org = $org ?? current_org();
    if ($org === null) {
        return $configured . (config('platform_host') ? '' : rtrim((string) config('platform_path'), '/'));
    }
    $scheme = explode('://', $configured)[0];
    $domain = qv('SELECT host FROM organisation_domains WHERE org_id = ? ORDER BY is_primary DESC, host LIMIT 1', [$org['id']]);
    if ($domain) {
        return "$scheme://$domain";
    }
    if ($org['slug'] === config('default_org')) {
        return $configured;
    }
    if (config('tenant_base_domain')) {
        return "$scheme://{$org['slug']}." . config('tenant_base_domain');
    }
    return $configured . rtrim((string) config('tenant_path_prefix'), '/') . '/' . $org['slug'];
}

/** Application references look like LMD-000042: the organisation's slug, upper-cased. */
function ref_prefix(?array $org = null): string
{
    $org = $org ?? current_org();
    return strtoupper(substr($org['slug'] ?? 'app', 0, 8));
}

function owns_static_site(?array $org = null): bool
{
    $org = $org ?? current_org();
    return $org !== null && $org['slug'] === config('static_site_org');
}

function create_org(string $slug, string $name, string $currency = 'GBP'): int
{
    return x('INSERT INTO organisations (slug, name, default_currency, created_at) VALUES (?,?,?,?)',
        [strtolower($slug), $name, $currency, now()]);
}
