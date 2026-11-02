<?php
declare(strict_types=1);

/**
 * public/asset_new.php — create an asset from the panel.
 *
 * Manager and above. Values land in `assets` with the selected source and
 * custom fields are stored through the EAV helper.
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
if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>' . e(lang('access_denied')) . '</p>';
    exit;
}

$pdo = db_try();
if ($pdo === null || !app_schema_ready()) {
    http_response_code(503);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>' . e(lang('db_unavailable')) . '</p>';
    exit;
}

$company = (string) ($_SESSION['company'] ?? '');
if ($company === '') {
    http_response_code(400);
    exit('No company assigned in session.');
}

$columns = asset_create_columns($role);
$sourcesList = sources_all($pdo);
$defaultSource = source_default_slug($pdo);
$errors = [];
$formValues = [];
$customErrors = [];
$customValues = [];

if (request_method() === 'POST') {
    csrf_require();

    $submitted = (array) ($_POST['row'] ?? []);
    $data = [];
    foreach ($columns as $column) {
        if (array_key_exists($column, $submitted)) {
            $data[$column] = $submitted[$column];
        }
    }
    $data = asset_normalize_input($data);
    $data['company'] = $company;

    $source = (string) ($_POST['source'] ?? $defaultSource);
    if (!source_exists($pdo, $source)) {
        $source = $defaultSource;
    }

    $customInput = custom_input_from($_POST);
    $errors = asset_validate($data, false);

    // Duplicate guard (UUID / SN) before touching the database.
    if ($errors === []) {
        $duplicate = asset_find_duplicate($pdo, $data, null, $company);
        if ($duplicate !== null) {
            $errors[$duplicate['field']] = 'duplicate';
        }
    }

    // Validate custom values before insert so nothing is half-written.
    $defs = field_defs_by_key($pdo);
    foreach ($customInput as $key => $value) {
        if (!isset($defs[$key]) || !field_editable_by($defs[$key], $role)) {
            $customErrors[$key] = 'unknown_field';
            continue;
        }
        $parsed = field_parse_value($defs[$key], $value);
        if (!$parsed['ok']) {
            $customErrors[$key] = (string) $parsed['error'];
        }
    }

    if ($errors === [] && $customErrors === []) {
        $pdo->beginTransaction();
        try {
            $assetId = asset_create($pdo, $data, $actor, $source);
            $saveErrors = custom_values_save($pdo, $assetId, $customInput, $defs, $actor, false);
            if ($saveErrors !== []) {
                $pdo->rollBack();
                $customErrors = $saveErrors;
            } else {
                $pdo->commit();
                flash_set('success', lang('asset_created'));
                redirect('asset.php?id=' . $assetId);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[asset_new] ' . $e->getMessage());
            $errors['_'] = 'save_failed';
        }
    }

    $formValues = $data;
    $customValues = $customInput;
    flash_set('error', lang('validation_failed'));
}

$companyUsers = [];
if (db_table_exists($pdo, 'users')) {
    $stmt = $pdo->prepare('SELECT email FROM users WHERE company = ? ORDER BY email');
    $stmt->execute([$company]);
    $companyUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(lang_code()); ?>" dir="<?php echo e(lang_dir()); ?>">

<head>
    <meta charset="utf-8">
    <title><?php echo e(lang('asset_new_title')); ?> — <?php echo e(lang('panel_title')); ?></title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="asset-page">
    <div class="page-wrapper">
        <div class="top-toolbar">
            <?php echo lang_selector('asset_new.php'); ?>
            <?php echo theme_toggle_button(); ?>
        </div>

        <a href="main.php" class="back-link">&larr; <?php echo e(lang('back_to_cmdb')); ?> <?php echo e($company); ?></a>
        <h2><?php echo e(lang('asset_new_title')); ?></h2>
        <?php echo app_flash_html(); ?>

        <?php if ($errors !== []): ?>
            <div class="flash flash-error">
                <?php foreach ($errors as $field => $code): ?>
                    <div><?php echo e($field === '_' ? lang('save_failed') : $field . ': ' . settings_error_message((string) $code)); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="form-section">
            <?php echo csrf_field(); ?>
            <table class="details-table">
                <tbody>
                    <tr>
                        <th><?php echo e(lang('source')); ?></th>
                        <td>
                            <select name="source">
                                <?php foreach ($sourcesList as $source): ?>
                                    <option value="<?php echo e($source['slug']); ?>" <?php echo $source['slug'] === $defaultSource ? 'selected' : ''; ?>>
                                        <?php echo e($source['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php foreach ($columns as $column): ?>
                        <tr>
                            <th><?php echo e(asset_column_defs()[$column]['label'] ?? $column); ?><?php echo $column === 'SN' ? ' *' : ''; ?></th>
                            <td>
                                <?php
                                if ($column === 'UserEmail' && $companyUsers !== []) {
                                    echo '<select name="row[UserEmail]"><option value="">—</option>';
                                    foreach ($companyUsers as $email) {
                                        $selected = ($formValues['UserEmail'] ?? '') === $email ? ' selected' : '';
                                        echo '<option value="' . e($email) . '"' . $selected . '>' . e($email) . '</option>';
                                    }
                                    echo '</select>';
                                } else {
                                    echo asset_field_input($column, $formValues[$column] ?? '');
                                }
                                ?>
                                <?php if (isset($errors[$column])): ?>
                                    <div class="field-error"><?php echo e($errors[$column] === 'duplicate' ? lang('set_err_duplicate') : settings_error_message((string) $errors[$column])); ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (field_defs($pdo) !== []): ?>
                        <tr class="section-row"><th colspan="2"><?php echo e(lang('custom_fields')); ?></th></tr>
                        <?php echo asset_custom_fields_html($pdo, $role, $customValues, $customErrors); ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="form-actions">
                <button type="submit"><?php echo e(lang('create_asset')); ?></button>
                <a class="link-button" href="main.php"><?php echo e(lang('cancel')); ?></a>
            </div>
        </form>
    </div>
    <script src="theme.js"></script>
</body>

</html>
