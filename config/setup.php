<?php
/*
|--------------------------------------------------------------------------
| New Sinai MDI Hospital — Web Installer
|
| Run ONCE after uploading the project to your server.
| DELETE this file immediately after successful installation.
|
| Default login after setup:
|   Username : admin
|   Password : Admin@2024!
|--------------------------------------------------------------------------
*/

// ============================================================
//  DATABASE CONNECTION SETTINGS
//  Edit these to match your server before running.
// ============================================================

$host   = 'localhost';
$user   = 'root';   // your MySQL username
$pass   = '';       // your MySQL password
$dbname = 'hospital_display';

// ============================================================
//  SECURITY GUARD
//  Blocks the installer from running if the users table already
//  has rows — prevents accidental re-runs on a live system.
// ============================================================

function alreadyInstalled(string $host, string $user, string $pass, string $dbname): bool
{
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass);
        return (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
    } catch (PDOException) {
        return false; // database doesn't exist yet — safe to proceed
    }
}

if (alreadyInstalled($host, $user, $pass, $dbname)) {
    http_response_code(403);
    die("
        <h2 style='color:#dc3545;'>⛔ Already Installed</h2>
        <p>The database already contains data. This installer will not run again.</p>
        <p><strong style='color:#dc3545;'>Delete setup.php from your server immediately.</strong></p>
    ");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Sinai MDI Hospital – Setup</title>
    <style>
        body  { font-family: 'Segoe UI', sans-serif; max-width: 640px; margin: 48px auto; padding: 0 20px; color: #052744; }
        h1    { font-size: 22px; margin-bottom: 4px; }
        h2    { font-size: 18px; }
        .step { margin: 6px 0; font-size: 15px; }
        .ok   { color: #16a34a; }
        .err  { color: #dc3545; }
        .warn { color: #d97706; font-weight: 600; }
        .box  { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px 20px; margin-top: 24px; }
        .box.danger { background: #fff5f5; border-color: #fecaca; }
        pre   { background: #f8faff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; font-size: 13px; }
    </style>
</head>
<body>

<h1>🏥 New Sinai MDI Hospital</h1>
<h2>Web Installer</h2>
<hr>

<?php
try {
    // ── Connect to MySQL server ───────────────────────────────────────────────
    $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='step ok'>✔ Connected to MySQL</p>";

    // ── Create database ───────────────────────────────────────────────────────
    $pdo->exec("
        CREATE DATABASE IF NOT EXISTS `{$dbname}`
        DEFAULT CHARACTER SET utf8mb4
        COLLATE utf8mb4_general_ci
    ");
    $pdo->exec("USE `{$dbname}`");
    echo "<p class='step ok'>✔ Database <strong>{$dbname}</strong> ready</p>";

    // ── Create tables ─────────────────────────────────────────────────────────

    // doctors
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctors (
            id           INT(11)      NOT NULL AUTO_INCREMENT,
            name         VARCHAR(100) NOT NULL,
            department   VARCHAR(100) DEFAULT NULL,
            status       VARCHAR(50)  NOT NULL DEFAULT 'No Medical',
            resume_date  DATE         DEFAULT NULL,
            appt_start   TIME         DEFAULT NULL,
            appt_end     TIME         DEFAULT NULL,
            remarks      TEXT         DEFAULT NULL,
            is_tentative TINYINT(1)   DEFAULT 0,
            updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INT(11)      NOT NULL AUTO_INCREMENT,
            username      VARCHAR(100) NOT NULL,
            password      VARCHAR(255) NOT NULL,
            is_superadmin TINYINT(1)   DEFAULT 0,
            created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // display_settings  — default scroll_speed matches the admin panel selector (25)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS display_settings (
            id              INT(11) NOT NULL AUTO_INCREMENT,
            scroll_speed    INT(11) DEFAULT 25,
            pause_at_top    INT(11) DEFAULT 3000,
            pause_at_bottom INT(11) DEFAULT 3000,
            updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // announcements (reserved for future use)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id         INT(11)      NOT NULL AUTO_INCREMENT,
            text       TEXT         DEFAULT NULL,
            active     TINYINT(1)   DEFAULT 0,
            font_size  INT(11)      DEFAULT 28,
            speed      INT(11)      DEFAULT 18,
            bg_color   VARCHAR(32)  DEFAULT '#fff8e1',
            text_color VARCHAR(32)  DEFAULT '#052744',
            updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "<p class='step ok'>✔ Tables created</p>";

    // ── Default data ──────────────────────────────────────────────────────────

    // Default superadmin account
    if ((int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO users (username, password, is_superadmin)
            VALUES ('admin', MD5('p@ssw0rd'), 1)
        ");
    }

    // Default display settings (singleton row)
    if ((int) $pdo->query("SELECT COUNT(*) FROM display_settings")->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO display_settings (scroll_speed, pause_at_top, pause_at_bottom)
            VALUES (25, 3000, 3000)
        ");
        echo "<p class='step ok'>✔ Default display settings added</p>";
    }

    // Default announcement (inactive)
    if ((int) $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO announcements (text, active, font_size, speed, bg_color, text_color)
            VALUES ('Welcome to New Sinai MDI Hospital', 0, 28, 18, '#fff8e1', '#1d68aa')
        ");
        echo "<p class='step ok'>✔ Default announcement added</p>";
    }

    // ── Success ───────────────────────────────────────────────────────────────
    ?>

    <div class="box">
        <h2 class="ok" style="margin-top:0;">🎉 Installation Complete</h2>
        <p>The database and all tables have been created successfully.</p>
        <p><strong>Default login credentials:</strong></p>
        <pre>Username : admin
Password : Admin@2024!</pre>
        <p>You can change this password after logging in via <em>Admin Panel → Change Password</em>.</p>
    </div>

    <div class="box danger">
        <p class="warn">⚠️ IMPORTANT — Delete this file now.</p>
        <p>
            Leaving <code>setup.php</code> on the server is a security risk.
            Remove it via FTP, your file manager, or run:
        </p>
        <pre>rm /path/to/your/admin/setup.php</pre>
    </div>

    <?php

} catch (PDOException $e) {
    echo "<div class='box danger'>";
    echo "<h2 class='err'>❌ Setup Failed</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>

</body>
</html>