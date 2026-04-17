<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// -- Multi-select Filters ------------------------------------------------------
$selected_periods  = isset($_GET['periods'])       ? (array)$_GET['periods']       : [];
$selected_counties = isset($_GET['counties'])      ? (array)$_GET['counties']      : [];
$selected_levels   = isset($_GET['levels']  )      ? (array)$_GET['levels']        : [];

// sanitize
$selected_periods  = array_map(fn($v) => mysqli_real_escape_string($conn, $v), $selected_periods);
$selected_counties = array_map(fn($v) => mysqli_real_escape_string($conn, $v), $selected_counties);
$selected_levels   = array_map(fn($v) => mysqli_real_escape_string($conn, $v), $selected_levels);

// -- Compare mode: by period (default) or by county/level ----------------------
// If 2+ periods selected → compare periods side-by-side
// If 1 period + 2+ counties OR 2+ levels → compare those groups side-by-side
$compare_by = 'period'; // default
$groups = []; // each group = ['label'=>..., 'where'=>...]

function build_where(array $periods, array $counties, array $levels): string {
    $w = "WHERE 1=1";
    if ($periods)  $w .= " AND assessment_period IN ('" . implode("','", $periods) . "')";
    if ($counties) $w .= " AND county_name IN ('" . implode("','", $counties) . "')";
    if ($levels)   $w .= " AND level_of_care_name IN ('" . implode("','", $levels) . "')";
    return $w;
}

// Determine groups
if (count($selected_periods) >= 2) {
    $compare_by = 'period';
    foreach ($selected_periods as $p) {
        $county_part = $selected_counties ? $selected_counties : [];
        $level_part  = $selected_levels  ? $selected_levels  : [];
        $groups[] = [
            'label' => $p,
            'where' => build_where([$p], $county_part, $level_part),
        ];
    }
} elseif (count($selected_counties) >= 2) {
    $compare_by = 'county';
    $period_part = $selected_periods ?: [];
    $level_part  = $selected_levels  ?: [];
    foreach ($selected_counties as $c) {
        $groups[] = [
            'label' => $c,
            'where' => build_where($period_part, [$c], $level_part),
        ];
    }
} elseif (count($selected_levels) >= 2) {
    $compare_by = 'level';
    $period_part = $selected_periods ?: [];
    $county_part = $selected_counties ?: [];
    foreach ($selected_levels as $lv) {
        $groups[] = [
            'label' => $lv,
            'where' => build_where($period_part, $county_part, [$lv]),
        ];
    }
} else {
    // No meaningful comparison yet; show all data as one group
    $groups[] = [
        'label' => 'All Data',
        'where' => build_where($selected_periods, $selected_counties, $selected_levels),
    ];
}

// -- Filter option lists -------------------------------------------------------
$periods_all = [];
$pr = mysqli_query($conn, "SELECT DISTINCT assessment_period FROM integration_assessments ORDER BY assessment_period DESC");
if ($pr) while ($r = mysqli_fetch_assoc($pr)) $periods_all[] = $r['assessment_period'];

$counties_all = [];
$cr = mysqli_query($conn, "SELECT DISTINCT county_name FROM integration_assessments WHERE county_name != '' ORDER BY county_name");
if ($cr) while ($r = mysqli_fetch_assoc($cr)) $counties_all[] = $r['county_name'];

$levels_all = [];
$clr = mysqli_query($conn, "SELECT DISTINCT level_of_care_name FROM integration_assessments WHERE level_of_care_name != '' ORDER BY level_of_care_name");
if ($clr) while ($r = mysqli_fetch_assoc($clr)) $levels_all[] = $r['level_of_care_name'];

// -- Helpers -------------------------------------------------------------------
function yn_stats($conn, $field, $where) {
    $r = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN $field='Yes' THEN 1 ELSE 0 END) AS yes_count,
                SUM(CASE WHEN $field='No'  THEN 1 ELSE 0 END) AS no_count
         FROM integration_assessments $where"));
    $total = (int)$r['total'];
    $yes   = (int)$r['yes_count'];
    $no    = (int)$r['no_count'];
    $pct   = $total > 0 ? round($yes / $total * 100) : 0;
    return ['total'=>$total,'yes'=>$yes,'no'=>$no,'pct'=>$pct];
}

function get_totals($conn, $where) {
    return mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT
            COUNT(*) AS assessments,
            COUNT(DISTINCT facility_id) AS facilities,
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
}

function get_hrh($conn, $where) {
    return mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT
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
}

$s2b_fields = [
    'pmtct_integrated_mnch' => 'PMTCT in MNCH',
    'hts_integrated_opd'    => 'HTS in OPD',
    'hts_integrated_ipd'    => 'HTS in IPD',
    'hts_integrated_mnch'   => 'HTS in MNCH',
    'prep_integrated_opd'   => 'PrEP in OPD',
    'prep_integrated_ipd'   => 'PrEP in IPD',
    'prep_integrated_mnch'  => 'PrEP in MNCH',
];

$emr_fields = [
    'uses_emr'               => 'Uses EMR',
    'single_unified_emr'     => 'Unified EMR',
    'emr_at_opd'             => 'OPD',
    'emr_at_ipd'             => 'IPD',
    'emr_at_mnch'            => 'MNCH',
    'emr_at_ccc'             => 'CCC',
    'emr_at_pmtct'           => 'PMTCT',
    'emr_at_lab'             => 'Lab',
    'emr_at_pharmacy'        => 'Pharmacy',
    'lab_manifest_in_use'    => 'Lab Manifest',
    'pharmacy_webadt_in_use' => 'WebADT',
    'emr_interoperable_his'  => 'HIS Interop.',
];

$s456_yn = [
    'sha_claims_submitted_ontime' => 'SHA Claims Ontime',
    'sha_reimbursements_monthly'  => 'SHA Monthly Reimb.',
    'fif_collection_in_place'     => 'FIF Collection',
    'fif_includes_hiv_tb_pmtct'   => 'FIF HIV/TB/PMTCT',
    'sha_capitation_hiv_tb'       => 'SHA Capitation HIV/TB',
];

$s1_fields = [
    'supported_by_usdos_ip' => 'US DoS IP Support',
    'is_art_site'           => 'ART Site',
    'hiv_tb_integrated'     => 'HIV/TB Integrated',
];

