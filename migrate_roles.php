<?php
// migrate_roles.php

// Manually define constants to bypass config.php/vendor autoload issue
// Assuming running from host where DB is exposed on localhost
$dbHost = '127.0.0.1';
$dbName = 'YOURDBNAME';
$dbUser = 'YOURDBUSERNAME';
$dbPass = 'YOURDBPASSWORD';

// Try to read from docker/.env if possible, but for now let's try these defaults
// or parse the .env file manually.
$envFile = __DIR__ . '/docker/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === 'DB_NAME')
                $dbName = $value;
            if ($key === 'DB_USERNAME')
                $dbUser = $value;
            if ($key === 'DB_PASSWORD')
                $dbPass = $value;
            // DB_SERVER in .env is host.docker.internal, which we probably want to override to 127.0.0.1 for local script execution
        }
    }
}

try {
    echo "Connecting to DB at $dbHost...\n";
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Checking if 'role' column exists...\n";

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleExists = $stmt->fetch();

    if (!$roleExists) {
        echo "Adding 'role' column to 'users' table...\n";
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
        echo "Column 'role' added.\n";
    } else {
        echo "Column 'role' already exists.\n";
    }

    echo "Migrating existing users...\n";

    // Update admins
    $stmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE is_admin = 1");
    $stmt->execute();
    echo "Updated " . $stmt->rowCount() . " admins.\n";

    // Update non-admins to managers
    $stmt = $pdo->prepare("UPDATE users SET role = 'manager' WHERE is_admin = 0");
    $stmt->execute();
    echo "Updated " . $stmt->rowCount() . " non-admins to managers.\n";

    echo "Migration complete.\n";

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage() . "\n");
}
