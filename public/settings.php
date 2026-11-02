<?php
declare(strict_types=1);

/**
 * public/settings.php — Settings & administration.
 *
 * Tabs: General, Storage (S3), API (+ keys), Sources, Fields (dynamic custom
 * fields) and Maintenance (migrations, diagnostics).
 *
 * Only admin/superadmin may open this page. Every mutation is CSRF-protected and
 * follows POST-redirect-GET.
 */

session_start();
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/../lib/settings_ui.php';
require_once __DIR__ . '/../lib/settings_actions.php';
lang_init();
app_boot();

if (empty($_SESSION['user_email'])) {
    redirect('index.php');
}
$role = (string) ($_SESSION['role'] ?? 'user');
if (!in_array($role, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>' . e(lang('access_denied')) . '</p>';
    exit;
}
$actor = (string) $_SESSION['user_email'];

$pdo = db_try();
if ($pdo === null) {
    http_response_code(503);
    echo '<!DOCTYPE html><meta charset="utf-8"><p>' . e(lang('db_unavailable')) . '</p>';
    exit;
}

$tabs = ['general', 'storage', 'api', 'sources', 'fields', 'maintenance'];
$tab = (string) ($_GET['tab'] ?? 'general');
if (!in_array($tab, $tabs, true)) {
    $tab = 'general';
}

$errors = [];
$testResult = null;
$migrationLog = [];
$formOld = [];

if (request_method() === 'POST') {
    csrf_require();
    $outcome = settings_handle_post($pdo, $tab, $actor);
    $errors = $outcome['errors'];
    $testResult = $outcome['test'];
    $migrationLog = $outcome['log'];

    if ($outcome['saved'] && $outcome['redirect'] !== null) {
        flash_set('success', lang('set_saved'));
        redirect($outcome['redirect']);
    }
    if ($errors !== []) {
        $formOld = (array) ($_POST['setting'] ?? []);
    }
}

$newToken = $_SESSION['new_api_token'] ?? null;
unset($_SESSION['new_api_token']);

$sourcesList = sources_all($pdo);
$companies = asset_companies($pdo);
$settings = settings_all(true);

/** Build a settings group form. */
function settings_group_form(string $group, array $settings, array $sources, array $formOld, string $tab): string
{
    $rows = '';
    foreach ($settings as $key => $entry) {
        if (($entry['def']['group'] ?? '') !== $group) {
            continue;
        }
        if (($entry['def']['type'] ?? '') !== 'secret' && array_key_exists($key, $formOld)) {
            $entry['value'] = (string) $formOld[$key];
        }
        $rows .= settings_field_row($key, $entry, $sources);
    }
    if ($rows === '') {
        return '';
    }
    return '<form method="post" class="settings-form">' . csrf_field()
        . '<input type="hidden" name="action" value="save_settings">'
        . '<input type="hidden" name="group" value="' . e($group) . '">'
        . $rows
        . '<div class="form-actions"><button type="submit">' . e(lang('save_changes')) . '</button></div>'
        . '</form>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(lang_code()); ?>" dir="<?php echo e(lang_dir()); ?>">

<head>
    <meta charset="utf-8">
    <title><?php echo e(lang('set_title')); ?> — <?php echo e(lang('title_cmdb')); ?></title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="settings-page">
    <div class="page-wrapper">
        <div class="top-toolbar">
            <?php echo lang_selector('settings.php'); ?>
            <?php echo theme_toggle_button(); ?>
        </div>

        <div class="settings-header">
            <div>
                <a href="main.php" class="back-link">&larr; <?php echo e(lang('back_to_cmdb')); ?></a>
                <h2><?php echo e(lang('set_title')); ?></h2>
                <p class="muted"><?php echo e(lang('signed_in_as')); ?> <?php echo e($actor); ?></p>
            </div>
        </div>

        <?php echo app_schema_banner(); ?>
        <?php echo app_flash_html(); ?>

        <?php if ($errors !== []): ?>
            <div class="flash flash-error">
                <?php foreach ($errors as $field => $code): ?>
                    <div><?php echo e($field === '_' ? '' : $field . ': '); ?><?php echo e(settings_error_message((string) $code)); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <nav class="settings-tabs">
            <?php foreach ($tabs as $item): ?>
                <a href="settings.php?tab=<?php echo e($item); ?>" class="settings-tab<?php echo $item === $tab ? ' active' : ''; ?>">
                    <?php echo e(lang('set_tab_' . $item)); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($newToken !== null): ?>
            <div class="flash flash-warning token-box">
                <strong><?php echo e(lang('set_key_token_once')); ?></strong>
                <code class="api-token"><?php echo e($newToken); ?></code>
                <p class="muted"><?php echo e(lang('set_key_token_warning')); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($testResult !== null): ?>
            <div class="flash <?php echo !empty($testResult['ok']) ? 'flash-success' : 'flash-error'; ?>">
                <?php echo e((string) ($testResult['message'] ?? '')); ?>
            </div>
        <?php endif; ?>

        <?php if ($migrationLog !== []): ?>
            <pre class="migration-log"><?php echo e(implode("\n", $migrationLog)); ?></pre>
        <?php endif; ?>

        <div class="settings-content">
        <?php if ($tab === 'general'): ?>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_tab_general')); ?></h3>
                <p class="muted"><?php echo e(lang('set_general_intro')); ?></p>
                <?php echo settings_group_form('general', $settings, $sourcesList, $formOld, 'general'); ?>

                <h3><?php echo e(lang('set_group_assets')); ?></h3>
                <p class="muted"><?php echo e(lang('set_assets_intro')); ?></p>
                <?php echo settings_group_form('assets', $settings, $sourcesList, $formOld, 'general'); ?>
            </div>

        <?php elseif ($tab === 'storage'): ?>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_tab_storage')); ?></h3>
                <p class="muted"><?php echo e(lang('set_storage_intro')); ?></p>
                <?php if (!crypto_enabled()): ?>
                    <div class="flash flash-warning"><?php echo e(lang('set_encryption_missing')); ?></div>
                <?php endif; ?>
                <?php echo settings_group_form('storage', $settings, $sourcesList, $formOld, 'storage'); ?>
            </div>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_test_connection')); ?></h3>
                <p class="muted"><?php echo e(lang('set_test_connection_hint')); ?></p>
                <form method="post" class="settings-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="s3_test">
                    <?php
                    $testFields = [
                        'region'     => ['setting_s3_region', 'text'],
                        'bucket'     => ['setting_s3_bucket', 'text'],
                        'endpoint'   => ['setting_s3_endpoint', 'text'],
                        'access_key' => ['setting_s3_access_key', 'text'],
                        'secret_key' => ['setting_s3_secret_key', 'password'],
                    ];
                    foreach ($testFields as $fieldName => [$labelKey, $inputType]):
                        $value = $fieldName === 'secret_key' ? '' : (string) $settings['s3.' . $fieldName]['value'];
                        ?>
                        <div class="form-row">
                            <label for="t_<?php echo e($fieldName); ?>"><?php echo e(lang($labelKey)); ?></label>
                            <div class="form-control">
                                <input type="<?php echo e($inputType); ?>" id="t_<?php echo e($fieldName); ?>"
                                       name="<?php echo e($fieldName); ?>" value="<?php echo e($value); ?>"
                                       <?php echo $inputType === 'password' ? 'placeholder="' . e(lang('set_secret_keep')) . '" autocomplete="new-password"' : ''; ?>>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-row">
                        <label for="t_path_style"><?php echo e(lang('setting_s3_path_style')); ?></label>
                        <div class="form-control">
                            <input type="checkbox" id="t_path_style" name="path_style" value="1" <?php echo settings_bool('s3.path_style') ? 'checked' : ''; ?>>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit"><?php echo e(lang('set_test_connection')); ?></button>
                    </div>
                </form>
            </div>

        <?php elseif ($tab === 'sources'): ?>
            <?php
            $editSource = isset($_GET['edit_source']) ? source_get($pdo, (string) $_GET['edit_source']) : null;
            $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE `source` = ?');
            ?>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_tab_sources')); ?></h3>
                <p class="muted"><?php echo e(lang('set_sources_intro')); ?></p>
                <table class="grid-table">
                    <thead>
                        <tr>
                            <th><?php echo e(lang('set_source_label')); ?></th>
                            <th><?php echo e(lang('set_source_slug')); ?></th>
                            <th><?php echo e(lang('set_source_color')); ?></th>
                            <th><?php echo e(lang('records')); ?></th>
                            <th><?php echo e(lang('set_source_default_flag')); ?></th>
                            <th><?php echo e(lang('set_actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sourcesList as $source): ?>
                        <?php $usageStmt->execute([$source['slug']]); $usage = (int) $usageStmt->fetchColumn(); ?>
                        <tr>
                            <td><?php echo source_badge($pdo, (string) $source['slug']); ?></td>
                            <td><code><?php echo e($source['slug']); ?></code></td>
                            <td><?php echo e($source['color'] ?? '—'); ?></td>
                            <td><?php echo $usage; ?></td>
                            <td><?php echo (int) $source['is_default'] === 1 ? e(lang('yes')) : '—'; ?></td>
                            <td class="row-actions">
                                <a class="link-button" href="settings.php?tab=sources&amp;edit_source=<?php echo e($source['slug']); ?>"><?php echo e(lang('set_edit')); ?></a>
                                <?php if ((int) $source['is_system'] === 0): ?>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="source_delete">
                                        <input type="hidden" name="slug" value="<?php echo e($source['slug']); ?>">
                                        <button type="submit" class="link-button danger" onclick="return confirm('<?php echo e(lang('set_source_delete_confirm')); ?>')"><?php echo e(lang('set_delete')); ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted"><?php echo e(lang('set_source_system')); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="settings-panel">
                <h3><?php echo $editSource ? e(lang('set_source_edit')) : e(lang('set_source_add')); ?></h3>
                <p class="muted"><?php echo e(lang('set_source_slug_hint')); ?></p>
                <form method="post" class="settings-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="source_save">
                    <?php if ($editSource !== null): ?>
                        <input type="hidden" name="original_slug" value="<?php echo e($editSource['slug']); ?>">
                    <?php endif; ?>
                    <div class="form-row">
                        <label for="s_slug"><?php echo e(lang('set_source_slug')); ?></label>
                        <div class="form-control">
                            <input type="text" id="s_slug" name="slug" maxlength="32" value="<?php echo e($editSource['slug'] ?? ''); ?>" <?php echo $editSource !== null ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="s_label"><?php echo e(lang('set_source_label')); ?></label>
                        <div class="form-control"><input type="text" id="s_label" name="label" required maxlength="64" value="<?php echo e($editSource['label'] ?? ''); ?>"></div>
                    </div>
                    <div class="form-row">
                        <label for="s_color"><?php echo e(lang('set_source_color')); ?></label>
                        <div class="form-control"><input type="text" id="s_color" name="color" maxlength="9" placeholder="#2f855a" value="<?php echo e($editSource['color'] ?? ''); ?>"></div>
                    </div>
                    <div class="form-row">
                        <label for="s_default"><?php echo e(lang('set_source_default_flag')); ?></label>
                        <div class="form-control"><input type="checkbox" id="s_default" name="is_default" value="1" <?php echo ($editSource !== null && (int) $editSource['is_default'] === 1) ? 'checked' : ''; ?>></div>
                    </div>
                    <div class="form-actions">
                        <button type="submit"><?php echo e(lang('save_changes')); ?></button>
                        <?php if ($editSource !== null): ?>
                            <a class="link-button" href="settings.php?tab=sources"><?php echo e(lang('cancel')); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

        <?php elseif ($tab === 'api'): ?>
            <?php
            $editKey = isset($_GET['edit_key']) ? api_key_get($pdo, (int) $_GET['edit_key']) : null;
            $allowedForEdit = $editKey !== null && !empty($editKey['allowed_sources'])
                ? array_map('trim', explode(',', (string) $editKey['allowed_sources']))
                : [];
            ?>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_tab_api')); ?></h3>
                <p class="muted"><?php echo e(lang('set_api_intro')); ?></p>
                <?php echo settings_group_form('api', $settings, $sourcesList, $formOld, 'api'); ?>
                <p class="muted"><?php echo e(lang('set_api_endpoint_hint')); ?> <code>/api/v1/health</code></p>
            </div>

            <div class="settings-panel">
                <h3><?php echo e(lang('set_api_keys')); ?></h3>
                <?php $keys = api_keys_all($pdo); ?>
                <?php if ($keys === []): ?>
                    <p class="muted"><?php echo e(lang('set_key_none')); ?></p>
                <?php else: ?>
                    <table class="grid-table">
                        <thead>
                            <tr>
                                <th><?php echo e(lang('set_key_label')); ?></th>
                                <th><?php echo e(lang('set_key_prefix')); ?></th>
                                <th><?php echo e(lang('set_key_company')); ?></th>
                                <th><?php echo e(lang('set_key_source')); ?></th>
                                <th><?php echo e(lang('set_key_scopes')); ?></th>
                                <th><?php echo e(lang('set_key_last_used')); ?></th>
                                <th><?php echo e(lang('set_key_active')); ?></th>
                                <th><?php echo e(lang('set_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($keys as $key): ?>
                            <?php
                            $scopes = [];
                            if ((int) $key['can_read'] === 1) { $scopes[] = 'R'; }
                            if ((int) $key['can_write'] === 1) { $scopes[] = 'W'; }
                            if ((int) $key['can_delete'] === 1) { $scopes[] = 'D'; }
                            ?>
                            <tr>
                                <td><?php echo e($key['label']); ?></td>
                                <td><code><?php echo e($key['prefix']); ?></code></td>
                                <td><?php echo e($key['company'] ?? lang('set_key_global')); ?></td>
                                <td><?php echo e($key['source_slug'] ?? '—'); ?><?php echo !empty($key['allowed_sources']) ? ' (' . e($key['allowed_sources']) . ')' : ''; ?></td>
                                <td><?php echo e(implode(' ', $scopes)); ?></td>
                                <td>
                                    <?php echo $key['last_used_at'] ? e(app_date((string) $key['last_used_at'])) : e(lang('set_key_never')); ?>
                                    <?php echo $key['last_used_ip'] ? '<br><small class="muted">' . e($key['last_used_ip']) . '</small>' : ''; ?>
                                </td>
                                <td><?php echo ((int) $key['is_active'] === 1) ? e(lang('set_key_enabled')) : e(lang('set_key_disabled')); ?></td>
                                <td class="row-actions">
                                    <a class="link-button" href="settings.php?tab=api&amp;edit_key=<?php echo (int) $key['id']; ?>"><?php echo e(lang('set_edit')); ?></a>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="key_rotate">
                                        <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>">
                                        <button type="submit" class="link-button" onclick="return confirm('<?php echo e(lang('set_key_rotate_confirm')); ?>')"><?php echo e(lang('set_key_rotate')); ?></button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="key_toggle">
                                        <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>">
                                        <input type="hidden" name="active" value="<?php echo ((int) $key['is_active'] === 1) ? '0' : '1'; ?>">
                                        <button type="submit" class="link-button"><?php echo ((int) $key['is_active'] === 1) ? e(lang('set_key_disable')) : e(lang('set_key_enable')); ?></button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="key_delete">
                                        <input type="hidden" name="key_id" value="<?php echo (int) $key['id']; ?>">
                                        <button type="submit" class="link-button danger" onclick="return confirm('<?php echo e(lang('set_key_delete_confirm')); ?>')"><?php echo e(lang('set_delete')); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="settings-panel">
                <h3><?php echo $editKey ? e(lang('set_key_edit')) : e(lang('set_key_create')); ?></h3>
                <form method="post" class="settings-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editKey ? 'key_update' : 'key_create'; ?>">
                    <?php if ($editKey !== null): ?>
                        <input type="hidden" name="key_id" value="<?php echo (int) $editKey['id']; ?>">
                    <?php endif; ?>
                    <div class="form-row">
                        <label for="k_label"><?php echo e(lang('set_key_label')); ?></label>
                        <div class="form-control"><input type="text" id="k_label" name="label" required maxlength="64" value="<?php echo e($editKey['label'] ?? ''); ?>"></div>
                    </div>
                    <div class="form-row">
                        <label for="k_company"><?php echo e(lang('set_key_company')); ?></label>
                        <div class="form-control">
                            <input type="text" id="k_company" name="company" list="company-list" value="<?php echo e($editKey['company'] ?? ''); ?>" placeholder="<?php echo e(lang('set_key_global')); ?>">
                            <datalist id="company-list">
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo e($company); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="k_source"><?php echo e(lang('set_key_source')); ?></label>
                        <div class="form-control">
                            <select id="k_source" name="source_slug">
                                <option value="">— <?php echo e(lang('set_key_default_source')); ?> —</option>
                                <?php foreach ($sourcesList as $source): ?>
                                    <option value="<?php echo e($source['slug']); ?>" <?php echo ($editKey['source_slug'] ?? '') === $source['slug'] ? 'selected' : ''; ?>><?php echo e($source['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <label><?php echo e(lang('set_key_allowed_sources')); ?></label>
                        <div class="form-control checkbox-list">
                            <?php foreach ($sourcesList as $source): ?>
                                <label class="inline-check">
                                    <input type="checkbox" name="allowed_sources[]" value="<?php echo e($source['slug']); ?>" <?php echo in_array($source['slug'], $allowedForEdit, true) ? 'checked' : ''; ?>>
                                    <?php echo e($source['label']); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="muted"><?php echo e(lang('set_key_allowed_sources_hint')); ?></p>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="k_allowlist"><?php echo e(lang('set_key_allowlist')); ?></label>
                        <div class="form-control"><textarea id="k_allowlist" name="ip_allowlist" rows="2" placeholder="<?php echo e(lang('set_allowlist_hint')); ?>"><?php echo e($editKey['ip_allowlist'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="form-row">
                        <label><?php echo e(lang('set_key_scopes')); ?></label>
                        <div class="form-control checkbox-list">
                            <label class="inline-check"><input type="checkbox" name="can_read" value="1" <?php echo ($editKey === null || (int) $editKey['can_read'] === 1) ? 'checked' : ''; ?>> <?php echo e(lang('set_key_read')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="can_write" value="1" <?php echo ($editKey === null || (int) $editKey['can_write'] === 1) ? 'checked' : ''; ?>> <?php echo e(lang('set_key_write')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="can_delete" value="1" <?php echo ($editKey !== null && (int) $editKey['can_delete'] === 1) ? 'checked' : ''; ?>> <?php echo e(lang('set_key_delete')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="is_active" value="1" <?php echo ($editKey === null || (int) $editKey['is_active'] === 1) ? 'checked' : ''; ?>> <?php echo e(lang('set_key_active')); ?></label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit"><?php echo $editKey ? e(lang('save_changes')) : e(lang('set_key_generate')); ?></button>
                        <?php if ($editKey !== null): ?>
                            <a class="link-button" href="settings.php?tab=api"><?php echo e(lang('cancel')); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

        <?php elseif ($tab === 'fields'): ?>
            <?php
            $allFields = field_defs($pdo, false);
            $editField = isset($_GET['edit_field']) ? field_def_by_id($pdo, (int) $_GET['edit_field']) : null;
            $roles = ['user', 'manager', 'admin', 'superadmin'];
            ?>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_tab_fields')); ?></h3>
                <p class="muted"><?php echo e(lang('set_fields_intro')); ?></p>
                <?php if ($allFields === []): ?>
                    <p class="muted"><?php echo e(lang('set_field_none')); ?></p>
                <?php else: ?>
                    <table class="grid-table">
                        <thead>
                            <tr>
                                <th><?php echo e(lang('set_field_label')); ?></th>
                                <th><?php echo e(lang('set_field_key')); ?></th>
                                <th><?php echo e(lang('set_field_type')); ?></th>
                                <th><?php echo e(lang('set_field_roles')); ?></th>
                                <th><?php echo e(lang('set_field_flags')); ?></th>
                                <th><?php echo e(lang('set_field_active')); ?></th>
                                <th><?php echo e(lang('set_actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allFields as $def): ?>
                            <?php
                            $flags = [];
                            if ((int) $def['is_required'] === 1) { $flags[] = lang('set_field_required'); }
                            if ((int) $def['is_unique'] === 1) { $flags[] = lang('set_field_unique'); }
                            if ((int) $def['show_in_grid'] === 1) { $flags[] = lang('set_field_grid'); }
                            if ((int) $def['is_searchable'] === 1) { $flags[] = lang('set_field_searchable'); }
                            ?>
                            <tr>
                                <td><?php echo e($def['label']); ?></td>
                                <td><code><?php echo e($def['field_key']); ?></code></td>
                                <td><?php echo e((string) ($def['data_type'])); ?><?php echo field_needs_options((string) $def['data_type']) ? ' (' . count(field_options($def)) . ')' : ''; ?></td>
                                <td><small><?php echo e(implode(', ', field_editable_roles($def))); ?></small></td>
                                <td><small><?php echo e(implode(', ', $flags)); ?></small></td>
                                <td><?php echo (int) $def['is_active'] === 1 ? e(lang('yes')) : e(lang('no')); ?></td>
                                <td class="row-actions">
                                    <a class="link-button" href="settings.php?tab=fields&amp;edit_field=<?php echo (int) $def['id']; ?>"><?php echo e(lang('set_edit')); ?></a>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="field_toggle">
                                        <input type="hidden" name="field_id" value="<?php echo (int) $def['id']; ?>">
                                        <input type="hidden" name="active" value="<?php echo (int) $def['is_active'] === 1 ? '0' : '1'; ?>">
                                        <button type="submit" class="link-button"><?php echo (int) $def['is_active'] === 1 ? e(lang('set_field_deactivate')) : e(lang('set_field_activate')); ?></button>
                                    </form>
                                    <form method="post" class="inline-form">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="field_delete">
                                        <input type="hidden" name="field_id" value="<?php echo (int) $def['id']; ?>">
                                        <button type="submit" class="link-button danger" onclick="return confirm('<?php echo e(lang('set_field_delete_confirm')); ?>')"><?php echo e(lang('set_delete')); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="settings-panel">
                <h3><?php echo $editField ? e(lang('set_field_edit')) : e(lang('set_field_add')); ?></h3>
                <p class="muted"><?php echo e(lang('set_field_i18n_hint')); ?></p>
                <form method="post" class="settings-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="field_save">
                    <?php if ($editField !== null): ?>
                        <input type="hidden" name="field_id" value="<?php echo (int) $editField['id']; ?>">
                    <?php endif; ?>
                    <div class="form-row">
                        <label for="f_label"><?php echo e(lang('set_field_label')); ?></label>
                        <div class="form-control"><input type="text" id="f_label" name="label" required maxlength="128" value="<?php echo e($editField['label'] ?? ''); ?>"></div>
                    </div>
                    <div class="form-row">
                        <label for="f_key"><?php echo e(lang('set_field_key')); ?></label>
                        <div class="form-control">
                            <input type="text" id="f_key" name="field_key" pattern="[a-z][a-z0-9_]{1,63}" value="<?php echo e($editField['field_key'] ?? ''); ?>" <?php echo $editField !== null ? 'readonly' : ''; ?>>
                            <p class="muted"><?php echo e(lang('set_field_key_hint')); ?></p>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="f_type"><?php echo e(lang('set_field_type')); ?></label>
                        <div class="form-control">
                            <select id="f_type" name="data_type">
                                <?php foreach (field_types() as $typeKey => $typeDef): ?>
                                    <option value="<?php echo e($typeKey); ?>" <?php echo ($editField['data_type'] ?? 'text') === $typeKey ? 'selected' : ''; ?>><?php echo e($typeDef['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="f_options"><?php echo e(lang('set_field_options')); ?></label>
                        <div class="form-control">
                            <textarea id="f_options" name="options_json" rows="4" placeholder="DC1&#10;DC2"><?php echo e(implode("\n", field_options($editField ?? []))); ?></textarea>
                            <p class="muted"><?php echo e(lang('set_field_options_hint')); ?></p>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="f_default"><?php echo e(lang('set_field_default')); ?></label>
                        <div class="form-control"><input type="text" id="f_default" name="default_value" value="<?php echo e($editField['default_value'] ?? ''); ?>"></div>
                    </div>
                    <div class="form-row">
                        <label for="f_sort"><?php echo e(lang('set_field_sort')); ?></label>
                        <div class="form-control"><input type="number" id="f_sort" name="sort_order" value="<?php echo (int) ($editField['sort_order'] ?? 0); ?>"></div>
                    </div>
                    <div class="form-row">
                        <label><?php echo e(lang('set_field_flags')); ?></label>
                        <div class="form-control checkbox-list">
                            <label class="inline-check"><input type="checkbox" name="is_required" value="1" <?php echo !empty($editField['is_required']) ? 'checked' : ''; ?>> <?php echo e(lang('set_field_required')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="is_unique" value="1" <?php echo !empty($editField['is_unique']) ? 'checked' : ''; ?>> <?php echo e(lang('set_field_unique')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="is_searchable" value="1" <?php echo ($editField === null || !empty($editField['is_searchable'])) ? 'checked' : ''; ?>> <?php echo e(lang('set_field_searchable')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="show_in_grid" value="1" <?php echo !empty($editField['show_in_grid']) ? 'checked' : ''; ?>> <?php echo e(lang('set_field_grid')); ?></label>
                            <label class="inline-check"><input type="checkbox" name="is_active" value="1" <?php echo ($editField === null || !empty($editField['is_active'])) ? 'checked' : ''; ?>> <?php echo e(lang('set_field_active')); ?></label>
                        </div>
                    </div>
                    <div class="form-row">
                        <label><?php echo e(lang('set_field_roles')); ?></label>
                        <div class="form-control checkbox-list">
                            <?php $fieldRoles = field_editable_roles($editField ?? ['editable_roles' => 'admin,superadmin']); ?>
                            <?php foreach ($roles as $roleName): ?>
                                <label class="inline-check">
                                    <input type="checkbox" name="editable_roles[]" value="<?php echo e($roleName); ?>" <?php echo in_array($roleName, $fieldRoles, true) ? 'checked' : ''; ?>>
                                    <?php echo e(lang('role_' . $roleName)); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <label><?php echo e(lang('set_field_validation')); ?></label>
                        <div class="form-control checkbox-list">
                            <label class="inline-check"><?php echo e(lang('set_field_min')); ?> <input type="number" step="any" name="min" value="<?php echo e((string) (field_rules($editField ?? [])['min'] ?? '')); ?>"></label>
                            <label class="inline-check"><?php echo e(lang('set_field_max')); ?> <input type="number" step="any" name="max" value="<?php echo e((string) (field_rules($editField ?? [])['max'] ?? '')); ?>"></label>
                            <label class="inline-check"><?php echo e(lang('set_field_maxlength')); ?> <input type="number" name="maxlength" value="<?php echo e((string) (field_rules($editField ?? [])['maxlength'] ?? '')); ?>"></label>
                            <label class="inline-check"><?php echo e(lang('set_field_pattern')); ?> <input type="text" name="pattern" value="<?php echo e((string) (field_rules($editField ?? [])['pattern'] ?? '')); ?>"></label>
                        </div>
                    </div>
                    <div class="form-row">
                        <label><?php echo e(lang('set_field_translations')); ?></label>
                        <div class="form-control checkbox-list">
                            <?php
                            $editI18n = json_decode((string) ($editField['label_i18n'] ?? ''), true);
                            $editI18n = is_array($editI18n) ? $editI18n : [];
                            foreach (lang_supported() as $localeCode):
                                ?>
                                <label class="inline-check">
                                    <?php echo e(lang_native_name($localeCode)); ?>
                                    <input type="text" name="label_i18n[<?php echo e($localeCode); ?>]" value="<?php echo e($editI18n[$localeCode] ?? ''); ?>" maxlength="128">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit"><?php echo e(lang('save_changes')); ?></button>
                        <?php if ($editField !== null): ?>
                            <a class="link-button" href="settings.php?tab=fields"><?php echo e(lang('cancel')); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

        <?php elseif ($tab === 'maintenance'): ?>
            <?php $status = migrate_status($pdo); ?>
            <div class="settings-panel">
                <h3><?php echo e(lang('set_tab_maintenance')); ?></h3>
                <p class="muted"><?php echo e(lang('set_maint_intro')); ?></p>
                <table class="grid-table">
                    <tbody>
                        <tr>
                            <th><?php echo e(lang('set_maint_ready')); ?></th>
                            <td><?php echo $status['ready'] ? e(lang('yes')) : e(lang('no')); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo e(lang('set_maint_applied')); ?></th>
                            <td><?php echo $status['applied'] === [] ? '—' : e(implode(', ', $status['applied'])); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo e(lang('set_maint_pending')); ?></th>
                            <td><?php echo $status['pending'] === [] ? e(lang('set_maint_none')) : e(implode(', ', $status['pending'])); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo e(lang('set_maint_encryption')); ?></th>
                            <td><?php echo crypto_enabled() ? e(lang('set_maint_encryption_ok')) : e(lang('set_maint_encryption_missing')); ?></td>
                        </tr>
                    </tbody>
                </table>
                <form method="post" class="settings-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="migrate">
                    <div class="form-row">
                        <label for="m_dry"><?php echo e(lang('set_maint_dry')); ?></label>
                        <div class="form-control"><input type="checkbox" id="m_dry" name="dry_run" value="1"></div>
                    </div>
                    <div class="form-actions"><button type="submit"><?php echo e(lang('set_maint_run')); ?></button></div>
                </form>
                <p class="muted"><?php echo e(lang('set_maint_cli_hint')); ?> <code>php bin/migrate.php</code></p>
            </div>

            <div class="settings-panel">
                <h3><?php echo e(lang('set_maint_diagnostics')); ?></h3>
                <table class="grid-table">
                    <tbody>
                        <tr><th><?php echo e(lang('set_maint_version')); ?></th><td><?php echo e(APP_VERSION); ?></td></tr>
                        <tr><th>PHP</th><td><?php echo e(PHP_VERSION); ?></td></tr>
                        <tr><th><?php echo e(lang('set_maint_db')); ?></th><td><?php echo e((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)); ?> / <?php echo e((string) DB_NAME); ?></td></tr>
                        <tr><th><?php echo e(lang('set_maint_timezone')); ?></th><td><?php echo e(app_timezone()); ?> — <?php echo e(app_date(gmdate('Y-m-d H:i:s'))); ?></td></tr>
                        <tr><th><?php echo e(lang('set_api_keys')); ?></th><td><?php echo count(api_keys_all($pdo)); ?></td></tr>
                        <tr><th><?php echo e(lang('set_tab_sources')); ?></th><td><?php echo count($sourcesList); ?></td></tr>
                        <tr><th><?php echo e(lang('set_tab_fields')); ?></th><td><?php echo count(field_defs($pdo, false)); ?></td></tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        </div><!-- .settings-content -->
    </div><!-- .page-wrapper -->
    <script src="theme.js"></script>
</body>

</html>


