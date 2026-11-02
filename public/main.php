<?php
declare(strict_types=1);

/**
 * public/main.php — asset grid.
 *
 * Filters/sorting/paging come from lib/assets.php (asset_list), so the panel,
 * the CSV export and the API share one query implementation.
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
$currentUser = (string) $_SESSION['user_email'];
$isSuper = $role === 'superadmin';
$canEdit = in_array($role, ['manager', 'admin', 'superadmin'], true);
$canDelete = in_array($role, ['admin', 'superadmin'], true);
$canCreate = $canEdit;

$pdo = db_try();
if ($pdo === null) {
    http_response_code(503);
    exit(e(lang('db_unavailable')));
}

// --- Superadmin: switch company -----------------------------------------
$allCompanies = asset_companies($pdo);
if ($isSuper && request_method() === 'POST' && isset($_POST['switch_company'])) {
    csrf_require();
    if (in_array($_POST['switch_company'], $allCompanies, true)) {
        $_SESSION['company'] = (string) $_POST['switch_company'];
    }
    redirect('main.php');
}

$company = (string) ($_SESSION['company'] ?? '');
if ($company === '') {
    if ($isSuper && $allCompanies !== []) {
        $company = $allCompanies[0];
        $_SESSION['company'] = $company;
    } else {
        http_response_code(400);
        exit('No company assigned in session.');
    }
}

// --- Delete action (POST, CSRF) -----------------------------------------
if (request_method() === 'POST' && isset($_POST['delete_asset'])) {
    csrf_require();
    if (!$canDelete) {
        http_response_code(403);
        exit(e(lang('access_denied')));
    }
    if (asset_soft_delete($pdo, (int) $_POST['delete_asset'], $currentUser, $company)) {
        flash_set('success', lang('asset_deleted'));
    }
    redirect('main.php');
}

$settings = settings_all();
$perPage = max(5, min(500, (int) ($settings['general.per_page']['value'] ?? 25)));

$searchField = trim((string) ($_GET['search_field'] ?? ''));
$searchText = trim((string) ($_GET['search_text'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? ''));
$dir = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = asset_list($pdo, [
    'company'      => $company,
    'source'       => $sourceFilter,
    'search'       => $searchText,
    'search_field' => $searchField,
    'owner_email'  => $role === 'user' ? $currentUser : '',
    'sort'         => $sort,
    'dir'          => $dir,
    'page'         => $page,
    'per_page'     => $perPage,
]);

$rows = $result['rows'];
$total = $result['total'];
$totalPages = $result['pages'];
$assetIds = array_map(static fn (array $row): int => (int) $row['Id'], $rows);
$customValues = custom_values_load($pdo, $assetIds);
$gridFields = array_values(array_filter(
    field_defs($pdo),
    static fn (array $def): bool => (int) $def['show_in_grid'] === 1
));
$sourcesList = sources_all($pdo);

$fileCounts = [];
if ($assetIds !== [] && db_table_exists($pdo, 'device_files')) {
    $in = implode(',', array_fill(0, count($assetIds), '?'));
    $stmt = $pdo->prepare("SELECT device_id, COUNT(*) AS c FROM device_files
                            WHERE device_table = 'assets' AND device_id IN ($in) GROUP BY device_id");
    $stmt->execute($assetIds);
    foreach ($stmt->fetchAll() as $row) {
        $fileCounts[(int) $row['device_id']] = (int) $row['c'];
    }
}

$gridColumns = asset_grid_columns();
$editableColumns = asset_grid_editable_columns($role);

$companyUsers = [];
if (db_table_exists($pdo, 'users')) {
    $userStmt = $pdo->prepare('SELECT email FROM users WHERE company = ? ORDER BY email');
    $userStmt->execute([$company]);
    $companyUsers = $userStmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Build a query string for links, dropping empty values. */
function grid_url(array $base, array $overrides = []): string
{
    $params = array_filter(
        array_merge($base, $overrides),
        static fn ($value): bool => $value !== '' && $value !== null
    );
    return 'main.php' . ($params === [] ? '' : '?' . http_build_query($params));
}

$filterParams = [
    'search_field' => $searchField,
    'search_text'  => $searchText,
    'source'       => $sourceFilter,
    'sort'         => $sort,
    'dir'          => $dir,
];

/** Translate a built-in column header (fallback: its English label). */
function column_label(string $column): string
{
    $key = 'col_' . strtolower($column);
    $translated = lang($key);
    return $translated === $key ? (asset_column_defs()[$column]['label'] ?? $column) : $translated;
}

