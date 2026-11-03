<?php
declare(strict_types=1);

/**
 * lib/panel/users.php — `GET /panel-api/v1/users`.
 *
 * The device form's "user email" field is a dropdown, so any account that may
 * edit a device (manager+) needs to read the directory. It stays deliberately
 * narrow: only the three columns the dropdown renders (`email`, `company`,
 * `role`), no role management (that is `PATCH /settings/users/{email}`) and no
 * way for a company-bound account to enumerate another tenant.
 *
 * The legacy `users` table has no surrogate key, so `email` is the identity.
 */

/** Maximum rows the directory returns; the panel does not paginate a select. */
const PANEL_USERS_LIMIT = 1000;

/**
 * @return list<array{email: string, company: string, role: string}>
 */
function panel_users_rows(PDO $pdo, string $company): array
{
    if (!db_table_exists($pdo, 'users')) {
        return [];
    }

    $sql = 'SELECT email, company, role FROM users';
    $bind = [];
    if ($company !== '') {
        $sql .= ' WHERE company = ?';
        $bind[] = $company;
    }
    $sql .= ' ORDER BY email LIMIT ' . PANEL_USERS_LIMIT;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);

    $rows = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            continue;
        }
        $rows[] = [
            'email'   => $email,
            'company' => (string) ($row['company'] ?? ''),
            'role'    => session_role_normalize((string) ($row['role'] ?? '')),
        ];
    }
    return $rows;
}

/**
 * GET /users?company=<name>
 *
 * A company-bound account always gets its own company, whatever the query says;
 * an unbound one (superadmin) may ask for a company or read the whole directory.
 */
function panel_users_index(array $params = []): array
{
    $user = session_require_role(['manager', 'admin', 'superadmin']);
    $pdo = db();

    $bound = trim((string) ($user['company'] ?? ''));
    $requested = $bound;
    if ($requested === '' && is_string($_GET['company'] ?? null)) {
        $requested = trim((string) $_GET['company']);
    }
    $requested = mb_substr($requested, 0, 191);

    $rows = panel_users_rows($pdo, $requested);

    return [
        'data' => [
            'rows'  => $rows,
            'total' => count($rows),
        ],
        'meta' => ['company' => $requested],
    ];
}
