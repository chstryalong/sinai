<?php
/*
|--------------------------------------------------------------------------
| New Sinai MDI Hospital - Web Installer
| Run once then DELETE this file for security
|--------------------------------------------------------------------------
*/

$host = "localhost";
$user = "root";        // change if needed
$pass = "";            // change if needed
$dbname = "hospital_display";

echo "<h2>New Sinai MDI Hospital - Setup</h2>";

try {

    // Connect to MySQL server
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✔ Connected to MySQL<br>";

    // Create database
    $pdo->exec("
        CREATE DATABASE IF NOT EXISTS `$dbname`
        DEFAULT CHARACTER SET utf8mb4
        COLLATE utf8mb4_general_ci
    ");
    echo "✔ Database created or already exists<br>";

    // Use database
    $pdo->exec("USE `$dbname`");

    /*
    |--------------------------------------------------------------------------
    | Create Tables
    |--------------------------------------------------------------------------
    */

    // Doctors
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS doctors (
            id INT(11) NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            department VARCHAR(100) DEFAULT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'No Medical',
            resume_date DATE DEFAULT NULL,
            appt_start TIME DEFAULT NULL,
            appt_end TIME DEFAULT NULL,
            remarks TEXT DEFAULT NULL,
            is_tentative TINYINT(1) DEFAULT 0,
            updated_at TIMESTAMP NOT NULL 
                DEFAULT CURRENT_TIMESTAMP 
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT(11) NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
            is_superadmin TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Display Settings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS display_settings (
            id INT(11) NOT NULL AUTO_INCREMENT,
            scroll_speed INT(11) DEFAULT 30,
            pause_at_top INT(11) DEFAULT 3000,
            pause_at_bottom INT(11) DEFAULT 3000,
            updated_at TIMESTAMP NOT NULL 
                DEFAULT CURRENT_TIMESTAMP 
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Announcements
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id INT(11) NOT NULL AUTO_INCREMENT,
            text TEXT DEFAULT NULL,
            active TINYINT(1) DEFAULT 0,
            font_size INT(11) DEFAULT 28,
            speed INT(11) DEFAULT 18,
            bg_color VARCHAR(32) DEFAULT '#fff8e1',
            text_color VARCHAR(32) DEFAULT '#052744',
            updated_at TIMESTAMP NOT NULL 
                DEFAULT CURRENT_TIMESTAMP 
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "✔ Tables created<br>";

    /*
    |--------------------------------------------------------------------------
    | Insert Default Data (Only if Empty)
    |--------------------------------------------------------------------------
    */

    // Insert default admin if not exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO users (username, password, is_superadmin)
            VALUES ('admin', MD5('p@ssw0rd'), 1)
        ");
    }

    // Insert default scroll settings
    $stmt = $pdo->query("SELECT COUNT(*) FROM display_settings");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO display_settings 
            (scroll_speed, pause_at_top, pause_at_bottom)
            VALUES (30, 3000, 3000)
        ");
        echo "✔ Default display settings added<br>";
    }

    // Insert default announcement
    $stmt = $pdo->query("SELECT COUNT(*) FROM announcements");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO announcements 
            (text, active, font_size, speed, bg_color, text_color)
            VALUES 
            ('Welcome to New Sinai MDI Hospital', 
             0, 28, 18, '#fff8e1', '#1d68aa')
        ");
        echo "✔ Default announcement added<br>";
    }

    echo "<br><h3 style='color:green'>🎉 Installation Completed Successfully!</h3>";
    echo "<p><strong>Login:</strong> admin / admin123</p>";
    echo "<p style='color:red'><strong>IMPORTANT:</strong> Delete setup.php now for security.</p>";

} catch (PDOException $e) {
    die("<h3 style='color:red'>Setup Failed:</h3> " . $e->getMessage());
}
?>