<?php
declare(strict_types=1);

/**
 * public/asset.php — asset detail: built-in columns, custom fields, attachments.
 */

session_start();
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/../lib/settings_ui.php';
require_once __DIR__ . '/../lib/assets_ui.php';
lang_init();
app_boot();

if (empty($_SESSION['user_email'])) {
    redirect('index.php');
}
$role = (string) ($_SESSION['role'] ?? 'user');
$actor = (string) $_SESSION['user_email'];
$company = (string) ($_SESSION['company'] ?? '');
$pdo = db_try();
if ($pdo === null || $company === '') {
    http_response_code(503);
    exit(e(lang('db_unavailable')));
}

$id = (int) ($_GET['id'] ?? 0);
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

$isAdmin = in_array($role, ['admin', 'superadmin'], true);
$canEdit = in_array($role, ['manager', 'admin', 'superadmin'], true);

$companyUsers = [];
if (db_table_exists($pdo, 'users')) {
    $stmt = $pdo->prepare('SELECT email FROM users WHERE company = ? ORDER BY email');
    $stmt->execute([$company]);
    $companyUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$files = asset_files($pdo, $id);
$s3Ready = false;
$s3 = null;
try {
    require_once __DIR__ . '/../s3_client.php';
    $s3 = new S3Client();
    $s3Ready = $s3->isConfigured();
} catch (Throwable $e) {
    $s3 = null;
}

$customValues = custom_values_for_asset($pdo, $id);
$editableColumns = [];
if ($canEdit) {
    foreach (asset_form_columns() as $column) {
        $def = asset_column_defs()[$column] ?? null;
        if ($def !== null && in_array($role, $def['form_roles'], true)) {
            $editableColumns[] = $column;
        }
    }
}

$serial = trim((string) ($row['SN'] ?? ''));
$title = $serial !== '' ? $serial : '#' . $id;
?>
<!DOCTYPE html>
<html lang="<?php echo e(lang_code()); ?>" dir="<?php echo e(lang_dir()); ?>">

<head>
    <meta charset="utf-8">
    <title><?php echo e(lang('asset_edit_title')); ?> <?php echo e($title); ?> — <?php echo e(lang('panel_title')); ?></title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="asset-page">
    <div class="page-wrapper">
        <div class="top-toolbar">
            <?php echo lang_selector('asset.php'); ?>
            <?php echo theme_toggle_button(); ?>
        </div>

        <a href="main.php" class="back-link">&larr; <?php echo e(lang('back_to_cmdb')); ?> <?php echo e($company); ?></a>

        <div class="asset-title">
            <h2><?php echo e(lang('asset_edit_title')); ?> <?php echo e($title); ?></h2>
            <?php echo source_badge($pdo, (string) ($row['source'] ?? '')); ?>
        </div>

        <p class="muted">
            <?php echo e(lang('source')); ?>: <?php echo e($row['source'] ?? '—'); ?>
            <?php if (!empty($row['source_ref'])): ?> · <?php echo e((string) $row['source_ref']); ?><?php endif; ?>
            <?php if (!empty($row['updated_at'])): ?> · <?php echo e(lang('asset_updated')); ?> <?php echo e(app_date((string) $row['updated_at'])); ?><?php endif; ?>
            <?php if (!empty($row['updated_by'])): ?> (<?php echo e((string) $row['updated_by']); ?>)<?php endif; ?>
        </p>

        <?php echo app_schema_banner(); ?>
        <?php echo app_flash_html(); ?>

        <form method="post" action="save_row.php" class="form-section">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="row[Id]" value="<?php echo $id; ?>">

            <table class="details-table">
                <tbody>
                <?php foreach (asset_form_columns() as $column): ?>
                    <?php
                    $def = asset_column_defs()[$column] ?? null;
                    if ($def === null) {
                        continue;
                    }
                    $value = (string) ($row[$column] ?? '');
                    $editable = in_array($column, $editableColumns, true);
                    ?>
                    <tr>
                        <th><?php echo e($def['label']); ?><?php echo $column === 'SN' ? ' *' : ''; ?></th>
                        <td>
                            <?php
                            if ($editable && $column === 'UserEmail' && $companyUsers !== []) {
                                echo '<select name="row[UserEmail]"><option value="">—</option>';
                                foreach ($companyUsers as $email) {
                                    $selected = $value === $email ? ' selected' : '';
                                    echo '<option value="' . e($email) . '"' . $selected . '>' . e($email) . '</option>';
                                }
                                echo '</select>';
                            } elseif ($editable) {
                                echo asset_field_input($column, $value);
                            } elseif ($column === 'Status') {
                                echo e(lang_status_label($value));
                            } elseif ($def['type'] === 'bool') {
                                echo e(asset_bool_parse($value) ? lang('true_label') : lang('false_label'));
                            } elseif ($def['type'] === 'datetime') {
                                echo e(app_date($value));
                            } else {
                                echo nl2br(e($value));
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $editableCustom = array_values(array_filter(
                field_defs($pdo),
                static fn (array $def): bool => field_editable_by($def, $role)
            ));
            if ($editableCustom !== []):
                ?>
                <h3><?php echo e(lang('custom_fields')); ?></h3>
                <table class="details-table">
                    <tbody>
                        <?php echo asset_custom_fields_html($pdo, $role, $customValues); ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($canEdit): ?>
                <div class="form-actions">
                    <button type="submit"><?php echo e(lang('save_asset')); ?></button>
                    <a class="link-button" href="main.php"><?php echo e(lang('cancel')); ?></a>
                </div>
            <?php endif; ?>
        </form>

        <h3><?php echo e(lang('files_title')); ?></h3>
        <?php if ($files === []): ?>
            <p class="muted"><?php echo e(lang('no_files_found')); ?></p>
        <?php else: ?>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th><?php echo e(lang('file_singular')); ?></th>
                        <th>MIME</th>
                        <th><?php echo e(lang('records')); ?></th>
                        <th><?php echo e(lang('actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $file): ?>
                    <tr>
                        <td>
                            <?php
                            $url = '';
                            if ($s3Ready && $s3 !== null) {
                                try {
                                    $url = $s3->getPresignedUrl((string) $file['file_path']);
                                } catch (Throwable $e) {
                                    $url = '';
                                }
                            }
                            ?>
                            <?php if ($url !== ''): ?>
                                <a href="<?php echo e($url); ?>" target="_blank" rel="noopener"><?php echo e($file['file_name']); ?></a>
                            <?php else: ?>
                                <?php echo e($file['file_name']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo e($file['mime_type'] ?? '—'); ?></td>
                        <td><?php echo isset($file['size']) ? (int) $file['size'] : 0; ?> B</td>
                        <td>
                            <?php if ($canEdit): ?>
                                <form method="post" action="save_row.php" class="inline-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="row[Id]" value="<?php echo $id; ?>">
                                    <input type="hidden" name="delete_file" value="<?php echo (int) $file['id']; ?>">
                                    <button type="submit" class="link-button danger" onclick="return confirm('<?php echo e(lang('confirm_delete')); ?>')"><?php echo e(lang('delete_btn')); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <form method="post" action="save_row.php" enctype="multipart/form-data" class="upload-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="row[Id]" value="<?php echo $id; ?>">
                <label for="newFile"><?php echo e(lang('upload_title')); ?></label>
                <input type="file" id="newFile" name="new_file" required>
                <button type="submit"><?php echo e(lang('upload_btn')); ?></button>
            </form>
        <?php endif; ?>

        <?php if ($isAdmin): ?>
            <form method="post" action="main.php" class="delete-form" onsubmit="return confirm('<?php echo e(lang('delete_confirm')); ?>')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="delete_asset" value="<?php echo $id; ?>">
                <button type="submit" class="danger"><?php echo e(lang('delete_asset')); ?></button>
            </form>
        <?php endif; ?>
    </div>
    <script src="theme.js"></script>
</body>

</html>
