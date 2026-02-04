<?php
// manage_permissions.php
session_start();
require_once '../config.php';

// Security check: Only SuperAdmins allowed
$role = $_SESSION['role'] ?? 'user';
if ($role !== 'superadmin') {
    die('Access Denied: You must be a SuperAdmin to view this page.');
}

// Helper: Escape output
function escape($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

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

$message = '';
$messageType = ''; // 'success' or 'error'

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'update') {
        $email = $_POST['email'] ?? '';
        $newRole = $_POST['role_to_set'] ?? '';
        
        if ($email && in_array($newRole, ['admin', 'superadmin', 'manager'])) {
            // Update user role
            $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE email = :email");
            $success = $stmt->execute([':role' => $newRole, ':email' => $email]);
            
            if ($success && $stmt->rowCount() > 0) {
                $message = "Successfully updated permission for " . escape($email);
                $messageType = 'success';
            } elseif ($success) {
                $message = "User " . escape($email) . " already has that role or does not exist.";
                $messageType = 'info';
            } else {
                $message = "Failed to update permission.";
                $messageType = 'error';
            }
        }
    } elseif ($action === 'remove') {
        $email = $_POST['email'] ?? '';
        // Prevent self-removal if validation needed, but usually SuperAdmin can remove themselves if not careful.
        // Let's just allow it or maybe warn. For now allow.
        
        if ($email === $_SESSION['user_email']) {
             $message = "You cannot remove your own SuperAdmin status from here.";
             $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET role = 'user' WHERE email = :email");
            $success = $stmt->execute([':email' => $email]);
             if ($success) {
                $message = "Removed admin rights from " . escape($email);
                $messageType = 'success';
            }
        }
    }
}

// Fetch Admins and SuperAdmins
$stmt = $pdo->query("SELECT * FROM users WHERE LOWER(TRIM(role)) IN ('admin', 'superadmin', 'manager') ORDER BY role DESC, email ASC");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Users for Dropdown
$stmt = $pdo->query("SELECT email FROM users ORDER BY email ASC");
$allUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Permissions</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 900px;
            margin: 20px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            border-bottom: 2px solid #eee;
            padding-bottom: 1rem;
        }
        .message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        
        .section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        .section h3 { margin-top: 0; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #f1f1f1; }
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: bold;
        }
        .role-superadmin { background: #6f42c1; color: white; }
        .role-admin { background: #28a745; color: white; }
        .role-manager { background: #17a2b8; color: white; }
        
        .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
        }
        .btn-add {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }
        select, input { padding: 8px; border: 1px solid #ced4da; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Permission Management</h2>
            <a href="main.php" class="btn-add" style="background: #6c757d; text-decoration: none;">&laquo; Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo escape($message); ?>
            </div>
        <?php endif; ?>

        <!-- Add New Section -->
        <div class="section">
            <h3>Grant Permissions</h3>
            <p>Select a user to promote to Admin or SuperAdmin status.</p>
            <form method="post" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="action" value="add">
                
                <label for="email">User:</label>
                <select name="email" id="email" required style="min-width: 200px;">
                    <option value="">-- Select User --</option>
                    <?php foreach ($allUsers as $uEmail): ?>
                        <option value="<?php echo escape($uEmail); ?>">
                            <?php echo escape($uEmail); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <label for="role">Role:</label>
                <select name="role_to_set" id="role" required>
                    <option value="manager">Manager</option>
                    <option value="admin">Admin</option>
                    <option value="superadmin">SuperAdmin</option>
                </select>
                
                <button type="submit" class="btn-add">Grant Permission</button>
            </form>
        </div>

        <!-- List Section -->
        <div class="section" style="background: white; border: none; padding: 0;">
            <h3>Current Admins & SuperAdmins</h3>
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Current Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($admins)): ?>
                        <tr><td colspan="4">No admins found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?php echo escape($admin['email']); ?></td>
                                <td><?php echo escape($admin['company']); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo escape($admin['role']); ?>">
                                        <?php echo strtoupper(escape($admin['role'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($admin['email'] === $_SESSION['user_email']): ?>
                                        <span style="color: #6c757d; font-style: italic;">(You)</span>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove admin rights from this user?');">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="email" value="<?php echo escape($admin['email']); ?>">
                                            <button type="submit" class="btn-remove">Remove</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
