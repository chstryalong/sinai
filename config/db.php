<?php
// ============================================================
//  Database Connection — New Sinai MDI Hospital
//  Edit the values below to match your server settings.
// ============================================================

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'hospital_display';
$port = 3306;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');