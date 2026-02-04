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
            position: relative;
            z-index: 1100;
            padding: 10px 16px;
            box-shadow: 0 6px 18px rgba(2,6,23,0.12);
            border-bottom: 3px solid rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .announcement { 
            position: relative; 
            overflow: hidden; 
            width: 100%; 
            height: 40px;
        }
        .announcement::before, .announcement::after { 
            content: ''; 
            position: absolute; 
            top:0; 
            bottom:0; 
            width:6%; 
            pointer-events: none; 
            z-index:2; 
        }
        .announcement::before { 
            left:0; 
            background: linear-gradient(to right, var(--fade-bg), transparent); 
        }
        .announcement::after { 
            right:0; 
            background: linear-gradient(to left, var(--fade-bg), transparent); 
        }
        
        /* Marquee track - continuous infinite scroll from right to left */
        .announcement .marquee { 
            position: relative; 
            height: 100%; 
            overflow: hidden; 
        }
        .announcement .marquee .marquee-track {
            position: absolute; 
            left: 0; 
            top: 50%; 
            transform: translateY(-50%);
            display: inline-flex; 
            align-items: center; 
            white-space: nowrap;
            will-change: transform;
        }
        .announcement .marquee .marquee-item {
            display: inline-block; 
            font-weight: 800; 
            white-space: nowrap; 
            padding-right: 100px;
        }
        
        @keyframes scrollLeftInfinite {
            0% {
                transform: translateX(0) translateY(-50%);
            }
            100% {
                transform: translateX(-50%) translateY(-50%);
            }
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

        /* Single table with dividing line */
        .main-board {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(180deg, rgba(255,255,255,0.86), rgba(245,250,255,0.80));
            border-radius: 8px;
            padding: 0;
            box-shadow: 0 6px 20px rgba(3,32,71,0.08);
            border-top: 3px solid rgba(0,82,204,0.12);
            overflow: hidden;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            position: relative;
        }

        /* Vertical dividing line */
        .main-board::before {
            content: '';
            position: absolute;
            left: 50%;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(to bottom, 
                rgba(0,82,204,0.1) 0%, 
                rgba(0,82,204,0.3) 50%, 
                rgba(0,82,204,0.1) 100%);
            transform: translateX(-50%);
            z-index: 1;
        }

        .status-col {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 12px 18px;
            overflow: hidden;
            min-height: 0;
            position: relative;
        }

        .col-header { 
            font-weight: 800; 
            font-size: 18px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            gap: 8px; 
            padding-bottom: 10px; 
            border-bottom: 2px solid var(--accent-yellow);
            flex-shrink: 0;
        }

        .col-header-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .col-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .resumes-label {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-blue);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .resumes-label i {
            font-size: 14px;
        }

        .current-date {
            font-size: 13px;
            font-weight: 600;
            color: var(--primary-blue);
            background: rgba(0,82,204,0.08);
            padding: 4px 10px;
            border-radius: 6px;
        }

        .col-list { 
            overflow-y: auto; 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
            padding-top: 6px; 
            min-height: 0; 
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
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .doctor-icon {
            font-size: 20px;
            flex-shrink: 0;
        }

        .doctor-specialization {
            font-size: 14px;
            color: #444;
            font-weight: 600;
            margin-bottom: 6px;
            padding-left: 19px;
        }

        .doctor-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
        }

        .doctor-name-left {
            display: flex;
            align-items: center;
            gap: 5px;
            flex: 1;
            min-width: 0;
        }

        .resume-date-right {
            font-size: 18px;
            color: var(--primary-blue);
            font-weight: 600;
            white-space: nowrap;
            background: rgba(0,82,204,0.08);
            padding: 3px 8px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .status-badge { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            padding: 0; 
            font-weight: 700; 
            font-size: 13px; 
        }
        .status-badge::before { 
            content: ""; 
            display: inline-block; 
            width: 10px; 
            height: 10px; 
            border-radius: 50%; 
            background: currentColor; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.06); 
            transform: translateY(-1px); 
        }
        .status-available { color: var(--success); }
        .status-unavailable { color: var(--danger); }
        .status-onleave { color: var(--danger); }
        .status-noclinic { color: #6c757d; }
        .status-onschedule { color: var(--primary-blue); }

        .resume-info {
            font-size: 11px;
            color: #444;
            margin-top: 6px;
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            display: block;
        }

        .resume-info i {
            color: var(--secondary-blue);
            margin-right: 6px;
        }

        /* Status note */
        .status-note {
            font-size: 13px;
            color: #052744;
            margin-top: 8px;
            display: block;
            font-weight: 700;
            background: rgba(255,255,255,0.9);
            padding: 6px 10px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .status-note .muted {
            font-weight: 600;
            color: #444;
            font-size: 12px;
            margin-left: 8px;
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
        .col-list::-webkit-scrollbar {
            width: 4px;
        }

        .col-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .col-list::-webkit-scrollbar-thumb {
            background: var(--primary-blue);
            border-radius: 10px;
        }

        .col-list::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-blue);
        }

        /* Mobile: stack vertically */
        @media (max-width: 900px) {
            .main-board { 
                grid-template-columns: 1fr; 
                gap: 0;
            }
            .main-board::before {
                left: 0;
                right: 0;
                top: 50%;
                bottom: auto;
                width: auto;
                height: 3px;
                background: linear-gradient(to right, 
                    rgba(0,82,204,0.1) 0%, 
                    rgba(0,82,204,0.3) 50%, 
                    rgba(0,82,204,0.1) 100%);
                transform: translateY(-50%);
            }
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .header-title {
                font-size: 28px;
            }

            .doctor-name {
                font-size: 18px;
            }

            .status-badge {
                font-size: 9px;
            }
        }

        @media (max-width: 900px) {
            header {
                padding: 12px 15px;
            }

            .header-title {
                font-size: 24px;
                gap: 8px;
            }

        /* Tablet and iPad (768px to 1024px) */
        @media (max-width: 1024px) {
            .header-title {
                font-size: 26px;
            }

            .header-subtitle {
                font-size: 11px;
            }

            .col-header {
                font-size: 16px;
                padding-bottom: 8px;
            }

            .current-date {
                font-size: 12px;
                padding: 3px 8px;
            }

            .doctor-name {
                font-size: 18px;
            }

            .doctor-specialization {
                font-size: 12px;
            }

            .status-badge {
                font-size: 11px;
            }

            .resume-info {
                font-size: 10px;
            }

            .status-note {
                font-size: 12px;
                padding: 5px 8px;
            }
        }

        /* Tablet portrait (600px to 900px) */
        @media (max-width: 900px) {
            body {
                font-size: 14px;
            }

            header {
                padding: 12px 15px;
            }

            .header-title {
                font-size: 22px;
                gap: 8px;
            }

            .header-subtitle {
                font-size: 10px;
            }

            .announcement-wrap {
                padding: 8px 12px;
            }

            .announcement .marquee .marquee-item {
                font-size: 18px !important;
                padding-right: 80px;
            }

            .container {
                padding: 10px;
            }

            .main-board {
                padding: 8px;
            }

            .status-col {
                padding: 10px 12px;
            }

            .col-header {
                font-size: 15px;
                padding-bottom: 8px;
            }

            .current-date {
                font-size: 11px;
                padding: 3px 8px;
            }

            .doctor-card {
                padding: 10px;
            }

            .doctor-name {
                font-size: 14px;
                margin-bottom: 4px;
            }

            .doctor-icon {
                font-size: 13px;
            }

            .doctor-specialization {
                font-size: 11px;
                margin-bottom: 6px;
                padding-left: 18px;
            }

            .resume-date-right {
                font-size: 10px;
                padding: 2px 6px;
            }

            .resumes-label {
                font-size: 12px;
            }

            .resumes-label i {
                font-size: 12px;
            }

            .status-badge {
                font-size: 11px;
            }

            .resume-info {
                font-size: 10px;
                margin-top: 5px;
            }

            .status-note {
                font-size: 11px;
                padding: 5px 8px;
                margin-top: 6px;
            }

            footer {
                padding: 8px;
                font-size: 11px;
            }

            .update-time {
                gap: 4px;
            }
        }

        /* Mobile landscape and portrait (480px to 768px) */
        @media (max-width: 768px) {
            header {
                padding: 10px 12px;
            }

            .header-title {
                font-size: 20px;
                gap: 6px;
            }

            .header-logo {
                height: 36px;
            }

            .header-subtitle {
                font-size: 10px;
            }

            .announcement-wrap {
                padding: 8px 10px;
            }

            #announcement-megaphone {
                font-size: 18px !important;
            }

            .announcement .marquee .marquee-item {
                font-size: 16px !important;
                padding-right: 60px;
            }

            #announcement-updated {
                font-size: 10px !important;
                min-width: 90px;
            }

            .container {
                padding: 8px;
            }

            .main-board {
                padding: 8px;
                gap: 8px;
            }

            .status-col {
                padding: 10px;
            }

            .col-header {
                font-size: 14px;
                padding-bottom: 6px;
            }

            .current-date {
                font-size: 10px;
                padding: 3px 6px;
            }

            .doctor-card {
                padding: 9px;
            }

            .doctor-name {
                font-size: 13px;
                margin-bottom: 3px;
            }

            .doctor-icon {
                font-size: 12px;
            }

            .doctor-specialization {
                font-size: 11px;
                margin-bottom: 5px;
                padding-left: 17px;
            }

            .resume-date-right {
                font-size: 9px;
                padding: 2px 5px;
            }

            .resumes-label {
                font-size: 11px;
            }

            .resumes-label i {
                font-size: 11px;
            }

            .status-badge {
                font-size: 10px;
            }

            .status-badge::before {
                width: 8px;
                height: 8px;
            }

            .resume-info {
                font-size: 9px;
                margin-top: 4px;
            }

            .status-note {
                font-size: 11px;
                padding: 5px 7px;
                margin-top: 5px;
            }

            .status-note .muted {
                font-size: 10px;
            }

            footer {
                padding: 7px;
                font-size: 10px;
            }
        }

        /* Mobile portrait (up to 600px) */
        @media (max-width: 600px) {
            body {
                font-size: 13px;
            }

            header {
                padding: 8px 10px;
            }

            .header-title {
                font-size: 18px;
                gap: 6px;
            }

            .header-logo {
                height: 32px;
            }

            .header-subtitle {
                font-size: 9px;
            }

            .announcement-wrap {
                padding: 6px 8px;
                gap: 8px;
            }

            #announcement-megaphone {
                font-size: 16px !important;
            }

            .announcement .marquee .marquee-item {
                font-size: 14px !important;
                padding-right: 50px;
            }

            #announcement-updated {
                font-size: 9px !important;
                min-width: 80px;
            }

            .container {
                padding: 8px;
            }

            .main-board {
                padding: 6px;
            }

            .status-col {
                padding: 8px 10px;
                gap: 6px;
            }

            .col-header {
                font-size: 13px;
                padding-bottom: 6px;
                gap: 6px;
            }

            .current-date {
                font-size: 9px;
                padding: 2px 6px;
            }

            .col-list {
                gap: 6px;
                padding-top: 4px;
            }

            .doctor-card {
                padding: 8px;
                border-left: 2px solid rgba(255,193,7,0.85);
            }

            .doctor-name {
                font-size: 12px;
                margin-bottom: 3px;
                gap: 4px;
            }

            .doctor-icon {
                font-size: 11px;
            }

            .doctor-specialization {
                font-size: 10px;
                margin-bottom: 4px;
                padding-left: 15px;
            }

            .resume-date-right {
                font-size: 8px;
                padding: 2px 4px;
            }

            .resumes-label {
                font-size: 10px;
            }

            .resumes-label i {
                font-size: 10px;
            }

            .status-badge {
                font-size: 9px;
                gap: 6px;
            }

            .status-badge::before {
                width: 7px;
                height: 7px;
            }

            .resume-info {
                font-size: 9px;
                margin-top: 4px;
            }

            .status-note {
                font-size: 10px;
                padding: 4px 6px;
                margin-top: 5px;
            }

            .status-note .muted {
                font-size: 9px;
                margin-left: 4px;
            }

            footer {
                padding: 6px;
                font-size: 9px;
            }

            .update-time {
                gap: 3px;
            }
        }

        /* Small mobile (under 400px) */
        @media (max-width: 400px) {
            body {
                font-size: 12px;
            }

            header {
                padding: 6px 8px;
            }

            .header-title {
                font-size: 16px;
                gap: 4px;
            }

            .header-logo {
                height: 28px;
            }

            .header-subtitle {
                font-size: 8px;
            }

            .announcement-wrap {
                padding: 5px 6px;
                gap: 6px;
            }

            #announcement-megaphone {
                font-size: 14px !important;
            }

            .announcement .marquee .marquee-item {
                font-size: 12px !important;
                padding-right: 40px;
            }

            #announcement-updated {
                font-size: 8px !important;
                min-width: 70px;
            }

            .container {
                padding: 6px;
            }

            .main-board {
                padding: 5px;
                border-top: 2px solid rgba(0,82,204,0.12);
            }

            .status-col {
                padding: 6px 8px;
                gap: 5px;
            }

            .col-header {
                font-size: 12px;
                padding-bottom: 5px;
                gap: 4px;
            }

            .current-date {
                font-size: 8px;
                padding: 2px 5px;
            }

            .col-list {
                gap: 5px;
                padding-top: 3px;
            }

            .doctor-card {
                padding: 7px;
                border-left: 2px solid rgba(255,193,7,0.85);
            }

            .doctor-name {
                font-size: 11px;
                margin-bottom: 2px;
                gap: 3px;
            }

            .doctor-icon {
                font-size: 10px;
            }

            .doctor-specialization {
                font-size: 9px;
                margin-bottom: 4px;
                padding-left: 13px;
            }

            .resume-date-right {
                font-size: 7px;
                padding: 1px 3px;
            }

            .resumes-label {
                font-size: 9px;
            }

            .resumes-label i {
                font-size: 9px;
            }

            .status-badge {
                font-size: 8px;
                gap: 5px;
            }

            .status-badge::before {
                width: 6px;
                height: 6px;
            }

            .resume-info {
                font-size: 8px;
                margin-top: 3px;
            }

            .status-note {
                font-size: 9px;
                padding: 4px 5px;
                margin-top: 4px;
            }

            .status-note .muted {
                font-size: 8px;
                margin-left: 3px;
            }

            footer {
                padding: 5px;
                font-size: 8px;
            }

            .update-time {
                gap: 2px;
                font-size: 8px;
            }
        }

        /* Extra small mobile (under 360px) */
        @media (max-width: 360px) {
            .header-title {
                font-size: 14px;
                gap: 4px;
            }

            .header-logo {
                height: 24px;
            }

            .header-subtitle {
                font-size: 7px;
            }

            .announcement-wrap {
                padding: 4px 5px;
            }

            #announcement-megaphone {
                font-size: 12px !important;
            }

            .announcement .marquee .marquee-item {
                font-size: 11px !important;
                padding-right: 35px;
            }

            #announcement-updated {
                font-size: 7px !important;
                min-width: 60px;
            }

            .col-header {
                font-size: 11px;
            }

            .current-date {
                font-size: 7px;
                padding: 2px 4px;
            }

            .doctor-name {
                font-size: 10px;
            }

            .doctor-specialization {
                font-size: 8px;
                padding-left: 12px;
            }

            .resume-date-right {
                font-size: 6px;
                padding: 1px 3px;
            }

            .resumes-label {
                font-size: 8px;
            }

            .resumes-label i {
                font-size: 8px;
            }

            .status-badge {
                font-size: 7px;
                gap: 4px;
            }

            .status-badge::before {
                width: 5px;
                height: 5px;
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
            <i id="announcement-megaphone" class="bi bi-megaphone-fill" style="font-size:22px; color: <?= htmlspecialchars($text_color) ?>; flex-shrink: 0;"></i>
            <div class="announcement" id="announcement" style="flex:1; --fade-bg: <?= htmlspecialchars($bg_color) ?>;">
                <div class="marquee">
                    <div class="marquee-track">
                        <span class="marquee-item" data-speed="<?= $speed ?>" style="font-size: <?= $font_size ?>px; color: <?= htmlspecialchars($text_color) ?>;"><?= htmlspecialchars($text) ?></span>
                    </div>
                </div>
            </div>
            <div id="announcement-updated" style="min-width:120px; text-align:right; font-size:12px; opacity:0.9; flex-shrink: 0;">
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

    <div class="main-board">
        <!-- No Clinic Column -->
        <div class="status-col" data-status="no medical">
            <div class="col-header">
                <div class="col-header-left">
                    <span>No Clinic Today</span>
                    <span class="col-count">(<?= count($groups['no medical']) ?>)</span>
                </div>
                <div class="current-date" id="current-date-display">
                    <?= date('M d, Y') ?>
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
                // Separate doctors with resume dates from those without
                $onLeaveOnly = [];
                $withResumeDates = [];
                
                foreach ($groups['on leave'] as $doctor) {
                    if (!empty($doctor['resume_date'])) {
                        $withResumeDates[] = $doctor;
                    } else {
                        $onLeaveOnly[] = $doctor;
                    }
                }
                
                // Display doctors on leave without resume dates first
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
                // Display doctors with resume dates
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
        
        // Also update the date display
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
    
    // Update time immediately
    updatePhilippinesTime();
    
    // Update time every second
    setInterval(updatePhilippinesTime, 1000);

    // Improved Marquee - Infinite continuous scrolling from right to left
    document.addEventListener('DOMContentLoaded', function() {
        const marqueeContainer = document.querySelector('.announcement .marquee');
        if (!marqueeContainer) return;

        const track = marqueeContainer.querySelector('.marquee-track');
        if (!track) return;

        const originalItems = track.querySelectorAll('.marquee-item');
        if (!originalItems.length) return;

        const firstItem = originalItems[0];
        
        // Get speed from data attribute
        const speed = parseFloat(firstItem.dataset.speed) || 14;
        
        // Duplicate content multiple times for seamless infinite scroll
        const originalHTML = track.innerHTML;
        track.innerHTML = originalHTML + originalHTML + originalHTML + originalHTML;

        // Apply CSS animation for infinite scroll
        track.style.animation = `scrollLeftInfinite ${speed}s linear infinite`;

        // Pause on hover
        const announcementWrap = document.getElementById('announcement-wrap');
        if (announcementWrap) {
            announcementWrap.addEventListener('mouseenter', () => {
                track.style.animationPlayState = 'paused';
            });
            announcementWrap.addEventListener('mouseleave', () => {
                track.style.animationPlayState = 'running';
            });
        }
    });

    // --- Live updates without full page reload (AJAX polling) ---
    (function() {
        const POLL_MS = 10000; // 10 seconds
        let lastData = null;

        function formatUpdatedAt(ts) {
            try {
                const d = new Date(ts);
                return d.toLocaleString('en-US', {year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit'});
            } catch(e) { return ts || ''; }
        }

        function buildDoctorCard(doc, isNoClinic) {
            const card = document.createElement('div');
            card.className = 'doctor-card';
            
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
            
            // Add resume date on the right if exists and not No Clinic
            if (!isNoClinic && doc.resume_date) {
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

            const specialization = document.createElement('div');
            specialization.className = 'doctor-specialization';
            specialization.textContent = doc.department || 'General';
            card.appendChild(specialization);

            const note = document.createElement('div'); 
            note.className = 'status-note';
            
            if (!isNoClinic) {
                if (doc.remarks) {
                    note.innerHTML = '<span>Remarks:</span> <span class="muted">' + doc.remarks + '</span>';
                    card.appendChild(note);
                }
            }
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
                    
                    const items = aw.querySelectorAll('.marquee-item');
                    items.forEach(item => {
                        item.textContent = data.announcement.text || '';
                        item.style.fontSize = (data.announcement.font_size || 32) + 'px';
                        item.dataset.speed = data.announcement.speed || 14;
                    });
                    
                    const up = document.getElementById('announcement-updated');
                    if (up) up.textContent = data.announcement.updated_at ? formatUpdatedAt(data.announcement.updated_at) : '';
                    
                    // Restart marquee animation with infinite scroll
                    const track = aw.querySelector('.marquee-track');
                    if (track) {
                        const items = aw.querySelectorAll('.marquee-item');
                        if (items.length > 0) {
                            const speed = parseFloat(items[0].dataset.speed) || 14;
                            
                            // Duplicate content for seamless loop
                            const firstTwo = Array.from(items).slice(0, 2);
                            const originalHTML = firstTwo.map(item => item.outerHTML).join('');
                            track.innerHTML = originalHTML + originalHTML;
                            
                            // Remove old animation
                            track.style.animation = 'none';
                            // Trigger reflow
                            void track.offsetHeight;
                            // Re-apply animation
                            track.style.animation = `scrollLeftInfinite ${speed}s linear infinite`;
                        }
                    }
                }
            }

            // Board update
            const board = document.querySelector('.main-board');
            if (!board) return;

            // If identical data, skip repaint
            try { if (JSON.stringify(data) === JSON.stringify(lastData)) return; } catch(e) {}
            lastData = data;

            // Group doctors (exclude "On Schedule")
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

            // Update columns
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
                }
            }

            // Update On Leave column
            const onLeaveCol = cols[1];
            if (onLeaveCol) {
                // Update header with Resumes label
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
                    
                    // Separate doctors with and without resume dates
                    const onLeaveOnly = [];
                    const withResumeDates = [];
                    
                    groups['on leave'].forEach(doc => {
                        if (doc.resume_date) {
                            withResumeDates.push(doc);
                        } else {
                            onLeaveOnly.push(doc);
                        }
                    });
                    
                    // Add doctors on leave without resume dates first
                    onLeaveOnly.forEach(doc => {
                        list.appendChild(buildDoctorCard(doc, false));
                    });
                    
                    // Add doctors with resume dates (no separate category wrapper)
                    withResumeDates.forEach(doc => {
                        list.appendChild(buildDoctorCard(doc, false));
                    });
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

        // Initial fetch and periodic polling
        fetchLoop();
        setInterval(fetchLoop, POLL_MS);
    })();
</script>
</body>
</html>