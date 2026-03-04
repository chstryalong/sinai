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

// ── Fix ENUM column ───────────────────────────────────────────────────────────
$col_type = $conn->query("SHOW COLUMNS FROM doctors LIKE 'status'")->fetch_assoc();
if ($col_type && strpos(strtolower($col_type['Type']), 'enum') !== false)
    $conn->query("ALTER TABLE doctors MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'No Medical'");

$conn->query("UPDATE doctors SET status='On Schedule' WHERE status IN ('Available')");
$conn->query("UPDATE doctors SET status='No Medical'  WHERE status IN ('Not Available','No Clinic','')");

foreach (['remarks TEXT NULL','appt_start TIME NULL','appt_end TIME NULL','is_tentative TINYINT(1) DEFAULT 0'] as $col_def) {
    $col_name = explode(' ', $col_def)[0];
    if (!$conn->query("SHOW COLUMNS FROM doctors LIKE '$col_name'")->fetch_assoc())
        $conn->query("ALTER TABLE doctors ADD COLUMN $col_def");
}

// ── Users table + superadmin flag ─────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_superadmin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// Add is_superadmin column if missing
if (!$conn->query("SHOW COLUMNS FROM users LIKE 'is_superadmin'")->fetch_assoc())
    $conn->query("ALTER TABLE users ADD COLUMN is_superadmin TINYINT(1) DEFAULT 0");
