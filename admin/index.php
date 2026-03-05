<?php
// ============================================================
//  Admin Panel — New Sinai MDI Hospital
//  Requires: session, db.php (provides $conn)
// ============================================================

session_start();

if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db.php';

// ============================================================
//  CONSTANTS
// ============================================================

define('MASTER_RESET_KEY', 'Newsinaimdi#53');
define('ADMIN_PASSWORD_KEY', 'New@sinaimdi#53');

const LEAVE_TYPES = ['On Vacation', 'Personal', 'Sick Leave'];

const SPEED_LABELS = [
    15  => 'Slow',
    25  => 'Normal',
    50  => 'Fast',
];

const PAUSE_LABELS = [
    2000  => '2 seconds',
    3000  => '3 seconds',
    5000  => '5 seconds',
    8000  => '8 seconds',
    10000 => '10 seconds',
];

// ============================================================
//  AUDIT LOG
// ============================================================

/**
 * Write a single row to the audit_log table.
 *
 * @param mysqli $conn
 * @param string $performedBy  Username of the actor
 * @param string $action       Short machine-readable key  e.g. 'doctor_added'
 * @param string $details      Human-readable description  e.g. 'Added Dr. Juan Dela Cruz (OPD)'
 */
function logAudit(mysqli $conn, string $performedBy, string $action, string $details): void
{
    $stmt = $conn->prepare(
        "INSERT INTO audit_log (performed_by, action, details) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('sss', $performedBy, $action, $details);
    $stmt->execute();
}

/**
 * GET  ?ajax=audit_log  — paginated audit log (superadmin only)
 */
function ajaxGetAuditLog(mysqli $conn, bool $isSuperadmin): never
{
    if (!$isSuperadmin) {
        jsonResponse(['ok' => false, 'error' => 'Access denied.']);
    }

    $page    = max(1, (int) ($_GET['page']   ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;
    $filter  = trim($_GET['filter'] ?? '');

    if ($filter !== '') {
        $like  = "%{$conn->real_escape_string($filter)}%";
        $total = $conn->query("SELECT COUNT(*) FROM audit_log WHERE performed_by LIKE '{$like}' OR action LIKE '{$like}' OR details LIKE '{$like}'")->fetch_row()[0];
        $rows  = $conn->query("SELECT * FROM audit_log WHERE performed_by LIKE '{$like}' OR action LIKE '{$like}' OR details LIKE '{$like}' ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    } else {
        $total = $conn->query("SELECT COUNT(*) FROM audit_log")->fetch_row()[0];
        $rows  = $conn->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
    }

    $logs = [];
    while ($row = $rows->fetch_assoc()) {
        $logs[] = $row;
    }

    jsonResponse([
        'ok'        => true,
        'logs'      => $logs,
        'total'     => (int) $total,
        'page'      => $page,
        'per_page'  => $perPage,
    ]);
}

// ============================================================
//  AUTO-CLEANUP
// ============================================================

/**
 * Reset doctors whose confirmed (non-tentative) resume date has passed today.
 * - is_tentative = 0 + resume_date < TODAY  →  reset to No Medical (No Clinic)
 * - is_tentative = 1 + resume_date < TODAY  →  leave untouched; flag is returned
 *   to the UI so admins know to review them manually.
 *
 * Runs on every page load — the WHERE clause is indexed on resume_date so it
 * is effectively free when there are no expired rows.
 */
function autoCleanExpiredLeave(mysqli $conn): void
{
    // Reset confirmed leave where resume date has passed
    $stmt = $conn->prepare("
        UPDATE doctors
        SET    status       = 'No Medical',
               resume_date  = NULL,
               remarks      = NULL,
               is_tentative = 0
        WHERE  status        = 'On Leave'
          AND  is_tentative  = 0
          AND  resume_date  IS NOT NULL
          AND  resume_date   < CURDATE()
    ");
    $stmt->execute();
}

// ============================================================
//  HELPERS
// ============================================================

/** Send a JSON response and halt execution. */
function jsonResponse(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Map a raw DB status string to a user-facing label and CSS badge class.
 * Returns ['label' => string, 'badge' => string]
 */
function resolveDoctorLabel(string $status): array
{
    $lower = strtolower(trim($status));

    if ($lower === '' || in_array($lower, ['not available', 'no clinic', 'no medical'], true)) {
        return ['label' => 'No Clinic', 'badge' => 'status-nomedical'];
    }

    if (in_array($lower, ['available', 'on schedule'], true)) {
        return ['label' => 'Available', 'badge' => 'status-onschedule'];
    }

    if (str_contains($lower, 'leave')) {
        return ['label' => 'On Leave', 'badge' => 'status-onleave'];
    }

    return ['label' => ucwords($lower) ?: 'No Clinic', 'badge' => 'status-nomedical'];
}

/** Convert a raw DB row into the normalised array the front-end expects. */
function formatDoctorRow(array $row): array
{
    $label = resolveDoctorLabel($row['status']);

    return [
        'id'          => $row['id'],
        'name'        => $row['name'],
        'department'  => $row['department'],
        'status'      => $row['status'],
        'label'       => $label['label'],
        'badge'       => $label['badge'],
        'resume_date' => $row['resume_date'],
        'is_tentative'=> (int) ($row['is_tentative'] ?? 0),
        'remarks'     => trim($row['remarks'] ?? ''),
    ];
}

// ============================================================
//  AJAX ENDPOINTS
// ============================================================

/**
 * GET  ?ajax=doctors  — list all doctors
 */
function ajaxGetDoctors(mysqli $conn): never
{
    $result = $conn->query("SELECT * FROM doctors ORDER BY name ASC");
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = formatDoctorRow($row);
    }
    jsonResponse(['ok' => true, 'doctors' => $rows]);
}

/**
 * GET  ?ajax=users  — list all users (superadmin only)
 */
function ajaxGetUsers(mysqli $conn, bool $isSuperadmin): never
{
    if (!$isSuperadmin) {
        jsonResponse(['ok' => false, 'error' => 'Access denied.']);
    }

    $result = $conn->query("SELECT id, username, is_superadmin, created_at FROM users ORDER BY id ASC");
    if (!$result) {
        jsonResponse(['ok' => false, 'error' => $conn->error]);
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    jsonResponse(['ok' => true, 'users' => $users]);
}

/**
 * POST  ajax=save_doctor  — insert or update a doctor record
 */
function ajaxSaveDoctor(mysqli $conn): never
{
    $id       = (int) ($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $dept     = trim($_POST['department'] ?? '');
    $status   = trim($_POST['status'] ?? '');
    $resume   = trim($_POST['resume_date'] ?? '') ?: null;
    $remarks  = trim($_POST['remarks'] ?? '') ?: null;
    $tentative = isset($_POST['is_tentative']) ? 1 : 0;

    // Basic validation
    if (!$name)   jsonResponse(['ok' => false, 'error' => 'Doctor name is required.']);
    if (!$dept)   jsonResponse(['ok' => false, 'error' => 'Please select a department.']);
    if (!$status) jsonResponse(['ok' => false, 'error' => 'Please select a status.']);

    if ($status === 'On Leave') {
        if (!$resume) {
            jsonResponse(['ok' => false, 'error' => 'Resume date is required for On Leave.']);
        }
        $date = DateTime::createFromFormat('Y-m-d', $resume);
        if (!$date || $date->format('Y-m-d') !== $resume) {
            jsonResponse(['ok' => false, 'error' => 'Invalid resume date.']);
        }
        $today = (new DateTime())->setTime(0, 0, 0);
        if ($date < $today) {
            jsonResponse(['ok' => false, 'error' => 'Resume date cannot be in the past.']);
        }
        if (!$remarks) {
            jsonResponse(['ok' => false, 'error' => 'Please select a leave type.']);
        }
    } else {
        // Clear leave-only fields when not on leave
        $resume    = null;
        $tentative = 0;
        $remarks   = null;
    }

    $null = null; // placeholder for unset time columns

    if ($id === 0) {
        $stmt = $conn->prepare(
            "INSERT INTO doctors (name, department, status, resume_date, appt_start, appt_end, remarks, is_tentative)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('sssssssi', $name, $dept, $status, $resume, $null, $null, $remarks, $tentative);
    } else {
        $stmt = $conn->prepare(
            "UPDATE doctors
             SET name = ?, department = ?, status = ?, resume_date = ?,
                 appt_start = ?, appt_end = ?, remarks = ?, is_tentative = ?
             WHERE id = ?"
        );
        $stmt->bind_param('sssssssii', $name, $dept, $status, $resume, $null, $null, $remarks, $tentative, $id);
    }

    $stmt->execute();
    $newId = $id ?: $conn->insert_id;

    $fetchSaved = $conn->prepare("SELECT * FROM doctors WHERE id = ?");
    $fetchSaved->bind_param('i', $newId);
    $fetchSaved->execute();
    $saved = $fetchSaved->get_result()->fetch_assoc();

    $actor       = $_SESSION['admin'];
    $action      = ($id === 0) ? 'doctor_added' : 'doctor_edited';
    // Map internal DB status values to the display labels shown in the UI
    $statusLabels  = ['On Schedule' => 'Available', 'No Medical' => 'No Clinic'];
    $displayStatus = $statusLabels[$status] ?? $status;
    $detail      = ($id === 0)
        ? "Added Dr. {$name} ({$dept}) — Status: {$displayStatus}"
        : "Edited Dr. {$name} ({$dept}) — Status: {$displayStatus}";
    logAudit($conn, $actor, $action, $detail);

    jsonResponse([
        'ok'     => true,
        'insert' => ($id === 0),
        'doctor' => formatDoctorRow($saved),
    ]);
}

/**
 * POST  ajax=delete_doctor  — delete a single doctor
 */
function ajaxDeleteDoctor(mysqli $conn): never
{
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        jsonResponse(['ok' => false, 'error' => 'Invalid id.']);
    }

    // Fetch name before deleting for the log
    $fetchDel = $conn->prepare("SELECT name, department FROM doctors WHERE id = ?");
    $fetchDel->bind_param('i', $id);
    $fetchDel->execute();
    $delRow = $fetchDel->get_result()->fetch_assoc();

    $stmt = $conn->prepare("DELETE FROM doctors WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    $detail = $delRow ? "Deleted Dr. {$delRow['name']} ({$delRow['department']})" : "Deleted doctor ID {$id}";
    logAudit($conn, $_SESSION['admin'], 'doctor_deleted', $detail);

    jsonResponse(['ok' => true]);
}

/**
 * POST  ajax=delete_selected  — delete a list of doctors by id
 */
function ajaxDeleteSelected(mysqli $conn): never
{
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    $count = 0;
    foreach ($ids as $id) {
        $id   = (int) $id;
        $stmt = $conn->prepare("DELETE FROM doctors WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count++;
    }
    logAudit($conn, $_SESSION['admin'], 'doctors_bulk_deleted', "Deleted {$count} doctor(s)");
    jsonResponse(['ok' => true]);
}

/**
 * POST  ajax=delete_all  — truncate the doctors table
 */
function ajaxDeleteAll(mysqli $conn): never
{
    $conn->query("DELETE FROM doctors");
    logAudit($conn, $_SESSION['admin'], 'doctors_all_deleted', 'Deleted all doctor records');
    jsonResponse(['ok' => true]);
}

/**
 * POST  ajax=save_display  — persist display scroll settings
 */
function ajaxSaveDisplay(mysqli $conn): never
{
    $speed  = max(5,    min(100,   (int) ($_POST['scroll_speed']   ?? 25)));
    $top    = max(1000, min(10000, (int) ($_POST['pause_at_top']   ?? 3000)));
    $bottom = max(1000, min(10000, (int) ($_POST['pause_at_bottom']?? 3000)));

    $stmt = $conn->prepare("UPDATE display_settings SET scroll_speed = ?, pause_at_top = ?, pause_at_bottom = ? WHERE id = 1");
    $stmt->bind_param('iii', $speed, $top, $bottom);
    $stmt->execute();

    logAudit($conn, $_SESSION['admin'], 'display_settings_saved',
        "Speed: {$speed}, Pause Top: {$top}ms, Pause Bottom: {$bottom}ms");

    jsonResponse(['ok' => true]);
}

/**
 * POST  ajax=change_password  — change the current user's password
 */
function ajaxChangePassword(mysqli $conn): never
{
    $currentOrKey   = trim($_POST['current_or_key']   ?? '');
    $newPassword    = trim($_POST['new_password']      ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if (!$currentOrKey || !$newPassword || !$confirmPassword) {
        jsonResponse(['ok' => false, 'error' => 'All fields are required.']);
    }
    if ($newPassword !== $confirmPassword) {
        jsonResponse(['ok' => false, 'error' => 'New passwords do not match.']);
    }
    if (strlen($newPassword) < 6) {
        jsonResponse(['ok' => false, 'error' => 'Password must be at least 6 characters.']);
    }

    $username = $_SESSION['admin'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $correctPassword = $user && md5($currentOrKey) === $user['password'];
    $usedResetKey    = ($currentOrKey === MASTER_RESET_KEY);

    if (!$correctPassword && !$usedResetKey) {
        jsonResponse(['ok' => false, 'error' => 'Current password or master reset key is incorrect.']);
    }

    $hashed = md5($newPassword);
    $update = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
    $update->bind_param('ss', $hashed, $username);
    $update->execute();

    $method = $usedResetKey ? 'master reset key' : 'current password';
    logAudit($conn, $username, 'password_changed', "Changed own password via {$method}");

    jsonResponse(['ok' => true, 'message' => 'Password changed successfully!']);
}

/**
 * POST  ajax=add_user  — create a new user account (superadmin only)
 */
function ajaxAddUser(mysqli $conn, bool $isSuperadmin): never
{
    if (!$isSuperadmin) {
        jsonResponse(['ok' => false, 'error' => 'Access denied.']);
    }

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = (int) ($_POST['role'] ?? 0);

    if (!$username || !$password) {
        jsonResponse(['ok' => false, 'error' => 'Username and password are required.']);
    }
    if (strlen($password) < 6) {
        jsonResponse(['ok' => false, 'error' => 'Password must be at least 6 characters.']);
    }

    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param('s', $username);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        jsonResponse(['ok' => false, 'error' => 'Username already exists.']);
    }

    $hashed = md5($password);
    $stmt   = $conn->prepare("INSERT INTO users (username, password, is_superadmin) VALUES (?, ?, ?)");
    $stmt->bind_param('ssi', $username, $hashed, $role);
    $stmt->execute();

    $roleLabel = $role ? 'Super Admin' : 'Admin';
    logAudit($conn, $_SESSION['admin'], 'user_added', "Created user '{$username}' as {$roleLabel}");

    jsonResponse(['ok' => true, 'id' => $conn->insert_id, 'username' => $username, 'is_superadmin' => $role]);
}

/**
 * POST  ajax=edit_user  — update an existing user account (superadmin only)
 */
function ajaxEditUser(mysqli $conn, bool $isSuperadmin): never
{
    if (!$isSuperadmin) {
        jsonResponse(['ok' => false, 'error' => 'Access denied.']);
    }

    $id       = (int) ($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = (int) ($_POST['role'] ?? 0);

    if (!$id || !$username) {
        jsonResponse(['ok' => false, 'error' => 'Username is required.']);
    }

    // Ensure username isn't taken by a different account
    $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check->bind_param('si', $username, $id);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        jsonResponse(['ok' => false, 'error' => 'Username already taken by another account.']);
    }

    // Prevent stripping the super-admin role if no other super-admin exists
    $fetchTarget = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $fetchTarget->bind_param('i', $id);
    $fetchTarget->execute();
    $target = $fetchTarget->get_result()->fetch_assoc();
    if (!$target) {
        jsonResponse(['ok' => false, 'error' => 'User not found.']);
    }

    if ($target['is_superadmin'] == 1 && $role == 0) {
        $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE is_superadmin = 1 AND id != ?");
        $countStmt->bind_param('i', $id);
        $countStmt->execute();
        $otherSuperAdmins = $countStmt->get_result()->fetch_assoc();

        if ($otherSuperAdmins['c'] == 0) {
            jsonResponse(['ok' => false, 'error' => 'Cannot remove Super Admin role — there must be at least one Super Admin.']);
        }
    }

    if ($password !== '') {
        if (strlen($password) < 6) {
            jsonResponse(['ok' => false, 'error' => 'Password must be at least 6 characters.']);
        }
        $adminKey = trim($_POST['admin_password'] ?? '');
        if ($adminKey !== ADMIN_PASSWORD_KEY) {
            jsonResponse(['ok' => false, 'error' => 'Incorrect administrator password. Password was not changed.']);
        }
        $hashed = md5($password);
        $stmt   = $conn->prepare("UPDATE users SET username = ?, password = ?, is_superadmin = ? WHERE id = ?");
        $stmt->bind_param('ssii', $username, $hashed, $role, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, is_superadmin = ? WHERE id = ?");
        $stmt->bind_param('sii', $username, $role, $id);
    }

    $stmt->execute();

    $roleLabel = $role ? 'Super Admin' : 'Admin';
    $detail    = "Updated user '{$username}' — Role: {$roleLabel}";
    if ($password !== '') {
        $detail .= ' (password changed)';
    }
    logAudit($conn, $_SESSION['admin'], 'user_edited', $detail);

    jsonResponse(['ok' => true, 'message' => 'User updated successfully.']);
}

/**
 * POST  ajax=delete_user  — remove a user account (superadmin only)
 */
function ajaxDeleteUser(mysqli $conn, bool $isSuperadmin, string $currentUsername): never
{
    if (!$isSuperadmin) {
        jsonResponse(['ok' => false, 'error' => 'Access denied.']);
    }

    $id = (int) ($_POST['id'] ?? 0);

    $fetchTarget = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $fetchTarget->bind_param('i', $id);
    $fetchTarget->execute();
    $target = $fetchTarget->get_result()->fetch_assoc();

    if (!$target) {
        jsonResponse(['ok' => false, 'error' => 'User not found.']);
    }
    if ($target['is_superadmin']) {
        jsonResponse(['ok' => false, 'error' => 'Cannot delete the superadmin account.']);
    }
    if ($target['username'] === $currentUsername) {
        jsonResponse(['ok' => false, 'error' => 'Cannot delete your own account.']);
    }

    $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $deleteStmt->bind_param('i', $id);
    $deleteStmt->execute();

    logAudit($conn, $_SESSION['admin'], 'user_deleted', "Deleted user '{$target['username']}'");

    jsonResponse(['ok' => true]);
}

/**
 * POST  ajax=clear_audit_log  — truncate audit_log table (superadmin only)
 */
function ajaxClearAuditLog(mysqli $conn, bool $isSuperadmin): never
{
    if (!$isSuperadmin) {
        jsonResponse(['ok' => false, 'error' => 'Access denied.']);
    }
    $conn->query("DELETE FROM audit_log");
    jsonResponse(['ok' => true]);
}

// ============================================================
//  BOOTSTRAP  (auth context → route)
// ============================================================

// Resolve the currently logged-in user
$currentUsername = $_SESSION['admin'];
$currentUser = $conn->prepare("SELECT * FROM users WHERE username = ?");
$currentUser->bind_param('s', $currentUsername);
$currentUser->execute();
$me = $currentUser->get_result()->fetch_assoc();
$isSuperadmin = !empty($me['is_superadmin']);

// ── Route AJAX GET requests ───────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    match ($_GET['ajax']) {
        'doctors'   => ajaxGetDoctors($conn),
        'users'     => ajaxGetUsers($conn, $isSuperadmin),
        'audit_log' => ajaxGetAuditLog($conn, $isSuperadmin),
        default     => jsonResponse(['ok' => false, 'error' => 'Unknown endpoint.']),
    };
}

// ── Route AJAX POST requests ──────────────────────────────────────────────────
if (isset($_POST['ajax'])) {
    match ($_POST['ajax']) {
        'save_doctor'      => ajaxSaveDoctor($conn),
        'delete_doctor'    => ajaxDeleteDoctor($conn),
        'delete_selected'  => ajaxDeleteSelected($conn),
        'delete_all'       => ajaxDeleteAll($conn),
        'save_display'     => ajaxSaveDisplay($conn),
        'change_password'  => ajaxChangePassword($conn),
        'add_user'         => ajaxAddUser($conn, $isSuperadmin),
        'edit_user'        => ajaxEditUser($conn, $isSuperadmin),
        'delete_user'      => ajaxDeleteUser($conn, $isSuperadmin, $currentUsername),
        'clear_audit_log'  => ajaxClearAuditLog($conn, $isSuperadmin),
        default            => jsonResponse(['ok' => false, 'error' => 'Unknown endpoint.']),
    };
}

// ============================================================
//  PAGE DATA  (for initial HTML render — no AJAX involved)
// ============================================================

// Auto-reset confirmed On Leave doctors whose resume date has passed.
// Tentative-date doctors are intentionally skipped — admin must review manually.
autoCleanExpiredLeave($conn);

$displaySettings = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1")->fetch_assoc();

// Cast to int — MySQL returns strings, and the === comparisons in the select options require matching types
$currentSpeed  = (int) ($displaySettings['scroll_speed']    ?? 25);
$currentTop    = (int) ($displaySettings['pause_at_top']    ?? 3000);
$currentBottom = (int) ($displaySettings['pause_at_bottom'] ?? 3000);

$currentSpeedLabel  = SPEED_LABELS[$currentSpeed]  ?? "{$currentSpeed} px/s";
$currentTopLabel    = PAUSE_LABELS[$currentTop]    ?? ($currentTop / 1000)    . ' sec';
$currentBottomLabel = PAUSE_LABELS[$currentBottom] ?? ($currentBottom / 1000) . ' sec';

// Initial doctor list (also used for stats)
$doctorResult = $conn->query("SELECT * FROM doctors ORDER BY name ASC");
$initialDoctors = [];
while ($row = $doctorResult->fetch_assoc()) {
    $initialDoctors[] = formatDoctorRow($row);
}

$countAvailable = count(array_filter($initialDoctors, fn($d) => $d['label'] === 'Available'));
$countNoClinic  = count(array_filter($initialDoctors, fn($d) => $d['label'] === 'No Clinic'));
$countOnLeave   = count(array_filter($initialDoctors, fn($d) => $d['label'] === 'On Leave'));

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
        /* ============================================================
           Design tokens
        ============================================================ */
        :root {
            --primary:          #0052CC;
            --primary-light:    #1e88e5;
            --primary-dark:     #003d99;
            --accent:           #ffc107;
            --success:          #22c55e;
            --danger:           #ef4444;
            --warning:          #f59e0b;
            --sidebar-w:        260px;
            --sidebar-collapsed:68px;
            --bg:               #f0f4f8;
            --surface:          #ffffff;
            --border:           #e2e8f0;
            --text:             #0f172a;
            --muted:            #64748b;
            --radius:           12px;
            --transition:       0.22s cubic-bezier(0.4,0,0.2,1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── Sidebar ── */
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

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 16px;
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
            opacity: 1;
            transition: opacity var(--transition);
            overflow: hidden;
        }
        .sidebar.collapsed .sidebar-brand-text { opacity: 0; pointer-events: none; }
        .brand-name { font-size: 13px; font-weight: 800; color: white; line-height: 1.2; }
        .brand-sub  { font-size: 10px; font-weight: 500; color: rgba(255,255,255,0.65); margin-top: 3px; display: block; }

        .sidebar-nav { flex: 1; padding: 16px 8px; display: flex; flex-direction: column; gap: 4px; overflow: hidden; }

        .nav-section-label {
            font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.4);
            text-transform: uppercase; letter-spacing: 1px;
            padding: 8px 10px 4px;
            white-space: nowrap;
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
        .nav-item:hover  { background: rgba(255,255,255,0.12); color: white; }
        .nav-item.active { background: rgba(255,255,255,0.2);  color: white; }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%;
            height: 60%; width: 3px;
            background: var(--accent);
            border-radius: 0 3px 3px 0;
        }
        .nav-item i     { font-size: 18px; flex-shrink: 0; width: 22px; text-align: center; }
        .nav-label      { opacity: 1; transition: opacity var(--transition); }
        .sidebar.collapsed .nav-label { opacity: 0; }

        .sidebar-toggle {
            margin: 8px;
            align-self: flex-end;
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
            flex-shrink: 0;
            transition: background var(--transition);
        }
        .sidebar-toggle:hover { background: rgba(255,255,255,0.22); }

        .sidebar-footer {
            padding: 12px 8px;
            border-top: 1px solid rgba(255,255,255,0.12);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        /* ── Main content ── */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            min-height: 100vh;
            transition: margin-left var(--transition);
            display: flex;
            flex-direction: column;
        }
        .main-content.sidebar-collapsed { margin-left: var(--sidebar-collapsed); }

        /* ── Top bar ── */
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
        .page-title  { font-size: 18px; font-weight: 700; color: var(--text); }
        .page-breadcrumb { font-size: 12px; color: var(--muted); margin-top: 1px; }
        .topbar-right { display: flex; align-items: center; gap: 12px; }

        .user-pill {
            display: flex; align-items: center; gap: 10px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 40px;
            padding: 6px 14px 6px 6px;
        }
        .user-avatar {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 14px; font-weight: 700;
        }
        .user-name { font-size: 13px; font-weight: 600; color: var(--text); }
        .user-role { font-size: 10px; color: var(--muted); }

        /* ── Pages ── */
        .page        { padding: 28px; flex: 1; display: none; }
        .page.active { display: block; }

        /* ── Stats grid ── */
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
        .stat-card.available::after { background: linear-gradient(90deg, #22c55e, #16a34a); }
        .stat-card.noclinic::after  { background: linear-gradient(90deg, #6b7280, #4b5563); }
        .stat-card.onleave::after   { background: linear-gradient(90deg, #ef4444, #dc2626); }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.1); }

        .stat-icon {
            width: 56px; height: 56px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
        }
        .stat-card.available .stat-icon { background: rgba(34,197,94,0.12); color: #22c55e; }
        .stat-card.noclinic  .stat-icon { background: rgba(107,114,128,0.12); color: #6b7280; }
        .stat-card.onleave   .stat-icon { background: rgba(239,68,68,0.12); color: #ef4444; }

        .stat-count { font-size: 36px; font-weight: 800; line-height: 1; }
        .stat-label { font-size: 13px; font-weight: 600; color: var(--muted); margin-top: 4px; }

        /* ── Section card ── */
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
            font-size: 16px; font-weight: 700;
            display: flex; align-items: center; gap: 8px;
        }
        .section-card-title i { color: var(--primary); }
        .section-card-body { padding: 24px; }

        /* ── Tables ── */
        .table-responsive { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table thead th {
            background: #f8faff; color: var(--muted);
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
            padding: 12px 16px; border-bottom: 2px solid var(--border);
            white-space: nowrap; cursor: pointer; user-select: none;
        }
        .data-table thead th:hover { color: var(--primary); }
        .data-table tbody tr { border-bottom: 1px solid #f1f5f9; transition: background var(--transition); }
        .data-table tbody tr:hover { background: #f8faff; }
        .data-table tbody td { padding: 14px 16px; font-size: 14px; vertical-align: middle; }
        .table-empty { text-align: center; padding: 48px; color: var(--muted); }

        /* ── Status badges ── */
        .badge-status {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
        }
        .badge-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge-available { color: #16a34a; background: rgba(34,197,94,0.12); }
        .badge-noclinic  { color: #6b7280; background: rgba(107,114,128,0.12); }
        .badge-onleave   { color: #dc2626; background: rgba(239,68,68,0.12); }

        /* ── Buttons ── */
        .btn-icon { padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: all var(--transition); display: inline-flex; align-items: center; gap: 5px; }
        .btn-edit-sm   { background: #eff6ff; color: var(--primary); }
        .btn-edit-sm:hover   { background: var(--primary); color: white; }
        .btn-delete-sm { background: #fff5f5; color: var(--danger); }
        .btn-delete-sm:hover { background: var(--danger); color: white; }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
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

        /* ── Filter bar ── */
        .filter-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .filter-bar .form-control,
        .filter-bar .form-select {
            border: 1.5px solid var(--border); border-radius: 8px;
            padding: 8px 12px; font-size: 13px; font-family: inherit; outline: none;
            transition: border-color var(--transition);
        }
        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,82,204,0.1);
        }
        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: 14px; pointer-events: none; }
        .search-wrap input { padding-left: 32px !important; }
        .btn-clear {
            background: #f1f5f9; border: 1.5px solid var(--border); color: var(--muted);
            border-radius: 8px; padding: 8px 12px; font-size: 13px; cursor: pointer;
            transition: all var(--transition);
        }
        .btn-clear:hover { border-color: var(--danger); color: var(--danger); background: #fff5f5; }

        /* ── Tentative pill ── */
        .tentative-pill {
            display: inline-block;
            background: rgba(245,158,11,0.15); color: #92400e;
            padding: 2px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 700;
            margin-left: 6px;
            border: 1px dashed rgba(245,158,11,0.5);
        }

        /* ── Overdue pill — tentative date has already passed, needs admin review ── */
        .overdue-pill {
            display: inline-block;
            background: rgba(220,53,69,0.12); color: #b91c1c;
            padding: 2px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 700;
            margin-left: 6px;
            border: 1px dashed rgba(220,53,69,0.5);
            animation: pulse-overdue 1.6s ease-in-out infinite;
        }
        @keyframes pulse-overdue {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.5; }
        }

        /* ── Audit log action badges ── */
        .audit-badge {
            display: inline-block;
            padding: 2px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .audit-badge.add     { background: #dcfce7; color: #15803d; }
        .audit-badge.edit    { background: #eff6ff; color: #1d4ed8; }
        .audit-badge.delete  { background: #fff5f5; color: #b91c1c; }
        .audit-badge.setting { background: #fefce8; color: #92400e; }
        .audit-badge.auth    { background: #f3e8ff; color: #7c3aed; }

        /* ── Modal internals ── */
        .modal .form-control,
        .modal .form-select {
            border: 1.5px solid var(--border); border-radius: 8px;
            padding: 10px 14px; font-size: 14px; font-family: inherit; outline: none;
            transition: all var(--transition); width: 100%;
        }
        .modal .form-control:focus,
        .modal .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,82,204,0.1);
        }
        .modal-title-bar {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white; padding: 18px 24px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .modal-title-bar h5 { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .modal-body   { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }

        .form-label-custom { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; display: block; }


        /* ── Password input with eye toggle ── */
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 40px !important; }
        .pw-eye { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #aaa; cursor: pointer; font-size: 16px; transition: color .15s; }
        .pw-eye:hover { color: var(--primary); }

        /* ── Display settings grid ── */
        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
        .settings-grid label { font-size: 12px; font-weight: 700; color: var(--muted); display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }

        .setting-select {
            width: 100%; border: 1.5px solid var(--border); border-radius: 8px; padding: 10px 36px 10px 14px;
            font-size: 14px; font-weight: 600; color: var(--primary); background: white;
            appearance: none; cursor: pointer; font-family: inherit; outline: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%230052CC' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            transition: border-color var(--transition);
        }
        .setting-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,82,204,0.1); }
        .setting-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }

        .summary-row {
            display: flex; flex-wrap: wrap; gap: 20px; align-items: center;
            padding: 14px 18px; background: #f0f4ff; border-radius: 10px;
            border-left: 3px solid var(--primary); margin-top: 16px;
        }
        .summary-item .s-lbl { font-size: 10px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-item .s-val { font-size: 18px; font-weight: 800; color: var(--primary); }

        /* ── Toast notifications ── */
        .toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
        .toast-msg {
            background: white; border-radius: 10px; padding: 12px 18px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15); font-size: 13px; font-weight: 600;
            display: flex; align-items: center; gap: 8px;
            animation: slideUp .22s ease;
            border-left: 4px solid var(--success);
            font-family: inherit;
        }
        .toast-msg.err { border-left-color: var(--danger); }
        @keyframes slideUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

        /* ── User management ── */
        .user-row-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-superadmin { background: rgba(245,158,11,0.15); color: #92400e; }
        .badge-admin      { background: rgba(0,82,204,0.1);    color: var(--primary); }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .sidebar          { width: var(--sidebar-collapsed); }
            .main-content     { margin-left: var(--sidebar-collapsed); }
            .stats-grid       { grid-template-columns: 1fr; }
            .topbar           { padding: 0 16px; }
            .page             { padding: 16px; }
        }
    </style>
</head>
<body>

<!-- ============================================================
     SIDEBAR
============================================================ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../display/assets/logo2.png" alt="Logo" class="sidebar-logo">
        <div class="sidebar-brand-text">
            <span class="brand-name">New Sinai MDI Hospital</span>
            <span class="brand-sub"><?= $isSuperadmin ? 'Super Admin' : 'Admin Panel' ?></span>
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

        <?php if ($isSuperadmin): ?>
        <span class="nav-section-label">Administration</span>
        <a class="nav-item" id="nav-users" onclick="showPage('users')">
            <i class="bi bi-people-fill"></i>
            <span class="nav-label">User Accounts</span>
        </a>
        <a class="nav-item" id="nav-audit" onclick="showPage('audit')">
            <i class="bi bi-journal-text"></i>
            <span class="nav-label">Audit Log</span>
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

<!-- ============================================================
     MAIN CONTENT
============================================================ -->
<div class="main-content" id="mainContent">

    <!-- Top bar -->
    <div class="topbar">
        <div class="topbar-left">
            <div>
                <div class="page-title" id="topbar-title">Dashboard</div>
                <div class="page-breadcrumb" id="topbar-sub">Overview of doctor availability</div>
            </div>
        </div>
        <div class="topbar-right">
            <div class="user-pill">
                <div class="user-avatar"><?= strtoupper(substr($currentUsername, 0, 1)) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($currentUsername) ?></div>
                    <div class="user-role"><?= $isSuperadmin ? 'Super Admin' : 'Admin' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Dashboard ── -->
    <div class="page active" id="page-dashboard">
        <div class="stats-grid">
            <div class="stat-card available">
                <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-count" id="count-available"><?= $countAvailable ?></div>
                    <div class="stat-label">Available Today</div>
                </div>
            </div>
            <div class="stat-card noclinic">
                <div class="stat-icon"><i class="bi bi-x-circle-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-count" id="count-noclinic"><?= $countNoClinic ?></div>
                    <div class="stat-label">No Clinic Today</div>
                </div>
            </div>
            <div class="stat-card onleave">
                <div class="stat-icon"><i class="bi bi-calendar-x-fill"></i></div>
                <div class="stat-info">
                    <div class="stat-count" id="count-onleave"><?= $countOnLeave ?></div>
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

    <!-- ── Doctor List ── -->
    <div class="page" id="page-doctors">
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title"><i class="bi bi-person-lines-fill"></i> Doctor List</div>
                <div class="filter-bar">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" class="form-control" id="global-search"
                               placeholder="Search by name…" oninput="filterTable()" style="width:200px;">
                    </div>
                    <select id="f-dept" class="form-select" onchange="filterTable()" style="width:150px;">
                        <option value="">All Departments</option>
                        <option>OPD</option>
                        <option>ER</option>
                        <option>Pediatrics</option>
                        <option>Cardiology</option>
                        <option>Radiology</option>
                        <option>Laboratory</option>
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

            <div class="section-card-header" style="border-top:none; padding-top:0; justify-content:flex-end;">
                <div style="display:flex; gap:8px;">
                    <button class="btn-primary-custom" onclick="showDoctorModal()">
                        <i class="bi bi-plus-lg"></i> Add Doctor
                    </button>
                    <button class="btn-icon btn-delete-sm" id="delete-selected-btn"
                            onclick="deleteSelected()" style="display:none; padding:10px 14px;">
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
                            <th onclick="sortTable(1)">Name <i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.5;" id="si-1"></i></th>
                            <th onclick="sortTable(2)">Department <i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.5;" id="si-2"></i></th>
                            <th onclick="sortTable(3)">Status <i class="bi bi-arrow-down-up" style="font-size:10px;opacity:.5;" id="si-3"></i></th>
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

    <!-- ── Display Settings ── -->
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
                            <option value="15"  <?= $currentSpeed === 15  ? 'selected' : '' ?>>🐢 Slow</option>
                            <option value="25"  <?= $currentSpeed === 25  ? 'selected' : '' ?>>👍 Normal</option>
                            <option value="50"  <?= $currentSpeed === 50  ? 'selected' : '' ?>>⚡ Fast</option>
                        </select>
                        <div class="setting-hint">How quickly the list moves on screen</div>
                    </div>
                    <div>
                        <label>Wait Time at Top</label>
                        <select id="set-top" class="setting-select">
                            <option value="2000"  <?= $currentTop === 2000  ? 'selected' : '' ?>>2 seconds</option>
                            <option value="3000"  <?= $currentTop === 3000  ? 'selected' : '' ?>>3 seconds</option>
                            <option value="5000"  <?= $currentTop === 5000  ? 'selected' : '' ?>>5 seconds</option>
                            <option value="8000"  <?= $currentTop === 8000  ? 'selected' : '' ?>>8 seconds</option>
                            <option value="10000" <?= $currentTop === 10000 ? 'selected' : '' ?>>10 seconds</option>
                        </select>
                        <div class="setting-hint">Pause before scrolling down</div>
                    </div>
                    <div>
                        <label>Wait Time at Bottom</label>
                        <select id="set-bot" class="setting-select">
                            <option value="2000"  <?= $currentBottom === 2000  ? 'selected' : '' ?>>2 seconds</option>
                            <option value="3000"  <?= $currentBottom === 3000  ? 'selected' : '' ?>>3 seconds</option>
                            <option value="5000"  <?= $currentBottom === 5000  ? 'selected' : '' ?>>5 seconds</option>
                            <option value="8000"  <?= $currentBottom === 8000  ? 'selected' : '' ?>>8 seconds</option>
                            <option value="10000" <?= $currentBottom === 10000 ? 'selected' : '' ?>>10 seconds</option>
                        </select>
                        <div class="setting-hint">Pause before scrolling back up</div>
                    </div>
                </div>

                <button class="btn-primary-custom" onclick="saveDisplaySettings()">
                    <i class="bi bi-save"></i> Save Settings
                </button>

                <div class="summary-row mt-3">
                    <strong style="font-size:13px;color:var(--muted);">Currently Active:</strong>
                    <div class="summary-item">
                        <div class="s-lbl">Speed</div>
                        <div class="s-val" id="sum-speed"><?= htmlspecialchars($currentSpeedLabel) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="s-lbl">Pause Top</div>
                        <div class="s-val" id="sum-top"><?= htmlspecialchars($currentTopLabel) ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="s-lbl">Pause Bottom</div>
                        <div class="s-val" id="sum-bot"><?= htmlspecialchars($currentBottomLabel) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isSuperadmin): ?>
    <!-- ── User Accounts ── -->
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

    <?php if ($isSuperadmin): ?>
    <!-- ── Audit Log ── -->
    <div class="page" id="page-audit">
        <div class="section-card">
            <div class="section-card-header">
                <div class="section-card-title"><i class="bi bi-journal-text"></i> Audit Log</div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="text" id="audit-search" class="form-control"
                           placeholder="Search user, action…"
                           oninput="auditSearchDebounced()"
                           style="width:220px; font-size:13px; padding:7px 12px;">
                    <button class="btn-danger-custom" onclick="clearAuditLog()" style="padding:7px 16px; font-size:13px;">
                        <i class="bi bi-trash"></i> Clear Log
                    </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:160px;">Date &amp; Time</th>
                            <th style="width:120px;">Performed By</th>
                            <th style="width:160px;">Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="audit-tbody">
                        <tr><td colspan="4" class="table-empty">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div id="audit-pagination" style="display:flex; justify-content:space-between; align-items:center;
                 padding:12px 16px; border-top:1px solid var(--border); font-size:13px; color:var(--muted);">
                <span id="audit-count"></span>
                <div style="display:flex; gap:6px;">
                    <button class="btn-icon btn-edit-sm" id="audit-prev" onclick="auditChangePage(-1)">
                        <i class="bi bi-chevron-left"></i> Prev
                    </button>
                    <span id="audit-page-label" style="padding:4px 10px; font-weight:600;"></span>
                    <button class="btn-icon btn-edit-sm" id="audit-next" onclick="auditChangePage(1)">
                        Next <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /main-content -->

<!-- ============================================================
     MODALS
============================================================ -->

<!-- Add / Edit Doctor -->
<div class="modal fade" id="doctorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border:none; border-radius:14px; overflow:hidden;">
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
                            <option>OPD</option>
                            <option>ER</option>
                            <option>Pediatrics</option>
                            <option>Cardiology</option>
                            <option>Radiology</option>
                            <option>Laboratory</option>
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
                            <label class="form-check-label" for="m-tentative" style="font-size:13px; font-weight:600;">
                                <i class="bi bi-calendar-question"></i> Resume date is tentative
                            </label>
                        </div>
                    <div class="col-12 leave-fields" style="display:none;">
                        <label class="form-label-custom">Leave Type *</label>
                        <select class="form-select" id="m-remarks">
                            <option value="">— Select leave type —</option>
                            <?php foreach (LEAVE_TYPES as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    </div>
                </div>
                <div id="form-error" class="mt-3"
                     style="display:none; color:var(--danger); font-size:13px; font-weight:600;
                            padding:10px 14px; background:#fff5f5; border-radius:8px; border-left:3px solid var(--danger);">
                </div>
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

<!-- Confirm Delete All -->
<div class="modal fade" id="deleteAllModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border:none; border-radius:14px; overflow:hidden;">
            <div class="modal-title-bar" style="background:var(--danger);">
                <h5><i class="bi bi-exclamation-triangle"></i> Delete All Doctors</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:14px; color:var(--muted); margin-bottom:12px;">
                    <strong style="color:var(--danger);">⚠️ Warning:</strong> This will permanently delete ALL doctors.
                </p>
                <p style="font-size:13px; margin-bottom:8px;">Type <strong>DELETE ALL</strong> to confirm:</p>
                <input type="text" id="confirm-input" class="form-control" placeholder="Type DELETE ALL">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-danger-custom" id="confirm-delete-btn" disabled onclick="confirmDeleteAll()">
                    Delete ALL
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Change Password -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="border:none; border-radius:14px; overflow:hidden;">
            <div class="modal-title-bar">
                <h5><i class="bi bi-key-fill"></i> Change Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="cp-alert" style="display:none;" class="mb-3"></div>
                <div class="mb-3">
                    <label class="form-label-custom">Current Password or Master Reset Key</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" id="pw_current"
                               placeholder="Current password or reset key" autocomplete="off">
                        <button type="button" class="pw-eye" onclick="togglePw('pw_current', this)" tabindex="-1">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div style="font-size:11px; color:#aaa; margin-top:4px;">
                        <i class="bi bi-info-circle"></i> Forgot password? Use the master reset key.
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label-custom">New Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" id="pw_new"
                               placeholder="Min. 6 characters" autocomplete="new-password">
                        <button type="button" class="pw-eye" onclick="togglePw('pw_new', this)" tabindex="-1">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label-custom">Confirm New Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" id="pw_confirm"
                               placeholder="Repeat new password" autocomplete="new-password">
                        <button type="button" class="pw-eye" onclick="togglePw('pw_confirm', this)" tabindex="-1">
                            <i class="bi bi-eye"></i>
                        </button>
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

<?php if ($isSuperadmin): ?>
<!-- Add / Edit User -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border:none; border-radius:14px; overflow:hidden;">
            <div class="modal-title-bar">
                <h5 id="au-modal-title"><i class="bi bi-person-plus-fill"></i> Add User Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="au-alert" style="display:none;" class="mb-3"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-custom">Username</label>
                        <input type="text" class="form-control" id="au-username"
                               placeholder="Enter username" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom" id="au-pw-label">Password</label>
                        <div class="pw-wrap">
                            <input type="password" class="form-control" id="au-password"
                                   placeholder="Min. 6 characters" autocomplete="new-password">
                            <button type="button" class="pw-eye" onclick="togglePw('au-password', this)" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div id="au-pw-hint" style="display:none; font-size:11px; color:var(--muted); margin-top:4px;">
                            <i class="bi bi-info-circle"></i> Leave blank to keep current password.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">Role</label>
                        <select class="form-select" id="au-role">
                            <option value="0">Admin</option>
                            <option value="1">Super Admin</option>
                        </select>
                        <div style="font-size:11px; color:var(--muted); margin-top:4px;">
                            <i class="bi bi-info-circle"></i> Super Admin can manage user accounts and change passwords.
                        </div>
                    </div>
                    <div class="col-md-6" id="au-admin-pw-wrap" style="display:none;">
                        <label class="form-label-custom">Administrator Password <span style="color:var(--danger);">*</span></label>
                        <div class="pw-wrap">
                            <input type="password" class="form-control" id="au-admin-password"
                                   placeholder="Required to change password" autocomplete="off">
                            <button type="button" class="pw-eye" onclick="togglePw('au-admin-password', this)" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div style="font-size:11px; color:var(--muted); margin-top:4px;">
                            <i class="bi bi-shield-lock"></i> Enter the administrator password to confirm this change.
                        </div>
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
        <div class="modal-content" style="border:none; border-radius:14px; overflow:hidden;">
            <div class="modal-title-bar" style="background:linear-gradient(135deg, #1e3a5f, #0052CC);">
                <h5><i class="bi bi-box-arrow-right"></i> Confirm Logout</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="text-align:center; padding:32px 24px;">
                <div style="font-size:16px; font-weight:700; margin-bottom:8px;">Are you sure you want to logout?</div>
                <div style="font-size:13px; color:var(--muted);">You will be redirected to the login page.</div>
            </div>
            <div class="modal-footer" style="justify-content:center; gap:10px; padding-bottom:24px; border:none;">
                <a href="logout.php" class="btn-danger-custom" style="padding:8px 22px; font-size:13px; text-decoration:none;">
                    <i class="bi bi-box-arrow-right"></i> Yes, Logout
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                        style="padding:8px 22px; border-radius:10px; font-weight:600; font-size:13px;">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================================
//  State
// ============================================================
let allDoctors   = <?= json_encode($initialDoctors) ?>;
let editingId    = 0;
let sortCol      = -1;
let sortAsc      = true;
let editingUserId = 0;

const IS_SUPERADMIN = <?= $isSuperadmin ? 'true' : 'false' ?>;

const SPEED_LABELS = { 15: 'Slow', 25: 'Normal', 50: 'Fast' };
const PAUSE_LABELS = { 2000: '2 seconds', 3000: '3 seconds', 5000: '5 seconds', 8000: '8 seconds', 10000: '10 seconds' };

const PAGE_META = {
    dashboard : { title: 'Dashboard',        sub: 'Overview of doctor availability' },
    doctors   : { title: 'Doctor List',      sub: 'Manage all doctor records' },
    display   : { title: 'Display Settings', sub: 'Configure TV display scroll behavior' },
    users     : { title: 'User Accounts',    sub: 'Manage admin user accounts' },
    audit     : { title: 'Audit Log',         sub: 'Track all changes made by admin users' },
};

// ============================================================
//  Utilities
// ============================================================

/** HTML-escape a string for safe insertion into the DOM. */
function escH(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/** Format a YYYY-MM-DD string to "Mon DD, YYYY". */
function fmtDate(s) {
    if (!s) return '—';
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
}

/** Map a doctor label to its CSS badge class. */
function badgeClass(label) {
    if (label === 'Available') return 'badge-available';
    if (label === 'No Clinic') return 'badge-noclinic';
    return 'badge-onleave';
}

/** Show a temporary toast notification. */
function toast(message, isError = false) {
    const container = document.getElementById('toast-wrap');
    const el = document.createElement('div');
    el.className = 'toast-msg' + (isError ? ' err' : '');
    el.innerHTML = `<i class="bi ${isError ? 'bi-x-circle-fill' : 'bi-check-circle-fill'}"
                       style="color:${isError ? 'var(--danger)' : 'var(--success)'}"></i>${message}`;
    container.appendChild(el);
    setTimeout(() => el.remove(), 3200);
}

/** Show an inline form error message. */
function showFormError(el, message) {
    el.textContent = message;
    el.style.display = 'block';
}

/** POST data as FormData and return parsed JSON. */
async function apiPost(data) {
    const fd = new FormData();
    for (const key in data) fd.append(key, data[key]);
    const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
    return res.json();
}

// ============================================================
//  Sidebar
// ============================================================

let sidebarCollapsed = false;

function toggleSidebar() {
    sidebarCollapsed = !sidebarCollapsed;
    document.getElementById('sidebar').classList.toggle('collapsed', sidebarCollapsed);
    document.getElementById('mainContent').classList.toggle('sidebar-collapsed', sidebarCollapsed);
    document.getElementById('toggle-icon').className = sidebarCollapsed ? 'bi bi-chevron-right' : 'bi bi-chevron-left';
}

// ============================================================
//  Page navigation
// ============================================================

function showPage(page) {
    document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

    document.getElementById('page-' + page)?.classList.add('active');
    document.getElementById('nav-'  + page)?.classList.add('active');

    // Load data lazily when navigating to a page
    if (page === 'audit') loadAuditLog();

    const meta = PAGE_META[page] ?? {};
    document.getElementById('topbar-title').textContent = meta.title ?? page;
    document.getElementById('topbar-sub').textContent   = meta.sub   ?? '';

    if (page === 'users')     loadUsers();
    if (page === 'dashboard') updateDashboard();
}

// ============================================================
//  Dashboard
// ============================================================

function updateDashboard() {
    const counts = {
        available : allDoctors.filter(d => d.label === 'Available').length,
        noclinic  : allDoctors.filter(d => d.label === 'No Clinic').length,
        onleave   : allDoctors.filter(d => d.label === 'On Leave').length,
    };

    document.getElementById('count-available').textContent = counts.available;
    document.getElementById('count-noclinic').textContent  = counts.noclinic;
    document.getElementById('count-onleave').textContent   = counts.onleave;

    const tbody = document.getElementById('dashboard-tbody');
    const recent = allDoctors.slice(0, 8);

    if (!recent.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="table-empty">No doctors yet</td></tr>';
        return;
    }

    tbody.innerHTML = recent.map(d => `
        <tr>
            <td style="font-weight:600;">${escH(d.name)}</td>
            <td>${escH(d.department ?? '')}</td>
            <td><span class="badge-status ${badgeClass(d.label)}">${escH(d.label)}</span></td>
            <td>${d.resume_date ? fmtDate(d.resume_date) : '<span style="color:#ccc;">—</span>'}</td>
        </tr>
    `).join('');
}

// ============================================================
//  Doctor table
// ============================================================

function renderTable(doctors) {
    const tbody = document.getElementById('doctor-tbody');

    if (!doctors.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="table-empty">
            <i class="bi bi-inbox" style="font-size:2rem; display:block; margin-bottom:8px;"></i>
            No doctors found
        </td></tr>`;
        updateDashboard();
        return;
    }

    tbody.innerHTML = doctors.map(d => {
        const today      = new Date(); today.setHours(0,0,0,0);
        const resumePast = d.resume_date && new Date(d.resume_date) < today;
        const resumeHtml = d.resume_date
            ? `${fmtDate(d.resume_date)}${
                d.is_tentative && resumePast
                    ? '<span class="overdue-pill">⚠ OVERDUE — Review</span>'
                    : d.is_tentative
                        ? '<span class="tentative-pill">TENTATIVE</span>'
                        : ''
              }`
            : '<span style="color:#ccc;">—</span>';

        const remarksHtml = (d.remarks && d.label === 'On Leave')
            ? escH(d.remarks)
            : '<span style="color:#ccc;">—</span>';

        return `
            <tr data-id="${d.id}"
                data-name="${escH(d.name.toLowerCase())}"
                data-dept="${escH((d.department ?? '').toLowerCase())}"
                data-status="${escH(d.label)}"
                data-date="${escH(d.resume_date ?? '')}"
                data-leave="${escH(d.remarks ?? '')}">
                <td><input type="checkbox" class="doctor-checkbox" value="${d.id}" onchange="updateDeleteBtn()"></td>
                <td style="font-weight:600;">${escH(d.name)}</td>
                <td>${escH(d.department ?? '')}</td>
                <td><span class="badge-status ${badgeClass(d.label)}">${escH(d.label)}</span></td>
                <td>${resumeHtml}</td>
                <td>${remarksHtml}</td>
                <td>
                    <button class="btn-icon btn-edit-sm"   onclick="openEditModal(${d.id})"><i class="bi bi-pencil"></i></button>
                    <button class="btn-icon btn-delete-sm" onclick="deleteOne(${d.id}, this)"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        `;
    }).join('');

    filterTable();
    updateDeleteBtn();
    updateDashboard();
}

// ── Sorting ───────────────────────────────────────────────────────────────────

const SORT_KEYS = [null, 'name', 'department', 'label', 'resume_date', 'remarks'];

function sortTable(col) {
    sortAsc = (sortCol === col) ? !sortAsc : true;
    sortCol = col;

    // Update sort icons
    for (let i = 1; i <= 5; i++) {
        const icon = document.getElementById('si-' + i);
        if (!icon) continue;
        if (i === col) {
            icon.className = 'bi ' + (sortAsc ? 'bi-arrow-up' : 'bi-arrow-down');
        } else {
            icon.className = 'bi bi-arrow-down-up';
            icon.style.opacity = '.5';
        }
    }

    const key = SORT_KEYS[col];
    allDoctors.sort((a, b) => {
        const va = (a[key] ?? '').toLowerCase();
        const vb = (b[key] ?? '').toLowerCase();
        return sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
    });

    renderTable(allDoctors);
}

// ── Filtering ─────────────────────────────────────────────────────────────────

function filterTable() {
    const search = (document.getElementById('global-search')?.value ?? '').toLowerCase().trim();
    const dept   = (document.getElementById('f-dept')?.value          ?? '').toLowerCase().trim();
    const status = (document.getElementById('f-status')?.value        ?? '').trim();

    document.querySelectorAll('#doctor-tbody tr').forEach(row => {
        if (!row.dataset.id) { row.style.display = ''; return; }

        const visible =
            (!search || row.dataset.name.includes(search))  &&
            (!dept   || row.dataset.dept.includes(dept))    &&
            (!status || row.dataset.status === status);

        row.style.display = visible ? '' : 'none';
    });
}

function clearAllFilters() {
    ['global-search'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    ['f-dept', 'f-status'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    filterTable();
}

// ── Doctor modal ──────────────────────────────────────────────────────────────

const STATUS_MAP = {
    'on schedule'  : 'On Schedule',
    'available'    : 'On Schedule',
    'no medical'   : 'No Medical',
    'no clinic'    : 'No Medical',
    'not available': 'No Medical',
    'on leave'     : 'On Leave',
};

function toggleLeaveFields() {
    const isLeave = document.getElementById('m-status').value === 'On Leave';
    document.querySelectorAll('.leave-fields').forEach(el => el.style.display = isLeave ? '' : 'none');
    if (!isLeave) {
        document.getElementById('m-remarks').value = '';
        document.getElementById('m-resume').value  = '';
        document.getElementById('m-tentative').checked = false;
    }
}

function showDoctorModal() {
    editingId = 0;
    document.getElementById('doctorModalTitle').innerHTML = '<i class="bi bi-plus-circle"></i> Add New Doctor';
    document.getElementById('m-name').value    = '';
    document.getElementById('m-resume').value  = '';
    document.getElementById('m-dept').value    = '';
    document.getElementById('m-status').value  = 'On Schedule';
    document.getElementById('m-tentative').checked = false;
    document.getElementById('m-remarks').value = '';
    document.getElementById('form-error').style.display = 'none';
    toggleLeaveFields();
    new bootstrap.Modal(document.getElementById('doctorModal')).show();
}

function openEditModal(id) {
    const doctor = allDoctors.find(d => d.id == id);
    if (!doctor) return;

    editingId = id;
    const mappedStatus = STATUS_MAP[(doctor.status ?? '').toLowerCase().trim()] ?? 'On Schedule';

    document.getElementById('doctorModalTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Doctor';
    document.getElementById('m-name').value    = doctor.name;
    document.getElementById('m-dept').value    = doctor.department ?? '';
    document.getElementById('m-status').value  = mappedStatus;
    document.getElementById('m-resume').value  = doctor.resume_date ?? '';
    document.getElementById('m-tentative').checked = doctor.is_tentative == 1;
    document.getElementById('form-error').style.display = 'none';
    toggleLeaveFields();
    document.getElementById('m-remarks').value = doctor.remarks ?? '';

    new bootstrap.Modal(document.getElementById('doctorModal')).show();
}

async function saveDoctor() {
    const name      = document.getElementById('m-name').value.trim();
    const dept      = document.getElementById('m-dept').value;
    const status    = document.getElementById('m-status').value;
    const resume    = document.getElementById('m-resume').value;
    const tentative = document.getElementById('m-tentative').checked ? 1 : 0;
    const remarks   = document.getElementById('m-remarks').value;
    const errEl     = document.getElementById('form-error');

    errEl.style.display = 'none';

    // Validate each field individually for accurate error messages
    if (!name)   { showFormError(errEl, 'Doctor name is required.'); return; }
    if (!dept)   { showFormError(errEl, 'Please select a department.'); return; }
    if (!status) { showFormError(errEl, 'Please select a status.'); return; }
    if (status === 'On Leave' && !resume)  { showFormError(errEl, 'Resume date is required for On Leave.'); return; }
    if (status === 'On Leave' && !remarks) { showFormError(errEl, 'Please select a leave type.'); return; }

    const payload = { ajax: 'save_doctor', id: editingId, name, department: dept, status, resume_date: resume, remarks };
    if (tentative) payload.is_tentative = '1'; // omit key entirely when unchecked — PHP uses isset()

    const res = await apiPost(payload);

    if (!res.ok) {
        errEl.textContent = res.error;
        errEl.style.display = 'block';
        return;
    }

    bootstrap.Modal.getInstance(document.getElementById('doctorModal'))?.hide();

    if (res.insert) {
        allDoctors.push(res.doctor);
    } else {
        const idx = allDoctors.findIndex(d => d.id == res.doctor.id);
        if (idx > -1) allDoctors[idx] = res.doctor;
    }

    renderTable(allDoctors);
    toast(res.insert ? 'Doctor added!' : 'Doctor updated!');
}

// ── Delete ────────────────────────────────────────────────────────────────────

async function deleteOne(id, btn) {
    if (!confirm('Delete this doctor?')) return;

    const res = await apiPost({ ajax: 'delete_doctor', id });
    if (!res.ok) { toast(res.error ?? 'Delete failed', true); return; }

    allDoctors = allDoctors.filter(d => d.id != id);

    const row = btn.closest('tr');
    row.style.transition = 'opacity .3s';
    row.style.opacity = '0';
    setTimeout(() => renderTable(allDoctors), 300);

    toast('Doctor deleted.');
}

function toggleSelectAll() {
    const checked = document.getElementById('select-all').checked;
    document.querySelectorAll('.doctor-checkbox').forEach(cb => cb.checked = checked);
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const selected = document.querySelectorAll('.doctor-checkbox:checked').length;
    const btn = document.getElementById('delete-selected-btn');
    btn.style.display = selected > 0 ? 'inline-flex' : 'none';
    if (selected > 0) btn.innerHTML = `<i class="bi bi-trash"></i> Delete Selected (${selected})`;
    document.getElementById('select-all').checked =
        selected > 0 && selected === document.querySelectorAll('.doctor-checkbox').length;
}

async function deleteSelected() {
    const ids = Array.from(document.querySelectorAll('.doctor-checkbox:checked')).map(cb => cb.value);
    if (!ids.length) { toast('No doctors selected', true); return; }
    if (!confirm(`Delete ${ids.length} doctor(s)?`)) return;

    const res = await apiPost({ ajax: 'delete_selected', ids: JSON.stringify(ids) });
    if (!res.ok) { toast('Delete failed', true); return; }

    allDoctors = allDoctors.filter(d => !ids.includes(String(d.id)));
    renderTable(allDoctors);
    toast(`${ids.length} doctor(s) deleted.`);
}

function showDeleteAllModal() {
    document.getElementById('confirm-input').value = '';
    document.getElementById('confirm-delete-btn').disabled = true;
    new bootstrap.Modal(document.getElementById('deleteAllModal')).show();
}

document.getElementById('confirm-input').addEventListener('input', function () {
    document.getElementById('confirm-delete-btn').disabled = this.value !== 'DELETE ALL';
});

async function confirmDeleteAll() {
    if (document.getElementById('confirm-input').value !== 'DELETE ALL') return;
    if (!confirm('Are you absolutely sure?')) return;

    const res = await apiPost({ ajax: 'delete_all' });
    bootstrap.Modal.getInstance(document.getElementById('deleteAllModal'))?.hide();

    if (!res.ok) { toast('Delete failed', true); return; }
    allDoctors = [];
    renderTable([]);
    toast('All doctors deleted.');
}

// ============================================================
//  Display settings
// ============================================================

async function saveDisplaySettings() {
    const speed  = document.getElementById('set-speed').value;
    const top    = document.getElementById('set-top').value;
    const bottom = document.getElementById('set-bot').value;

    const res = await apiPost({ ajax: 'save_display', scroll_speed: speed, pause_at_top: top, pause_at_bottom: bottom });
    if (!res.ok) { toast('Failed to save', true); return; }

    document.getElementById('sum-speed').textContent = SPEED_LABELS[speed] ?? `${speed} px/s`;
    document.getElementById('sum-top').textContent   = PAUSE_LABELS[top]   ?? `${top / 1000} sec`;
    document.getElementById('sum-bot').textContent   = PAUSE_LABELS[bottom]?? `${bottom / 1000} sec`;

    toast('Display settings saved!');
}

// ============================================================
//  Password management
// ============================================================

function showChangePasswordModal() {
    ['pw_current', 'pw_new', 'pw_confirm'].forEach(id => document.getElementById(id).value = '');
    const alert = document.getElementById('cp-alert');
    alert.style.display = 'none';
    new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
}

async function savePassword() {
    const current = document.getElementById('pw_current').value;
    const newPw   = document.getElementById('pw_new').value;
    const confirm = document.getElementById('pw_confirm').value;
    const alertEl = document.getElementById('cp-alert');
    alertEl.style.display = 'none';

    const res = await apiPost({ ajax: 'change_password', current_or_key: current, new_password: newPw, confirm_password: confirm });

    if (!res.ok) {
        alertEl.className = 'alert alert-danger py-2';
        alertEl.style.display = 'block';
        alertEl.innerHTML = `<i class="bi bi-x-circle-fill"></i> ${res.error}`;
        return;
    }

    alertEl.className = 'alert alert-success py-2';
    alertEl.style.display = 'block';
    alertEl.innerHTML = `<i class="bi bi-check-circle-fill"></i> ${res.message}`;
    setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('changePasswordModal'))?.hide(), 1500);
    toast('Password changed!');
}

function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// ============================================================
//  User management
// ============================================================

async function loadUsers() {
    const res  = await fetch(window.location.pathname + '?ajax=users');
    const data = await res.json();
    if (!data.ok) return;
    renderUsersTable(data.users);
}

function renderUsersTable(users) {
    const tbody = document.getElementById('users-tbody');
    if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="table-empty">No users found</td></tr>';
        return;
    }

    tbody.innerHTML = users.map((user, index) => `
        <tr>
            <td style="color:var(--muted);">${index + 1}</td>
            <td style="font-weight:600;">${escH(user.username)}</td>
            <td>
                <span class="user-row-badge ${user.is_superadmin == 1 ? 'badge-superadmin' : 'badge-admin'}">
                    ${user.is_superadmin == 1 ? '⭐ Super Admin' : 'Admin'}
                </span>
            </td>
            <td style="color:var(--muted); font-size:12px;">${user.created_at ?? '—'}</td>
            <td style="display:flex; gap:6px; align-items:center;">
                <button class="btn-icon btn-edit-sm"
                        onclick="showEditUserModal(${user.id}, '${escH(user.username)}', ${user.is_superadmin})">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                ${user.is_superadmin == 1
                    ? '<span style="color:#ccc; font-size:12px;">Protected</span>'
                    : `<button class="btn-icon btn-delete-sm" onclick="deleteUser(${user.id}, '${escH(user.username)}')"><i class="bi bi-trash"></i> Delete</button>`
                }
            </td>
        </tr>
    `).join('');
}

// ── User modal helpers ───────────────────────────────────────────────────────

/**
 * Reset all user modal fields to a blank state.
 * Called by both showAddUserModal and showEditUserModal.
 */
function resetUserModal() {
    document.getElementById('au-username').value             = '';
    document.getElementById('au-password').value             = '';
    document.getElementById('au-admin-password').value       = '';
    document.getElementById('au-admin-pw-wrap').style.display = 'none';
    document.getElementById('au-alert').style.display        = 'none';
    document.getElementById('au-role').value                  = '0';
}

/**
 * When typing a new password in edit mode, reveal the admin confirmation
 * field. Clears and hides it again if the password field is emptied.
 * Registered once on page load — ignored in add mode (editingUserId === 0).
 */
function onUserPasswordInput() {
    if (editingUserId === 0) return; // not in edit mode — do nothing
    const hasNewPassword = this.value.trim() !== '';
    document.getElementById('au-admin-pw-wrap').style.display = hasNewPassword ? '' : 'none';
    if (!hasNewPassword) document.getElementById('au-admin-password').value = '';
}

function showAddUserModal() {
    editingUserId = 0;
    resetUserModal();
    document.getElementById('au-modal-title').innerHTML  = '<i class="bi bi-person-plus-fill"></i> Add User Account';
    document.getElementById('au-submit-btn').innerHTML   = '<i class="bi bi-check-circle"></i> Create Account';
    document.getElementById('au-password').placeholder  = 'Min. 6 characters';
    document.getElementById('au-pw-label').textContent  = 'Password';
    document.getElementById('au-pw-hint').style.display = 'none';
    new bootstrap.Modal(document.getElementById('addUserModal')).show();
}

function showEditUserModal(id, username, isSuperadmin) {
    editingUserId = id;
    resetUserModal();
    document.getElementById('au-modal-title').innerHTML  = '<i class="bi bi-pencil-fill"></i> Edit User Account';
    document.getElementById('au-submit-btn').innerHTML   = '<i class="bi bi-check-circle"></i> Save Changes';
    document.getElementById('au-username').value         = username;
    document.getElementById('au-password').placeholder  = 'Leave blank to keep current';
    document.getElementById('au-pw-label').textContent  = 'New Password';
    document.getElementById('au-pw-hint').style.display = 'block';
    document.getElementById('au-role').value             = isSuperadmin == 1 ? '1' : '0';
    new bootstrap.Modal(document.getElementById('addUserModal')).show();
}

async function submitUserModal() {
    const username      = document.getElementById('au-username').value.trim();
    const password      = document.getElementById('au-password').value.trim();
    const adminPassword = document.getElementById('au-admin-password').value.trim();
    const role          = document.getElementById('au-role').value;
    const alertEl       = document.getElementById('au-alert');
    alertEl.style.display = 'none';

    const action  = editingUserId === 0 ? 'add_user' : 'edit_user';
    const payload = { ajax: action, username, password, role };
    if (editingUserId !== 0) {
        payload.id = editingUserId;
        // Only send admin_password when actually changing the password
        if (password) payload.admin_password = adminPassword;
    }

    const res = await apiPost(payload);

    if (!res.ok) {
        alertEl.className = 'alert alert-danger py-2';
        alertEl.style.display = 'block';
        alertEl.innerHTML = `<i class="bi bi-x-circle-fill"></i> ${res.error}`;
        return;
    }

    bootstrap.Modal.getInstance(document.getElementById('addUserModal'))?.hide();
    toast(editingUserId === 0 ? `User "${username}" created!` : `User "${username}" updated!`);
    loadUsers();
}

async function deleteUser(id, name) {
    if (!confirm(`Delete user "${name}"? They will no longer be able to log in.`)) return;

    const res = await apiPost({ ajax: 'delete_user', id });
    if (!res.ok) { toast(res.error ?? 'Delete failed', true); return; }

    toast(`User "${name}" deleted.`);
    loadUsers();
}

function showLogoutModal() {
    new bootstrap.Modal(document.getElementById('logoutModal')).show();
}

// ============================================================
//  Background polling (users table — silent, no page reload)
// ============================================================

let lastUsersHash = '';

async function pollUsers() {
    if (!IS_SUPERADMIN) return;
    try {
        const res  = await fetch(window.location.pathname + '?ajax=users');
        const data = await res.json();
        if (!data.ok) return;

        const hash = JSON.stringify(data.users);
        if (hash === lastUsersHash) return; // nothing changed
        lastUsersHash = hash;

        // Only update the DOM if the users page is currently visible
        if (document.getElementById('users-tbody')) {
            renderUsersTable(data.users);
        }
    } catch (e) {
        // Network error — fail silently, will retry next interval
    }
}

// ============================================================
//  Audit Log
// ============================================================

let auditPage       = 1;
let auditTotalPages = 1;
let auditSearchTerm = '';
let auditDebounce   = null;

/** Map action key → badge class and display label. */
const AUDIT_META = {
    doctor_added          : { cls: 'add',     label: 'Doctor Added' },
    doctor_edited         : { cls: 'edit',    label: 'Doctor Edited' },
    doctor_deleted        : { cls: 'delete',  label: 'Doctor Deleted' },
    doctors_bulk_deleted  : { cls: 'delete',  label: 'Bulk Delete' },
    doctors_all_deleted   : { cls: 'delete',  label: 'All Deleted' },
    display_settings_saved: { cls: 'setting', label: 'Display Settings' },
    password_changed      : { cls: 'auth',    label: 'Password Changed' },
    user_added            : { cls: 'add',     label: 'User Added' },
    user_edited           : { cls: 'edit',    label: 'User Edited' },
    user_deleted          : { cls: 'delete',  label: 'User Deleted' },
};

async function loadAuditLog() {
    const tbody = document.getElementById('audit-tbody');
    tbody.innerHTML = '<tr><td colspan="4" class="table-empty">Loading…</td></tr>';

    const params = new URLSearchParams({
        ajax  : 'audit_log',
        page  : auditPage,
        filter: auditSearchTerm,
    });

    const res = await fetch(window.location.pathname + '?' + params);
    const data = await res.json();

    if (!data.ok) {
        tbody.innerHTML = `<tr><td colspan="4" class="table-empty" style="color:var(--danger);">${escH(data.error)}</td></tr>`;
        return;
    }

    if (data.logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="table-empty">No audit entries found.</td></tr>';
        updateAuditPagination(data);
        return;
    }

    tbody.innerHTML = data.logs.map(log => {
        const meta  = AUDIT_META[log.action] ?? { cls: 'edit', label: log.action };
        const dt    = new Date(log.created_at).toLocaleString('en-US', {
            month: 'short', day: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true,
        });
        return `
            <tr>
                <td style="color:var(--muted); font-size:12px;">${escH(dt)}</td>
                <td><strong>${escH(log.performed_by)}</strong></td>
                <td><span class="audit-badge ${meta.cls}">${escH(meta.label)}</span></td>
                <td style="font-size:13px;">${escH(log.details)}</td>
            </tr>`;
    }).join('');

    updateAuditPagination(data);
}

function updateAuditPagination(data) {
    auditTotalPages = Math.max(1, Math.ceil(data.total / data.per_page));
    const start = data.total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
    const end   = Math.min(data.page * data.per_page, data.total);

    document.getElementById('audit-count').textContent =
        data.total === 0 ? 'No entries' : `Showing ${start}–${end} of ${data.total} entries`;
    document.getElementById('audit-page-label').textContent = `Page ${data.page} of ${auditTotalPages}`;
    document.getElementById('audit-prev').disabled = data.page <= 1;
    document.getElementById('audit-next').disabled = data.page >= auditTotalPages;
}

function auditChangePage(dir) {
    const next = auditPage + dir;
    if (next < 1 || next > auditTotalPages) return;
    auditPage = next;
    loadAuditLog();
}

function auditSearchDebounced() {
    clearTimeout(auditDebounce);
    auditDebounce = setTimeout(() => {
        auditPage       = 1;
        auditSearchTerm = document.getElementById('audit-search').value.trim();
        loadAuditLog();
    }, 350);
}

async function clearAuditLog() {
    if (!confirm('Clear the entire audit log? This cannot be undone.')) return;
    const res = await apiPost({ ajax: 'clear_audit_log' });
    if (!res.ok) { toast(res.error ?? 'Failed to clear log', true); return; }
    toast('Audit log cleared.');
    auditPage = 1;
    loadAuditLog();
}

// ============================================================
//  Init
// ============================================================

renderTable(allDoctors);
updateDashboard();

if (IS_SUPERADMIN) {
    loadUsers();
    setInterval(pollUsers, 5000);

    // Register the password-reveal listener once — onUserPasswordInput checks
    // editingUserId at call time so it safely does nothing in add mode.
    document.getElementById('au-password').addEventListener('input', onUserPasswordInput);
}
</script>
</body>
</html>