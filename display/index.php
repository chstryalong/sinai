<?php
// ============================================================
//  Doctor Availability Display — New Sinai MDI Hospital
//  TV/kiosk display page. Polls for live updates via AJAX.
// ============================================================

require_once '../config/db.php';

// ============================================================
//  DATA LOADING
// ============================================================

/**
 * Load all doctors grouped into 'no_clinic' and 'on_leave' buckets.
 * Returns ['no_clinic' => [...], 'on_leave' => [...]]
 */
function loadDoctorGroups(mysqli $conn): array
{
    $result = $conn->query("SELECT * FROM doctors ORDER BY department, name");

    $groups = ['no_clinic' => [], 'on_leave' => []];

    while ($row = $result->fetch_assoc()) {
        $status = strtolower(trim($row['status'] ?? ''));

        if (str_contains($status, 'leave')) {
            $groups['on_leave'][] = $row;
        } elseif (str_contains($status, 'schedule') || str_contains($status, 'available')) {
            // Available doctors are not shown on the display board
        } else {
            // Covers: no medical, no clinic, not available, empty, and any unknown status
            $groups['no_clinic'][] = $row;
        }
    }

    return $groups;
}

/** Load display scroll settings (or return safe defaults). */
function loadDisplaySettings(mysqli $conn): array
{
    $row = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1")->fetch_assoc();

    return $row ?: [
        'scroll_speed'    => 25,
        'pause_at_top'    => 3000,
        'pause_at_bottom' => 3000,
    ];
}

// ============================================================
//  AJAX ENDPOINT  (?ajax=1)
// ============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $allDoctors = [];
    $result = $conn->query("SELECT * FROM doctors ORDER BY department, name");
    while ($row = $result->fetch_assoc()) {
        $allDoctors[] = $row;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'doctors'          => $allDoctors,
        'display_settings' => loadDisplaySettings($conn),
    ]);
    exit;
}

// ============================================================
//  INITIAL PAGE RENDER
// ============================================================

$groups          = loadDoctorGroups($conn);
$displaySettings = loadDisplaySettings($conn);

