<?php
// asset.php
session_start();
require_once '../config.php';

// Optional: enable during debugging only (remove or comment in production)
# ini_set('display_errors', 1);
# ini_set('display_startup_errors', 1);
# error_reporting(E_ALL);

// --- Security check ---
if (!isset($_SESSION['user_email'])) {
    header('Location: index.php');
    exit();
}

// --- Table from session ---
$table = $_SESSION['user_table'] ?? '';
if ($table === '') {
    http_response_code(400);
    exit('No user table assigned in session.');
}

// --- Row id ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit('Invalid record ID');
}
$recordId = (int) $_GET['id'];

// --- Admin check from session ---
$isAdmin = $_SESSION['is_admin'] ?? false;

// --- Helper functions ---
// --- DB Connection ---
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

require_once '../s3_client.php';
$s3 = new S3Client();

// --- Helper functions (Local DB & S3) ---
function get_row($pdo, $table, $id)
{
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE Id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function update_row($pdo, $table, $id, $data)
{
    if (empty($data))
        return;
    $set = [];
    $params = [':id' => $id];
    foreach ($data as $col => $val) {
        $set[] = "`$col` = :$col";
        $params[":$col"] = $val;
    }
    $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE Id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function get_files($pdo, $table, $id)
{
    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE device_id = :id AND device_table = :table");
    $stmt->execute([':id' => $id, ':table' => $table]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function upload_file($pdo, $s3, $table, $id, $file)
{
    $fileName = $file['name'];
    $tmpName = $file['tmp_name'];
    $mime = $file['type'];
    $size = $file['size'];

    // Generate unique key
    $key = "uploads/$table/$id/" . uniqid() . '_' . $fileName;

    $result = $s3->uploadFile($tmpName, $key, $mime);

    if ($result['success']) {
        $stmt = $pdo->prepare("INSERT INTO device_files (device_id, device_table, file_path, file_name, mime_type, size) VALUES (:did, :dtab, :path, :name, :mime, :size)");
        $stmt->execute([
            ':did' => $id,
            ':dtab' => $table,
            ':path' => $key,
            ':name' => $fileName,
            ':mime' => $mime,
            ':size' => $size
        ]);
        return ['success' => true];
    }
    return $result;
}

function delete_file($pdo, $s3, $fileId)
{
    $stmt = $pdo->prepare("SELECT * FROM device_files WHERE id = :id");
    $stmt->execute([':id' => $fileId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($file) {
        if ($s3->deleteFile($file['file_path'])) {
            $del = $pdo->prepare("DELETE FROM device_files WHERE id = :id");
            $del->execute([':id' => $fileId]);
            return true;
        }
    }
    return false;
}

// --- Handle file actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['new_file'])) {
        $file = $_FILES['new_file'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            $result = upload_file($pdo, $s3, $table, $recordId, $file);
            if (!$result['success']) {
                die("S3 Upload Failed. Code: " . $result['code'] . " | Message: " . htmlspecialchars($result['message']));
            }
        } else {
            die("File upload error code: " . $file['error']);
        }
        header("Location: asset.php?id=" . urlencode((string) $recordId));
        exit();
    }
    if (isset($_POST['delete_file'])) {
        delete_file($pdo, $s3, $_POST['delete_file']);
        header("Location: asset.php?id=" . urlencode((string) $recordId));
        exit();
    }
}

// --- Row data ---
$row = get_row($pdo, $table, $recordId);
$files = get_files($pdo, $table, $recordId);
function escape($v)
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Serial number for title/h2; fall back to record id if empty
$serial = trim((string) ($row['SN'] ?? ''));
$serialForTitle = $serial !== '' ? $serial : (string) $recordId;

// --- Columns config ---
// Reordered: Term first, then LastSeen, then UserEmail, then rest.
$columns = [
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
if ($isAdmin) {
    $columns[] = 'CypherID';
    $columns[] = 'CypherKey';
}

// Insert the new read-only, view-only columns immediately after CypherKey (or append if not present)
$newReadOnlyCols = [
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
$idx = array_search('CypherKey', $columns, true);
if ($idx !== false) {
    array_splice($columns, $idx + 1, 0, $newReadOnlyCols);
} else {
    // Non-admin or CypherKey absent: still show these new fields
    $columns = array_merge($columns, $newReadOnlyCols);
}

$hidden = ['Id'];
$editable = ['UserEmail', 'Status', 'Warranty', 'Asset', 'PurchaseDate', 'BYOD'];
if ($isAdmin) {
    $editable[] = 'CypherID';
    $editable[] = 'CypherKey';
}
// Mark the requested fields as read-only (view-only)
$readonly = array_merge(
    ['Hostname'],
    [
        'CPUs',
        'HDs',
        'HDsTypes',
        'HDsSpacesGB',
        'NetworkAdapters',
        'MACAddresses',
        'ESETComponents',
        'PrimaryLocalIP',
        'PrimaryRemoteIP'
    ]
);

$display = array_filter($columns, fn($c) => !in_array($c, $hidden, true));

$status_options = ["In Use", "In Stock", "In Repair", "Replaced", "Decommissioned", "Lost or Stolen"];
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>CMDB Row Details (SN #<?php echo escape($serialForTitle); ?>)</title>
    <link rel="stylesheet" href="style.css">
</head>

<body class="asset-page">
    <a href="main.php" class="back-link">&larr; Back to CMDB Table: <?php echo escape($table); ?></a>
    <h2>CMDB Row Details (SN #<?php echo escape($serialForTitle); ?>)</h2>

    <form method="post" action="save_row.php" class="form-section">
        <input type="hidden" name="row[Id]" value="<?php echo escape($row['Id'] ?? $recordId); ?>">
        <input type="hidden" name="row[UUID]" value="<?php echo escape($row['UUID'] ?? ''); ?>">

        <table class="details-table">
            <tbody>
                <?php foreach ($display as $col): ?>
                    <tr>
                        <th><?php echo escape($col); ?></th>
                        <td>
                            <?php
                            $value = $row[$col] ?? '';

                            // Mobile: show "True"/"False" explicitly
                            if ($col === 'Mobile') {
                                $norm = is_bool($value) ? $value : strtolower(trim((string) $value));
                                $isMobile = is_bool($norm) ? $norm : in_array($norm, ['true', '1', 'yes', 'on'], true);
                                echo $isMobile ? 'True' : 'False';

                            } elseif ($col === 'Term') {
                                $count = is_array($row['Term'] ?? null) ? count($row['Term']) : 0;
                                echo $count ? "$count file" . ($count > 1 ? 's' : '') : 'No files';

                            } elseif ($col === 'SN') {
                                echo escape($value);

                            } elseif (in_array($col, $editable, true)) {

                                if ($col === 'BYOD') {
                                    $v = strtolower(trim((string) $value));
                                    $isTrue = in_array($v, ['true', '1', 'yes', 'on'], true);
                                    ?>
                                    <select name="row[BYOD]">
                                        <option value="true" <?php echo $isTrue ? 'selected' : ''; ?>>True</option>
                                        <option value="false" <?php echo !$isTrue ? 'selected' : ''; ?>>False</option>
                                    </select>
                                <?php } elseif ($col === 'Status') { ?>
                                    <select name="row[Status]">
                                        <option value="" <?php echo ($value === '' || is_null($value)) ? 'selected' : ''; ?>></option>
                                        <?php foreach ($status_options as $opt): ?>
                                            <option value="<?php echo escape($opt); ?>" <?php echo ($value === $opt) ? 'selected' : ''; ?>>
                                                <?php echo escape($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php } elseif ($col === 'UserEmail') { ?>
                                    <input type="email" name="row[UserEmail]" value="<?php echo escape($value); ?>"
                                        pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"
                                        oninput="this.setCustomValidity('')"
                                        title="Please enter a valid email address or leave empty" class="track-change">
                                <?php } elseif ($col === 'Warranty' || $col === 'PurchaseDate') { ?>
                                    <input type="date" name="row[<?php echo escape($col); ?>]"
                                        value="<?php echo escape($value); ?>">
                                <?php } else { ?>
                                    <input type="text" name="row[<?php echo escape($col); ?>]"
                                        value="<?php echo escape($value); ?>">
                                <?php }

                            } elseif (in_array($col, $readonly, true)) {
                                echo is_array($value) ? escape(json_encode($value)) : escape($value);

                            } else {
                                echo is_array($value) ? escape(json_encode($value)) : escape($value);
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="save-section">
            <button type="submit">Save Changes</button>
        </div>
    </form>

    <!-- Files Upload -->
    <div class="upload-box">
        <h3>Upload New File</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="new_file" required>
            <button type="submit" class="upload">Upload</button>
        </form>
    </div>

    <!-- Existing Files -->
    <div class="existing-files">
        <h3>Existing Files</h3>
        <div class="file-list">
            <?php if (is_array($files) && count($files)):
                foreach ($files as $f):
                    $url = $s3->getPresignedUrl($f['file_path']);
                    $title = $f['file_name'];
                    $delete = $f['id'];
                    ?>
                    <div class="file-item">
                        <a href="<?php echo escape($url); ?>" target="_blank"><?php echo escape($title); ?></a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="delete_file" value="<?php echo escape($delete); ?>">
                            <button type="submit" onclick="return confirm('Delete this file?');">Delete</button>
                        </form>
                    </div>
                <?php endforeach; else: ?>
                <div>No files found.</div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>