<?php
//main.php
session_start();
require_once '../config.php';
require_once '../auth_keycloak.php';
if (!isset($_SESSION['user_email'])) {
    header('Location: index.php');
    exit();
}
$company = $_SESSION['company'] ?? '';
if ($company === '') {
    die('No company assigned in session.');
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
// Hardcoded Status options
$status_options = ["In Use","In Stock","In Repair","Replaced","Decommissioned","Lost or Stolen"];
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

// Count Total
$countSql = "SELECT COUNT(*) FROM `$userTableName` $whereSql";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalRows = $stmt->fetchColumn();
$totalPages = $perPage > 0 ? (int)ceil($totalRows / $perPage) : 1;

// Sorting
$orderSql = '';
if ($sort_by !== '' && in_array($sort_by, $columns_to_show, true)) {
    $orderSql = "ORDER BY `$sort_by` " . ($sort_dir === 'desc' ? 'DESC' : 'ASC');
} else {
    // Default sort
    $orderSql = "ORDER BY Id DESC"; 
}

// Pagination
$offset = ($page - 1) * $perPage;
$limitSql = "LIMIT :offset, :limit";

// Fetch Rows
$sql = "SELECT * FROM `$userTableName` $whereSql $orderSql $limitSql";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$companyUsers = [];
if ($role === 'admin' || $role === 'manager') {
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
<html>
<head>
    <title>CMDB Company: <?php echo escape($company); ?></title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<h2>CMDB Company: <?php echo escape($company); ?></h2>
<p>Signed in as: <?php echo escape($_SESSION['user_email']); ?></p>
<div class="search-container" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
    <form method="get" action="main.php" style="flex-grow:1; min-width: 300px; max-width: 600px;">
        <label for="search_field">Search Field:</label>
        <select name="search_field" id="search_field" required>
            <option value="" disabled <?php echo $search_field === '' ? 'selected' : ''; ?>>Select field</option>
            <?php foreach ($columns_visible as $col): ?>
                <option value="<?php echo escape($col); ?>" <?php echo ($search_field === $col) ? 'selected' : ''; ?>>
                    <?php echo escape($col); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="search_text">Search Text:</label>
        <input type="text" id="search_text" name="search_text" value="<?php echo escape($search_text); ?>" required>
        <button type="submit">Search</button>
        <a href="main.php" style="margin-left:10px;">Clear</a>
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
        <button type="submit" class="export-btn">Export to Excel</button>
    </form>
    <div class="header-links">
        <form method="post" action="logout.php" style="display:inline;">
            <button type="submit">Logout</button>
        </form>
    </div>
</div>
<div class="record-info">
    Showing <?php echo (int)$startRecord; ?> to <?php echo (int)$endRecord; ?> of <?php echo (int)$totalRows; ?> records
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
                                $label = ($value !== '') ? escape($value) : 'Open files';
                                echo '<a href="asset.php?id=' . urlencode($rowId) . '">' . $label . '</a>';
                            } elseif ($col === 'Term') {
                                $fileCount = count_files_in_term($row, $pdo, $userTableName);
                                echo '<a href="asset.php?id=' . urlencode($rowId) . '">';
                                echo $fileCount > 0 ? ($fileCount . ' file' . ($fileCount > 1 ? 's' : '')) : 'No files';
                                echo '</a>';
                            } elseif (in_array($col, $columns_editable, true)) {
                                if ($col === 'BYOD') {
                                    $v = strtolower(trim((string)$value));
                                    $isTrue = in_array($v, ['true','1','yes','on'], true);
                                    ?>
                                    <select name="rows[<?php echo (int)$index; ?>][BYOD]" class="track-change">
                                        <option value="true"  <?php echo $isTrue ? 'selected' : ''; ?>>True</option>
                                        <option value="false" <?php echo !$isTrue ? 'selected' : ''; ?>>False</option>
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
                                echo $isTrue ? 'True' : 'False';
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
    <button type="submit">Save Changes</button>
</form>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?page=<?php echo ($page - 1) . $paginationSuffix; ?>">&laquo; Previous</a>
    <?php else: ?>
        <span>&laquo; Previous</span>
    <?php endif; ?>
    <span class="current">Page <?php echo (int)$page; ?> of <?php echo (int)max(1, $totalPages); ?></span>
    <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo ($page + 1) . $paginationSuffix; ?>">Next &raquo;</a>
    <?php else: ?>
        <span>Next &raquo;</span>
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
</body>
</html>