// Separate on-leave doctors: those without a resume date shown first
$onLeaveNoDate   = array_values(array_filter($groups['on_leave'], fn($d) => empty($d['resume_date'])));
$onLeaveWithDate = array_values(array_filter($groups['on_leave'], fn($d) => !empty($d['resume_date'])));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Sinai MDI Hospital – Doctor Availability</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* ============================================================
           Base / reset
        ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #eef6ff;
            display: flex;
            flex-direction: column;
            color: #052744;
        }

        /* Full-screen watermark background */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(3,32,71,0.28), rgba(3,32,71,0.28)),
                url('assets/logo.png');
            background-repeat: no-repeat;
            background-position: center;
            background-size: cover;
            background-blend-mode: overlay;
            pointer-events: none;
            z-index: 0;
        }

        /* ============================================================
           Layout
        ============================================================ */
        .board-wrap {
            position: relative;
            z-index: 1;
            flex: 1;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2vw;
            padding: 2vh 2vw;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }

        /* ── Column panel ── */
        .status-col {
            display: flex;
            flex-direction: column;
            background: rgba(255,255,255,0.88);
            border-radius: 1.2vw;
            box-shadow: 0 8px 40px rgba(3,32,71,0.18);
            border-top: 0.5vh solid rgba(0,82,204,0.25);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            overflow: hidden;
            min-height: 0;
            padding: 2.5vh 2.5vw;
            gap: 1.5vh;
        }

        /* ── Column header ── */
        .col-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 1.5vh;
            border-bottom: 0.4vh solid #ffc107;
            flex-shrink: 0;
            gap: 1vw;
        }

        .col-header-left {
            display: flex;
            align-items: center;
            gap: 0.8vw;
        }

        .col-title { font-size: 2.6vw; font-weight: 800; color: #052744; line-height: 1; }
        .col-count { font-size: 2vw;   font-weight: 700; color: #0052CC; opacity: 0.8; }

        .current-date {
            font-size: 1.8vw;
            font-weight: 700;
            color: #0052CC;
            background: rgba(0,82,204,0.1);
            padding: 0.5vh 1.2vw;
            border-radius: 0.6vw;
            white-space: nowrap;
        }

        .resumes-label {
            font-size: 1.8vw;
            font-weight: 700;
            color: #0052CC;
            display: flex;
            align-items: center;
            gap: 0.5vw;
        }

        /* ── Scrollable list ── */
        .col-list {
            flex: 1;
            overflow: hidden;
            min-height: 0;
            position: relative;
            /* Fade edges for a clean scroll illusion */
            mask-image: linear-gradient(to bottom, transparent 0%, black 3vh, black calc(100% - 3vh), transparent 100%);
            -webkit-mask-image: linear-gradient(to bottom, transparent 0%, black 3vh, black calc(100% - 3vh), transparent 100%);
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .col-list::-webkit-scrollbar { display: none; }

        .col-list-inner {
            display: flex;
            flex-direction: column;
            gap: 1.2vh;
            will-change: transform;
            padding: 1.5vh 0;
        }

        /* ── Doctor card ── */
        .doctor-card {
            background: rgba(255,255,255,0.95);
            border-radius: 0.8vw;
            padding: 1.6vh 1.6vw;
            box-shadow: 0 0.3vh 1.2vh rgba(3,32,71,0.08);
            border-left: 0.4vw solid rgba(255,193,7,0.9);
            flex-shrink: 0;
        }

        .doctor-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1vw;
            margin-bottom: 0.6vh;
        }

        /* Shared by both single-name and name-with-date layouts */
        .doctor-name,
        .doctor-name-left {
            display: flex;
            align-items: center;
            gap: 0.6vw;
            font-size: 2vw;
            font-weight: 700;
            color: #0052CC;
            line-height: 1.2;
            flex: 1;
            min-width: 0;
        }

        .doctor-icon { font-size: 1.8vw; flex-shrink: 0; color: #0052CC; }

        .doctor-department {
            font-size: 1.5vw;
            font-weight: 600;
            color: #444;
            padding-left: 2.4vw;
        }

        .resume-date {
            font-size: 1.5vw;
            font-weight: 700;
            color: #0052CC;
            background: rgba(0,82,204,0.1);
            padding: 0.4vh 1vw;
            border-radius: 0.5vw;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .tentative-badge {
            font-size: 1.1vw;
            font-weight: 700;
            color: #856404;
            background: rgba(255,193,7,0.2);
            border: 0.15vw dashed rgba(255,193,7,0.7);
            padding: 0.3vh 0.7vw;
            border-radius: 0.4vw;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .remarks-line {
            font-size: 1.4vw;
            color: #555;
            margin-top: 0.5vh;
            padding-left: 2.4vw;
        }
        .remarks-line strong { color: #333; font-weight: 700; }

        /* ============================================================
           Responsive breakpoints
           Base is vw/vh (TV-first). Each breakpoint scales down.
        ============================================================ */

        @media (max-width: 1440px) {
            .board-wrap        { gap: 1.6vw; padding: 1.8vh 1.8vw; }
            .status-col        { padding: 2vh 2vw; gap: 1.2vh; }
            .col-title         { font-size: 2.8vw; }
            .col-count         { font-size: 2.1vw; }
            .current-date      { font-size: 1.9vw; padding: 0.4vh 1vw; }
            .resumes-label     { font-size: 1.9vw; }
            .doctor-name,
            .doctor-name-left  { font-size: 2.1vw; }
            .doctor-icon       { font-size: 1.9vw; }
            .doctor-department { font-size: 1.6vw; padding-left: 2.5vw; }
            .resume-date       { font-size: 1.6vw; padding: 0.4vh 0.9vw; }
            .tentative-badge   { font-size: 1.15vw; padding: 0.3vh 0.6vw; }
            .remarks-line      { font-size: 1.5vw; padding-left: 2.5vw; }
            .doctor-card       { padding: 1.4vh 1.4vw; }
        }

        @media (max-width: 1024px) {
            .board-wrap        { gap: 2vw; padding: 1.5vh 1.5vw; }
            .status-col        { padding: 1.8vh 2vw; border-radius: 1.4vw; }
            .col-title         { font-size: 3.2vw; }
            .col-count         { font-size: 2.4vw; }
            .current-date      { font-size: 2.1vw; }
            .resumes-label     { font-size: 2.1vw; }
            .doctor-name,
            .doctor-name-left  { font-size: 2.4vw; }
            .doctor-icon       { font-size: 2.2vw; }
            .doctor-department { font-size: 1.9vw; padding-left: 2.9vw; }
            .resume-date       { font-size: 1.8vw; }
            .tentative-badge   { font-size: 1.3vw; }
            .remarks-line      { font-size: 1.7vw; padding-left: 2.9vw; }
            .doctor-card       { padding: 1.3vh 1.6vw; border-radius: 1vw; }
        }

        /* Tablet portrait — switch to single column */
        @media (max-width: 900px) {
            .board-wrap        { grid-template-columns: 1fr; gap: 2vh; padding: 1.5vh 3vw; overflow-y: auto; }
            .status-col        { padding: 2vh 3vw; min-height: 45vh; }
            .col-title         { font-size: 5vw; }
            .col-count         { font-size: 3.8vw; }
            .current-date      { font-size: 3.2vw; padding: 0.5vh 2vw; }
            .resumes-label     { font-size: 3.2vw; }
            .doctor-name,
            .doctor-name-left  { font-size: 4vw; }
            .doctor-icon       { font-size: 3.6vw; }
            .doctor-department { font-size: 3vw; padding-left: 5vw; }
            .resume-date       { font-size: 3vw; padding: 0.4vh 1.5vw; }
            .tentative-badge   { font-size: 2.2vw; padding: 0.3vh 1.5vw; }
            .remarks-line      { font-size: 2.8vw; padding-left: 5vw; }
            .doctor-card       { padding: 1.5vh 3vw; border-radius: 2vw; border-left-width: 1vw; }
            .col-list          { min-height: 30vh; }
        }

        @media (max-width: 768px) {
            .board-wrap        { padding: 1.5vh 2.5vw; gap: 1.5vh; }
            .status-col        { padding: 1.8vh 3vw; }
            .col-title         { font-size: 5.5vw; }
            .col-count         { font-size: 4.2vw; }
            .current-date      { font-size: 3.5vw; }
            .resumes-label     { font-size: 3.5vw; }
            .doctor-name,
            .doctor-name-left  { font-size: 4.5vw; }
            .doctor-icon       { font-size: 4vw; }
            .doctor-department { font-size: 3.4vw; padding-left: 5.5vw; }
            .resume-date       { font-size: 3.2vw; }
            .tentative-badge   { font-size: 2.5vw; }
            .remarks-line      { font-size: 3vw; padding-left: 5.5vw; }
            .doctor-card       { padding: 1.5vh 3.5vw; }
        }

        @media (max-width: 480px) {
            .board-wrap        { padding: 1.2vh 2vw; gap: 1.2vh; }
            .status-col        { padding: 1.5vh 3vw; border-radius: 3vw; }
            .col-title         { font-size: 6.5vw; }
            .col-count         { font-size: 5vw; }
            .current-date      { font-size: 4vw; padding: 0.5vh 2.5vw; border-radius: 1.5vw; }
            .resumes-label     { font-size: 4vw; }
            .doctor-name,
            .doctor-name-left  { font-size: 5.5vw; gap: 1.5vw; }
            .doctor-icon       { font-size: 5vw; }
            .doctor-department { font-size: 4vw; padding-left: 7vw; }
            .resume-date       { font-size: 3.8vw; padding: 0.4vh 2vw; border-radius: 1.5vw; }
            .tentative-badge   { font-size: 3vw; padding: 0.3vh 2vw; border-radius: 1.2vw; }
            .remarks-line      { font-size: 3.6vw; padding-left: 7vw; }
            .doctor-card       { padding: 1.5vh 4vw; border-radius: 3vw; border-left-width: 1.2vw; }
            .col-header        { padding-bottom: 1.2vh; }
        }

        @media (max-width: 360px) {
            .col-title         { font-size: 7vw; }
            .col-count         { font-size: 5.5vw; }
            .current-date      { font-size: 4.5vw; }
            .doctor-name,
            .doctor-name-left  { font-size: 6vw; }
            .doctor-department { font-size: 4.5vw; }
            .resume-date       { font-size: 4.2vw; }
            .remarks-line      { font-size: 4vw; }
        }
    </style>
</head>
<body>

<div class="board-wrap">

    <!-- ── No Clinic Today ── -->
    <div class="status-col" data-status="no_clinic">
        <div class="col-header">
            <div class="col-header-left">
                <span class="col-title">No Clinic Today</span>
                <span class="col-count">(<?= count($groups['no_clinic']) ?>)</span>
            </div>
            <div class="current-date" id="current-date-display"></div>
        </div>
        <div class="col-list">
            <div class="col-list-inner">
                <?php foreach ($groups['no_clinic'] as $doctor): ?>
                <div class="doctor-card">
                    <div class="doctor-name">
                        <i class="doctor-icon bi bi-person-fill"></i>
                        <span><?= htmlspecialchars($doctor['name']) ?></span>
                    </div>
                    <div class="doctor-department">
                        <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── On Leave ── -->
    <div class="status-col" data-status="on_leave">
        <div class="col-header">
            <div class="col-header-left">
                <span class="col-title">On Leave</span>
                <span class="col-count">(<?= count($groups['on_leave']) ?>)</span>
            </div>
            <div class="resumes-label">
                <i class="bi bi-calendar-check"></i>
                <span>Resumes</span>
            </div>
        </div>
        <div class="col-list">
            <div class="col-list-inner">

                <?php
                // Show doctors without a resume date first, then those with one
                foreach (array_merge($onLeaveNoDate, $onLeaveWithDate) as $doctor):
                    $remarks = trim($doctor['remarks'] ?? '');
                ?>
                <div class="doctor-card">
                    <div class="doctor-name-row">
                        <div class="doctor-name-left">
                            <i class="doctor-icon bi bi-person-fill"></i>
                            <span><?= htmlspecialchars($doctor['name']) ?></span>
                        </div>
                        <?php if (!empty($doctor['resume_date'])): ?>
                        <div class="resume-date">
                            <?= date('M d, Y', strtotime($doctor['resume_date'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div class="doctor-department">
                            <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                        </div>
                        <?php if (!empty($doctor['resume_date']) && ($doctor['is_tentative'] ?? 0) == 1): ?>
                        <div class="tentative-badge">
                            <i class="bi bi-calendar-question"></i> TENTATIVE
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($remarks !== ''): ?>
                    <div class="remarks-line">
                        <strong>Remarks:</strong> <?= htmlspecialchars($remarks) ?>
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
// ============================================================
//  Date display
// ============================================================

function updateDateDisplay() {
    const el = document.getElementById('current-date-display');
    if (!el) return;
    el.textContent = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        year: 'numeric', month: 'short', day: '2-digit',
    }).format(new Date());
}

updateDateDisplay();
setInterval(updateDateDisplay, 60_000);

// ============================================================
//  Scroll engine
// ============================================================
(function () {
    const POLL_INTERVAL_MS = 3000;

    // Seeded from PHP so the first render matches the saved settings
    const settings = {
        speed   : <?= (int) ($displaySettings['scroll_speed']    ?? 25) ?>,
        pauseTop: <?= (int) ($displaySettings['pause_at_top']    ?? 3000) ?>,
        pauseBot: <?= (int) ($displaySettings['pause_at_bottom'] ?? 3000) ?>,
    };

    // Tracks per-column animation state (keyed by the .col-list element)
    const scrollStates = new WeakMap();

    // Last-seen stringified doctor data — lets us skip DOM rebuilds when nothing changed
    let lastDoctorsHash   = '';
    let lastSettingsHash  = JSON.stringify(settings);

    // ── Scroll animation ──────────────────────────────────────────────────

    function startScroll(colList, forceReset = false) {
        const inner = colList.querySelector('.col-list-inner');
        if (!inner || inner.children.length === 0) return;

        // Compute how many pixels are hidden below the viewport
        let totalContentHeight = 0;
        for (const card of inner.children) {
            totalContentHeight += card.offsetHeight + Math.round(window.innerHeight * 0.012);
        }
        totalContentHeight += Math.round(window.innerHeight * 0.03); // bottom padding
        const maxScroll = Math.max(totalContentHeight - colList.offsetHeight, 0);

        const existing = scrollStates.get(colList);

        if (maxScroll <= 0) {
            // Content fits — cancel any running animation and reset position
            if (existing?.rafId) cancelAnimationFrame(existing.rafId);
            inner.style.transform = 'translateY(0)';
            scrollStates.delete(colList);
            return;
        }

        if (!forceReset && existing) {
            // Live-update maxScroll without interrupting the current scroll
            existing.maxScroll = maxScroll;
            return;
        }

        if (existing?.rafId) cancelAnimationFrame(existing.rafId);

        const state = {
            pos      : 0,
            dir      : 1,          // 1 = scrolling down, -1 = scrolling up
            pausing  : true,
            pauseEnd : performance.now() + settings.pauseTop,
            lastTime : null,
            rafId    : null,
            maxScroll,
        };
        scrollStates.set(colList, state);
        inner.style.transform = 'translateY(0)';

        function tick(now) {
            const s = scrollStates.get(colList);
            if (!s) return;

            if (s.pausing) {
                if (now >= s.pauseEnd) {
                    s.pausing  = false;
                    s.lastTime = now;
                }
                s.rafId = requestAnimationFrame(tick);
                return;
            }

            // Delta-time based movement — frame-rate independent
            const elapsed = s.lastTime ? Math.min(now - s.lastTime, 100) : 0;
            s.lastTime = now;
            const step = (settings.speed * elapsed) / 1000;

            if (s.dir === 1) {
                s.pos = Math.min(s.pos + step, s.maxScroll);
                if (s.pos >= s.maxScroll) {
                    s.pos     = s.maxScroll;
                    s.dir     = -1;
                    s.pausing = true;
                    s.pauseEnd = now + settings.pauseBot;
                }
            } else {
                s.pos = Math.max(s.pos - step, 0);
                if (s.pos <= 0) {
                    s.pos     = 0;
                    s.dir     = 1;
                    s.pausing = true;
                    s.pauseEnd = now + settings.pauseTop;
                }
            }

            inner.style.transform = `translateY(-${s.pos}px)`;
            s.rafId = requestAnimationFrame(tick);
        }

        state.rafId = requestAnimationFrame(tick);
    }

    // ── DOM helpers ───────────────────────────────────────────────────────

    function escH(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtDate(str) {
        try {
            const [y, m, d] = str.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: '2-digit',
            });
        } catch {
            return str;
        }
    }

    function buildNoClinicCard(doctor) {
        const card = document.createElement('div');
        card.className = 'doctor-card';
        card.innerHTML = `
            <div class="doctor-name">
                <i class="doctor-icon bi bi-person-fill"></i>
                <span>${escH(doctor.name)}</span>
            </div>
            <div class="doctor-department">${escH(doctor.department ?? 'General')}</div>
        `;
        return card;
    }

    function buildOnLeaveCard(doctor) {
        const remarks    = (doctor.remarks ?? '').trim();
        const dateHtml   = doctor.resume_date
            ? `<div class="resume-date">${fmtDate(doctor.resume_date)}</div>`
            : '';
        const tentHtml   = (doctor.resume_date && doctor.is_tentative == 1)
            ? `<div class="tentative-badge"><i class="bi bi-calendar-question"></i> TENTATIVE</div>`
            : '';
        const remarksHtml = remarks
            ? `<div class="remarks-line"><strong>Remarks:</strong> ${escH(remarks)}</div>`
            : '';

        const card = document.createElement('div');
        card.className = 'doctor-card';
        card.innerHTML = `
            <div class="doctor-name-row">
                <div class="doctor-name-left">
                    <i class="doctor-icon bi bi-person-fill"></i>
                    <span>${escH(doctor.name)}</span>
                </div>
                ${dateHtml}
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div class="doctor-department">${escH(doctor.department ?? 'General')}</div>
                ${tentHtml}
            </div>
            ${remarksHtml}
        `;
        return card;
    }

    // ── Board update ──────────────────────────────────────────────────────

    function applySettingsUpdate(ds) {
        const hash = JSON.stringify(ds);
        if (hash === lastSettingsHash) return;
        lastSettingsHash = hash;

        settings.speed    = parseInt(ds.scroll_speed)    || 25;
        settings.pauseTop = parseInt(ds.pause_at_top)    || 3000;
        settings.pauseBot = parseInt(ds.pause_at_bottom) || 3000;

        // Clamp any active pause to the new duration without restarting the scroll
        document.querySelectorAll('.col-list').forEach(colList => {
            const s = scrollStates.get(colList);
            if (!s?.pausing) return;
            const maxPause = s.dir === -1 ? settings.pauseBot : settings.pauseTop;
            if ((s.pauseEnd - performance.now()) > maxPause) {
                s.pauseEnd = performance.now() + maxPause;
            }
        });
    }

    function applyDoctorUpdate(doctors) {
        const hash = JSON.stringify(doctors);
        if (hash === lastDoctorsHash) return; // nothing changed — skip DOM work
        lastDoctorsHash = hash;

        const noClinic = [];
        const onLeave  = [];

        doctors.forEach(d => {
            const status = (d.status ?? '').toLowerCase();
            if (status.includes('leave'))                               onLeave.push(d);
            else if (status.includes('schedule') || status.includes('available')) { /* skip */ }
            else                                                        noClinic.push(d);
        });

        const cols = document.querySelectorAll('.status-col');

        // No Clinic column (index 0)
        const noClinicCol = cols[0];
        if (noClinicCol) {
            noClinicCol.querySelector('.col-count').textContent = `(${noClinic.length})`;
            const listEl  = noClinicCol.querySelector('.col-list');
            const innerEl = listEl?.querySelector('.col-list-inner');
            if (innerEl) {
                innerEl.innerHTML = '';
                noClinic.forEach(d => innerEl.appendChild(buildNoClinicCard(d)));
                startScroll(listEl, true);
            }
        }

        // On Leave column (index 1)
        const onLeaveCol = cols[1];
        if (onLeaveCol) {
            onLeaveCol.querySelector('.col-count').textContent = `(${onLeave.length})`;
            const listEl  = onLeaveCol.querySelector('.col-list');
            const innerEl = listEl?.querySelector('.col-list-inner');
            if (innerEl) {
                innerEl.innerHTML = '';
                // No-date entries first, then entries with a resume date
                const noDate   = onLeave.filter(d => !d.resume_date);
                const withDate = onLeave.filter(d =>  d.resume_date);
                [...noDate, ...withDate].forEach(d => innerEl.appendChild(buildOnLeaveCard(d)));
                startScroll(listEl, true);
            }
        }
    }

    // ── Polling ───────────────────────────────────────────────────────────

    async function pollServer() {
        try {
            const res  = await fetch(window.location.pathname + '?ajax=1');
            if (!res.ok) return;
            const data = await res.json();
            if (data.display_settings) applySettingsUpdate(data.display_settings);
            if (data.doctors)          applyDoctorUpdate(data.doctors);
        } catch {
            // Network blip — fail silently, will retry on next interval
        }
    }

    // ── Init ──────────────────────────────────────────────────────────────

    window.addEventListener('load', () => {
        document.querySelectorAll('.col-list').forEach(el => startScroll(el, true));
    });

    // First poll is slightly delayed so the initial render has settled
    setTimeout(pollServer, 1500);
    setInterval(pollServer, POLL_INTERVAL_MS);

})();
</script>
</body>
</html>