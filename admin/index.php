<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

include("../config/db.php");

define('MASTER_RESET_KEY', 'Newsinaimdi#53');

// ── Fix ENUM column → VARCHAR so all status values save correctly ─────────────
$col_type = $conn->query("SHOW COLUMNS FROM doctors LIKE 'status'")->fetch_assoc();
if ($col_type && strpos(strtolower($col_type['Type']), 'enum') !== false) {
    $conn->query("ALTER TABLE doctors MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'No Medical'");
}

// Normalize legacy statuses + fix any empty status from old ENUM rejections
$conn->query("UPDATE doctors SET status='On Schedule' WHERE status IN ('Available')");
$conn->query("UPDATE doctors SET status='No Medical'  WHERE status IN ('Not Available','No Clinic','')");

// Ensure columns exist
foreach (['remarks TEXT NULL','appt_start TIME NULL','appt_end TIME NULL','is_tentative TINYINT(1) DEFAULT 0'] as $col_def) {
    $col_name = explode(' ', $col_def)[0];
    if (!$conn->query("SHOW COLUMNS FROM doctors LIKE '$col_name'")->fetch_assoc())
        $conn->query("ALTER TABLE doctors ADD COLUMN $col_def");
}

// ── Display settings table ────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS display_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scroll_speed INT DEFAULT 25,
    pause_at_top INT DEFAULT 3000,
    pause_at_bottom INT DEFAULT 3000,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
if ($conn->query("SELECT COUNT(*) as c FROM display_settings")->fetch_assoc()['c'] == 0)
    $conn->query("INSERT INTO display_settings (scroll_speed,pause_at_top,pause_at_bottom) VALUES (25,3000,3000)");

// ════════════════════════════════════════════════════════════════════════════
//  AJAX ENDPOINTS  (all return JSON, no HTML)
// ════════════════════════════════════════════════════════════════════════════
header_remove('X-Powered-By');

function jsonOut($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function doctorLabel($status) {
    $low = strtolower(trim($status));
    if ($low==='' || in_array($low,['not available','no clinic','no medical'])) return ['label'=>'No Clinic','badge'=>'status-nomedical'];
    if (in_array($low,['available','on schedule'])) return ['label'=>'Available','badge'=>'status-onschedule'];
    if (strpos($low,'leave')!==false) return ['label'=>'On Leave','badge'=>'status-onleave'];
    return ['label'=>ucwords($low)?:'No Clinic','badge'=>'status-nomedical'];
}

// ── Fetch doctors list ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax']==='doctors') {
    global $conn;
    $res = $conn->query("SELECT * FROM doctors ORDER BY name ASC");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $lb = doctorLabel($r['status']);
        $rows[] = [
            'id'          => $r['id'],
            'name'        => $r['name'],
            'department'  => $r['department'],
            'status'      => $r['status'],
            'label'       => $lb['label'],
            'badge'       => $lb['badge'],
            'resume_date' => $r['resume_date'],
            'is_tentative'=> (int)($r['is_tentative'] ?? 0),
            'remarks'     => trim($r['remarks'] ?? ''),
        ];
    }
    jsonOut(['ok'=>true,'doctors'=>$rows]);
}

// ── Save doctor (add/edit) ────────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='save_doctor') {
    $id           = intval($_POST['id'] ?? 0);
    $name         = trim($_POST['name'] ?? '');
    $dept         = trim($_POST['department'] ?? '');
    $status       = trim($_POST['status'] ?? '');
    $resume       = trim($_POST['resume_date'] ?? '') ?: null;
    $remarks      = trim($_POST['remarks'] ?? '') ?: null;
    $is_tentative = isset($_POST['is_tentative']) ? 1 : 0;

    if (!$name || !$dept || !$status) jsonOut(['ok'=>false,'error'=>'Name, department and status are required.']);

    if ($status === 'On Leave') {
        if (!$resume) jsonOut(['ok'=>false,'error'=>'Resume date is required for On Leave.']);
        $d = DateTime::createFromFormat('Y-m-d',$resume);
        if (!$d || $d->format('Y-m-d')!==$resume) jsonOut(['ok'=>false,'error'=>'Invalid resume date.']);
        $today = new DateTime(); $today->setTime(0,0,0);
        if ($d < $today) jsonOut(['ok'=>false,'error'=>'Resume date cannot be in the past.']);
        if (!$remarks) jsonOut(['ok'=>false,'error'=>'Please select a leave type.']);
    } else {
        $resume = null; $is_tentative = 0; $remarks = null;
    }

    $null = null;
    if ($id === 0) {
        $stmt = $conn->prepare("INSERT INTO doctors (name,department,status,resume_date,appt_start,appt_end,remarks,is_tentative) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssssi",$name,$dept,$status,$resume,$null,$null,$remarks,$is_tentative);
    } else {
        $stmt = $conn->prepare("UPDATE doctors SET name=?,department=?,status=?,resume_date=?,appt_start=?,appt_end=?,remarks=?,is_tentative=? WHERE id=?");
        $stmt->bind_param("sssssssii",$name,$dept,$status,$resume,$null,$null,$remarks,$is_tentative,$id);
    }
    $stmt->execute();
    $newId = $id ?: $conn->insert_id;
    // Return updated row
    $r = $conn->query("SELECT * FROM doctors WHERE id=$newId")->fetch_assoc();
    $lb = doctorLabel($r['status']);
    jsonOut(['ok'=>true,'doctor'=>[
        'id'=>$r['id'],'name'=>$r['name'],'department'=>$r['department'],
        'status'=>$r['status'],'label'=>$lb['label'],'badge'=>$lb['badge'],
        'resume_date'=>$r['resume_date'],'is_tentative'=>(int)($r['is_tentative']??0),
        'remarks'=>trim($r['remarks']??''),
    ],'insert'=>($id===0)]);
}

