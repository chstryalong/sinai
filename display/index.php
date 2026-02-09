<?php
include("../config/db.php");
$result = $conn->query("SELECT * FROM doctors ORDER BY department, name");

// Group doctors by department
$doctors_by_dept = [];
while ($row = $result->fetch_assoc()) {
    $dept = $row['department'];
    if (!isset($doctors_by_dept[$dept])) {
        $doctors_by_dept[$dept] = [];
    }
    $doctors_by_dept[$dept][] = $row;
}

// Fetch active announcement (if any)
$ann_res = $conn->query("SELECT * FROM announcements WHERE active=1 ORDER BY id DESC LIMIT 1");
$announcement = $ann_res ? $ann_res->fetch_assoc() : null;

// Fetch display settings for scroll configuration
$display_settings_res = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1");
$display_settings = $display_settings_res ? $display_settings_res->fetch_assoc() : ['scroll_speed' => 25, 'pause_at_top' => 3000, 'pause_at_bottom' => 3000];

// Flatten doctors into a single list (keep department as property)
$all_doctors = [];
foreach ($doctors_by_dept as $dname => $list) {
    foreach ($list as $r) {
        $r['department'] = $dname;
        $all_doctors[] = $r;
    }
}

// Provide a lightweight AJAX endpoint for live updates
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    // We return announcement, display settings, and the flat doctors list
    echo json_encode([
        'announcement' => $announcement,
        'doctors'      => $all_doctors,
        'display_settings' => $display_settings
    ]);
    exit;
} 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Sinai MDI Hospital - Doctor Availability</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            /* Design tokens / color palette */
            --primary: #0052CC;
            --primary-600: #1e88e5;
            --accent: #ffc107;
            --success: #28a745;
            --danger: #dc3545;
            --muted: #eef6ff;
            --bg: #f7fbff;
            --surface: rgba(255,255,255,0.9);
            --text: #052744;
            --glass: rgba(255,255,255,0.75);
            --radius: 12px;
            --shadow-1: 0 4px 12px rgba(3,32,71,0.08);
            --shadow-2: 0 8px 24px rgba(3,32,71,0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body { 
            -webkit-font-smoothing: antialiased; 
            text-rendering: optimizeLegibility;
            overflow: hidden;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #eef6ff 0%, #f7fbff 100%);
            height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            color: #052744;
            font-size: 20px; /* Base font size for TV */
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(3,32,71,0.30), rgba(3,32,71,0.30)),
                url('assets/logo.png'),
                radial-gradient(circle at 10% 20%, rgba(1,63,113,0.06) 0%, transparent 15%),
                radial-gradient(circle at 80% 80%, rgba(30,136,229,0.04) 0%, transparent 20%),
                url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1400 300"><g fill="%230052CC" fill-opacity="0.06"><rect x="100" y="80" width="80" height="120" rx="6"/><rect x="200" y="50" width="100" height="150" rx="6"/><rect x="320" y="20" width="160" height="180" rx="6"/><rect x="500" y="90" width="80" height="110" rx="6"/><rect x="620" y="60" width="90" height="140" rx="6"/><rect x="720" y="40" width="110" height="160" rx="6"/><rect x="860" y="70" width="140" height="130" rx="6"/><rect x="1050" y="100" width="90" height="100" rx="6"/></g></svg>');
            background-repeat: no-repeat, no-repeat, no-repeat, no-repeat, no-repeat;
            background-position: center center, center center, center, center, 50% 35%;
            background-size: cover, cover, cover, cover, 85%;
            background-blend-mode: overlay;
            background-attachment: fixed;
            pointer-events: none;
            z-index: 0;
            opacity: 0.98;
        }

        .container {
            flex: 1;
            padding: 24px;
            overflow: hidden;
            display: flex;
            position: relative;
            z-index: 1;
        }

        .main-board {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            width: 100%;
            height: 100%;
            background: transparent;
            border-radius: 0;
            padding: 0;
            overflow: hidden;
            position: relative;
        }

        .status-col {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 28px 36px;
            overflow: hidden;
            min-height: 0;
            position: relative;
            background: linear-gradient(180deg, rgba(255,255,255,0.90), rgba(245,250,255,0.85));
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(3,32,71,0.12);
            border-top: 4px solid rgba(0,82,204,0.15);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .col-header { 
            font-weight: 800; 
            font-size: 32px;
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            gap: 12px; 
            padding-bottom: 16px; 
            border-bottom: 3px solid var(--accent);
            flex-shrink: 0;
        }

        .col-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .col-header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .col-count {
            font-size: 28px;
            color: var(--primary);
            opacity: 0.85;
        }

        .resumes-label {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .resumes-label i {
            font-size: 24px;
        }

        .current-date {
            font-size: 26px;
            font-weight: 750;
            color: var(--primary);
            background: rgba(0,82,204,0.1);
            padding: 8px 16px;
            border-radius: 10px;
        }

        .col-list { 
            overflow: hidden; 
            display: flex; 
            flex-direction: column; 
            gap: 12px; 
            padding-top: 8px; 
            min-height: 0;
            flex: 1;
            position: relative;
        }

        .doctor-card {
            background: rgba(255, 255, 255, 0.92);
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(3,32,71,0.08);
            border-left: 5px solid rgba(255,193,7,0.9);
            transition: all 0.3s ease;
            flex-shrink: 0;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .doctor-card:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .doctor-name {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.3;
        }

        .doctor-icon {
            font-size: 26px;
            flex-shrink: 0;
            color: var(--primary);
        }

        .doctor-specialization {
            font-size: 20px;
            color: #444;
            font-weight: 600;
            margin-bottom: 8px;
            padding-left: 36px;
        }

        .doctor-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 8px;
        }

        .doctor-name-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            min-width: 0;
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
        }

        .resume-date-right {
            font-size: 22px;
            color: var(--primary);
            font-weight: 700;
            white-space: nowrap;
            background: rgba(0,82,204,0.12);
            padding: 6px 14px;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .status-note {
            font-size: 18px;
            color: #052744;
            margin-top: 12px;
            display: block;
            font-weight: 700;
            background: rgba(255,255,255,0.95);
            padding: 10px 16px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.06);
        }

        .status-note .muted {
            font-weight: 600;
            color: #555;
            font-size: 17px;
            margin-left: 10px;
        }

        /* Smooth scrolling styles */
        .col-list {
            will-change: transform;
        }

        /* Hide scrollbar */
        .col-list::-webkit-scrollbar {
            display: none;
        }

        .col-list {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* ========== RESPONSIVE STYLES ========== */

        /* Large Desktop (1920px and up) - Default styles already set */
        
        /* Desktop (1440px to 1920px) */
        @media (max-width: 1920px) {
            body {
                font-size: 18px;
            }

            .container {
                padding: 20px;
            }

            .status-col {
                padding: 24px 32px;
            }

            .col-header {
                font-size: 28px;
                padding-bottom: 14px;
            }

            .col-count {
                font-size: 24px;
            }

            .current-date {
                font-size: 22px;
                padding: 7px 14px;
            }

            .resumes-label {
                font-size: 24px;
            }

            .doctor-card {
                padding: 18px 22px;
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 24px;
            }

            .doctor-icon {
                font-size: 22px;
            }

            .doctor-specialization {
                font-size: 18px;
                padding-left: 32px;
            }

            .resume-date-right {
                font-size: 20px;
                padding: 5px 12px;
            }

            .status-note {
                font-size: 16px;
                padding: 9px 14px;
            }

            .status-note .muted {
                font-size: 15px;
            }
        }

        /* Laptop (1024px to 1440px) */
        @media (max-width: 1440px) {
            body {
                font-size: 16px;
            }

            .container {
                padding: 18px;
            }

            .main-board {
                gap: 24px;
            }

            .status-col {
                padding: 20px 28px;
            }

            .col-header {
                font-size: 24px;
                padding-bottom: 12px;
            }

            .col-count {
                font-size: 20px;
            }

            .current-date {
                font-size: 18px;
                padding: 6px 12px;
            }

            .resumes-label {
                font-size: 20px;
            }

            .resumes-label i {
                font-size: 18px;
            }

            .doctor-card {
                padding: 16px 20px;
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 20px;
            }

            .doctor-icon {
                font-size: 18px;
            }

            .doctor-specialization {
                font-size: 16px;
                padding-left: 28px;
            }

            .resume-date-right {
                font-size: 16px;
                padding: 4px 10px;
            }

            .status-note {
                font-size: 14px;
                padding: 8px 12px;
            }

            .status-note .muted {
                font-size: 13px;
            }
        }

        /* Tablet Landscape (900px to 1024px) */
        @media (max-width: 1024px) {
            body {
                font-size: 15px;
            }

            .container {
                padding: 16px;
            }

            .main-board {
                gap: 20px;
                border-radius: 12px;
            }

            .status-col {
                padding: 18px 24px;
                border-radius: 12px;
            }

            .col-header {
                font-size: 22px;
                padding-bottom: 10px;
                border-bottom: 2px solid var(--accent);
            }

            .col-count {
                font-size: 18px;
            }

            .current-date {
                font-size: 16px;
                padding: 5px 10px;
                border-radius: 8px;
            }

            .resumes-label {
                font-size: 18px;
            }

            .resumes-label i {
                font-size: 16px;
            }

            .col-list {
                gap: 10px;
            }

            .doctor-card {
                padding: 14px 18px;
                border-radius: 10px;
                border-left: 4px solid rgba(255,193,7,0.9);
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 18px;
                gap: 8px;
            }

            .doctor-icon {
                font-size: 16px;
            }

            .doctor-specialization {
                font-size: 14px;
                padding-left: 24px;
            }

            .resume-date-right {
                font-size: 14px;
                padding: 4px 8px;
                border-radius: 6px;
            }

            .status-note {
                font-size: 13px;
                padding: 7px 10px;
                border-radius: 8px;
            }

            .status-note .muted {
                font-size: 12px;
                margin-left: 8px;
            }
        }

        /* Tablet Portrait & Mobile Landscape (600px to 900px) */
        @media (max-width: 900px) {
            body {
                font-size: 14px;
            }

            .container {
                padding: 12px;
            }

            .main-board {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .status-col {
                padding: 16px 20px;
            }

            .col-header {
                font-size: 20px;
                padding-bottom: 10px;
            }

            .col-count {
                font-size: 16px;
            }

            .current-date {
                font-size: 14px;
                padding: 4px 8px;
            }

            .resumes-label {
                font-size: 16px;
            }

            .resumes-label i {
                font-size: 14px;
            }

            .col-list {
                gap: 8px;
            }

            .doctor-card {
                padding: 12px 16px;
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 16px;
            }

            .doctor-icon {
                font-size: 14px;
            }

            .doctor-specialization {
                font-size: 13px;
                padding-left: 22px;
            }

            .doctor-name-row {
                gap: 12px;
            }

            .resume-date-right {
                font-size: 12px;
                padding: 3px 6px;
            }

            .status-note {
                font-size: 12px;
                padding: 6px 8px;
            }

            .status-note .muted {
                font-size: 11px;
            }
        }

        /* Mobile Portrait (480px to 600px) */
        @media (max-width: 600px) {
            body {
                font-size: 13px;
            }

            .container {
                padding: 10px;
            }

            .main-board {
                gap: 12px;
                border-radius: 10px;
            }

            .status-col {
                padding: 14px 16px;
                gap: 10px;
                border-radius: 10px;
                border-top: 3px solid rgba(0,82,204,0.15);
            }

            .col-header {
                font-size: 18px;
                padding-bottom: 8px;
                gap: 8px;
            }

            .col-count {
                font-size: 14px;
            }

            .current-date {
                font-size: 12px;
                padding: 3px 6px;
            }

            .resumes-label {
                font-size: 14px;
            }

            .resumes-label i {
                font-size: 12px;
            }

            .col-list {
                gap: 8px;
                padding-top: 6px;
            }

            .doctor-card {
                padding: 10px 14px;
                border-radius: 8px;
                border-left: 3px solid rgba(255,193,7,0.9);
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 14px;
                gap: 6px;
            }

            .doctor-icon {
                font-size: 13px;
            }

            .doctor-specialization {
                font-size: 11px;
                padding-left: 19px;
                margin-bottom: 6px;
            }

            .doctor-name-row {
                gap: 10px;
                margin-bottom: 6px;
            }

            .resume-date-right {
                font-size: 10px;
                padding: 2px 5px;
            }

            .status-note {
                font-size: 11px;
                padding: 5px 7px;
                margin-top: 8px;
            }

            .status-note .muted {
                font-size: 10px;
                margin-left: 6px;
            }
        }

        /* Small Mobile (up to 480px) */
        @media (max-width: 480px) {
            body {
                font-size: 12px;
            }

            .container {
                padding: 8px;
            }

            .main-board {
                gap: 10px;
            }

            .status-col {
                padding: 12px 14px;
                gap: 8px;
            }

            .col-header {
                font-size: 16px;
                padding-bottom: 8px;
                gap: 6px;
            }

            .col-count {
                font-size: 13px;
            }

            .current-date {
                font-size: 11px;
                padding: 3px 5px;
            }

            .resumes-label {
                font-size: 13px;
            }

            .resumes-label i {
                font-size: 11px;
            }

            .col-list {
                gap: 7px;
            }

            .doctor-card {
                padding: 9px 12px;
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 13px;
                gap: 5px;
            }

            .doctor-icon {
                font-size: 12px;
            }

            .doctor-specialization {
                font-size: 10px;
                padding-left: 17px;
                margin-bottom: 5px;
            }

            .doctor-name-row {
                gap: 8px;
                margin-bottom: 5px;
            }

            .resume-date-right {
                font-size: 9px;
                padding: 2px 4px;
            }

            .status-note {
                font-size: 10px;
                padding: 4px 6px;
                margin-top: 6px;
            }

            .status-note .muted {
                font-size: 9px;
                margin-left: 5px;
            }
        }

        /* Extra Small Mobile (320px to 400px) */
        @media (max-width: 400px) {
            .container {
                padding: 6px;
            }

            .status-col {
                padding: 10px 12px;
            }

            .col-header {
                font-size: 14px;
                padding-bottom: 6px;
            }

            .col-count {
                font-size: 12px;
            }

            .current-date {
                font-size: 10px;
                padding: 2px 4px;
            }

            .resumes-label {
                font-size: 12px;
            }

            .resumes-label i {
                font-size: 10px;
            }

            .doctor-card {
                padding: 8px 10px;
            }

            .doctor-name,
            .doctor-name-left {
                font-size: 12px;
            }

            .doctor-icon {
                font-size: 11px;
            }

            .doctor-specialization {
                font-size: 9px;
                padding-left: 15px;
            }

            .resume-date-right {
                font-size: 8px;
                padding: 2px 3px;
            }

            .status-note {
                font-size: 9px;
                padding: 4px 5px;
            }

            .status-note .muted {
                font-size: 8px;
            }
        }
    </style>
</head>

<body>



<div class="container">
    <?php
        // Pre-group doctors into the two status columns for server-side initial render
        $groups = ['no medical' => [], 'on leave' => []];
        foreach ($all_doctors as $d) {
            $low = strtolower(trim($d['status'] ?? ''));
            if ($low === '' || strpos($low, 'no medical') !== false || strpos($low, 'no clinic') !== false) {
                $key = 'no medical';
            } elseif (strpos($low, 'leave') !== false) {
                $key = 'on leave';
            } elseif (strpos($low, 'schedule') !== false || strpos($low, 'available') !== false) {
                continue;
            } else {
                $key = 'no medical';
            }
            $groups[$key][] = $d;
        }
    ?>

    <div class="main-board">
        <!-- No Clinic Column -->
        <div class="status-col" data-status="no medical">
            <div class="col-header">
                <div class="col-header-left">
                    <span>No Clinic Today</span>
                    <span class="col-count">(<?= count($groups['no medical']) ?>)</span>
                </div>
                <div class="current-date" id="current-date-display">
                    <!-- Date will be set by JavaScript -->
                </div>
            </div>
            <div class="col-list">
                <?php foreach ($groups['no medical'] as $doctor): ?>
                    <div class="doctor-card">
                        <div class="doctor-name">
                            <i class="doctor-icon bi bi-person-fill"></i>
                            <span><?= htmlspecialchars($doctor['name']) ?></span>
                        </div>
                        <div class="doctor-specialization">
                            <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- On Leave Column -->
        <div class="status-col" data-status="on leave">
            <div class="col-header">
                <div class="col-header-left">
                    <span>On Leave</span>
                    <span class="col-count">(<?= count($groups['on leave']) ?>)</span>
                </div>
                <div class="col-header-right">
                    <div class="resumes-label">
                        <i class="bi bi-calendar-check"></i>
                        <span>Resumes</span>
                    </div>
                </div>
            </div>
            <div class="col-list">
                <?php 
                $onLeaveOnly = [];
                $withResumeDates = [];
                
                foreach ($groups['on leave'] as $doctor) {
                    if (!empty($doctor['resume_date'])) {
                        $withResumeDates[] = $doctor;
                    } else {
                        $onLeaveOnly[] = $doctor;
                    }
                }
                
                foreach ($onLeaveOnly as $doctor): 
                ?>
                    <div class="doctor-card">
                        <div class="doctor-name-row">
                            <div class="doctor-name-left">
                                <i class="doctor-icon bi bi-person-fill"></i>
                                <span><?= htmlspecialchars($doctor['name']) ?></span>
                            </div>
                        </div>
                        <div class="doctor-specialization">
                            <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                        </div>

                        <?php if (!empty($doctor['remarks'])): ?>
                            <div class="status-note">
                                <span>Remarks:</span>
                                <span class="muted"><?= htmlspecialchars($doctor['remarks']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php 
                foreach ($withResumeDates as $doctor): 
                ?>
                    <div class="doctor-card">
                        <div class="doctor-name-row">
                            <div class="doctor-name-left">
                                <i class="doctor-icon bi bi-person-fill"></i>
                                <span><?= htmlspecialchars($doctor['name']) ?></span>
                            </div>
                            <div class="resume-date-right">
                                <?= date("M d, Y", strtotime($doctor['resume_date'])) ?>
                            </div>
                        </div>
                        <div class="doctor-specialization">
                            <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                        </div>

                        <?php if (!empty($doctor['remarks'])): ?>
                            <div class="status-note">
                                <span>Remarks:</span>
                                <span class="muted"><?= htmlspecialchars($doctor['remarks']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Update current date display
    function updateCurrentDate() {
        const dateOptions = {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'short',
            day: '2-digit'
        };
        const dateDisplay = new Intl.DateTimeFormat('en-US', dateOptions).format(new Date());
        const dateEl = document.getElementById('current-date-display');
        if (dateEl) {
            dateEl.textContent = dateDisplay;
        }
    }
    
    // Update date immediately and every minute
    updateCurrentDate();
    setInterval(updateCurrentDate, 60000);

    // Enhanced auto-scroll system for TV display
    (function() {
        const POLL_MS = 10000; // 10 seconds
        let SCROLL_SPEED = <?= $display_settings['scroll_speed'] ?? 25 ?>; // pixels per second (from database)
        let PAUSE_AT_TOP = <?= $display_settings['pause_at_top'] ?? 3000 ?>; // ms to pause at top (from database)
        let PAUSE_AT_BOTTOM = <?= $display_settings['pause_at_bottom'] ?? 3000 ?>; // ms to pause at bottom (from database)
        let lastData = null;

        function setupSmoothAutoScroll(list) {
            const itemCount = list.children.length;
            
            if (itemCount === 0) return;

            // Clear any existing animation
            list.style.transform = 'translateY(0)';
            
            // Wait for DOM to render
            setTimeout(() => {
                let totalHeight = 0;
                for (let i = 0; i < list.children.length; i++) {
                    const card = list.children[i];
                    totalHeight += card.offsetHeight + 12; // include gap
                }

                const containerHeight = list.offsetHeight || 600;
                const maxScroll = Math.max(totalHeight - containerHeight, 0);
                
                // If content fits in view, no need to scroll
                if (maxScroll <= 0) {
                    list.style.transform = 'translateY(0)';
                    return;
                }

                console.log('Auto-scroll setup:', {
                    items: itemCount,
                    totalHeight,
                    containerHeight,
                    maxScroll
                });

                let currentPos = 0;
                let direction = 1; // 1 for down, -1 for up
                let isPaused = true;
                let pauseStartTime = Date.now();

                function scrollFrame(timestamp) {
                    // Handle pause at top
                    if (isPaused) {
                        if (Date.now() - pauseStartTime >= PAUSE_AT_TOP) {
                            isPaused = false;
                        } else {
                            requestAnimationFrame(scrollFrame);
                            return;
                        }
                    }

                    // Scroll down
                    if (direction === 1) {
                        currentPos += SCROLL_SPEED / 60; // Convert to per-frame
                        
                        if (currentPos >= maxScroll) {
                            currentPos = maxScroll;
                            direction = -1;
                            isPaused = true;
                            pauseStartTime = Date.now();
                        }
                    } 
                    // Scroll up
                    else {
                        currentPos -= SCROLL_SPEED / 60;
                        
                        if (currentPos <= 0) {
                            currentPos = 0;
                            direction = 1;
                            isPaused = true;
                            pauseStartTime = Date.now();
                        }
                    }

                    list.style.transform = `translateY(-${currentPos}px)`;
                    requestAnimationFrame(scrollFrame);
                }

                // Start with pause at top
                pauseStartTime = Date.now();
                requestAnimationFrame(scrollFrame);
            }, 200);
        }

        function buildDoctorCard(doc, isNoClinic) {
            const card = document.createElement('div');
            card.className = 'doctor-card';
            
            if (isNoClinic) {
                const name = document.createElement('div');
                name.className = 'doctor-name';
                
                const icon = document.createElement('i'); 
                icon.className = 'doctor-icon bi bi-person-fill';
                const text = document.createElement('span'); 
                text.textContent = doc.name || '';
                
                name.appendChild(icon);
                name.appendChild(text);
                card.appendChild(name);
            } else {
                const nameRow = document.createElement('div');
                nameRow.className = 'doctor-name-row';
                
                const nameLeft = document.createElement('div');
                nameLeft.className = 'doctor-name-left';
                
                const icon = document.createElement('i'); 
                icon.className = 'doctor-icon bi bi-person-fill';
                const text = document.createElement('span'); 
                text.textContent = doc.name || '';
                
                nameLeft.appendChild(icon);
                nameLeft.appendChild(text);
                nameRow.appendChild(nameLeft);
                
                if (doc.resume_date) {
                    const resumeRight = document.createElement('div');
                    resumeRight.className = 'resume-date-right';
                    try {
                        const d = new Date(doc.resume_date);
                        resumeRight.textContent = d.toLocaleDateString('en-US', {year:'numeric', month:'short', day:'2-digit'});
                    } catch(e) {
                        resumeRight.textContent = doc.resume_date;
                    }
                    nameRow.appendChild(resumeRight);
                }
                
                card.appendChild(nameRow);
            }

            const specialization = document.createElement('div');
            specialization.className = 'doctor-specialization';
            specialization.textContent = doc.department || 'General';
            card.appendChild(specialization);

            if (!isNoClinic && doc.remarks) {
                const note = document.createElement('div'); 
                note.className = 'status-note';
                note.innerHTML = '<span>Remarks:</span> <span class="muted">' + doc.remarks + '</span>';
                card.appendChild(note);
            }
            
            return card;
        }

        function updateBoard(data) {
            if (!data) return;

            const board = document.querySelector('.main-board');
            if (!board) return;

            // Update scroll settings if they've changed
            if (data.display_settings) {
                SCROLL_SPEED = data.display_settings.scroll_speed || 25;
                PAUSE_AT_TOP = data.display_settings.pause_at_top || 3000;
                PAUSE_AT_BOTTOM = data.display_settings.pause_at_bottom || 3000;
            }

            try { if (JSON.stringify(data) === JSON.stringify(lastData)) return; } catch(e) {}
            lastData = data;

            const groups = { 'no medical': [], 'on leave': [] };
            (data.doctors || []).forEach(d => {
                const st = (d.status || '').toLowerCase();
                if (st === '' || st.indexOf('no medical') !== -1 || st.indexOf('no clinic') !== -1) {
                    groups['no medical'].push(d);
                } else if (st.indexOf('leave') !== -1) {
                    groups['on leave'].push(d);
                } else if (st.indexOf('schedule') !== -1 || st.indexOf('available') !== -1) {
                    // skip
                } else {
                    groups['no medical'].push(d);
                }
            });

            const cols = board.querySelectorAll('.status-col');
            
            // Update No Clinic column
            const noClinicCol = cols[0];
            if (noClinicCol) {
                const count = noClinicCol.querySelector('.col-count');
                if (count) count.textContent = '(' + groups['no medical'].length + ')';
                
                const list = noClinicCol.querySelector('.col-list');
                if (list) {
                    list.innerHTML = '';
                    groups['no medical'].forEach(doc => {
                        list.appendChild(buildDoctorCard(doc, true));
                    });
                    setupSmoothAutoScroll(list);
                }
            }

            // Update On Leave column
            const onLeaveCol = cols[1];
            if (onLeaveCol) {
                let headerRight = onLeaveCol.querySelector('.col-header-right');
                if (!headerRight) {
                    headerRight = document.createElement('div');
                    headerRight.className = 'col-header-right';
                    const resumesLabel = document.createElement('div');
                    resumesLabel.className = 'resumes-label';
                    resumesLabel.innerHTML = '<i class="bi bi-calendar-check"></i><span>Resumes</span>';
                    headerRight.appendChild(resumesLabel);
                    const header = onLeaveCol.querySelector('.col-header');
                    if (header) header.appendChild(headerRight);
                }
                
                const count = onLeaveCol.querySelector('.col-count');
                if (count) count.textContent = '(' + groups['on leave'].length + ')';
                
                const list = onLeaveCol.querySelector('.col-list');
                if (list) {
                    list.innerHTML = '';
                    
                    const onLeaveOnly = [];
                    const withResumeDates = [];
                    
                    groups['on leave'].forEach(doc => {
                        if (doc.resume_date) {
                            withResumeDates.push(doc);
                        } else {
                            onLeaveOnly.push(doc);
                        }
                    });
                    
                    onLeaveOnly.forEach(doc => {
                        list.appendChild(buildDoctorCard(doc, false));
                    });
                    
                    withResumeDates.forEach(doc => {
                        list.appendChild(buildDoctorCard(doc, false));
                    });

                    setupSmoothAutoScroll(list);
                }
            }
        }

        async function fetchLoop() {
            try {
                const res = await fetch(window.location.pathname + '?ajax=1');
                if (!res.ok) return;
                const data = await res.json();
                updateBoard(data);
            } catch (e) { 
                console.warn('Live update failed', e); 
            }
        }

        fetchLoop();
        setInterval(fetchLoop, POLL_MS);
        
        // Initialize scroll on page load
        setTimeout(() => {
            document.querySelectorAll('.col-list').forEach(list => {
                setupSmoothAutoScroll(list);
            });
        }, 500);
    })();
</script>
</body>
</html>