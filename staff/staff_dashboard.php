<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// --- SUMMARY STATS ------------------------------------------------------------
$total_staff    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM county_staff WHERE status='active'"))['c'];
$total_male     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM county_staff WHERE status='active' AND sex='Male'"))['c'];
$total_female   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM county_staff WHERE status='active' AND sex='Female'"))['c'];
$total_inactive = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM county_staff WHERE status!='active'"))['c'];

// Profiles with academics filled
$with_academics = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT id_number) AS c FROM employee_academics"))['c'];

// Staff currently in school (work experience is_current=Yes AND employer_type contains study, OR academic end_date in future)
$in_school = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT ea.id_number) AS c
     FROM employee_academics ea
     WHERE ea.completion_status IN ('In Progress','Enrolled')"))['c'];

// Expiring professional registrations (next 90 days)
$expiring_regs = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS c FROM employee_professional_registrations
     WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"))['c'];

// Open disciplinary cases
$open_cases = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS c FROM employee_disciplinary WHERE status IN ('Open','Under Investigation')"))['c'];

// Pending leave
$pending_leave = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS c FROM employee_leave WHERE status='Pending'"))['c'];

// --- BIRTHDAYS (next 30 days) -------------------------------------------------
$birthdays = [];
$bday_result = mysqli_query($conn,
    "SELECT staff_id, first_name, last_name, other_name, date_of_birth, cadre_name, facility_name,
            DATE_FORMAT(date_of_birth, '%m-%d') AS bday_mmdd,
            TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) + 1 AS turning_age
     FROM county_staff
     WHERE status='active'
       AND date_of_birth IS NOT NULL
       AND (
           DATE_FORMAT(date_of_birth, '%m-%d') BETWEEN DATE_FORMAT(CURDATE(),'%m-%d')
               AND DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY),'%m-%d')
           OR (
               DATE_FORMAT(CURDATE(),'%m-%d') > DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY),'%m-%d')
               AND (
                   DATE_FORMAT(date_of_birth,'%m-%d') >= DATE_FORMAT(CURDATE(),'%m-%d')
                   OR DATE_FORMAT(date_of_birth,'%m-%d') <= DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 30 DAY),'%m-%d')
               )
           )
       )
     ORDER BY bday_mmdd
     LIMIT 20");
while ($r = mysqli_fetch_assoc($bday_result)) $birthdays[] = $r;

