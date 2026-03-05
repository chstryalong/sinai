<?php
// ============================================================
//  Logout — New Sinai MDI Hospital
//  Destroys the admin session and redirects to the login page.
// ============================================================

session_start();
session_unset();
session_destroy();

header('Location: login.php');
exit;