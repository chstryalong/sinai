<?php
include("../config/db.php");
$result = $conn->query("SELECT * FROM doctors ORDER BY department, name");

$doctors_by_dept = [];
while ($row = $result->fetch_assoc()) {
    $dept = $row['department'];
    if (!isset($doctors_by_dept[$dept])) $doctors_by_dept[$dept] = [];
    $doctors_by_dept[$dept][] = $row;
}

$ann_res = $conn->query("SELECT * FROM announcements WHERE active=1 ORDER BY id DESC LIMIT 1");
$announcement = $ann_res ? $ann_res->fetch_assoc() : null;

$display_settings_res = $conn->query("SELECT * FROM display_settings ORDER BY id LIMIT 1");
$display_settings = $display_settings_res ? $display_settings_res->fetch_assoc() : ['scroll_speed' => 25, 'pause_at_top' => 3000, 'pause_at_bottom' => 3000];

$all_doctors = [];
foreach ($doctors_by_dept as $dname => $list) {
    foreach ($list as $r) {
        $r['department'] = $dname;
        $all_doctors[] = $r;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'announcement'     => $announcement,
        'doctors'          => $all_doctors,
        'display_settings' => $display_settings
    ]);
    exit;
}

$groups = ['no medical' => [], 'on leave' => []];
foreach ($all_doctors as $d) {
    $low = strtolower(trim($d['status'] ?? ''));
    if ($low === '' || strpos($low, 'no medical') !== false || strpos($low, 'no clinic') !== false) {
        $groups['no medical'][] = $d;
    } elseif (strpos($low, 'leave') !== false) {
        $groups['on leave'][] = $d;
    } elseif (strpos($low, 'schedule') !== false || strpos($low, 'available') !== false) {
        // skip
    } else {
        $groups['no medical'][] = $d;
    }
}

$leave_icons = [
    'On Vacation' => ['icon' => 'bi-airplane-fill',    'color' => '#1e88e5'],
    'Personal'    => ['icon' => 'bi-person-heart',     'color' => '#8e44ad'],
    'Sick Leave'  => ['icon' => 'bi-thermometer-half', 'color' => '#dc3545'],
];