// -- Build data per group ------------------------------------------------------
$group_data = [];
foreach ($groups as $g) {
    $w    = $g['where'];
    $tot  = get_totals($conn, $w);
    $hrh  = get_hrh($conn, $w);
    $fac  = (int)($tot['facilities'] ?? 0);

    // S1
    $s1 = [];
    foreach ($s1_fields as $f => $l) $s1[$f] = ['label'=>$l] + yn_stats($conn, $f, $w);

    // S2b
    $s2b = [];
    foreach ($s2b_fields as $f => $l) $s2b[$f] = ['label'=>$l] + yn_stats($conn, $f, $w);

    // EMR
    $emr = [];
    foreach ($emr_fields as $f => $l) $emr[$f] = ['label'=>$l] + yn_stats($conn, $f, $w);

    // S456
    $s456 = [];
    foreach ($s456_yn as $f => $l) $s456[$f] = ['label'=>$l] + yn_stats($conn, $f, $w);

    // Mortality
    $mort = [
        'All-Cause' => (int)($tot['deaths_all'] ?? 0),
        'HIV'       => (int)($tot['deaths_hiv'] ?? 0),
        'TB'        => (int)($tot['deaths_tb']  ?? 0),
        'Maternal'  => (int)($tot['deaths_maternal'] ?? 0),
    ];

    // HRH cats
    $hrh_cats = [
        'Clinical'    => ['pepfar'=>(int)($hrh['clinical']??0),    'trans'=>(int)($hrh['t_clinical']??0)],
        'Non-Clinical'=> ['pepfar'=>(int)($hrh['nonclinical']??0), 'trans'=>(int)($hrh['t_nonclinical']??0)],
        'Data'        => ['pepfar'=>(int)($hrh['data_s']??0),      'trans'=>(int)($hrh['t_data']??0)],
        'Community'   => ['pepfar'=>(int)($hrh['community']??0),   'trans'=>(int)($hrh['t_community']??0)],
        'Other'       => ['pepfar'=>(int)($hrh['other_p']??0),     'trans'=>(int)($hrh['t_other']??0)],
    ];

    // EMR systems from child table
    $emr_where_child = "WHERE emr_type != ''";
    // parse existing periods/counties/levels from $w and apply
    if ($selected_periods || $selected_counties || $selected_levels) {
        $period_list  = array_map(fn($v) => mysqli_real_escape_string($conn, $v), $selected_periods);
        $county_list  = array_map(fn($v) => mysqli_real_escape_string($conn, $v), $selected_counties);
        $level_list   = array_map(fn($v) => mysqli_real_escape_string($conn, $v), $selected_levels);

        // Override for this specific group
        if ($g['label'] !== 'All Data') {
            if ($compare_by === 'period')  $period_list  = [$g['label']];
            if ($compare_by === 'county')  $county_list  = [$g['label']];
            if ($compare_by === 'level')   $level_list   = [$g['label']];
        }
        if ($period_list)  $emr_where_child .= " AND ia.assessment_period IN ('" . implode("','", $period_list) . "')";
        if ($county_list)  $emr_where_child .= " AND ia.county_name IN ('" . implode("','", $county_list) . "')";
        if ($level_list)   $emr_where_child .= " AND ia.level_of_care_name IN ('" . implode("','", $level_list) . "')";
    }

    $emr_systems = [];
    $etr = mysqli_query($conn,
        "SELECT emr_type, COUNT(*) cnt
         FROM integration_assessment_emr_systems es
         JOIN integration_assessments ia ON ia.assessment_id = es.assessment_id
         $emr_where_child
         GROUP BY emr_type ORDER BY cnt DESC LIMIT 8");
    if ($etr) while ($r = mysqli_fetch_assoc($etr)) $emr_systems[] = $r;

    $group_data[] = [
        'label'       => $g['label'],
        'totals'      => $tot,
        'facilities'  => $fac,
        's1'          => $s1,
        's2b'         => $s2b,
        'emr'         => $emr,
        's456'        => $s456,
        'mort'        => $mort,
        'hrh_cats'    => $hrh_cats,
        'emr_systems' => $emr_systems,
    ];
}

$num_groups = count($group_data);
$has_comparison = $num_groups >= 2;

// -- Palette per group ---------------------------------------------------------
$group_colors = [
    '#0D1A63','#0ABFBC','#27AE60','#F5A623','#8B5CF6','#E74C3C','#3B82F6','#F97316'
];
$group_colors_light = [
    'rgba(13,26,99,.12)','rgba(10,191,188,.12)','rgba(39,174,96,.12)',
    'rgba(245,166,35,.12)','rgba(139,92,246,.12)','rgba(231,76,60,.12)',
    'rgba(59,130,246,.12)','rgba(249,115,22,.12)'
];

// -- JSON for charts -----------------------------------------------------------
// NOTE: $s2b_fields, $emr_fields, $s456_yn, $s1_fields are all key=>label_string arrays.
// Their VALUES are plain strings, so use array_values() directly for labels.

// S2b comparison chart
$chart_s2b_labels = json_encode(array_values($s2b_fields));
$chart_s2b_datasets = [];
foreach ($group_data as $i => $gd) {
    $chart_s2b_datasets[] = [
        'label'           => $gd['label'],
        'data'            => array_values(array_map(fn($v) => $v['pct'], $gd['s2b'])),
        'backgroundColor' => $group_colors[$i % count($group_colors)],
        'borderRadius'    => 5,
        'borderSkipped'   => false,
    ];
}
$chart_s2b_datasets_json = json_encode($chart_s2b_datasets);

// EMR comparison chart
$chart_emr_labels = json_encode(array_values($emr_fields));
$chart_emr_datasets = [];
foreach ($group_data as $i => $gd) {
    $chart_emr_datasets[] = [
        'label'           => $gd['label'],
        'data'            => array_values(array_map(fn($v)=>$v['pct'], $gd['emr'])),
        'backgroundColor' => $group_colors[$i % count($group_colors)],
        'borderRadius'    => 5,
        'borderSkipped'   => false,
    ];
}
$chart_emr_datasets_json = json_encode($chart_emr_datasets);

// SHA/FIF comparison
$chart_s456_labels = json_encode(array_values($s456_yn));
$chart_s456_datasets = [];
foreach ($group_data as $i => $gd) {
    $chart_s456_datasets[] = [
        'label'           => $gd['label'],
        'data'            => array_values(array_map(fn($v)=>$v['pct'], $gd['s456'])),
        'backgroundColor' => $group_colors[$i % count($group_colors)],
        'borderRadius'    => 5,
        'borderSkipped'   => false,
    ];
}
$chart_s456_datasets_json = json_encode($chart_s456_datasets);

// Mortality comparison
$mort_labels = json_encode(['All-Cause','HIV','TB','Maternal']);
$mort_datasets = [];
foreach ($group_data as $i => $gd) {
    $mort_datasets[] = [
        'label'           => $gd['label'],
        'data'            => array_values($gd['mort']),
        'backgroundColor' => $group_colors[$i % count($group_colors)],
        'borderRadius'    => 5,
        'borderSkipped'   => false,
    ];
}
$mort_datasets_json = json_encode($mort_datasets);

// HRH datasets
$hrh_cat_labels = json_encode(['Clinical','Non-Clinical','Data','Community','Other']);
$hrh_pepfar_datasets = [];
$hrh_trans_datasets  = [];
foreach ($group_data as $i => $gd) {
    $pepfar_vals = array_map(fn($c) => $c['pepfar'], array_values($gd['hrh_cats']));
    $trans_vals  = array_map(fn($c) => $c['trans'],  array_values($gd['hrh_cats']));
    $hrh_pepfar_datasets[] = [
        'label'           => $gd['label'] . ' (PEPFAR)',
        'data'            => $pepfar_vals,
        'backgroundColor' => $group_colors_light[$i % count($group_colors_light)],
        'borderColor'     => $group_colors[$i % count($group_colors)],
        'borderWidth'     => 1.5,
        'borderRadius'    => 5,
        'borderSkipped'   => false,
    ];
    $hrh_trans_datasets[] = [
        'label'           => $gd['label'] . ' (Transitioned)',
        'data'            => $trans_vals,
        'backgroundColor' => $group_colors[$i % count($group_colors)],
        'borderRadius'    => 5,
        'borderSkipped'   => false,
    ];
}
$hrh_all_datasets_json = json_encode(array_merge($hrh_pepfar_datasets, $hrh_trans_datasets));

// TX_CURR comparison radar / bar
$kpi_compare_labels = json_encode(['Facilities','TX_CURR (÷100)','PLHIVs SHA','HCW PEPFAR','HCW Trans.','TA Visits']);
$kpi_compare_datasets = [];
foreach ($group_data as $i => $gd) {
    $t = $gd['totals'];
    $kpi_compare_datasets[] = [
        'label' => $gd['label'],
        'data'  => [
            (int)($t['facilities']??0),
            round((int)($t['tx_curr']??0)/100),
            (int)($t['plhiv_sha']??0),
            (int)($t['hcw_pepfar']??0),
            (int)($t['hcw_transitioned']??0),
            (int)($t['ta_visits']??0),
        ],
        'backgroundColor' => $group_colors_light[$i % count($group_colors_light)],
        'borderColor'     => $group_colors[$i % count($group_colors)],
        'borderWidth'     => 2,
        'pointBackgroundColor' => $group_colors[$i % count($group_colors)],
        'pointRadius'     => 4,
    ];
}
$kpi_compare_datasets_json = json_encode($kpi_compare_datasets);

// Group colors for JS
$group_colors_json       = json_encode(array_slice($group_colors, 0, $num_groups));
$group_colors_light_json = json_encode(array_slice($group_colors_light, 0, $num_groups));
$group_labels_json       = json_encode(array_map(fn($g) => $g['label'], $group_data));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Integration Comparison Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<!-- Tom Select for multiselect dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
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
.topbar{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 16px rgba(0,0,0,.2);position:sticky;top:0;z-index:200;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.topbar-logo{width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.topbar-title{font-size:16px;font-weight:700;}
.topbar-sub{font-size:11px;opacity:.65;margin-top:1px;}
.topbar-right{display:flex;align-items:center;gap:14px;}
.topbar-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;padding:6px 14px;border-radius:7px;font-size:12px;text-decoration:none;transition:.2s;display:inline-flex;align-items:center;gap:6px;}
.topbar-btn:hover{background:rgba(255,255,255,.25);}

.page{padding:24px 28px;max-width:1700px;margin:0 auto;}

/* Filters */
.filters-card{background:var(--card);border-radius:var(--radius);padding:20px 22px;margin-bottom:22px;box-shadow:var(--shadow);}
.filters-title{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.filters-grid{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:16px;align-items:end;}
.filter-group label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;}
.filter-hint{font-size:10px;color:var(--teal);margin-top:3px;font-weight:500;}
.filter-actions{display:flex;gap:10px;align-items:flex-end;padding-bottom:2px;}
.filter-btn{background:var(--navy);color:#fff;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:.2s;display:flex;align-items:center;gap:7px;height:40px;}
.filter-btn:hover{background:var(--navy2);}
.filter-clear{background:#f3f4f6;color:var(--muted);border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:6px;height:40px;}
.filter-clear:hover{background:#e5e7eb;}

/* Tom Select overrides */
.ts-wrapper .ts-control{border:1.5px solid var(--border)!important;border-radius:8px!important;font-size:13px!important;min-height:40px!important;padding:4px 8px!important;}
.ts-wrapper.focus .ts-control{border-color:var(--navy)!important;box-shadow:none!important;}
.ts-wrapper .ts-control .item{background:var(--navy)!important;color:#fff!important;border-radius:5px!important;font-size:11px!important;padding:2px 7px!important;}
.ts-wrapper .ts-control .item .remove{color:rgba(255,255,255,.7)!important;margin-left:4px!important;}
.ts-dropdown .option.selected{background:rgba(13,26,99,.08)!important;color:var(--navy)!important;}
.ts-dropdown .option:hover{background:rgba(13,26,99,.06)!important;}

/* Comparison mode banner */
.compare-banner{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:600;}
.compare-banner.active{background:rgba(10,191,188,.1);border:1.5px solid rgba(10,191,188,.3);color:var(--teal);}
.compare-banner.inactive{background:rgba(245,166,35,.08);border:1.5px solid rgba(245,166,35,.25);color:#92400E;}
.group-tags{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto;}
.group-tag{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;color:#fff;}

/* Section label */
.section-label{font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin:26px 0 14px;display:flex;align-items:center;gap:10px;}
.section-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* Cards */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.card-head{padding:15px 20px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.card-head h3{font-size:14px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.card-head h3 i{width:26px;height:26px;background:rgba(13,26,99,.08);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:var(--navy);}
.card-body{padding:18px 20px;}
.card-badge{font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;background:var(--bg);color:var(--muted);}

/* Comparison columns */
.compare-cols{display:grid;gap:14px;}
.compare-col{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.compare-col-head{padding:12px 16px;font-size:13px;font-weight:700;color:#fff;display:flex;align-items:center;gap:8px;}
.compare-col-body{padding:16px;}

/* KPI compare grid */
.kpi-compare-grid{display:grid;gap:14px;}
.kpi-compare-row{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 20px;}
.kpi-compare-row-title{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:12px;display:flex;align-items:center;gap:6px;}
.kpi-compare-row-title i{font-size:13px;}
.kpi-compare-items{display:grid;gap:10px;}
.kpi-compare-item{display:flex;align-items:center;gap:12px;}
.kci-label{font-size:12px;font-weight:600;min-width:130px;flex-shrink:0;}
.kci-bar-wrap{flex:1;display:flex;flex-direction:column;gap:5px;}
.kci-bar-row{display:flex;align-items:center;gap:8px;}
.kci-bar-track{flex:1;height:10px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.kci-bar-fill{height:100%;border-radius:99px;transition:width 1s cubic-bezier(.4,0,.2,1);}
.kci-group-label{font-size:11px;font-weight:600;min-width:80px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.kci-val{font-size:12px;font-weight:700;min-width:65px;text-align:right;flex-shrink:0;}

/* Y/N comparison rows */
.yn-cmp-row{display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);}
.yn-cmp-row:last-child{border-bottom:none;}
.yn-cmp-label{font-size:12px;font-weight:500;flex:1;min-width:0;padding-top:1px;}
.yn-cmp-bars{display:flex;flex-direction:column;gap:4px;min-width:140px;}
.yn-cmp-bar-row{display:flex;align-items:center;gap:5px;}
.yn-cmp-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.yn-cmp-track{flex:1;height:7px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.yn-cmp-fill{height:100%;border-radius:99px;transition:width 1s cubic-bezier(.4,0,.2,1);}
.yn-cmp-pct{font-size:11px;font-weight:700;min-width:32px;text-align:right;}

/* Grid layouts */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;}
.grid-32{display:grid;grid-template-columns:3fr 2fr;gap:18px;}

/* Tables */
.dash-table{width:100%;border-collapse:collapse;font-size:12px;}
.dash-table th{padding:8px 12px;background:#f8fafc;font-size:10px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);text-align:left;}
.dash-table td{padding:8px 12px;border-bottom:1px solid var(--border);vertical-align:middle;}
.dash-table tr:last-child td{border-bottom:none;}
.dash-table tr:hover td{background:#f8fafc;}

/* Pills */
.pill{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.pill-green{background:#D1FAE5;color:#065F46;}
.pill-amber{background:#FEF3C7;color:#92400E;}
.pill-rose{background:#FEE2E2;color:#991B1B;}
.pill-blue{background:#DBEAFE;color:#1E40AF;}
.pill-gray{background:#F3F4F6;color:#374151;}

/* Big number highlight */
.big-num{font-size:38px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums;}
.big-num-sub{font-size:11px;color:var(--muted);margin-top:4px;font-weight:500;}

/* Delta badge */
.delta{display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px;}
.delta-up{background:#D1FAE5;color:#065F46;}
.delta-down{background:#FEE2E2;color:#991B1B;}
.delta-neutral{background:#F3F4F6;color:#6B7280;}

/* Legend */
.legend{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:12px;}
.legend-item{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;}
.legend-dot{width:10px;height:10px;border-radius:3px;flex-shrink:0;}

/* Chart wraps — always visible labels, no hover-only */
.chart-wrap{position:relative;}
.chart-label-visible .chartjs-tooltip{opacity:1!important;}

/* Responsive */
@media(max-width:1200px){.grid-32{grid-template-columns:1fr;}.filters-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:800px){.grid-2,.grid-3{grid-template-columns:1fr;}.page{padding:14px;}.topbar{padding:0 14px;}.filters-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <div class="topbar-left">
        <div class="topbar-logo"><i class="fas fa-chart-line"></i></div>
        <div>
            <div class="topbar-title">Integration Assessment — Comparison Dashboard</div>
            <div class="topbar-sub">Multi-period · Multi-county · Multi-level side-by-side analysis</div>
        </div>
    </div>
    <div class="topbar-right">
        <a href="integration_dashboard.php" class="topbar-btn"><i class="fas fa-tachometer-alt"></i> Main Dashboard</a>
        <a href="integration_assessment.php" class="topbar-btn"><i class="fas fa-plus"></i> New Assessment</a>
    </div>
</div>

<div class="page">

<!-- ── FILTERS ── -->
<div class="filters-card">
    <div class="filters-title"><i class="fas fa-sliders-h"></i> Comparison Filters &mdash; select multiple values to compare side-by-side</div>
    <form method="GET" id="filterForm">
        <div class="filters-grid">
            <div class="filter-group">
                <label>Assessment Periods <span style="color:var(--teal)">(multiselect)</span></label>
                <select id="sel-periods" name="periods[]" multiple placeholder="Select periods...">
                    <?php foreach ($periods_all as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= in_array($p, $selected_periods)?'selected':'' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="filter-hint"><i class="fas fa-info-circle"></i> Select 2+ periods to compare over time</div>
            </div>
            <div class="filter-group">
                <label>Counties <span style="color:var(--teal)">(multiselect)</span></label>
                <select id="sel-counties" name="counties[]" multiple placeholder="Select counties...">
                    <?php foreach ($counties_all as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= in_array($c, $selected_counties)?'selected':'' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="filter-hint"><i class="fas fa-info-circle"></i> Select 2+ counties to compare between counties</div>
            </div>
            <div class="filter-group">
                <label>Levels of Care <span style="color:var(--teal)">(multiselect)</span></label>
                <select id="sel-levels" name="levels[]" multiple placeholder="Select levels...">
                    <?php foreach ($levels_all as $lv): ?>
                    <option value="<?= htmlspecialchars($lv) ?>" <?= in_array($lv, $selected_levels)?'selected':'' ?>>
                        <?= htmlspecialchars($lv) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="filter-hint"><i class="fas fa-info-circle"></i> Select 2+ levels to compare across care tiers</div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="filter-btn"><i class="fas fa-sync-alt"></i> Compare</button>
                <a href="integration_comparison_dashboard.php" class="filter-clear"><i class="fas fa-times"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- ── COMPARISON MODE BANNER ── -->
<?php if ($has_comparison): ?>
<div class="compare-banner active">
    <i class="fas fa-check-circle" style="font-size:18px"></i>
    <span>Comparing <strong><?= $num_groups ?> groups</strong> by <strong><?= ucfirst($compare_by) ?></strong></span>
    <div class="group-tags">
        <?php foreach ($group_data as $i => $gd): ?>
        <span class="group-tag" style="background:<?= $group_colors[$i % count($group_colors)] ?>">
            <i class="fas fa-circle" style="font-size:7px"></i>
            <?= htmlspecialchars($gd['label']) ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="compare-banner inactive">
    <i class="fas fa-exclamation-triangle" style="font-size:16px"></i>
    <span>Select <strong>2 or more</strong> periods, counties, or levels of care above to enable side-by-side comparison.</span>
</div>
<?php endif; ?>

<!-- ── LEGEND ── -->
<?php if ($has_comparison): ?>
<div class="legend">
    <?php foreach ($group_data as $i => $gd): ?>
    <div class="legend-item">
        <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>"></div>
        <?= htmlspecialchars($gd['label']) ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════
     SECTION A: KPI OVERVIEW — side-by-side number cards
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Overview — Key Performance Indicators</div>

<?php
$kpi_defs = [
    ['Facilities',        'facilities',        'fa-hospital',        'navy'],
    ['Assessments',       'assessments',       'fa-clipboard-check', 'blue'],
    ['TX_CURR',           'tx_curr',           'fa-pills',           'teal'],
    ['PLHIVs SHA',        'plhiv_sha',         'fa-id-card',         'purple'],
    ['HCWs (PEPFAR)',     'hcw_pepfar',        'fa-users',           'green'],
    ['HCWs Transitioned', 'hcw_transitioned',  'fa-exchange-alt',    'amber'],
    ['TA Visits',         'ta_visits',         'fa-chalkboard-teacher','teal'],
    ['All-Cause Deaths',  'deaths_all',        'fa-heartbeat',       'rose'],
];
?>

<!-- KPI comparison bars -->
<div class="kpi-compare-grid" style="grid-template-columns: repeat(auto-fit, minmax(360px,1fr));">
<?php foreach ($kpi_defs as [$lbl, $key, $icon, $col]):
    $max_val = max(1, ...array_map(fn($gd) => (int)($gd['totals'][$key]??0), $group_data));
?>
<div class="kpi-compare-row">
    <div class="kpi-compare-row-title" style="color:var(--<?= $col ?>)">
        <i class="fas <?= $icon ?>"></i> <?= $lbl ?>
    </div>
    <div class="kpi-compare-items">
    <?php foreach ($group_data as $i => $gd):
        $val = (int)($gd['totals'][$key] ?? 0);
        $bar_pct = $max_val > 0 ? round($val / $max_val * 100) : 0;
        $color = $group_colors[$i % count($group_colors)];
    ?>
        <div class="kci-bar-row">
            <div class="kci-group-label" style="color:<?= $color ?>"><?= htmlspecialchars($gd['label']) ?></div>
            <div class="kci-bar-track" style="flex:1">
                <div class="kci-bar-fill" style="width:<?= $bar_pct ?>%;background:<?= $color ?>"></div>
            </div>
            <div class="kci-val" style="color:<?= $color ?>"><?= number_format($val) ?></div>
        </div>
    <?php endforeach; ?>

    <?php if ($num_groups === 2):
        $v0 = (int)($group_data[0]['totals'][$key]??0);
        $v1 = (int)($group_data[1]['totals'][$key]??0);
        $diff = $v1 - $v0;
        $pct_chg = $v0 > 0 ? round($diff / $v0 * 100, 1) : null;
        $cls = $diff > 0 ? 'delta-up' : ($diff < 0 ? 'delta-down' : 'delta-neutral');
        $arrow = $diff > 0 ? '▲' : ($diff < 0 ? '▼' : '→');
    ?>
        <div style="margin-top:4px">
            <span class="delta <?= $cls ?>">
                <?= $arrow ?> <?= $pct_chg !== null ? abs($pct_chg).'%' : '—' ?>
                (<?= $diff >= 0 ? '+' : '' ?><?= number_format($diff) ?>)
            </span>
            <span style="font-size:10px;color:var(--muted);margin-left:5px">vs previous</span>
        </div>
    <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Radar/Line overview chart -->
<div style="margin-top:18px" class="card">
    <div class="card-head">
        <h3><i class="fas fa-radar"></i> Relative Comparison (Radar)</h3>
        <span class="card-badge">Normalised values</span>
    </div>
    <div class="card-body">
        <div style="height:340px;max-width:600px;margin:0 auto"><canvas id="radarChart"></canvas></div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     SECTION 1: Facility Profile — side by side columns
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Section 1 &mdash; Facility Profile</div>
<div class="compare-cols" style="grid-template-columns:repeat(<?= min($num_groups,4) ?>,1fr)">
<?php foreach ($group_data as $i => $gd):
    $color = $group_colors[$i % count($group_colors)];
?>
<div class="compare-col">
    <div class="compare-col-head" style="background:<?= $color ?>">
        <i class="fas fa-circle" style="font-size:9px"></i>
        <?= htmlspecialchars($gd['label']) ?>
        <span style="margin-left:auto;font-size:11px;opacity:.8"><?= number_format($gd['facilities']) ?> facilities</span>
    </div>
    <div class="compare-col-body">
        <?php foreach ($gd['s1'] as $f => $d):
            $pct = $d['pct'];
            $bar_col = $pct>=70?'var(--green)':($pct>=40?'var(--amber)':'var(--rose)');
        ?>
        <div style="margin-bottom:12px">
            <div style="font-size:12px;font-weight:600;margin-bottom:4px;color:var(--text)"><?= $d['label'] ?></div>
            <div style="display:flex;align-items:center;gap:8px">
                <div style="flex:1;height:8px;background:#f0f0f0;border-radius:99px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $bar_col ?>;border-radius:99px;transition:width 1s"></div>
                </div>
                <span style="font-size:14px;font-weight:800;color:<?= $bar_col ?>;min-width:38px;text-align:right"><?= $pct ?>%</span>
            </div>
            <div style="font-size:10px;color:var(--muted);margin-top:2px"><?= $d['yes'] ?> yes / <?= $d['total'] ?> total</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>


<!-- ══════════════════════════════════════════════════════
     SECTION 2b: Service Integration — grouped bar chart + table
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Section 2b &mdash; Service Integration (% Facilities Reporting Yes)</div>
<div class="grid-32">
    <div class="card">
        <div class="card-head">
            <h3><i class="fas fa-chart-bar"></i> Integration by Service &mdash; Grouped Bar</h3>
        </div>
        <div class="card-body">
            <!-- Visible legend above chart -->
            <div class="legend" style="margin-bottom:10px">
                <?php foreach ($group_data as $i => $gd): ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>"></div>
                    <?= htmlspecialchars($gd['label']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:280px"><canvas id="s2bChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-list-check"></i> Side-by-Side Breakdown</h3></div>
        <div class="card-body">
            <?php
            $s2b_keys = array_keys($s2b_fields);
            foreach ($s2b_keys as $f):
                $label = $s2b_fields[$f];
            ?>
            <div class="yn-cmp-row">
                <div class="yn-cmp-label"><?= $label ?></div>
                <div class="yn-cmp-bars">
                    <?php foreach ($group_data as $i => $gd):
                        $d = $gd['s2b'][$f];
                        $pct = $d['pct'];
                        $color = $group_colors[$i % count($group_colors)];
                    ?>
                    <div class="yn-cmp-bar-row">
                        <div class="yn-cmp-dot" style="background:<?= $color ?>"></div>
                        <div class="yn-cmp-track">
                            <div class="yn-cmp-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                        </div>
                        <div class="yn-cmp-pct" style="color:<?= $color ?>"><?= $pct ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     SECTION 2c: EMR Coverage
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Section 2c &mdash; EMR Coverage (% Facilities Reporting Yes)</div>
<div class="grid-32">
    <div class="card">
        <div class="card-head">
            <h3><i class="fas fa-laptop-medical"></i> EMR by Department &mdash; Grouped Bar</h3>
        </div>
        <div class="card-body">
            <div class="legend" style="margin-bottom:10px">
                <?php foreach ($group_data as $i => $gd): ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>"></div>
                    <?= htmlspecialchars($gd['label']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:300px"><canvas id="emrChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-table"></i> EMR Coverage Table</h3></div>
        <div class="card-body" style="overflow-x:auto">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Indicator</th>
                        <?php foreach ($group_data as $gd): ?>
                        <th><?= htmlspecialchars($gd['label']) ?></th>
                        <?php endforeach; ?>
                        <?php if ($num_groups === 2): ?><th>Δ</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($emr_fields as $f => $lbl):
                    $pcts = array_map(fn($gd) => $gd['emr'][$f]['pct'], $group_data);
                ?>
                <tr>
                    <td><strong><?= $lbl ?></strong></td>
                    <?php foreach ($pcts as $j => $pct): ?>
                    <td><span class="pill <?= $pct>=70?'pill-green':($pct>=40?'pill-amber':'pill-rose') ?>"><?= $pct ?>%</span></td>
                    <?php endforeach; ?>
                    <?php if ($num_groups === 2):
                        $diff2 = $pcts[1] - $pcts[0];
                        $cls2 = $diff2 > 0 ? 'delta-up' : ($diff2 < 0 ? 'delta-down' : 'delta-neutral');
                    ?>
                    <td><span class="delta <?= $cls2 ?>"><?= $diff2>=0?'+':'' ?><?= $diff2 ?>pp</span></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- EMR Systems in use -->
<?php $any_emr_systems = !empty(array_filter(array_column($group_data, 'emr_systems'))); ?>
<?php if ($any_emr_systems): ?>
<div class="section-label">EMR Systems in Use — by Group</div>
<div class="compare-cols" style="grid-template-columns:repeat(<?= min($num_groups,4) ?>,1fr)">
<?php foreach ($group_data as $i => $gd):
    $color = $group_colors[$i % count($group_colors)];
?>
<div class="compare-col">
    <div class="compare-col-head" style="background:<?= $color ?>">
        <i class="fas fa-database" style="font-size:12px"></i>
        <?= htmlspecialchars($gd['label']) ?>
    </div>
    <div class="compare-col-body">
        <?php if (empty($gd['emr_systems'])): ?>
        <p style="color:var(--muted);font-size:13px;text-align:center;padding:20px 0">No EMR system data</p>
        <?php else: ?>
        <?php
        $max_emr = max(1, ...array_column($gd['emr_systems'], 'cnt'));
        foreach ($gd['emr_systems'] as $es):
            $ep = round($es['cnt'] / $max_emr * 100);
        ?>
        <div style="margin-bottom:9px">
            <div style="font-size:12px;font-weight:600;margin-bottom:3px"><?= htmlspecialchars($es['emr_type']) ?></div>
            <div style="display:flex;align-items:center;gap:6px">
                <div style="flex:1;height:7px;background:#f0f0f0;border-radius:99px;overflow:hidden">
                    <div style="width:<?= $ep ?>%;height:100%;background:<?= $color ?>;border-radius:99px"></div>
                </div>
                <span style="font-size:11px;font-weight:700;color:<?= $color ?>"><?= $es['cnt'] ?></span>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════
     SECTION 3: HRH Transition
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Section 3 &mdash; HRH Transition (Workforce Absorption)</div>
<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <h3><i class="fas fa-users"></i> PEPFAR-Supported vs Transitioned — Grouped Bar</h3>
        </div>
        <div class="card-body">
            <div class="legend" style="margin-bottom:10px">
                <?php foreach ($group_data as $i => $gd): ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>;opacity:.35;border:2px solid <?= $group_colors[$i % count($group_colors)] ?>"></div>
                    <?= htmlspecialchars($gd['label']) ?> PEPFAR
                </div>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>"></div>
                    <?= htmlspecialchars($gd['label']) ?> Transitioned
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:260px"><canvas id="hrhChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-exchange-alt"></i> Transition Rate by Category</h3></div>
        <div class="card-body">
            <?php
            $hrh_cat_keys = ['Clinical','Non-Clinical','Data','Community','Other'];
            foreach ($hrh_cat_keys as $cat):
            ?>
            <div style="margin-bottom:14px">
                <div style="font-size:12px;font-weight:700;margin-bottom:5px;color:var(--text)"><?= $cat ?></div>
                <?php foreach ($group_data as $i => $gd):
                    $c = $gd['hrh_cats'][$cat];
                    $rate = $c['pepfar'] > 0 ? round($c['trans'] / $c['pepfar'] * 100) : 0;
                    $color = $group_colors[$i % count($group_colors)];
                    $rate_col = $rate>=70?'var(--green)':($rate>=40?'var(--amber)':'var(--rose)');
                ?>
                <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
                    <div style="width:72px;font-size:10px;font-weight:600;color:<?= $color ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($gd['label']) ?></div>
                    <div style="flex:1;height:8px;background:#f0f0f0;border-radius:99px;overflow:hidden">
                        <div style="width:<?= $rate ?>%;height:100%;background:<?= $color ?>;border-radius:99px;transition:width 1s"></div>
                    </div>
                    <span style="font-size:11px;font-weight:800;color:<?= $color ?>;min-width:36px;text-align:right"><?= $rate ?>%</span>
                    <span style="font-size:10px;color:var(--muted);min-width:60px"><?= $c['trans'] ?>/<?= $c['pepfar'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     SECTION 4-6: SHA, FIF & Financing
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Sections 4–6 &mdash; SHA, TA &amp; Financing</div>

<!-- SHA KPIs side-by-side -->
<div class="compare-cols" style="grid-template-columns:repeat(<?= min($num_groups,4) ?>,1fr);margin-bottom:18px">
<?php foreach ($group_data as $i => $gd):
    $color = $group_colors[$i % count($group_colors)];
    $t = $gd['totals'];
    $sha_kpis = [
        ['PLHIVs Enrolled SHA', $t['plhiv_sha']??0, 'fa-id-card'],
        ['PLHIVs Premium Paid', $t['plhiv_sha_paid']??0, 'fa-check-circle'],
        ['PBFW Enrolled SHA',   $t['pbfw_sha']??0, 'fa-baby'],
    ];
?>
<div class="compare-col">
    <div class="compare-col-head" style="background:<?= $color ?>">
        <i class="fas fa-circle" style="font-size:9px"></i> <?= htmlspecialchars($gd['label']) ?>
    </div>
    <div class="compare-col-body">
        <?php foreach ($sha_kpis as [$lbl, $val, $icon]): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
            <div style="width:28px;height:28px;background:<?= $color ?>22;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:<?= $color ?>">
                <i class="fas <?= $icon ?>"></i>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:<?= $color ?>"><?= number_format($val) ?></div>
                <div style="font-size:10px;color:var(--muted)"><?= $lbl ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- SHA/FIF grouped bar + table -->
<div class="grid-32">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-chart-bar"></i> SHA, FIF &amp; Capitation Indicators</h3></div>
        <div class="card-body">
            <div class="legend" style="margin-bottom:10px">
                <?php foreach ($group_data as $i => $gd): ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>"></div>
                    <?= htmlspecialchars($gd['label']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:240px"><canvas id="s456Chart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-list-check"></i> SHA Indicators Breakdown</h3></div>
        <div class="card-body">
            <?php foreach ($s456_yn as $f => $lbl): ?>
            <div class="yn-cmp-row">
                <div class="yn-cmp-label"><?= $lbl ?></div>
                <div class="yn-cmp-bars">
                    <?php foreach ($group_data as $i => $gd):
                        $d = $gd['s456'][$f];
                        $pct = $d['pct'];
                        $color = $group_colors[$i % count($group_colors)];
                    ?>
                    <div class="yn-cmp-bar-row">
                        <div class="yn-cmp-dot" style="background:<?= $color ?>"></div>
                        <div class="yn-cmp-track">
                            <div class="yn-cmp-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                        </div>
                        <div class="yn-cmp-pct" style="color:<?= $color ?>"><?= $pct ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     SECTION 7: Mortality
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Section 7 &mdash; Mortality Outcomes</div>
<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-heartbeat"></i> Mortality — Grouped Bar</h3></div>
        <div class="card-body">
            <div class="legend" style="margin-bottom:10px">
                <?php foreach ($group_data as $i => $gd): ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $group_colors[$i % count($group_colors)] ?>"></div>
                    <?= htmlspecialchars($gd['label']) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="height:260px"><canvas id="mortChart"></canvas></div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-table"></i> Deaths by Cause — Comparison</h3></div>
        <div class="card-body">
            <?php
            $mort_causes = ['All-Cause','HIV','TB','Maternal'];
            $mort_colors = ['rose','amber','blue','teal'];
            foreach ($mort_causes as $mi => $cause):
                $vals = array_map(fn($gd) => $gd['mort'][$cause], $group_data);
                $max_v = max(1, ...$vals);
            ?>
            <div style="margin-bottom:14px">
                <div style="font-size:12px;font-weight:700;color:var(--<?= $mort_colors[$mi] ?>);margin-bottom:5px"><?= $cause ?> Deaths</div>
                <?php foreach ($group_data as $i => $gd):
                    $val = $gd['mort'][$cause];
                    $bp  = $max_v > 0 ? round($val / $max_v * 100) : 0;
                    $color = $group_colors[$i % count($group_colors)];
                ?>
                <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
                    <div style="width:72px;font-size:10px;font-weight:600;color:<?= $color ?>;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($gd['label']) ?></div>
                    <div style="flex:1;height:8px;background:#f0f0f0;border-radius:99px;overflow:hidden">
                        <div style="width:<?= $bp ?>%;height:100%;background:<?= $color ?>;border-radius:99px;transition:width 1s"></div>
                    </div>
                    <span style="font-size:12px;font-weight:800;min-width:40px;text-align:right;color:<?= $color ?>"><?= number_format($val) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ($num_groups===2):
                    $diff3 = $vals[1]-$vals[0];
                    $cls3 = $diff3>0?'delta-down':($diff3<0?'delta-up':'delta-neutral'); // for deaths, decrease is good
                ?>
                <div style="margin-top:2px">
                    <span class="delta <?= $cls3 ?>"><?= $diff3>=0?'+':'' ?><?= number_format($diff3) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     FULL COMPARISON TABLE (summary)
     ══════════════════════════════════════════════════════ -->
<div class="section-label">Full Comparison Summary Table</div>
<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-table"></i> All Indicators — <?= $num_groups ?>-Way Comparison</h3>
        <span class="card-badge">All sections</span>
    </div>
    <div style="overflow-x:auto">
        <table class="dash-table">
            <thead>
                <tr>
                    <th style="min-width:200px">Indicator</th>
                    <?php foreach ($group_data as $i => $gd): ?>
                    <th style="color:<?= $group_colors[$i % count($group_colors)] ?>"><?= htmlspecialchars($gd['label']) ?></th>
                    <?php endforeach; ?>
                    <?php if ($num_groups===2): ?><th>Change</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <!-- KPIs -->
                <tr><td colspan="<?= $num_groups+($num_groups===2?2:1) ?>" style="background:#f8fafc;font-weight:700;font-size:11px;color:var(--navy);text-transform:uppercase;letter-spacing:.5px">Key Performance Indicators</td></tr>
                <?php foreach ($kpi_defs as [$lbl2,$key2,$icon2,$col2]):
                    $vals2 = array_map(fn($gd) => (int)($gd['totals'][$key2]??0), $group_data);
                ?>
                <tr>
                    <td><i class="fas <?= $icon2 ?>" style="color:var(--<?= $col2 ?>);margin-right:5px;font-size:11px"></i><?= $lbl2 ?></td>
                    <?php foreach ($vals2 as $j => $v2): ?>
                    <td style="font-weight:700;color:<?= $group_colors[$j % count($group_colors)] ?>"><?= number_format($v2) ?></td>
                    <?php endforeach; ?>
                    <?php if ($num_groups===2):
                        $d4=$vals2[1]-$vals2[0];$c4=$d4>0?'delta-up':($d4<0?'delta-down':'delta-neutral');
                    ?><td><span class="delta <?= $c4 ?>"><?= $d4>=0?'+':'' ?><?= number_format($d4) ?></span></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>

                <!-- S2b -->
                <tr><td colspan="<?= $num_groups+($num_groups===2?2:1) ?>" style="background:#f8fafc;font-weight:700;font-size:11px;color:var(--navy);text-transform:uppercase;letter-spacing:.5px">Section 2b — Service Integration (%)</td></tr>
                <?php foreach ($s2b_fields as $f => $lbl3):
                    $vals3 = array_map(fn($gd) => $gd['s2b'][$f]['pct'], $group_data);
                ?>
                <tr>
                    <td><?= $lbl3 ?></td>
                    <?php foreach ($vals3 as $j => $v3): ?>
                    <td><span class="pill <?= $v3>=70?'pill-green':($v3>=40?'pill-amber':'pill-rose') ?>"><?= $v3 ?>%</span></td>
                    <?php endforeach; ?>
                    <?php if ($num_groups===2):
                        $d5=$vals3[1]-$vals3[0];$c5=$d5>0?'delta-up':($d5<0?'delta-down':'delta-neutral');
                    ?><td><span class="delta <?= $c5 ?>"><?= $d5>=0?'+':'' ?><?= $d5 ?>pp</span></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>

                <!-- SHA/FIF -->
                <tr><td colspan="<?= $num_groups+($num_groups===2?2:1) ?>" style="background:#f8fafc;font-weight:700;font-size:11px;color:var(--navy);text-transform:uppercase;letter-spacing:.5px">Sections 4–6 — SHA &amp; Financing (%)</td></tr>
                <?php foreach ($s456_yn as $f => $lbl4):
                    $vals4 = array_map(fn($gd) => $gd['s456'][$f]['pct'], $group_data);
                ?>
                <tr>
                    <td><?= $lbl4 ?></td>
                    <?php foreach ($vals4 as $j => $v4): ?>
                    <td><span class="pill <?= $v4>=70?'pill-green':($v4>=40?'pill-amber':'pill-rose') ?>"><?= $v4 ?>%</span></td>
                    <?php endforeach; ?>
                    <?php if ($num_groups===2):
                        $d6=$vals4[1]-$vals4[0];$c6=$d6>0?'delta-up':($d6<0?'delta-down':'delta-neutral');
                    ?><td><span class="delta <?= $c6 ?>"><?= $d6>=0?'+':'' ?><?= $d6 ?>pp</span></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>

                <!-- Mortality -->
                <tr><td colspan="<?= $num_groups+($num_groups===2?2:1) ?>" style="background:#f8fafc;font-weight:700;font-size:11px;color:var(--navy);text-transform:uppercase;letter-spacing:.5px">Section 7 — Mortality</td></tr>
                <?php foreach (['All-Cause','HIV','TB','Maternal'] as $mc):
                    $valsm = array_map(fn($gd) => $gd['mort'][$mc], $group_data);
                ?>
                <tr>
                    <td><?= $mc ?> Deaths</td>
                    <?php foreach ($valsm as $j => $vm): ?>
                    <td style="font-weight:700"><?= number_format($vm) ?></td>
                    <?php endforeach; ?>
                    <?php if ($num_groups===2):
                        $dm=$valsm[1]-$valsm[0];$cm=$dm>0?'delta-down':($dm<0?'delta-up':'delta-neutral');
                    ?><td><span class="delta <?= $cm ?>"><?= $dm>=0?'+':'' ?><?= number_format($dm) ?></span></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="height:50px"></div>
</div><!-- /page -->

<script>
Chart.defaults.font.family = "'Segoe UI','Helvetica Neue',Arial,sans-serif";
Chart.defaults.color = '#6B7280';

const groupColors      = <?= $group_colors_json ?>;
const groupColorsLight = <?= $group_colors_light_json ?>;
const groupLabels      = <?= $group_labels_json ?>;

// ── Shared plugin: always-visible data labels ─────────────────────────────────
const alwaysShowLabels = {
    id: 'alwaysShowLabels',
    afterDatasetsDraw(chart) {
        const {ctx} = chart;
        chart.data.datasets.forEach((dataset, di) => {
            const meta = chart.getDatasetMeta(di);
            if (meta.hidden) return;
            meta.data.forEach((bar, idx) => {
                const val = dataset.data[idx];
                if (val === 0 || val === null) return;
                ctx.save();
                ctx.fillStyle = dataset.backgroundColor || '#333';
                ctx.font = 'bold 10px Segoe UI';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                const {x, y} = bar.tooltipPosition();
                ctx.fillText(val + (dataset._isPct ? '%' : ''), x, y - 3);
                ctx.restore();
            });
        });
    }
};

// Mark pct datasets
function pctDatasets(ds) { ds.forEach(d => d._isPct = true); return ds; }

// ── Radar Chart ──────────────────────────────────────────────────────────────
const radarDs = <?= $kpi_compare_datasets_json ?>;
new Chart(document.getElementById('radarChart'), {
    type: 'radar',
    data: {
        labels: ['Facilities','TX_CURR (÷100)','PLHIVs SHA','HCW PEPFAR','HCW Trans.','TA Visits'],
        datasets: radarDs
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: true, position: 'top',
                labels:{boxWidth:12,font:{size:12},borderRadius:3,useBorderRadius:true} },
            tooltip: { callbacks: { label: c => ` ${c.dataset.label}: ${c.raw}` } }
        },
        scales: {
            r: {
                grid:{color:'#e5e7eb'},
                angleLines:{color:'#e5e7eb'},
                pointLabels:{font:{size:11},color:'#374151'},
                ticks:{display:false}
            }
        }
    }
});

// ── S2b Grouped Bar ───────────────────────────────────────────────────────────
const s2bDs = <?= $chart_s2b_datasets_json ?>;
pctDatasets(s2bDs);
new Chart(document.getElementById('s2bChart'), {
    type: 'bar',
    plugins: [alwaysShowLabels],
    data: {
        labels: <?= $chart_s2b_labels ?>,
        datasets: s2bDs
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: {display: false},
            tooltip: { mode:'index', intersect:false,
                callbacks: {label: c => ` ${c.dataset.label}: ${c.raw}%`} }
        },
        scales: {
            x: { grid:{display:false}, ticks:{font:{size:10},maxRotation:35} },
            y: { max:100, grid:{color:'#f0f0f0'}, ticks:{callback:v=>v+'%'} }
        }
    }
});

// ── EMR Grouped Bar ───────────────────────────────────────────────────────────
const emrDs = <?= $chart_emr_datasets_json ?>;
pctDatasets(emrDs);
new Chart(document.getElementById('emrChart'), {
    type: 'bar',
    plugins: [alwaysShowLabels],
    data: {
        labels: <?= $chart_emr_labels ?>,
        datasets: emrDs
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: {display: false},
            tooltip: { mode:'index', intersect:false,
                callbacks: {label: c => ` ${c.dataset.label}: ${c.raw}%`} }
        },
        scales: {
            x: { grid:{display:false}, ticks:{font:{size:9},maxRotation:40} },
            y: { max:100, grid:{color:'#f0f0f0'}, ticks:{callback:v=>v+'%'} }
        }
    }
});

// ── SHA/FIF Grouped Bar ───────────────────────────────────────────────────────
const s456Ds = <?= $chart_s456_datasets_json ?>;
pctDatasets(s456Ds);
new Chart(document.getElementById('s456Chart'), {
    type: 'bar',
    plugins: [alwaysShowLabels],
    data: {
        labels: <?= $chart_s456_labels ?>,
        datasets: s456Ds
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: {display:false},
            tooltip: { mode:'index', intersect:false,
                callbacks: {label: c => ` ${c.dataset.label}: ${c.raw}%`} }
        },
        scales: {
            x: { grid:{display:false}, ticks:{font:{size:10},maxRotation:30} },
            y: { max:100, grid:{color:'#f0f0f0'}, ticks:{callback:v=>v+'%'} }
        }
    }
});

// ── Mortality Grouped Bar ─────────────────────────────────────────────────────
const mortDs = <?= $mort_datasets_json ?>;
new Chart(document.getElementById('mortChart'), {
    type: 'bar',
    plugins: [alwaysShowLabels],
    data: {
        labels: <?= $mort_labels ?>,
        datasets: mortDs
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: {display:false},
            tooltip: { mode:'index', intersect:false,
                callbacks: {label: c => ` ${c.dataset.label}: ${c.raw} deaths`} }
        },
        scales: {
            x: { grid:{display:false} },
            y: { grid:{color:'#f0f0f0'}, ticks:{stepSize:1} }
        }
    }
});

// ── HRH Grouped Bar ──────────────────────────────────────────────────────────
new Chart(document.getElementById('hrhChart'), {
    type: 'bar',
    plugins: [alwaysShowLabels],
    data: {
        labels: <?= $hrh_cat_labels ?>,
        datasets: <?= $hrh_all_datasets_json ?>
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: {display:true, position:'top',
                labels:{boxWidth:12,font:{size:11},borderRadius:3,useBorderRadius:true}},
            tooltip: { mode:'index', intersect:false }
        },
        scales: {
            x: { grid:{display:false} },
            y: { grid:{color:'#f0f0f0'}, ticks:{stepSize:1} }
        }
    }
});

// ── Tom Select initialisation ─────────────────────────────────────────────────
['sel-periods','sel-counties','sel-levels'].forEach(id => {
    new TomSelect('#'+id, {
        plugins: ['remove_button','checkbox_options'],
        maxItems: null,
        placeholder: document.getElementById(id).getAttribute('placeholder'),
        render: {
            option: (data, escape) => `<div class="option">${escape(data.text)}</div>`,
            item:   (data, escape) => `<div class="item">${escape(data.text)}</div>`
        }
    });
});
</script>
</body>
</html>