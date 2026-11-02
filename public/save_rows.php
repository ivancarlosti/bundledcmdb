<?php
declare(strict_types=1);

/**
 * public/save_rows.php — grid save and bulk soft delete.
 */

session_start();
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/lang.php';
lang_init();
app_boot();

if (empty($_SESSION['user_email']) || request_method() !== 'POST') {
    redirect('index.php');
}
csrf_require();

$role = (string) ($_SESSION['role'] ?? 'user');
$actor = (string) $_SESSION['user_email'];
$company = (string) ($_SESSION['company'] ?? '');
$pdo = db_try();
if ($pdo === null || $company === '') {
    http_response_code(503);
    exit(e(lang('db_unavailable')));
}

$action = (string) ($_POST['action'] ?? 'save');

// --- Bulk soft delete ---------------------------------------------------
if ($action === 'delete_selected') {
    if (!in_array($role, ['admin', 'superadmin'], true)) {
        http_response_code(403);
        exit(e(lang('access_denied')));
    }
    $deleted = 0;
    foreach ((array) ($_POST['delete_ids'] ?? []) as $candidate) {
        if (asset_soft_delete($pdo, (int) $candidate, $actor, $company)) {
            $deleted++;
        }
    }
    if ($deleted > 0) {
        flash_set('success', lang('asset_deleted') . ' (' . $deleted . ')');
    }
    redirect('main.php');
}

// --- Grid save ----------------------------------------------------------
if ($role === 'user') {
    http_response_code(403);
    exit(e(lang('access_denied')));
}

$editable = asset_grid_editable_columns($role);
$rows = (array) ($_POST['rows'] ?? []);
$updated = 0;
$failed = 0;

foreach ($rows as $rowId => $fields) {
    $rowId = (int) $rowId;
    if ($rowId <= 0 || !is_array($fields)) {
        continue;
    }
    if (asset_get($pdo, $rowId, $company) === null) {
        continue;
    }

    $data = [];
    foreach ($editable as $column) {
        if (array_key_exists($column, $fields)) {
            $data[$column] = $fields[$column];
        }
    }
    if ($data === []) {
        continue;
    }
    $data = asset_normalize_input($data);
    if (asset_validate($data, true) !== []) {
        $failed++;
        continue;
    }
    if (asset_find_duplicate($pdo, $data, $rowId, $company) !== null) {
        $failed++;
        continue;
    }
    if (asset_update($pdo, $rowId, $data, $actor, $company) > 0) {
        $updated++;
    }
}

if ($updated > 0) {
    flash_set('success', lang('asset_updated') . ' (' . $updated . ')');
}
if ($failed > 0) {
    flash_set('error', lang('validation_failed'));
}

redirect('main.php');
