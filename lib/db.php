<?php
declare(strict_types=1);

/**
 * lib/db.php — one PDO connection for every entry point (web, API, CLI).
 *
 * Every entry point (`public/api.php`, `public/panel_api.php`, `public/auth.php`,
 * `public/index.php`, `bin/*`) goes through `db()` / `db_try()`; nothing opens a
 * connection of its own.
 */

function db_dsn(): string
{
    $dsn = 'mysql:host=' . DB_HOST;
    if (defined('DB_PORT') && trim((string) DB_PORT) !== '') {
        $dsn .= ';port=' . trim((string) DB_PORT);
    }
    return $dsn . ';dbname=' . DB_NAME . ';charset=utf8mb4';
}

/**
 * Shared PDO handle. Throws RuntimeException when the database is unreachable.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(db_dsn(), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Same as db() but returns null instead of throwing (used to render the
 * "database not migrated / unreachable" banner instead of a white page).
 */
function db_try(): ?PDO
{
    try {
        return db();
    } catch (Throwable) {
        return null;
    }
}

/**
 * Error message safe to display: never leaks the DB password.
 */
function db_error_message(Throwable $e): string
{
    $msg = $e->getMessage();
    foreach ([DB_PASS, DB_USER] as $secret) {
        $secret = (string) $secret;
        if ($secret !== '' && strlen($secret) > 3 && !str_starts_with($secret, 'YOUR')) {
            $msg = str_replace($secret, '***', $msg);
        }
    }
    return $msg;
}

/**
 * True when the given table exists in the current schema.
 *
 * Only positive results are cached: migrations create tables in the same
 * process and must see them immediately afterwards.
 */
function db_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    $key = DB_NAME . '.' . $table;
    if (!empty($cache[$key])) {
        return true;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    if ((int) $stmt->fetchColumn() > 0) {
        $cache[$key] = true;
    }
    return !empty($cache[$key]);
}

/**
 * True when the given column exists; returns false if the table is missing.
 * Positive results only are cached (same reason as db_table_exists()).
 */
function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = DB_NAME . '.' . $table . '.' . $column;
    if (!empty($cache[$key])) {
        return true;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        $cache[$key] = true;
    }
    return !empty($cache[$key]);
}
/**
 * Column definition (type + nullability) as reported by information_schema.
 */
function db_column_definition(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch();
    return $row === false ? null : (string) $row['COLUMN_TYPE'];
}
