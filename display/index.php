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
    // We return announcement and the flat doctors list
    echo json_encode([
        'announcement' => $announcement,
        'doctors'      => $all_doctors
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

    <!-- Auto refresh removed — using AJAX live updates -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            /* Design tokens / color palette */
            --primary: #0052CC; /* Deep brand blue */
            --primary-600: #1e88e5; /* Secondary blue */
            --accent: #ffc107; /* Amber accent */
            --success: #28a745;
            --danger: #dc3545;
            --muted: #eef6ff;
            --bg: #f7fbff;
            --surface: rgba(255,255,255,0.9);
            --text: #052744;
            --glass: rgba(255,255,255,0.75);
            --radius: 8px;
            --shadow-1: 0 2px 8px rgba(3,32,71,0.06);
            --shadow-2: 0 6px 20px rgba(3,32,71,0.08);
            /* Legacy aliases for backwards compatibility */
            --primary-blue: var(--primary);
            --secondary-blue: var(--primary-600);
            --accent-yellow: var(--accent);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Typography & rendering */
        html, body { -webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility; }
        a { color: var(--primary); text-decoration: none; }

        /* Buttons */
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-600) 100%);
            color: #fff;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            box-shadow: var(--shadow-1);
        }



        /* Status badge polish */
        .status-badge { border-radius: 999px; padding: 4px 10px; font-size: 11px; font-weight: 700; }
        .status-available { background-color: rgba(40,167,69,0.12); color: var(--success); }
        .status-unavailable { background-color: rgba(255,193,7,0.12); color: #856404; }
        .status-onleave { background-color: rgba(220,53,69,0.12); color: var(--danger); }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #eef6ff 0%, #f7fbff 100%);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            color: #052744;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            /* top layer: soft bluish overlay, then uploaded image, then subtle gradients and svg pattern */
            background-image:
                linear-gradient(rgba(3,32,71,0.30), rgba(3,32,71,0.30)),
                url('assets/logo.png'),
                radial-gradient(circle at 10% 20%, rgba(1,63,113,0.06) 0%, transparent 15%),
                radial-gradient(circle at 80% 80%, rgba(30,136,229,0.04) 0%, transparent 20%),
                url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1400 300"><g fill="%230052CC" fill-opacity="0.06"><rect x="100" y="80" width="80" height="120" rx="6"/><rect x="200" y="50" width="100" height="150" rx="6"/><rect x="320" y="20" width="160" height="180" rx="6"/><rect x="500" y="90" width="80" height="110" rx="6"/><rect x="620" y="60" width="90" height="140" rx="6"/><rect x="720" y="40" width="110" height="160" rx="6"/><rect x="860" y="70" width="140" height="130" rx="6"/><rect x="1050" y="100" width="90" height="100" rx="6"/><rect x="300" y="40" width="40" height="40" rx="4" fill="%230052CC" fill-opacity="0.12"/><rect x="360" y="40" width="40" height="40" rx="4" fill="%230052CC" fill-opacity="0.12"/><rect x="420" y="40" width="40" height="40" rx="4" fill="%230052CC" fill-opacity="0.12"/><text x="700" y="260" font-family="Segoe UI, Arial, sans-serif" font-size="36" fill="%230052CC" fill-opacity="0.06" text-anchor="middle">New Sinai MDI Hospital</text></g></svg>');
            background-repeat: no-repeat, no-repeat, no-repeat, no-repeat, no-repeat;
            background-position: center center, center center, center, center, 50% 35%;
            background-size: cover, cover, cover, cover, 85%;
            background-blend-mode: overlay;
            background-attachment: fixed;
            pointer-events: none;
            z-index: 0;
            opacity: 0.98;
        }

        header {
            background: linear-gradient(135deg, rgba(0,82,204,0.88) 0%, rgba(30,136,229,0.80) 100%);
            color: white;
            padding: 15px 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(3,32,71,0.12);
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        /* Announcement banner (scrolling) */
        .announcement-wrap {
            position: sticky;
            top: 0;
            z-index: 1100;
            padding: 10px 16px;
            box-shadow: 0 6px 18px rgba(2,6,23,0.12);
            border-bottom: 3px solid rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .announcement { position: relative; overflow: hidden; width: 100%; }
        .announcement::before, .announcement::after { content: ''; position: absolute; top:0; bottom:0; width:6%; pointer-events: none; z-index:2; }
        .announcement::before { left:0; background: linear-gradient(to right, var(--fade-bg), transparent); }
        .announcement::after { right:0; background: linear-gradient(to left, var(--fade-bg), transparent); }
        .announcement { position: relative; }
        /* Marquee track spans the whole viewport so the announcement moves across the whole screen */
        .announcement .marquee { position: relative; height: 40px; overflow: hidden; }
        .announcement .marquee .marquee-track {
            position: absolute; left: 0; right: 0; top: 50%; transform: translateY(-50%);
            width: 100vw; display: flex; align-items: center; z-index: 1; pointer-events: none;
        }
        .announcement .marquee .marquee-item {
            display: inline-block; font-weight: 800; white-space: nowrap; will-change: transform; text-shadow: 0 1px 2px rgba(0,0,0,0.12);
        }

        /* Visual effect when text passes behind the megaphone (balanced with design) */
        .announcement .marquee .marquee-item.passing {
            /* Keep text blue to match the brand and remove color transition */
            color: var(--primary-blue) !important;
            /* subtle bluish glow for balance */
            text-shadow: 0 4px 10px rgba(0,82,204,0.08) !important;
            /* remove scale/bounce to keep it static */
            transform: none !important;
            /* only animate text-shadow (no color or transform changes) */
            transition: text-shadow 160ms linear;
        }
        /* Fallback keyframes (keeps movement for very old browsers) */
        @keyframes marquee {
            0% { transform: translateX(0%); }
            100% { transform: translateX(-100%); }
        }

        .header-content {
            max-width: 100%;
            margin: 0 auto;
        }

        .header-title {
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 2px;
        }

        .header-logo {
            height: 56px;
            width: auto;
            display: block;
            object-fit: contain;
            border-radius: 6px;
        }

        @media (max-width: 900px) {
            .header-logo { height: 44px; }
        }

        @media (max-width: 600px) {
            .header-logo { height: 36px; }
            .header-title { font-size: 20px; gap: 8px; }
        }

        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
        }

        .container {
            flex: 1;
            padding: 15px;
            overflow: hidden;
            display: flex;
            position: relative;
            z-index: 1;
        }

        .departments-wrapper {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 12px;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        .department-section {
            background: linear-gradient(180deg, rgba(255,255,255,0.86), rgba(245,250,255,0.80));
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 6px 20px rgba(3,32,71,0.08);
            border-top: 3px solid rgba(0,82,204,0.12);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .dept-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--accent-yellow);
            flex-shrink: 0;
        }

        .dept-icon {
            font-size: 18px;
            color: var(--primary-blue);
        }

        .dept-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-blue);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doctor-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }

        /* Two-column side-by-side board (No Clinic / On Leave) */
        .three-columns {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0; /* no gap between columns */
            width: 100%;
            height: 100%;
            min-height: 0;
        }

        /* Draw a vertical separator between the two adjacent status columns */
        .status-col + .status-col {
            border-left: 2px solid rgba(0,0,0,0.06);
        }

        /* Ensure columns have inner spacing so content doesn't touch the separator */
        .status-col { padding: 12px 18px; }

        /* Mobile: stack vertically and use a horizontal separator instead */
        @media (max-width: 900px) {
            .three-columns { grid-template-columns: 1fr; gap: 12px; }
            .status-col + .status-col { border-left: none; border-top: 2px solid rgba(0,0,0,0.06); }
        }
        .status-col {
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: linear-gradient(180deg, rgba(255,255,255,0.92), rgba(245,250,255,0.92));
            padding: 12px;
            border-radius: 8px;
            overflow: hidden;
            min-height: 0;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .col-header { font-weight:800; font-size:16px; display:flex; justify-content:space-between; gap:8px; padding-bottom:6px; border-bottom:1px solid rgba(0,0,0,0.04); }
        .col-list { overflow-y:auto; display:flex; flex-direction:column; gap:8px; padding-top:6px; min-height:0; }

        .dept-tag { font-size:12px; color:#444; margin-left:8px; }

        @media (max-width: 900px) {
            .three-columns { grid-template-columns: 1fr; }
        }

        .doctor-card {
            background: rgba(255, 255, 255, 0.86);
            border-radius: 6px;
            padding: 10px;
            box-shadow: 0 4px 12px rgba(3,32,71,0.06);
            border-left: 3px solid rgba(255,193,7,0.85);
            transition: all 0.3s ease;
            flex-shrink: 0;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
        }

        .doctor-card:hover {
            transform: translateX(3px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .doctor-name {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doctor-icon {
            font-size: 14px;
            flex-shrink: 0;
        }

        .status-badge { display:inline-flex; align-items:center; gap:8px; padding:0; font-weight:700; font-size:13px; }
        .status-badge::before { content: ""; display:inline-block; width:10px; height:10px; border-radius:50%; background: currentColor; box-shadow: 0 1px 2px rgba(0,0,0,0.06); transform: translateY(-1px); }
        .status-available { color: var(--success); }
        .status-unavailable { color: var(--danger); }
        .status-onleave { color: var(--danger); }
        .status-nomedical { color: #6c757d; }
        .status-onschedule { color: var(--primary-blue); }

        .resume-info {
            font-size: 11px;
            color: #444;
            margin-top: 6px;
            /* allow wrap so long texts don't get truncated */
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            display: block;
        }

        .resume-info i {
            color: var(--secondary-blue);
            margin-right: 6px;
        }

        /* Status note (shows appointment/resume/no-medical messages) */
        .status-note {
            font-size: 13px;
            color: #052744;
            margin-top: 8px;
            display:block;
            font-weight:700;
            background: rgba(255,255,255,0.9);
            padding: 6px 10px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .status-note .muted {
            font-weight:600;
            color: #444;
            font-size: 12px;
            margin-left:8px;
        }

        footer {
            background: linear-gradient(135deg, rgba(0,82,204,0.95) 0%, rgba(30,136,229,0.95) 100%);
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 12px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
        }

        .update-time {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* Scrollbar styling */
        .doctor-grid::-webkit-scrollbar {
            width: 4px;
        }

        .doctor-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .doctor-grid::-webkit-scrollbar-thumb {
            background: var(--primary-blue);
            border-radius: 10px;
        }

        .doctor-grid::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-blue);
        }

        /* Tablet and iPad (768px to 1024px) */
        @media (max-width: 1024px) {
            .header-title {
                font-size: 28px;
            }

            .departments-wrapper {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 10px;
            }

            .dept-name {
                font-size: 15px;
            }

            .doctor-name {
                font-size: 13px;
            }

            .status-badge {
                font-size: 9px;
                padding: 2px 6px;
            }

            .resume-info {
                font-size: 8px;
            }
        }

        /* Tablet portrait (600px to 900px) */
        @media (max-width: 900px) {
            header {
                padding: 12px 15px;
            }

            .header-title {
                font-size: 24px;
                gap: 8px;
            }

            .header-subtitle {
                font-size: 11px;
            }

            .container {
                padding: 12px;
            }

            .departments-wrapper {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 10px;
            }

            .department-section {
                padding: 10px;
                border-top: 2px solid var(--primary-blue);
            }

            .dept-header {
                margin-bottom: 8px;
                padding-bottom: 6px;
                gap: 6px;
            }

            .dept-icon {
                font-size: 16px;
            }

            .dept-name {
                font-size: 14px;
            }

            .doctor-grid {
                gap: 6px;
            }

            .doctor-card {
                padding: 8px;
                border-left: 2px solid var(--accent-yellow);
            }

            .doctor-name {
                font-size: 12px;
                margin-bottom: 4px;
                gap: 4px;
            }

            .status-badge {
                font-size: 8px;
                padding: 2px 5px;
            }

            .resume-info {
                font-size: 8px;
                margin-top: 3px;
            }

            footer {
                padding: 8px;
                font-size: 11px;
            }

            .update-time {
                gap: 4px;
            }
        }

        /* Mobile landscape (600px to 768px) */
        @media (max-width: 768px) {
            header {
                padding: 10px 12px;
            }

            .header-title {
                font-size: 20px;
                gap: 6px;
            }

            .header-subtitle {
                font-size: 10px;
            }

            .container {
                padding: 10px;
            }

            .departments-wrapper {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .department-section {
                padding: 8px;
                border-top: 2px solid var(--primary-blue);
                border-radius: 6px;
            }

            .dept-header {
                margin-bottom: 6px;
                padding-bottom: 5px;
                gap: 4px;
            }

            .dept-icon {
                font-size: 14px;
            }

            .dept-name {
                font-size: 13px;
            }

            .doctor-grid {
                gap: 5px;
            }

            .doctor-card {
                padding: 7px;
                border-left: 2px solid var(--accent-yellow);
            }

            .doctor-name {
                font-size: 11px;
                margin-bottom: 3px;
                gap: 3px;
            }

            .doctor-icon {
                font-size: 12px;
            }

            .status-badge {
                font-size: 7px;
                padding: 1px 4px;
            }

            .resume-info {
                font-size: 7px;
                margin-top: 2px;
            }

            footer {
                padding: 6px;
                font-size: 10px;
            }

            .update-time {
                gap: 3px;
                font-size: 9px;
            }
        }

        /* Mobile portrait (up to 600px) */
        @media (max-width: 600px) {
            header {
                padding: 8px 10px;
            }

            .header-title {
                font-size: 18px;
                gap: 4px;
            }

            .header-subtitle {
                font-size: 9px;
            }

            .container {
                padding: 8px;
            }

            .departments-wrapper {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .department-section {
                padding: 8px;
                border-top: 2px solid var(--primary-blue);
                border-radius: 6px;
            }

            .dept-header {
                margin-bottom: 6px;
                padding-bottom: 5px;
                gap: 4px;
            }

            .dept-icon {
                font-size: 14px;
            }

            .dept-name {
                font-size: 13px;
            }

            .doctor-grid {
                gap: 5px;
            }

            .doctor-card {
                padding: 7px;
                border-left: 2px solid var(--accent-yellow);
            }

            .doctor-name {
                font-size: 12px;
                margin-bottom: 3px;
                gap: 3px;
            }

            .status-badge {
                font-size: 8px;
                padding: 2px 5px;
            }

            .resume-info {
                font-size: 8px;
                margin-top: 2px;
            }

            footer {
                padding: 6px;
                font-size: 10px;
            }

            .update-time {
                gap: 3px;
            }
        }

        /* Small mobile (under 400px) */
        @media (max-width: 400px) {
            header {
                padding: 6px 8px;
            }

            .header-title {
                font-size: 16px;
                gap: 4px;
            }

            .header-subtitle {
                font-size: 8px;
            }

            .container {
                padding: 6px;
            }

            .departments-wrapper {
                gap: 6px;
            }

            .department-section {
                padding: 6px;
                border-top: 2px solid var(--primary-blue);
            }

            .dept-header {
                margin-bottom: 4px;
                padding-bottom: 4px;
                gap: 4px;
            }

            .dept-icon {
                font-size: 12px;
            }

            .dept-name {
                font-size: 12px;
            }

            .doctor-grid {
                gap: 4px;
            }

            .doctor-card {
                padding: 6px;
                border-left: 2px solid var(--accent-yellow);
            }

            .doctor-name {
                font-size: 11px;
                margin-bottom: 2px;
                gap: 2px;
            }

            .doctor-icon {
                font-size: 10px;
            }

            .status-badge {
                font-size: 7px;
                padding: 1px 3px;
            }

            .resume-info {
                font-size: 7px;
                margin-top: 1px;
            }

            footer {
                padding: 4px;
                font-size: 9px;
            }

            .update-time {
                gap: 2px;
                font-size: 8px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-content">
        <div class="header-title">
            <img src="assets/logo2.png" alt="New Sinai MDI Hospital Logo" class="header-logo" />
            <span>New Sinai MDI Hospital</span>
        </div>
        <div class="header-subtitle">Doctor Availability Board</div>
    </div>
</header>

<?php if (!empty($announcement) && trim($announcement['text'] ?? '') !== ''):
    $font_size = intval($announcement['font_size'] ?? 32);
    $speed = intval($announcement['speed'] ?? 14);
    $bg_color = $announcement['bg_color'] ?? '#fff8e1';
    $text_color = $announcement['text_color'] ?? '#052744';
    $text = $announcement['text'];
?>
    <div id="announcement-wrap" class="announcement-wrap" style="background: <?= htmlspecialchars($bg_color) ?>; color: <?= htmlspecialchars($text_color) ?>;">
        <div style="display:flex; align-items:center; gap:8px; width:100%;">
            <i id="announcement-megaphone" class="bi bi-megaphone-fill" style="font-size:22px; color: <?= htmlspecialchars($text_color) ?>;"></i>
            <div class="announcement" id="announcement" style="flex:1; --fade-bg: <?= htmlspecialchars($bg_color) ?>;">
                <div class="marquee"><div class="marquee-track"><span class="marquee-content" data-speed="<?= $speed ?>" style="font-size: <?= $font_size ?>px; color: <?= htmlspecialchars($text_color) ?>;"><?= htmlspecialchars($text) ?></span></div></div>
            </div>
            <div id="announcement-updated" style="min-width:120px; text-align:right; font-size:12px; opacity:0.9;">
                <?= !empty($announcement['updated_at']) ? htmlspecialchars(date('M d, Y H:i', strtotime($announcement['updated_at']))) : '' ?>
            </div>
        </div>
    </div> 
<?php endif; ?>

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
                    // Skip "On Schedule" entries entirely (do not display them)
                    continue;
                } else {
                    $key = 'no medical';
                }
                $groups[$key][] = $d;
            }
    ?>

    <div class="three-columns departments-wrapper" role="list">
        <div class="status-col" data-status="no medical" role="group" aria-labelledby="<?= 'col-'.md5('no medical') ?>">
            <div class="col-header" id="<?= 'col-'.md5('no medical') ?>"><span>No Clinic</span> <span class="col-count">(<?= count($groups['no medical']) ?>)</span></div>
            <div class="col-list" role="list">
                <?php foreach ($groups['no medical'] as $doctor): ?>
                    <div class="doctor-card" role="listitem">
                        <div class="doctor-name">
                            <i class="doctor-icon bi bi-person-fill" aria-hidden="true"></i>
                            <?= htmlspecialchars($doctor['name']) ?>
                            <small class="dept-tag" style="margin-left:8px; opacity:0.8; font-weight:600;">(<?= htmlspecialchars($doctor['department'] ?? '') ?>)</small>
                        </div>

                        <?php
                            $low = strtolower(trim($doctor['status'] ?? ''));
                            if ($low === '' || strpos($low, 'no medical') !== false || strpos($low, 'no clinic') !== false) {
                                $badge_class = 'status-noclinic'; $status_label = 'No Clinic'; $icon = 'bi-x-circle-fill';
                            } elseif (strpos($low, 'leave') !== false) {
                                $badge_class = 'status-onleave'; $status_label = 'On Leave'; $icon = 'bi-clock-history';
                            } else {
                                $badge_class = 'status-onschedule'; $status_label = 'On Schedule'; $icon = 'bi-calendar-check';
                            }
                        ?>

                        <span class="status-badge <?= $badge_class ?>"><i class="bi <?= $icon ?>" aria-hidden="true" style="margin-right:6px"></i> <?= htmlspecialchars($status_label) ?></span>

                        <div class="status-note">
                            <span>No Clinic</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="status-col" data-status="on leave" role="group" aria-labelledby="<?= 'col-'.md5('on leave') ?>">
            <div class="col-header" id="<?= 'col-'.md5('on leave') ?>"><span>On Leave</span> <span class="col-count">(<?= count($groups['on leave']) ?>)</span></div>
            <div class="col-list" role="list">
                <?php foreach ($groups['on leave'] as $doctor): ?>
                    <div class="doctor-card" role="listitem">
                        <div class="doctor-name">
                            <i class="doctor-icon bi bi-person-fill" aria-hidden="true"></i>
                            <?= htmlspecialchars($doctor['name']) ?>
                            <small class="dept-tag" style="margin-left:8px; opacity:0.8; font-weight:600;">(<?= htmlspecialchars($doctor['department'] ?? '') ?>)</small>
                        </div>

                        <?php
                            $low = strtolower(trim($doctor['status'] ?? ''));
                            if ($low === '' || strpos($low, 'no medical') !== false || strpos($low, 'no clinic') !== false) {
                                $badge_class = 'status-noclinic'; $status_label = 'No Clinic'; $icon = 'bi-x-circle-fill';
                            } elseif (strpos($low, 'leave') !== false) {
                                $badge_class = 'status-onleave'; $status_label = 'On Leave'; $icon = 'bi-clock-history';
                            } else {
                                $badge_class = 'status-onschedule'; $status_label = 'On Schedule'; $icon = 'bi-calendar-check';
                            }
                        ?>

                        <span class="status-badge <?= $badge_class ?>"><i class="bi <?= $icon ?>" aria-hidden="true" style="margin-right:6px"></i> <?= htmlspecialchars($status_label) ?></span>

                        <div class="status-note">
                            <?php if (!empty($doctor['remarks'])): ?>
                                <span>Remarks:</span>
                                <span class="muted"><?= htmlspecialchars($doctor['remarks']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($doctor['resume_date'])): ?>
                                <div class="resume-info"><i class="bi bi-calendar-event"></i>Resumes: <?= date("M d, Y", strtotime($doctor['resume_date'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<footer>
    <div class="update-time">
        <i class="bi bi-arrow-repeat"></i>
        Auto-updated every 10 seconds • Last updated: <span id="current-time"></span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function updatePhilippinesTime() {
        // Get current time in Philippines timezone (UTC+8)
        const options = {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        
        const phillipinesTime = new Intl.DateTimeFormat('en-US', options).format(new Date());
        document.getElementById('current-time').textContent = phillipinesTime;
    }
    
    // Update time immediately
    updatePhilippinesTime();
    
    // Update time every second
    setInterval(updatePhilippinesTime, 1000);

    // Marquee (improved) — continuous seamless scroll, pauses on hover, restarts on resize
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.announcement .marquee').forEach(m => {
            const container = m.closest('.announcement');
            const original = m.querySelector('.marquee-content');
            if (!original || !container) return;

            // ensure track exists and contains two copies for seamless loop
            let track = m.querySelector('.marquee-track');
            track.style.overflow = 'hidden';
            track.style.display = 'block';

            // create two copies of the content inside an inner flex track (for seamless loop)
            function buildTrack() {
                track.innerHTML = '';
                const c1 = document.createElement('span');
                c1.className = 'marquee-item';
                c1.style.whiteSpace = 'nowrap';
                c1.style.display = 'inline-block';
                // reduce repeat gap for tighter flow; make adjustable later
                c1.style.paddingRight = '2.5rem';
                // preserve admin font size but ensure a minimum for readability
                const origFont = original.style.fontSize || '';
                if (!origFont) c1.style.fontSize = '20px';
                c1.innerHTML = original.innerHTML;
                const c2 = c1.cloneNode(true);
                track.appendChild(c1); track.appendChild(c2);
                return c1;
            }

            let anim = null, item = null;

            function startAnim() {
                // cancel existing animation
                if (anim && typeof anim.cancel === 'function') try { anim.cancel(); } catch(e){}

                item = buildTrack();
                const containerWidth = window.innerWidth || document.documentElement.clientWidth; // use viewport width so it moves across entire screen
                // ensure track spans viewport
                track.style.position = 'absolute'; track.style.left = '0'; track.style.right = '0'; track.style.width = containerWidth + 'px';

                // Ensure repeated item covers container width to avoid visible gaps (make infinite seamless)
                const contentWidth = item.scrollWidth || item.offsetWidth;
                if (contentWidth < containerWidth) {
                    const extra = containerWidth - contentWidth + 80; // breathing room
                    item.style.paddingRight = extra + 'px';
                }
                let itemWidth = item.offsetWidth;

                // How long a single full cycle should take (base speed in seconds from admin)
                const baseSpeed = parseFloat(original.dataset.speed) || 14;
                // scale by content length relative to container so long texts scroll proportionally
                const duration = Math.max(2000, Math.round(baseSpeed * 1000 * (itemWidth / Math.max(containerWidth, 100))));

                // Use WAAPI for smoothness where available
                const trackEl = track;

                // ensure track layout supports full translation: set width to two repeats
                trackEl.style.display = 'flex';
                trackEl.style.width = (itemWidth * 2) + 'px';
                // start at 0 (first copy) so the animation is seamless between the two copies
                trackEl.style.transform = `translateX(0px)`;

                // duration scaled by item width so speed is consistent
                const durationAdjusted = Math.max(2000, Math.round(baseSpeed * 1000 * (itemWidth / Math.max(containerWidth, 100))));

                try {
                    // animate from 0 to -itemWidth (seamless with duplicated content)
                    anim = trackEl.animate([
                        { transform: `translateX(0px)` },
                        { transform: `translateX(-${itemWidth}px)` }
                    ], { duration: durationAdjusted, iterations: Infinity, easing: 'linear' });
                    trackEl.style.animation = '';
                } catch (e) {
                    // CSS fallback: inject keyframes with unique name
                    const uid = 'm' + Math.random().toString(36).slice(2,9);
                    const keyframes = `@keyframes ${uid} { from { transform: translateX(0px); } to { transform: translateX(-${itemWidth}px); } }`;
                    const style = document.createElement('style'); style.textContent = keyframes; document.head.appendChild(style);
                    trackEl.style.display = 'flex';
                    trackEl.style.width = (itemWidth*2) + 'px';
                    trackEl.style.animation = `${uid} ${durationAdjusted}ms linear infinite`;
                }

                // Pause/resume handlers
                container.addEventListener('mouseenter', function() {
                    if (anim && typeof anim.pause === 'function') anim.pause();
                    else track.style.animationPlayState = 'paused';
                });
                container.addEventListener('mouseleave', function() {
                    if (anim && typeof anim.play === 'function') anim.play();
                    else track.style.animationPlayState = 'running';
                });

                // Monitor overlap with megaphone to create 'through' effect (use viewport coordinates)
                let rafId = null;
                const meg = document.getElementById('announcement-megaphone');
                function monitor() {
                    if (!meg) return;
                    const mrect = meg.getBoundingClientRect();
                    const items = track.querySelectorAll('.marquee-item');
                    let overlapping = false;
                    items.forEach(n => {
                        const r = n.getBoundingClientRect();
                        const isOverlap = !(r.right < mrect.left || r.left > mrect.right || r.bottom < mrect.top || r.top > mrect.bottom);
                        n.classList.toggle('passing', !!isOverlap);
                        if (isOverlap) overlapping = true;
                    });
                    // also ensure megaphone highlight toggles
                    if (meg) {
                        if (overlapping) meg.classList.add('meg-active'); else meg.classList.remove('meg-active');
                    }
                    rafId = requestAnimationFrame(monitor);
                }

                // stop previous monitor if any
                if (typeof window._marqueeMonitor === 'number') cancelAnimationFrame(window._marqueeMonitor);
                window._marqueeMonitor = null;
                rafId = requestAnimationFrame(monitor);
                window._marqueeMonitor = rafId;
            }

            // start immediately
            startAnim();

            // restart on resize to recompute widths
            let resizeTimer;
            window.addEventListener('resize', function() { clearTimeout(resizeTimer); resizeTimer = setTimeout(startAnim, 150); });
        });
    });

    // --- Live updates without full page reload (AJAX polling) ---
    (function() {
        const POLL_MS = 5000;
        let lastData = null;

        function formatUpdatedAt(ts) {
            try {
                const d = new Date(ts);
                return d.toLocaleString('en-US', {year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit'});
            } catch(e) { return ts || ''; }
        }

        function buildDoctorCard(doc) {
            const card = document.createElement('div');
            card.className = 'doctor-card';
            const name = document.createElement('div');
            name.className = 'doctor-name';
            const icon = document.createElement('i'); icon.className = 'doctor-icon bi bi-person-fill'; icon.setAttribute('aria-hidden','true');
            name.appendChild(icon);
            const text = document.createElement('span'); text.textContent = doc.name || '';
            name.appendChild(text);
            card.appendChild(name);

            const status = document.createElement('span');
            const st = (doc.status || '').toLowerCase();
            let label = 'No Clinic', badge = 'status-noclinic', iconCls = 'bi-x-circle-fill';
            if (st.indexOf('leave') !== -1) { label = 'On Leave'; badge = 'status-onleave'; iconCls = 'bi-clock-history'; }
            else if (st.indexOf('schedule') !== -1 || st.indexOf('available') !== -1) { label = 'On Schedule'; badge = 'status-onschedule'; iconCls = 'bi-calendar-check'; }
            status.className = 'status-badge ' + badge;
            status.innerHTML = '<i class="bi ' + iconCls + '" aria-hidden="true" style="margin-right:6px"></i> ' + label;
            card.appendChild(status);

            // helper to parse common DB time formats robustly
            function parsePossibleTime(s) {
                if (!s) return null;
                s = String(s).trim();
                if (!s || s.indexOf('0000') === 0) return null; // invalid zero-date

                // HH:MM or HH:MM:SS
                const hm = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
                if (hm) {
                    const h = parseInt(hm[1],10), m = parseInt(hm[2],10), sec = parseInt(hm[3]||'0',10);
                    const d = new Date(); d.setHours(h, m, sec, 0); return d;
                }

                // common DB format YYYY-MM-DD HH:MM:SS -> convert to ISO
                const isoLike = s.replace(' ', 'T');
                let d = new Date(isoLike);
                if (!isNaN(d)) return d;

                // fallback to Date parsing
                d = new Date(s);
                if (!isNaN(d)) return d;

                return null;
            }

            function formatTimeStr(s) {
                const d = parsePossibleTime(s);
                if (!d) return null;
                try { return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}); } catch(e) { return null; }
            }

            const note = document.createElement('div'); note.className = 'status-note';
            if (label === 'On Schedule') {
                const start = formatTimeStr(doc.appt_start);
                const end = formatTimeStr(doc.appt_end);
                if (start && end) {
                    note.innerHTML = '<span>Appointment:</span> <span class="muted">' + start + ' - ' + end + '</span>';
                } else if (start) {
                    note.innerHTML = '<span>Appointment from</span> <span class="muted">' + start + '</span>';
                } else {
                    note.innerHTML = '<span>On Schedule</span> <span class="muted">(no time set)</span>';
                }
            } else if (label === 'No Clinic') {
                note.textContent = 'No Clinic';
            } else if (label === 'On Leave') {
                if (doc.remarks) {
                    note.innerHTML = '<span>Remarks:</span> <span class="muted">' + doc.remarks + '</span>';
                }
                if (doc.resume_date) {
                    const res = document.createElement('div'); res.className = 'resume-info'; res.innerHTML = '<i class="bi bi-calendar-event"></i>Resumes: ' + formatUpdatedAt(doc.resume_date); note.appendChild(res);
                }
            }
            card.appendChild(note);
            return card;
        }

        function updateBoard(data) {
            if (!data) return;
            // Announcement
            const aw = document.getElementById('announcement-wrap');
            if (!data.announcement || !data.announcement.text || data.announcement.text.trim() === '') {
                if (aw) aw.style.display = 'none';
            } else {
                if (aw) {
                    aw.style.display = 'flex';
                    aw.style.background = data.announcement.bg_color || '';
                    aw.style.color = data.announcement.text_color || '';
                    const span = aw.querySelector('.marquee-content');
                    if (span) {
                        span.textContent = data.announcement.text || '';
                        span.style.fontSize = (data.announcement.font_size || 32) + 'px';
                        span.dataset.speed = data.announcement.speed || 14;
                    }
                    const up = document.getElementById('announcement-updated');
                    if (up) up.textContent = data.announcement.updated_at ? formatUpdatedAt(data.announcement.updated_at) : '';
                    // restart marquee by triggering resize
                    window.dispatchEvent(new Event('resize'));
                }
            }

            // Status columns (two columns across all doctors)
            const wrapper = document.querySelector('.three-columns');
            if (!wrapper) return;

            // If identical data, skip repaint for performance
            try { if (JSON.stringify(data) === JSON.stringify(lastData)) return; } catch(e) {}
            lastData = data;

            // group flat doctors array (exclude "On Schedule" entries)
            const groups = { 'no medical': [], 'on leave': [] };
            (data.doctors || []).forEach(d => {
                const st = (d.status || '').toLowerCase();
                if (st === '' || st.indexOf('no medical') !== -1 || st.indexOf('no clinic') !== -1) groups['no medical'].push(d);
                else if (st.indexOf('leave') !== -1) groups['on leave'].push(d);
                else if (st.indexOf('schedule') !== -1 || st.indexOf('available') !== -1) {
                    // skip on-schedule entries
                } else groups['no medical'].push(d);
            });

            // rebuild with two side-by-side columns
            wrapper.innerHTML = '';
            const order = [['no medical','No Clinic'], ['on leave','On Leave']];
            order.forEach(([key,label]) => {
                const col = document.createElement('div'); col.className = 'status-col'; col.setAttribute('data-status', key);
                const hdr = document.createElement('div'); hdr.className = 'col-header'; hdr.innerHTML = '<span>' + label + '</span> <span class="col-count">(' + (groups[key]||[]).length + ')</span>';
                const list = document.createElement('div'); list.className = 'col-list';
                (groups[key] || []).forEach(doc => {
                    const card = buildDoctorCard(doc);
                    const deptTag = document.createElement('small'); deptTag.className = 'dept-tag'; deptTag.style.marginLeft = '8px'; deptTag.style.opacity = '0.8'; deptTag.style.fontWeight = '600'; deptTag.textContent = '(' + (doc.department || '') + ')';
                    const name = card.querySelector('.doctor-name'); if (name) name.appendChild(deptTag);
                    list.appendChild(card);
                });
                col.appendChild(hdr); col.appendChild(list); wrapper.appendChild(col);
            });
        }

        async function fetchLoop() {
            try {
                const res = await fetch(window.location.pathname + '?ajax=1');
                if (!res.ok) return;
                const data = await res.json();
                updateBoard(data);
            } catch (e) { console.warn('Live update failed', e); }
        }

        // Initial fetch and periodic polling
        fetchLoop();
        setInterval(fetchLoop, POLL_MS);
    })();
</script>