<?php
include("../config/db.php");
$result = $conn->query("SELECT * FROM doctors ORDER BY department, name");

// Group doctors by department
$doctors_by_dept = [];
while ($row = $result->fetch_assoc()) {
    $dept = $row['department'];
    $doctors_by_dept[$dept][] = $row;
}

// Fetch active announcement
$ann_res = $conn->query("SELECT * FROM announcements WHERE active=1 ORDER BY id DESC LIMIT 1");
$announcement = $ann_res ? $ann_res->fetch_assoc() : null;

// Flatten doctors
$all_doctors = [];
foreach ($doctors_by_dept as $dept => $list) {
    foreach ($list as $r) {
        $r['department'] = $dept;
        $all_doctors[] = $r;
    }
}

// AJAX endpoint
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'announcement' => $announcement,
        'doctors' => $all_doctors
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Doctor Clinic Status Board</title>
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
            display: none;
            background: linear-gradient(135deg, rgba(0,82,204,0.88) 0%, rgba(30,136,229,0.80) 100%);
            color: white;
            text-align: center;
            box-shadow: 0 4px 12px rgba(3,32,71,0.12);
            flex-shrink: 0;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        /* Announcement banner (static) */
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
            width: 100%; 
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 10px 0;
        }
        
        .announcement .announcement-text {
            font-weight: 750;
            line-height: 0.5;
        }

        .header-content {
            max-width: 100%;
            margin: 0 auto;
            position: relative;
        }

        .header-title {
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: flex-start;
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

        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
            text-align: left;
            margin-left: 20px   ;
        }

        .update-time {
            position: absolute;
            bottom: 8px;
            right: 20px;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 8px;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .update-time i {
            font-size: 24px;
            animation: spin 2s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
            grid-template-columns: 1.2fr 1.2fr;
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
            padding: 12px 25px;
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
            font-size: 23px;
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
            font-size: 18px;
            font-weight: 750;
            color: var(--primary-blue);
            background: rgba(0,82,204,0.08);
            padding: 4px 10px;
            border-radius: 6px;
        }

        .col-list { 
            overflow: hidden; 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
            padding-top: 6px; 
            min-height: 0;
            flex: 1;
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
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-blue);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 5px;
            line-height: 1.3;
        }

        .doctor-name span {
            color: var(--primary-blue);
        }

        .doctor-icon {
            font-size: 16px;
            flex-shrink: 0;
            color: var(--primary-blue);
        }

        .doctor-specialization {
            font-size: 12px;
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
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .doctor-name-left span {
            color: var(--primary-blue);
            font-size: 18px;
            font-weight: 700;
        }

        .resume-date-right {
            font-size: 16px;
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

        /* Auto-scroll animation */
        @keyframes infiniteScroll {
            0% {
                transform: translateY(0);
            }
            100% {
                transform: translateY(var(--scroll-distance, 0px));
            }
        }

        .col-list {
            transition: none !important;
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
            
            .update-time {
                position: static;
                margin-top: 10px;
                font-size: 18px;
                padding: 6px 12px;
                justify-content: center;
            }
            
            .update-time i {
                font-size: 18px;
            }
        }

        /* Tablet and iPad (768px to 1024px) */
        @media (max-width: 1024px) {
            .header-title {
                font-size: 26px;
            }

            .header-subtitle {
                font-size: 11px;
            }
            
            .update-time {
                font-size: 16px;
                padding: 6px 14px;
                bottom: 6px;
            }
            
            .update-time i {
                font-size: 24px;
            }

            .col-header {
                font-size: 16px;
                padding-bottom: 8px;
            }

            .current-date {
                font-size: 18px;
                padding: 3px 8px;
            }

            .doctor-name {
                font-size: 14px;
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

            .announcement .announcement-text {
                font-size: 18px !important;
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
                font-size: 14px;
                padding: 3px 8px;
            }

            .doctor-card {
                padding: 10px;
            }

            .doctor-name {
                font-size: 16px;
                margin-bottom: 4px;
            }

            .doctor-name span {
                font-size: 16px;
            }

            .doctor-icon {
                font-size: 15px;
            }

            .doctor-name-left {
                font-size: 16px;
            }

            .doctor-name-left span {
                font-size: 16px;
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

            .announcement .announcement-text {
                font-size: 16px !important;
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
                font-size: 12px;
                padding: 3px 6px;
            }

            .doctor-card {
                padding: 9px;
            }

            .doctor-name {
                font-size: 15px;
                margin-bottom: 3px;
            }

            .doctor-name span {
                font-size: 15px;
            }

            .doctor-icon {
                font-size: 14px;
            }

            .doctor-name-left {
                font-size: 15px;
            }

            .doctor-name-left span {
                font-size: 15px;
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
        }

        /* Mobile portrait (up to 600px) */
        @media (max-width: 600px) {
            body {
                font-size: 13px;
            }

            header {
                padding: 8px 10px;
            }

            .header-logo {
                height: 44px;
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

            .announcement .announcement-text {
                font-size: 14px !important;
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
                font-size: 10px;
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
                font-size: 14px;
                margin-bottom: 3px;
                gap: 4px;
            }

            .doctor-name span {
                font-size: 14px;
            }

            .doctor-icon {
                font-size: 13px;
            }

            .doctor-name-left {
                font-size: 14px;
            }

            .doctor-name-left span {
                font-size: 14px;
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

            .announcement .announcement-text {
                font-size: 12px !important;
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
                font-size: 13px;
                margin-bottom: 2px;
                gap: 3px;
            }

            .doctor-name span {
                font-size: 13px;
            }

            .doctor-icon {
                font-size: 12px;
            }

            .doctor-name-left {
                font-size: 13px;
            }

            .doctor-name-left span {
                font-size: 13px;
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

            .announcement .announcement-text {
                font-size: 11px !important;
            }

            .col-header {
                font-size: 11px;
            }

            .current-date {
                font-size: 7px;
                padding: 2px 4px;
            }

            .doctor-name {
                font-size: 12px;
            }

            .doctor-name span {
                font-size: 12px;
            }

            .doctor-name-left {
                font-size: 12px;
            }

            .doctor-name-left span {
                font-size: 12px;
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
    <h3 class="m-0">New Sinai MDI Hospital</h3>
    <small>Doctor Clinic Status Board</small>
</header>

<div class="container">
<?php
$groups = ['no medical' => [], 'on leave' => []];
foreach ($all_doctors as $d) {
    $s = strtolower($d['status'] ?? '');
    if ($s === '' || str_contains($s, 'no clinic')) {
        $groups['no medical'][] = $d;
    } elseif (str_contains($s, 'leave')) {
        $groups['on leave'][] = $d;
    }
}
?>

<div class="main-board">

<!-- NO CLINIC -->
<div class="status-col">
    <div class="col-header">
        <span>No Clinic Today</span>
        <span>(<?= count($groups['no medical']) ?>)</span>
    </div>
    <div class="col-list auto-scroll">
        <?php foreach ($groups['no medical'] as $d): ?>
        <div class="doctor-card">
            <div class="doctor-name">
                <i class="bi bi-person-fill"></i>
                <?= htmlspecialchars($d['name']) ?>
            </div>
            <div class="doctor-specialization">
                <?= htmlspecialchars($d['department']) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ON LEAVE -->
<div class="status-col">
    <div class="col-header">
        <span>On Leave</span>
        <span>(<?= count($groups['on leave']) ?>)</span>
    </div>
    <div class="col-list auto-scroll">
        <?php foreach ($groups['on leave'] as $d): ?>
        <div class="doctor-card">
            <div class="doctor-name">
                <i class="bi bi-person-fill"></i>
                <?= htmlspecialchars($d['name']) ?>
            </div>
            <div class="doctor-specialization">
                <?= htmlspecialchars($d['department']) ?>
            </div>
            <?php if (!empty($d['remarks'])): ?>
            <div class="status-note">
                Remarks: <?= htmlspecialchars($d['remarks']) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div>
</div>

<script>
/* ===========================
   AUTO SCROLL (TV FRIENDLY)
   =========================== */
function enableAutoScroll(el, speed = 0.4, pause = 2000) {
    let dir = 1;
    let paused = false;

    function animate() {
        if (!paused) {
            el.scrollTop += dir * speed;

            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 1) {
                paused = true;
                setTimeout(() => { dir = -1; paused = false; }, pause);
            }

            if (el.scrollTop <= 0) {
                paused = true;
                setTimeout(() => { dir = 1; paused = false; }, pause);
            }
        }
        requestAnimationFrame(animate);
    }

    el.addEventListener('mouseenter', () => paused = true);
    el.addEventListener('mouseleave', () => paused = false);

    animate();
}

document.querySelectorAll('.auto-scroll').forEach(list => {
    enableAutoScroll(list, 0.35, 2500);
});
</script>

</body>
</html>
