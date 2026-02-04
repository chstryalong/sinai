<?php
session_start();
include("../config/db.php");

$error = "";

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = md5($_POST['password']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE username=? AND password=?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $_SESSION['admin'] = $username;
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid username or password";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta http-equiv="refresh" content="600">
    <title>New Sinai MDI Hospital - Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0052CC;
            --primary-600: #1e88e5;
            --accent: #ffc107;
            --bg-gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 50%, var(--accent) 100%);
            --surface: rgba(255,255,255,0.92);
            --shadow: 0 10px 40px rgba(0,0,0,0.18);
            --radius: 15px;
        }

        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow: hidden;
            color: var(--text, #052744);
        }

        /* entrance */
        @keyframes popIn { from { transform: translateY(12px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .login-container { animation: popIn 420ms cubic-bezier(.2,.8,.2,1) both; }

        .login-container {
            background: var(--surface);
            border-radius: calc(var(--radius) - 4px);
            box-shadow: 0 12px 40px rgba(3,32,71,0.14);
            overflow: hidden;
            width: 100%;
            max-width: 380px; /* narrower */
            position: relative;
            z-index: 1;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            padding: 8px;
            margin: 0 16px;
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            color: white;
            padding: 26px 20px; /* tighter */
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .login-header h1 {
            font-size: 22px; /* smaller, cleaner */
            font-weight: 700;
            margin: 6px 0 4px;
        }

        .login-header p {
            font-size: 13px;
            opacity: 0.95;
            margin: 0;
        }

        .hospital-logo {
            margin-bottom: 12px;
            display: inline-flex;
            gap: 12px;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
        }
        .hospital-logo img {
            max-height: 64px;
            width: auto;
            display: block;
            border-radius: 8px;
            padding: 6px;
            background: rgba(255,255,255,0.94);
            box-shadow: 0 8px 28px rgba(3,32,71,0.18);
        }
        @media (max-width: 480px) {
            .hospital-logo img { max-height: 50px; }
            .hospital-logo { gap: 8px; }
        }

        /* full-screen background using uploaded image */
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image: url('../display/assets/logo.png');
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center center;
            background-attachment: fixed;
            opacity: 0.95;
            pointer-events: none;
            z-index: 0;
        }

        .login-body {
            padding: 20px 18px; /* compact */
        }

        .form-label {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 13px;
        }

        .form-control { border-radius: 10px; padding: 10px 12px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 8px 20px rgba(30,136,229,0.08); }

        .btn-login { padding: 10px; font-size: 15px; border-radius: 12px; box-shadow: 0 10px 30px rgba(3,32,71,0.12); }
        .btn-login:hover { transform: translateY(-3px); box-shadow: 0 16px 44px rgba(3,32,71,0.14); }

        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #0052CC;
            box-shadow: 0 0 0 0.2rem rgba(0, 82, 204, 0.25);
        }

        .btn-login {
            background: linear-gradient(135deg, var(--accent) 0%, #ffb300 100%);
            color: var(--primary);
            border: none;
            padding: 12px;
            font-weight: 700;
            border-radius: 8px;
            width: 100%;
            transition: all 0.24s ease;
            margin-top: 10px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.12);
        }

        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(0,0,0,0.14); }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 0.12rem rgba(0,82,204,0.12); }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 193, 7, 0.3);
            color: #0052CC;
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .alert-error {
            border-left: 4px solid #dc3545;
        }

        .login-footer {
            background-color: #f5f5f5;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #666;
            border-top: 1px solid #e0e0e0;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
            }

            .login-header {
                padding: 30px 20px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .login-body {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="hospital-logo">
                <img src="../display/assets/logo2.png" alt="New Sinai MDI Hospital Logo" />
            </div>
            <h1>New Sinai MDI Hospital</h1>
            <p>Admin Dashboard Login</p>
        </div>

        <div class="login-body">
            <form method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Enter your username" required>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" name="login" class="btn btn-login">Login</button>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-error mt-3 mb-0" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <div class="login-footer">
            <p class="mb-0">&copy; 2026 New Sinai MDI Hospital. All rights reserved.</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
