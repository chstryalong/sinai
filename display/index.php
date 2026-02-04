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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Sinai MDI Hospital - Doctor Availability</title>

    <!-- Auto refresh every 10 seconds -->
    <meta http-equiv="refresh" content="10">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary-blue: #0052CC;
            --secondary-blue: #1e88e5;
            --accent-yellow: #ffc107;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e8f0f8 100%);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
            color: white;
            padding: 15px 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            flex-shrink: 0;
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

        .header-subtitle {
            font-size: 12px;
            opacity: 0.9;
        }

        .container {
            flex: 1;
            padding: 15px;
            overflow: hidden;
            display: flex;
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
            background: white;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border-top: 3px solid var(--primary-blue);
            display: flex;
            flex-direction: column;
            overflow: hidden;
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

        .doctor-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 6px;
            padding: 10px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            border-left: 3px solid var(--accent-yellow);
            transition: all 0.3s ease;
            flex-shrink: 0;
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

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 10px;
            width: fit-content;
        }

        .status-available {
            background-color: #d4edda;
            color: #155724;
        }

        .status-unavailable {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-onleave {
            background-color: #f8d7da;
            color: #721c24;
        }

        .resume-info {
            font-size: 9px;
            color: #666;
            margin-top: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .resume-info i {
            color: var(--secondary-blue);
            margin-right: 2px;
        }

        footer {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--secondary-blue) 100%);
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 12px;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
            flex-shrink: 0;
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
            <i class="bi bi-hospital"></i>
            New Sinai MDI Hospital
        </div>
        <div class="header-subtitle">Doctor Availability Board</div>
    </div>
</header>

<div class="container">
    <div class="departments-wrapper">
        <?php foreach ($doctors_by_dept as $dept => $doctors): ?>
            <div class="department-section">
                <div class="dept-header">
                    <div class="dept-icon"><i class="bi bi-building"></i></div>
                    <div class="dept-name"><?= htmlspecialchars($dept) ?></div>
                </div>

                <div class="doctor-grid">
                    <?php foreach ($doctors as $doctor): ?>
                        <div class="doctor-card">
                            <div class="doctor-name">
                                <i class="doctor-icon bi bi-person-fill"></i>
                                <?= htmlspecialchars($doctor['name']) ?>
                            </div>

                            <?php
                            $status = $doctor['status'];
                            if ($status == 'Available') {
                                $badge_class = 'status-available';
                                $icon = 'bi-check-circle-fill';
                            } elseif ($status == 'Not Available') {
                                $badge_class = 'status-unavailable';
                                $icon = 'bi-dash-circle-fill';
                            } else {
                                $badge_class = 'status-onleave';
                                $icon = 'bi-clock-history';
                            }
                            ?>

                            <span class="status-badge <?= $badge_class ?>">
                                <i class="bi <?= $icon ?>"></i> <?= htmlspecialchars($status) ?>
                            </span>

                            <?php if ($status == 'On Leave' && $doctor['resume_date']): ?>
                                <div class="resume-info">
                                    <i class="bi bi-calendar-event"></i>
                                    Resumes: <?= date("M d, Y", strtotime($doctor['resume_date'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
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
</script>
