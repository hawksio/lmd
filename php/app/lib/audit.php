<?php
/**
 * Append-only audit trail. Each row stores the hash of the previous row, so any edit made outside the app (the
 * database triggers block edits through it) breaks the chain and is reported by verify_chain().
 * Never put passwords, tokens, authentication codes or document contents in the detail.
 */
declare(strict_types=1);

const AUDIT_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
const AUDIT_FORBIDDEN = ['password', 'token', 'secret', 'code', 'otp'];

function audit_clean(array $detail): string
{
    $clean = [];
    foreach ($detail as $k => $v) {
        foreach (AUDIT_FORBIDDEN as $word) {
            if (str_contains(strtolower((string) $k), $word)) {
                continue 2;
            }
        }
        $clean[$k] = (is_int($v) || is_float($v) || is_bool($v) || $v === null) ? $v : mb_substr((string) $v, 0, 300);
    }
    ksort($clean, SORT_STRING);
    return $clean ? json_out($clean) : '{}';
}

/**
 * Record an event. $actor: a user row, or false for "no actor" (anonymous/system); default is the signed-in user.
 */
function audit_log(string $event, string $targetType = '', $targetId = null, array $detail = [], $actor = null): void
{
    if ($actor === null) {
        $actor = $GLOBALS['__lmd_user'] ?? null;
    }
    $actor = $actor ?: null;
    $actorId = $actor['id'] ?? null;
    $org = current_org();
    $orgId = $org['id'] ?? ($actor['org_id'] ?? null);
    if ($orgId !== null) {
        $detail = ['org' => (int) $orgId] + $detail;  // inside the hashed detail, so it can't be altered silently
    }
    $stamp = now();
    $body = audit_clean($detail);
    x("INSERT INTO audit_log (org_id, created_at, actor_id, actor_role, event, target_type, target_id, detail, ip, prev_hash, row_hash)
       SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, p.h, lmd_sha256(p.h || '|' || ? || '|' || ? || '|' || ? || '|' || ? || '|' || ? || '|' || ?)
       FROM (SELECT COALESCE((SELECT row_hash FROM audit_log ORDER BY id DESC LIMIT 1), ?) AS h) p",
        [$orgId, $stamp, $actorId, $actor['role'] ?? '', $event, $targetType, $targetId, $body, client_ip(),
            $stamp, $actorId === null ? '' : (string) $actorId, $event, $targetType, $targetId === null ? '' : (string) $targetId, $body,
            AUDIT_GENESIS]);
}

/** [ok, number checked, first bad id]. */
function verify_chain(): array
{
    $prev = AUDIT_GENESIS;
    $n = 0;
    $st = db()->query('SELECT * FROM audit_log ORDER BY id');
    while ($r = $st->fetch()) {
        $n++;
        $raw = implode('|', [$prev, $r['created_at'], $r['actor_id'] ?? '', $r['event'], $r['target_type'], $r['target_id'] ?? '', $r['detail']]);
        if ($r['prev_hash'] !== $prev || $r['row_hash'] !== hash('sha256', $raw)) {
            return [false, $n, (int) $r['id']];
        }
        $prev = $r['row_hash'];
    }
    return [true, $n, null];
}
