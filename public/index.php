<?php
// index.php
session_start();
require_once '../config.php';
require_once '../auth_keycloak.php';
require_once 'lang.php';
lang_init();

$debug = false;
$message = '';

// Initialize Keycloak Helper
$keycloak = new KeycloakAuth();

// Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $keycloak->getLogoutUrl());
    exit();
}

// Handle Keycloak Callback
if (isset($_GET['code'])) {
    $tokenData = $keycloak->getToken($_GET['code']);

    if ($tokenData && isset($tokenData['access_token'])) {
        $userInfo = $keycloak->getUserInfo($tokenData['access_token']);

        if ($userInfo && isset($userInfo['email'])) {
            $email = $userInfo['email'];

            // Verify user in MariaDB
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

            $user = $keycloak->verifyUser($email, $pdo);

            if ($user) {
                $company = $user['company'] ?? '';
                $role = $user['role'] ?? 'user';

                $_SESSION['user_email'] = $email;
                $_SESSION['company'] = $company;
                $_SESSION['role'] = $role;

                header('Location: main.php');
                exit();
            } else {
                $message = 'Access Denied: User not found in authorized list.';
            }
        } else {
            $message = 'Failed to retrieve user information from Keycloak.';
        }
    } else {
        $message = 'Failed to authenticate with Keycloak.';
    }
}

// If already logged in, redirect to main
if (isset($_SESSION['user_email'])) {
    header('Location: main.php');
    exit();
}

// If no code and not logged in, show login page or redirect
$loginUrl = $keycloak->getLoginUrl();

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($_SESSION['lang'] ?? 'pt_BR', ENT_QUOTES, 'UTF-8'); ?>">

<head>
    <title><?php echo lang('login_title'); ?></title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="style.css">
</head>

<body class="login-page">
    <!-- Top-Right Toolbar: Language Flags + Theme Toggle -->
    <div class="top-toolbar">
        <?php echo lang_flag_buttons('index.php'); ?>
        <?php echo theme_toggle_button(); ?>
    </div>

    <div class="login-container">
        <h2><?php echo lang('login_title'); ?></h2>
        <?php if ($message): ?>
            <p class="error-msg"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <p><?php echo lang('login_prompt'); ?></p>
        <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="sso-btn"><?php echo lang('sso_btn'); ?></a>
    </div>

    <script src="theme.js"></script>
</body>

</html>
