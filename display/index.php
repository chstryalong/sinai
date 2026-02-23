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
            --primary: #0052CC;
            --primary-600: #1e88e5;
            --accent: #ffc107;
            --success: #28a745;
            --danger: #dc3545;
            --muted: #eef6ff;
            --bg: #f7fbff;
            --surface: rgba(255, 255, 255, 0.9);
            --text: #052744;
            --glass: rgba(255, 255, 255, 0.75);
            --radius: 12px;
            --shadow-1: 0 4px 12px rgba(3, 32, 71, 0.08);
            --shadow-2: 0 8px 24px rgba(3, 32, 71, 0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
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
            font-size: 20px;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(3, 32, 71, 0.30), rgba(3, 32, 71, 0.30)),
                url('assets/logo.png'),
                radial-gradient(circle at 10% 20%, rgba(1, 63, 113, 0.06) 0%, transparent 15%),
                radial-gradient(circle at 80% 80%, rgba(30, 136, 229, 0.04) 0%, transparent 20%),
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
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.90), rgba(245, 250, 255, 0.85));
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(3, 32, 71, 0.12);
            border-top: 4px solid rgba(0, 82, 204, 0.15);
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
            background: rgba(0, 82, 204, 0.1);
            padding: 8px 16px;
            border-radius: 10px;
        }

        .col-list {
            overflow: hidden;
            display: block;
            padding-top: 16px;
            padding-bottom: 16px;
            min-height: 0;
            flex: 1;
            position: relative;
            mask-image: linear-gradient(to bottom,
                    transparent 0%,
                    black 40px,
                    black calc(100% - 40px),
                    transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom,
                    transparent 0%,
                    black 40px,
                    black calc(100% - 40px),
                    transparent 100%);
        }

        .col-list-inner {
            display: flex;
            flex-direction: column;
            gap: 12px;
            will-change: transform;
            transition: none;
            padding-top: 12px;
            padding-bottom: 12px;
        }

        .doctor-card {
            background: rgba(255, 255, 255, 0.92);
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 6px 16px rgba(3, 32, 71, 0.08);
            border-left: 5px solid rgba(255, 193, 7, 0.9);
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
            background: rgba(0, 82, 204, 0.12);
            padding: 6px 14px;
            border-radius: 8px;
            flex-shrink: 0;
        }

        .tentative-badge {
            display: inline-block;
            background: rgba(255, 193, 7, 0.25);
            color: #856404;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            text-align: center;
            border: 2px dashed rgba(255, 193, 7, 0.6);
            white-space: nowrap;
        }

        .status-note {
            font-size: 18px;
            color: #052744;
            margin-top: 12px;
            display: block;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.95);
            padding: 10px 16px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.06);
        }

        .status-note .muted {
            font-weight: 600;
            color: #555;
            font-size: 17px;
            margin-left: 10px;
        }

        .col-list::-webkit-scrollbar { display: none; }
        .col-list { -ms-overflow-style: none; scrollbar-width: none; }

        @media (max-width: 1920px) {
            body { font-size: 18px; }
            .container { padding: 20px; }
            .status-col { padding: 24px 32px; }
            .col-header { font-size: 28px; padding-bottom: 14px; }
            .col-count { font-size: 24px; }
            .current-date { font-size: 22px; padding: 7px 14px; }
            .resumes-label { font-size: 24px; }
            .doctor-card { padding: 18px 22px; }
            .doctor-name, .doctor-name-left { font-size: 24px; }
            .doctor-icon { font-size: 22px; }
            .doctor-specialization { font-size: 18px; padding-left: 32px; }
            .resume-date-right { font-size: 20px; padding: 5px 12px; }
            .tentative-badge { font-size: 14px; padding: 3px 10px; }
            .status-note { font-size: 16px; padding: 9px 14px; }
            .status-note .muted { font-size: 15px; }
        }

        @media (max-width: 1440px) {
            body { font-size: 16px; }
            .container { padding: 18px; }
            .main-board { gap: 24px; }
            .status-col { padding: 20px 28px; }
            .col-header { font-size: 24px; padding-bottom: 12px; }
            .col-count { font-size: 20px; }
            .current-date { font-size: 18px; padding: 6px 12px; }
            .resumes-label { font-size: 20px; }
            .resumes-label i { font-size: 18px; }
            .doctor-card { padding: 16px 20px; }
            .doctor-name, .doctor-name-left { font-size: 20px; }
            .doctor-icon { font-size: 18px; }
            .doctor-specialization { font-size: 16px; padding-left: 28px; }
            .resume-date-right { font-size: 16px; padding: 4px 10px; }
            .tentative-badge { font-size: 13px; padding: 3px 9px; }
            .status-note { font-size: 14px; padding: 8px 12px; }
            .status-note .muted { font-size: 13px; }
        }

        @media (max-width: 1024px) {
            body { font-size: 15px; }
            .container { padding: 16px; }
            .main-board { gap: 20px; border-radius: 12px; }
            .status-col { padding: 18px 24px; border-radius: 12px; }
            .col-header { font-size: 22px; padding-bottom: 10px; border-bottom: 2px solid var(--accent); }
            .col-count { font-size: 18px; }
            .current-date { font-size: 16px; padding: 5px 10px; border-radius: 8px; }
            .resumes-label { font-size: 18px; }
            .resumes-label i { font-size: 16px; }
            .doctor-card { padding: 14px 18px; border-radius: 10px; border-left: 4px solid rgba(255, 193, 7, 0.9); }
            .doctor-name, .doctor-name-left { font-size: 18px; gap: 8px; }
            .doctor-icon { font-size: 16px; }
            .doctor-specialization { font-size: 14px; padding-left: 24px; }
            .resume-date-right { font-size: 14px; padding: 4px 8px; border-radius: 6px; }
            .tentative-badge { font-size: 12px; padding: 3px 8px; }
            .status-note { font-size: 13px; padding: 7px 10px; border-radius: 8px; }
            .status-note .muted { font-size: 12px; margin-left: 8px; }
        }

        @media (max-width: 900px) {
            body { font-size: 14px; }
            .container { padding: 12px; }
            .main-board { grid-template-columns: 1fr; gap: 16px; }
            .status-col { padding: 16px 20px; }
            .col-header { font-size: 20px; padding-bottom: 10px; }
            .col-count { font-size: 16px; }
            .current-date { font-size: 14px; padding: 4px 8px; }
            .resumes-label { font-size: 16px; }
            .resumes-label i { font-size: 14px; }
            .doctor-card { padding: 12px 16px; }
            .doctor-name, .doctor-name-left { font-size: 16px; }
            .doctor-icon { font-size: 14px; }
            .doctor-specialization { font-size: 13px; padding-left: 22px; }
            .doctor-name-row { gap: 12px; }
            .resume-date-right { font-size: 12px; padding: 3px 6px; }
            .tentative-badge { font-size: 11px; padding: 2px 7px; }
            .status-note { font-size: 12px; padding: 6px 8px; }
            .status-note .muted { font-size: 11px; }
        }

        @media (max-width: 600px) {
            body { font-size: 13px; }
            .container { padding: 10px; }
            .main-board { gap: 12px; border-radius: 10px; }
            .status-col { padding: 14px 16px; gap: 10px; border-radius: 10px; border-top: 3px solid rgba(0, 82, 204, 0.15); }
            .col-header { font-size: 18px; padding-bottom: 8px; gap: 8px; }
            .col-count { font-size: 14px; }
            .current-date { font-size: 12px; padding: 3px 6px; }
            .resumes-label { font-size: 14px; }
            .resumes-label i { font-size: 12px; }
            .doctor-card { padding: 10px 14px; border-radius: 8px; border-left: 3px solid rgba(255, 193, 7, 0.9); }
            .doctor-name, .doctor-name-left { font-size: 14px; gap: 6px; }
            .doctor-icon { font-size: 13px; }
            .doctor-specialization { font-size: 11px; padding-left: 19px; margin-bottom: 6px; }
            .doctor-name-row { gap: 10px; margin-bottom: 6px; }
            .resume-date-right { font-size: 10px; padding: 2px 5px; }
            .tentative-badge { font-size: 10px; padding: 2px 6px; }
            .status-note { font-size: 11px; padding: 5px 7px; margin-top: 8px; }
            .status-note .muted { font-size: 10px; margin-left: 6px; }
        }

        @media (max-width: 480px) {
            body { font-size: 12px; }
            .container { padding: 8px; }
            .main-board { gap: 10px; }
            .status-col { padding: 12px 14px; gap: 8px; }
            .col-header { font-size: 16px; padding-bottom: 8px; gap: 6px; }
            .col-count { font-size: 13px; }
            .current-date { font-size: 11px; padding: 3px 5px; }
            .resumes-label { font-size: 13px; }
            .resumes-label i { font-size: 11px; }
            .doctor-card { padding: 9px 12px; }
            .doctor-name, .doctor-name-left { font-size: 13px; gap: 5px; }
            .doctor-icon { font-size: 12px; }
            .doctor-specialization { font-size: 10px; padding-left: 17px; margin-bottom: 5px; }
            .doctor-name-row { gap: 8px; margin-bottom: 5px; }
            .resume-date-right { font-size: 9px; padding: 2px 4px; }
            .tentative-badge { font-size: 9px; padding: 2px 5px; }
            .status-note { font-size: 10px; padding: 4px 6px; margin-top: 6px; }
            .status-note .muted { font-size: 9px; margin-left: 5px; }
        }

        @media (max-width: 400px) {
            .container { padding: 6px; }
            .status-col { padding: 10px 12px; }
            .col-header { font-size: 14px; padding-bottom: 6px; }
            .col-count { font-size: 12px; }
            .current-date { font-size: 10px; padding: 2px 4px; }
            .resumes-label { font-size: 12px; }
            .resumes-label i { font-size: 10px; }
            .doctor-card { padding: 8px 10px; }
            .doctor-name, .doctor-name-left { font-size: 12px; }
            .doctor-icon { font-size: 11px; }
            .doctor-specialization { font-size: 9px; padding-left: 15px; }
            .resume-date-right { font-size: 8px; padding: 2px 3px; }
            .tentative-badge { font-size: 8px; padding: 2px 4px; }
            .status-note { font-size: 9px; padding: 4px 5px; }
            .status-note .muted { font-size: 8px; }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php
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
                    <div class="current-date" id="current-date-display"></div>
                </div>
                <div class="col-list">
                    <div class="col-list-inner">
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
                    <div class="col-list-inner">
                        <?php
                        $onLeaveOnly = [];
                        $withResumeDates = [];
                        foreach ($groups['on leave'] as $doctor) {
                            if (!empty($doctor['resume_date'])) $withResumeDates[] = $doctor;
                            else $onLeaveOnly[] = $doctor;
                        }

                        foreach ($onLeaveOnly as $doctor): ?>
                            <div class="doctor-card">
                                <div class="doctor-name-row">
                                    <div class="doctor-name-left">
                                        <i class="doctor-icon bi bi-person-fill"></i>
                                        <span><?= htmlspecialchars($doctor['name']) ?></span>
                                    </div>
                                </div>
                                <div class="doctor-specialization"><?= htmlspecialchars($doctor['department'] ?? 'General') ?></div>
                                <?php if (!empty($doctor['remarks'])): ?>
                                    <div class="status-note">
                                        <span>Remarks:</span>
                                        <span class="muted"><?= htmlspecialchars($doctor['remarks']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach;

                        foreach ($withResumeDates as $doctor): ?>
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
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="doctor-specialization" style="margin-bottom: 0;">
                                        <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                                    </div>
                                    <?php if (!empty($doctor['is_tentative']) && $doctor['is_tentative'] == 1): ?>
                                        <div class="tentative-badge">
                                            <i class="bi bi-calendar-question"></i> TENTATIVE
                                        </div>
                                    <?php endif; ?>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ── Date display ──────────────────────────────────────────────────────────
        function updateCurrentDate() {
            const el = document.getElementById('current-date-display');
            if (!el) return;
            el.textContent = new Intl.DateTimeFormat('en-US', {
                timeZone: 'Asia/Manila',
                year: 'numeric', month: 'short', day: '2-digit'
            }).format(new Date());
        }
        updateCurrentDate();
        setInterval(updateCurrentDate, 60000);

        // ── Scroll system ─────────────────────────────────────────────────────────
        (function () {
            const POLL_MS = 10000;

            // Settings from PHP (live-updated when AJAX returns new values)
            let SCROLL_SPEED  = <?= (int)($display_settings['scroll_speed']    ?? 25)   ?>;
            let PAUSE_AT_TOP  = <?= (int)($display_settings['pause_at_top']    ?? 3000) ?>;
            let PAUSE_AT_BOT  = <?= (int)($display_settings['pause_at_bottom'] ?? 3000) ?>;

            let lastDataStr = '';

            // Per-column scroll state (keyed by the .col-list element itself)
            const scrollStates = new WeakMap();

            /**
             * Start (or restart cleanly) the auto-scroll loop for ONE column.
             * If a loop is already running for this column and the content
             * hasn't changed, we leave it completely untouched.
             *
             * @param {HTMLElement} colList   – the .col-list wrapper
             * @param {boolean}     forceReset – true only when content was rebuilt
             */
            function startScroll(colList, forceReset) {
                const inner = colList.querySelector('.col-list-inner');
                if (!inner || inner.children.length === 0) return;

                // If already running and no content change, leave it alone
                if (!forceReset && scrollStates.has(colList)) return;

                // Cancel any old RAF loop for this column
                const old = scrollStates.get(colList);
                if (old && old.rafId) cancelAnimationFrame(old.rafId);

                // Build fresh state
                const state = {
                    pos: 0,
                    dir: 1,          // 1 = scrolling down, -1 = scrolling up
                    pausing: true,
                    pauseEnd: performance.now() + PAUSE_AT_TOP,
                    rafId: null,
                    maxScroll: 0
                };
                scrollStates.set(colList, state);

                // Reset position to top
                inner.style.transform = 'translateY(0)';

                // Wait one frame so the browser has laid out the new content
                requestAnimationFrame(() => {
                    // Measure after layout
                    let totalH = 0;
                    for (const card of inner.children) {
                        totalH += card.offsetHeight + 12; // 12 = gap
                    }
                    totalH += 24; // padding-top + padding-bottom of inner

                    const containerH = colList.offsetHeight || 600;
                    state.maxScroll = Math.max(totalH - containerH, 0);

                    if (state.maxScroll <= 0) return; // content fits – no scroll needed

                    function tick(now) {
                        const s = scrollStates.get(colList);
                        if (!s) return; // state was cleared externally

                        if (s.pausing) {
                            if (now >= s.pauseEnd) {
                                s.pausing = false;
                            }
                            s.rafId = requestAnimationFrame(tick);
                            return;
                        }

                        const step = SCROLL_SPEED / 60; // px per frame at ~60 fps

                        if (s.dir === 1) {
                            // Scrolling DOWN
                            s.pos = Math.min(s.pos + step, s.maxScroll);
                            if (s.pos >= s.maxScroll) {
                                // Reached bottom – pause then reverse
                                s.dir = -1;
                                s.pausing = true;
                                s.pauseEnd = now + PAUSE_AT_BOT;
                            }
                        } else {
                            // Scrolling UP
                            s.pos = Math.max(s.pos - step, 0);
                            if (s.pos <= 0) {
                                // Reached top – pause then reverse
                                s.dir = 1;
                                s.pausing = true;
                                s.pauseEnd = now + PAUSE_AT_TOP;
                            }
                        }

                        inner.style.transform = `translateY(-${s.pos}px)`;
                        s.rafId = requestAnimationFrame(tick);
                    }

                    state.rafId = requestAnimationFrame(tick);
                });
            }

            // ── Card builders ─────────────────────────────────────────────────────
            function buildNoClinicCard(doc) {
                const card = document.createElement('div');
                card.className = 'doctor-card';

                const name = document.createElement('div');
                name.className = 'doctor-name';
                name.innerHTML = `<i class="doctor-icon bi bi-person-fill"></i><span>${escHtml(doc.name)}</span>`;
                card.appendChild(name);

                const dept = document.createElement('div');
                dept.className = 'doctor-specialization';
                dept.textContent = doc.department || 'General';
                card.appendChild(dept);

                return card;
            }

            function buildOnLeaveCard(doc) {
                const card = document.createElement('div');
                card.className = 'doctor-card';

                const nameRow = document.createElement('div');
                nameRow.className = 'doctor-name-row';

                const nameLeft = document.createElement('div');
                nameLeft.className = 'doctor-name-left';
                nameLeft.innerHTML = `<i class="doctor-icon bi bi-person-fill"></i><span>${escHtml(doc.name)}</span>`;
                nameRow.appendChild(nameLeft);

                if (doc.resume_date) {
                    const resumeEl = document.createElement('div');
                    resumeEl.className = 'resume-date-right';
                    try {
                        // Force parse as local date to avoid timezone shifts
                        const [y, m, d] = doc.resume_date.split('-').map(Number);
                        resumeEl.textContent = new Date(y, m - 1, d)
                            .toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: '2-digit' });
                    } catch (e) {
                        resumeEl.textContent = doc.resume_date;
                    }
                    nameRow.appendChild(resumeEl);
                }
                card.appendChild(nameRow);

                const deptRow = document.createElement('div');
                deptRow.style.cssText = 'display:flex;justify-content:space-between;align-items:center;';

                const dept = document.createElement('div');
                dept.className = 'doctor-specialization';
                dept.style.marginBottom = '0';
                dept.textContent = doc.department || 'General';
                deptRow.appendChild(dept);

                if (doc.resume_date && doc.is_tentative == 1) {
                    const badge = document.createElement('div');
                    badge.className = 'tentative-badge';
                    badge.innerHTML = '<i class="bi bi-calendar-question"></i> TENTATIVE';
                    deptRow.appendChild(badge);
                }
                card.appendChild(deptRow);

                if (doc.remarks) {
                    const note = document.createElement('div');
                    note.className = 'status-note';
                    note.innerHTML = `<span>Remarks:</span> <span class="muted">${escHtml(doc.remarks)}</span>`;
                    card.appendChild(note);
                }

                return card;
            }

            function escHtml(str) {
                return String(str || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            // ── Board updater ─────────────────────────────────────────────────────
            function updateBoard(data) {
                if (!data) return;

                // Update scroll settings
                if (data.display_settings) {
                    SCROLL_SPEED = data.display_settings.scroll_speed || 25;
                    PAUSE_AT_TOP = data.display_settings.pause_at_top  || 3000;
                    PAUSE_AT_BOT = data.display_settings.pause_at_bottom || 3000;
                }

                // Bail out if nothing changed
                const str = JSON.stringify(data.doctors);
                if (str === lastDataStr) return;
                lastDataStr = str;

                // Bucket doctors
                const noClinic = [], onLeave = [];
                (data.doctors || []).forEach(d => {
                    const st = (d.status || '').toLowerCase();
                    if (st.indexOf('leave') !== -1) {
                        onLeave.push(d);
                    } else if (st.indexOf('schedule') !== -1 || st.indexOf('available') !== -1) {
                        // skip – on schedule doctors are not shown
                    } else {
                        noClinic.push(d);
                    }
                });

                const cols = document.querySelectorAll('.status-col');

                // ── No Clinic column ──
                const noClinicCol = cols[0];
                if (noClinicCol) {
                    const countEl = noClinicCol.querySelector('.col-count');
                    if (countEl) countEl.textContent = `(${noClinic.length})`;

                    const listEl = noClinicCol.querySelector('.col-list');
                    const innerEl = listEl && listEl.querySelector('.col-list-inner');
                    if (innerEl) {
                        innerEl.innerHTML = '';
                        noClinic.forEach(d => innerEl.appendChild(buildNoClinicCard(d)));
                        startScroll(listEl, true); // content changed – reset scroll
                    }
                }

                // ── On Leave column ──
                const onLeaveCol = cols[1];
                if (onLeaveCol) {
                    const countEl = onLeaveCol.querySelector('.col-count');
                    if (countEl) countEl.textContent = `(${onLeave.length})`;

                    const listEl = onLeaveCol.querySelector('.col-list');
                    const innerEl = listEl && listEl.querySelector('.col-list-inner');
                    if (innerEl) {
                        innerEl.innerHTML = '';
                        const withDate = onLeave.filter(d => d.resume_date);
                        const noDate   = onLeave.filter(d => !d.resume_date);
                        noDate.forEach(d   => innerEl.appendChild(buildOnLeaveCard(d)));
                        withDate.forEach(d => innerEl.appendChild(buildOnLeaveCard(d)));
                        startScroll(listEl, true); // content changed – reset scroll
                    }
                }
            }

            // ── AJAX poll ─────────────────────────────────────────────────────────
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

            // ── Init ──────────────────────────────────────────────────────────────
            // Kick off scrolling for the server-rendered content (no forceReset
            // needed – these columns have never been started before)
            window.addEventListener('load', () => {
                document.querySelectorAll('.col-list').forEach(l => startScroll(l, true));
            });

            // First AJAX fetch after a short delay, then repeat
            setTimeout(fetchLoop, 2000);
            setInterval(fetchLoop, POLL_MS);
        })();
    </script>
</body>
</html>