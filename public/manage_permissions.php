<?php
// manage_permissions.php
session_start();
require_once '../config.php';
require_once 'lang.php';
lang_init();

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
<html lang="<?php echo escape($_SESSION['lang'] ?? 'pt_BR'); ?>">
<head>
    <title><?php echo lang('perm_title'); ?></title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <!-- Top-Right Toolbar: Language Flags + Theme Toggle -->
    <div class="top-toolbar">
        <?php echo lang_flag_buttons('manage_permissions.php'); ?>
        <?php echo theme_toggle_button(); ?>
    </div>

    <div class="container">
        <div class="header">
            <h2><?php echo lang('perm_title'); ?></h2>
            <a href="main.php" class="btn-add" style="background: var(--btn-secondary); text-decoration: none;">&laquo; <?php echo lang('perm_back'); ?></a>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo escape($message); ?>
            </div>
        <?php endif; ?>

        <!-- Add New Section -->
        <div class="section">
            <h3><?php echo lang('perm_grant'); ?></h3>
            <p><?php echo lang('perm_promote'); ?></p>
            <form method="post" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="action" value="add">
                
                <label for="email"><?php echo lang('perm_user_label'); ?></label>
                <select name="email" id="email" required style="min-width: 200px;">
                    <option value=""><?php echo lang('perm_select_user'); ?></option>
                    <?php foreach ($allUsers as $uEmail): ?>
                        <option value="<?php echo escape($uEmail); ?>">
                            <?php echo escape($uEmail); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <label for="role"><?php echo lang('perm_role_label'); ?></label>
                <select name="role_to_set" id="role" required>
                    <option value="manager"><?php echo lang('role_manager'); ?></option>
                    <option value="admin"><?php echo lang('role_admin'); ?></option>
                    <option value="superadmin"><?php echo lang('role_superadmin'); ?></option>
                </select>
                
                <button type="submit" class="btn-add"><?php echo lang('perm_btn_grant'); ?></button>
            </form>
        </div>

        <!-- List Section -->
        <div class="section" style="background: var(--container-bg); border: none; padding: 0;">
            <h3><?php echo lang('perm_current'); ?></h3>
            <table>
                <thead>
                    <tr>
                        <th><?php echo lang('perm_email'); ?></th>
                        <th><?php echo lang('perm_company'); ?></th>
                        <th><?php echo lang('perm_role'); ?></th>
                        <th><?php echo lang('perm_actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($admins)): ?>
                        <tr><td colspan="4"><?php echo lang('perm_no_admins'); ?></td></tr>
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
                                        <span style="color: var(--text-secondary); font-style: italic;"><?php echo lang('perm_you'); ?></span>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo lang('perm_confirm_remove'); ?>');">
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="email" value="<?php echo escape($admin['email']); ?>">
                                            <button type="submit" class="btn-remove"><?php echo lang('perm_remove'); ?></button>
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
    <script src="theme.js"></script>
</body>
</html>
