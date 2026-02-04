<?php
// save_row.php
session_start();
require_once '../config.php';
require_once '../s3_client.php';
if (!isset($_SESSION['user_email'])) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['row'])) {
    http_response_code(405);
    exit('Method Not Allowed');
}

$userEmail = $_SESSION['user_email'];
$company = $_SESSION['company'] ?? '';
if ($company === '') {
    http_response_code(400);
    exit('No company assigned in session.');
}
$userTableName = 'assets';

$row = $_POST['row'];
$rowId = $row['Id'] ?? null;
$uuidForLog = $row['UUID'] ?? '';

if (!$rowId) {
    http_response_code(400);
    exit('Missing Id');
}

// ----------------- Helpers (same style as save_rows.php) -----------------
// Helpers
// DB Connection
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

// GET current row (before) to compare
function get_row($pdo, $table, $pk, $company)
{
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE Id = :id AND company = :company");
    $stmt->execute([':id' => $pk, ':company' => $company]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// PATCH only certain fields
function update_row($pdo, $table, $pk, $data, $company)
{
    if (empty($data))
        return ['ok' => true];
    $set = [];
    $params = [':id' => $pk, ':company' => $company];
    foreach ($data as $col => $val) {
        $set[] = "`$col` = :$col";
        $params[":$col"] = $val;
    }
    $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE Id = :id AND company = :company";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return ['ok' => true];
    } catch (PDOException $e) {
        return ['error' => $e->getMessage()];
    }
}

// INSERT a single audit log
function insert_log($pdo, $email, $field, $newValue, $uuid, $dateTimeIsoUtc)
{
    // Skip logging for now or implement local log table
    return ['ok' => true];
}

// Normalize payload similarly to your grid flow
function normalize_update_payload(array $input): array
{
    $updateData = $input;
    // Remove PK/immutable from update set
    unset($updateData['Id']);

    // Email: optional, but if filled must be valid; blank -> null
    if (array_key_exists('UserEmail', $updateData)) {
        $emailVal = trim((string) ($updateData['UserEmail'] ?? ''));
        if ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
            $updateData['**invalid_email**'] = $emailVal;
        } else {
            $updateData['UserEmail'] = ($emailVal === '') ? null : $emailVal;
        }
    }

    // BYOD boolean normalization
    if (array_key_exists('BYOD', $updateData)) {
        $val = strtolower(trim((string) $updateData['BYOD']));
        $updateData['BYOD'] = in_array($val, ['true', '1', 'on', 'yes'], true) ? 1 : 0;
    }

    // Optional date/text fields: empty string -> null
    foreach (['Warranty', 'PurchaseDate', 'Asset'] as $field) {
        if (array_key_exists($field, $updateData)) {
            $v = $updateData[$field];
            if ($v === '' || $v === null) {
                $updateData[$field] = null;
            } elseif (in_array($field, ['Warranty', 'PurchaseDate'], true)) {
                // Normalize to YYYY-MM-DD if parseable; else null
                $ts = strtotime((string) $v);
                $updateData[$field] = ($ts !== false) ? date('Y-m-d', $ts) : null;
            }
        }
    }

    return $updateData;
}

// Robust equality with normalization (empty string ~ null, boolean-ish strings, numeric strings)
function values_equal($a, $b): bool
{
    $boolMap = ['true' => true, 'false' => false, '1' => true, '0' => false, 'yes' => true, 'no' => false];
    if (is_string($a) && array_key_exists(strtolower($a), $boolMap))
        $a = $boolMap[strtolower($a)];
    if (is_string($b) && array_key_exists(strtolower($b), $boolMap))
        $b = $boolMap[strtolower($b)];

    if (is_string($a) && is_numeric($a))
        $a = $a + 0;
    if (is_string($b) && is_numeric($b))
        $b = $b + 0;

    // Treat '' and null as equal
    if ($a === '' && $b === null)
        return true;
    if ($b === '' && $a === null)
        return true;

    if ((is_array($a) || is_object($a)) || (is_array($b) || is_object($b))) {
        return json_encode($a, JSON_UNESCAPED_SLASHES) === json_encode($b, JSON_UNESCAPED_SLASHES);
    }
    return $a === $b;
}

// ----------------- Build and apply update -----------------

// Compute which fields are allowed to be edited on this page
$editable = ['UserEmail', 'Status', 'Warranty', 'Asset', 'PurchaseDate', 'BYOD'];
$role = $_SESSION['role'] ?? 'user';

if ($role !== 'user') {
    // Manager, Admin, Superadmin can edit Notes
    if (in_array($role, ['manager', 'admin', 'superadmin'])) {
        $editable[] = 'Notes';
    }
    // Admin, Superadmin can edit Cypher fields
    if (in_array($role, ['admin', 'superadmin'])) {
        $editable[] = 'CypherID';
        $editable[] = 'CypherKey';
    }
}

// Start from posted row; keep only editable keys
$incoming = [];
foreach ($editable as $k) {
    if (array_key_exists($k, $row)) {
        $incoming[$k] = $row[$k];
    }
}

// Normalize incoming values (email/boolean/dates/empties)
$updateData = normalize_update_payload($incoming);

// Abort on invalid email (mirror save_rows.php behavior)
if (isset($updateData['**invalid_email**'])) {
    header("Location: asset.php?id=" . urlencode((string) $rowId));
    exit();
}

// If nothing submitted, return
if (empty($updateData)) {
    header("Location: asset.php?id=" . urlencode((string) $rowId));
    exit();
}

// Fetch current state
$before = get_row($pdo, $userTableName, $rowId, $company);
if (empty($before)) {
    // On fetch error, just return to the page (or render an error if preferred)
    header("Location: asset.php?id=" . urlencode((string) $rowId));
    exit();
}

// Determine actual changes vs DB
$changedPayload = [];
$changedFields = [];
foreach ($updateData as $fieldName => $newVal) {
    $oldVal = $before[$fieldName] ?? null;
    if (!values_equal($oldVal, $newVal)) {
        $changedPayload[$fieldName] = $newVal;
        $changedFields[$fieldName] = $newVal;
    }
}

// If no changes, redirect back
if (empty($changedPayload)) {
    header("Location: asset.php?id=" . urlencode((string) $rowId));
    exit();
}

// Apply PATCH with only changed fields
$result = update_row($pdo, $userTableName, $rowId, $changedPayload, $company);
if (isset($result['error'])) {
    // If update failed, just go back; you can improve UX with a query param or flash
    header("Location: asset.php?id=" . urlencode((string) $rowId));
    exit();
}

// Write one log per changed field (skip sensitive values if needed)
$nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
foreach ($changedFields as $fieldName => $newValue) {
    if (in_array($fieldName, ['CypherKey'], true)) {
        // Skip logging sensitive secrets if desired
        continue;
    }
    insert_log($pdo, $userEmail, $fieldName, $newValue, $uuidForLog, $nowUtc);
}

// Done
header("Location: asset.php?id=" . urlencode((string) $rowId));
exit();
