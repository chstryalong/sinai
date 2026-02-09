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

    if ($resume === '') $resume = null;
    if ($appt_start === '') $appt_start = null;
    if ($appt_end === '') $appt_end = null;

    // Clear fields that don't apply to selected status
    if ($status !== 'On Schedule') {
        $appt_start = null; $appt_end = null;
    }
    if ($status !== 'On Leave') {
        $resume = null;
    }

    if ($id == "") {
        $stmt = $conn->prepare(
            "INSERT INTO doctors (name, department, status, resume_date, appt_start, appt_end)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("ssssss", $name, $dept, $status, $resume, $appt_start, $appt_end);
    } else {
        $stmt = $conn->prepare(
            "UPDATE doctors SET name=?, department=?, status=?, resume_date=?, appt_start=?, appt_end=? WHERE id=?"
        );
        $stmt->bind_param("ssssssi", $name, $dept, $status, $resume, $appt_start, $appt_end, $id);
    }
    $stmt->execute();
    // redirect back to main page to clear edit state and show updated list
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

// Validate sort column
$valid_sorts = ['name', 'department', 'status'];
if (!in_array($sort, $valid_sorts)) {
    $sort = 'name';
}

// Validate order
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

/* ANNOUNCEMENTS TABLE & HANDLING */
$conn->query("CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    text TEXT,
    active TINYINT(1) DEFAULT 0,
    font_size INT DEFAULT 28,
    speed INT DEFAULT 18,
    bg_color VARCHAR(32) DEFAULT '#fff8e1',
    text_color VARCHAR(32) DEFAULT '#052744',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ensure additional columns exist (DBs without ALTER IF NOT EXISTS may error on older MySQL; check existence first)
$col = $conn->query("SHOW COLUMNS FROM announcements LIKE 'font_size'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE announcements ADD COLUMN font_size INT DEFAULT 28");
}
$col = $conn->query("SHOW COLUMNS FROM announcements LIKE 'speed'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE announcements ADD COLUMN speed INT DEFAULT 18");
}
$col = $conn->query("SHOW COLUMNS FROM announcements LIKE 'bg_color'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE announcements ADD COLUMN bg_color VARCHAR(32) DEFAULT '#fff8e1'");
}
$col = $conn->query("SHOW COLUMNS FROM announcements LIKE 'text_color'")->fetch_assoc();
if (!$col) {
    $conn->query("ALTER TABLE announcements ADD COLUMN text_color VARCHAR(32) DEFAULT '#052744'");
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

if (isset($_POST['save_announcement'])) {
    $announcement_text = $_POST['announcement_text'] ?? '';
    $announcement_active = isset($_POST['announcement_active']) ? 1 : 0;
    $announcement_speed = intval($_POST['announcement_speed'] ?? 18);
    $announcement_bg_color = $_POST['announcement_bg_color'] ?? '#fff8e1';
    $announcement_text_color = $_POST['announcement_text_color'] ?? '#052744';

    $row = $conn->query("SELECT id FROM announcements LIMIT 1")->fetch_assoc();
    if ($row) {
        $stmt = $conn->prepare("UPDATE announcements SET text=?, active=?, speed=?, bg_color=?, text_color=? WHERE id=?");
        $stmt->bind_param("siissi", $announcement_text, $announcement_active, $announcement_speed, $announcement_bg_color, $announcement_text_color, $row['id']);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO announcements (text, active, speed, bg_color, text_color) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("siiss", $announcement_text, $announcement_active, $announcement_speed, $announcement_bg_color, $announcement_text_color);
        $stmt->execute();
    }
    header("Location: index.php");
    exit;
} 

$announcement = $conn->query("SELECT * FROM announcements ORDER BY id DESC LIMIT 1")->fetch_assoc();
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
            --accent-700: #e0a800;
            --success: #28a745;
            --danger: #dc3545;
            --muted: #f1f5f9;
            --bg: linear-gradient(180deg,#f5f7fb 0%, #f8fafc 100%);
            --surface: rgba(255,255,255,0.96);
            --glass: rgba(255,255,255,0.65);
            --radius: 10px;
            --shadow-1: 0 2px 8px rgba(3,32,71,0.06);
            --shadow-2: 0 8px 30px rgba(3,32,71,0.08);
            --text: #052744;
            --muted-text: rgba(5,39,68,0.6);
            --transition: 240ms cubic-bezier(.2,.8,.2,1);
            /* legacy aliases */
            --primary-blue: var(--primary);
            --secondary-blue: var(--primary-600);
            --accent-yellow: var(--accent);
        }

        body {
            background: var(--bg);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            transition: background var(--transition);
        }

        a { color: var(--primary); }
        img { max-width: 100%; height: auto; }
        :focus { outline: none; box-shadow: none; }

        /* subtle micro-interactions */
        * { transition: color var(--transition), background var(--transition), box-shadow var(--transition), transform var(--transition); }

        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            box-shadow: var(--shadow-1);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            position: relative; /* make navbar the positioning context so header spans full width */
        }
        /* make navbar span full viewport width and anchor controls to edges */
        .navbar .container-fluid { width: 100%; padding: 0 1.5rem; display:block; position:relative; }

        /* left anchored logo */
        .nav-left { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); display:flex; align-items:center; gap:8px; }
        .nav-logo-left {
            height: 52px;
            width: auto;
            border-radius:8px;
            padding:6px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: 0 4px 12px rgba(2,6,23,0.35);
            transition: transform var(--transition), box-shadow var(--transition), opacity var(--transition);
            display: block;
        }
        .nav-logo-left:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 8px 24px rgba(2,6,23,0.45); opacity: 0.98; }

        /* right anchored user controls */
        .nav-right { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); display:flex; align-items:center; gap:12px; }

        .navbar .navbar-brand {
            display:block;
            text-align:center;
            font-weight:600;
            color:#fff;
            font-size: 18px;
            letter-spacing: -0.02em;
            padding: 0 8px;
            /* reserve clear space so the centered brand won't overlap the anchored logo/right controls */
            padding-left: 50px; /* space for left logo (reduced) */
            padding-right: 120px; /* space for right controls (reduced) */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            box-sizing: border-box;
        }
        .navbar .navbar-brand img { height:36px; width:auto; display:none; }

        @media (max-width: 992px) { .navbar .container-fluid { padding: 0 1rem; } }
        @media (max-width: 768px) { .nav-right, .nav-left { position: static; transform: none; display:flex; margin-top:8px; } .navbar .container-fluid { padding: 0 0.75rem; } .navbar .navbar-brand { text-align:left; padding-left: 0; padding-right: 0; max-width: none; } }

        /* constrain admin content */
        .col-lg-10.mx-auto { max-width: 980px; margin-left: auto; margin-right: auto; }

        /* slightly tighter panels for better density */
        .form-section { padding: 18px; }
        .table-section { padding: 16px; }

        .navbar .navbar-brand { display:flex; align-items:center; gap:10px; }
        .navbar .navbar-brand img { height:36px; width:auto; display:block; border-radius:6px; padding:2px; background: rgba(255,255,255,0.04); }
        .navbar .navbar-brand img:hover { transform: translateY(-2px) scale(1.02); box-shadow: var(--shadow-1); }
        .navbar .ms-auto { margin-left: auto; display:flex; gap:12px; align-items:center; justify-content:flex-end; }
        .navbar .text-white { opacity:0.95; }

        .card-surface { background: var(--surface); border-radius: calc(var(--radius) - 2px); box-shadow: var(--shadow-2); }
        .section-title { letter-spacing: -0.01em; }

        /* input / button focus polish */
        .form-control:focus { border-color: var(--primary); box-shadow: 0 8px 20px rgba(30,136,229,0.08); }
        .btn { border-radius: 10px; transition: transform var(--transition), box-shadow var(--transition); }
        .btn:hover { transform: translateY(-2px); }

        /* action button micro styles */
        .btn-action { transition: transform var(--transition), opacity var(--transition); }
        .btn-action:hover { opacity: 0.95; transform: translateY(-3px); }

        /* Utility */
        .card-surface { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow-2); }
        .muted-text { color: rgba(5,39,68,0.6); }
        .small { font-size: 0.9rem; }

        /* Enhanced section visuals */
        .form-section {
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,252,255,0.96));
            border-radius: var(--radius);
            padding: 26px;
            box-shadow: var(--shadow-1);
            margin-bottom: 28px;
            border-left: 6px solid rgba(30,136,229,0.08); /* replaced yellow with blue tint */
        }

        .table-section {
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,252,255,0.96));
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow-1);
        }

        footer { text-align:center; color: var(--muted-text); font-size: 12px; margin-top: 18px; }

        .navbar-brand {
            font-weight: 700;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border-top: 4px solid var(--primary-blue);
        }

        .form-label {
            color: var(--primary-blue);
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border: 1px solid rgba(16,24,40,0.06);
            border-radius: 8px;
            padding: 12px;
            transition: all 0.2s ease;
            background: rgba(255,255,255,0.99);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 6px 18px rgba(0,82,204,0.08);
            outline: none;
        }

        .btn-save {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            color: #fff;
            border: none;
            padding: 12px 20px;
            font-weight: 700;
            border-radius: 8px;
            transition: all 0.24s ease;
            width: 100%;
            margin-top: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }

        .btn-save:hover { transform: translateY(-2px); box-shadow: var(--shadow-2); }

        /* Action buttons */
        .btn-action { padding: 6px 12px; margin: 2px; font-size: 12px; border-radius: 6px; text-decoration: none; display: inline-block; }
        .btn-edit { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%); color: white; }
        .btn-edit:hover { opacity: 0.95; transform: translateY(-2px); }
        .btn-delete { background: linear-gradient(135deg, #f44336 0%, #dc3545 100%); color: white; }
        .btn-delete:hover { opacity: 0.95; transform: translateY(-2px); }
        .btn-logout { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; border: none; padding: 10px 22px; border-radius: 8px; }

        .section-title { color: var(--primary); font-size: 28px; font-weight: 700; display:flex; align-items:center; gap:10px; }

        /* Table styles */
        .table { border-collapse: separate; border-spacing: 0 8px; }
        .table thead { position: sticky; top: 0; z-index: 2; }
        .table thead th { background: linear-gradient(90deg, var(--primary) 0%, var(--primary-600) 100%); color: white; font-weight: 700; border: none; padding: 16px 18px; text-transform: uppercase; font-size: 16px; border-top-left-radius: 8px; border-top-right-radius: 8px; }
        .table tbody tr { background: var(--surface); box-shadow: 0 2px 6px rgba(3,32,71,0.04); border-radius: 8px; }
        .table tbody td { padding: 14px 18px; border: none; vertical-align: middle; font-size: 16px; }
        .table tbody tr + tr { margin-top: 8px; }
        .table tbody tr:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(3,32,71,0.06); }
        .table .badge-available, .table .badge-unavailable, .table .badge-leave { padding: 6px 10px; font-weight:700; border-radius: 999px; }
        .table-responsive { overflow-x: auto; padding-bottom: 8px; }

        @media (max-width: 768px) { .table thead th { font-size: 14px; } .table tbody td { font-size: 14px; } }

        /* Status badges (subtle pills with color dot) - TV Display Optimized */
        .status-badge { display:inline-flex; align-items:center; gap:12px; padding: 12px 16px; background: none; font-weight: 700; font-size: 20px; border-radius: 8px; }
        .status-badge::before { content: ""; display:inline-block; width:16px; height:16px; border-radius:50%; background: currentColor; box-shadow: 0 2px 4px rgba(0,0,0,0.12); transform: translateY(-1px); }
        .status-available { color: var(--success); }
        .status-unavailable { color: var(--danger); }
        .status-onleave { color: var(--danger); background: rgba(220, 53, 69, 0.08); }
        .status-nomedical { color: #6c757d; background: rgba(108, 117, 125, 0.08); }
        .status-onschedule { color: var(--primary-600); background: rgba(30, 136, 229, 0.08); }

        .table-section {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .table {
            margin-bottom: 0;
        }

        .table thead {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
        }

        .table thead th {
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }

        .table tbody td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .badge-available {
            background-color: #28a745;
        }

        .badge-unavailable {
            background-color: #dc3545;
        }

        .badge-leave {
            background-color: rgba(30,136,229,0.10);
            color: var(--primary-600);
        }

        .btn-action {
            padding: 6px 12px;
            margin: 2px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-edit {
            background-color: #0052CC;
            color: white;
        }

        .btn-edit:hover {
            background-color: #0041a3;
            color: white;
        }

        .btn-delete {
            background-color: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background-color: #c82333;
            color: white;
        }

        .btn-logout {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
            color: white;
        }

        .section-title {
            color: var(--primary-blue);
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            font-size: 36px;
        }

        .content-wrapper {
            padding: 20px 0;
        }

        .form-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-left: 5px solid rgba(30,136,229,0.08);
        }

        .table-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-left: 5px solid #0052CC;
        }

        .section-title {
            background: linear-gradient(135deg, #0052CC 0%, #1e88e5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        @media (max-width: 1200px) {
            .col-lg-10 { max-width: 920px; margin: 0 auto; }
        }

        @media (max-width: 768px) {
            .section-title { font-size: 18px; }
            .form-section, .table-section { padding: 16px; }
            .navbar-brand { font-size: 18px; }
            .table-responsive { overflow-x:auto; }
            .btn-save { width: 100%; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <div class="nav-left">
                <a href="../display/index.php" title="Open Display"><img src="../display/assets/logo2.png" alt="New Sinai MDI Hospital" class="nav-logo-left" /></a>
            </div>

            <span class="navbar-brand">New Sinai MDI Hospital</span>

            <div class="nav-right">
                <span class="text-white me-2">Welcome, <strong><?= htmlspecialchars($_SESSION['admin']) ?></strong></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div>
                    <h1 class="section-title">
                        <i class="bi bi-pencil-square"></i>
                        Doctor Schedule Management
                    </h1>
                </div>


                <!-- Add/Edit Form -->
                <div class="form-section">
            <h4 class="section-title" style="font-size: 28px; margin-bottom: 25px;">
                <i class="bi bi-plus-circle"></i>
                <?= $edit ? 'Edit Doctor Information' : 'Add New Doctor' ?>
            </h4>

            <form method="POST" id="doctor-form">
                <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="name" class="form-label">Doctor Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= $edit['name'] ?? '' ?>" required>
                    </div> 

                    <div class="col-md-6 mb-3">
                        <label for="department" class="form-label">Department</label>
                        <select class="form-select" id="department" name="department" required>
                            <option value="">Select Department</option>
                            <?php
                            $departments = ['OPD','ER','Pediatrics','Cardiology','Radiology','Laboratory'];
                            foreach ($departments as $d) {
                                $selected = ($edit && $edit['department'] == $d) ? "selected" : "";
                                echo "<option value='$d' $selected>$d</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php
                            // Confidential status options
                            $statuses = ['On Schedule','No Medical','On Leave'];
                            foreach ($statuses as $s) {
                                $selected = ($edit && $edit['status'] == $s) ? "selected" : "";
                                echo "<option $selected>$s</option>";
                            }
                            ?>
                        </select>
                    </div> 

                    <div class="col-md-6 mb-3 schedule-fields" style="display:none;">
                        <label for="appt_start" class="form-label">Appointment Start</label>
                        <input type="time" class="form-control" id="appt_start" name="appt_start" value="<?= $edit['appt_start'] ?? '' ?>">
                    </div>

                    <div class="col-md-6 mb-3 schedule-fields" style="display:none;">
                        <label for="appt_end" class="form-label">Appointment End</label>
                        <input type="time" class="form-control" id="appt_end" name="appt_end" value="<?= $edit['appt_end'] ?? '' ?>">
                    </div>

                    <div class="col-md-6 mb-3 leave-fields" style="display:none;">
                        <label for="resume_date" class="form-label">Resume Date</label>
                        <input type="date" class="form-control" id="resume_date" name="resume_date" value="<?= $edit['resume_date'] ?? '' ?>">
                    </div>
                </div>

                <div id="form-error" class="text-danger mb-3" style="display:none;"></div>

                <button type="submit" name="save" class="btn-save">
                    <i class="bi bi-check-circle"></i> <?= $edit ? 'Update Doctor' : 'Add Doctor' ?>
                </button>
            </form> 
        </div>

        <!-- Doctor List Table -->
        <div class="table-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h4 class="section-title" style="font-size: 28px; margin-bottom: 0;">
                    <i class="bi bi-list-check"></i>
                    Doctor List
                </h4>
                
                <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 400px;">
                    <div style="flex: 1;">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by name or department..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn btn-sm" style="background: var(--primary-blue); color: white; border: none;">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <?php if ($search): ?>
                        <a href="?" class="btn btn-sm" style="background: #6c757d; color: white; border: none;">
                            <i class="bi bi-x"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>

                <div style="display: flex; gap: 10px; align-items:center;">
                    <div class="status-legend me-2" style="display:flex; gap:12px; align-items:center;">
                        <div class="legend-item" style="display:flex; gap:8px; align-items:center;">
                            <span class="legend-dot" style="width:12px;height:12px;border-radius:6px;display:inline-block;background:#6c757d;"></span>
                            <span class="small muted-text">No Medical</span>
                        </div>
                        <div class="legend-item" style="display:flex; gap:8px; align-items:center;">
                            <span class="legend-dot" style="width:12px;height:12px;border-radius:6px;display:inline-block;background:var(--danger);"></span>
                            <span class="small muted-text">On Leave</span>
                        </div>
                        <div class="legend-item" style="display:flex; gap:8px; align-items:center;">
                            <span class="legend-dot" style="width:12px;height:12px;border-radius:6px;display:inline-block;background:var(--primary-600);"></span>
                            <span class="small muted-text">Resume</span>
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-secondary" id="delete-selected-btn" onclick="deleteSelected()" style="display: none;">
                        <i class="bi bi-trash"></i> Delete Selected
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="showDeleteAllModal()">
                        <i class="bi bi-trash"></i> Delete All
                    </button>
                </div>
            </div>

            <form method="POST" id="bulk-delete-form" style="display: none;">
                <input type="hidden" name="delete_selected" value="1">
                <input type="hidden" id="selected_ids_input" name="selected_ids[]" value="">
            </form>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 30px;">
                                <input type="checkbox" id="select-all" onclick="toggleSelectAll()">
                            </th>
                            <th>
                                <a href="?sort=name&order=<?= $sort === 'name' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; color: white;">
                                    <i class="bi bi-person"></i> Name
                                    <?php if ($sort === 'name'): ?>
                                        <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=department&order=<?= $sort === 'department' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; color: white;">
                                    <i class="bi bi-building"></i> Department
                                    <?php if ($sort === 'department'): ?>
                                        <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?sort=status&order=<?= $sort === 'status' && $order === 'ASC' ? 'DESC' : 'ASC' ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; color: white;">
                                    <i class="bi bi-toggle-on"></i> Status
                                    <?php if ($sort === 'status'): ?>
                                        <i class="bi <?= $order === 'ASC' ? 'bi-arrow-up' : 'bi-arrow-down' ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th><i class="bi bi-calendar"></i> Resume Date</th>
                            <th><i class="bi bi-clock"></i> Appointment</th>
                            <th><i class="bi bi-gear"></i> Actions</th> 
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
                                // Normalize legacy and varied statuses so list shows canonical labels
                                $status_text = trim($row['status'] ?? '');
                                $low = strtolower(preg_replace('/\s+/', ' ', $status_text)); // collapse whitespace

                                if ($low === '' || in_array($low, ['not available','notavailable','no clinic','no-clinic','no_medical','nomedical','no medical','not seeing patients'])) {
                                    $label = 'No Medical';
                                    $badge_class = 'status-nomedical';
                                } elseif (in_array($low, ['available','available now','onschedule','on schedule','on-schedule'])) {
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
                            <td><?= $row['resume_date'] ?? '-' ?></td>
                            <td><?= (!empty($row['appt_start']) && !empty($row['appt_end'])) ? htmlspecialchars($row['appt_start'] . ' - ' . $row['appt_end']) : '-' ?></td>
                            <td>
                                <a href="?edit=<?= $row['id'] ?>" class="btn-action btn-edit">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                                <a href="?delete=<?= $row['id'] ?>" class="btn-action btn-delete" onclick="return confirm('Are you sure you want to delete this doctor?')">
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

    <!-- Delete All Modal -->
    <div class="modal fade" id="deleteAllModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%); color: white;">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Delete All Doctors</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p><strong>⚠️ Warning:</strong> This will <strong>permanently delete ALL doctors</strong> from the system, including available doctors. This action <strong>CANNOT be undone!</strong></p>
                    <p>Type <strong>DELETE ALL</strong> to confirm:</p>
                    <input type="text" id="confirm-input" class="form-control" placeholder="Type DELETE ALL">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display: inline;">
                        <button type="submit" name="delete_all" value="1" class="btn btn-danger" id="confirm-delete-btn" disabled onclick="return validateDeleteAll()">
                            Delete ALL Doctors
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Store scroll position before page reload
        let scrollPosition = 0;

        // Save scroll position when page is about to unload
        window.addEventListener('beforeunload', function() {
            sessionStorage.setItem('scrollPosition', window.scrollY);
        });

        // Restore scroll position when page loads
        window.addEventListener('load', function() {
            scrollPosition = sessionStorage.getItem('scrollPosition');
            if (scrollPosition) {
                window.scrollTo(0, parseInt(scrollPosition));
            }
        });

        // Auto-refresh every 30 seconds without jumping to top
        setInterval(function() {
            // Save current scroll position
            sessionStorage.setItem('scrollPosition', window.scrollY);
            
            // Reload page silently
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    
                    // Update only the table tbody content
                    const oldTable = document.querySelector('table tbody');
                    const newTable = newDoc.querySelector('table tbody');
                    
                    if (oldTable && newTable && oldTable.innerHTML !== newTable.innerHTML) {
                        oldTable.innerHTML = newTable.innerHTML;
                        
                        // Re-attach event listeners to checkboxes
                        attachCheckboxListeners();
                    }
                })
                .catch(error => console.log('Auto-refresh check completed'));
        }, 30000); // 30 seconds

        function attachCheckboxListeners() {
            document.querySelectorAll('.doctor-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.doctor-checkbox');
                    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                    document.getElementById('select-all').checked = allChecked;
                    updateDeleteButton();
                });
            });
        }

        function showDeleteAllModal() {
            const modal = new bootstrap.Modal(document.getElementById('deleteAllModal'));
            modal.show();
            document.getElementById('confirm-input').value = '';
            document.getElementById('confirm-delete-btn').disabled = true;
        }

        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.doctor-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one doctor to delete');
                return;
            }

            if (!confirm(`Are you sure you want to delete ${checkboxes.length} doctor(s)? This action cannot be undone!`)) {
                return;
            }

            // Collect selected IDs
            const selectedIds = Array.from(checkboxes).map(cb => cb.value);
            
            // Create hidden inputs and submit form
            const form = document.getElementById('bulk-delete-form');
            const inputContainer = form.querySelector('input[type="hidden"]:last-of-type').parentElement;
            
            // Clear previous hidden inputs
            form.querySelectorAll('input[type="hidden"][name="selected_ids[]"]').forEach(el => el.remove());
            
            // Add new hidden inputs for each selected ID
            selectedIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_ids[]';
                input.value = id;
                form.appendChild(input);
            });
            
            form.submit();
        }

        document.getElementById('confirm-input').addEventListener('input', function() {
            document.getElementById('confirm-delete-btn').disabled = this.value !== 'DELETE ALL';
        });

        function validateDeleteAll() {
            if (document.getElementById('confirm-input').value === 'DELETE ALL') {
                return confirm('Are you absolutely sure? All doctors will be permanently deleted!');
            }
            return false;
        }

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
                deleteBtn.style.display = 'block';
                deleteBtn.textContent = `Delete Selected (${checkedCount})`;
            } else {
                deleteBtn.style.display = 'none';
            }
        }

        // Update select-all checkbox state when individual checkboxes change
        attachCheckboxListeners();

        // Initial check
        updateDeleteButton();

        // Show/hide fields and manage required attributes based on status selection
        (function(){
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

            // Form validation: require both times and ensure end > start when On Schedule
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
                            errorEl.textContent = 'Please enter both appointment start and end times for On Schedule.';
                            errorEl.style.display = '';
                            if (!s && apptStart) apptStart.focus();
                            else if (!t && apptEnd) apptEnd.focus();
                            return false;
                        }

                        // compare HH:MM values
                        const sParts = s.split(':').map(Number);
                        const tParts = t.split(':').map(Number);
                        const sMinutes = sParts[0]*60 + (sParts[1] || 0);
                        const tMinutes = tParts[0]*60 + (tParts[1] || 0);
                        if (tMinutes <= sMinutes) {
                            e.preventDefault();
                            errorEl.textContent = 'Appointment End time must be after Start time.';
                            errorEl.style.display = '';
                            if (apptEnd) apptEnd.focus();
                            return false;
                        }
                    }
                });
            }
        })();
    </script>
</body>
</html>