// ── Delete one doctor ─────────────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='delete_doctor') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) jsonOut(['ok'=>false,'error'=>'Invalid id']);
    $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
    $stmt->bind_param("i",$id);
    $stmt->execute();
    jsonOut(['ok'=>true]);
}

// ── Delete selected doctors ───────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='delete_selected') {
    $ids = json_decode($_POST['ids'] ?? '[]',true);
    foreach ($ids as $id) {
        $id = intval($id);
        $stmt = $conn->prepare("DELETE FROM doctors WHERE id=?");
        $stmt->bind_param("i",$id);
        $stmt->execute();
    }
    jsonOut(['ok'=>true]);
}

// ── Delete all doctors ────────────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='delete_all') {
    $conn->query("DELETE FROM doctors");
    jsonOut(['ok'=>true]);
}

// ── Save display settings ─────────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='save_display') {
    $spd = max(5,  min(100,   intval($_POST['scroll_speed']    ?? 25)));
    $top = max(1000,min(10000,intval($_POST['pause_at_top']    ?? 3000)));
    $bot = max(1000,min(10000,intval($_POST['pause_at_bottom'] ?? 3000)));
    $conn->query("UPDATE display_settings SET scroll_speed=$spd,pause_at_top=$top,pause_at_bottom=$bot WHERE id=1");
    jsonOut(['ok'=>true]);
}

// ── Change password ───────────────────────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='change_password') {
    $cur  = trim($_POST['current_or_key']  ?? '');
    $np   = trim($_POST['new_password']    ?? '');
    $cp   = trim($_POST['confirm_password']?? '');
    if (!$cur||!$np||!$cp)          jsonOut(['ok'=>false,'error'=>'All fields are required.']);
    if ($np !== $cp)                 jsonOut(['ok'=>false,'error'=>'New passwords do not match.']);
    if (strlen($np) < 6)             jsonOut(['ok'=>false,'error'=>'Password must be at least 6 characters.']);
    $username = $_SESSION['admin'];
    $u = $conn->prepare("SELECT * FROM users WHERE username=?");
    $u->bind_param("s",$username); $u->execute();
    $row = $u->get_result()->fetch_assoc();
    if (!$row || (md5($cur)!==$row['password'] && $cur!==MASTER_RESET_KEY))
        jsonOut(['ok'=>false,'error'=>'Current password or master reset key is incorrect.']);
    $h = md5($np);
    $upd = $conn->prepare("UPDATE users SET password=? WHERE username=?");
    $upd->bind_param("ss",$h,$username); $upd->execute();
    jsonOut(['ok'=>true,'message'=>'Password changed successfully!']);
}

// ── Load display settings for page render ─────────────────────────────────────
$ds = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1")->fetch_assoc();
$speed_labels  = [15=>'Slow',25=>'Normal',50=>'Fast'];
$pause_labels  = [2000=>'2 seconds',3000=>'3 seconds',5000=>'5 seconds',8000=>'8 seconds',10000=>'10 seconds'];
$cs = $ds['scroll_speed']??25; $ct = $ds['pause_at_top']??3000; $cb = $ds['pause_at_bottom']??3000;
$csl = $speed_labels[$cs]??$cs.' px/s';
$ctl = $pause_labels[$ct]??($ct/1000).' sec';
$cbl = $pause_labels[$cb]??($cb/1000).' sec';

$leave_types = ['On Vacation','Personal','Sick Leave'];

// Pre-load all doctors for initial render
$init_res = $conn->query("SELECT * FROM doctors ORDER BY name ASC");
$init_doctors = [];
while ($r = $init_res->fetch_assoc()) {
    $lb = doctorLabel($r['status']);
    $init_doctors[] = ['id'=>$r['id'],'name'=>$r['name'],'department'=>$r['department'],
        'status'=>$r['status'],'label'=>$lb['label'],'badge'=>$lb['badge'],
        'resume_date'=>$r['resume_date'],'is_tentative'=>(int)($r['is_tentative']??0),
        'remarks'=>trim($r['remarks']??'')];
}
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
    --primary:#0052CC; --primary-600:#1e88e5; --accent:#ffc107;
    --success:#28a745; --danger:#dc3545;
    --bg:linear-gradient(180deg,#f5f7fb 0%,#f8fafc 100%);
    --radius:10px; --shadow-1:0 2px 8px rgba(3,32,71,.06);
    --shadow-2:0 8px 30px rgba(3,32,71,.08); --text:#052744;
    --muted-text:rgba(5,39,68,.6); --transition:240ms cubic-bezier(.2,.8,.2,1);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);font-family:'Inter','Segoe UI',sans-serif;color:var(--text);-webkit-font-smoothing:antialiased;line-height:1.6;}

/* Navbar */
.navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%);box-shadow:var(--shadow-1);padding:1rem 0;position:sticky;top:0;z-index:1000;}
.navbar .container-fluid{max-width:1400px;margin:0 auto;padding:0 2rem;display:flex;align-items:center;justify-content:space-between;}
.nav-left{display:flex;align-items:center;gap:12px;}
.nav-logo-left{height:48px;width:auto;border-radius:8px;padding:4px;background:rgba(255,255,255,.15);transition:transform var(--transition);}
.nav-logo-left:hover{transform:translateY(-2px) scale(1.05);}
.navbar-brand{color:white;font-weight:700;font-size:20px;text-decoration:none;letter-spacing:-.02em;}
.nav-right{display:flex;align-items:center;gap:16px;}

