<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// -- Filters -------------------------------------------------------------------
$period   = isset($_GET['period'])   ? mysqli_real_escape_string($conn, $_GET['period'])   : '';
$county   = isset($_GET['county'])   ? mysqli_real_escape_string($conn, $_GET['county'])   : '';
$agency   = isset($_GET['agency'])   ? mysqli_real_escape_string($conn, $_GET['agency'])   : '';

$where = "WHERE 1=1";
if ($period) $where .= " AND assessment_period = '$period'";
if ($county) $where .= " AND county_name = '$county'";
if ($agency) $where .= " AND agency = '$agency'";

// -- Filter options ------------------------------------------------------------
$periods  = [];
$pr = mysqli_query($conn, "SELECT DISTINCT assessment_period FROM integration_assessments ORDER BY assessment_period DESC");
if ($pr) while ($r = mysqli_fetch_assoc($pr)) $periods[] = $r['assessment_period'];

$counties = [];
$cr = mysqli_query($conn, "SELECT DISTINCT county_name FROM integration_assessments WHERE county_name != '' ORDER BY county_name");
if ($cr) while ($r = mysqli_fetch_assoc($cr)) $counties[] = $r['county_name'];

$agencies = [];
$ar = mysqli_query($conn, "SELECT DISTINCT agency FROM integration_assessments WHERE agency != '' ORDER BY agency");
if ($ar) while ($r = mysqli_fetch_assoc($ar)) $agencies[] = $r['agency'];

// -- Helper: Yes/No stats for a field -----------------------------------------
function yn_stats($conn, $field, $where) {
    $r = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN $field='Yes' THEN 1 ELSE 0 END) AS yes_count,
            SUM(CASE WHEN $field='No'  THEN 1 ELSE 0 END) AS no_count
         FROM integration_assessments $where"));
    $total = (int)$r['total'];
    $yes   = (int)$r['yes_count'];
    $no    = (int)$r['no_count'];
    $pct   = $total > 0 ? round($yes / $total * 100) : 0;
    return ['total'=>$total,'yes'=>$yes,'no'=>$no,'pct'=>$pct];
}

// -- Summary KPIs -------------------------------------------------------------
$total_assessments = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM integration_assessments $where"))['c'];

$total_facilities  = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT facility_id) c FROM integration_assessments $where"))['c'];

$totals = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        SUM(tx_curr) AS tx_curr,
        SUM(tx_curr_pmtct) AS tx_curr_pmtct,
        SUM(plhiv_integrated_care) AS plhiv_integrated,
        SUM(plhiv_enrolled_sha) AS plhiv_sha,
        SUM(plhiv_sha_premium_paid) AS plhiv_sha_paid,
        SUM(pbfw_enrolled_sha) AS pbfw_sha,
        SUM(hcw_total_pepfar) AS hcw_pepfar,
        SUM(hcw_transitioned_clinical + hcw_transitioned_nonclinical +
            hcw_transitioned_data + hcw_transitioned_community +
            hcw_transitioned_other) AS hcw_transitioned,
        SUM(deaths_all_cause) AS deaths_all,
        SUM(deaths_hiv_related) AS deaths_hiv,
        SUM(deaths_tb) AS deaths_tb,
        SUM(deaths_maternal) AS deaths_maternal,
        SUM(ta_visits_total) AS ta_visits,
        SUM(ta_visits_moh_only) AS ta_moh
     FROM integration_assessments $where"));

// -- Section 2a Yes/No ---------------------------------------------------------
$hiv_tb   = yn_stats($conn, 'hiv_tb_integrated', $where);
$usdos    = yn_stats($conn, 'supported_by_usdos_ip', $where);
$art_site = yn_stats($conn, 'is_art_site', $where);

// -- Section 2b Yes/No ---------------------------------------------------------
$s2b_fields = [
    'pmtct_integrated_mnch' => 'PMTCT integrated in MNCH',
    'hts_integrated_opd'    => 'HTS integrated in OPD',
    'hts_integrated_ipd'    => 'HTS integrated in IPD',
    'hts_integrated_mnch'   => 'HTS integrated in MNCH',
    'prep_integrated_opd'   => 'PrEP integrated in OPD',
    'prep_integrated_ipd'   => 'PrEP integrated in IPD',
    'prep_integrated_mnch'  => 'PrEP integrated in MNCH',
];
$s2b = [];
foreach ($s2b_fields as $f => $l) $s2b[$f] = ['label'=>$l] + yn_stats($conn, $f, $where);

// -- Section 2c EMR Yes/No -----------------------------------------------------
$emr_fields = [
    'uses_emr'               => 'Uses any EMR',
    'single_unified_emr'     => 'Single Unified EMR',
    'emr_at_opd'             => 'EMR at OPD',
    'emr_at_ipd'             => 'EMR at IPD',
    'emr_at_mnch'            => 'EMR at MNCH',
    'emr_at_ccc'             => 'EMR at CCC',
    'emr_at_pmtct'           => 'EMR at PMTCT',
    'emr_at_lab'             => 'EMR at Lab',
    'emr_at_pharmacy'        => 'EMR at Pharmacy',
    'lab_manifest_in_use'    => 'Lab Manifest in Use',
    'pharmacy_webadt_in_use' => 'Pharmacy WebADT in Use',
    'emr_interoperable_his'  => 'EMR Interoperable with HIS',
];
$s2c = [];
foreach ($emr_fields as $f => $l) $s2c[$f] = ['label'=>$l] + yn_stats($conn, $f, $where);