// Add created_at column if missing (existing installs without it)
if (!$conn->query("SHOW COLUMNS FROM users LIKE 'created_at'")->fetch_assoc())
    $conn->query("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
// First user ever = superadmin (subquery alias avoids MySQL restriction)
$conn->query("UPDATE users SET is_superadmin=1 WHERE id=(SELECT id FROM (SELECT MIN(id) as id FROM users) t)");

// Fetch current user AFTER column is guaranteed to exist
$me = $conn->query("SELECT * FROM users WHERE username='" . $conn->real_escape_string($_SESSION['admin']) . "'")->fetch_assoc();
$is_superadmin = !empty($me['is_superadmin']);

// ── Display settings ──────────────────────────────────────────────────────────
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
//  AJAX ENDPOINTS
// ════════════════════════════════════════════════════════════════════════════
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

if (isset($_GET['ajax']) && $_GET['ajax']==='doctors') {
    $res = $conn->query("SELECT * FROM doctors ORDER BY name ASC");
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $lb = doctorLabel($r['status']);
        $rows[] = ['id'=>$r['id'],'name'=>$r['name'],'department'=>$r['department'],
            'status'=>$r['status'],'label'=>$lb['label'],'badge'=>$lb['badge'],
            'resume_date'=>$r['resume_date'],'is_tentative'=>(int)($r['is_tentative']??0),
            'remarks'=>trim($r['remarks']??'')];
    }
    jsonOut(['ok'=>true,'doctors'=>$rows]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='save_doctor') {
    $id=$id=intval($_POST['id']??0); $name=trim($_POST['name']??''); $dept=trim($_POST['department']??'');
    $status=trim($_POST['status']??''); $resume=trim($_POST['resume_date']??'')?:null;
    $remarks=trim($_POST['remarks']??'')?:null; $is_tentative=isset($_POST['is_tentative'])?1:0;
    if (!$name||!$dept||!$status) jsonOut(['ok'=>false,'error'=>'Name, department and status are required.']);
    if ($status==='On Leave') {
        if (!$resume) jsonOut(['ok'=>false,'error'=>'Resume date is required for On Leave.']);
        $d=DateTime::createFromFormat('Y-m-d',$resume);
        if (!$d||$d->format('Y-m-d')!==$resume) jsonOut(['ok'=>false,'error'=>'Invalid resume date.']);
        $today=new DateTime(); $today->setTime(0,0,0);
        if ($d<$today) jsonOut(['ok'=>false,'error'=>'Resume date cannot be in the past.']);
        if (!$remarks) jsonOut(['ok'=>false,'error'=>'Please select a leave type.']);
    } else { $resume=null; $is_tentative=0; $remarks=null; }
    $null=null;
    if ($id===0) {
        $stmt=$conn->prepare("INSERT INTO doctors (name,department,status,resume_date,appt_start,appt_end,remarks,is_tentative) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssssi",$name,$dept,$status,$resume,$null,$null,$remarks,$is_tentative);
    } else {
        $stmt=$conn->prepare("UPDATE doctors SET name=?,department=?,status=?,resume_date=?,appt_start=?,appt_end=?,remarks=?,is_tentative=? WHERE id=?");
        $stmt->bind_param("sssssssii",$name,$dept,$status,$resume,$null,$null,$remarks,$is_tentative,$id);
    }
    $stmt->execute(); $newId=$id?:$conn->insert_id;
    $r=$conn->query("SELECT * FROM doctors WHERE id=$newId")->fetch_assoc();
    $lb=doctorLabel($r['status']);
    jsonOut(['ok'=>true,'doctor'=>['id'=>$r['id'],'name'=>$r['name'],'department'=>$r['department'],
        'status'=>$r['status'],'label'=>$lb['label'],'badge'=>$lb['badge'],
        'resume_date'=>$r['resume_date'],'is_tentative'=>(int)($r['is_tentative']??0),
        'remarks'=>trim($r['remarks']??'')],'insert'=>($id===0)]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='delete_doctor') {
    $id=intval($_POST['id']??0); if (!$id) jsonOut(['ok'=>false,'error'=>'Invalid id']);
    $stmt=$conn->prepare("DELETE FROM doctors WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute();
    jsonOut(['ok'=>true]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='delete_selected') {
    $ids=json_decode($_POST['ids']??'[]',true);
    foreach ($ids as $id) { $id=intval($id); $stmt=$conn->prepare("DELETE FROM doctors WHERE id=?"); $stmt->bind_param("i",$id); $stmt->execute(); }
    jsonOut(['ok'=>true]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='delete_all') {
    $conn->query("DELETE FROM doctors"); jsonOut(['ok'=>true]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='save_display') {
    $spd=max(5,min(100,intval($_POST['scroll_speed']??25)));
    $top=max(1000,min(10000,intval($_POST['pause_at_top']??3000)));
    $bot=max(1000,min(10000,intval($_POST['pause_at_bottom']??3000)));
    $conn->query("UPDATE display_settings SET scroll_speed=$spd,pause_at_top=$top,pause_at_bottom=$bot WHERE id=1");
    jsonOut(['ok'=>true]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='change_password') {
    $cur=trim($_POST['current_or_key']??''); $np=trim($_POST['new_password']??''); $cp=trim($_POST['confirm_password']??'');
    if (!$cur||!$np||!$cp) jsonOut(['ok'=>false,'error'=>'All fields are required.']);
    if ($np!==$cp) jsonOut(['ok'=>false,'error'=>'New passwords do not match.']);
    if (strlen($np)<6) jsonOut(['ok'=>false,'error'=>'Password must be at least 6 characters.']);
    $username=$_SESSION['admin'];
    $u=$conn->prepare("SELECT * FROM users WHERE username=?"); $u->bind_param("s",$username); $u->execute();
    $row=$u->get_result()->fetch_assoc();
    if (!$row||(md5($cur)!==$row['password']&&$cur!==MASTER_RESET_KEY)) jsonOut(['ok'=>false,'error'=>'Current password or master reset key is incorrect.']);
    $h=md5($np); $upd=$conn->prepare("UPDATE users SET password=? WHERE username=?"); $upd->bind_param("ss",$h,$username); $upd->execute();
    jsonOut(['ok'=>true,'message'=>'Password changed successfully!']);
}

// ── User management (superadmin only) ─────────────────────────────────────────
if (isset($_POST['ajax']) && $_POST['ajax']==='add_user') {
    if (!$is_superadmin) jsonOut(['ok'=>false,'error'=>'Access denied.']);
    $un=trim($_POST['username']??''); $pw=trim($_POST['password']??'');
    $role=intval($_POST['role']??0); // 1=Super Admin, 0=Admin
    if (!$un||!$pw) jsonOut(['ok'=>false,'error'=>'Username and password are required.']);
    if (strlen($pw)<6) jsonOut(['ok'=>false,'error'=>'Password must be at least 6 characters.']);
    $check=$conn->prepare("SELECT id FROM users WHERE username=?"); $check->bind_param("s",$un); $check->execute();
    if ($check->get_result()->fetch_assoc()) jsonOut(['ok'=>false,'error'=>'Username already exists.']);
    $h=md5($pw); $stmt=$conn->prepare("INSERT INTO users (username,password,is_superadmin) VALUES (?,?,?)");
    $stmt->bind_param("ssi",$un,$h,$role); $stmt->execute();
    jsonOut(['ok'=>true,'id'=>$conn->insert_id,'username'=>$un,'is_superadmin'=>$role]);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='edit_user') {
    if (!$is_superadmin) jsonOut(['ok'=>false,'error'=>'Access denied.']);
    $id=intval($_POST['id']??0);
    $un=trim($_POST['username']??'');
    $pw=trim($_POST['password']??'');
    $role=intval($_POST['role']??0);
    if (!$id||!$un) jsonOut(['ok'=>false,'error'=>'Username is required.']);
    // Check username not taken by another user
    $check=$conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
    $check->bind_param("si",$un,$id); $check->execute();
    if ($check->get_result()->fetch_assoc()) jsonOut(['ok'=>false,'error'=>'Username already taken by another account.']);
    // Prevent removing superadmin role from the only superadmin
    $target=$conn->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
    if (!$target) jsonOut(['ok'=>false,'error'=>'User not found.']);
    if ($target['is_superadmin']==1 && $role==0) {
        $otherSuper=$conn->query("SELECT COUNT(*) as c FROM users WHERE is_superadmin=1 AND id!=$id")->fetch_assoc();
        if ($otherSuper['c']==0) jsonOut(['ok'=>false,'error'=>'Cannot remove Super Admin role — there must be at least one Super Admin.']);
    }
    if ($pw!=='') {
        if (strlen($pw)<6) jsonOut(['ok'=>false,'error'=>'Password must be at least 6 characters.']);
        $h=md5($pw);
        $stmt=$conn->prepare("UPDATE users SET username=?,password=?,is_superadmin=? WHERE id=?");
        $stmt->bind_param("ssii",$un,$h,$role,$id);
    } else {
        $stmt=$conn->prepare("UPDATE users SET username=?,is_superadmin=? WHERE id=?");
        $stmt->bind_param("sii",$un,$role,$id);
    }
    $stmt->execute();
    jsonOut(['ok'=>true,'message'=>'User updated successfully.']);
}

if (isset($_POST['ajax']) && $_POST['ajax']==='delete_user') {
    if (!$is_superadmin) jsonOut(['ok'=>false,'error'=>'Access denied.']);
    $id=intval($_POST['id']??0);
    $target=$conn->query("SELECT * FROM users WHERE id=$id")->fetch_assoc();
    if (!$target) jsonOut(['ok'=>false,'error'=>'User not found.']);
    if ($target['is_superadmin']) jsonOut(['ok'=>false,'error'=>'Cannot delete the superadmin account.']);
    if ($target['username']===$_SESSION['admin']) jsonOut(['ok'=>false,'error'=>'Cannot delete your own account.']);
    $conn->query("DELETE FROM users WHERE id=$id");
    jsonOut(['ok'=>true]);
}

if (isset($_GET['ajax']) && $_GET['ajax']==='users') {
    if (!$is_superadmin) jsonOut(['ok'=>false,'error'=>'Access denied.']);
    // Add created_at column if it doesn't exist (existing installs won't have it)
    if (!$conn->query("SHOW COLUMNS FROM users LIKE 'created_at'")->fetch_assoc())
        $conn->query("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    $res=$conn->query("SELECT id, username, is_superadmin, created_at FROM users ORDER BY id ASC");
    if (!$res) jsonOut(['ok'=>false,'error'=>$conn->error]);
    $users=[];
    while ($r=$res->fetch_assoc()) $users[]=$r;
    jsonOut(['ok'=>true,'users'=>$users]);
}

// ── Page data ─────────────────────────────────────────────────────────────────
$ds=$conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1")->fetch_assoc();
$speed_labels=[15=>'Slow',25=>'Normal',50=>'Fast'];
$pause_labels=[2000=>'2 seconds',3000=>'3 seconds',5000=>'5 seconds',8000=>'8 seconds',10000=>'10 seconds'];
$cs=$ds['scroll_speed']??25; $ct=$ds['pause_at_top']??3000; $cb=$ds['pause_at_bottom']??3000;
$csl=$speed_labels[$cs]??$cs.' px/s'; $ctl=$pause_labels[$ct]??($ct/1000).' sec'; $cbl=$pause_labels[$cb]??($cb/1000).' sec';
$leave_types=['On Vacation','Personal','Sick Leave'];

$init_res=$conn->query("SELECT * FROM doctors ORDER BY name ASC");
$init_doctors=[];
while ($r=$init_res->fetch_assoc()) {
    $lb=doctorLabel($r['status']);
    $init_doctors[]=['id'=>$r['id'],'name'=>$r['name'],'department'=>$r['department'],
        'status'=>$r['status'],'label'=>$lb['label'],'badge'=>$lb['badge'],
        'resume_date'=>$r['resume_date'],'is_tentative'=>(int)($r['is_tentative']??0),'remarks'=>trim($r['remarks']??'')];
}

// Stats
$available = count(array_filter($init_doctors, fn($d)=>$d['label']==='Available'));
$noclinic  = count(array_filter($init_doctors, fn($d)=>$d['label']==='No Clinic'));
$onleave   = count(array_filter($init_doctors, fn($d)=>$d['label']==='On Leave'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Sinai MDI Hospital – Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #0052CC;
    --primary-light: #1e88e5;
    --primary-dark: #003d99;
    --accent: #ffc107;
    --success: #22c55e;
    --danger: #ef4444;
    --warning: #f59e0b;
    --sidebar-w: 260px;
    --sidebar-collapsed: 68px;
    --bg: #f0f4f8;
    --surface: #ffffff;
    --border: #e2e8f0;
    --text: #0f172a;
    --muted: #64748b;
    --radius: 12px;
    --transition: 0.22s cubic-bezier(0.4,0,0.2,1);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; overflow-x: hidden; }

/* ── SIDEBAR ── */
.sidebar {
    width: var(--sidebar-w);
    min-height: 100vh;
    background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 60%, var(--primary-light) 100%);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0;
    z-index: 200;
    transition: width var(--transition);
    overflow: hidden;
    box-shadow: 4px 0 24px rgba(0,52,153,0.18);
}
.sidebar.collapsed { width: var(--sidebar-collapsed); }

/* Brand */
.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 16px 20px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.12);
    min-height: 80px;
    overflow: hidden;
    white-space: nowrap;
}
.sidebar-logo {
    width: 38px; height: 38px;
    border-radius: 10px;
    object-fit: cover;
    flex-shrink: 0;
    background: rgba(255,255,255,0.15);
    padding: 2px;
}
.sidebar-brand-text {
    display: flex;
    flex-direction: column;
    opacity: 1;
    transition: opacity var(--transition);
    overflow: hidden;
}
.sidebar.collapsed .sidebar-brand-text { opacity: 0; pointer-events: none; }
.brand-name { font-size: 13px; font-weight: 800; color: white; line-height: 1.2; }
.brand-sub  { font-size: 10px; font-weight: 500; color: rgba(255,255,255,0.65); margin-top: 2px; }

/* Nav */
.sidebar-nav { flex: 1; padding: 16px 8px; display: flex; flex-direction: column; gap: 4px; overflow: hidden; }
.nav-section-label {
    font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.4);
    text-transform: uppercase; letter-spacing: 1px;
    padding: 8px 10px 4px;
    white-space: nowrap;
    overflow: hidden;
    opacity: 1;
    transition: opacity var(--transition);
}
.sidebar.collapsed .nav-section-label { opacity: 0; }
.nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 12px;
    border-radius: 10px;
    cursor: pointer;
    color: rgba(255,255,255,0.75);
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    transition: all var(--transition);
    white-space: nowrap;
    overflow: hidden;
    position: relative;
}
.nav-item:hover { background: rgba(255,255,255,0.12); color: white; }
.nav-item.active { background: rgba(255,255,255,0.2); color: white; }
.nav-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 20%; height: 60%;
    width: 3px;
    background: var(--accent);
    border-radius: 0 3px 3px 0;
}
.nav-item i { font-size: 18px; flex-shrink: 0; width: 22px; text-align: center; }
.nav-label { opacity: 1; transition: opacity var(--transition); }
.sidebar.collapsed .nav-label { opacity: 0; }

/* Toggle button */
.sidebar-toggle {
    margin: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px; height: 36px;
    border-radius: 8px;
    border: none;
    background: rgba(255,255,255,0.12);
    color: white;
    cursor: pointer;
    font-size: 16px;
    align-self: flex-end;
    transition: background var(--transition);
    flex-shrink: 0;
}
.sidebar-toggle:hover { background: rgba(255,255,255,0.22); }

/* Sidebar footer */
.sidebar-footer {
    padding: 12px 8px;
    border-top: 1px solid rgba(255,255,255,0.12);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* ── MAIN CONTENT ── */
.main-content {
    margin-left: var(--sidebar-w);
    flex: 1;
    min-height: 100vh;
    transition: margin-left var(--transition);
    display: flex;
    flex-direction: column;
}
.main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed); }

/* Top bar */
.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 1px 8px rgba(0,0,0,0.06);
}
.topbar-left { display: flex; align-items: center; gap: 12px; }
.page-title { font-size: 18px; font-weight: 700; color: var(--text); }
.page-breadcrumb { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 12px; }

/* User pill */
.user-pill {
    display: flex; align-items: center; gap: 10px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 40px;
    padding: 6px 14px 6px 6px;
    cursor: pointer;
    position: relative;
    transition: all var(--transition);
}
.user-pill:hover { border-color: var(--border); background: var(--bg); }
.user-avatar {
    width: 32px; height: 32px;
    background: linear-gradient(135deg,var(--primary),var(--primary-light));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-size: 14px; font-weight: 700;
}
.user-name { font-size: 13px; font-weight: 600; color: var(--text); }
.user-role { font-size: 10px; color: var(--muted); }

/* Profile dropdown */
.profile-dropdown { position: relative; }
.profile-menu {
    display: none;
    position: absolute; right: 0; top: calc(100% + 8px);
    background: white; border-radius: var(--radius);
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    min-width: 200px; z-index: 2000;
    border: 1px solid var(--border);
    overflow: hidden;
}
.profile-menu.open { display: block; animation: fadeDown .16s ease; }
@keyframes fadeDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.profile-menu-header { padding: 14px 16px; background: #f8faff; border-bottom: 1px solid var(--border); }
.profile-menu-header .pm-name { font-weight: 700; font-size: 14px; color: var(--primary); }
.profile-menu-header .pm-role { font-size: 11px; color: var(--muted); margin-top: 2px; }
.pm-item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; font-size: 13px; font-weight: 600;
    color: var(--text); cursor: pointer; border: none; background: none; width: 100%;
    transition: background var(--transition);
}
.pm-item:hover { background: #f8faff; }
.pm-item.danger { color: var(--danger); }
.pm-item.danger:hover { background: #fff5f5; }
.pm-divider { height: 1px; background: var(--border); }

/* ── PAGE ── */
.page { padding: 28px; flex: 1; display: none; }
.page.active { display: block; }

/* Dashboard cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 28px;
}
.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    gap: 18px;
    border: 1px solid var(--border);
    transition: all var(--transition);
    position: relative;
    overflow: hidden;
}
.stat-card::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}
.stat-card.available::after { background: linear-gradient(90deg,#22c55e,#16a34a); }
.stat-card.noclinic::after  { background: linear-gradient(90deg,#6b7280,#4b5563); }
.stat-card.onleave::after   { background: linear-gradient(90deg,#ef4444,#dc2626); }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
.stat-icon {
    width: 56px; height: 56px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; flex-shrink: 0;
}
.stat-card.available .stat-icon { background: rgba(34,197,94,0.12); color: #22c55e; }
.stat-card.noclinic  .stat-icon { background: rgba(107,114,128,0.12); color: #6b7280; }
.stat-card.onleave   .stat-icon { background: rgba(239,68,68,0.12); color: #ef4444; }
.stat-info { flex: 1; }
.stat-count { font-size: 36px; font-weight: 800; color: var(--text); line-height: 1; }
.stat-label { font-size: 13px; font-weight: 600; color: var(--muted); margin-top: 4px; }

/* Section card */
.section-card {
    background: var(--surface);
    border-radius: var(--radius);
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    border: 1px solid var(--border);
    margin-bottom: 24px;
    overflow: hidden;
}
.section-card-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.section-card-title {
    font-size: 16px; font-weight: 700; color: var(--text);
    display: flex; align-items: center; gap: 8px;
}
.section-card-title i { color: var(--primary); }
.section-card-body { padding: 24px; }

/* Collapsible settings */
.settings-toggle-btn {
    background: none; border: 1px solid var(--border); color: var(--muted);
    border-radius: 8px; padding: 6px 14px; font-size: 13px; font-weight: 600;
    cursor: pointer; display: flex; align-items: center; gap: 6px;
    transition: all var(--transition);
}
.settings-toggle-btn:hover { border-color: var(--primary); color: var(--primary); background: #f0f4ff; }
.settings-panel { display: none; }
.settings-panel.open { display: block; }

/* Table */
.table-responsive { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead th {
    background: #f8faff; color: var(--muted);
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
    padding: 12px 16px; border-bottom: 2px solid var(--border);
    white-space: nowrap; cursor: pointer; user-select: none;
}
.data-table thead th:hover { color: var(--primary); }
.data-table thead th.sortable-active { color: var(--primary); }
.data-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background var(--transition); }
.data-table tbody tr:hover { background: #f8faff; }
.data-table tbody td { padding: 14px 16px; font-size: 14px; vertical-align: middle; }
.table-empty { text-align: center; padding: 48px; color: var(--muted); }

/* Status badges */
.badge-status {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
}
.badge-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.badge-available { color: #16a34a; background: rgba(34,197,94,0.12); }
.badge-noclinic  { color: #6b7280; background: rgba(107,114,128,0.12); }
.badge-onleave   { color: #dc2626; background: rgba(239,68,68,0.12); }

/* Action buttons */
.btn-icon { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: all var(--transition); display: inline-flex; align-items: center; gap: 5px; }
.btn-edit-sm   { background: #eff6ff; color: var(--primary); }
.btn-edit-sm:hover   { background: var(--primary); color: white; }
.btn-delete-sm { background: #fff5f5; color: var(--danger); }
.btn-delete-sm:hover { background: var(--danger); color: white; }

/* Primary button */
.btn-primary-custom {
    background: linear-gradient(135deg,var(--primary),var(--primary-light));
    color: white; border: none; border-radius: 10px;
    padding: 10px 18px; font-size: 13px; font-weight: 700;
    cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
    transition: all var(--transition);
}
.btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,82,204,0.35); }
.btn-danger-custom {
    background: var(--danger); color: white; border: none; border-radius: 10px;
    padding: 10px 18px; font-size: 13px; font-weight: 700;
    cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
    transition: all var(--transition);
}
.btn-danger-custom:hover { background: #dc2626; transform: translateY(-2px); }

/* Search/filter bar */
.filter-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.filter-bar .form-control, .filter-bar .form-select {
    border: 1.5px solid var(--border); border-radius: 8px; padding: 8px 12px;
    font-size: 13px; font-family: inherit; outline: none;
    transition: border-color var(--transition);
}
.filter-bar .form-control:focus, .filter-bar .form-select:focus {
    border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,82,204,0.1);
}
.search-wrap { position: relative; }
.search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 14px; pointer-events: none; }
.search-wrap input { padding-left: 32px !important; }
.btn-clear { background: #f1f5f9; border: 1.5px solid var(--border); color: var(--muted); border-radius: 8px; padding: 8px 12px; font-size: 13px; cursor: pointer; transition: all var(--transition); }
.btn-clear:hover { border-color: var(--danger); color: var(--danger); background: #fff5f5; }

/* Tentative badge */
.tentative-pill { display: inline-block; background: rgba(245,158,11,0.15); color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; margin-left: 6px; border: 1px dashed rgba(245,158,11,0.5); }

/* Form inputs in modal */
.modal .form-control, .modal .form-select {
    border: 1.5px solid var(--border); border-radius: 8px;
    padding: 10px 14px; font-size: 14px; font-family: inherit; outline: none;
    transition: all var(--transition); width: 100%;
}
.modal .form-control:focus, .modal .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,82,204,0.1); }
.modal-title-bar {
    background: linear-gradient(135deg,var(--primary),var(--primary-light));
    color: white; padding: 18px 24px;
    display: flex; align-items: center; justify-content: space-between;
}
.modal-title-bar h5 { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.modal-body { padding: 24px; }
.modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }
.form-label-custom { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block; }
.form-select:disabled { background: #f8faff; opacity: 0.6; cursor: not-allowed; }
.remarks-hint { font-size: 11px; margin-top: 4px; }
.hint-locked { color: #aaa; }
.hint-active { color: var(--primary); }

/* Password toggle */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 40px !important; }
.pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #aaa; cursor: pointer; font-size: 16px; transition: color .15s; }
.pw-eye:hover { color: var(--primary); }

/* Settings grid */
.settings-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap: 16px; margin-bottom: 16px; }
.setting-item label { font-size: 12px; font-weight: 700; color: var(--muted); display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.setting-select {
    width: 100%; border: 1.5px solid var(--border); border-radius: 8px; padding: 10px 14px;
    font-size: 14px; font-weight: 600; color: var(--primary); background: white;
    appearance: none; cursor: pointer; font-family: inherit; outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%230052CC' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px;
    transition: border-color var(--transition);
}
.setting-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,82,204,0.1); }
.setting-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }
.summary-row { display: flex; flex-wrap: wrap; gap: 20px; align-items: center; padding: 14px 18px; background: #f0f4ff; border-radius: 10px; border-left: 3px solid var(--primary); margin-top: 16px; }
.summary-item .s-lbl { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
.summary-item .s-val { font-size: 18px; font-weight: 800; color: var(--primary); }

/* Toast */
.toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.toast-msg { background: white; border-radius: 10px; padding: 12px 18px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px; animation: slideUp .22s ease; border-left: 4px solid var(--success); font-family: inherit; }
.toast-msg.err { border-left-color: var(--danger); }
@keyframes slideUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

/* User management */
.user-row-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
.badge-superadmin { background: rgba(245,158,11,0.15); color: #92400e; }
.badge-admin { background: rgba(0,82,204,0.1); color: var(--primary); }

@media (max-width: 768px) {
    .sidebar { width: var(--sidebar-collapsed); }
    .main-content { margin-left: var(--sidebar-collapsed); }
    .stats-grid { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .page { padding: 16px; }
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══════════════════════════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../display/assets/logo2.png" alt="Logo" class="sidebar-logo">
        <div class="sidebar-brand-text">
            <span class="brand-name">New Sinai MDI</span>
            <span class="brand-sub">Hospital Admin</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <span class="nav-section-label">Main</span>
        <a class="nav-item active" id="nav-dashboard" onclick="showPage('dashboard')">
            <i class="bi bi-grid-1x2-fill"></i>
            <span class="nav-label">Dashboard</span>
        </a>
        <a class="nav-item" id="nav-doctors" onclick="showPage('doctors')">
            <i class="bi bi-person-lines-fill"></i>
            <span class="nav-label">Doctor List</span>
        </a>
        <?php if ($is_superadmin): ?>
        <span class="nav-section-label">Administration</span>
        <a class="nav-item" id="nav-users" onclick="showPage('users')">
            <i class="bi bi-people-fill"></i>
            <span class="nav-label">User Accounts</span>
        </a>
        <?php endif; ?>
        <span class="nav-section-label">Settings</span>
        <a class="nav-item" id="nav-display" onclick="showPage('display')">
            <i class="bi bi-display"></i>
            <span class="nav-label">Display Settings</span>
        </a>
        <a class="nav-item" href="../display/index.php" target="_blank">
            <i class="bi bi-tv"></i>
            <span class="nav-label">Open Display</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a class="nav-item" onclick="showLogoutModal()">
            <i class="bi bi-box-arrow-right"></i>
            <span class="nav-label">Logout</span>
        </a>
        <button class="sidebar-toggle" onclick="toggleSidebar()" title="Toggle sidebar">
            <i class="bi bi-chevron-left" id="toggle-icon"></i>
        </button>
    </div>
</aside>

<!-- ══ MAIN ═════════════════════════════════════════════════════════════════ -->
<div class="main-content" id="mainContent">

    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <div>
                <div class="page-title" id="topbar-title">Dashboard</div>
                <div class="page-breadcrumb" id="topbar-sub">Overview of doctor availability</div>
            </div>
        </div>
        <div class="topbar-right">
            <div class="user-pill" style="cursor:default;">
                <div class="user-avatar"><?= strtoupper(substr($_SESSION['admin'],0,1)) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($_SESSION['admin']) ?></div>
                    <div class="user-role"><?= $is_superadmin ? 'Super Admin' : 'Admin' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── DASHBOARD PAGE ── -->
    <div class="page active" id="page-dashboard">
        <div class="stats-grid">
            <div class="stat-card available">
                <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-count" id="count-available"><?= $available ?></div>
                    <div class="stat-label">Available Today</div>
                </div>
            </div>
            <div class="stat-card noclinic">
                <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-count" id="count-noclinic"><?= $noclinic ?></div>
                    <div class="stat-label">No Clinic Today</div>
                </div>
            </div>
            <div class="stat-card onleave">
                <div class="stat-icon"><i class="bi bi-calendar-x-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-count" id="count-onleave"><?= $onleave ?></div>
                    <div class="stat-label">On Leave</div>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title"><i class="bi bi-clock-history"></i> Recent Doctor List</div>
                <button class="btn-primary-custom" onclick="showPage('doctors')">
                    <i class="bi bi-arrow-right"></i> View All
                </button>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Resume Date</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-tbody">
                        <tr><td colspan="4" class="table-empty">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── DOCTORS PAGE ── -->
    <div class="page" id="page-doctors">
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title"><i class="bi bi-person-lines-fill"></i> Doctor List</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <div class="filter-bar">
                        <div class="search-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" id="global-search" placeholder="Search by name…" oninput="filterTable()" style="width:200px;">
                        </div>
                        <select id="f-dept" class="form-select" onchange="filterTable()" style="width:150px;">
                            <option value="">All Departments</option>
                            <option>OPD</option><option>ER</option><option>Pediatrics</option>
                            <option>Cardiology</option><option>Radiology</option><option>Laboratory</option>
                        </select>
                        <select id="f-status" class="form-select" onchange="filterTable()" style="width:140px;">
                            <option value="">All Status</option>
                            <option value="Available">Available</option>
                            <option value="No Clinic">No Clinic</option>
                            <option value="On Leave">On Leave</option>
                        </select>
                        <button class="btn-clear" onclick="clearAllFilters()"><i class="bi bi-x-lg"></i></button>
                    </div>
                </div>
            </div>
            <div class="section-card-header" style="border-top:none;padding-top:0;justify-content:flex-end;">
                <div style="display:flex;gap:8px;">
                    <button class="btn-primary-custom" onclick="showDoctorModal()">
                        <i class="bi bi-plus-lg"></i> Add Doctor
                    </button>
                    <button class="btn-icon btn-delete-sm" id="delete-selected-btn" onclick="deleteSelected()" style="display:none;padding:10px 14px;">
                        <i class="bi bi-trash"></i> Delete Selected
                    </button>
                    <button class="btn-danger-custom" onclick="showDeleteAllModal()">
                        <i class="bi bi-trash3"></i> Delete All
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table" id="doctor-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="select-all" onclick="toggleSelectAll()"></th>
                            <th class="sortable" onclick="sortTable(1)">Name <i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.5;" id="si-1"></i></th>
                            <th class="sortable" onclick="sortTable(2)">Department <i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.5;" id="si-2"></i></th>
                            <th class="sortable" onclick="sortTable(3)">Status <i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.5;" id="si-3"></i></th>
                            <th>Resume Date</th>
                            <th>Leave Type</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="doctor-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── DISPLAY SETTINGS PAGE ── -->
    <div class="page" id="page-display">
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title"><i class="bi bi-display"></i> Display Scroll Settings</div>
            </div>
            <div class="section-card-body">
                <div class="settings-grid">
                    <div>
                        <label>Scroll Speed</label>
                        <select id="set-speed" class="setting-select">
                            <option value="15" <?= $cs==15?'selected':'' ?>>🐢 Slow</option>
                            <option value="25" <?= $cs==25?'selected':'' ?>>👍 Normal</option>
                            <option value="50" <?= $cs==50?'selected':'' ?>>⚡ Fast</option>
                        </select>
                        <div class="setting-hint">How quickly the list moves on screen</div>
                    </div>
                    <div>
                        <label>Wait Time at Top</label>
                        <select id="set-top" class="setting-select">
                            <option value="2000"  <?= $ct==2000 ?'selected':'' ?>>2 seconds</option>
                            <option value="3000"  <?= $ct==3000 ?'selected':'' ?>>3 seconds</option>
                            <option value="5000"  <?= $ct==5000 ?'selected':'' ?>>5 seconds</option>
                            <option value="8000"  <?= $ct==8000 ?'selected':'' ?>>8 seconds</option>
                            <option value="10000" <?= $ct==10000?'selected':'' ?>>10 seconds</option>
                        </select>
                        <div class="setting-hint">Pause before scrolling down</div>
                    </div>
                    <div>
                        <label>Wait Time at Bottom</label>
                        <select id="set-bot" class="setting-select">
                            <option value="2000"  <?= $cb==2000 ?'selected':'' ?>>2 seconds</option>
                            <option value="3000"  <?= $cb==3000 ?'selected':'' ?>>3 seconds</option>
                            <option value="5000"  <?= $cb==5000 ?'selected':'' ?>>5 seconds</option>
                            <option value="8000"  <?= $cb==8000 ?'selected':'' ?>>8 seconds</option>
                            <option value="10000" <?= $cb==10000?'selected':'' ?>>10 seconds</option>
                        </select>
                        <div class="setting-hint">Pause before scrolling back up</div>
                    </div>
                </div>
                <button class="btn-primary-custom" onclick="saveDisplaySettings()">
                    <i class="bi bi-save"></i> Save Settings
                </button>
                <div class="summary-row mt-3">
                    <strong style="font-size:13px;color:var(--muted);">Currently Active:</strong>
                    <div class="summary-item"><div class="s-lbl">Speed</div><div class="s-val" id="sum-speed"><?= htmlspecialchars($csl) ?></div></div>
                    <div class="summary-item"><div class="s-lbl">Pause Top</div><div class="s-val" id="sum-top"><?= htmlspecialchars($ctl) ?></div></div>
                    <div class="summary-item"><div class="s-lbl">Pause Bottom</div><div class="s-val" id="sum-bot"><?= htmlspecialchars($cbl) ?></div></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_superadmin): ?>
    <!-- ── USER ACCOUNTS PAGE ── -->
    <div class="page" id="page-users">
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title"><i class="bi bi-people-fill"></i> User Accounts</div>
                <button class="btn-primary-custom" onclick="showAddUserModal()">
                    <i class="bi bi-plus-lg"></i> Add User
                </button>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="users-tbody">
                        <tr><td colspan="5" class="table-empty">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /main-content -->

<!-- ══ MODALS ════════════════════════════════════════════════════════════════ -->

<!-- Add/Edit Doctor -->
<div class="modal fade" id="doctorModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
      <div class="modal-title-bar">
        <h5 id="doctorModalTitle"><i class="bi bi-plus-circle"></i> Add New Doctor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label-custom">Doctor Name *</label>
            <input type="text" class="form-control" id="m-name" placeholder="e.g. Dr. Juan Dela Cruz">
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">Department *</label>
            <select class="form-select" id="m-dept">
              <option value="">Select Department</option>
              <option>OPD</option><option>ER</option><option>Pediatrics</option>
              <option>Cardiology</option><option>Radiology</option><option>Laboratory</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">Status *</label>
            <select class="form-select" id="m-status" onchange="toggleLeaveFields()">
              <option value="On Schedule">Available</option>
              <option value="No Medical">No Clinic</option>
              <option value="On Leave">On Leave</option>
            </select>
          </div>
          <div class="col-md-6 leave-fields" style="display:none;">
            <label class="form-label-custom">Resume Date *</label>
            <input type="date" class="form-control" id="m-resume">
          </div>
          <div class="col-12 leave-fields" style="display:none;">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="m-tentative">
              <label class="form-check-label" for="m-tentative" style="font-size:13px;font-weight:600;">
                <i class="bi bi-calendar-question"></i> Resume date is tentative
              </label>
            </div>
          </div>
          <div class="col-12">
            <label class="form-label-custom">Leave Type</label>
            <select class="form-select" id="m-remarks" disabled>
              <option value="">— Select leave type —</option>
              <?php foreach($leave_types as $lt): ?>
                <option value="<?= htmlspecialchars($lt) ?>"><?= htmlspecialchars($lt) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="remarks-hint hint-locked" id="remarks-hint">
              <i class="bi bi-lock"></i> Only available when status is <strong>On Leave</strong>
            </div>
          </div>
        </div>
        <div id="form-error" class="mt-3" style="display:none;color:var(--danger);font-size:13px;font-weight:600;padding:10px 14px;background:#fff5f5;border-radius:8px;border-left:3px solid var(--danger);"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn-primary-custom" onclick="saveDoctor()">
          <i class="bi bi-check-circle"></i> Save Doctor
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Delete All -->
<div class="modal fade" id="deleteAllModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
      <div class="modal-title-bar" style="background:var(--danger);">
        <h5><i class="bi bi-exclamation-triangle"></i> Delete All Doctors</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:14px;color:var(--muted);margin-bottom:12px;"><strong style="color:var(--danger);">⚠️ Warning:</strong> This will permanently delete ALL doctors.</p>
        <p style="font-size:13px;margin-bottom:8px;">Type <strong>DELETE ALL</strong> to confirm:</p>
        <input type="text" id="confirm-input" class="form-control" placeholder="Type DELETE ALL">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn-danger-custom" id="confirm-delete-btn" disabled onclick="confirmDeleteAll()">Delete ALL</button>
      </div>
    </div>
  </div>
</div>

<!-- Change Password -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
      <div class="modal-title-bar">
        <h5><i class="bi bi-key-fill"></i> Change Password</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="cp-alert" style="display:none;" class="mb-3"></div>
        <div class="mb-3">
          <label class="form-label-custom">Current Password or Master Reset Key</label>
          <div class="pw-wrap">
            <input type="password" class="form-control" id="pw_current" placeholder="Current password or reset key" autocomplete="off">
            <button type="button" class="pw-eye" onclick="togglePw('pw_current',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
          <div style="font-size:11px;color:#aaa;margin-top:4px;"><i class="bi bi-info-circle"></i> Forgot password? Use the master reset key.</div>
        </div>
        <div class="mb-3">
          <label class="form-label-custom">New Password</label>
          <div class="pw-wrap">
            <input type="password" class="form-control" id="pw_new" placeholder="Min. 6 characters" autocomplete="new-password">
            <button type="button" class="pw-eye" onclick="togglePw('pw_new',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label-custom">Confirm New Password</label>
          <div class="pw-wrap">
            <input type="password" class="form-control" id="pw_confirm" placeholder="Repeat new password" autocomplete="new-password">
            <button type="button" class="pw-eye" onclick="togglePw('pw_confirm',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn-primary-custom" onclick="savePassword()">
          <i class="bi bi-check-circle"></i> Save Password
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($is_superadmin): ?>
<!-- Add User -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
      <div class="modal-title-bar">
        <h5 id="au-modal-title"><i class="bi bi-person-plus-fill"></i> Add User Account</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="au-alert" style="display:none;" class="mb-3"></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label-custom">Username</label>
            <input type="text" class="form-control" id="au-username" placeholder="Enter username" autocomplete="off">
          </div>
          <div class="col-md-6">
            <label class="form-label-custom" id="au-pw-label">Password</label>
            <div class="pw-wrap">
              <input type="password" class="form-control" id="au-password" placeholder="Min. 6 characters" autocomplete="new-password">
              <button type="button" class="pw-eye" onclick="togglePw('au-password',this)" tabindex="-1"><i class="bi bi-eye"></i></button>
            </div>
            <div id="au-pw-hint" style="display:none;font-size:11px;color:var(--muted);margin-top:4px;">
              <i class="bi bi-info-circle"></i> Leave blank to keep current password.
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">Role</label>
            <select class="form-select" id="au-role">
              <option value="0">Admin</option>
              <option value="1">Super Admin</option>
            </select>
            <div style="font-size:11px;color:var(--muted);margin-top:4px;"><i class="bi bi-info-circle"></i> Super Admin can manage user accounts and change passwords.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn-primary-custom" id="au-submit-btn" onclick="submitUserModal()">
          <i class="bi bi-check-circle"></i> Create Account
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Logout Confirmation -->
<div class="modal fade" id="logoutModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered" style="max-width:340px;">
    <div class="modal-content" style="border:none;border-radius:14px;overflow:hidden;">
      <div class="modal-title-bar" style="background:linear-gradient(135deg,#1e3a5f,#0052CC);">
        <h5><i class="bi bi-box-arrow-right"></i> Confirm Logout</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="text-align:center;padding:32px 24px;">
        <div style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:8px;">Are you sure you want to logout?</div>
        <div style="font-size:13px;color:var(--muted);">You will be redirected to the login page.</div>
      </div>
      <div class="modal-footer" style="justify-content:center;gap:10px;padding-bottom:24px;border:none;">
        <a href="logout.php" class="btn-danger-custom" style="padding:8px 22px;font-size:13px;text-decoration:none;">
          <i class="bi bi-box-arrow-right"></i> Yes, Logout
        </a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="padding:8px 22px;border-radius:10px;font-weight:600;font-size:13px;">
          Cancel
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Data ──────────────────────────────────────────────────────────────────────
let allDoctors = <?= json_encode($init_doctors) ?>;
let editingId  = 0, sortCol = -1, sortAsc = true;
const IS_SUPERADMIN = <?= $is_superadmin ? 'true' : 'false' ?>;
const speedLabels = {15:'Slow',25:'Normal',50:'Fast'};
const pauseLabels = {2000:'2 seconds',3000:'3 seconds',5000:'5 seconds',8000:'8 seconds',10000:'10 seconds'};

// ── Sidebar toggle ────────────────────────────────────────────────────────────
let sidebarCollapsed = false;
function toggleSidebar() {
    sidebarCollapsed = !sidebarCollapsed;
    document.getElementById('sidebar').classList.toggle('collapsed', sidebarCollapsed);
    document.getElementById('mainContent').classList.toggle('sidebar-collapsed', sidebarCollapsed);
    document.getElementById('toggle-icon').className = sidebarCollapsed ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
}

// ── Page navigation ───────────────────────────────────────────────────────────
const pageMeta = {
    dashboard: { title: 'Dashboard',        sub: 'Overview of doctor availability' },
    doctors:   { title: 'Doctor List',      sub: 'Manage all doctor records' },
    display:   { title: 'Display Settings', sub: 'Configure TV display scroll behavior' },
    users:     { title: 'User Accounts',    sub: 'Manage admin user accounts' },
};
function showPage(page) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    const pg = document.getElementById('page-' + page);
    if (pg) pg.classList.add('active');
    const nav = document.getElementById('nav-' + page);
    if (nav) nav.classList.add('active');
    const m = pageMeta[page] || {};
    document.getElementById('topbar-title').textContent = m.title || page;
    document.getElementById('topbar-sub').textContent   = m.sub   || '';
    if (page === 'users') loadUsers();
    if (page === 'dashboard') updateDashboard();
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, isErr=false) {
    const w=document.getElementById('toast-wrap');
    const d=document.createElement('div');
    d.className='toast-msg'+(isErr?' err':'');
    d.innerHTML=`<i class="bi ${isErr?'bi-x-circle-fill':'bi-check-circle-fill'}" style="color:${isErr?'var(--danger)':'var(--success)'}"></i>${msg}`;
    w.appendChild(d);
    setTimeout(()=>d.remove(),3200);
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
async function post(data) {
    const fd=new FormData(); for(const k in data) fd.append(k,data[k]);
    const r=await fetch(window.location.pathname,{method:'POST',body:fd});
    return r.json();
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
function updateDashboard() {
    const avail  = allDoctors.filter(d=>d.label==='Available').length;
    const clinic = allDoctors.filter(d=>d.label==='No Clinic').length;
    const leave  = allDoctors.filter(d=>d.label==='On Leave').length;
    document.getElementById('count-available').textContent = avail;
    document.getElementById('count-noclinic').textContent  = clinic;
    document.getElementById('count-onleave').textContent   = leave;

    const tbody = document.getElementById('dashboard-tbody');
    const recent = [...allDoctors].slice(0,8);
    if (!recent.length) { tbody.innerHTML='<tr><td colspan="4" class="table-empty">No doctors yet</td></tr>'; return; }
    tbody.innerHTML = recent.map(d => `<tr>
        <td style="font-weight:600;">${escH(d.name)}</td>
        <td>${escH(d.department||'')}</td>
        <td><span class="badge-status ${badgeClass(d.label)}">${escH(d.label)}</span></td>
        <td>${d.resume_date ? fmtDate(d.resume_date) : '<span style="color:#ccc;">—</span>'}</td>
    </tr>`).join('');
}

function badgeClass(label) {
    if (label==='Available') return 'badge-available';
    if (label==='No Clinic') return 'badge-noclinic';
    return 'badge-onleave';
}

// ── Render doctors table ──────────────────────────────────────────────────────
function renderTable(doctors) {
    const tbody=document.getElementById('doctor-tbody');
    if (!doctors.length) {
        tbody.innerHTML='<tr><td colspan="7" class="table-empty"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No doctors found</td></tr>';
        updateDashboard(); return;
    }
    tbody.innerHTML=doctors.map(d=>{
        const rd=d.resume_date?`${fmtDate(d.resume_date)}${d.is_tentative?'<span class="tentative-pill">TENTATIVE</span>':''}`:'<span style="color:#ccc;">—</span>';
        const rm=(d.remarks&&d.label==='On Leave')?escH(d.remarks):'<span style="color:#ccc;">—</span>';
        return `<tr data-id="${d.id}" data-name="${escH(d.name.toLowerCase())}" data-dept="${escH((d.department||'').toLowerCase())}" data-status="${escH(d.label)}" data-date="${escH(d.resume_date||'')}" data-leave="${escH(d.remarks||'')}">
            <td><input type="checkbox" class="doctor-checkbox" value="${d.id}" onchange="updateDeleteBtn()"></td>
            <td style="font-weight:600;">${escH(d.name)}</td>
            <td>${escH(d.department||'')}</td>
            <td><span class="badge-status ${badgeClass(d.label)}">${escH(d.label)}</span></td>
            <td>${rd}</td>
            <td>${rm}</td>
            <td>
                <button class="btn-icon btn-edit-sm" onclick="openEditModal(${d.id})"><i class="bi bi-pencil"></i></button>
                <button class="btn-icon btn-delete-sm" onclick="deleteOne(${d.id},this)"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`;
    }).join('');
    filterTable(); updateDeleteBtn(); updateDashboard();
}

function fmtDate(s) {
    if(!s) return '—';
    const [y,m,d]=s.split('-').map(Number);
    return new Date(y,m-1,d).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'2-digit'});
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Sort ──────────────────────────────────────────────────────────────────────
function sortTable(col) {
    sortAsc=(sortCol===col)?!sortAsc:true; sortCol=col;
    for(let i=1;i<=5;i++){const ic=document.getElementById('si-'+i);if(ic)ic.className='bi bi-arrow-down-up'+(i===col?sortAsc?' bi-arrow-up':' bi-arrow-down':'');}
    const keys=[null,'name','department','label','resume_date','remarks'];
    const key=keys[col];
    allDoctors.sort((a,b)=>{const va=(a[key]||'').toLowerCase(),vb=(b[key]||'').toLowerCase();return sortAsc?va.localeCompare(vb):vb.localeCompare(va);});
    renderTable(allDoctors);
}

// ── Filter ────────────────────────────────────────────────────────────────────
function filterTable() {
    const fn=(document.getElementById('global-search')?.value||'').toLowerCase().trim();
    const fd=(document.getElementById('f-dept')?.value||'').toLowerCase().trim();
    const fs=(document.getElementById('f-status')?.value||'').trim();
    document.querySelectorAll('#doctor-tbody tr').forEach(row=>{
        if(!row.dataset.id){row.style.display='';return;}
        const show=(!fn||row.dataset.name.includes(fn))&&(!fd||row.dataset.dept.includes(fd))&&(!fs||row.dataset.status===fs);
        row.style.display=show?'':'none';
    });
}
function clearAllFilters(){
    ['global-search'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});
    ['f-dept','f-status'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});
    filterTable();
}

// ── Doctor modal ──────────────────────────────────────────────────────────────
function toggleLeaveFields() {
    const isLeave=document.getElementById('m-status').value==='On Leave';
    document.querySelectorAll('.leave-fields').forEach(e=>e.style.display=isLeave?'':'none');
    const rm=document.getElementById('m-remarks'),rh=document.getElementById('remarks-hint');
    rm.disabled=!isLeave;
    if(isLeave){rh.className='remarks-hint hint-active';rh.innerHTML='<i class="bi bi-info-circle"></i> Select the reason for leave';}
    else{rm.value='';rh.className='remarks-hint hint-locked';rh.innerHTML='<i class="bi bi-lock"></i> Only available when status is <strong>On Leave</strong>';}
}
function showDoctorModal(){
    editingId=0;
    document.getElementById('doctorModalTitle').innerHTML='<i class="bi bi-plus-circle"></i> Add New Doctor';
    ['m-name','m-resume'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('m-dept').value='';
    document.getElementById('m-status').value='On Schedule';
    document.getElementById('m-tentative').checked=false;
    document.getElementById('m-remarks').value='';
    document.getElementById('form-error').style.display='none';
    toggleLeaveFields();
    new bootstrap.Modal(document.getElementById('doctorModal')).show();
}
function openEditModal(id){
    const d=allDoctors.find(x=>x.id==id); if(!d) return;
    editingId=id;
    const statusMap={'on schedule':'On Schedule','available':'On Schedule','no medical':'No Medical','no clinic':'No Medical','not available':'No Medical','on leave':'On Leave'};
    const mapped=statusMap[(d.status||'').toLowerCase().trim()]||'On Schedule';
    document.getElementById('doctorModalTitle').innerHTML='<i class="bi bi-pencil"></i> Edit Doctor';
    document.getElementById('m-name').value=d.name;
    document.getElementById('m-dept').value=d.department||'';
    document.getElementById('m-status').value=mapped;
    document.getElementById('m-resume').value=d.resume_date||'';
    document.getElementById('m-tentative').checked=d.is_tentative==1;
    document.getElementById('form-error').style.display='none';
    toggleLeaveFields();
    document.getElementById('m-remarks').value=d.remarks||'';
    new bootstrap.Modal(document.getElementById('doctorModal')).show();
}
async function saveDoctor(){
    const name=document.getElementById('m-name').value.trim();
    const dept=document.getElementById('m-dept').value;
    const status=document.getElementById('m-status').value;
    const resume=document.getElementById('m-resume').value;
    const tent=document.getElementById('m-tentative').checked?1:0;
    const remarks=document.getElementById('m-remarks').value;
    const errEl=document.getElementById('form-error'); errEl.style.display='none';
    if(!name||!dept||!status){errEl.textContent='Name, department and status are required.';errEl.style.display='block';return;}
    if(status==='On Leave'){
        if(!resume){errEl.textContent='Resume date is required.';errEl.style.display='block';return;}
        if(!remarks){errEl.textContent='Please select a leave type.';errEl.style.display='block';return;}
    }
    const res=await post({ajax:'save_doctor',id:editingId,name,department:dept,status,resume_date:resume,remarks,is_tentative:tent?'1':''});
    if(!res.ok){errEl.textContent=res.error;errEl.style.display='block';return;}
    bootstrap.Modal.getInstance(document.getElementById('doctorModal'))?.hide();
    if(res.insert) allDoctors.push(res.doctor);
    else { const idx=allDoctors.findIndex(x=>x.id==res.doctor.id); if(idx>-1) allDoctors[idx]=res.doctor; }
    renderTable(allDoctors);
    toast(res.insert?'Doctor added!':'Doctor updated!');
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteOne(id,btn){
    if(!confirm('Delete this doctor?')) return;
    const res=await post({ajax:'delete_doctor',id});
    if(!res.ok){toast(res.error||'Delete failed',true);return;}
    allDoctors=allDoctors.filter(x=>x.id!=id);
    const row=btn.closest('tr'); row.style.opacity='0'; row.style.transition='opacity .3s';
    setTimeout(()=>renderTable(allDoctors),300);
    toast('Doctor deleted.');
}
function toggleSelectAll(){const all=document.getElementById('select-all').checked;document.querySelectorAll('.doctor-checkbox').forEach(cb=>cb.checked=all);updateDeleteBtn();}
function updateDeleteBtn(){
    const n=document.querySelectorAll('.doctor-checkbox:checked').length;
    const btn=document.getElementById('delete-selected-btn');
    btn.style.display=n>0?'inline-flex':'none';
    if(n>0)btn.innerHTML=`<i class="bi bi-trash"></i> Delete Selected (${n})`;
    document.getElementById('select-all').checked=n>0&&n===document.querySelectorAll('.doctor-checkbox').length;
}
async function deleteSelected(){
    const ids=Array.from(document.querySelectorAll('.doctor-checkbox:checked')).map(c=>c.value);
    if(!ids.length){toast('No doctors selected',true);return;}
    if(!confirm(`Delete ${ids.length} doctor(s)?`)) return;
    const res=await post({ajax:'delete_selected',ids:JSON.stringify(ids)});
    if(!res.ok){toast('Delete failed',true);return;}
    allDoctors=allDoctors.filter(x=>!ids.includes(String(x.id)));
    renderTable(allDoctors); toast(`${ids.length} doctor(s) deleted.`);
}
function showDeleteAllModal(){document.getElementById('confirm-input').value='';document.getElementById('confirm-delete-btn').disabled=true;new bootstrap.Modal(document.getElementById('deleteAllModal')).show();}
document.getElementById('confirm-input').addEventListener('input',function(){document.getElementById('confirm-delete-btn').disabled=this.value!=='DELETE ALL';});
async function confirmDeleteAll(){
    if(document.getElementById('confirm-input').value!=='DELETE ALL') return;
    if(!confirm('Are you absolutely sure?')) return;
    const res=await post({ajax:'delete_all'});
    bootstrap.Modal.getInstance(document.getElementById('deleteAllModal'))?.hide();
    if(!res.ok){toast('Delete failed',true);return;}
    allDoctors=[]; renderTable([]); toast('All doctors deleted.');
}

// ── Display settings ──────────────────────────────────────────────────────────
async function saveDisplaySettings(){
    const spd=document.getElementById('set-speed').value;
    const top=document.getElementById('set-top').value;
    const bot=document.getElementById('set-bot').value;
    const res=await post({ajax:'save_display',scroll_speed:spd,pause_at_top:top,pause_at_bottom:bot});
    if(!res.ok){toast('Failed to save',true);return;}
    document.getElementById('sum-speed').textContent=speedLabels[spd]||spd+' px/s';
    document.getElementById('sum-top').textContent=pauseLabels[top]||(top/1000)+' sec';
    document.getElementById('sum-bot').textContent=pauseLabels[bot]||(bot/1000)+' sec';
    toast('Display settings saved!');
}

// ── Password ──────────────────────────────────────────────────────────────────
function showChangePasswordModal(){
    ['pw_current','pw_new','pw_confirm'].forEach(id=>document.getElementById(id).value='');
    const a=document.getElementById('cp-alert'); a.style.display='none';
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}
async function savePassword(){
    const cur=document.getElementById('pw_current').value;
    const np=document.getElementById('pw_new').value;
    const cp=document.getElementById('pw_confirm').value;
    const a=document.getElementById('cp-alert'); a.style.display='none';
    const res=await post({ajax:'change_password',current_or_key:cur,new_password:np,confirm_password:cp});
    if(!res.ok){a.className='alert alert-danger py-2';a.style.display='block';a.innerHTML=`<i class="bi bi-x-circle-fill"></i> ${res.error}`;return;}
    a.className='alert alert-success py-2';a.style.display='block';a.innerHTML=`<i class="bi bi-check-circle-fill"></i> ${res.message}`;
    setTimeout(()=>bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'))?.hide(),1500);
    toast('Password changed!');
}
function togglePw(id,btn){const inp=document.getElementById(id),ic=btn.querySelector('i');if(inp.type==='password'){inp.type='text';ic.className='bi bi-eye-slash';}else{inp.type='password';ic.className='bi bi-eye';}}

// ── User management ───────────────────────────────────────────────────────────
async function loadUsers(){
    const res=await fetch(window.location.pathname+'?ajax=users');
    const data=await res.json();
    if(!data.ok) return;
    const tbody=document.getElementById('users-tbody');
    if(!data.users.length){tbody.innerHTML='<tr><td colspan="5" class="table-empty">No users found</td></tr>';return;}
    tbody.innerHTML=data.users.map((u,i)=>`<tr>
        <td style="color:var(--muted);">${i+1}</td>
        <td style="font-weight:600;">${escH(u.username)}</td>
        <td><span class="user-row-badge ${u.is_superadmin==1?'badge-superadmin':'badge-admin'}">${u.is_superadmin==1?'⭐ Super Admin':'Admin'}</span></td>
        <td style="color:var(--muted);font-size:12px;">${u.created_at||'—'}</td>
        <td style="display:flex;gap:6px;align-items:center;">
            <button class="btn-icon btn-edit-sm" onclick="showEditUserModal(${u.id},'${escH(u.username)}',${u.is_superadmin})"><i class="bi bi-pencil"></i> Edit</button>
            ${u.is_superadmin==1?'<span style="color:#ccc;font-size:12px;">Protected</span>':`<button class="btn-icon btn-delete-sm" onclick="deleteUser(${u.id},'${escH(u.username)}')"><i class="bi bi-trash"></i> Delete</button>`}
        </td>
    </tr>`).join('');
}
let editingUserId = 0;

function showAddUserModal(){
    editingUserId = 0;
    document.getElementById('au-modal-title').innerHTML='<i class="bi bi-person-plus-fill"></i> Add User Account';
    document.getElementById('au-submit-btn').innerHTML='<i class="bi bi-check-circle"></i> Create Account';
    document.getElementById('au-username').value='';
    document.getElementById('au-password').value='';
    document.getElementById('au-password').placeholder='Min. 6 characters';
    document.getElementById('au-pw-label').textContent='Password';
    document.getElementById('au-pw-hint').style.display='none';
    document.getElementById('au-role').value='0';
    const a=document.getElementById('au-alert'); a.style.display='none';
    new bootstrap.Modal(document.getElementById('addUserModal')).show();
}

function showEditUserModal(id, username, isSuperadmin){
    editingUserId = id;
    document.getElementById('au-modal-title').innerHTML='<i class="bi bi-pencil-fill"></i> Edit User Account';
    document.getElementById('au-submit-btn').innerHTML='<i class="bi bi-check-circle"></i> Save Changes';
    document.getElementById('au-username').value=username;
    document.getElementById('au-password').value='';
    document.getElementById('au-password').placeholder='Leave blank to keep current';
    document.getElementById('au-pw-label').textContent='New Password';
    document.getElementById('au-pw-hint').style.display='block';
    document.getElementById('au-role').value=isSuperadmin==1?'1':'0';
    const a=document.getElementById('au-alert'); a.style.display='none';
    new bootstrap.Modal(document.getElementById('addUserModal')).show();
}

async function submitUserModal(){
    const un=document.getElementById('au-username').value.trim();
    const pw=document.getElementById('au-password').value.trim();
    const role=document.getElementById('au-role').value;
    const a=document.getElementById('au-alert'); a.style.display='none';

    if (editingUserId === 0) {
        // ADD
        const res=await post({ajax:'add_user',username:un,password:pw,role});
        if(!res.ok){a.className='alert alert-danger py-2';a.style.display='block';a.innerHTML=`<i class="bi bi-x-circle-fill"></i> ${res.error}`;return;}
        bootstrap.Modal.getInstance(document.getElementById('addUserModal'))?.hide();
        toast(`User "${un}" created!`);
    } else {
        // EDIT
        const res=await post({ajax:'edit_user',id:editingUserId,username:un,password:pw,role});
        if(!res.ok){a.className='alert alert-danger py-2';a.style.display='block';a.innerHTML=`<i class="bi bi-x-circle-fill"></i> ${res.error}`;return;}
        bootstrap.Modal.getInstance(document.getElementById('addUserModal'))?.hide();
        toast(`User "${un}" updated!`);
    }
    loadUsers();
}
async function deleteUser(id,name){
    if(!confirm(`Delete user "${name}"? They will no longer be able to log in.`)) return;
    const res=await post({ajax:'delete_user',id});
    if(!res.ok){toast(res.error||'Delete failed',true);return;}
    toast(`User "${name}" deleted.`);
    loadUsers();
}

// ── Logout confirmation ───────────────────────────────────────────────────────
function showLogoutModal() {
    new bootstrap.Modal(document.getElementById('logoutModal')).show();
}

// ── Init ──────────────────────────────────────────────────────────────────────
renderTable(allDoctors);
updateDashboard();
if (IS_SUPERADMIN) loadUsers();

// ── Silent background poll for User Accounts (no page reload) ─────────────────
// Only re-renders the tbody rows if the data actually changed
let lastUsersStr = '';
async function pollUsers() {
    if (!IS_SUPERADMIN) return;
    try {
        const res = await fetch(window.location.pathname + '?ajax=users');
        const data = await res.json();
        if (!data.ok) return;
        const newStr = JSON.stringify(data.users);
        if (newStr === lastUsersStr) return; // nothing changed — don't touch the DOM
        lastUsersStr = newStr;
        // Only update if user accounts page is currently visible
        const tbody = document.getElementById('users-tbody');
        if (!tbody) return;
        if (!data.users.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="table-empty">No users found</td></tr>';
            return;
        }
        tbody.innerHTML = data.users.map((u, i) => `<tr>
            <td style="color:var(--muted);">${i+1}</td>
            <td style="font-weight:600;">${escH(u.username)}</td>
            <td><span class="user-row-badge ${u.is_superadmin==1?'badge-superadmin':'badge-admin'}">${u.is_superadmin==1?'⭐ Super Admin':'Admin'}</span></td>
            <td style="color:var(--muted);font-size:12px;">${u.created_at||'—'}</td>
            <td style="display:flex;gap:6px;align-items:center;">
                <button class="btn-icon btn-edit-sm" onclick="showEditUserModal(${u.id},'${escH(u.username)}',${u.is_superadmin})"><i class="bi bi-pencil"></i> Edit</button>
                ${u.is_superadmin==1?'<span style="color:#ccc;font-size:12px;">Protected</span>':`<button class="btn-icon btn-delete-sm" onclick="deleteUser(${u.id},'${escH(u.username)}')"><i class="bi bi-trash"></i> Delete</button>`}
            </td>
        </tr>`).join('');
    } catch(e) { /* silent fail — no network disruption shown to user */ }
}
if (IS_SUPERADMIN) setInterval(pollUsers, 5000);
</script>
</body>
</html>