/* Profile dropdown */
.profile-dropdown{position:relative;}
.profile-btn{background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.4);color:white;width:44px;height:44px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:24px;transition:all var(--transition);}
.profile-btn:hover{background:rgba(255,255,255,.25);transform:scale(1.05);}
.profile-menu{display:none;position:absolute;right:0;top:calc(100% + 10px);background:white;border-radius:12px;box-shadow:0 8px 30px rgba(3,32,71,.15);min-width:180px;overflow:hidden;z-index:2000;border:1px solid #e2e8f0;}
.profile-menu.open{display:block;animation:fadeDown .18s ease;}
@keyframes fadeDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.profile-menu-header{padding:14px 16px;display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;color:var(--primary);background:#f0f5ff;}
.profile-menu-header i{font-size:18px;}
.profile-menu-divider{height:1px;background:#e2e8f0;}
.profile-menu-item{display:flex;align-items:center;gap:10px;padding:12px 16px;font-size:14px;font-weight:600;color:var(--danger);text-decoration:none;transition:background var(--transition);cursor:pointer;border:none;background:none;width:100%;}
.profile-menu-item:hover{background:#fff0f0;color:var(--danger);}
.profile-menu-item-neutral{color:var(--primary)!important;}
.profile-menu-item-neutral:hover{background:#f0f5ff!important;color:var(--primary)!important;}

/* Password toggle */
.pw-wrap{position:relative;display:flex;align-items:center;}
.pw-wrap .form-control{padding-right:42px;}
.pw-eye{position:absolute;right:10px;background:none;border:none;color:#aaa;cursor:pointer;padding:0;font-size:17px;line-height:1;transition:color .15s;}
.pw-eye:hover{color:var(--primary);}

/* Layout */
.container-fluid.mt-4{max-width:1400px;margin:2rem auto!important;padding:0 2rem;}
.section-title{color:var(--primary);font-size:32px;font-weight:700;margin-bottom:24px;display:flex;align-items:center;gap:12px;}
.section-title i{font-size:36px;}
.table-section{background:white;border-radius:var(--radius);padding:32px;box-shadow:var(--shadow-2);margin-bottom:32px;border-top:4px solid var(--primary);}
.form-label{color:var(--primary);font-weight:600;margin-bottom:8px;font-size:14px;}
.form-control,.form-select,textarea.form-control{border:2px solid #e2e8f0;border-radius:8px;padding:12px;transition:all var(--transition);font-size:14px;}
.form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,82,204,.1);outline:none;}

/* Table */
.table-responsive{overflow-x:auto;margin-top:20px;}
.table{width:100%;border-collapse:separate;border-spacing:0 8px;}
.table thead th{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%);color:white;font-weight:700;padding:16px;border:none;text-align:left;font-size:14px;white-space:nowrap;}
.table thead th:first-child{border-top-left-radius:8px;}
.table thead th:last-child{border-top-right-radius:8px;}
.table tbody tr{background:white;box-shadow:0 2px 6px rgba(0,0,0,.04);transition:all var(--transition);}
.table tbody tr:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(0,0,0,.08);}
.table tbody td{padding:16px;border:none;vertical-align:middle;font-size:14px;}
.table-empty{text-align:center;color:#aaa;padding:32px!important;}

/* Sortable headers */
.sortable{cursor:pointer;user-select:none;}
.sortable:hover{background:rgba(255,255,255,.15);}
.sort-icon{font-size:11px;opacity:.5;margin-left:4px;}
.sort-asc .sort-icon,.sort-desc .sort-icon{opacity:1;}

/* Filter row */
.filter-input{width:100%;background:white;border:1px solid #d0daf0;border-radius:6px;padding:5px 8px;font-size:12px;color:var(--text);outline:none;}
.filter-input:focus{border-color:var(--primary);box-shadow:0 0 0 2px rgba(0,82,204,.1);}
#thead-filters th{background:rgba(0,82,204,.07);padding:6px 16px;}

/* Status badges */
.status-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:20px;font-weight:700;font-size:12px;}
.status-badge::before{content:"";width:10px;height:10px;border-radius:50%;background:currentColor;}
.status-nomedical{color:#6c757d;background:rgba(108,117,125,.1);}
.status-onschedule{color:var(--primary-600);background:rgba(30,136,229,.1);}
.status-onleave{color:var(--danger);background:rgba(220,53,69,.1);}

/* Buttons */
.btn-action{padding:6px 14px;margin:2px;font-size:12px;border-radius:6px;text-decoration:none;display:inline-block;transition:all var(--transition);font-weight:600;border:none;cursor:pointer;}
.btn-edit{background:var(--primary);color:white;}
.btn-edit:hover{background:var(--primary-600);color:white;transform:translateY(-2px);}
.btn-delete{background:var(--danger);color:white;}
.btn-delete:hover{background:#c82333;color:white;transform:translateY(-2px);}

/* Tentative badge */
.tentative-badge{display:inline-block;background:rgba(255,193,7,.2);color:#856404;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;margin-left:8px;}

/* Form check */
.form-check-label{color:var(--text);font-weight:600;cursor:pointer;}
.form-check-input:checked{background-color:var(--accent);border-color:var(--accent);}
.form-select:disabled,.form-select[disabled]{background-color:#f1f5f9;color:#aaa;cursor:not-allowed;opacity:.7;}
.remarks-hint{font-size:12px;margin-top:4px;}
.remarks-hint.hint-active{color:var(--primary);}
.remarks-hint.hint-disabled{color:#aaa;}

/* Display settings */
.settings-card{background:linear-gradient(135deg,#f8f9fa 0%,#ffffff 100%);border:2px solid var(--primary);border-radius:var(--radius);padding:28px;margin-bottom:32px;}
.settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;}
.setting-item{display:flex;flex-direction:column;gap:6px;}
.setting-select{border:2px solid #e2e8f0;border-radius:10px;padding:14px 16px;font-size:16px;font-weight:600;color:var(--primary);background:white;cursor:pointer;transition:all var(--transition);appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%230052CC' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:40px;}
.setting-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,82,204,.1);outline:none;}
.setting-label-text{font-size:13px;font-weight:700;color:var(--primary);display:flex;align-items:center;gap:6px;}
.setting-hint{font-size:12px;color:var(--muted-text);}
.current-summary{margin-top:20px;padding:16px 20px;background:#f0f5ff;border-radius:10px;border-left:4px solid var(--primary);display:flex;flex-wrap:wrap;gap:24px;align-items:center;}
.summary-item{display:flex;flex-direction:column;gap:2px;}
.summary-item .s-label{font-size:11px;text-transform:uppercase;font-weight:700;color:var(--muted-text);letter-spacing:.5px;}
.summary-item .s-value{font-size:20px;font-weight:800;color:var(--primary);}
.btn-save{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%);color:white;border:none;padding:14px 24px;font-weight:700;border-radius:8px;transition:all var(--transition);width:100%;margin-top:16px;font-size:16px;cursor:pointer;}
.btn-save:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,82,204,.3);}

/* Collapsible */
.collapsible-section{margin-bottom:24px;}
.collapsible-header{background:linear-gradient(135deg,var(--primary) 0%,var(--primary-600) 100%);color:white;padding:16px 24px;border-radius:8px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:700;transition:all var(--transition);}
.collapsible-header:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,82,204,.3);}
.collapsible-header i{transition:transform var(--transition);}
.collapsible-header.active i{transform:rotate(180deg);}
.collapsible-content{max-height:0;overflow:hidden;transition:max-height .3s ease;}
.collapsible-content.active{max-height:2000px;}

/* Toast */
.toast-wrap{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast-msg{background:white;border-radius:10px;padding:12px 20px;box-shadow:0 4px 20px rgba(0,0,0,.15);font-size:14px;font-weight:600;display:flex;align-items:center;gap:10px;animation:slideUp .25s ease;border-left:4px solid var(--success);}
.toast-msg.error{border-left-color:var(--danger);}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

@media(max-width:768px){
    .navbar .container-fluid{flex-direction:column;gap:12px;padding:0 1rem;}
    .nav-left,.nav-right{width:100%;justify-content:center;}
    .container-fluid.mt-4{padding:0 1rem;}
    .table-section{padding:20px;}
    .section-title{font-size:24px;}
    .settings-grid{grid-template-columns:1fr;}
    .table thead th,.table tbody td{font-size:12px;padding:12px 8px;}
}
</style>
</head>
<body>

<nav class="navbar">
  <div class="container-fluid">
    <div class="nav-left">
      <a href="../display/index.php" target="_blank" title="Open Display">
        <img src="../display/assets/logo2.png" alt="Logo" class="nav-logo-left"/>
      </a>
      <span class="navbar-brand">New Sinai MDI Hospital</span>
    </div>
    <div class="nav-right">
      <div class="profile-dropdown" id="profileDropdown">
        <button class="profile-btn" onclick="toggleProfileMenu()">
          <i class="bi bi-person-circle"></i>
        </button>
        <div class="profile-menu" id="profileMenu">
          <div class="profile-menu-header">
            <i class="bi bi-person-fill"></i>
            <span><?= htmlspecialchars($_SESSION['admin']) ?></span>
          </div>
          <div class="profile-menu-divider"></div>
          <button class="profile-menu-item profile-menu-item-neutral"
            onclick="profileMenu.classList.remove('open');showChangePasswordModal()">
            <i class="bi bi-key-fill"></i> Change Password
          </button>
          <div class="profile-menu-divider"></div>
          <a href="logout.php" class="profile-menu-item">
            <i class="bi bi-box-arrow-right"></i> Logout
          </a>
        </div>
      </div>
    </div>
  </div>
</nav>

<div class="container-fluid mt-4">
  <div class="row"><div class="col-12">

    <div id="alert-area"></div>

    <h1 class="section-title"><i class="bi bi-gear-fill"></i> Admin Dashboard</h1>

    <!-- Display Scroll Settings -->
    <div class="collapsible-section">
      <div class="collapsible-header" onclick="toggleSection('display-settings')">
        <span><i class="bi bi-display"></i> Display Scroll Settings</span>
        <i class="bi bi-chevron-down"></i>
      </div>
      <div id="display-settings" class="collapsible-content">
        <div class="settings-card mt-3">
          <div class="settings-grid">
            <div class="setting-item">
              <label class="setting-label-text"><i class="bi bi-speedometer2"></i> How fast should it scroll?</label>
              <select id="set-speed" class="setting-select">
                <option value="15" <?= $cs==15?'selected':'' ?>>🐢 Slow</option>
                <option value="25" <?= $cs==25?'selected':'' ?>>👍 Normal</option>
                <option value="50" <?= $cs==50?'selected':'' ?>>⚡ Fast</option>
              </select>
              <span class="setting-hint">How quickly the list moves on screen</span>
            </div>
            <div class="setting-item">
              <label class="setting-label-text"><i class="bi bi-arrow-up-circle"></i> Wait time at the top</label>
              <select id="set-top" class="setting-select">
                <option value="2000"  <?= $ct==2000 ?'selected':'' ?>>2 seconds</option>
                <option value="3000"  <?= $ct==3000 ?'selected':'' ?>>3 seconds</option>
                <option value="5000"  <?= $ct==5000 ?'selected':'' ?>>5 seconds</option>
                <option value="8000"  <?= $ct==8000 ?'selected':'' ?>>8 seconds</option>
                <option value="10000" <?= $ct==10000?'selected':'' ?>>10 seconds</option>
              </select>
              <span class="setting-hint">How long it pauses before scrolling down</span>
            </div>
            <div class="setting-item">
              <label class="setting-label-text"><i class="bi bi-arrow-down-circle"></i> Wait time at the bottom</label>
              <select id="set-bot" class="setting-select">
                <option value="2000"  <?= $cb==2000 ?'selected':'' ?>>2 seconds</option>
                <option value="3000"  <?= $cb==3000 ?'selected':'' ?>>3 seconds</option>
                <option value="5000"  <?= $cb==5000 ?'selected':'' ?>>5 seconds</option>
                <option value="8000"  <?= $cb==8000 ?'selected':'' ?>>8 seconds</option>
                <option value="10000" <?= $cb==10000?'selected':'' ?>>10 seconds</option>
              </select>
              <span class="setting-hint">How long it pauses before scrolling back up</span>
            </div>
          </div>
          <button class="btn-save mt-3" onclick="saveDisplaySettings()">
            <i class="bi bi-save"></i> Save Display Settings
          </button>
          <div class="current-summary" id="summary-bar">
            <strong><i class="bi bi-info-circle"></i> Currently active:</strong>
            <div class="summary-item">
              <span class="s-label">Scroll Speed</span>
              <span class="s-value" id="sum-speed"><?= htmlspecialchars($csl) ?></span>
            </div>
            <div class="summary-item">
              <span class="s-label">Pause at Top</span>
              <span class="s-value" id="sum-top"><?= htmlspecialchars($ctl) ?></span>
            </div>
            <div class="summary-item">
              <span class="s-label">Pause at Bottom</span>
              <span class="s-value" id="sum-bot"><?= htmlspecialchars($cbl) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Doctor List -->
    <div class="table-section">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:15px;">
        <h4 class="section-title" style="font-size:24px;margin:0;">
          <i class="bi bi-list-check"></i> Doctor List
        </h4>
        <div style="display:flex;gap:10px;flex:1;max-width:600px;align-items:center;">
          <div style="position:relative;flex:1;">
            <i class="bi bi-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#aaa;pointer-events:none;"></i>
            <input type="text" id="global-search" class="form-control"
              style="padding-left:36px;"
              placeholder="Search by name…" oninput="filterTable()">
          </div>
          <select id="f-dept" class="form-select" style="max-width:160px;" onchange="filterTable()">
            <option value="">All Departments</option>
            <option>OPD</option>
            <option>ER</option>
            <option>Pediatrics</option>
            <option>Cardiology</option>
            <option>Radiology</option>
            <option>Laboratory</option>
          </select>
          <select id="f-status" class="form-select" style="max-width:150px;" onchange="filterTable()">
            <option value="">All Status</option>
            <option value="Available">Available</option>
            <option value="No Clinic">No Clinic</option>
            <option value="On Leave">On Leave</option>
          </select>
          <button class="btn btn-sm" style="background:#6c757d;color:white;padding:0 14px;white-space:nowrap;height:42px;"
            onclick="clearAllFilters()" title="Clear filters"><i class="bi bi-x-lg"></i></button>
        </div>
        <div style="display:flex;gap:10px;">
          <button class="btn btn-sm" onclick="showDoctorModal()"
            style="background:linear-gradient(135deg,var(--success),#28a745);color:white;font-weight:600;padding:8px 16px;border:none;">
            <i class="bi bi-plus-circle"></i> Add New Doctor
          </button>
          <button class="btn btn-sm btn-secondary" id="delete-selected-btn"
            onclick="deleteSelected()" style="display:none;">
            <i class="bi bi-trash"></i> Delete Selected
          </button>
          <button class="btn btn-sm btn-danger" onclick="showDeleteAllModal()">
            <i class="bi bi-trash"></i> Delete All
          </button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table" id="doctor-table">
          <thead>
            <tr id="thead-labels">
              <th style="width:40px;"><input type="checkbox" id="select-all" onclick="toggleSelectAll()"></th>
              <th class="sortable" data-col="1" onclick="sortTable(1)">Name <i class="bi bi-arrow-down-up sort-icon" id="si-1"></i></th>
              <th class="sortable" data-col="2" onclick="sortTable(2)">Department <i class="bi bi-arrow-down-up sort-icon" id="si-2"></i></th>
              <th class="sortable" data-col="3" onclick="sortTable(3)">Status <i class="bi bi-arrow-down-up sort-icon" id="si-3"></i></th>
              <th class="sortable" data-col="4" onclick="sortTable(4)">Resume Date <i class="bi bi-arrow-down-up sort-icon" id="si-4"></i></th>
              <th class="sortable" data-col="5" onclick="sortTable(5)">Leave Type <i class="bi bi-arrow-down-up sort-icon" id="si-5"></i></th>
              <th>Actions</th>
            </tr>

          </thead>
          <tbody id="doctor-tbody">
            <!-- filled by JS -->
          </tbody>
        </table>
      </div>
    </div>

  </div></div>
</div>

<!-- Add/Edit Doctor Modal -->
<div class="modal fade" id="doctorModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-600));color:white;">
        <h5 class="modal-title" id="doctorModalTitle"><i class="bi bi-plus-circle"></i> Add New Doctor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Doctor Name *</label>
            <input type="text" class="form-control" id="m-name">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Department *</label>
            <select class="form-select" id="m-dept">
              <option value="">Select Department</option>
              <option>OPD</option><option>ER</option><option>Pediatrics</option>
              <option>Cardiology</option><option>Radiology</option><option>Laboratory</option>
            </select>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Status *</label>
            <select class="form-select" id="m-status" onchange="toggleLeaveFields()">
              <option value="On Schedule">Available</option>
              <option value="No Medical">No Clinic</option>
              <option value="On Leave">On Leave</option>
            </select>
          </div>
          <div class="col-md-6 mb-3 leave-fields" style="display:none;">
            <label class="form-label">Resume Date *</label>
            <input type="date" class="form-control" id="m-resume">
          </div>
        </div>
        <div class="row leave-fields" style="display:none;">
          <div class="col-12 mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="m-tentative">
              <label class="form-check-label" for="m-tentative">
                <i class="bi bi-calendar-question"></i> Resume date is tentative
              </label>
            </div>
            <small class="text-muted">Check this if the return date is not confirmed yet</small>
          </div>
        </div>
        <div class="row">
          <div class="col-12 mb-3">
            <label class="form-label"><i class="bi bi-tag"></i> Leave Type</label>
            <select class="form-select" id="m-remarks" disabled>
              <option value="">— Select leave type —</option>
              <?php foreach($leave_types as $lt): ?>
                <option value="<?= htmlspecialchars($lt) ?>"><?= htmlspecialchars($lt) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="remarks-hint hint-disabled" id="remarks-hint">
              <i class="bi bi-lock"></i> Only available when status is <strong>On Leave</strong>
            </div>
          </div>
        </div>
        <div id="form-error" class="text-danger mb-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="bi bi-x-circle"></i> Cancel
        </button>
        <button type="button" class="btn" onclick="saveDoctor()"
          style="background:linear-gradient(135deg,var(--primary),var(--primary-600));color:white;font-weight:700;">
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
      <div class="modal-header" style="background:var(--danger);color:white;">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Delete All Doctors</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p><strong>⚠️ Warning:</strong> This will permanently delete ALL doctors.</p>
        <p>Type <strong>DELETE ALL</strong> to confirm:</p>
        <input type="text" id="confirm-input" class="form-control" placeholder="Type DELETE ALL">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirm-delete-btn" disabled onclick="confirmDeleteAll()">
          Delete ALL Doctors
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--primary),var(--primary-600));color:white;">
        <h5 class="modal-title"><i class="bi bi-key-fill"></i> Change Password</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="cp-alert" style="display:none;" class="mb-3"></div>
        <div class="mb-3">
          <label class="form-label" style="font-size:13px;font-weight:700;color:var(--primary);">
            Current Password <span style="color:#aaa;font-weight:400;">or Master Reset Key</span>
          </label>
          <div class="pw-wrap">
            <input type="password" class="form-control" id="pw_current" placeholder="Current password or reset key" autocomplete="off">
            <button type="button" class="pw-eye" onclick="togglePw('pw_current',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
          <div style="font-size:11px;color:#aaa;margin-top:4px;">
            <i class="bi bi-info-circle"></i> Forgot your password? Enter the master reset key instead.
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:13px;font-weight:700;color:var(--primary);">New Password</label>
          <div class="pw-wrap">
            <input type="password" class="form-control" id="pw_new" placeholder="Min. 6 characters" autocomplete="new-password">
            <button type="button" class="pw-eye" onclick="togglePw('pw_new',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:13px;font-weight:700;color:var(--primary);">Confirm New Password</label>
          <div class="pw-wrap">
            <input type="password" class="form-control" id="pw_confirm" placeholder="Repeat new password" autocomplete="new-password">
            <button type="button" class="pw-eye" onclick="togglePw('pw_confirm',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm" onclick="savePassword()"
          style="background:linear-gradient(135deg,var(--primary),var(--primary-600));color:white;font-weight:700;">
          <i class="bi bi-check-circle"></i> Save Password
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Initial data from PHP ─────────────────────────────────────────────────────
let allDoctors = <?= json_encode($init_doctors) ?>;
let editingId  = 0;
let sortCol    = -1, sortAsc = true;
const speedLabels = {15:'Slow',25:'Normal',50:'Fast'};
const pauseLabels = {2000:'2 seconds',3000:'3 seconds',5000:'5 seconds',8000:'8 seconds',10000:'10 seconds'};

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, isError=false) {
    const w = document.getElementById('toast-wrap');
    const d = document.createElement('div');
    d.className = 'toast-msg' + (isError?' error':'');
    d.innerHTML = `<i class="bi ${isError?'bi-x-circle-fill':'bi-check-circle-fill'}"></i>${msg}`;
    w.appendChild(d);
    setTimeout(()=>d.remove(), 3000);
}

// ── AJAX helper ───────────────────────────────────────────────────────────────
async function post(data) {
    const fd = new FormData();
    for(const k in data) fd.append(k, data[k]);
    const r = await fetch(window.location.pathname, {method:'POST', body:fd});
    return r.json();
}

// ── Render table ──────────────────────────────────────────────────────────────
function renderTable(doctors) {
    const tbody = document.getElementById('doctor-tbody');
    if (!doctors.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No doctors found</td></tr>';
        return;
    }
    tbody.innerHTML = doctors.map(d => {
        const rd = d.resume_date
            ? `${fmtDate(d.resume_date)}${d.is_tentative?'<span class="tentative-badge">TENTATIVE</span>':''}`
            : '—';
        const rm = (d.remarks && d.label==='On Leave') ? escH(d.remarks) : '<span style="color:#bbb;">—</span>';
        return `<tr
            data-id="${d.id}"
            data-name="${escH(d.name.toLowerCase())}"
            data-dept="${escH((d.department||'').toLowerCase())}"
            data-status="${escH(d.label)}"
            data-date="${escH(d.resume_date||'')}"
            data-leave="${escH(d.remarks||'')}">
            <td><input type="checkbox" class="doctor-checkbox" value="${d.id}" onchange="updateDeleteBtn()"></td>
            <td>${escH(d.name)}</td>
            <td>${escH(d.department||'')}</td>
            <td><span class="status-badge ${d.badge}">${escH(d.label)}</span></td>
            <td>${rd}</td>
            <td>${rm}</td>
            <td>
                <button class="btn-action btn-edit" onclick="openEditModal(${d.id})">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn-action btn-delete" onclick="deleteOne(${d.id}, this)">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </td>
        </tr>`;
    }).join('');
    filterTable();
    updateDeleteBtn();
}

function fmtDate(s) {
    if(!s) return '—';
    const [y,m,d] = s.split('-').map(Number);
    return new Date(y,m-1,d).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'2-digit'});
}
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Sort ──────────────────────────────────────────────────────────────────────
function sortTable(col) {
    sortAsc = (sortCol===col) ? !sortAsc : true;
    sortCol = col;
    document.querySelectorAll('.sortable').forEach(th => {
        const ic = th.querySelector('.sort-icon');
        th.classList.remove('sort-asc','sort-desc');
        if(ic) ic.className='bi bi-arrow-down-up sort-icon';
        if(+th.dataset.col===col){
            th.classList.add(sortAsc?'sort-asc':'sort-desc');
            if(ic) ic.className=sortAsc?'bi bi-arrow-up sort-icon':'bi bi-arrow-down sort-icon';
        }
    });
    const colKeys = [null,'name','department','label','resume_date','remarks'];
    const key = colKeys[col];
    allDoctors.sort((a,b)=>{
        const va=(a[key]||'').toLowerCase(), vb=(b[key]||'').toLowerCase();
        return sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    renderTable(allDoctors);
}

// ── Filter ────────────────────────────────────────────────────────────────────
function filterTable() {
    const fname  = (document.getElementById('global-search')?.value||'').toLowerCase().trim();
    const fdept  = (document.getElementById('f-dept')?.value||'').toLowerCase().trim();
    const fstatus= (document.getElementById('f-status')?.value||'').trim();

    document.querySelectorAll('#doctor-tbody tr').forEach(row => {
        if (!row.dataset.id) { row.style.display = ''; return; }
        const name   = (row.dataset.name  ||'');   // already lowercased
        const dept   = (row.dataset.dept  ||'');   // already lowercased
        const status = (row.dataset.status||'');   // e.g. "Available", "No Clinic"

        const show =
            (!fname   || name.includes(fname))  &&
            (!fdept   || dept.includes(fdept))  &&
            (!fstatus || status === fstatus);

        row.style.display = show ? '' : 'none';
    });
}
function clearAllFilters() {
    const gs = document.getElementById('global-search'); if(gs) gs.value='';
    const fd = document.getElementById('f-dept');        if(fd) fd.value='';
    const fs = document.getElementById('f-status');      if(fs) fs.value='';
    filterTable();
}

// ── Doctor modal ──────────────────────────────────────────────────────────────
function toggleLeaveFields() {
    const isLeave = document.getElementById('m-status').value==='On Leave';
    document.querySelectorAll('.leave-fields').forEach(e=>e.style.display=isLeave?'':'none');
    const rm=document.getElementById('m-remarks'), rh=document.getElementById('remarks-hint');
    rm.disabled=!isLeave;
    if(isLeave){
        rh.className='remarks-hint hint-active';
        rh.innerHTML='<i class="bi bi-info-circle"></i> Select the reason for leave';
    } else {
        rm.value='';
        rh.className='remarks-hint hint-disabled';
        rh.innerHTML='<i class="bi bi-lock"></i> Only available when status is <strong>On Leave</strong>';
    }
}

function showDoctorModal() {
    editingId=0;
    document.getElementById('doctorModalTitle').innerHTML='<i class="bi bi-plus-circle"></i> Add New Doctor';
    document.getElementById('m-name').value='';
    document.getElementById('m-dept').value='';
    document.getElementById('m-status').value='On Schedule';
    document.getElementById('m-resume').value='';
    document.getElementById('m-tentative').checked=false;
    document.getElementById('m-remarks').value='';
    document.getElementById('form-error').style.display='none';
    toggleLeaveFields();
    new bootstrap.Modal(document.getElementById('doctorModal')).show();
}

function openEditModal(id) {
    const d = allDoctors.find(x=>x.id==id);
    if(!d) return;
    editingId=id;

    // Normalize DB status value to match <option value> exactly
    const statusMap = {
        'on schedule':'On Schedule', 'available':'On Schedule',
        'no medical':'No Medical',   'no clinic':'No Medical', 'not available':'No Medical',
        'on leave':'On Leave'
    };
    const rawStatus = (d.status||'').toLowerCase().trim();
    const mappedStatus = statusMap[rawStatus] || 'On Schedule';

    document.getElementById('doctorModalTitle').innerHTML='<i class="bi bi-pencil"></i> Edit Doctor Information';
    document.getElementById('m-name').value=d.name;
    document.getElementById('m-dept').value=d.department||'';
    document.getElementById('m-status').value=mappedStatus;
    document.getElementById('m-resume').value=d.resume_date||'';
    document.getElementById('m-tentative').checked=d.is_tentative==1;
    document.getElementById('form-error').style.display='none';
    toggleLeaveFields();
    document.getElementById('m-remarks').value=d.remarks||'';
    new bootstrap.Modal(document.getElementById('doctorModal')).show();
}

async function saveDoctor() {
    const name   = document.getElementById('m-name').value.trim();
    const dept   = document.getElementById('m-dept').value;
    const status = document.getElementById('m-status').value;
    const resume = document.getElementById('m-resume').value;
    const tent   = document.getElementById('m-tentative').checked ? 1 : 0;
    const remarks= document.getElementById('m-remarks').value;
    const errEl  = document.getElementById('form-error');
    errEl.style.display='none';

    if(!name||!dept||!status){ errEl.textContent='Name, department and status are required.'; errEl.style.display='block'; return; }
    if(status==='On Leave'){
        if(!resume){ errEl.textContent='Resume date is required.'; errEl.style.display='block'; return; }
        if(!remarks){ errEl.textContent='Please select a leave type.'; errEl.style.display='block'; return; }
    }

    const res = await post({ajax:'save_doctor', id:editingId, name, department:dept, status, resume_date:resume, remarks, is_tentative:tent?'1':''});
    if(!res.ok){ errEl.textContent=res.error; errEl.style.display='block'; return; }

    bootstrap.Modal.getInstance(document.getElementById('doctorModal'))?.hide();

    if(res.insert) {
        allDoctors.push(res.doctor);
    } else {
        const idx=allDoctors.findIndex(x=>x.id==res.doctor.id);
        if(idx>-1) allDoctors[idx]=res.doctor;
    }
    renderTable(allDoctors);
    toast(res.insert ? 'Doctor added successfully!' : 'Doctor updated successfully!');
}

// ── Delete one ────────────────────────────────────────────────────────────────
async function deleteOne(id, btn) {
    if(!confirm('Delete this doctor?')) return;
    const res = await post({ajax:'delete_doctor', id});
    if(!res.ok){ toast(res.error||'Delete failed',true); return; }
    allDoctors = allDoctors.filter(x=>x.id!=id);
    const row = btn.closest('tr');
    row.style.transition='opacity .3s';
    row.style.opacity='0';
    setTimeout(()=>renderTable(allDoctors), 300);
    toast('Doctor deleted.');
}

// ── Select all / bulk delete ──────────────────────────────────────────────────
function toggleSelectAll() {
    const all = document.getElementById('select-all').checked;
    document.querySelectorAll('.doctor-checkbox').forEach(cb => cb.checked = all);
    updateDeleteBtn();
}
function updateDeleteBtn() {
    const n = document.querySelectorAll('.doctor-checkbox:checked').length;
    const btn = document.getElementById('delete-selected-btn');
    btn.style.display = n>0 ? 'inline-block' : 'none';
    if(n>0) btn.innerHTML = `<i class="bi bi-trash"></i> Delete Selected (${n})`;
    document.getElementById('select-all').checked =
        n>0 && n===document.querySelectorAll('.doctor-checkbox').length;
}
async function deleteSelected() {
    const ids = Array.from(document.querySelectorAll('.doctor-checkbox:checked')).map(c=>c.value);
    if(!ids.length){ toast('No doctors selected',true); return; }
    if(!confirm(`Delete ${ids.length} doctor(s)?`)) return;
    const res = await post({ajax:'delete_selected', ids:JSON.stringify(ids)});
    if(!res.ok){ toast('Delete failed',true); return; }
    allDoctors = allDoctors.filter(x=>!ids.includes(String(x.id)));
    renderTable(allDoctors);
    toast(`${ids.length} doctor(s) deleted.`);
}

// ── Delete all ────────────────────────────────────────────────────────────────
function showDeleteAllModal() {
    document.getElementById('confirm-input').value='';
    document.getElementById('confirm-delete-btn').disabled=true;
    new bootstrap.Modal(document.getElementById('deleteAllModal')).show();
}
document.getElementById('confirm-input').addEventListener('input',function(){
    document.getElementById('confirm-delete-btn').disabled = this.value!=='DELETE ALL';
});
async function confirmDeleteAll() {
    if(document.getElementById('confirm-input').value!=='DELETE ALL') return;
    if(!confirm('Are you absolutely sure?')) return;
    const res = await post({ajax:'delete_all'});
    bootstrap.Modal.getInstance(document.getElementById('deleteAllModal'))?.hide();
    if(!res.ok){ toast('Delete failed',true); return; }
    allDoctors=[];
    renderTable([]);
    toast('All doctors deleted.');
}

// ── Display settings ──────────────────────────────────────────────────────────
async function saveDisplaySettings() {
    const spd = document.getElementById('set-speed').value;
    const top = document.getElementById('set-top').value;
    const bot = document.getElementById('set-bot').value;
    const res = await post({ajax:'save_display', scroll_speed:spd, pause_at_top:top, pause_at_bottom:bot});
    if(!res.ok){ toast('Failed to save settings',true); return; }
    document.getElementById('sum-speed').textContent = speedLabels[spd]||spd+' px/s';
    document.getElementById('sum-top').textContent   = pauseLabels[top]||(top/1000)+' sec';
    document.getElementById('sum-bot').textContent   = pauseLabels[bot]||(bot/1000)+' sec';
    toast('Display settings saved!');
}

// ── Change password ───────────────────────────────────────────────────────────
function showChangePasswordModal(){
    document.getElementById('pw_current').value='';
    document.getElementById('pw_new').value='';
    document.getElementById('pw_confirm').value='';
    const a=document.getElementById('cp-alert'); a.style.display='none';
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}
async function savePassword(){
    const cur=document.getElementById('pw_current').value;
    const np =document.getElementById('pw_new').value;
    const cp =document.getElementById('pw_confirm').value;
    const a  =document.getElementById('cp-alert');
    a.style.display='none';
    const res=await post({ajax:'change_password',current_or_key:cur,new_password:np,confirm_password:cp});
    if(!res.ok){
        a.className='alert alert-danger py-2'; a.style.display='block';
        a.innerHTML=`<i class="bi bi-exclamation-triangle-fill"></i> ${res.error}`;
        return;
    }
    a.className='alert alert-success py-2'; a.style.display='block';
    a.innerHTML=`<i class="bi bi-check-circle-fill"></i> ${res.message}`;
    setTimeout(()=>bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'))?.hide(),1500);
    toast('Password changed!');
}

// ── Profile dropdown ──────────────────────────────────────────────────────────
const profileMenu=document.getElementById('profileMenu');
function toggleProfileMenu(){ profileMenu.classList.toggle('open'); }
document.addEventListener('click',e=>{
    const d=document.getElementById('profileDropdown');
    if(d&&!d.contains(e.target)) profileMenu.classList.remove('open');
});

// ── Collapsible ───────────────────────────────────────────────────────────────
function toggleSection(id){
    const c=document.getElementById(id), h=c.previousElementSibling;
    c.classList.toggle('active'); h.classList.toggle('active');
}
document.addEventListener('DOMContentLoaded',()=>toggleSection('display-settings'));

// ── Password eye toggle ───────────────────────────────────────────────────────
function togglePw(id,btn){
    const inp=document.getElementById(id), ic=btn.querySelector('i');
    if(inp.type==='password'){ inp.type='text'; ic.className='bi bi-eye-slash'; }
    else { inp.type='password'; ic.className='bi bi-eye'; }
}

// ── Init ──────────────────────────────────────────────────────────────────────
renderTable(allDoctors);
</script>
</body>
</html>