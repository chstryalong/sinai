<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include("../config/db.php");

// Normalize legacy statuses
$conn->query("UPDATE doctors SET status='On Schedule' WHERE status = 'Available'");
$conn->query("UPDATE doctors SET status='No Medical' WHERE status = 'Not Available'");

/* ADD / UPDATE */
if (isset($_POST['save'])) {
    $id     = $_POST['id'];
    $name   = $_POST['name'];
    $dept   = $_POST['department'];
    $status = $_POST['status'];
    $resume = $_POST['resume_date'] ?? null;
    $remarks      = $_POST['remarks'] ?? null;
    $is_tentative = isset($_POST['is_tentative']) ? 1 : 0;

    if ($resume   === '') $resume   = null;
    if ($remarks  === '') $remarks  = null;

    if ($status === 'On Leave') {
        if (empty($resume)) {
            $_SESSION['error'] = 'Resume date is required for doctors on leave.';
            header("Location: index.php");
            exit;
        }
        $dateObj = DateTime::createFromFormat('Y-m-d', $resume);
        if (!$dateObj || $dateObj->format('Y-m-d') !== $resume) {
            $_SESSION['error'] = 'Invalid resume date format.';
            header("Location: index.php");
            exit;
        }
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        if ($dateObj < $today) {
            $error = 'Resume date cannot be in the past.';
            $open_modal = true;
        }
        // Remarks are now a dropdown — treat empty/null as "not selected"
        if (empty($remarks)) {
            $error = 'Please select a leave type.';
            $open_modal = true;
        }
    }

    // Clear leave-only fields when not on leave
    if ($status !== 'On Leave') {
        $resume       = null;
        $is_tentative = 0;
        $remarks      = null; // always null for No Medical
    }

    $appt_start = null;
    $appt_end   = null;

    if ($id == "") {
        $stmt = $conn->prepare(
            "INSERT INTO doctors (name, department, status, resume_date, appt_start, appt_end, remarks, is_tentative)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sssssssi", $name, $dept, $status, $resume, $appt_start, $appt_end, $remarks, $is_tentative);
    } else {
        $stmt = $conn->prepare(
            "UPDATE doctors SET name=?, department=?, status=?, resume_date=?, appt_start=?, appt_end=?, remarks=?, is_tentative=? WHERE id=?"
        );
        $stmt->bind_param("sssssssii", $name, $dept, $status, $resume, $appt_start, $appt_end, $remarks, $is_tentative, $id);
    }
    if (empty($open_modal)) {
        $stmt->execute();
        header("Location: index.php");
        exit;
    }

    // Validation failed — carry submitted values so modal reopens
    // pre-filled in the correct add/edit mode (not a blank Add form)
    $modal_data = [
        'id'           => $id,
        'name'         => $name,
        'department'   => $dept,
        'status'       => $status,
        'resume_date'  => $_POST['resume_date'] ?? '',
        'remarks'      => $_POST['remarks']     ?? '',
        'is_tentative' => isset($_POST['is_tentative']) ? 1 : 0,
    ];
}

/* DELETE */
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
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
    foreach ($_POST['selected_ids'] as $id) {
        $id = intval($id);
        $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
}

/* EDIT */
$edit = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM doctors WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
}

/* FETCH ALL */
$search = $_GET['search'] ?? '';
$sort   = $_GET['sort']   ?? 'name';
$order  = $_GET['order']  ?? 'ASC';

$query = "SELECT * FROM doctors WHERE 1=1";
if ($search) {
    $search_term = "%$search%";
    $query .= " AND (name LIKE ? OR department LIKE ?)";
}
$valid_sorts = ['name', 'department', 'status'];
if (!in_array($sort, $valid_sorts)) $sort = 'name';
if ($order !== 'ASC' && $order !== 'DESC') $order = 'ASC';
$query .= " ORDER BY $sort $order";

if ($search) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

