<?php
/**
 * SQLite storage through PDO. One connection per request; rows are associative arrays.
 *
 * A fresh database is created from app/schema.sql. Later schema changes are numbered migrations in MIGRATIONS
 * below; before any migration runs on a database that already holds data, a copy is saved to lmd-storage/backups/.
 * Never edit a migration that has shipped; add a new one.
 */
declare(strict_types=1);

/** [version, name, sql]. Version 1 is the full schema in schema.sql. */
const MIGRATIONS = [
    // [2, 'example: add a column', 'ALTER TABLE ...;'],
];

function db_path(): string
{
    return getenv('LMD_DB_PATH') ?: storage_dir() . '/lmd.sqlite3';
}

function db(): PDO
{
    if (!isset($GLOBALS['__lmd_db'])) {
        $pdo = new PDO('sqlite:' . db_path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // The audit hash chain is computed inside the INSERT so concurrent requests can't fork it.
        $pdo->sqliteCreateFunction('lmd_sha256', fn($v) => hash('sha256', $v === null ? '' : (string) $v), 1, PDO::SQLITE_DETERMINISTIC);
        $GLOBALS['__lmd_db'] = $pdo;
        $GLOBALS['__lmd_tx'] = 0;
    }
    return $GLOBALS['__lmd_db'];
}

function db_close(): void
{
    unset($GLOBALS['__lmd_db']);
}

/** All rows. */
function q(string $sql, array $args = []): array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/** First row or null. */
function q1(string $sql, array $args = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** First column of the first row. */
function qv(string $sql, array $args = [])
{
    $row = q1($sql, $args);
    return $row === null ? null : reset($row);
}

/** Run a write; returns the new row id for inserts. */
function x(string $sql, array $args = []): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int) db()->lastInsertId();
}

function is_unique_violation(Throwable $e): bool
{
    return $e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE');
}

/** Group several writes so they all happen or none do. Nested calls join the outer transaction. */
function transaction(callable $fn)
{
    $pdo = db();
    $outer = $GLOBALS['__lmd_tx'] === 0;
    if ($outer) {
        $pdo->exec('BEGIN IMMEDIATE');
    }
    $GLOBALS['__lmd_tx']++;
    try {
        $result = $fn();
        $GLOBALS['__lmd_tx']--;
        if ($outer) {
            $pdo->exec('COMMIT');
        }
        return $result;
    } catch (Throwable $e) {
        $GLOBALS['__lmd_tx']--;
        if ($outer) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}

function backup_database(string $label, ?string $dest = null): string
{
    $dest = $dest ?? storage_dir() . '/backups';
    if (!is_dir($dest)) {
        mkdir($dest, 0700, true);
    }
    $file = $dest . '/lmd-' . $label . '-' . gmdate('Ymd-His') . '.sqlite3';
    // VACUUM INTO makes a consistent copy while the site is running.
    db()->exec('VACUUM INTO ' . db()->quote($file));
    return $file;
}

/** Create the schema on first use and apply pending migrations. Returns the versions applied. */
function migrate(): array
{
    $pdo = db();
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, name TEXT NOT NULL, applied_at TEXT NOT NULL)');
    $done = array_map('intval', array_column(q('SELECT version FROM schema_migrations'), 'version'));
    $applied = [];
    if (!in_array(1, $done, true)) {
        if (qv("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'")) {
            throw new RuntimeException('Database has tables but no schema version. Restore a backup or use a new database file.');
        }
        transaction(function () use ($pdo) {
            $pdo->exec(file_get_contents(LMD_APP . '/schema.sql'));
            x('INSERT INTO schema_migrations VALUES (1, ?, ?)', ['complete schema (PHP edition)', now()]);
        });
        $applied[] = 1;
        $done[] = 1;
    }
    $pending = array_values(array_filter(MIGRATIONS, fn($m) => !in_array($m[0], $done, true)));
    if ($pending && !$applied) {
        backup_database('pre-migration-' . $pending[0][0]);
    }
    foreach ($pending as [$version, $name, $sql]) {
        transaction(function () use ($pdo, $version, $name, $sql) {
            $pdo->exec($sql);
            x('INSERT INTO schema_migrations VALUES (?, ?, ?)', [$version, $name, now()]);
        });
        $applied[] = $version;
    }
    return $applied;
}
