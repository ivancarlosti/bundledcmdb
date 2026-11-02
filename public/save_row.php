<?php
declare(strict_types=1);

/**
 * public/save_row.php — update one asset (detail form), upload/delete files.
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

$id = (int) ($_POST['row']['Id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid record ID');
}

$row = asset_get($pdo, $id, $company);
if ($row === null) {
    http_response_code(404);
    exit(e(lang('asset_not_found')));
}
if ($role === 'user' && (string) ($row['UserEmail'] ?? '') !== $actor) {
    http_response_code(403);
    exit(e(lang('access_denied')));
}

// --- File upload / delete ----------------------------------------------
if (isset($_FILES['new_file']) && (int) $_FILES['new_file']['error'] === UPLOAD_ERR_OK) {
    try {
        require_once __DIR__ . '/../s3_client.php';
        $s3 = new S3Client();
        if (!$s3->isConfigured()) {
            flash_set('error', lang('set_err_unknown_setting'));
            redirect('asset.php?id=' . $id);
        }
        $file = $_FILES['new_file'];
        $key = 'assets/' . $id . '/' . uniqid('', true) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $file['name']);
        $result = $s3->uploadFile((string) $file['tmp_name'], $key, (string) $file['type']);
        if (!empty($result['success'])) {
            $stmt = $pdo->prepare(
                'INSERT INTO device_files (device_id, device_table, file_path, file_name, mime_type, size)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, 'assets', $s3->objectKey($key), $file['name'], $file['type'], (int) $file['size']]);
            flash_set('success', lang('upload_btn'));
        } else {
            flash_set('error', (string) ($result['message'] ?? 'upload failed'));
        }
    } catch (Throwable $e) {
        error_log('[save_row upload] ' . $e->getMessage());
        flash_set('error', $e->getMessage());
    }
    redirect('asset.php?id=' . $id);
}

if (isset($_POST['delete_file'])) {
    $fileId = (int) $_POST['delete_file'];
    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE id = ? AND device_id = ? AND device_table = 'assets'");
    $stmt->execute([$fileId, $id]);
    $file = $stmt->fetch();
    if ($file !== false) {
        try {
            require_once __DIR__ . '/../s3_client.php';
            (new S3Client())->deleteFile((string) $file['file_path']);
        } catch (Throwable $e) {
            error_log('[save_row delete file] ' . $e->getMessage());
        }
        $pdo->prepare('DELETE FROM device_files WHERE id = ?')->execute([$fileId]);
        flash_set('success', lang('delete'));
    }
    redirect('asset.php?id=' . $id);
}

// --- Field update -------------------------------------------------------
if ($role === 'user') {
    http_response_code(403);
    exit(e(lang('access_denied')));
}

$submitted = (array) ($_POST['row'] ?? []);
$data = [];
foreach (asset_form_columns() as $column) {
    $def = asset_column_defs()[$column] ?? null;
    if ($def === null || !in_array($role, $def['form_roles'], true)) {
        continue;
    }
    if (array_key_exists($column, $submitted)) {
        $data[$column] = $submitted[$column];
    }
}
$data = asset_normalize_input($data);
$errors = asset_validate($data, true);

$duplicate = $errors === [] ? asset_find_duplicate($pdo, $data, $id, $company) : null;
if ($duplicate !== null) {
    $errors[$duplicate['field']] = 'duplicate';
}

// Custom fields: only definitions the role may edit, only submitted keys.
$defs = [];
foreach (field_defs($pdo) as $def) {
    if (field_editable_by($def, $role)) {
        $defs[(string) $def['field_key']] = $def;
    }
}
$customInput = array_intersect_key(custom_input_from($_POST), $defs);
foreach ($customInput as $key => $value) {
    $parsed = field_parse_value($defs[$key], $value);
    if (!$parsed['ok']) {
        $errors['custom.' . $key] = (string) $parsed['error'];
    }
}

if ($errors !== []) {
    flash_set('error', lang('validation_failed') . ' ' . implode(', ', array_keys($errors)));
    redirect('asset.php?id=' . $id);
}

$pdo->beginTransaction();
try {
    asset_update($pdo, $id, $data, $actor, $company);
    $customErrors = custom_values_save($pdo, $id, $customInput, $defs, $actor, false);
    if ($customErrors !== []) {
        $pdo->rollBack();
        flash_set('error', lang('validation_failed'));
    } else {
        $pdo->commit();
        flash_set('success', lang('asset_updated'));
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[save_row] ' . $e->getMessage());
    flash_set('error', lang('save_failed'));
}

redirect('asset.php?id=' . $id);
