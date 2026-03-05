<?php
// ============================================================
//  Admin Login — New Sinai MDI Hospital
// ============================================================

session_start();
require_once '../config/db.php';

$error = '';

if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = md5($_POST['password']  ?? '');

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password = ?");
    $stmt->bind_param('ss', $username, $password);
    $stmt->execute();

    if ($stmt->get_result()->num_rows === 1) {
        session_regenerate_id(true); // prevent session fixation
        $_SESSION['admin'] = $username;
        header('Location: index.php');
        exit;
    }

    $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Prevent browser from caching the login page -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <!-- Auto-refresh after 10 minutes of idle -->
    <meta http-equiv="refresh" content="600">
    <title>New Sinai MDI Hospital – Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary:     #0052CC;
            --primary-mid: #1e88e5;
            --accent:      #ffc107;
        }

        /* ── Layout ── */
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-mid) 50%, var(--accent) 100%);
            color: #052744;
            position: relative;
        }

        /* Full-screen background watermark */
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: url('../display/assets/logo.png');
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            opacity: 0.95;
            pointer-events: none;
            z-index: 0;
        }

        /* ── Card ── */
        @keyframes popIn {
            from { transform: translateY(12px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        .login-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 380px;
            margin: 0 16px;
            background: rgba(255,255,255,0.92);
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(3,32,71,0.18);
            overflow: hidden;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            animation: popIn 420ms cubic-bezier(.2,.8,.2,1) both;
        }

        /* ── Header ── */
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-mid));
            color: white;
            padding: 26px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .hospital-logo img {
            max-height: 64px;
            width: auto;
            border-radius: 8px;
            padding: 6px;
            background: rgba(255,255,255,0.94);
            box-shadow: 0 8px 28px rgba(3,32,71,0.18);
            margin-bottom: 12px;
        }

        .card-header-custom h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 4px;
        }

        .card-header-custom p {
            font-size: 13px;
            opacity: 0.9;
            margin: 0;
        }

        /* ── Body ── */
        .card-body-custom { padding: 24px 20px; }

        .form-label {
            color: var(--primary);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 6px;
        }

        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,82,204,0.12);
            outline: none;
        }

        /* ── Password wrapper ── */
        .pw-wrap { position: relative; }
        .pw-wrap .form-control { padding-right: 42px; }
        .pw-eye {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #aaa;
            cursor: pointer;
            font-size: 17px;
            line-height: 1;
            padding: 0;
            transition: color .15s;
        }
        .pw-eye:hover { color: var(--primary); }

        /* ── Login button ── */
        .btn-login {
            width: 100%;
            margin-top: 10px;
            padding: 11px;
            font-size: 15px;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent), #ffb300);
            color: var(--primary);
            box-shadow: 0 6px 18px rgba(0,0,0,0.12);
            transition: transform .2s, box-shadow .2s;
            cursor: pointer;
        }
        .btn-login:hover  { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(255,193,7,0.35); color: var(--primary); }
        .btn-login:active { transform: translateY(0); }

        /* ── Footer ── */
        .card-footer-custom {
            background: #f5f5f5;
            border-top: 1px solid #e0e0e0;
            padding: 14px 20px;
            text-align: center;
            font-size: 12px;
            color: #888;
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .hospital-logo img        { max-height: 50px; }
            .card-header-custom h1    { font-size: 19px; }
            .card-body-custom         { padding: 20px 16px; }
        }
    </style>
</head>
<body>

<div class="login-card">

    <div class="card-header-custom">
        <div class="hospital-logo">
            <img src="../display/assets/logo2.png" alt="New Sinai MDI Hospital Logo">
        </div>
        <h1>New Sinai MDI Hospital</h1>
        <p>Admin Dashboard Login</p>
    </div>

    <div class="card-body-custom">
        <form method="POST" novalidate>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username"
                       placeholder="Enter your username" required autocomplete="username">
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="pw-wrap">
                    <input type="password" class="form-control" id="password" name="password"
                           placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="pw-eye" onclick="togglePassword()" tabindex="-1" id="pw-toggle">
                        <i class="bi bi-eye" id="pw-icon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" name="login" class="btn-login">Login</button>

            <?php if ($error): ?>
            <div class="alert alert-danger mt-3 mb-0" style="border-left:4px solid #dc3545; font-size:13px;" role="alert">
                <i class="bi bi-exclamation-circle-fill me-1"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

        </form>
    </div>

    <div class="card-footer-custom">
        &copy; 2026 New Sinai MDI Hospital. All rights reserved.
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePassword() {
    const input = document.getElementById('password');
    const icon  = document.getElementById('pw-icon');
    if (input.type === 'password') {
        input.type    = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type    = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>