/* DISPLAY SETTINGS */
$conn->query("CREATE TABLE IF NOT EXISTS display_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scroll_speed INT DEFAULT 25,
    pause_at_top INT DEFAULT 3000,
    pause_at_bottom INT DEFAULT 3000,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$settings_check = $conn->query("SELECT COUNT(*) as count FROM display_settings")->fetch_assoc();
if ($settings_check['count'] == 0) {
    $conn->query("INSERT INTO display_settings (scroll_speed, pause_at_top, pause_at_bottom) VALUES (25, 3000, 3000)");
}

// Ensure columns exist
foreach (['remarks TEXT NULL', 'appt_start TIME NULL', 'appt_end TIME NULL', 'is_tentative TINYINT(1) DEFAULT 0'] as $col_def) {
    $col_name = explode(' ', $col_def)[0];
    $col = $conn->query("SHOW COLUMNS FROM doctors LIKE '$col_name'")->fetch_assoc();
    if (!$col) $conn->query("ALTER TABLE doctors ADD COLUMN $col_def");
}

if (isset($_POST['save_display_settings'])) {
    $scroll_speed = max(5,    min(100,   intval($_POST['scroll_speed']    ?? 25)));
    $pause_top    = max(1000, min(10000, intval($_POST['pause_at_top']    ?? 3000)));
    $pause_bottom = max(1000, min(10000, intval($_POST['pause_at_bottom'] ?? 3000)));
    $conn->query("UPDATE display_settings SET scroll_speed=$scroll_speed, pause_at_top=$pause_top, pause_at_bottom=$pause_bottom WHERE id=1");
    header("Location: index.php");
    exit;
}

$display_settings = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1")->fetch_assoc();
$speed_labels  = [15 => 'Slow', 25 => 'Normal', 50 => 'Fast'];
$pause_labels  = [2000 => '2 seconds', 3000 => '3 seconds', 5000 => '5 seconds', 8000 => '8 seconds', 10000 => '10 seconds'];
$current_speed  = $display_settings['scroll_speed']    ?? 25;
$current_top    = $display_settings['pause_at_top']    ?? 3000;
$current_bottom = $display_settings['pause_at_bottom'] ?? 3000;
$current_speed_label  = $speed_labels[$current_speed]   ?? $current_speed  . ' px/s';
$current_top_label    = $pause_labels[$current_top]     ?? ($current_top    / 1000) . ' sec';
$current_bottom_label = $pause_labels[$current_bottom]  ?? ($current_bottom / 1000) . ' sec';

// Leave type options
$leave_types = ['On Vacation', 'Personal', 'Sick Leave'];
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
            --bg: linear-gradient(180deg, #f5f7fb 0%, #f8fafc 100%);
            --radius: 10px;
            --shadow-1: 0 2px 8px rgba(3,32,71,0.06);
            --shadow-2: 0 8px 30px rgba(3,32,71,0.08);
            --text: #052744;
            --muted-text: rgba(5,39,68,0.6);
            --transition: 240ms cubic-bezier(.2,.8,.2,1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); font-family: 'Inter','Segoe UI',sans-serif; color: var(--text); -webkit-font-smoothing: antialiased; line-height: 1.6; }

        /* ── Navbar ── */
        .navbar { background: linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%); box-shadow: var(--shadow-1); padding: 1rem 0; position: sticky; top: 0; z-index: 1000; }
        .navbar .container-fluid { max-width: 1400px; margin: 0 auto; padding: 0 2rem; display: flex; align-items: center; justify-content: space-between; }
        .nav-left { display: flex; align-items: center; gap: 12px; }
        .nav-logo-left { height: 48px; width: auto; border-radius: 8px; padding: 4px; background: rgba(255,255,255,0.15); transition: transform var(--transition); }
        .nav-logo-left:hover { transform: translateY(-2px) scale(1.05); }
        .navbar-brand { color: white; font-weight: 700; font-size: 20px; text-decoration: none; letter-spacing: -0.02em; }
        .nav-right { display: flex; align-items: center; gap: 16px; }
        .nav-right .text-white { font-size: 14px; opacity: 0.95; }
        .btn-outline-light { border: 2px solid rgba(255,255,255,0.8); color: white; padding: 8px 20px; border-radius: 8px; font-weight: 600; transition: all var(--transition); }
        .btn-outline-light:hover { background: white; color: var(--primary); transform: translateY(-2px); }

        /* ── Layout ── */
        .container-fluid.mt-4 { max-width: 1400px; margin: 2rem auto !important; padding: 0 2rem; }
        .section-title { color: var(--primary); font-size: 32px; font-weight: 700; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
        .section-title i { font-size: 36px; }
        .form-section, .table-section { background: white; border-radius: var(--radius); padding: 32px; box-shadow: var(--shadow-2); margin-bottom: 32px; border-top: 4px solid var(--primary); }
        .form-label { color: var(--primary); font-weight: 600; margin-bottom: 8px; font-size: 14px; }
        .form-control, .form-select, textarea.form-control { border: 2px solid #e2e8f0; border-radius: 8px; padding: 12px; transition: all var(--transition); font-size: 14px; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,82,204,0.1); outline: none; }
        .btn-save { background: linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%); color: white; border: none; padding: 14px 24px; font-weight: 700; border-radius: 8px; transition: all var(--transition); width: 100%; margin-top: 16px; font-size: 16px; }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,82,204,0.3); }

        /* ── Table ── */
        .table-responsive { overflow-x: auto; margin-top: 20px; }
        .table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
        .table thead th { background: linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%); color: white; font-weight: 700; padding: 16px; border: none; text-align: left; font-size: 14px; white-space: nowrap; }
        .table thead th:first-child { border-top-left-radius: 8px; }
        .table thead th:last-child  { border-top-right-radius: 8px; }
        .table tbody tr { background: white; box-shadow: 0 2px 6px rgba(0,0,0,0.04); transition: all var(--transition); }
        .table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0,0,0,0.08); }
        .table tbody td { padding: 16px; border: none; vertical-align: middle; font-size: 14px; }

        /* ── Status badges ── */
        .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 12px; }
        .status-badge::before { content: ""; width: 10px; height: 10px; border-radius: 50%; background: currentColor; }
        .status-nomedical  { color: #6c757d; background: rgba(108,117,125,0.1); }
        .status-onschedule { color: var(--primary-600); background: rgba(30,136,229,0.1); }
        .status-onleave    { color: var(--danger); background: rgba(220,53,69,0.1); }

        /* ── Leave type badge (table) ── */
        .leave-type-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .leave-vacation { background: rgba(30,136,229,0.12); color: #1e88e5; }
        .leave-personal { background: rgba(142,68,173,0.12); color: #8e44ad; }
        .leave-sick     { background: rgba(220,53,69,0.12);  color: #dc3545; }

        /* ── Action buttons ── */
        .btn-action { padding: 6px 14px; margin: 2px; font-size: 12px; border-radius: 6px; text-decoration: none; display: inline-block; transition: all var(--transition); font-weight: 600; }
        .btn-edit   { background: var(--primary); color: white; }
        .btn-edit:hover  { background: var(--primary-600); color: white; transform: translateY(-2px); }
        .btn-delete { background: var(--danger); color: white; }
        .btn-delete:hover { background: #c82333; color: white; transform: translateY(-2px); }

        /* ── Display settings card ── */
        .settings-card { background: linear-gradient(135deg,#f8f9fa 0%,#ffffff 100%); border: 2px solid var(--primary); border-radius: var(--radius); padding: 28px; margin-bottom: 32px; }
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 20px; }
        .setting-item { display: flex; flex-direction: column; gap: 6px; }
        .setting-select { border: 2px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; font-size: 16px; font-weight: 600; color: var(--primary); background: white; cursor: pointer; transition: all var(--transition); appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%230052CC' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px; }
        .setting-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,82,204,0.1); outline: none; }
        .setting-select:hover { border-color: var(--primary); }
        .setting-label-text { font-size: 13px; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 6px; }
        .setting-hint { font-size: 12px; color: var(--muted-text); }
        .current-summary { margin-top: 20px; padding: 16px 20px; background: #f0f5ff; border-radius: 10px; border-left: 4px solid var(--primary); display: flex; flex-wrap: wrap; gap: 24px; align-items: center; }
        .summary-item { display: flex; flex-direction: column; gap: 2px; }
        .summary-item .s-label { font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--muted-text); letter-spacing: 0.5px; }
        .summary-item .s-value { font-size: 20px; font-weight: 800; color: var(--primary); }

        /* ── Tentative badge ── */
        .tentative-badge { display: inline-block; background: rgba(255,193,7,0.2); color: #856404; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; margin-left: 8px; }

        /* ── Form check ── */
        .form-check-label { color: var(--text); font-weight: 600; cursor: pointer; }
        .form-check-input:checked { background-color: var(--accent); border-color: var(--accent); }

        /* ── Remarks select disabled state ── */
        .form-select:disabled, .form-select[disabled] {
            background-color: #f1f5f9;
            color: #aaa;
            cursor: not-allowed;
            opacity: 0.7;
        }
        .remarks-hint {
            font-size: 12px;
            margin-top: 4px;
        }
        .remarks-hint.hint-active { color: var(--primary); }
        .remarks-hint.hint-disabled { color: #aaa; }

        /* ── Collapsible ── */
        .collapsible-section { margin-bottom: 24px; }
        .collapsible-header { background: linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%); color: white; padding: 16px 24px; border-radius: 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-weight: 700; transition: all var(--transition); }
        .collapsible-header:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,82,204,0.3); }
        .collapsible-header i { transition: transform var(--transition); }
        .collapsible-header.active i { transform: rotate(180deg); }
        .collapsible-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
        .collapsible-content.active { max-height: 2000px; }

        @media (max-width: 768px) {
            .navbar .container-fluid { flex-direction: column; gap: 12px; padding: 0 1rem; }
            .nav-left, .nav-right { width: 100%; justify-content: center; }
            .container-fluid.mt-4 { padding: 0 1rem; }
            .form-section, .table-section { padding: 20px; }
            .section-title { font-size: 24px; }
            .settings-grid { grid-template-columns: 1fr; }
            .table thead th, .table tbody td { font-size: 12px; padding: 12px 8px; }
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

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($_SESSION['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <h1 class="section-title">
                <i class="bi bi-gear-fill"></i> Admin Dashboard
            </h1>

            <!-- ── Display Scroll Settings ── -->
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
                                    <label class="setting-label-text"><i class="bi bi-speedometer2"></i> How fast should it scroll?</label>
                                    <select name="scroll_speed" class="setting-select">
                                        <option value="15" <?= $current_speed == 15 ? 'selected' : '' ?>>🐢 Slow</option>
                                        <option value="25" <?= $current_speed == 25 ? 'selected' : '' ?>>👍 Normal</option>
                                        <option value="50" <?= $current_speed == 50 ? 'selected' : '' ?>>⚡ Fast</option>
                                    </select>
                                    <span class="setting-hint">How quickly the list moves on screen</span>
                                </div>
                                <div class="setting-item">
                                    <label class="setting-label-text"><i class="bi bi-arrow-up-circle"></i> Wait time at the top</label>
                                    <select name="pause_at_top" class="setting-select">
                                        <option value="2000"  <?= $current_top == 2000  ? 'selected' : '' ?>>2 seconds</option>
                                        <option value="3000"  <?= $current_top == 3000  ? 'selected' : '' ?>>3 seconds</option>
                                        <option value="5000"  <?= $current_top == 5000  ? 'selected' : '' ?>>5 seconds</option>
                                        <option value="8000"  <?= $current_top == 8000  ? 'selected' : '' ?>>8 seconds</option>
                                        <option value="10000" <?= $current_top == 10000 ? 'selected' : '' ?>>10 seconds</option>
                                    </select>
                                    <span class="setting-hint">How long it pauses before scrolling down</span>
                                </div>
                                <div class="setting-item">
                                    <label class="setting-label-text"><i class="bi bi-arrow-down-circle"></i> Wait time at the bottom</label>
                                    <select name="pause_at_bottom" class="setting-select">
                                        <option value="2000"  <?= $current_bottom == 2000  ? 'selected' : '' ?>>2 seconds</option>
                                        <option value="3000"  <?= $current_bottom == 3000  ? 'selected' : '' ?>>3 seconds</option>
                                        <option value="5000"  <?= $current_bottom == 5000  ? 'selected' : '' ?>>5 seconds</option>
                                        <option value="8000"  <?= $current_bottom == 8000  ? 'selected' : '' ?>>8 seconds</option>
                                        <option value="10000" <?= $current_bottom == 10000 ? 'selected' : '' ?>>10 seconds</option>
                                    </select>
                                    <span class="setting-hint">How long it pauses before scrolling back up</span>
                                </div>
                            </div>
                            <button type="submit" name="save_display_settings" class="btn-save mt-3">
                                <i class="bi bi-save"></i> Save Display Settings
                            </button>
                        </form>
                        <div class="current-summary">
                            <strong><i class="bi bi-info-circle"></i> Currently active:</strong>
                            <div class="summary-item">
                                <span class="s-label">Scroll Speed</span>
                                <span class="s-value"><?= htmlspecialchars($current_speed_label) ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="s-label">Pause at Top</span>
                                <span class="s-value"><?= htmlspecialchars($current_top_label) ?></span>
                            </div>
                            <div class="summary-item">
                                <span class="s-label">Pause at Bottom</span>
                                <span class="s-value"><?= htmlspecialchars($current_bottom_label) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Doctor List Table ── -->
            <div class="table-section">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:15px;">
                    <h4 class="section-title" style="font-size:24px;margin:0;">
                        <i class="bi bi-list-check"></i> Doctor List
                    </h4>
                    <form method="GET" style="display:flex;gap:10px;flex:1;max-width:400px;">
                        <input type="text" name="search" class="form-control"
                            placeholder="Search by name or department..."
                            value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-sm" style="background:var(--primary);color:white;padding:0 20px;">
                            <i class="bi bi-search"></i>
                        </button>
                        <?php if ($search): ?>
                            <a href="?" class="btn btn-sm" style="background:#6c757d;color:white;padding:0 20px;">
                                <i class="bi bi-x"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                    <div style="display:flex;gap:10px;">
                        <button type="button" class="btn btn-sm" onclick="showDoctorModal()"
                            style="background:linear-gradient(135deg,var(--success) 0%,#28a745 100%);color:white;font-weight:600;padding:8px 16px;border:none;">
                            <i class="bi bi-plus-circle"></i> Add New Doctor
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary" id="delete-selected-btn"
                            onclick="deleteSelected()" style="display:none;">
                            <i class="bi bi-trash"></i> Delete Selected
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="showDeleteAllModal()">
                            <i class="bi bi-trash"></i> Delete All
                        </button>
                    </div>
                </div>

                <form method="POST" id="bulk-delete-form" style="display:none;">
                    <input type="hidden" name="delete_selected" value="1">
                </form>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="select-all" onclick="toggleSelectAll()"></th>
                                <th>
                                    <a href="?sort=name&order=<?= $sort==='name'&&$order==='ASC'?'DESC':'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration:none;color:white;">
                                        Name <?php if ($sort==='name'): ?><i class="bi <?= $order==='ASC'?'bi-arrow-up':'bi-arrow-down' ?>"></i><?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?sort=department&order=<?= $sort==='department'&&$order==='ASC'?'DESC':'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration:none;color:white;">
                                        Department <?php if ($sort==='department'): ?><i class="bi <?= $order==='ASC'?'bi-arrow-up':'bi-arrow-down' ?>"></i><?php endif; ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?sort=status&order=<?= $sort==='status'&&$order==='ASC'?'DESC':'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration:none;color:white;">
                                        Status <?php if ($sort==='status'): ?><i class="bi <?= $order==='ASC'?'bi-arrow-up':'bi-arrow-down' ?>"></i><?php endif; ?>
                                    </a>
                                </th>
                                <th>Resume Date</th>
                                <th>Leave Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                $status_text = trim($row['status'] ?? '');
                                $low = strtolower(preg_replace('/\s+/', ' ', $status_text));
                                if ($low === '' || in_array($low, ['not available','no clinic','no medical'])) {
                                    $label = 'No Medical'; $badge_class = 'status-nomedical';
                                } elseif (in_array($low, ['available','on schedule'])) {
                                    $label = 'On Schedule'; $badge_class = 'status-onschedule';
                                } elseif (strpos($low, 'leave') !== false) {
                                    $label = 'On Leave'; $badge_class = 'status-onleave';
                                } else {
                                    $label = ucwords($low) ?: 'No Medical'; $badge_class = 'status-nomedical';
                                }
                                $rm = trim($row['remarks'] ?? '');
                                $pill_class = match($rm) {
                                    'On Vacation' => 'leave-vacation',
                                    'Personal'    => 'leave-personal',
                                    'Sick Leave'  => 'leave-sick',
                                    default       => ''
                                };
                                $pill_icon = match($rm) {
                                    'On Vacation' => 'bi-airplane-fill',
                                    'Personal'    => 'bi-person-heart',
                                    'Sick Leave'  => 'bi-thermometer-half',
                                    default       => ''
                                };
                                ?>
                                <tr>
                                    <td><input type="checkbox" class="doctor-checkbox" value="<?= $row['id'] ?>"></td>
                                    <td><?= htmlspecialchars($row['name']) ?></td>
                                    <td><?= htmlspecialchars($row['department']) ?></td>
                                    <td><span class="status-badge <?= $badge_class ?>"><?= htmlspecialchars($label) ?></span></td>
                                    <td>
                                        <?php if ($row['resume_date']): ?>
                                            <?= date('M d, Y', strtotime($row['resume_date'])) ?>
                                            <?php if ($row['is_tentative']): ?>
                                                <span class="tentative-badge">TENTATIVE</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rm !== '' && $label === 'On Leave'): ?>
                                            <span class="leave-type-pill <?= $pill_class ?>">
                                                <i class="bi <?= $pill_icon ?>"></i> <?= htmlspecialchars($rm) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:#bbb;font-size:13px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="javascript:void(0)"
                                            onclick="editDoctor(<?= $row['id'] ?>,'<?= addslashes($row['name']) ?>','<?= $row['department'] ?>','<?= $row['status'] ?>','<?= $row['resume_date'] ?? '' ?>','<?= addslashes($rm) ?>',<?= $row['is_tentative'] ?? 0 ?>)"
                                            class="btn-action btn-edit">
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

<!-- ── Add/Edit Doctor Modal ── -->
<div class="modal fade" id="doctorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%);color:white;">
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
                                <option value="No Medical">No Medical</option>
                                <option value="On Leave">On Leave</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3 leave-fields" style="display:none;">
                            <label for="resume_date" class="form-label">Resume Date *</label>
                            <input type="date" class="form-control" id="resume_date" name="resume_date">
                            <?php if (!empty($error)): ?>
                                <div class="text-danger mt-1"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="row leave-fields" style="display:none;">
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_tentative" name="is_tentative" value="1">
                                <label class="form-check-label" for="is_tentative">
                                    <i class="bi bi-calendar-question"></i> Resume date is tentative
                                </label>
                            </div>
                            <small class="text-muted">Check this if the return date is not confirmed yet</small>
                        </div>
                    </div>

                    <!-- ── Remarks / Leave Type ── -->
                    <div class="row">
                        <div class="col-12 mb-3">
                            <label for="remarks" class="form-label" id="remarks-label">
                                <i class="bi bi-tag"></i> Leave Type
                            </label>
                            <select class="form-select" id="remarks" name="remarks" disabled>
                                <option value="">— Select leave type —</option>
                                <?php foreach ($leave_types as $lt): ?>
                                    <option value="<?= htmlspecialchars($lt) ?>"><?= htmlspecialchars($lt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="remarks-hint hint-disabled" id="remarks-hint">
                                <i class="bi bi-lock"></i> Only available when status is <strong>On Leave</strong>
                            </div>
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
                    style="background:linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%);color:white;font-weight:700;">
                    <i class="bi bi-check-circle"></i> Save Doctor
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Delete All Modal ── -->
<div class="modal fade" id="deleteAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--danger);color:white;">
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
                <form method="POST" style="display:inline;">
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
    // ── Collapsible sections ──────────────────────────────────────────────────
    function toggleSection(id) {
        const content = document.getElementById(id);
        const header  = content.previousElementSibling;
        content.classList.toggle('active');
        header.classList.toggle('active');
    }
    document.addEventListener('DOMContentLoaded', () => toggleSection('display-settings'));

    // ── Status / leave-fields / remarks toggle ────────────────────────────────
    const statusEl      = document.getElementById('status');
    const leaveFields   = document.querySelectorAll('.leave-fields');
    const resumeDateEl  = document.getElementById('resume_date');
    const remarksEl     = document.getElementById('remarks');
    const remarksHint   = document.getElementById('remarks-hint');

    function toggleFields() {
        const isLeave = statusEl.value === 'On Leave';

        // Show/hide leave-specific fields (resume date, tentative)
        leaveFields.forEach(e => e.style.display = isLeave ? '' : 'none');
        if (resumeDateEl) resumeDateEl.required = isLeave;

        // Enable/disable and style the remarks dropdown
        remarksEl.disabled = !isLeave;
        if (isLeave) {
            remarksEl.classList.remove('text-muted');
            remarksHint.className = 'remarks-hint hint-active';
            remarksHint.innerHTML = '<i class="bi bi-info-circle"></i> Select the reason for leave';
        } else {
            // Reset and lock
            remarksEl.value = '';
            remarksHint.className = 'remarks-hint hint-disabled';
            remarksHint.innerHTML = '<i class="bi bi-lock"></i> Only available when status is <strong>On Leave</strong>';
        }
    }

    toggleFields();
    statusEl.addEventListener('change', toggleFields);

    // ── Form validation ───────────────────────────────────────────────────────
    const formEl   = document.getElementById('doctor-form');
    const errorEl  = document.getElementById('form-error');
    if (formEl) {
        formEl.addEventListener('submit', function(e) {
            errorEl.style.display = 'none';
            errorEl.textContent   = '';
            if (statusEl.value === 'On Leave') {
                if (!resumeDateEl.value) {
                    e.preventDefault();
                    errorEl.textContent = 'Resume date is required for doctors on leave.';
                    errorEl.style.display = 'block';
                    return false;
                }
                if (!remarksEl.value) {
                    e.preventDefault();
                    errorEl.textContent = 'Please select a leave type.';
                    errorEl.style.display = 'block';
                    return false;
                }
            }
        });
    }

    // ── Select-all / bulk delete ──────────────────────────────────────────────
    function toggleSelectAll() {
        const cbs = document.querySelectorAll('.doctor-checkbox');
        cbs.forEach(cb => cb.checked = document.getElementById('select-all').checked);
        updateDeleteButton();
    }
    function updateDeleteButton() {
        const n = document.querySelectorAll('.doctor-checkbox:checked').length;
        const btn = document.getElementById('delete-selected-btn');
        btn.style.display = n > 0 ? 'inline-block' : 'none';
        if (n > 0) btn.textContent = `Delete Selected (${n})`;
    }
    function deleteSelected() {
        const cbs = document.querySelectorAll('.doctor-checkbox:checked');
        if (!cbs.length) { alert('Please select at least one doctor to delete'); return; }
        if (!confirm(`Delete ${cbs.length} doctor(s)?`)) return;
        const f = document.getElementById('bulk-delete-form');
        f.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());
        cbs.forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'selected_ids[]'; inp.value = cb.value;
            f.appendChild(inp);
        });
        f.submit();
    }
    document.querySelectorAll('.doctor-checkbox').forEach(cb => {
        cb.addEventListener('change', () => {
            const all = document.querySelectorAll('.doctor-checkbox');
            document.getElementById('select-all').checked = Array.from(all).every(c => c.checked);
            updateDeleteButton();
        });
    });
    updateDeleteButton();

    // ── Delete All modal ──────────────────────────────────────────────────────
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
        return document.getElementById('confirm-input').value === 'DELETE ALL' && confirm('Are you absolutely sure?');
    }

    // ── Doctor modal helpers ──────────────────────────────────────────────────
    function showDoctorModal() {
        const modal = new bootstrap.Modal(document.getElementById('doctorModal'));
        document.getElementById('doctor-form').reset();
        document.getElementById('doctor-id').value = '';
        errorEl.style.display = 'none';
        document.getElementById('is_tentative').checked = false;
        document.getElementById('doctorModalTitle').innerHTML = '<i class="bi bi-plus-circle"></i> Add New Doctor';
        toggleFields();
        modal.show();
    }

    function editDoctor(id, name, department, status, resumeDate, remarks, isTentative) {
        document.getElementById('doctorModalTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Doctor Information';
        document.getElementById('doctor-id').value     = id;
        document.getElementById('name').value          = name;
        document.getElementById('department').value    = department;
        statusEl.value                                 = status;
        document.getElementById('resume_date').value   = resumeDate;
        document.getElementById('is_tentative').checked = isTentative == 1;
        toggleFields(); // enable/disable remarks BEFORE setting its value
        remarksEl.value = remarks;
        errorEl.style.display = 'none';
        const modal = new bootstrap.Modal(document.getElementById('doctorModal'));
        modal.show();
    }

    <?php if ($edit): ?>
    document.addEventListener('DOMContentLoaded', () => {
        editDoctor(
            <?= $edit['id'] ?>,
            '<?= addslashes($edit['name']) ?>',
            '<?= $edit['department'] ?>',
            '<?= $edit['status'] ?>',
            '<?= $edit['resume_date'] ?? '' ?>',
            '<?= addslashes(trim($edit['remarks'] ?? '')) ?>',
            <?= $edit['is_tentative'] ?? 0 ?>
        );
    });
    <?php endif; ?>
</script>

<?php if (!empty($open_modal) && isset($modal_data)): ?>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        // Reopen the modal pre-filled — distinguishes edit vs add correctly
        editDoctor(
            <?= json_encode($modal_data['id'])           ?>,
            <?= json_encode($modal_data['name'])         ?>,
            <?= json_encode($modal_data['department'])   ?>,
            <?= json_encode($modal_data['status'])       ?>,
            <?= json_encode($modal_data['resume_date'])  ?>,
            <?= json_encode($modal_data['remarks'])      ?>,
            <?= json_encode($modal_data['is_tentative']) ?>
        );
        <?php if (!empty($error)): ?>
        // Show the server-side validation error inside the modal
        const errEl = document.getElementById('form-error');
        if (errEl) {
            errEl.textContent  = <?= json_encode($error) ?>;
            errEl.style.display = 'block';
        }
        <?php endif; ?>
    });
</script>
<?php endif; ?>

</body>
</html>