<?php
//main.php
session_start();
require_once '../config.php';
require_once '../auth_keycloak.php';
require_once 'lang.php';
lang_init();

if (!isset($_SESSION['user_email'])) {
    header('Location: index.php');
    exit();
}
$role = $_SESSION['role'] ?? 'user';

// Superadmin: Handle company switch
if ($role === 'superadmin') {
    // Fetch all available companies
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $stmt = $pdo->query("SELECT DISTINCT company FROM assets ORDER BY company ASC");
        $allCompanies = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        die("DB Connection failed: " . $e->getMessage());
    }

    // Handle switch action
    if (isset($_POST['switch_company']) && in_array($_POST['switch_company'], $allCompanies)) {
        $_SESSION['company'] = $_POST['switch_company'];
        header("Location: main.php");
        exit();
    }
}

$company = $_SESSION['company'] ?? '';
if ($company === '') {
    if ($role === 'superadmin' && !empty($allCompanies)) {
        // Auto-select first company if none selected
        $company = $allCompanies[0];
        $_SESSION['company'] = $company;
    } else {
        die('No company assigned in session.');
    }
}
$userTableName = 'assets'; // Fixed table name
$role = $_SESSION['role'] ?? 'user';
$currentUserEmail = $_SESSION['user_email'] ?? '';
$perPage = 25;
$page = (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) ? intval($_GET['page']) : 1;
// Sorting
$sort_by  = $_GET['sort_by']  ?? '';
$sort_dir = strtolower($_GET['sort_dir'] ?? 'asc');
$sort_dir = in_array($sort_dir, ['asc','desc'], true) ? $sort_dir : 'asc';
// Columns to fetch from API (Term before UserEmail)
$columns_to_show = [
    'Id','UUID','SN','OS','OSVersion','Hostname','Mobile','Manufacturer',
    'Term','UserEmail','BYOD','Status','Warranty','Asset','PurchaseDate',
    'CypherID','CypherKey'
];
// Columns editable in this grid
$columns_editable = ['UserEmail','Status','Warranty','Asset','PurchaseDate','BYOD'];
// Columns read-only in this grid
$columns_readonly = ['Hostname'];


// Columns hidden in this grid (but still fetched)
$columns_hidden   = ['Id','UUID','CypherID','CypherKey','OSVersion','Mobile'];
// Visible columns in this grid (Term will appear before UserEmail here)
$columns_visible = array_values(array_diff($columns_to_show, $columns_hidden));

if ($role === 'user') {
    $columns_editable = [];
    $columns_readonly = $columns_visible;
}
$fields_param = implode(',', $columns_to_show);
// Status options (Replaced removed)
$status_options = [
    lang('status_in_use'),
    lang('status_stock'),
    lang('status_repair'),
    lang('status_decomm'),
    lang('status_lost')
];
// Search/filter
$search_field = $_GET['search_field'] ?? '';
$search_text  = $_GET['search_text'] ?? '';
$filterParamStr = '';
if ($search_field !== '' && $search_text !== '') {
    $filterParamStr = '&where=(' . rawurlencode($search_field) . ',like,' . rawurlencode('%' . $search_text . '%') . ')';
}
// Helper: DB Connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

// Build Query
$whereClauses = [];
$params = [];

if ($search_field !== '' && $search_text !== '') {
    $whereClauses[] = "`$search_field` LIKE :searchText";
    $params[':searchText'] = '%' . $search_text . '%';
}

if ($role === 'user') {
    $whereClauses[] = "`UserEmail` = :currentUserEmail";
    $params[':currentUserEmail'] = $currentUserEmail;
}