// --- SEX DISAGGREGATION BY CADRE ---------------------------------------------
$cadre_sex = [];
$cs_result = mysqli_query($conn,
    "SELECT cadre_name,
            SUM(CASE WHEN sex='Male' THEN 1 ELSE 0 END) AS male,
            SUM(CASE WHEN sex='Female' THEN 1 ELSE 0 END) AS female,
            COUNT(*) AS total
     FROM county_staff WHERE status='active'
     GROUP BY cadre_name ORDER BY total DESC LIMIT 15");
while ($r = mysqli_fetch_assoc($cs_result)) $cadre_sex[] = $r;

// --- AGE DISTRIBUTION ---------------------------------------------------------
$age_dist = [];
$age_result = mysqli_query($conn,
    "SELECT
        CASE
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 25 THEN 'Under 25'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 25 AND 34 THEN '25 – 34'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 35 AND 44 THEN '35 – 44'
            WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 45 AND 54 THEN '45 – 54'
            ELSE '55+'
        END AS age_band,
        COUNT(*) AS total,
        SUM(CASE WHEN sex='Male' THEN 1 ELSE 0 END) AS male,
        SUM(CASE WHEN sex='Female' THEN 1 ELSE 0 END) AS female
     FROM county_staff
     WHERE status='active' AND date_of_birth IS NOT NULL
     GROUP BY age_band
     ORDER BY FIELD(age_band,'Under 25','25 – 34','35 – 44','45 – 54','55+')");
while ($r = mysqli_fetch_assoc($age_result)) $age_dist[] = $r;

// --- TOP COURSES STUDIED ------------------------------------------------------
$courses = [];
$courses_result = mysqli_query($conn,
    "SELECT course_name, qualification_type, COUNT(*) AS cnt
     FROM employee_academics
     WHERE course_name IS NOT NULL AND course_name != ''
     GROUP BY course_name, qualification_type
     ORDER BY cnt DESC LIMIT 12");
while ($r = mysqli_fetch_assoc($courses_result)) $courses[] = $r;

// --- QUALIFICATION TYPES BREAKDOWN -------------------------------------------
$qual_types = [];
$qt_result = mysqli_query($conn,
    "SELECT qualification_type, COUNT(*) AS cnt
     FROM employee_academics
     GROUP BY qualification_type ORDER BY cnt DESC");
while ($r = mysqli_fetch_assoc($qt_result)) $qual_types[] = $r;

// --- STAFF IN SCHOOL (In Progress / Enrolled) --------------------------------
$in_school_list = [];
$is_result = mysqli_query($conn,
    "SELECT cs.first_name, cs.last_name, cs.cadre_name, cs.facility_name,
            ea.qualification_type, ea.institution_name, ea.course_name,
            ea.end_date, ea.completion_status
     FROM employee_academics ea
     JOIN county_staff cs ON cs.id_number = ea.id_number
     WHERE ea.completion_status IN ('In Progress','Enrolled')
       AND cs.status='active'
     ORDER BY ea.end_date ASC LIMIT 20");
while ($r = mysqli_fetch_assoc($is_result)) $in_school_list[] = $r;

// --- CADRE BREAKDOWN ----------------------------------------------------------
$cadres = [];
$cadre_result = mysqli_query($conn,
    "SELECT cadre_name, COUNT(*) AS cnt,
            SUM(CASE WHEN sex='Male' THEN 1 ELSE 0 END) AS male,
            SUM(CASE WHEN sex='Female' THEN 1 ELSE 0 END) AS female
     FROM county_staff WHERE status='active'
     GROUP BY cadre_name ORDER BY cnt DESC LIMIT 20");
while ($r = mysqli_fetch_assoc($cadre_result)) $cadres[] = $r;

// --- FACILITY DISTRIBUTION ---------------------------------------------------
$facilities = [];
$fac_result = mysqli_query($conn,
    "SELECT facility_name, COUNT(*) AS cnt
     FROM county_staff WHERE status='active'
     GROUP BY facility_name ORDER BY cnt DESC LIMIT 10");
while ($r = mysqli_fetch_assoc($fac_result)) $facilities[] = $r;

// --- RECENT HIRES (last 60 days) ---------------------------------------------
$recent_hires = [];
$rh_result = mysqli_query($conn,
    "SELECT first_name, last_name, cadre_name, facility_name, created_at
     FROM county_staff
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
     ORDER BY created_at DESC LIMIT 8");
while ($r = mysqli_fetch_assoc($rh_result)) $recent_hires[] = $r;

// --- LEAVE SUMMARY -----------------------------------------------------------
$leave_summary = [];
$lv_result = mysqli_query($conn,
    "SELECT leave_type,
            SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status='Pending'  THEN 1 ELSE 0 END) AS pending,
            COUNT(*) AS total
     FROM employee_leave
     GROUP BY leave_type ORDER BY total DESC");
while ($r = mysqli_fetch_assoc($lv_result)) $leave_summary[] = $r;

// --- EMPLOYMENT STATUS MIX ----------------------------------------------------
$emp_status = [];
$es_result = mysqli_query($conn,
    "SELECT employment_status, COUNT(*) AS cnt
     FROM county_staff WHERE status='active'
     GROUP BY employment_status ORDER BY cnt DESC");
while ($r = mysqli_fetch_assoc($es_result)) $emp_status[] = $r;

// JSON for charts
$cadre_labels  = json_encode(array_column($cadres, 'cadre_name'));
$cadre_counts  = json_encode(array_column($cadres, 'cnt'));
$cadre_male    = json_encode(array_column($cadres, 'male'));
$cadre_female  = json_encode(array_column($cadres, 'female'));

$age_labels    = json_encode(array_column($age_dist, 'age_band'));
$age_male      = json_encode(array_column($age_dist, 'male'));
$age_female    = json_encode(array_column($age_dist, 'female'));

$qt_labels     = json_encode(array_column($qual_types, 'qualification_type'));
$qt_counts     = json_encode(array_column($qual_types, 'cnt'));

$fac_labels    = json_encode(array_column($facilities, 'facility_name'));
$fac_counts    = json_encode(array_column($facilities, 'cnt'));

$es_labels     = json_encode(array_column($emp_status, 'employment_status'));
$es_counts     = json_encode(array_column($emp_status, 'cnt'));

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HR Staff Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* -- ROOT & RESET ----------------------------------------------------------- */
:root {
    --navy:    #0D1A63;
    --navy2:   #1a2a7a;
    --accent:  #4F8EF7;
    --teal:    #0ABFBC;
    --rose:    #F7626B;
    --amber:   #F5A623;
    --green:   #27AE60;
    --purple:  #8B5CF6;
    --bg:      #EEF2F9;
    --card:    #FFFFFF;
    --text:    #1a1e2e;
    --muted:   #6b7280;
    --border:  #e2e8f0;
    --shadow:  0 4px 24px rgba(13,26,99,0.08);
    --shadow2: 0 8px 40px rgba(13,26,99,0.14);
    --radius:  16px;
    --font:    'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

a { text-decoration: none; color: inherit; }

/* -- TOP BAR ---------------------------------------------------------------- */
.topbar {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 100%);
    color: white;
    padding: 0 32px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 20px rgba(0,0,0,0.25);
}

.topbar-left { display: flex; align-items: center; gap: 14px; }
.topbar-logo { width: 38px; height: 38px; background: rgba(255,255,255,0.15);
               border-radius: 10px; display: flex; align-items: center;
               justify-content: center; font-size: 18px; }
.topbar-title { font-size: 18px; font-weight: 700; letter-spacing: -0.3px; }
.topbar-sub   { font-size: 12px; opacity: 0.65; margin-top: 1px; }

.topbar-right { display: flex; align-items: center; gap: 20px; }
.topbar-date  { font-size: 13px; opacity: 0.75; }
.topbar-btn   { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25);
                color: white; padding: 7px 16px; border-radius: 8px; font-size: 13px;
                cursor: pointer; transition: .2s; display: inline-flex; align-items: center; gap: 7px; }
.topbar-btn:hover { background: rgba(255,255,255,0.25); }

/* -- LAYOUT ----------------------------------------------------------------- */
.page { padding: 28px 32px; max-width: 1600px; margin: 0 auto; }

/* -- SECTION LABEL ---------------------------------------------------------- */
.section-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--muted);
    margin: 32px 0 14px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.section-label::after { content:''; flex:1; height:1px; background:var(--border); }

/* -- KPI CARDS -------------------------------------------------------------- */
.kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
}

.kpi {
    background: var(--card);
    border-radius: var(--radius);
    padding: 20px 22px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: transform .2s, box-shadow .2s;
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow2); }

.kpi::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: var(--kpi-color, var(--accent));
}

.kpi-icon {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: var(--kpi-bg, rgba(79,142,247,0.1));
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    color: var(--kpi-color, var(--accent));
    margin-bottom: 14px;
}

.kpi-value {
    font-size: 32px;
    font-weight: 800;
    color: var(--text);
    line-height: 1;
    margin-bottom: 4px;
    font-variant-numeric: tabular-nums;
}

.kpi-label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 500;
}

.kpi-sub {
    font-size: 11px;
    color: var(--muted);
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}

/* -- GRID LAYOUTS ----------------------------------------------------------- */
.grid-2  { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.grid-3  { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
.grid-23 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
.grid-32 { display: grid; grid-template-columns: 3fr 2fr; gap: 20px; }

/* -- CARD ------------------------------------------------------------------- */
.card {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.card-head {
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.card-head h3 {
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    display: flex;
    align-items: center;
    gap: 9px;
}

.card-head h3 i {
    width: 28px; height: 28px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px;
    background: rgba(13,26,99,0.08);
    color: var(--navy);
}

.card-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 20px;
    background: var(--bg);
    color: var(--muted);
}

.card-badge.alert { background: #FEF3C7; color: #92400E; }
.card-badge.danger { background: #FEE2E2; color: #991B1B; }
.card-badge.success { background: #D1FAE5; color: #065F46; }

.card-body { padding: 18px 22px; }

/* -- CHART WRAPPER ---------------------------------------------------------- */
.chart-wrap { position: relative; }
.chart-wrap canvas { max-width: 100%; }

/* -- DONUT LEGEND ----------------------------------------------------------- */
.donut-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}
.donut-center-wrap { position: relative; width: 180px; height: 180px; }
.donut-center-wrap canvas { width: 180px !important; height: 180px !important; }
.donut-center {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    pointer-events: none;
}
.donut-center-val  { font-size: 28px; font-weight: 800; color: var(--text); }
.donut-center-lbl  { font-size: 11px; color: var(--muted); margin-top: 2px; }

.donut-legend { width: 100%; }
.donut-legend-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 5px 0;
    font-size: 13px;
    border-bottom: 1px solid var(--border);
}
.donut-legend-item:last-child { border-bottom: none; }
.donut-legend-dot {
    width: 10px; height: 10px;
    border-radius: 3px;
    margin-right: 8px;
    flex-shrink: 0;
}
.donut-legend-name { flex: 1; color: var(--text); font-weight: 500; display: flex; align-items: center; }
.donut-legend-val  { font-weight: 700; color: var(--text); }

/* -- TABLE ------------------------------------------------------------------ */
.dash-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.dash-table th {
    padding: 10px 12px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--muted);
    background: #f8fafc;
    border-bottom: 1px solid var(--border);
}
.dash-table td {
    padding: 11px 12px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    vertical-align: middle;
}
.dash-table tr:last-child td { border-bottom: none; }
.dash-table tr:hover td { background: #f8fafc; }

/* -- PILLS / BADGES --------------------------------------------------------- */
.pill {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}
.pill-blue   { background:#EFF6FF; color:#1D4ED8; }
.pill-green  { background:#D1FAE5; color:#065F46; }
.pill-amber  { background:#FEF3C7; color:#92400E; }
.pill-red    { background:#FEE2E2; color:#991B1B; }
.pill-purple { background:#EDE9FE; color:#5B21B6; }
.pill-teal   { background:#CCFBF1; color:#0F766E; }
.pill-gray   { background:#F3F4F6; color:#374151; }

/* -- BIRTHDAY CARD ---------------------------------------------------------- */
.bday-list { display: flex; flex-direction: column; gap: 0; }
.bday-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.bday-item:last-child { border-bottom: none; }
.bday-avatar {
    width: 40px; height: 40px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--accent), var(--teal));
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    font-weight: 800;
    color: white;
    flex-shrink: 0;
}
.bday-avatar.today { background: linear-gradient(135deg, var(--rose), var(--amber)); animation: pulse 2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.75} }
.bday-name  { font-size: 14px; font-weight: 600; color: var(--text); }
.bday-meta  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.bday-date  { margin-left: auto; text-align: right; }
.bday-day   { font-size: 13px; font-weight: 700; color: var(--navy); }
.bday-age   { font-size: 11px; color: var(--muted); margin-top: 1px; }

/* -- ALERT ITEM ------------------------------------------------------------- */
.alert-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
}
.alert-item:last-child { border-bottom: none; }
.alert-icon {
    width: 34px; height: 34px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
}
.alert-icon.warn   { background:#FEF3C7; color:#D97706; }
.alert-icon.danger { background:#FEE2E2; color:#DC2626; }
.alert-icon.info   { background:#EFF6FF; color:#2563EB; }
.alert-icon.ok     { background:#D1FAE5; color:#059669; }
.alert-text  { font-size: 13px; font-weight: 600; color: var(--text); }
.alert-sub   { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* -- PROGRESS BAR ----------------------------------------------------------- */
.prog-row    { margin-bottom: 14px; }
.prog-row:last-child { margin-bottom: 0; }
.prog-label  { display: flex; justify-content: space-between;
               font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text); }
.prog-sub    { font-size: 11px; color: var(--muted); font-weight: 400; }
.prog-bar    { height: 8px; background: var(--bg); border-radius: 99px; overflow: hidden; }
.prog-fill   { height: 100%; border-radius: 99px; background: var(--navy);
               transition: width 1s cubic-bezier(.4,0,.2,1); }

/* -- IN SCHOOL TABLE -------------------------------------------------------- */
.school-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
    font-size: 14px;
}
.school-empty i { font-size: 32px; display: block; margin-bottom: 10px; opacity: .4; }

/* -- RESPONSIVE ------------------------------------------------------------- */
@media (max-width: 1100px) {
    .grid-32, .grid-23 { grid-template-columns: 1fr; }
}
@media (max-width: 800px) {
    .page { padding: 16px; }
    .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .topbar { padding: 0 16px; }
    .topbar-right { gap: 10px; }
    .topbar-date { display: none; }
    .kpi-row { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<!-- -- TOP BAR ---------------------------------------------------------------- -->
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-logo"><i class="fas fa-users"></i></div>
        <div>
            <div class="topbar-title">HR Staff Dashboard</div>
            <div class="topbar-sub">Human Resource Analytics &amp; Intelligence</div>
        </div>
    </div>
    <div class="topbar-right">
        <span class="topbar-date"><i class="fas fa-calendar-alt"></i> <?php echo date('l, d F Y'); ?></span>
        <a href="staffslist.php" class="topbar-btn"><i class="fas fa-list"></i> Staff List</a>
        <a href="add_staff.php" class="topbar-btn"><i class="fas fa-plus"></i> Add Staff</a>
    </div>
</div>

<!-- -- PAGE BODY --------------------------------------------------------------- -->
<div class="page">

    <!-- -- KPI ROW -- -->
    <div class="section-label">Overview</div>
    <div class="kpi-row">
        <div class="kpi" style="--kpi-color:var(--accent);--kpi-bg:rgba(79,142,247,.1)">
            <div class="kpi-icon"><i class="fas fa-users"></i></div>
            <div class="kpi-value"><?php echo number_format($total_staff); ?></div>
            <div class="kpi-label">Active Staff</div>
            <div class="kpi-sub">
                <i class="fas fa-mars" style="color:var(--accent)"></i> <?php echo $total_male; ?> Male &nbsp;
                <i class="fas fa-venus" style="color:var(--rose)"></i> <?php echo $total_female; ?> Female
            </div>
        </div>

        <div class="kpi" style="--kpi-color:var(--rose);--kpi-bg:rgba(247,98,107,.1)">
            <div class="kpi-icon"><i class="fas fa-cake-candles"></i></div>
            <div class="kpi-value"><?php echo count($birthdays); ?></div>
            <div class="kpi-label">Upcoming Birthdays</div>
            <div class="kpi-sub">Next 30 days</div>
        </div>

        <div class="kpi" style="--kpi-color:var(--purple);--kpi-bg:rgba(139,92,246,.1)">
            <div class="kpi-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="kpi-value"><?php echo $in_school; ?></div>
            <div class="kpi-label">Staff In School</div>
            <div class="kpi-sub">Enrolled / In Progress</div>
        </div>

        <div class="kpi" style="--kpi-color:var(--amber);--kpi-bg:rgba(245,166,35,.1)">
            <div class="kpi-icon"><i class="fas fa-id-badge"></i></div>
            <div class="kpi-value"><?php echo $expiring_regs; ?></div>
            <div class="kpi-label">Expiring Licences</div>
            <div class="kpi-sub">Within 90 days</div>
        </div>

        <div class="kpi" style="--kpi-color:var(--rose);--kpi-bg:rgba(247,98,107,.1)">
            <div class="kpi-icon"><i class="fas fa-gavel"></i></div>
            <div class="kpi-value"><?php echo $open_cases; ?></div>
            <div class="kpi-label">Open Disciplinary</div>
            <div class="kpi-sub">Open / Under Investigation</div>
        </div>

        <div class="kpi" style="--kpi-color:var(--teal);--kpi-bg:rgba(10,191,188,.1)">
            <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
            <div class="kpi-value"><?php echo $pending_leave; ?></div>
            <div class="kpi-label">Pending Leave</div>
            <div class="kpi-sub">Awaiting approval</div>
        </div>

        <div class="kpi" style="--kpi-color:var(--green);--kpi-bg:rgba(39,174,96,.1)">
            <div class="kpi-icon"><i class="fas fa-book-open"></i></div>
            <div class="kpi-value"><?php echo $with_academics; ?></div>
            <div class="kpi-label">Academic Records</div>
            <div class="kpi-sub">Profiles with qualifications</div>
        </div>

        <div class="kpi" style="--kpi-color:var(--muted);--kpi-bg:rgba(107,114,128,.1)">
            <div class="kpi-icon"><i class="fas fa-user-slash"></i></div>
            <div class="kpi-value"><?php echo $total_inactive; ?></div>
            <div class="kpi-label">Inactive Staff</div>
            <div class="kpi-sub">Transferred / Resigned</div>
        </div>
    </div>

    <!-- -- ROW: Sex Donut + Age Pyramid + Employment Status -- -->
    <div class="section-label">Workforce Composition</div>
    <div class="grid-3">

        <!-- Sex Disaggregation Donut -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-venus-mars"></i> Sex Disaggregation</h3>
            </div>
            <div class="card-body">
                <div class="donut-wrap">
                    <div class="donut-center-wrap">
                        <canvas id="sexChart"></canvas>
                        <div class="donut-center">
                            <div class="donut-center-val"><?php echo $total_staff; ?></div>
                            <div class="donut-center-lbl">Total</div>
                        </div>
                    </div>
                    <div class="donut-legend" style="width:100%">
                        <div class="donut-legend-item">
                            <span class="donut-legend-dot" style="background:var(--accent)"></span>
                            <span class="donut-legend-name">Male</span>
                            <span class="donut-legend-val"><?php echo $total_male; ?>
                                <small style="color:var(--muted);font-weight:400"> (<?php echo $total_staff > 0 ? round($total_male/$total_staff*100) : 0; ?>%)</small>
                            </span>
                        </div>
                        <div class="donut-legend-item">
                            <span class="donut-legend-dot" style="background:var(--rose)"></span>
                            <span class="donut-legend-name">Female</span>
                            <span class="donut-legend-val"><?php echo $total_female; ?>
                                <small style="color:var(--muted);font-weight:400"> (<?php echo $total_staff > 0 ? round($total_female/$total_staff*100) : 0; ?>%)</small>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Age Distribution -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-chart-bar"></i> Age Distribution</h3>
            </div>
            <div class="card-body">
                <?php if (empty($age_dist)): ?>
                    <div class="school-empty"><i class="fas fa-calendar"></i>No date of birth data yet</div>
                <?php else: ?>
                <?php
                $age_max = max(array_column($age_dist, 'total'));
                $colors = ['#4F8EF7','#0ABFBC','#8B5CF6','#F5A623','#F7626B'];
                $ci = 0;
                foreach ($age_dist as $ab):
                    $pct = $age_max > 0 ? round($ab['total']/$age_max*100) : 0;
                ?>
                <div class="prog-row">
                    <div class="prog-label">
                        <span><?php echo htmlspecialchars($ab['age_band']); ?></span>
                        <span><?php echo $ab['total']; ?>
                            <span class="prog-sub">
                                <i class="fas fa-mars" style="color:var(--accent)"></i><?php echo $ab['male']; ?>
                                <i class="fas fa-venus" style="color:var(--rose);margin-left:4px"></i><?php echo $ab['female']; ?>
                            </span>
                        </span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $colors[$ci % count($colors)]; ?>"></div>
                    </div>
                </div>
                <?php $ci++; endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Employment Status Donut -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-briefcase"></i> Employment Status</h3>
            </div>
            <div class="card-body">
                <?php if (empty($emp_status)): ?>
                    <div class="school-empty"><i class="fas fa-briefcase"></i>No data yet</div>
                <?php else: ?>
                <div class="donut-wrap">
                    <div class="donut-center-wrap">
                        <canvas id="empStatusChart"></canvas>
                        <div class="donut-center">
                            <div class="donut-center-val"><?php echo $total_staff; ?></div>
                            <div class="donut-center-lbl">Active</div>
                        </div>
                    </div>
                    <div class="donut-legend" style="width:100%">
                        <?php
                        $es_colors = ['#4F8EF7','#0ABFBC','#8B5CF6','#F5A623','#F7626B','#27AE60'];
                        $eci = 0;
                        foreach ($emp_status as $es):
                        ?>
                        <div class="donut-legend-item">
                            <span class="donut-legend-dot" style="background:<?php echo $es_colors[$eci % count($es_colors)]; ?>"></span>
                            <span class="donut-legend-name"><?php echo htmlspecialchars($es['employment_status'] ?: 'Unknown'); ?></span>
                            <span class="donut-legend-val"><?php echo $es['cnt']; ?></span>
                        </div>
                        <?php $eci++; endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- -- ROW: Cadre Bar Chart + Facility Distribution -- -->
    <div class="section-label">Cadre &amp; Facility</div>
    <div class="grid-32">

        <!-- Cadre Breakdown Bar Chart -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-layer-group"></i> Staff by Cadre</h3>
                <span class="card-badge"><?php echo count($cadres); ?> cadres</span>
            </div>
            <div class="card-body">
                <div class="chart-wrap" style="height:300px">
                    <canvas id="cadreChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Facilities -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-hospital"></i> Top Facilities</h3>
                <span class="card-badge"><?php echo count($facilities); ?> shown</span>
            </div>
            <div class="card-body">
                <?php
                $fac_max = !empty($facilities) ? max(array_column($facilities, 'cnt')) : 1;
                $fac_colors = ['#4F8EF7','#0ABFBC','#8B5CF6','#F5A623','#F7626B','#27AE60','#0D1A63','#E91E8C','#FF6B35','#2ECC71'];
                foreach ($facilities as $fi => $fac):
                    $fpct = $fac_max > 0 ? round($fac['cnt']/$fac_max*100) : 0;
                ?>
                <div class="prog-row">
                    <div class="prog-label">
                        <span style="font-size:12px;max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($fac['facility_name']); ?></span>
                        <span><?php echo $fac['cnt']; ?></span>
                    </div>
                    <div class="prog-bar">
                        <div class="prog-fill" style="width:<?php echo $fpct; ?>%;background:<?php echo $fac_colors[$fi % count($fac_colors)]; ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- -- ROW: Cadre Sex Disaggregation Table -- -->
    <div class="section-label">Cadre × Sex Disaggregation</div>
    <div class="card">
        <div class="card-head">
            <h3><i class="fas fa-table"></i> Cadre Breakdown by Sex</h3>
            <span class="card-badge"><?php echo count($cadre_sex); ?> cadres</span>
        </div>
        <div style="overflow-x:auto">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Cadre</th>
                        <th><i class="fas fa-mars" style="color:var(--accent)"></i> Male</th>
                        <th><i class="fas fa-venus" style="color:var(--rose)"></i> Female</th>
                        <th>Total</th>
                        <th>% Female</th>
                        <th style="width:200px">Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cadre_sex as $cs):
                        $fpct = $cs['total'] > 0 ? round($cs['female']/$cs['total']*100) : 0;
                        $mpct = 100 - $fpct;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cs['cadre_name']); ?></strong></td>
                        <td><?php echo $cs['male']; ?></td>
                        <td><?php echo $cs['female']; ?></td>
                        <td><strong><?php echo $cs['total']; ?></strong></td>
                        <td>
                            <span class="pill <?php echo $fpct >= 50 ? 'pill-teal' : 'pill-blue'; ?>">
                                <?php echo $fpct; ?>%
                            </span>
                        </td>
                        <td>
                            <div style="display:flex;height:10px;border-radius:99px;overflow:hidden;gap:2px">
                                <div style="width:<?php echo $mpct; ?>%;background:var(--accent);border-radius:99px 0 0 99px;transition:width 1s"></div>
                                <div style="width:<?php echo $fpct; ?>%;background:var(--rose);border-radius:0 99px 99px 0;transition:width 1s"></div>
                            </div>
                            <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-top:3px">
                                <span>M <?php echo $mpct; ?>%</span>
                                <span>F <?php echo $fpct; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- -- ROW: Birthdays + Alerts -- -->
    <div class="section-label">Events &amp; Alerts</div>
    <div class="grid-2">

        <!-- Upcoming Birthdays -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-cake-candles"></i> Upcoming Birthdays</h3>
                <span class="card-badge <?php echo count($birthdays) > 0 ? 'success' : ''; ?>">
                    <?php echo count($birthdays); ?> in 30 days
                </span>
            </div>
            <div class="card-body" style="max-height:380px;overflow-y:auto">
                <?php if (empty($birthdays)): ?>
                    <div class="school-empty"><i class="fas fa-cake-candles"></i>No birthdays in the next 30 days</div>
                <?php else: ?>
                <div class="bday-list">
                    <?php foreach ($birthdays as $b):
                        $bday_this_year = date('Y') . '-' . date('m-d', strtotime($b['date_of_birth']));
                        $is_today = ($bday_this_year === $today);
                        $initials = strtoupper(substr($b['first_name'],0,1) . substr($b['last_name'],0,1));
                        $bday_fmt = date('d M', strtotime($b['date_of_birth']));
                    ?>
                    <div class="bday-item">
                        <div class="bday-avatar <?php echo $is_today ? 'today' : ''; ?>"><?php echo $initials; ?></div>
                        <div>
                            <div class="bday-name">
                                <?php echo htmlspecialchars($b['first_name'] . ' ' . $b['last_name']); ?>
                                <?php if ($is_today): ?> <span class="pill pill-amber">?? Today!</span><?php endif; ?>
                            </div>
                            <div class="bday-meta">
                                <?php echo htmlspecialchars($b['cadre_name'] ?? '—'); ?> &bull;
                                <?php echo htmlspecialchars($b['facility_name'] ?? '—'); ?>
                            </div>
                        </div>
                        <div class="bday-date">
                            <div class="bday-day"><?php echo $bday_fmt; ?></div>
                            <div class="bday-age">Turning <?php echo $b['turning_age']; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- HR Alerts -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-bell"></i> HR Alerts</h3>
                <?php $total_alerts = ($expiring_regs + $open_cases + $pending_leave + $in_school); ?>
                <span class="card-badge <?php echo $total_alerts > 0 ? 'alert' : ''; ?>">
                    <?php echo $total_alerts; ?> items
                </span>
            </div>
            <div class="card-body">
                <div class="alert-item">
                    <div class="alert-icon <?php echo $expiring_regs > 0 ? 'warn' : 'ok'; ?>">
                        <i class="fas fa-id-badge"></i>
                    </div>
                    <div>
                        <div class="alert-text"><?php echo $expiring_regs; ?> Professional Licences Expiring</div>
                        <div class="alert-sub">Within the next 90 days — action required</div>
                    </div>
                </div>

                <div class="alert-item">
                    <div class="alert-icon <?php echo $open_cases > 0 ? 'danger' : 'ok'; ?>">
                        <i class="fas fa-gavel"></i>
                    </div>
                    <div>
                        <div class="alert-text"><?php echo $open_cases; ?> Open Disciplinary Cases</div>
                        <div class="alert-sub">Open or under investigation</div>
                    </div>
                </div>

                <div class="alert-item">
                    <div class="alert-icon <?php echo $pending_leave > 0 ? 'warn' : 'ok'; ?>">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                    <div>
                        <div class="alert-text"><?php echo $pending_leave; ?> Leave Requests Pending</div>
                        <div class="alert-sub">Awaiting supervisor approval</div>
                    </div>
                </div>

                <div class="alert-item">
                    <div class="alert-icon info">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div>
                        <div class="alert-text"><?php echo $in_school; ?> Staff Currently in School</div>
                        <div class="alert-sub">Enrolled or coursework in progress</div>
                    </div>
                </div>

                <?php if (!empty($recent_hires)): ?>
                <div class="alert-item">
                    <div class="alert-icon ok">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div>
                        <div class="alert-text"><?php echo count($recent_hires); ?> New Hires (Last 60 Days)</div>
                        <div class="alert-sub">Recently onboarded staff members</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="alert-item">
                    <div class="alert-icon info">
                        <i class="fas fa-file-contract"></i>
                    </div>
                    <div>
                        <div class="alert-text"><?php echo $with_academics; ?> Academic Profiles Captured</div>
                        <div class="alert-sub">Out of <?php echo $total_staff; ?> active staff
                            (<?php echo $total_staff > 0 ? round($with_academics/$total_staff*100) : 0; ?>%)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- -- ROW: Staff In School -- -->
    <div class="section-label">Staff Currently in School</div>
    <div class="card">
        <div class="card-head">
            <h3><i class="fas fa-graduation-cap"></i> Staff Enrolled / In Progress</h3>
            <span class="card-badge <?php echo $in_school > 0 ? 'alert' : ''; ?>">
                <?php echo $in_school; ?> staff
            </span>
        </div>
        <?php if (empty($in_school_list)): ?>
        <div class="school-empty">
            <i class="fas fa-graduation-cap"></i>
            No staff currently marked as enrolled or in progress
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Cadre</th>
                        <th>Facility</th>
                        <th>Qualification</th>
                        <th>Course</th>
                        <th>Institution</th>
                        <th>Expected End</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($in_school_list as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></strong></td>
                        <td><span class="pill pill-blue"><?php echo htmlspecialchars($s['cadre_name'] ?? '—'); ?></span></td>
                        <td><?php echo htmlspecialchars($s['facility_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($s['qualification_type']); ?></td>
                        <td><?php echo htmlspecialchars($s['course_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($s['institution_name'] ?? '—'); ?></td>
                        <td>
                            <?php if (!empty($s['end_date'])): ?>
                                <?php
                                $end = strtotime($s['end_date']);
                                $overdue = $end < time();
                                ?>
                                <span class="pill <?php echo $overdue ? 'pill-red' : 'pill-green'; ?>">
                                    <?php echo date('M Y', $end); ?>
                                    <?php if ($overdue): ?> ?<?php endif; ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <span class="pill <?php echo $s['completion_status']==='Enrolled' ? 'pill-purple' : 'pill-amber'; ?>">
                                <?php echo htmlspecialchars($s['completion_status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- -- ROW: Courses Studied + Qualification Types -- -->
    <div class="section-label">Academic Intelligence</div>
    <div class="grid-32">

        <!-- Top Courses -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-book"></i> Most Common Courses</h3>
                <span class="card-badge">Top 12</span>
            </div>
            <div style="overflow-x:auto">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Course Name</th>
                            <th>Qualification Level</th>
                            <th>Staff Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($courses)): ?>
                        <tr><td colspan="4" class="school-empty"><i class="fas fa-book"></i>No courses on record yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($courses as $ci => $c): ?>
                        <tr>
                            <td style="color:var(--muted);font-size:12px"><?php echo $ci + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($c['course_name']); ?></strong></td>
                            <td><span class="pill pill-purple"><?php echo htmlspecialchars($c['qualification_type']); ?></span></td>
                            <td>
                                <strong><?php echo $c['cnt']; ?></strong>
                                <span style="color:var(--muted);font-size:11px"> staff</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Qualification Types Donut -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-certificate"></i> Qualification Levels</h3>
            </div>
            <div class="card-body">
                <?php if (empty($qual_types)): ?>
                    <div class="school-empty"><i class="fas fa-certificate"></i>No qualification data yet</div>
                <?php else: ?>
                <div class="chart-wrap" style="height:200px;margin-bottom:16px">
                    <canvas id="qualChart"></canvas>
                </div>
                <div class="donut-legend">
                    <?php
                    $qt_colors = ['#4F8EF7','#0ABFBC','#8B5CF6','#F5A623','#F7626B','#27AE60','#0D1A63','#E91E8C','#FF6B35'];
                    foreach ($qual_types as $qi => $qt):
                    ?>
                    <div class="donut-legend-item">
                        <span class="donut-legend-dot" style="background:<?php echo $qt_colors[$qi % count($qt_colors)]; ?>"></span>
                        <span class="donut-legend-name"><?php echo htmlspecialchars($qt['qualification_type']); ?></span>
                        <span class="donut-legend-val"><?php echo $qt['cnt']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- -- ROW: Leave Summary + Recent Hires -- -->
    <div class="section-label">Leave &amp; Recent Activity</div>
    <div class="grid-2">

        <!-- Leave Summary -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-calendar-minus"></i> Leave Summary by Type</h3>
            </div>
            <div style="overflow-x:auto">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Approved</th>
                            <th>Pending</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leave_summary)): ?>
                        <tr><td colspan="4" class="school-empty">No leave records yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($leave_summary as $lv): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($lv['leave_type']); ?></strong></td>
                            <td><span class="pill pill-green"><?php echo $lv['approved']; ?></span></td>
                            <td><span class="pill <?php echo $lv['pending'] > 0 ? 'pill-amber' : 'pill-gray'; ?>"><?php echo $lv['pending']; ?></span></td>
                            <td><strong><?php echo $lv['total']; ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Hires -->
        <div class="card">
            <div class="card-head">
                <h3><i class="fas fa-user-plus"></i> Recent Hires</h3>
                <span class="card-badge success">Last 60 days</span>
            </div>
            <?php if (empty($recent_hires)): ?>
            <div class="school-empty" style="padding:40px 20px">
                <i class="fas fa-user-plus"></i>No new hires in the last 60 days
            </div>
            <?php else: ?>
            <div style="overflow-x:auto">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Cadre</th>
                            <th>Facility</th>
                            <th>Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_hires as $rh): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($rh['first_name'] . ' ' . $rh['last_name']); ?></strong></td>
                            <td><span class="pill pill-blue"><?php echo htmlspecialchars($rh['cadre_name'] ?? '—'); ?></span></td>
                            <td><?php echo htmlspecialchars($rh['facility_name'] ?? '—'); ?></td>
                            <td style="color:var(--muted);font-size:12px">
                                <?php echo date('d M Y', strtotime($rh['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div style="height:40px"></div>

</div><!-- /page -->

<!-- -- CHARTS JS ------------------------------------------------------------ -->
<script>
Chart.defaults.font.family = "'Segoe UI','Helvetica Neue',Arial,sans-serif";
Chart.defaults.color = '#6b7280';
Chart.defaults.plugins.legend.display = false;

const navy  = '#0D1A63';
const accent= '#4F8EF7';
const rose  = '#F7626B';
const teal  = '#0ABFBC';
const amber = '#F5A623';
const green = '#27AE60';
const purple= '#8B5CF6';

// -- Sex Donut --
new Chart(document.getElementById('sexChart'), {
    type: 'doughnut',
    data: {
        labels: ['Male','Female'],
        datasets: [{
            data: [<?php echo $total_male; ?>, <?php echo $total_female; ?>],
            backgroundColor: [accent, rose],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        cutout: '72%',
        plugins: { tooltip: { callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.raw} (${Math.round(ctx.raw/(<?php echo max(1,$total_staff); ?>)*100)}%)`
        }}}
    }
});

// -- Employment Status Donut --
<?php if (!empty($emp_status)): ?>
new Chart(document.getElementById('empStatusChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo $es_labels; ?>,
        datasets: [{
            data: <?php echo $es_counts; ?>,
            backgroundColor: [accent, teal, purple, amber, rose, green],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        cutout: '72%',
        plugins: { tooltip: { callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.raw}`
        }}}
    }
});
<?php endif; ?>

// -- Cadre Bar Chart --
<?php if (!empty($cadres)): ?>
new Chart(document.getElementById('cadreChart'), {
    type: 'bar',
    data: {
        labels: <?php echo $cadre_labels; ?>,
        datasets: [
            {
                label: 'Male',
                data: <?php echo $cadre_male; ?>,
                backgroundColor: accent,
                borderRadius: 5,
                borderSkipped: false
            },
            {
                label: 'Female',
                data: <?php echo $cadre_female; ?>,
                backgroundColor: rose,
                borderRadius: 5,
                borderSkipped: false
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: true, position: 'top',
                labels: { boxWidth: 12, borderRadius: 3, useBorderRadius: true, font: { size: 12 } }
            },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            x: {
                stacked: true,
                grid: { display: false },
                ticks: { font: { size: 11 }, maxRotation: 40 }
            },
            y: {
                stacked: true,
                grid: { color: '#f0f0f0' },
                ticks: { stepSize: 1 }
            }
        }
    }
});
<?php endif; ?>

// -- Qualification Types Pie --
<?php if (!empty($qual_types)): ?>
new Chart(document.getElementById('qualChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo $qt_labels; ?>,
        datasets: [{
            data: <?php echo $qt_counts; ?>,
            backgroundColor: [accent, teal, purple, amber, rose, green, navy, '#E91E8C','#FF6B35'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        cutout: '60%',
        plugins: {
            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` }}
        }
    }
});
<?php endif; ?>
</script>

</body>
</html>