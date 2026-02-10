<?php
$host = "localhost";
$user = "root"; 
$pass = "";
$db   = "hospital_display";
$port = 3307;

$conn = new mysqli("localhost", "root", "", "hospital_display", 3307);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