// Always filter by company
$whereClauses[] = "`company` = :company";
$params[':company'] = $company;

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Count Total — use JOIN when sorting by Term
if ($sort_by === 'Term') {
    $countSql = "SELECT COUNT(*) FROM `$userTableName` a LEFT JOIN device_files df ON df.device_id = a.Id AND df.device_table = 'assets' $whereSql";
} else {
    $countSql = "SELECT COUNT(*) FROM `$userTableName` $whereSql";
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

// Sorting
$orderSql = '';
$sortingByTerm = false;
if ($sort_by === 'Term') {
    $sortingByTerm = true;
} elseif ($sort_by !== '' && in_array($sort_by, $columns_to_show, true)) {
    $orderSql = "ORDER BY `$sort_by` " . ($sort_dir === 'desc' ? 'DESC' : 'ASC');
} else {
    // Default sort
    $orderSql = "ORDER BY Id DESC";
}

// Pagination
$offset = ($page - 1) * $perPage;
$limitSql = "LIMIT :offset, :limit";

// Fetch Rows — use LEFT JOIN for Term sort
if ($sortingByTerm) {
    $sql = "SELECT a.*, COUNT(df.id) AS file_count 
            FROM `$userTableName` a 
            LEFT JOIN device_files df ON df.device_id = a.Id AND df.device_table = 'assets'
            $whereSql 
            GROUP BY a.Id 
            ORDER BY file_count " . ($sort_dir === 'desc' ? 'DESC' : 'ASC') . " 
            $limitSql";
} else {
    $sql = "SELECT * FROM `$userTableName` $whereSql $orderSql $limitSql";
}
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$companyUsers = [];
if ($role === 'admin' || $role === 'manager' || $role === 'superadmin') {
    // Fetch all users for this company to populate the dropdown
    // We need to query the 'users' table.
    $uStmt = $pdo->prepare("SELECT email FROM users WHERE company = :comp ORDER BY email ASC");
    $uStmt->execute([':comp' => $company]);
    $companyUsers = $uStmt->fetchAll(PDO::FETCH_COLUMN);
}

function escape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function count_files_in_term($row, $pdo, $tableName) {
    $id = $row['Id'] ?? 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM device_files WHERE device_id = :id AND device_table = 'assets'");
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn();
}

// Preserve query params for pagination links
$queryParams = $_GET;
unset($queryParams['page']);
// Also preserve lang param
$queryFilterStr = http_build_query($queryParams);
$paginationSuffix = $queryFilterStr ? '&' . $queryFilterStr : '';
$startRecord = $totalRows > 0 ? (($page - 1) * $perPage) + 1 : 0;
$endRecord   = ($page * $perPage) > $totalRows ? $totalRows : ($page * $perPage);

// Helper to build sorted header links and arrow
function sort_link($col, $current_by, $current_dir) {
    $params = $_GET;
    $params['sort_by'] = $col;
    $params['sort_dir'] = ($current_by === $col && strtolower($current_dir) === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query($params);
    return '?' . $qs;
}
function sort_arrow($col, $current_by, $current_dir) {
    if ($col !== $current_by) return '';
    return strtolower($current_dir) === 'asc' ? '▲' : '▼';
}
?>
<!DOCTYPE html>
<html lang="<?php echo escape($_SESSION['lang'] ?? 'pt_BR'); ?>">
<head>
    <title>CMDB <?php echo lang('title_cmdb'); ?> <?php echo escape($company); ?></title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="page-wrapper">
<!-- Top-Right Toolbar: Language Flags + Theme Toggle -->
<div class="top-toolbar">
    <?php echo lang_flag_buttons('main.php'); ?>
    <?php echo theme_toggle_button(); ?>
</div>

<h2><?php echo lang('title_cmdb'); ?> <?php echo escape($company); ?></h2>
<p><?php echo lang('signed_in_as'); ?> <?php echo escape($_SESSION['user_email']); ?> (<?php echo escape($role); ?>)</p>

<?php if ($role === 'superadmin'): ?>
<div class="superadmin-controls">
    <form method="post" style="display:inline;">
        <label for="switch_company"><strong><?php echo lang('switch_company'); ?></strong></label>
        <select name="switch_company" id="switch_company" onchange="this.form.submit()">
            <?php foreach ($allCompanies as $comp): ?>
                <option value="<?php echo escape($comp); ?>" <?php echo ($company === $comp) ? 'selected' : ''; ?>>
                    <?php echo escape($comp); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Switch</button></noscript>
    </form>
</div>
<?php endif; ?>
<div class="search-container" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <form method="get" action="main.php" style="flex-grow:1; min-width: 300px; max-width: 600px;">
        <label for="search_field"><?php echo lang('search_field'); ?>:</label>
        <select name="search_field" id="search_field" required>
            <option value="" disabled <?php echo $search_field === '' ? 'selected' : ''; ?>><?php echo lang('select_field'); ?></option>
            <?php foreach ($columns_visible as $col): ?>
                <option value="<?php echo escape($col); ?>" <?php echo ($search_field === $col) ? 'selected' : ''; ?>>
                    <?php echo escape($col); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="search_text"><?php echo lang('search_text'); ?>:</label>
        <input type="text" id="search_text" name="search_text" value="<?php echo escape($search_text); ?>" required>
        <button type="submit"><?php echo lang('search_btn'); ?></button>
        <a href="main.php" style="margin-left:10px;"><?php echo lang('clear'); ?></a>
    </form>
    <form method="get" action="export.php" style="margin: 0;">
        <?php if ($search_field !== ''): ?>
            <input type="hidden" name="search_field" value="<?php echo escape($search_field); ?>">
        <?php endif; ?>
        <?php if ($search_text !== ''): ?>
            <input type="hidden" name="search_text" value="<?php echo escape($search_text); ?>">
        <?php endif; ?>
        <?php if ($sort_by !== ''): ?>
            <input type="hidden" name="sort_by" value="<?php echo escape($sort_by); ?>">
        <?php endif; ?>
        <?php if ($sort_dir !== ''): ?>
            <input type="hidden" name="sort_dir" value="<?php echo escape($sort_dir); ?>">
        <?php endif; ?>
        <button type="submit" class="export-btn"><?php echo lang('export_btn'); ?></button>
    </form>
    <?php if ($role === 'superadmin'): ?>
        <form method="get" action="manage_permissions.php" style="margin: 0;">
            <button type="submit" class="export-btn" style="background-color: var(--btn-info) !important;"><?php echo lang('manage_perm'); ?></button>
        </form>
    <?php endif; ?>
    <div class="header-links">
        <form method="post" action="logout.php" style="display:inline;">
            <button type="submit"><?php echo lang('logout'); ?></button>
        </form>
    </div>
</div>
<div class="record-info">
    <?php echo lang('showing'); ?> <?php echo (int)$startRecord; ?> <?php echo lang('to'); ?> <?php echo (int)$endRecord; ?> <?php echo lang('of'); ?> <?php echo (int)$totalRows; ?> <?php echo lang('records'); ?>
</div>
<form method="post" action="save_rows.php" id="editForm">
    <table>
        <thead>
            <tr>
                <?php foreach ($columns_visible as $col): ?>
                    <?php $arrow = sort_arrow($col, $sort_by, $sort_dir); ?>
                    <th>
                        <a href="<?php echo escape(sort_link($col, $sort_by, $sort_dir)); ?>">
                            <?php echo escape($col); ?>
                            <?php if ($arrow): ?><span class="arrow"><?php echo $arrow; ?></span><?php endif; ?>
                        </a>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $index => $row): ?>
                <?php

                $row = (array)$row;
                $rowId = $row['Id'] ?? '';
                ?>
                <tr data-row-index="<?php echo (int)$index; ?>">
                    <input type="hidden" name="rows[<?php echo (int)$index; ?>][Id]" value="<?php echo escape($rowId); ?>">
                    <input type="hidden" name="rows[<?php echo (int)$index; ?>][UUID]" value="<?php echo escape($row['UUID'] ?? ''); ?>">
                    <?php foreach ($columns_visible as $col): ?>
                        <td>
                            <?php
                            $value = $row[$col] ?? '';
                            if ($col === 'SN') {
                                $label = ($value !== '') ? escape($value) : lang('no_files');
                                echo '<a href="asset.php?id=' . urlencode($rowId) . '">' . $label . '</a>';
                            } elseif ($col === 'Term') {
                                $fileCount = count_files_in_term($row, $pdo, $userTableName);
                                echo '<a href="asset.php?id=' . urlencode($rowId) . '">';
                                echo $fileCount > 0 ? ($fileCount . ' ' . ($fileCount > 1 ? lang('file_plural') : lang('file_singular'))) : lang('no_files');
                                echo '</a>';
                            } elseif (in_array($col, $columns_editable, true)) {
                                if ($col === 'BYOD') {
                                    $v = strtolower(trim((string)$value));
                                    $isTrue = in_array($v, ['true','1','yes','on'], true);
                                    ?>
                                    <select name="rows[<?php echo (int)$index; ?>][BYOD]" class="track-change">
                                        <option value="true"  <?php echo $isTrue ? 'selected' : ''; ?>><?php echo lang('true_label'); ?></option>
                                        <option value="false" <?php echo !$isTrue ? 'selected' : ''; ?>><?php echo lang('false_label'); ?></option>
                                    </select>
                                    <?php
                                } elseif ($col === 'Status') { ?>
                                    <select name="rows[<?php echo (int)$index; ?>][Status]" class="track-change">
                                        <option value="" <?php echo ($value === '' || is_null($value)) ? 'selected' : ''; ?>></option>
                                        <?php foreach ($status_options as $option): ?>
                                            <option value="<?php echo escape($option); ?>" <?php echo ($value === $option) ? 'selected' : ''; ?>>
                                                <?php echo escape($option); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php
                                } elseif ($col === 'UserEmail') { ?>
                                    <select name="rows[<?php echo (int)$index; ?>][UserEmail]" class="track-change">
                                        <option value="" <?php echo ($value === '') ? 'selected' : ''; ?>></option>
                                        <?php foreach ($companyUsers as $uEmail): ?>
                                            <option value="<?php echo escape($uEmail); ?>" <?php echo ($value === $uEmail) ? 'selected' : ''; ?>>
                                                <?php echo escape($uEmail); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php
                                } elseif ($col === 'Warranty' || $col === 'PurchaseDate') { ?>
                                    <input
                                        type="date"
                                        name="rows[<?php echo (int)$index; ?>][<?php echo escape($col); ?>]"
                                        value="<?php echo escape($value); ?>"
                                        class="track-change"
                                    >
                                <?php
                                } elseif ($col === 'Asset') { ?>
                                    <input
                                        type="text"
                                        name="rows[<?php echo (int)$index; ?>][Asset]"
                                        value="<?php echo escape($value); ?>"
                                        class="track-change"
                                    >
                                <?php
                                } else { ?>
                                    <input
                                        type="text"
                                        name="rows[<?php echo (int)$index; ?>][<?php echo escape($col); ?>]"
                                        value="<?php echo escape($value); ?>"
                                        class="track-change"
                                    >
                                <?php
                                }
                            } elseif ($col === 'BYOD') {
                                $v = strtolower(trim((string)$value));
                                $isTrue = in_array($v, ['true','1','yes','on'], true);
                                echo $isTrue ? lang('true_label') : lang('false_label');
                            } elseif (in_array($col, $columns_readonly, true)) {
                                echo escape($value);
                            } else {
                                echo escape($value);
                            }
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <br>
    <button type="submit"><?php echo lang('save_changes'); ?></button>
</form>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?php echo ($page - 1) . $paginationSuffix; ?>">&laquo; <?php echo lang('previous'); ?></a>
    <?php else: ?>
        <span>&laquo; <?php echo lang('previous'); ?></span>
    <?php endif; ?>
    <span class="current"><?php echo lang('page'); ?> <?php echo (int)$page; ?> <?php echo lang('of'); ?> <?php echo (int)max(1, $totalPages); ?></span>
    <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo ($page + 1) . $paginationSuffix; ?>"><?php echo lang('next'); ?> &raquo;</a>
    <?php else: ?>
        <span><?php echo lang('next'); ?> &raquo;</span>
    <?php endif; ?>
</div>
<script>
// Preserve native validation and only disable untouched rows.
document.getElementById('editForm').addEventListener('submit', function(e) {
    const form = this;
    Array.from(form.querySelectorAll('tbody tr')).forEach(function(row) {
        let hasChanged = false;
        Array.from(row.querySelectorAll('[name^="rows["]')).forEach(function(input) {
            if (input.type === "hidden") return;
            if (input.type === "checkbox" || input.type === "radio") {
                if (input.checked !== input.defaultChecked) hasChanged = true;
            } else if (input.tagName === "SELECT") {
                const selectedIndex = input.selectedIndex;
                let hasDefaultSelected = false;
                Array.from(input.options).forEach(function(opt, idx) {
                    if (opt.defaultSelected && idx === selectedIndex) {
                        hasDefaultSelected = true;
                    }
                });
                if (!hasDefaultSelected) {
                    const anyDefault = Array.from(input.options).some(opt => opt.defaultSelected);
                    if (!anyDefault) {
                        if (input.value !== '') hasChanged = true;
                    } else {
                        hasChanged = true;
                    }
                }
            } else {
                if (input.value !== input.defaultValue) hasChanged = true;
            }
        });
        if (!hasChanged) {
            Array.from(row.querySelectorAll('input,select,textarea')).forEach(function(input) {
                input.disabled = true;
            });
        }
    });
    if (!form.reportValidity()) {
        e.preventDefault();
        return false;
    }
});
</script>
</div><!-- .page-wrapper -->
<script src="theme.js"></script>
</body>
</html>
