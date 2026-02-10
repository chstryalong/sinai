<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include("../config/db.php");

// Normalize legacy statuses to new labels: Available -> On Schedule, Not Available -> No Medical
$conn->query("UPDATE doctors SET status='On Schedule' WHERE status = 'Available'");
$conn->query("UPDATE doctors SET status='No Medical' WHERE status = 'Not Available'");

/* ADD / UPDATE */
if (isset($_POST['save'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $dept = $_POST['department'];
    $status = $_POST['status'];
    $resume = $_POST['resume_date'] ?? null;
    $appt_start = $_POST['appt_start'] ?? null;
    $appt_end = $_POST['appt_end'] ?? null;
    $remarks = $_POST['remarks'] ?? null;

    if ($resume === '') $resume = null;
    if ($appt_start === '') $appt_start = null;
    if ($appt_end === '') $appt_end = null;
    if ($remarks === '') $remarks = null;

    // Clear fields that don't apply to selected status
    if ($status !== 'On Schedule') {
        $appt_start = null; $appt_end = null;
    }
    if ($status !== 'On Leave') {
        $resume = null;
    }

    if ($id == "") {
        $stmt = $conn->prepare(
            "INSERT INTO doctors (name, department, status, resume_date, appt_start, appt_end, remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssssss", $name, $dept, $status, $resume, $appt_start, $appt_end, $remarks);
    } else {
        $stmt = $conn->prepare(
            "UPDATE doctors SET name=?, department=?, status=?, resume_date=?, appt_start=?, appt_end=?, remarks=? WHERE id=?"
        );
        $stmt->bind_param("sssssssi", $name, $dept, $status, $resume, $appt_start, $appt_end, $remarks, $id);
    }
    $stmt->execute();
    header("Location: index.php");
    exit;
} 

/* DELETE */
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

/* DELETE ALL */
if (isset($_POST['delete_all'])) {
    $conn->query("DELETE FROM doctors");
    header("Location: index.php");
    exit;
}

/* DELETE SELECTED */
if (isset($_POST['delete_selected']) && isset($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
    foreach ($ids as $id) {
        $id = intval($id);
        $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

/* EDIT */
$edit = null;
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
}

/* FETCH ALL */
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';

$query = "SELECT * FROM doctors WHERE 1=1";

if ($search) {
    $search_term = "%$search%";
    $query .= " AND (name LIKE ? OR department LIKE ?)";
}

$valid_sorts = ['name', 'department', 'status'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'name';
}

if ($order !== 'ASC' && $order !== 'DESC') {
    $order = 'ASC';
}

$query .= " ORDER BY $sort $order";

if ($search) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

/* DISPLAY SETTINGS TABLE & HANDLING */
$conn->query("CREATE TABLE IF NOT EXISTS display_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scroll_speed INT DEFAULT 25,
    pause_at_top INT DEFAULT 3000,
    pause_at_bottom INT DEFAULT 3000,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Initialize with default values if empty
$settings_check = $conn->query("SELECT COUNT(*) as count FROM display_settings")->fetch_assoc();
if ($settings_check['count'] == 0) {
    $conn->query("INSERT INTO display_settings (scroll_speed, pause_at_top, pause_at_bottom) VALUES (25, 3000, 3000)");
}

// Ensure remarks column exists in doctors table
$col = $conn->query("SHOW COLUMNS FROM doctors LIKE 'remarks'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE doctors ADD COLUMN remarks TEXT NULL");
}

// Ensure doctors table has fields for appointment times
$col = $conn->query("SHOW COLUMNS FROM doctors LIKE 'appt_start'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE doctors ADD COLUMN appt_start TIME NULL");
}
$col = $conn->query("SHOW COLUMNS FROM doctors LIKE 'appt_end'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE doctors ADD COLUMN appt_end TIME NULL");
}

if (isset($_POST['save_display_settings'])) {
    $scroll_speed = intval($_POST['scroll_speed'] ?? 25);
    $pause_top = intval($_POST['pause_at_top'] ?? 3000);
    $pause_bottom = intval($_POST['pause_at_bottom'] ?? 3000);
    
    // Ensure values are within reasonable ranges
    $scroll_speed = max(5, min(100, $scroll_speed));
    $pause_top = max(1000, min(10000, $pause_top));
    $pause_bottom = max(1000, min(10000, $pause_bottom));
    
    $conn->query("UPDATE display_settings SET scroll_speed=$scroll_speed, pause_at_top=$pause_top, pause_at_bottom=$pause_bottom WHERE id=1");
    header("Location: index.php");
    exit;
}

$display_settings = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Sinai MDI Hospital - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0052CC;
            --primary-600: #1e88e5;
            --accent: #ffc107;
            --success: #28a745;
            --danger: #dc3545;
            --muted: #f1f5f9;
            --bg: linear-gradient(180deg,#f5f7fb 0%, #f8fafc 100%);
            --surface: rgba(255,255,255,0.96);
            --radius: 10px;
            --shadow-1: 0 2px 8px rgba(3,32,71,0.06);
            --shadow-2: 0 8px 30px rgba(3,32,71,0.08);
            --text: #052744;
            --muted-text: rgba(5,39,68,0.6);
            --transition: 240ms cubic-bezier(.2,.8,.2,1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            font-family: 'Inter', 'Segoe UI', sans-serif;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            line-height: 1.6;
        }

        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            box-shadow: var(--shadow-1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar .container-fluid {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .nav-logo-left {
            height: 48px;
            width: auto;
            border-radius: 8px;
            padding: 4px;
            background: rgba(255,255,255,0.15);
            transition: transform var(--transition);
        }

        .nav-logo-left:hover {
            transform: translateY(-2px) scale(1.05);
        }

        .navbar-brand {
            color: white;
            font-weight: 700;
            font-size: 20px;
            text-decoration: none;
            letter-spacing: -0.02em;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-right .text-white {
            font-size: 14px;
            opacity: 0.95;
        }

        .btn-outline-light {
            border: 2px solid rgba(255,255,255,0.8);
            color: white;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all var(--transition);
        }

        .btn-outline-light:hover {
            background: white;
            color: var(--primary);
            transform: translateY(-2px);
        }

        .container-fluid.mt-4 {
            max-width: 1400px;
            margin: 2rem auto !important;
            padding: 0 2rem;
        }

        .section-title {
            color: var(--primary);
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            font-size: 36px;
        }

        .form-section, .table-section {
            background: white;
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow-2);
            margin-bottom: 32px;
            border-top: 4px solid var(--primary);
        }

        .form-label {
            color: var(--primary);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control, .form-select, textarea.form-control {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            transition: all var(--transition);
            font-size: 14px;
        }

        .form-control:focus, .form-select:focus, textarea.form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,82,204,0.1);
            outline: none;
        }

        .btn-save {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            font-weight: 700;
            border-radius: 8px;
            transition: all var(--transition);
            width: 100%;
            margin-top: 16px;
            font-size: 16px;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,82,204,0.3);
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .table thead th {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            color: white;
            font-weight: 700;
            padding: 16px;
            border: none;
            text-align: left;
            font-size: 14px;
            white-space: nowrap;
        }

        .table thead th:first-child {
            border-top-left-radius: 8px;
        }

        .table thead th:last-child {
            border-top-right-radius: 8px;
        }

        .table tbody tr {
            background: white;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            transition: all var(--transition);
        }

        .table tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px rgba(0,0,0,0.08);
        }

        .table tbody td {
            padding: 16px;
            border: none;
            vertical-align: middle;
            font-size: 14px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 12px;
        }

        .status-badge::before {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-nomedical {
            color: #6c757d;
            background: rgba(108,117,125,0.1);
        }

        .status-onschedule {
            color: var(--primary-600);
            background: rgba(30,136,229,0.1);
        }

        .status-onleave {
            color: var(--danger);
            background: rgba(220,53,69,0.1);
        }

        .btn-action {
            padding: 6px 14px;
            margin: 2px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            transition: all var(--transition);
            font-weight: 600;
        }

        .btn-edit {
            background: var(--primary);
            color: white;
        }

        .btn-edit:hover {
            background: var(--primary-600);
            color: white;
            transform: translateY(-2px);
        }

        .btn-delete {
            background: var(--danger);
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
            color: white;
            transform: translateY(-2px);
        }

        /* Display Settings Card */
        .settings-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid var(--primary);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .setting-item {
            display: flex;
            flex-direction: column;
        }

        .setting-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-top: 8px;
        }

        .setting-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--muted-text);
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar .container-fluid {
                flex-direction: column;
                gap: 12px;
                padding: 0 1rem;
            }

            .nav-left, .nav-right {
                width: 100%;
                justify-content: center;
            }

            .container-fluid.mt-4 {
                padding: 0 1rem;
            }

            .form-section, .table-section {
                padding: 20px;
            }

            .section-title {
                font-size: 24px;
            }

            .settings-grid {
                grid-template-columns: 1fr;
            }

            .table thead th,
            .table tbody td {
                font-size: 12px;
                padding: 12px 8px;
            }
        }

        /* Collapsible sections */
        .collapsible-section {
            margin-bottom: 24px;
        }

        .collapsible-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 700;
            transition: all var(--transition);
        }

        .collapsible-header:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,82,204,0.3);
        }

        .collapsible-header i {
            transition: transform var(--transition);
        }

        .collapsible-header.active i {
            transform: rotate(180deg);
        }

        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .collapsible-content.active {
            max-height: 2000px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container-fluid">
            <div class="nav-left">
                <a href="../display/index.php" target="_blank" title="Open Display">
                    <img src="../display/assets/logo2.png" alt="Logo" class="nav-logo-left" />
                </a>
                <span class="navbar-brand">New Sinai MDI Hospital</span>
            </div>

            <div class="nav-right">
                <span class="text-white">Welcome, <strong><?= htmlspecialchars($_SESSION['admin']) ?></strong></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h1 class="section-title">
                    <i class="bi bi-gear-fill"></i>
                    Admin Dashboard
                </h1>

                <!-- Display Settings -->
                <div class="collapsible-section">
                    <div class="collapsible-header" onclick="toggleSection('display-settings')">
                        <span><i class="bi bi-display"></i> Display Scroll Settings</span>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div id="display-settings" class="collapsible-content">
                        <div class="settings-card mt-3">
                            <form method="POST">
                                <div class="settings-grid">
                                    <div class="setting-item">
                                        <label for="scroll_speed" class="form-label">
                                            <i class="bi bi-speedometer2"></i> Scroll Speed (pixels/sec)
                                        </label>
                                        <input type="number" class="form-control" id="scroll_speed" name="scroll_speed" 
                                               value="<?= $display_settings['scroll_speed'] ?? 25 ?>" min="5" max="100" required>
                                        <small class="text-muted">Range: 5-100 (default: 25)</small>
                                    </div>

                                    <div class="setting-item">
                                        <label for="pause_at_top" class="form-label">
                                            <i class="bi bi-pause-circle"></i> Pause at Top (milliseconds)
                                        </label>
                                        <input type="number" class="form-control" id="pause_at_top" name="pause_at_top" 
                                               value="<?= $display_settings['pause_at_top'] ?? 3000 ?>" min="1000" max="10000" step="500" required>
                                        <small class="text-muted">Range: 1000-10000 (default: 3000)</small>
                                    </div>

                                    <div class="setting-item">
                                        <label for="pause_at_bottom" class="form-label">
                                            <i class="bi bi-pause-circle"></i> Pause at Bottom (milliseconds)
                                        </label>
                                        <input type="number" class="form-control" id="pause_at_bottom" name="pause_at_bottom" 
                                               value="<?= $display_settings['pause_at_bottom'] ?? 3000 ?>" min="1000" max="10000" step="500" required>
                                        <small class="text-muted">Range: 1000-10000 (default: 3000)</small>
                                    </div>
                                </div>

                                <button type="submit" name="save_display_settings" class="btn-save mt-3">
                                    <i class="bi bi-save"></i> Save Display Settings
                                </button>
                            </form>

                            <div class="mt-4 p-3" style="background: #f8f9fa; border-radius: 8px; border-left: 4px solid var(--primary);">
                                <strong><i class="bi bi-info-circle"></i> Current Settings:</strong>
                                <div class="mt-2" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                                    <div>
                                        <div class="setting-label">Scroll Speed</div>
                                        <div class="setting-value"><?= $display_settings['scroll_speed'] ?? 25 ?> px/s</div>
                                    </div>
                                    <div>
                                        <div class="setting-label">Pause at Top</div>
                                        <div class="setting-value"><?= ($display_settings['pause_at_top'] ?? 3000) / 1000 ?> sec</div>
                                    </div>
                                    <div>
                                        <div class="setting-label">Pause at Bottom</div>
                                        <div class="setting-value"><?= ($display_settings['pause_at_bottom'] ?? 3000) / 1000 ?> sec</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Doctor List Table -->
                <div class="table-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                        <h4 class="section-title" style="font-size: 24px; margin: 0;">
                            <i class="bi bi-list-check"></i>
                            Doctor List
                        </h4>

                        <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 400px;">
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Search by name or department..." 
                                   value="<?= htmlspecialchars($search) ?>">
                            <button type="submit" class="btn btn-sm" style="background: var(--primary); color: white; padding: 0 20px;">
                                <i class="bi bi-search"></i>
                            </button>
                            <?php if ($search): ?>
                                <a href="?" class="btn btn-sm" style="background: #6c757d; color: white; padding: 0 20px;">
                                    <i class="bi bi-x"></i>
                                </a>
                            <?php endif; ?>
                        </form>

                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="btn btn-sm" onclick="showDoctorModal()" 
                                    style="background: linear-gradient(135deg, var(--success) 0%, #28a745 100%); color: white; font-weight: 600; padding: 8px 16px; border: none;">
                                <i class="bi bi-plus-circle"></i> Add New Doctor
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" id="delete-selected-btn" 
                                    onclick="deleteSelected()" style="display: none;">
                                <i class="bi bi-trash"></i> Delete Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="showDeleteAllModal()">
                                <i class="bi bi-trash"></i> Delete All
                            </button>
                        </div>
                    </div>

                    <form method="POST" id="bulk-delete-form" style="display: none;">
                        <input type="hidden" name="delete_selected" value="1">
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="select-all" onclick="toggleSelectAll()">
                                    </th>
                                    <th>
                                        <a href="?sort=name&order=<?= $sort === 'name' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" 
                                           style="text-decoration: none; color: white;">
                                            Name <?php if ($sort === 'name'): ?>
                                                <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?sort=department&order=<?= $sort === 'department' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" 
                                           style="text-decoration: none; color: white;">
                                            Department <?php if ($sort === 'department'): ?>
                                                <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a href="?sort=status&order=<?= $sort === 'status' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" 
                                           style="text-decoration: none; color: white;">
                                            Status <?php if ($sort === 'status'): ?>
                                                <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th>Resume Date</th>
                                    <th>Appointment</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="doctor-checkbox" value="<?= $row['id'] ?>">
                                    </td>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['department']) ?></td>
                                    <td>
                                        <?php
                                        $status_text = trim($row['status'] ?? '');
                                        $low = strtolower(preg_replace('/\s+/', ' ', $status_text));

                                        if ($low === '' || in_array($low, ['not available','no clinic','no medical'])) {
                                            $label = 'No Medical';
                                            $badge_class = 'status-nomedical';
                                        } elseif (in_array($low, ['available','on schedule'])) {
                                            $label = 'On Schedule';
                                            $badge_class = 'status-onschedule';
                                        } elseif (strpos($low, 'leave') !== false) {
                                            $label = 'On Leave';
                                            $badge_class = 'status-onleave';
                                        } else {
                                            $label = $low ? ucwords($low) : 'No Medical';
                                            $badge_class = 'status-nomedical';
                                        }

                                        echo '<span class="status-badge ' . $badge_class . '">' . htmlspecialchars($label) . '</span>';
                                        ?>
                                    </td>
                                    <td><?= $row['resume_date'] ? date('M d, Y', strtotime($row['resume_date'])) : '-' ?></td>
                                    <td>
                                        <?= (!empty($row['appt_start']) && !empty($row['appt_end'])) 
                                            ? htmlspecialchars($row['appt_start'] . ' - ' . $row['appt_end']) 
                                            : '-' ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['remarks'] ?? '-') ?></td>
                                    <td>
                                        <a href="javascript:void(0)" onclick="editDoctor(<?= $row['id'] ?>, '<?= addslashes($row['name']) ?>', '<?= $row['department'] ?>', '<?= $row['status'] ?>', '<?= $row['resume_date'] ?? '' ?>', '<?= $row['appt_start'] ?? '' ?>', '<?= $row['appt_end'] ?? '' ?>', '<?= addslashes($row['remarks'] ?? '') ?>')" class="btn-action btn-edit">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                        <a href="?delete=<?= $row['id'] ?>" class="btn-action btn-delete" 
                                           onclick="return confirm('Delete this doctor?')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Doctor Modal -->
    <div class="modal fade" id="doctorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%); color: white;">
                    <h5 class="modal-title" id="doctorModalTitle">
                        <i class="bi bi-plus-circle"></i> Add New Doctor
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="doctor-form">
                        <input type="hidden" name="id" id="doctor-id" value="">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Doctor Name *</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="department" class="form-label">Department *</label>
                                <select class="form-select" id="department" name="department" required>
                                    <option value="">Select Department</option>
                                    <option value="OPD">OPD</option>
                                    <option value="ER">ER</option>
                                    <option value="Pediatrics">Pediatrics</option>
                                    <option value="Cardiology">Cardiology</option>
                                    <option value="Radiology">Radiology</option>
                                    <option value="Laboratory">Laboratory</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label">Status *</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="On Schedule">On Schedule</option>
                                    <option value="No Medical">No Medical</option>
                                    <option value="On Leave">On Leave</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3 leave-fields" style="display:none;">
                                <label for="resume_date" class="form-label">Resume Date</label>
                                <input type="date" class="form-control" id="resume_date" name="resume_date">
                            </div>

                            <div class="col-md-3 mb-3 schedule-fields" style="display:none;">
                                <label for="appt_start" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="appt_start" name="appt_start">
                            </div>

                            <div class="col-md-3 mb-3 schedule-fields" style="display:none;">
                                <label for="appt_end" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="appt_end" name="appt_end">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 mb-3">
                                <label for="remarks" class="form-label">Remarks (Optional)</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2" 
                                          placeholder="Add any additional notes or remarks..."></textarea>
                            </div>
                        </div>

                        <div id="form-error" class="text-danger mb-3" style="display:none;"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" form="doctor-form" name="save" class="btn" 
                            style="background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%); color: white; font-weight: 700;">
                        <i class="bi bi-check-circle"></i> Save Doctor
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete All Modal -->
    <div class="modal fade" id="deleteAllModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--danger); color: white;">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Delete All Doctors</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>⚠️ Warning:</strong> This will permanently delete ALL doctors. This action CANNOT be undone!</p>
                    <p>Type <strong>DELETE ALL</strong> to confirm:</p>
                    <input type="text" id="confirm-input" class="form-control" placeholder="Type DELETE ALL">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="delete_all" value="1" class="btn btn-danger" 
                                id="confirm-delete-btn" disabled onclick="return validateDeleteAll()">
                            Delete ALL Doctors
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Collapsible sections
        function toggleSection(id) {
            const content = document.getElementById(id);
            const header = content.previousElementSibling;
            
            content.classList.toggle('active');
            header.classList.toggle('active');
        }

        // Initialize - open display settings by default
        document.addEventListener('DOMContentLoaded', function() {
            toggleSection('display-settings');
        });

        // Show/hide fields based on status
        const status = document.getElementById('status');
        const leaveFields = document.querySelectorAll('.leave-fields');
        const scheduleFields = document.querySelectorAll('.schedule-fields');
        const apptStart = document.getElementById('appt_start');
        const apptEnd = document.getElementById('appt_end');

        function toggleFields() {
            if (!status) return;
            if (status.value === 'On Leave') {
                leaveFields.forEach(e => e.style.display = '');
                scheduleFields.forEach(e => e.style.display = 'none');
                if (apptStart) apptStart.required = false;
                if (apptEnd) apptEnd.required = false;
            } else if (status.value === 'On Schedule') {
                leaveFields.forEach(e => e.style.display = 'none');
                scheduleFields.forEach(e => e.style.display = '');
                if (apptStart) apptStart.required = true;
                if (apptEnd) apptEnd.required = true;
            } else {
                leaveFields.forEach(e => e.style.display = 'none');
                scheduleFields.forEach(e => e.style.display = 'none');
                if (apptStart) apptStart.required = false;
                if (apptEnd) apptEnd.required = false;
            }
        }
        toggleFields();
        if (status) status.addEventListener('change', toggleFields);

        // Form validation
        const form = document.getElementById('doctor-form');
        const errorEl = document.getElementById('form-error');
        if (form) {
            form.addEventListener('submit', function(e) {
                errorEl.style.display = 'none';
                errorEl.textContent = '';
                if (!status) return;
                if (status.value === 'On Schedule') {
                    const s = apptStart ? apptStart.value : '';
                    const t = apptEnd ? apptEnd.value : '';
                    if (!s || !t) {
                        e.preventDefault();
                        errorEl.textContent = 'Please enter both start and end times.';
                        errorEl.style.display = 'block';
                        return false;
                    }
                    const sParts = s.split(':').map(Number);
                    const tParts = t.split(':').map(Number);
                    const sMinutes = sParts[0]*60 + (sParts[1] || 0);
                    const tMinutes = tParts[0]*60 + (tParts[1] || 0);
                    if (tMinutes <= sMinutes) {
                        e.preventDefault();
                        errorEl.textContent = 'End time must be after start time.';
                        errorEl.style.display = 'block';
                        return false;
                    }
                }
            });
        }

        // Checkbox functions
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.doctor-checkbox');
            const selectAll = document.getElementById('select-all');
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            updateDeleteButton();
        }

        function updateDeleteButton() {
            const checkedCount = document.querySelectorAll('.doctor-checkbox:checked').length;
            const deleteBtn = document.getElementById('delete-selected-btn');
            if (checkedCount > 0) {
                deleteBtn.style.display = 'inline-block';
                deleteBtn.textContent = `Delete Selected (${checkedCount})`;
            } else {
                deleteBtn.style.display = 'none';
            }
        }

        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.doctor-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one doctor to delete');
                return;
            }

            if (!confirm(`Delete ${checkboxes.length} doctor(s)?`)) {
                return;
            }

            const form = document.getElementById('bulk-delete-form');
            form.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());
            
            Array.from(checkboxes).forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            
            form.submit();
        }

        document.querySelectorAll('.doctor-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.doctor-checkbox');
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                document.getElementById('select-all').checked = allChecked;
                updateDeleteButton();
            });
        });

        updateDeleteButton();

        // Delete all modal
        function showDeleteAllModal() {
            const modal = new bootstrap.Modal(document.getElementById('deleteAllModal'));
            modal.show();
            document.getElementById('confirm-input').value = '';
            document.getElementById('confirm-delete-btn').disabled = true;
        }

        document.getElementById('confirm-input').addEventListener('input', function() {
            document.getElementById('confirm-delete-btn').disabled = this.value !== 'DELETE ALL';
        });

        function validateDeleteAll() {
            return document.getElementById('confirm-input').value === 'DELETE ALL' && 
                   confirm('Are you absolutely sure?');
        }

        // Doctor Modal Functions
        function showDoctorModal(id = null) {
            const modal = new bootstrap.Modal(document.getElementById('doctorModal'));
            
            // Reset form
            document.getElementById('doctor-form').reset();
            document.getElementById('doctor-id').value = '';
            document.getElementById('form-error').style.display = 'none';
            
            // Update modal title
            const title = document.getElementById('doctorModalTitle');
            title.innerHTML = '<i class="bi bi-plus-circle"></i> Add New Doctor';
            
            // Reset field visibility
            toggleFields();
            
            modal.show();
        }

        function editDoctor(id, name, department, status, resumeDate, apptStart, apptEnd, remarks) {
            const modal = new bootstrap.Modal(document.getElementById('doctorModal'));
            
            // Update modal title
            const title = document.getElementById('doctorModalTitle');
            title.innerHTML = '<i class="bi bi-pencil"></i> Edit Doctor Information';
            
            // Populate form fields
            document.getElementById('doctor-id').value = id;
            document.getElementById('name').value = name;
            document.getElementById('department').value = department;
            document.getElementById('status').value = status;
            document.getElementById('resume_date').value = resumeDate;
            document.getElementById('appt_start').value = apptStart;
            document.getElementById('appt_end').value = apptEnd;
            document.getElementById('remarks').value = remarks;
            
            // Update field visibility based on status
            toggleFields();
            
            modal.show();
        }

        // Auto-open modal if editing (when page loads with edit parameter)
        <?php if ($edit): ?>
        document.addEventListener('DOMContentLoaded', function() {
            editDoctor(
                <?= $edit['id'] ?>,
                '<?= addslashes($edit['name']) ?>',
                '<?= $edit['department'] ?>',
                '<?= $edit['status'] ?>',
                '<?= $edit['resume_date'] ?? '' ?>',
                '<?= $edit['appt_start'] ?? '' ?>',
                '<?= $edit['appt_end'] ?? '' ?>',
                '<?= addslashes($edit['remarks'] ?? '') ?>'
            );
        });
        <?php endif; ?>
    </script>
</body>
</html>