/** Sort link for a column header. */
function sort_link(string $column, array $filterParams, string $sort, string $dir): string
{
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sort === $column ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="' . e(grid_url($filterParams, ['sort' => $column, 'dir' => $nextDir])) . '">'
        . e(column_label($column)) . $arrow . '</a>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(lang_code()); ?>" dir="<?php echo e(lang_dir()); ?>">

<head>
    <meta charset="utf-8">
    <title><?php echo e(lang('title_cmdb')); ?> <?php echo e($company); ?></title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="dashboard-page">
    <div class="page-wrapper">
        <div class="top-toolbar">
            <?php echo lang_selector('main.php'); ?>
            <?php echo theme_toggle_button(); ?>
        </div>

        <div class="dashboard-header">
            <div>
                <h2><?php echo e(lang('title_cmdb')); ?> <?php echo e($company); ?></h2>
                <p class="muted"><?php echo e(lang('signed_in_as')); ?> <?php echo e($currentUser); ?> (<?php echo e(lang('role_' . $role)); ?>)</p>
            </div>
            <div class="dashboard-actions">
                <?php if ($canCreate): ?>
                    <a class="button-link" href="asset_new.php">+ <?php echo e(lang('new_asset')); ?></a>
                <?php endif; ?>
                <a class="button-link" href="export.php?<?php echo e(http_build_query($filterParams)); ?>"><?php echo e(lang('export_btn')); ?></a>
                <?php if (in_array($role, ['admin', 'superadmin'], true)): ?>
                    <a class="button-link" href="settings.php"><?php echo e(lang('set_title')); ?></a>
                    <a class="button-link" href="manage_permissions.php"><?php echo e(lang('manage_perm')); ?></a>
                <?php endif; ?>
                <a class="button-link" href="index.php?action=logout"><?php echo e(lang('logout')); ?></a>
            </div>
        </div>

        <?php echo app_schema_banner(); ?>
        <?php echo app_flash_html(); ?>

        <?php if ($isSuper && $allCompanies !== []): ?>
            <form method="post" class="company-switch">
                <?php echo csrf_field(); ?>
                <label for="switchCompany"><?php echo e(lang('switch_company')); ?></label>
                <select id="switchCompany" name="switch_company" onchange="this.form.submit()">
                    <?php foreach ($allCompanies as $option): ?>
                        <option value="<?php echo e($option); ?>" <?php echo $option === $company ? 'selected' : ''; ?>><?php echo e($option); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <form method="get" class="search-form">
            <label for="searchField"><?php echo e(lang('search_field')); ?></label>
            <select id="searchField" name="search_field">
                <option value=""><?php echo e(lang('search_any')); ?></option>
                <?php foreach ($gridColumns as $column): ?>
                    <?php if (in_array($column, ['source', 'BYOD', 'Status'], true)) { continue; } ?>
                    <option value="<?php echo e($column); ?>" <?php echo $searchField === $column ? 'selected' : ''; ?>><?php echo e(column_label($column)); ?></option>
                <?php endforeach; ?>
                <?php foreach ($gridFields as $def): ?>
                    <option value="custom:<?php echo e($def['field_key']); ?>" <?php echo $searchField === 'custom:' . $def['field_key'] ? 'selected' : ''; ?>>
                        <?php echo e(field_label($def, lang_code())); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="search_text" value="<?php echo e($searchText); ?>" placeholder="<?php echo e(lang('search_text')); ?>">
            <select name="source">
                <option value=""><?php echo e(lang('all_sources')); ?></option>
                <?php foreach ($sourcesList as $source): ?>
                    <option value="<?php echo e($source['slug']); ?>" <?php echo $sourceFilter === $source['slug'] ? 'selected' : ''; ?>>
                        <?php echo e($source['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><?php echo e(lang('search_btn')); ?></button>
            <a class="link-button" href="main.php"><?php echo e(lang('clear')); ?></a>
        </form>

        <form method="post" action="save_rows.php" id="gridForm">
            <?php echo csrf_field(); ?>
            <div class="table-scroll">
                <table class="grid-table">
                    <thead>
                        <tr>
                            <th><?php echo e(lang('col_sn')); ?></th>
                            <?php foreach ($gridColumns as $column): ?>
                                <?php if ($column === 'source'): ?>
                                    <th><?php echo e(lang('source_filter')); ?></th>
                                <?php else: ?>
                                    <th><?php echo sort_link($column, $filterParams, $sort, $dir); ?></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($gridFields as $def): ?>
                                <?php $fieldSort = 'custom:' . $def['field_key']; ?>
                                <th><a href="<?php echo e(grid_url($filterParams, ['sort' => $fieldSort, 'dir' => $sort === $fieldSort && $dir === 'asc' ? 'desc' : 'asc'])); ?>">
                                    <?php echo e(field_label($def, lang_code())); ?>
                                </a></th>
                            <?php endforeach; ?>
                            <th><?php echo e(lang('files_title')); ?></th>
                            <th><?php echo e(lang('actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="<?php echo 3 + count($gridColumns) + count($gridFields); ?>" class="muted"><?php echo e(lang('no_assets')); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $rowId = (int) $row['Id'];
                        $rowCustom = $customValues[$rowId] ?? [];
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="rows[<?php echo $rowId; ?>][Id]" value="<?php echo $rowId; ?>">
                                <a href="asset.php?id=<?php echo $rowId; ?>"><?php echo e(($row['SN'] ?? '') !== '' ? $row['SN'] : '#' . $rowId); ?></a>
                            </td>
                            <?php foreach ($gridColumns as $column): ?>
                                <?php
                                $value = (string) ($row[$column] ?? '');
                                $isEditable = in_array($column, $editableColumns, true);
                                $inputName = 'rows[' . $rowId . '][' . $column . ']';
                                ?>
                                <td>
                                    <?php if ($column === 'source'): ?>
                                        <?php echo source_badge($pdo, (string) ($row['source'] ?? '')); ?>
                                    <?php elseif ($column === 'Status'): ?>
                                        <?php if ($isEditable): ?>
                                            <?php echo asset_field_input('Status', $value); ?>
                                        <?php else: ?>
                                            <?php echo e(lang_status_label($value)); ?>
                                        <?php endif; ?>
                                    <?php elseif ($column === 'BYOD'): ?>
                                        <?php if ($isEditable): ?>
                                            <?php echo asset_field_input('BYOD', $value); ?>
                                        <?php else: ?>
                                            <?php echo e(asset_bool_parse($value) ? lang('true_label') : lang('false_label')); ?>
                                        <?php endif; ?>
                                    <?php elseif ($isEditable): ?>
                                        <?php
                                        $type = asset_column_defs()[$column]['type'] ?? 'text';
                                        if ($type === 'date') {
                                            echo '<input type="date" name="' . e($inputName) . '" value="' . e(substr($value, 0, 10)) . '">';
                                        } elseif ($type === 'textarea') {
                                            echo '<input type="text" name="' . e($inputName) . '" value="' . e($value) . '">';
                                        } else {
                                            echo '<input type="text" name="' . e($inputName) . '" value="' . e($value) . '">';
                                        }
                                        ?>
                                    <?php else: ?>
                                        <?php echo e($value); ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <?php foreach ($gridFields as $def): ?>
                                <td><?php echo e(field_display_value($def, $rowCustom[(string) $def['field_key']] ?? null)); ?></td>
                            <?php endforeach; ?>

                            <td><?php echo (int) ($fileCounts[$rowId] ?? 0); ?></td>
                            <td>
                                <a class="link-button" href="asset.php?id=<?php echo $rowId; ?>"><?php echo e(lang('details')); ?></a>
                                <?php if ($canDelete): ?>
                                    <label class="inline-check">
                                        <input type="checkbox" name="delete_ids[]" value="<?php echo $rowId; ?>">
                                        <?php echo e(lang('delete')); ?>
                                    </label>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="grid-actions">
                <?php if ($editableColumns !== []): ?>
                    <button type="submit" name="action" value="save"><?php echo e(lang('save_changes')); ?></button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                    <button type="submit" name="action" value="delete_selected" class="danger"
                            onclick="return confirm('<?php echo e(lang('delete_confirm')); ?>')"><?php echo e(lang('delete_asset')); ?></button>
                <?php endif; ?>
                <span class="muted">
                    <?php echo e(lang('showing_results')); ?>
                    <?php echo $total === 0 ? 0 : (($page - 1) * $perPage + 1); ?>–<?php echo min($total, $page * $perPage); ?>
                    <?php echo e(lang('of')); ?> <?php echo $total; ?> <?php echo e(lang('records')); ?>
                </span>
            </div>

            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?php echo e(grid_url($filterParams, ['page' => $page - 1])); ?>">&laquo; <?php echo e(lang('previous')); ?></a>
                <?php else: ?>
                    <span>&laquo; <?php echo e(lang('previous')); ?></span>
                <?php endif; ?>
                <span class="current"><?php echo e(lang('page')); ?> <?php echo $page; ?> <?php echo e(lang('of')); ?> <?php echo $totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?php echo e(grid_url($filterParams, ['page' => $page + 1])); ?>"><?php echo e(lang('next')); ?> &raquo;</a>
                <?php else: ?>
                    <span><?php echo e(lang('next')); ?> &raquo;</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <script src="theme.js"></script>
</body>

</html>