// Tibu Lite breakdown
$tibu = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN tibu_lite_lims_in_use='Yes' THEN 1 ELSE 0 END) AS yes_c,
        SUM(CASE WHEN tibu_lite_lims_in_use='No' THEN 1 ELSE 0 END) AS no_c,
        SUM(CASE WHEN tibu_lite_lims_in_use='Partial' THEN 1 ELSE 0 END) AS partial_c,
        COUNT(*) AS total
     FROM integration_assessments $where"));

// EMR type distribution from child table
// Build a separate WHERE for the JOIN query prefixing ia. on filter columns
$emr_where = "WHERE emr_type != ''";
if ($period) $emr_where .= " AND ia.assessment_period = '$period'";
if ($county) $emr_where .= " AND ia.county_name = '$county'";
if ($agency) $emr_where .= " AND ia.agency = '$agency'";

$emr_types = [];
$etr = mysqli_query($conn,
    "SELECT emr_type, COUNT(*) cnt
     FROM integration_assessment_emr_systems es
     JOIN integration_assessments ia ON ia.assessment_id = es.assessment_id
     $emr_where
     GROUP BY emr_type ORDER BY cnt DESC LIMIT 12");
if ($etr) while ($r = mysqli_fetch_assoc($etr)) $emr_types[] = $r;

// No EMR reasons
$no_emr_data = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        SUM(CASE WHEN no_emr_reasons LIKE '%No hardware%'    THEN 1 ELSE 0 END) AS hw,
        SUM(CASE WHEN no_emr_reasons LIKE '%No internet%'    THEN 1 ELSE 0 END) AS net,
        SUM(CASE WHEN no_emr_reasons LIKE '%No electricity%' THEN 1 ELSE 0 END) AS elec,
        SUM(CASE WHEN no_emr_reasons LIKE '%No trained%'     THEN 1 ELSE 0 END) AS staff,
        SUM(CASE WHEN no_emr_reasons LIKE '%Private%'          THEN 1 ELSE 0 END) AS other_r
     FROM integration_assessments $where AND uses_emr='No'"));

// -- Section 3 HRH -------------------------------------------------------------
$hrh = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        SUM(hcw_total_pepfar) AS total_pepfar,
        SUM(hcw_clinical_pepfar) AS clinical,
        SUM(hcw_nonclinical_pepfar) AS nonclinical,
        SUM(hcw_data_pepfar) AS data_s,
        SUM(hcw_community_pepfar) AS community,
        SUM(hcw_other_pepfar) AS other_p,
        SUM(hcw_transitioned_clinical) AS t_clinical,
        SUM(hcw_transitioned_nonclinical) AS t_nonclinical,
        SUM(hcw_transitioned_data) AS t_data,
        SUM(hcw_transitioned_community) AS t_community,
        SUM(hcw_transitioned_other) AS t_other
     FROM integration_assessments $where"));

// -- Section 4/5/6 Yes/No -----------------------------------------------------
$s456_yn = [
    'sha_claims_submitted_ontime' => 'SHA Claims Submitted On Time',
    'sha_reimbursements_monthly'  => 'SHA Reimbursements Received Monthly',
    'fif_collection_in_place'     => 'FIF Collection Mechanism in Place',
    'fif_includes_hiv_tb_pmtct'   => 'FIF Includes HIV/TB/PMTCT Services',
    'sha_capitation_hiv_tb'       => 'Receiving SHA Capitation for HIV/TB',
];
$s456 = [];
foreach ($s456_yn as $f => $l) $s456[$f] = ['label'=>$l] + yn_stats($conn, $f, $where);

// -- County breakdown ----------------------------------------------------------
$county_data = [];
$cdr = mysqli_query($conn,
    "SELECT county_name,
        COUNT(*) AS cnt,
        SUM(CASE WHEN hiv_tb_integrated='Yes' THEN 1 ELSE 0 END) AS hiv_tb_yes,
        SUM(CASE WHEN uses_emr='Yes' THEN 1 ELSE 0 END) AS emr_yes,
        SUM(tx_curr) AS tx_curr,
        SUM(plhiv_enrolled_sha) AS sha_enroll
     FROM integration_assessments $where
     GROUP BY county_name ORDER BY cnt DESC LIMIT 15");
if ($cdr) while ($r = mysqli_fetch_assoc($cdr)) $county_data[] = $r;

// -- TA visits: MOH-only vs Total ---------------------------------------------
$ta_total   = (int)($totals['ta_visits'] ?? 0);
$ta_moh     = (int)($totals['ta_moh'] ?? 0);
$ta_moh_pct = $ta_total > 0 ? round($ta_moh / $ta_total * 100) : 0;

// -- JSON for charts -----------------------------------------------------------
$s2b_labels  = json_encode(array_values(array_map(fn($v)=>$v['label'], $s2b)));
$s2b_pcts    = json_encode(array_values(array_map(fn($v)=>$v['pct'], $s2b)));

$emr_labels  = json_encode(array_values(array_map(fn($v)=>$v['label'], $s2c)));
$emr_pcts    = json_encode(array_values(array_map(fn($v)=>$v['pct'], $s2c)));

$emr_type_labels = json_encode(array_column($emr_types, 'emr_type'));
$emr_type_counts = json_encode(array_column($emr_types, 'cnt'));

$hrh_pepfar_data = json_encode([
    (int)($hrh['clinical']??0),
    (int)($hrh['nonclinical']??0),
    (int)($hrh['data_s']??0),
    (int)($hrh['community']??0),
    (int)($hrh['other_p']??0),
]);
$hrh_trans_data = json_encode([
    (int)($hrh['t_clinical']??0),
    (int)($hrh['t_nonclinical']??0),
    (int)($hrh['t_data']??0),
    (int)($hrh['t_community']??0),
    (int)($hrh['t_other']??0),
]);
$hrh_labels = json_encode(['Clinical','Non-Clinical','Data','Community','Other']);

$county_labels = json_encode(array_column($county_data, 'county_name'));
$county_counts = json_encode(array_column($county_data, 'cnt'));

$mort_data = json_encode([
    (int)($totals['deaths_all']??0),
    (int)($totals['deaths_hiv']??0),
    (int)($totals['deaths_tb']??0),
    (int)($totals['deaths_maternal']??0),
]);

$no_emr_labels = json_encode(['No Hardware','No Internet','No Electricity','No Trained Staff','Other']);
$no_emr_vals   = json_encode([
    (int)($no_emr_data['hw']??0),
    (int)($no_emr_data['net']??0),
    (int)($no_emr_data['elec']??0),
    (int)($no_emr_data['staff']??0),
    (int)($no_emr_data['other_r']??0),
]);

$s456_labels = json_encode(array_values(array_map(fn($v)=>$v['label'], $s456)));
$s456_pcts   = json_encode(array_values(array_map(fn($v)=>$v['pct'], $s456)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Integration Assessment Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
    --navy:   #0D1A63;
    --navy2:  #1a3a9e;
    --teal:   #0ABFBC;
    --green:  #27AE60;
    --amber:  #F5A623;
    --rose:   #E74C3C;
    --purple: #8B5CF6;
    --blue:   #3B82F6;
    --bg:     #EEF2F9;
    --card:   #FFFFFF;
    --border: #E2E8F0;
    --muted:  #6B7280;
    --text:   #1A1E2E;
    --shadow: 0 2px 16px rgba(13,26,99,.08);
    --radius: 14px;
}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* Top bar */
.topbar{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 16px rgba(0,0,0,.2);position:sticky;top:0;z-index:100;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.topbar-title{font-size:16px;font-weight:700;}
.topbar-sub{font-size:11px;opacity:.65;margin-top:1px;}
.topbar-right{display:flex;align-items:center;gap:14px;}
.topbar-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;padding:6px 14px;border-radius:7px;font-size:12px;text-decoration:none;transition:.2s;display:inline-flex;align-items:center;gap:6px;}
.topbar-btn:hover{background:rgba(255,255,255,.25);}

/* Page */
.page{padding:24px 28px;max-width:1600px;margin:0 auto;}

/* Filters */
.filters{background:var(--card);border-radius:var(--radius);padding:16px 20px;margin-bottom:22px;box-shadow:var(--shadow);display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
.filter-group select{padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;font-family:inherit;color:var(--text);min-width:160px;}
.filter-group select:focus{outline:none;border-color:var(--navy);}
.filter-btn{background:var(--navy);color:#fff;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;align-self:flex-end;transition:.2s;display:flex;align-items:center;gap:7px;}
.filter-btn:hover{background:var(--navy2);}
.filter-clear{background:#f3f4f6;color:var(--muted);border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;align-self:flex-end;text-decoration:none;display:flex;align-items:center;gap:6px;}

/* Section label */
.section-label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin:26px 0 14px;display:flex;align-items:center;gap:10px;}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* KPI grid */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;}
.kpi{background:var(--card);border-radius:var(--radius);padding:18px 20px;box-shadow:var(--shadow);border-top:4px solid var(--kc,var(--navy));transition:transform .2s;}
.kpi:hover{transform:translateY(-2px);}
.kpi-icon{width:38px;height:38px;border-radius:10px;background:var(--kbg,rgba(13,26,99,.08));display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--kc,var(--navy));margin-bottom:12px;}
.kpi-val{font-size:28px;font-weight:800;line-height:1;color:var(--text);font-variant-numeric:tabular-nums;}
.kpi-lbl{font-size:12px;color:var(--muted);margin-top:4px;font-weight:500;}

/* Cards */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.card-head{padding:15px 20px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.card-head h3{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-head h3 i{width:26px;height:26px;background:rgba(13,26,99,.08);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--navy);}
.card-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;background:var(--bg);color:var(--muted);}
.card-body{padding:18px 20px;}

/* Grid layouts */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;}
.grid-32{display:grid;grid-template-columns:3fr 2fr;gap:18px;}
.grid-23{display:grid;grid-template-columns:2fr 3fr;gap:18px;}

/* Yes/No bar rows */
.yn-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);}
.yn-row:last-child{border-bottom:none;}
.yn-label{font-size:13px;color:var(--text);font-weight:500;flex:1;min-width:0;}
.yn-pct-val{font-size:13px;font-weight:700;width:38px;text-align:right;flex-shrink:0;}
.yn-bar-wrap{width:140px;flex-shrink:0;}
.yn-bar-track{height:8px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.yn-bar-fill{height:100%;border-radius:99px;transition:width 1s cubic-bezier(.4,0,.2,1);}
.yn-count{font-size:11px;color:var(--muted);width:55px;flex-shrink:0;text-align:right;}

/* Colour helpers */
.fill-green{background:var(--green);}
.fill-amber{background:var(--amber);}
.fill-rose{background:var(--rose);}
.fill-blue{background:var(--blue);}
.fill-navy{background:var(--navy);}
.fill-teal{background:var(--teal);}
.fill-purple{background:var(--purple);}

/* County table */
.dash-table{width:100%;border-collapse:collapse;font-size:13px;}
.dash-table th{padding:9px 12px;background:#f8fafc;font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);text-align:left;}
.dash-table td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;}
.dash-table tr:last-child td{border-bottom:none;}
.dash-table tr:hover td{background:#f8fafc;}

/* Mini bar in table */
.mini-bar-wrap{display:flex;align-items:center;gap:7px;}
.mini-bar-track{flex:1;height:7px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.mini-bar-fill{height:100%;border-radius:99px;}

/* Pill */
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.pill-green{background:#D1FAE5;color:#065F46;}
.pill-amber{background:#FEF3C7;color:#92400E;}
.pill-rose{background:#FEE2E2;color:#991B1B;}
.pill-blue{background:#DBEAFE;color:#1E40AF;}
.pill-purple{background:#EDE9FE;color:#5B21B6;}
.pill-gray{background:#F3F4F6;color:#374151;}

/* Chart wrap */
.chart-wrap{position:relative;}
.chart-wrap canvas{max-width:100%;}

/* Progress ring */
.ring-wrap{display:flex;flex-direction:column;align-items:center;gap:4px;}
.ring-label{font-size:12px;color:var(--muted);font-weight:500;text-align:center;}

/* HRH comparison */
.hrh-compare{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
.hrh-label{font-size:13px;font-weight:500;flex:1;}
.hrh-bars{flex:2;display:flex;flex-direction:column;gap:3px;}
.hrh-bar{height:9px;border-radius:99px;}
.hrh-vals{font-size:12px;color:var(--muted);width:90px;text-align:right;flex-shrink:0;}

/* Tibu breakdown */
.tibu-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border);}
.tibu-row:last-child{border-bottom:none;}

/* Responsive */
@media(max-width:1100px){.grid-32,.grid-23{grid-template-columns:1fr;}}
@media(max-width:800px){.grid-2,.grid-3{grid-template-columns:1fr;}.page{padding:14px;}.topbar{padding:0 14px;}}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-logo"><i class="fas fa-clipboard-check"></i></div>
        <div>
            <div class="topbar-title">Integration Assessment Dashboard</div>
            <div class="topbar-sub">HIV Services, EMR, HRH &amp; Sustainability Analytics</div>
        </div>
    </div>
    <div class="topbar-right">
        <a href="integration_assessment.php" class="topbar-btn"><i class="fas fa-plus"></i> New Assessment</a>
        <a href="integration_assessment_list.php" class="topbar-btn"><i class="fas fa-list"></i> All Assessments</a>
    </div>
</div>

<div class="page">

<!-- -- FILTERS -- -->
<form method="GET" id="filterForm">
    <div class="filters">
        <div class="filter-group">
            <label>Period</label>
            <select name="period">
                <option value="">All Periods</option>
                <?php foreach ($periods as $p): ?>
                <option value="<?= htmlspecialchars($p) ?>" <?= $period===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>County</label>
            <select name="county">
                <option value="">All Counties</option>
                <?php foreach ($counties as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $county===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Agency</label>
            <select name="agency">
                <option value="">All Agencies</option>
                <?php foreach ($agencies as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $agency===$a?'selected':'' ?>><?= htmlspecialchars($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply</button>
        <a href="integration_dashboard.php" class="filter-clear"><i class="fas fa-times"></i> Clear</a>
    </div>
</form>

<!-- -- KPI ROW -- -->
<div class="section-label">Overview</div>
<div class="kpi-grid">
    <div class="kpi" style="--kc:var(--navy);--kbg:rgba(13,26,99,.08)">
        <div class="kpi-icon"><i class="fas fa-hospital"></i></div>
        <div class="kpi-val"><?= number_format($total_facilities) ?></div>
        <div class="kpi-lbl">Facilities Assessed</div>
    </div>
    <div class="kpi" style="--kc:var(--blue);--kbg:rgba(59,130,246,.1)">
        <div class="kpi-icon"><i class="fas fa-clipboard-check"></i></div>
        <div class="kpi-val"><?= number_format($total_assessments) ?></div>
        <div class="kpi-lbl">Total Assessments</div>
    </div>
    <div class="kpi" style="--kc:var(--teal);--kbg:rgba(10,191,188,.1)">
        <div class="kpi-icon"><i class="fas fa-pills"></i></div>
        <div class="kpi-val"><?= number_format($totals['tx_curr']??0) ?></div>
        <div class="kpi-lbl">TX_CURR Total</div>
    </div>
    <div class="kpi" style="--kc:var(--purple);--kbg:rgba(139,92,246,.1)">
        <div class="kpi-icon"><i class="fas fa-id-card"></i></div>
        <div class="kpi-val"><?= number_format($totals['plhiv_sha']??0) ?></div>
        <div class="kpi-lbl">PLHIVs Enrolled SHA</div>
    </div>
    <div class="kpi" style="--kc:var(--green);--kbg:rgba(39,174,96,.1)">
        <div class="kpi-icon"><i class="fas fa-users"></i></div>
        <div class="kpi-val"><?= number_format($totals['hcw_pepfar']??0) ?></div>
        <div class="kpi-lbl">HCWs (PEPFAR)</div>
    </div>
    <div class="kpi" style="--kc:var(--amber);--kbg:rgba(245,166,35,.1)">
        <div class="kpi-icon"><i class="fas fa-exchange-alt"></i></div>
        <div class="kpi-val"><?= number_format($totals['hcw_transitioned']??0) ?></div>
        <div class="kpi-lbl">HCWs Transitioned</div>
    </div>
    <div class="kpi" style="--kc:var(--rose);--kbg:rgba(231,76,60,.1)">
        <div class="kpi-icon"><i class="fas fa-heartbeat"></i></div>
        <div class="kpi-val"><?= number_format($totals['deaths_all']??0) ?></div>
        <div class="kpi-lbl">All-Cause Deaths</div>
    </div>
    <div class="kpi" style="--kc:var(--teal);--kbg:rgba(10,191,188,.1)">
        <div class="kpi-icon"><i class="fas fa-chalkboard-teacher"></i></div>
        <div class="kpi-val"><?= number_format($ta_total) ?></div>
        <div class="kpi-lbl">TA/Mentorship Visits</div>
    </div>
</div>

<!-- -- SECTION 1: FACILITY PROFILE INDICATORS -- -->
<div class="section-label">Section 1 &mdash; Facility Profile</div>
<div class="grid-3">
    <?php
    $s1_cards = [
        ['US DoS IP Support', $usdos, 'fa-flag', 'fill-blue'],
        ['ART Sites',         $art_site, 'fa-pills', 'fill-green'],
        ['HIV/TB Integrated', $hiv_tb,   'fa-virus', 'fill-teal'],
    ];
    foreach ($s1_cards as [$title, $d, $icon, $fill]):
        $pct = $d['pct'];
        $col = $pct >= 70 ? 'fill-green' : ($pct >= 40 ? 'fill-amber' : 'fill-rose');
    ?>
    <div class="card">
        <div class="card-head">
            <h3><i class="fas <?= $icon ?>"></i> <?= $title ?></h3>
            <span class="card-badge <?= $pct>=70?'':''; ?>"><?= $d['total'] ?> sites</span>
        </div>
        <div class="card-body" style="text-align:center;padding:28px 20px">
            <div style="font-size:52px;font-weight:900;color:var(--<?= $pct>=70?'green':($pct>=40?'amber':'rose') ?>);line-height:1"><?= $pct ?>%</div>
            <div style="font-size:13px;color:var(--muted);margin:8px 0 16px">Reporting Yes</div>
            <div style="display:flex;justify-content:center;gap:24px">
                <div><div style="font-size:20px;font-weight:700;color:var(--green)"><?= $d['yes'] ?></div><div style="font-size:11px;color:var(--muted)">YES</div></div>
                <div><div style="font-size:20px;font-weight:700;color:var(--rose)"><?= $d['no'] ?></div><div style="font-size:11px;color:var(--muted)">NO</div></div>
            </div>
            <div style="margin-top:14px;height:10px;background:#f0f0f0;border-radius:99px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:var(--<?= $pct>=70?'green':($pct>=40?'amber':'rose') ?>);border-radius:99px;transition:width 1.2s"></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- -- SECTION 2b: INTEGRATION Yes/No percentages -- -->
<div class="section-label">Section 2b &mdash; Service Integration (% Facilities Reporting Yes)</div>
<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-chart-bar"></i> Integration by Service &amp; Department</h3></div>
        <div class="card-body">
            <div class="chart-wrap" style="height:260px">
                <canvas id="s2bChart"></canvas>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-list-check"></i> Detailed Breakdown</h3></div>
        <div class="card-body">
            <?php foreach ($s2b as $f => $d):
                $pct = $d['pct'];
                $fill = $pct>=70?'fill-green':($pct>=40?'fill-amber':'fill-rose');
            ?>
            <div class="yn-row">
                <div class="yn-label"><?= $d['label'] ?></div>
                <div class="yn-bar-wrap">
                    <div class="yn-bar-track">
                        <div class="yn-bar-fill <?= $fill ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <div class="yn-pct-val" style="color:var(--<?= $pct>=70?'green':($pct>=40?'amber':'rose') ?>)"><?= $pct ?>%</div>
                <div class="yn-count"><?= $d['yes'] ?> / <?= $d['total'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- TX_CURR -->
<div class="section-label">Section 2a &mdash; HIV/TB Treatment Numbers</div>
<div class="grid-3">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-pills"></i> TX_CURR</h3></div>
        <div class="card-body" style="text-align:center;padding:28px">
            <div style="font-size:42px;font-weight:900;color:var(--navy)"><?= number_format($totals['tx_curr']??0) ?></div>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">Total PLHIV on ART</div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-baby"></i> TX_CURR PMTCT</h3></div>
        <div class="card-body" style="text-align:center;padding:28px">
            <div style="font-size:42px;font-weight:900;color:var(--teal)"><?= number_format($totals['tx_curr_pmtct']??0) ?></div>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">PMTCT clients on ART</div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-handshake"></i> PLHIVs — Integrated Care</h3></div>
        <div class="card-body" style="text-align:center;padding:28px">
            <div style="font-size:42px;font-weight:900;color:var(--purple)"><?= number_format($totals['plhiv_integrated']??0) ?></div>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">Receiving care through integrated models</div>
        </div>
    </div>
</div>

<!-- -- SECTION 2c: EMR -- -->
<div class="section-label">Section 2c &mdash; EMR Integration</div>
<div class="grid-32">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-laptop-medical"></i> EMR Coverage by Department</h3></div>
        <div class="card-body">
            <?php foreach ($s2c as $f => $d):
                $pct = $d['pct'];
                $fill = $pct>=70?'fill-green':($pct>=40?'fill-amber':'fill-rose');
            ?>
            <div class="yn-row">
                <div class="yn-label"><?= $d['label'] ?></div>
                <div class="yn-bar-wrap" style="width:160px">
                    <div class="yn-bar-track">
                        <div class="yn-bar-fill <?= $fill ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <div class="yn-pct-val" style="color:var(--<?= $pct>=70?'green':($pct>=40?'amber':'rose') ?>)"><?= $pct ?>%</div>
                <div class="yn-count"><?= $d['yes'] ?>/<?= $d['total'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:18px">
        <!-- EMR Types -->
        <div class="card">
            <div class="card-head"><h3><i class="fas fa-database"></i> EMR Systems in Use</h3></div>
            <div class="card-body">
                <?php if (empty($emr_types)): ?>
                <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px 0">No EMR data yet</p>
                <?php else: ?>
                <div class="chart-wrap" style="height:180px"><canvas id="emrTypeChart"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tibu Lite + No EMR reasons -->
        <div class="card">
            <div class="card-head"><h3><i class="fas fa-flask"></i> Tibu Lite (LIMS)</h3></div>
            <div class="card-body">
                <?php
                $tibu_total = (int)($tibu['total']??0);
                $tibu_yes = (int)($tibu['yes_c']??0);
                $tibu_no  = (int)($tibu['no_c']??0);
                $tibu_part= (int)($tibu['partial_c']??0);
                ?>
                <div class="tibu-row">
                    <span style="font-size:13px;font-weight:500">In Use</span>
                    <span class="pill pill-green"><?= $tibu_yes ?> (<?= $tibu_total>0?round($tibu_yes/$tibu_total*100):0 ?>%)</span>
                </div>
                <div class="tibu-row">
                    <span style="font-size:13px;font-weight:500">Partial Use</span>
                    <span class="pill pill-amber"><?= $tibu_part ?> (<?= $tibu_total>0?round($tibu_part/$tibu_total*100):0 ?>%)</span>
                </div>
                <div class="tibu-row">
                    <span style="font-size:13px;font-weight:500">Not in Use</span>
                    <span class="pill pill-rose"><?= $tibu_no ?> (<?= $tibu_total>0?round($tibu_no/$tibu_total*100):0 ?>%)</span>
                </div>
            </div>
        </div>

        <!-- No EMR reasons -->
        <?php if (array_sum(array_map('intval', $no_emr_data ?? [])) > 0): ?>
        <div class="card">
            <div class="card-head"><h3><i class="fas fa-exclamation-circle"></i> Barriers to EMR Adoption</h3></div>
            <div class="card-body">
                <div class="chart-wrap" style="height:140px"><canvas id="noEmrChart"></canvas></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- -- SECTION 3: HRH -- -->
<div class="section-label">Section 3 &mdash; HRH Transition (Workforce Absorption)</div>
<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <h3><i class="fas fa-users"></i> HCW Breakdown: PEPFAR-Supported vs Transitioned</h3>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="height:240px"><canvas id="hrhChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-exchange-alt"></i> Transition Progress by Category</h3></div>
        <div class="card-body">
            <?php
            $hrh_cats = [
                ['Clinical',     (int)($hrh['clinical']??0),     (int)($hrh['t_clinical']??0)],
                ['Non-Clinical', (int)($hrh['nonclinical']??0),  (int)($hrh['t_nonclinical']??0)],
                ['Data',         (int)($hrh['data_s']??0),       (int)($hrh['t_data']??0)],
                ['Community',    (int)($hrh['community']??0),    (int)($hrh['t_community']??0)],
                ['Other',        (int)($hrh['other_p']??0),      (int)($hrh['t_other']??0)],
            ];
            foreach ($hrh_cats as [$cat, $pepfar, $trans]):
                $pct = $pepfar > 0 ? round($trans / $pepfar * 100) : 0;
                $pct_col = $pct>=70?'var(--green)':($pct>=40?'var(--amber)':'var(--rose)');
            ?>
            <div class="hrh-compare">
                <div class="hrh-label"><?= $cat ?></div>
                <div class="hrh-bars">
                    <div class="hrh-bar" style="width:<?= $pepfar>0?'100%':'0%' ?>;background:rgba(13,26,99,.15);max-width:100%"></div>
                    <div class="hrh-bar" style="width:<?= $pepfar>0?round($trans/$pepfar*100).'%':'0%' ?>;background:<?= $pct_col ?>;margin-top:2px"></div>
                </div>
                <div class="hrh-vals">
                    <span style="color:var(--navy)"><?= $pepfar ?></span>
                    &rarr; <span style="color:<?= $pct_col ?>"><?= $trans ?></span>
                    <span style="color:var(--muted);font-size:10px"> (<?= $pct ?>%)</span>
                </div>
            </div>
            <?php endforeach; ?>
            <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);display:flex;gap:20px;font-size:12px">
                <span><span style="display:inline-block;width:10px;height:10px;background:rgba(13,26,99,.15);border-radius:2px;margin-right:4px"></span>PEPFAR-Supported</span>
                <span><span style="display:inline-block;width:10px;height:10px;background:var(--green);border-radius:2px;margin-right:4px"></span>Transitioned to County</span>
            </div>
        </div>
    </div>
</div>

<!-- -- SECTION 4: SHA Enrollment -- -->
<div class="section-label">Section 4 &mdash; PLHIV &amp; PBFW SHA Enrollment</div>
<div class="kpi-grid">
    <?php
    $sha_kpis = [
        ['PLHIVs Enrolled in SHA',       $totals['plhiv_sha']??0,     'fa-id-card',  'purple'],
        ['PLHIVs — Premium Paid',         $totals['plhiv_sha_paid']??0, 'fa-check-circle','green'],
        ['PBFW Enrolled in SHA',          $totals['pbfw_sha']??0,      'fa-baby',     'teal'],
        ['PBFW — Premium Paid',           $totals['pbfw_sha_paid']??0 ?? 0,'fa-check','amber'],
    ];
    foreach ($sha_kpis as [$lbl,$val,$icon,$col]): ?>
    <div class="kpi" style="--kc:var(--<?= $col ?>);--kbg:rgba(0,0,0,.05)">
        <div class="kpi-icon"><i class="fas <?= $icon ?>"></i></div>
        <div class="kpi-val"><?= number_format($val) ?></div>
        <div class="kpi-lbl"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- SHA & FIF Yes/No -->
<div class="section-label">Sections 4–6 &mdash; SHA, TA &amp; Financing (% Facilities Reporting Yes)</div>
<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-chart-bar"></i> SHA, FIF &amp; Capitation Indicators</h3></div>
        <div class="card-body">
            <div class="chart-wrap" style="height:220px"><canvas id="s456Chart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-list-check"></i> Detailed Breakdown</h3></div>
        <div class="card-body">
            <?php foreach ($s456 as $f => $d):
                $pct = $d['pct'];
                $fill = $pct>=70?'fill-green':($pct>=40?'fill-amber':'fill-rose');
            ?>
            <div class="yn-row">
                <div class="yn-label"><?= $d['label'] ?></div>
                <div class="yn-bar-wrap">
                    <div class="yn-bar-track">
                        <div class="yn-bar-fill <?= $fill ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <div class="yn-pct-val" style="color:var(--<?= $pct>=70?'green':($pct>=40?'amber':'rose') ?>)"><?= $pct ?>%</div>
                <div class="yn-count"><?= $d['yes'] ?>/<?= $d['total'] ?></div>
            </div>
            <?php endforeach; ?>

            <!-- TA MOH only % -->
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
                <div style="font-size:13px;font-weight:600;margin-bottom:8px">TA Visits by MOH Only vs Total</div>
                <div style="display:flex;gap:10px;align-items:center">
                    <div style="flex:1;height:10px;background:#f0f0f0;border-radius:99px;overflow:hidden">
                        <div style="width:<?= $ta_moh_pct ?>%;height:100%;background:var(--navy);border-radius:99px;transition:width 1.2s"></div>
                    </div>
                    <span style="font-size:13px;font-weight:700;color:var(--navy);width:38px"><?= $ta_moh_pct ?>%</span>
                    <span style="font-size:12px;color:var(--muted)"><?= number_format($ta_moh) ?>/<?= number_format($ta_total) ?></span>
                </div>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">MOH-only visits as % of total TA visits</div>
            </div>
        </div>
    </div>
</div>

<!-- -- SECTION 7: MORTALITY -- -->
<div class="section-label">Section 7 &mdash; Mortality Outcomes</div>
<div class="grid-32">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-heartbeat"></i> Mortality Summary</h3></div>
        <div class="card-body">
            <div class="chart-wrap" style="height:240px"><canvas id="mortChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-table"></i> Deaths by Cause</h3></div>
        <div class="card-body">
            <?php
            $mort_rows = [
                ['All-Cause Mortality', $totals['deaths_all']??0,      'rose'],
                ['HIV Related',         $totals['deaths_hiv']??0,      'amber'],
                ['HIV — Pre-ART',       $totals['deaths_hiv_pre_art']??0,'purple'],
                ['TB Related',          $totals['deaths_tb']??0,       'blue'],
                ['Maternal',            $totals['deaths_maternal']??0,  'teal'],
                ['Perinatal',           $totals['deaths_perinatal']??0, 'navy'],
            ];
            $max_mort = max(array_column($mort_rows, 1) ?: [1]);
            foreach ($mort_rows as [$lbl,$val,$col]):
                $pct = $max_mort > 0 ? round($val/$max_mort*100) : 0;
            ?>
            <div class="yn-row">
                <div class="yn-label"><?= $lbl ?></div>
                <div class="yn-bar-wrap" style="width:160px">
                    <div class="yn-bar-track">
                        <div class="yn-bar-fill fill-<?= $col ?>" style="width:<?= $pct ?>%"></div>
                    </div>
                </div>
                <div class="yn-pct-val" style="color:var(--<?= $col ?>);font-size:15px;font-weight:800"><?= number_format($val) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- -- COUNTY BREAKDOWN -- -->
<?php if (!empty($county_data)): ?>
<div class="section-label">County Breakdown</div>
<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-map-marked-alt"></i> Performance by County</h3>
        <span class="card-badge"><?= count($county_data) ?> counties</span>
    </div>
    <div style="overflow-x:auto">
        <table class="dash-table">
            <thead>
                <tr>
                    <th>County</th>
                    <th>Assessments</th>
                    <th>HIV/TB Integrated</th>
                    <th>EMR in Use</th>
                    <th>TX_CURR</th>
                    <th>PLHIV SHA Enrolled</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $max_cnt = max(array_column($county_data, 'cnt') ?: [1]);
            foreach ($county_data as $cd):
                $hiv_pct = $cd['cnt']>0 ? round($cd['hiv_tb_yes']/$cd['cnt']*100) : 0;
                $emr_pct = $cd['cnt']>0 ? round($cd['emr_yes']/$cd['cnt']*100)    : 0;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($cd['county_name']) ?></strong></td>
                <td>
                    <div class="mini-bar-wrap">
                        <div class="mini-bar-track">
                            <div class="mini-bar-fill" style="width:<?= round($cd['cnt']/$max_cnt*100) ?>%;background:var(--navy)"></div>
                        </div>
                        <strong><?= $cd['cnt'] ?></strong>
                    </div>
                </td>
                <td>
                    <span class="pill <?= $hiv_pct>=70?'pill-green':($hiv_pct>=40?'pill-amber':'pill-rose') ?>">
                        <?= $hiv_pct ?>%
                    </span>
                    <span style="color:var(--muted);font-size:11px"> <?= $cd['hiv_tb_yes'] ?>/<?= $cd['cnt'] ?></span>
                </td>
                <td>
                    <span class="pill <?= $emr_pct>=70?'pill-green':($emr_pct>=40?'pill-amber':'pill-rose') ?>">
                        <?= $emr_pct ?>%
                    </span>
                    <span style="color:var(--muted);font-size:11px"> <?= $cd['emr_yes'] ?>/<?= $cd['cnt'] ?></span>
                </td>
                <td><?= number_format($cd['tx_curr']) ?></td>
                <td><?= number_format($cd['sha_enroll']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div style="height:40px"></div>

</div><!-- /page -->

<script>
Chart.defaults.font.family = "'Segoe UI','Helvetica Neue',Arial,sans-serif";
Chart.defaults.color = '#6B7280';
Chart.defaults.plugins.legend.display = false;

const navy   = '#0D1A63';
const green  = '#27AE60';
const amber  = '#F5A623';
const rose   = '#E74C3C';
const blue   = '#3B82F6';
const teal   = '#0ABFBC';
const purple = '#8B5CF6';

// Colour each bar by value (>=70 green, >=40 amber, else rose)
function colourByPct(pcts) {
    return pcts.map(p => p >= 70 ? green : p >= 40 ? amber : rose);
}

// -- Section 2b chart ---------------------------------------------------------
const s2bPcts   = <?= $s2b_pcts ?>;
const s2bLabels = <?= $s2b_labels ?>;
new Chart(document.getElementById('s2bChart'), {
    type: 'bar',
    data: {
        labels: s2bLabels,
        datasets: [{ data: s2bPcts, backgroundColor: colourByPct(s2bPcts),
            borderRadius: 6, borderSkipped: false }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { tooltip: { callbacks: { label: c => ` ${c.raw}%` } } },
        scales: {
            x: { grid:{display:false}, ticks:{font:{size:10},maxRotation:45} },
            y: { max:100, grid:{color:'#f0f0f0'}, ticks:{callback:v=>v+'%'} }
        }
    }
});

// -- EMR types chart -----------------------------------------------------------
<?php if (!empty($emr_types)): ?>
new Chart(document.getElementById('emrTypeChart'), {
    type: 'doughnut',
    data: {
        labels: <?= $emr_type_labels ?>,
        datasets: [{ data: <?= $emr_type_counts ?>,
            backgroundColor: [navy,teal,green,amber,rose,purple,blue,'#F97316','#EC4899','#14B8A6','#6366F1','#84CC16'],
            borderWidth: 0, hoverOffset: 6 }]
    },
    options: {
        cutout: '60%',
        plugins: { legend: { display: true, position: 'right',
            labels:{boxWidth:10,font:{size:11},borderRadius:3,useBorderRadius:true} } }
    }
});
<?php endif; ?>

// -- No EMR reasons chart ------------------------------------------------------
<?php if (isset($no_emr_data) && array_sum(array_map('intval', $no_emr_data)) > 0): ?>
new Chart(document.getElementById('noEmrChart'), {
    type: 'bar',
    data: {
        labels: <?= $no_emr_labels ?>,
        datasets: [{ data: <?= $no_emr_vals ?>,
            backgroundColor: [rose,amber,purple,blue,teal],
            borderRadius: 6, borderSkipped: false }]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { tooltip: { callbacks: { label: c => ` ${c.raw} facilities` } } },
        scales: {
            x: { grid:{color:'#f0f0f0'}, ticks:{stepSize:1} },
            y: { grid:{display:false}, ticks:{font:{size:11}} }
        }
    }
});
<?php endif; ?>

// -- HRH chart -----------------------------------------------------------------
new Chart(document.getElementById('hrhChart'), {
    type: 'bar',
    data: {
        labels: <?= $hrh_labels ?>,
        datasets: [
            { label: 'PEPFAR-Supported', data: <?= $hrh_pepfar_data ?>,
              backgroundColor: 'rgba(13,26,99,.25)', borderRadius: 5, borderSkipped: false },
            { label: 'Transitioned to County', data: <?= $hrh_trans_data ?>,
              backgroundColor: green, borderRadius: 5, borderSkipped: false }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: true, position: 'top',
                labels:{boxWidth:12,font:{size:12},borderRadius:3,useBorderRadius:true} },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            x: { grid:{display:false} },
            y: { grid:{color:'#f0f0f0'}, ticks:{stepSize:1} }
        }
    }
});

// -- Sections 4–6 chart --------------------------------------------------------
const s456Pcts   = <?= $s456_pcts ?>;
const s456Labels = <?= $s456_labels ?>;
new Chart(document.getElementById('s456Chart'), {
    type: 'bar',
    data: {
        labels: s456Labels,
        datasets: [{ data: s456Pcts, backgroundColor: colourByPct(s456Pcts),
            borderRadius: 6, borderSkipped: false }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { tooltip: { callbacks: { label: c => ` ${c.raw}%` } } },
        scales: {
            x: { grid:{display:false}, ticks:{font:{size:10},maxRotation:40} },
            y: { max:100, grid:{color:'#f0f0f0'}, ticks:{callback:v=>v+'%'} }
        }
    }
});

// -- Mortality chart -----------------------------------------------------------
new Chart(document.getElementById('mortChart'), {
    type: 'bar',
    data: {
        labels: ['All-Cause','HIV Related','TB Deaths','Maternal'],
        datasets: [{ data: <?= $mort_data ?>,
            backgroundColor: [rose, amber, blue, teal],
            borderRadius: 8, borderSkipped: false }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { tooltip: { callbacks: { label: c => ` ${c.raw} deaths` } } },
        scales: {
            x: { grid:{display:false} },
            y: { grid:{color:'#f0f0f0'}, ticks:{stepSize:1} }
        }
    }
});
</script>
</body>
</html>