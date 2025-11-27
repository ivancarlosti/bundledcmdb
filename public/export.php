<?php
session_start();
require_once '../config.php';

// Only allow logged-in users to export
if (!isset($_SESSION['user_email'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied';
    exit();
}

$userTableName = $_SESSION['user_table'] ?? '';
if ($userTableName === '') {
    die('No user table assigned in session.');
}

// Check admin status from session
$isAdmin = $_SESSION['is_admin'] ?? false;

// Base columns matching your main.php + asset.php (all visible for export)
$base_columns = [
    'Id',
    'UUID',
    'SN',
    'OS',
    'OSVersion',
    'Hostname',
    'Mobile',
    'Manufacturer',
    'Term',
    'LastSeen',
    'UserEmail',
    'BYOD',
    'Status',
    'Warranty',
    'Asset',
    'PurchaseDate'
];

// Admin-only columns
$admin_columns = ['CypherID', 'CypherKey'];

// Read-only additional columns included for everyone (some admin-only?), from asset.php insertion logic
$additional_read_only = [
    'CPUs',
    'HDs',
    'HDsTypes',
    'HDsSpacesGB',
    'NetworkAdapters',
    'MACAddresses',
    'ESETComponents',
    'PrimaryLocalIP',
    'PrimaryRemoteIP'
];

// Build columns for export respecting admin rights
$columns_to_export = $base_columns;
if ($isAdmin) {
    // Insert admin columns after 'Asset' (somewhere in middle, here after base)
    $columns_to_export = array_merge($columns_to_export, $admin_columns);
}
$columns_to_export = array_merge($columns_to_export, $additional_read_only);

$fields_param = implode(',', $columns_to_export);

// Get filter and sort parameters from GET to match the table view
$search_field = $_GET['search_field'] ?? '';
$search_text = $_GET['search_text'] ?? '';
$sort_by = $_GET['sort_by'] ?? '';
$sort_dir = strtolower($_GET['sort_dir'] ?? 'asc');
$sort_dir = in_array($sort_dir, ['asc', 'desc'], true) ? $sort_dir : 'asc';

$filterParamStr = '';
if ($search_field !== '' && $search_text !== '') {
    $filterParamStr = '&where=(' . rawurlencode($search_field) . ',like,' . rawurlencode('%' . $search_text . '%') . ')';
}

$sortParamStr = '';
if ($sort_by !== '' && in_array($sort_by, $columns_to_export, true)) {
    $prefix = ($sort_dir === 'desc') ? '-' : '+';
    $sortParamStr = '&sort=' . rawurlencode($prefix . $sort_by);
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

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Sorting
$orderSql = '';
if ($sort_by !== '' && in_array($sort_by, $columns_to_export, true)) {
    $orderSql = "ORDER BY `$sort_by` " . ($sort_dir === 'desc' ? 'DESC' : 'ASC');
} else {
    $orderSql = "ORDER BY Id DESC";
}

// Fetch All Rows
$sql = "SELECT * FROM `$userTableName` $whereSql $orderSql";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Send headers to prompt download as CSV file
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="cmdb_export_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Write UTF-8 BOM for Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

// Write CSV header row
fputcsv($output, $columns_to_export);

// Write all rows
foreach ($allRows as $row) {
    $exportRow = [];
    foreach ($columns_to_export as $colName) {
        $val = $row[$colName] ?? '';
        if (is_array($val)) {
            $val = implode('; ', $val); // Flatten arrays if any
        }
        $exportRow[] = $val;
    }
    fputcsv($output, $exportRow);
}

fclose($output);
exit();