$onLeaveOnly     = [];
$withResumeDates = [];
foreach ($groups['on leave'] as $doctor) {
    if (!empty($doctor['resume_date'])) $withResumeDates[] = $doctor;
    else $onLeaveOnly[] = $doctor;
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

    /* ── Full-screen background ── */
    body::before {
        content: "";
        position: fixed;
        inset: 0;
        background-image:
            linear-gradient(rgba(3,32,71,0.28), rgba(3,32,71,0.28)),
            url('assets/logo.png');
        background-repeat: no-repeat, no-repeat;
        background-position: center center, center center;
        background-size: cover, cover;
        background-blend-mode: overlay;
        pointer-events: none;
        z-index: 0;
    }

    /* ── Main wrapper ── */
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

    /* ── Each column ── */
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

    .col-title {
        font-size: 2.6vw;
        font-weight: 800;
        color: #052744;
        line-height: 1;
    }

    .col-count {
        font-size: 2vw;
        font-weight: 700;
        color: #0052CC;
        opacity: 0.8;
    }

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
        mask-image: linear-gradient(to bottom, transparent 0%, black 3vh, black calc(100% - 3vh), transparent 100%);
        -webkit-mask-image: linear-gradient(to bottom, transparent 0%, black 3vh, black calc(100% - 3vh), transparent 100%);
    }

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

    .doctor-name-left,
    .doctor-name {
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

    .doctor-icon {
        font-size: 1.8vw;
        flex-shrink: 0;
        color: #0052CC;
    }

    .doctor-specialization {
        font-size: 1.5vw;
        font-weight: 600;
        color: #444;
        padding-left: 2.4vw;
        margin-bottom: 0;
    }

    .resume-date-right {
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

    .remarks-line strong {
        color: #333;
        font-weight: 700;
    }

    /* ── Hide scrollbar ── */
    .col-list::-webkit-scrollbar { display: none; }
    .col-list { -ms-overflow-style: none; scrollbar-width: none; }

    /* ══════════════════════════════════════════════════════
       RESPONSIVE BREAKPOINTS
       Base is vw/vh (TV-first). Breakpoints override where
       vw units get too small on phone/tablet screens.
    ══════════════════════════════════════════════════════ */

    /* Large desktop / big TV 1920px+ — vw units already handle this perfectly */

    /* Standard 1080p monitor / small TV (≤ 1440px) */
    @media (max-width: 1440px) {
        .board-wrap       { gap: 1.6vw; padding: 1.8vh 1.8vw; }
        .status-col       { padding: 2vh 2vw; gap: 1.2vh; }
        .col-title        { font-size: 2.8vw; }
        .col-count        { font-size: 2.1vw; }
        .current-date     { font-size: 1.9vw; padding: 0.4vh 1vw; }
        .resumes-label    { font-size: 1.9vw; }
        .doctor-name,
        .doctor-name-left { font-size: 2.1vw; }
        .doctor-icon      { font-size: 1.9vw; }
        .doctor-specialization { font-size: 1.6vw; padding-left: 2.5vw; }
        .resume-date-right{ font-size: 1.6vw; padding: 0.4vh 0.9vw; }
        .tentative-badge  { font-size: 1.15vw; padding: 0.3vh 0.6vw; }
        .remarks-line     { font-size: 1.5vw; padding-left: 2.5vw; }
        .doctor-card      { padding: 1.4vh 1.4vw; }
    }

    /* Tablet landscape / small monitor (≤ 1024px) */
    @media (max-width: 1024px) {
        .board-wrap       { gap: 2vw; padding: 1.5vh 1.5vw; }
        .status-col       { padding: 1.8vh 2vw; border-radius: 1.4vw; }
        .col-title        { font-size: 3.2vw; }
        .col-count        { font-size: 2.4vw; }
        .current-date     { font-size: 2.1vw; }
        .resumes-label    { font-size: 2.1vw; }
        .doctor-name,
        .doctor-name-left { font-size: 2.4vw; }
        .doctor-icon      { font-size: 2.2vw; }
        .doctor-specialization { font-size: 1.9vw; padding-left: 2.9vw; }
        .resume-date-right{ font-size: 1.8vw; }
        .tentative-badge  { font-size: 1.3vw; }
        .remarks-line     { font-size: 1.7vw; padding-left: 2.9vw; }
        .doctor-card      { padding: 1.3vh 1.6vw; border-radius: 1vw; }
    }

    /* Tablet portrait (≤ 900px) — switch to single column */
    @media (max-width: 900px) {
        .board-wrap {
            grid-template-columns: 1fr;
            gap: 2vh;
            padding: 1.5vh 3vw;
            overflow-y: auto;
        }
        .status-col       { padding: 2vh 3vw; min-height: 45vh; }
        .col-title        { font-size: 5vw; }
        .col-count        { font-size: 3.8vw; }
        .current-date     { font-size: 3.2vw; padding: 0.5vh 2vw; }
        .resumes-label    { font-size: 3.2vw; }
        .doctor-name,
        .doctor-name-left { font-size: 4vw; }
        .doctor-icon      { font-size: 3.6vw; }
        .doctor-specialization { font-size: 3vw; padding-left: 5vw; }
        .resume-date-right{ font-size: 3vw; padding: 0.4vh 1.5vw; }
        .tentative-badge  { font-size: 2.2vw; padding: 0.3vh 1.5vw; }
        .remarks-line     { font-size: 2.8vw; padding-left: 5vw; }
        .doctor-card      { padding: 1.5vh 3vw; border-radius: 2vw; border-left-width: 1vw; }
        .col-list         { min-height: 30vh; }
    }

    /* Large phone landscape / small tablet (≤ 768px) */
    @media (max-width: 768px) {
        .board-wrap       { padding: 1.5vh 2.5vw; gap: 1.5vh; }
        .status-col       { padding: 1.8vh 3vw; }
        .col-title        { font-size: 5.5vw; }
        .col-count        { font-size: 4.2vw; }
        .current-date     { font-size: 3.5vw; }
        .resumes-label    { font-size: 3.5vw; }
        .doctor-name,
        .doctor-name-left { font-size: 4.5vw; }
        .doctor-icon      { font-size: 4vw; }
        .doctor-specialization { font-size: 3.4vw; padding-left: 5.5vw; }
        .resume-date-right{ font-size: 3.2vw; }
        .tentative-badge  { font-size: 2.5vw; }
        .remarks-line     { font-size: 3vw; padding-left: 5.5vw; }
        .doctor-card      { padding: 1.5vh 3.5vw; }
    }

    /* Phone portrait (≤ 480px) */
    @media (max-width: 480px) {
        .board-wrap       { padding: 1.2vh 2vw; gap: 1.2vh; }
        .status-col       { padding: 1.5vh 3vw; border-radius: 3vw; }
        .col-title        { font-size: 6.5vw; }
        .col-count        { font-size: 5vw; }
        .current-date     { font-size: 4vw; padding: 0.5vh 2.5vw; border-radius: 1.5vw; }
        .resumes-label    { font-size: 4vw; }
        .doctor-name,
        .doctor-name-left { font-size: 5.5vw; gap: 1.5vw; }
        .doctor-icon      { font-size: 5vw; }
        .doctor-specialization { font-size: 4vw; padding-left: 7vw; }
        .resume-date-right{ font-size: 3.8vw; padding: 0.4vh 2vw; border-radius: 1.5vw; }
        .tentative-badge  { font-size: 3vw; padding: 0.3vh 2vw; border-radius: 1.2vw; }
        .remarks-line     { font-size: 3.6vw; padding-left: 7vw; }
        .doctor-card      { padding: 1.5vh 4vw; border-radius: 3vw; border-left-width: 1.2vw; }
        .col-header       { padding-bottom: 1.2vh; }
    }

    /* Very small phone (≤ 360px) */
    @media (max-width: 360px) {
        .col-title        { font-size: 7vw; }
        .col-count        { font-size: 5.5vw; }
        .current-date     { font-size: 4.5vw; }
        .doctor-name,
        .doctor-name-left { font-size: 6vw; }
        .doctor-specialization { font-size: 4.5vw; }
        .resume-date-right{ font-size: 4.2vw; }
        .remarks-line     { font-size: 4vw; }
    }
</style>
</head>
<body>

<div class="board-wrap">

    <!-- No Clinic Today Column -->
    <div class="status-col" data-status="no medical">
        <div class="col-header">
            <div class="col-header-left">
                <span class="col-title">No Clinic Today</span>
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
                <span class="col-title">On Leave</span>
                <span class="col-count">(<?= count($groups['on leave']) ?>)</span>
            </div>
            <div class="resumes-label">
                <i class="bi bi-calendar-check"></i>
                <span>Resumes</span>
            </div>
        </div>
        <div class="col-list">
            <div class="col-list-inner">

                <?php foreach ($onLeaveOnly as $doctor):
                    $rm = trim($doctor['remarks'] ?? '');
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
                    <?php if ($rm !== ''): ?>
                    <div class="remarks-line">
                        <strong>Remarks:</strong> <?= htmlspecialchars($rm) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php foreach ($withResumeDates as $doctor):
                    $rm = trim($doctor['remarks'] ?? '');
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
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div class="doctor-specialization">
                            <?= htmlspecialchars($doctor['department'] ?? 'General') ?>
                        </div>
                        <?php if (!empty($doctor['is_tentative']) && $doctor['is_tentative'] == 1): ?>
                        <div class="tentative-badge">
                            <i class="bi bi-calendar-question"></i> TENTATIVE
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($rm !== ''): ?>
                    <div class="remarks-line">
                        <strong>Remarks:</strong> <?= htmlspecialchars($rm) ?>
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
// ── Date display ──────────────────────────────────────────────────────────────
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

// ── Scroll system ─────────────────────────────────────────────────────────────
(function () {
    const SETTINGS_POLL_MS = 3000;

    const settings = {
        speed:    <?= (int)($display_settings['scroll_speed']    ?? 30) ?>,
        pauseTop: <?= (int)($display_settings['pause_at_top']    ?? 3000) ?>,
        pauseBot: <?= (int)($display_settings['pause_at_bottom'] ?? 3000) ?>
    };

    let lastDataStr     = '';
    let lastSettingsStr = JSON.stringify(settings);
    const scrollStates  = new WeakMap();

    // speed value = pixels per second (accurate, screen-independent)
    // e.g. speed 30 = 30px/sec, speed 75 = 75px/sec
    function startScroll(colList, forceReset) {
        const inner = colList.querySelector('.col-list-inner');
        if (!inner || inner.children.length === 0) return;

        const existing = scrollStates.get(colList);

        // Compute maxScroll fresh
        let totalH = 0;
        for (const card of inner.children) totalH += card.offsetHeight + Math.round(window.innerHeight * 0.012);
        totalH += Math.round(window.innerHeight * 0.03);
        const containerH = colList.offsetHeight || window.innerHeight;
        const maxScroll = Math.max(totalH - containerH, 0);

        if (maxScroll <= 0) {
            // Content fits — no scrolling needed, just show it static
            if (existing && existing.rafId) cancelAnimationFrame(existing.rafId);
            inner.style.transform = 'translateY(0)';
            scrollStates.delete(colList);
            return;
        }

        if (!forceReset && existing) {
            // Just update maxScroll in case content height changed
            existing.maxScroll = maxScroll;
            return;
        }

        // Cancel existing animation
        if (existing && existing.rafId) cancelAnimationFrame(existing.rafId);

        const state = {
            pos: 0,
            dir: 1,
            pausing: true,
            pauseEnd: performance.now() + settings.pauseTop,
            lastTime: null,
            rafId: null,
            maxScroll
        };
        scrollStates.set(colList, state);
        inner.style.transform = 'translateY(0)';

        function tick(now) {
            const s = scrollStates.get(colList);
            if (!s) return;

            if (s.pausing) {
                if (now >= s.pauseEnd) {
                    s.pausing = false;
                    s.lastTime = now;
                }
                s.rafId = requestAnimationFrame(tick);
                return;
            }

            // Delta-time based: pixels per second regardless of frame rate
            const delta = s.lastTime ? Math.min(now - s.lastTime, 100) : 0;
            s.lastTime = now;
            const step = (settings.speed * delta) / 1000;

            if (s.dir === 1) {
                s.pos = Math.min(s.pos + step, s.maxScroll);
                if (s.pos >= s.maxScroll) {
                    s.pos = s.maxScroll;
                    s.dir = -1;
                    s.pausing = true;
                    s.pauseEnd = now + settings.pauseBot;
                }
            } else {
                s.pos = Math.max(s.pos - step, 0);
                if (s.pos <= 0) {
                    s.pos = 0;
                    s.dir = 1;
                    s.pausing = true;
                    s.pauseEnd = now + settings.pauseTop;
                }
            }

            inner.style.transform = `translateY(-${s.pos}px)`;
            s.rafId = requestAnimationFrame(tick);
        }

        state.rafId = requestAnimationFrame(tick);
    }

    function escHtml(str) {
        return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fmtDate(s) {
        try {
            const [y,m,d] = s.split('-').map(Number);
            return new Date(y,m-1,d).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'2-digit'});
        } catch(e) { return s; }
    }

    function buildNoClinicCard(doc) {
        const card = document.createElement('div');
        card.className = 'doctor-card';
        card.innerHTML = `
            <div class="doctor-name">
                <i class="doctor-icon bi bi-person-fill"></i>
                <span>${escHtml(doc.name)}</span>
            </div>
            <div class="doctor-specialization">${escHtml(doc.department||'General')}</div>`;
        return card;
    }

    function buildOnLeaveCard(doc) {
        const card = document.createElement('div');
        card.className = 'doctor-card';
        const rm = (doc.remarks||'').trim();
        const tentBadge = (doc.resume_date && doc.is_tentative==1)
            ? `<div class="tentative-badge"><i class="bi bi-calendar-question"></i> TENTATIVE</div>` : '';
        const dateHtml = doc.resume_date
            ? `<div class="resume-date-right">${fmtDate(doc.resume_date)}</div>` : '';
        const remarksHtml = rm
            ? `<div class="remarks-line"><strong>Remarks:</strong> ${escHtml(rm)}</div>` : '';
        card.innerHTML = `
            <div class="doctor-name-row">
                <div class="doctor-name-left">
                    <i class="doctor-icon bi bi-person-fill"></i>
                    <span>${escHtml(doc.name)}</span>
                </div>
                ${dateHtml}
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div class="doctor-specialization">${escHtml(doc.department||'General')}</div>
                ${tentBadge}
            </div>
            ${remarksHtml}`;
        return card;
    }

    function updateBoard(data) {
        if (!data) return;

        // ── Update settings silently (no scroll restart) ─────────────────
        if (data.display_settings) {
            const ds = data.display_settings;
            const newStr = JSON.stringify(ds);
            if (newStr !== lastSettingsStr) {
                lastSettingsStr = newStr;
                settings.speed    = parseInt(ds.scroll_speed)    || 30;
                settings.pauseTop = parseInt(ds.pause_at_top)    || 3000;
                settings.pauseBot = parseInt(ds.pause_at_bottom) || 3000;
                // Clamp any active pause to new duration — no restart
                document.querySelectorAll('.col-list').forEach(colList => {
                    const s = scrollStates.get(colList);
                    if (s && s.pausing) {
                        const maxPause = s.dir === -1 ? settings.pauseBot : settings.pauseTop;
                        if ((s.pauseEnd - performance.now()) > maxPause)
                            s.pauseEnd = performance.now() + maxPause;
                    }
                });
            }
        }

        // ── Only rebuild DOM if doctor data actually changed ───────────
        const str = JSON.stringify(data.doctors);
        if (str === lastDataStr) return;
        lastDataStr = str;

        const noClinic = [], onLeave = [];
        (data.doctors || []).forEach(d => {
            const st = (d.status||'').toLowerCase();
            if (st.includes('leave')) onLeave.push(d);
            else if (st.includes('schedule') || st.includes('available')) { /* skip */ }
            else noClinic.push(d);
        });

        const cols = document.querySelectorAll('.status-col');

        const noClinicCol = cols[0];
        if (noClinicCol) {
            const countEl = noClinicCol.querySelector('.col-count');
            if (countEl) countEl.textContent = `(${noClinic.length})`;
            const listEl  = noClinicCol.querySelector('.col-list');
            const innerEl = listEl && listEl.querySelector('.col-list-inner');
            if (innerEl) {
                innerEl.innerHTML = '';
                noClinic.forEach(d => innerEl.appendChild(buildNoClinicCard(d)));
                startScroll(listEl, true); // only fires when data changed
            }
        }

        const onLeaveCol = cols[1];
        if (onLeaveCol) {
            const countEl = onLeaveCol.querySelector('.col-count');
            if (countEl) countEl.textContent = `(${onLeave.length})`;
            const listEl  = onLeaveCol.querySelector('.col-list');
            const innerEl = listEl && listEl.querySelector('.col-list-inner');
            if (innerEl) {
                innerEl.innerHTML = '';
                const withDate = onLeave.filter(d => d.resume_date);
                const noDate   = onLeave.filter(d => !d.resume_date);
                noDate.forEach(d   => innerEl.appendChild(buildOnLeaveCard(d)));
                withDate.forEach(d => innerEl.appendChild(buildOnLeaveCard(d)));
                startScroll(listEl, true); // only fires when data changed
            }
        }
    }

    async function fetchAll() {
        try {
            const res = await fetch(window.location.pathname + '?ajax=1');
            if (!res.ok) return;
            updateBoard(await res.json());
        } catch(e) { console.warn('Live update failed', e); }
    }

    window.addEventListener('load', () => {
        document.querySelectorAll('.col-list').forEach(l => startScroll(l, true));
    });

    setTimeout(fetchAll, 1500);
    setInterval(fetchAll, SETTINGS_POLL_MS);
})();
</script>
</body>
</html>