<?php
// index.php
session_start();
require_once '../config.php';
require_once '../auth_keycloak.php';

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
                $userTable = $user['user_table'] ?? '';
                // Role support with backward compatibility
                $role = $user['role'] ?? (($user['is_admin'] ?? false) ? 'admin' : 'manager');

                $_SESSION['user_email'] = $email;
                $_SESSION['user_table'] = $userTable;
                $_SESSION['role'] = $role;
                $_SESSION['is_admin'] = ($role === 'admin');

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
// For better UX, we can show a "Login with SSO" button or auto-redirect.
// Let's show a simple page with a button to avoid infinite loops if configuration is wrong.
$loginUrl = $keycloak->getLoginUrl();

?>
<!DOCTYPE html>
<html>

<head>
    <title>CMDB - Login</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="style.css">
    <style>
        .login-container {
            text-align: center;
            margin-top: 100px;
        }

        .sso-btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #4CAF50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
        }

        .sso-btn:hover {
            background-color: #45a049;
        }

        .error-msg {
            color: red;
            margin-bottom: 20px;
        }
    </style>
</head>

<body class="login-page">
    <div class="login-container">
        <h2>CMDB - Login</h2>
        <?php if ($message): ?>
            <p class="error-msg"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <p>Please sign in using your company account.</p>
        <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="sso-btn">Sign in with SSO</a>
    </div>
</body>

</html>