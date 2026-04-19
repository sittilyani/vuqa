<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

// Get user role for permission checks
$user_role = $_SESSION['role'] ?? '';
$is_super_admin = ($user_role === 'Super Admin');
$is_admin = ($user_role === 'Admin' || $user_role === 'Super Admin');

// Handle Status Update (Admin/SuperAdmin only)
if (isset($_POST['update_status']) && isset($_POST['assessment_id']) && $is_admin) {
    $aid = (int)$_POST['assessment_id'];
    $new_status = mysqli_real_escape_string($conn, $_POST['new_status']);
    $valid_statuses = ['Draft', 'Complete', 'Submitted'];

    if (in_array($new_status, $valid_statuses)) {
        if (mysqli_query($conn, "UPDATE integration_assessments SET assessment_status='$new_status', last_saved_at=NOW() WHERE assessment_id=$aid")) {
            $_SESSION['success_msg'] = "Assessment #$aid status updated to '$new_status' successfully.";
        } else {
            $_SESSION['error_msg'] = "Error updating status: " . mysqli_error($conn);
        }
    } else {
        $_SESSION['error_msg'] = "Invalid status value.";
    }
    header('Location: facility_integration_assessment_list.php');
    exit();
}

// Handle DELETE with soft delete for Super Admin only
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int)$_GET['delete'];

    // Check if user has permission to delete
    if (!$is_super_admin) {
        $_SESSION['error_msg'] = 'You do not have permission to delete assessments. Only Super Admin can delete.';
        header('Location: facility_integration_assessment_list.php');
        exit();
    }

    // Get assessment data before deletion for archiving
    $assessment_query = mysqli_query($conn, "SELECT * FROM integration_assessments WHERE assessment_id=$did");
    if ($assessment = mysqli_fetch_assoc($assessment_query)) {
        // Get EMR systems for this assessment
        $emr_systems = [];
        $emr_query = mysqli_query($conn, "SELECT * FROM integration_assessment_emr_systems WHERE assessment_id=$did");
        while ($emr = mysqli_fetch_assoc($emr_query)) {
            $emr_systems[] = $emr;
        }

        // Build INSERT query for deleted table (simplified - use the full version from previous response)
        $deleted_data = [
            'original_assessment_id' => $assessment['assessment_id'],
            'facility_id' => $assessment['facility_id'],
            'assessment_period' => $assessment['assessment_period'],
            'facility_name' => $assessment['facility_name'],
            'mflcode' => $assessment['mflcode'],
            'county_name' => $assessment['county_name'],
            'subcounty_name' => $assessment['subcounty_name'],
            'owner' => $assessment['owner'],
            'sdp' => $assessment['sdp'],
            'agency' => $assessment['agency'],
            'emr' => $assessment['emr'],
            'emrstatus' => $assessment['emrstatus'],
            'infrastructuretype' => $assessment['infrastructuretype'],
            'latitude' => $assessment['latitude'],
            'longitude' => $assessment['longitude'],
            'level_of_care_name' => $assessment['level_of_care_name'],
            'assessment_status' => $assessment['assessment_status'],
            'sections_saved' => $assessment['sections_saved'],
            'collected_by' => $assessment['collected_by'],
            'collection_date' => $assessment['collection_date'],
            'supported_by_usdos_ip' => $assessment['supported_by_usdos_ip'],
            'is_art_site' => $assessment['is_art_site'],
            'hiv_tb_integrated' => $assessment['hiv_tb_integrated'],
            'hiv_tb_integration_model' => $assessment['hiv_tb_integration_model'],
            'tx_curr' => $assessment['tx_curr'],
            'tx_curr_pmtct' => $assessment['tx_curr_pmtct'],
            'plhiv_integrated_care' => $assessment['plhiv_integrated_care'],
            'pmtct_integrated_mnch' => $assessment['pmtct_integrated_mnch'],
            'hts_integrated_opd' => $assessment['hts_integrated_opd'],
            'hts_integrated_ipd' => $assessment['hts_integrated_ipd'],
            'hts_integrated_mnch' => $assessment['hts_integrated_mnch'],
            'prep_integrated_opd' => $assessment['prep_integrated_opd'],
            'prep_integrated_ipd' => $assessment['prep_integrated_ipd'],
            'prep_integrated_mnch' => $assessment['prep_integrated_mnch'],
            'uses_emr' => $assessment['uses_emr'],
            'no_emr_reasons' => $assessment['no_emr_reasons'],
            'single_unified_emr' => $assessment['single_unified_emr'],
            'emr_at_opd' => $assessment['emr_at_opd'],
            'emr_opd_other' => $assessment['emr_opd_other'],
            'emr_at_ipd' => $assessment['emr_at_ipd'],
            'emr_ipd_other' => $assessment['emr_ipd_other'],
            'emr_at_mnch' => $assessment['emr_at_mnch'],
            'emr_mnch_other' => $assessment['emr_mnch_other'],
            'emr_at_ccc' => $assessment['emr_at_ccc'],
            'emr_ccc_other' => $assessment['emr_ccc_other'],
            'emr_at_pmtct' => $assessment['emr_at_pmtct'],
            'emr_pmtct_other' => $assessment['emr_pmtct_other'],
            'emr_at_lab' => $assessment['emr_at_lab'],
            'emr_lab_other' => $assessment['emr_lab_other'],
            'lab_manifest_in_use' => $assessment['lab_manifest_in_use'],
            'tibu_lite_lims_in_use' => $assessment['tibu_lite_lims_in_use'],
            'emr_at_pharmacy' => $assessment['emr_at_pharmacy'],
            'emr_pharmacy_other' => $assessment['emr_pharmacy_other'],
            'pharmacy_webadt_in_use' => $assessment['pharmacy_webadt_in_use'],
            'emr_interoperable_his' => $assessment['emr_interoperable_his'],
            'hcw_total_pepfar' => $assessment['hcw_total_pepfar'],
            'hcw_clinical_pepfar' => $assessment['hcw_clinical_pepfar'],
            'hcw_nonclinical_pepfar' => $assessment['hcw_nonclinical_pepfar'],
            'hcw_data_pepfar' => $assessment['hcw_data_pepfar'],
            'hcw_community_pepfar' => $assessment['hcw_community_pepfar'],
            'hcw_other_pepfar' => $assessment['hcw_other_pepfar'],
            'hcw_transitioned_clinical' => $assessment['hcw_transitioned_clinical'],
            'hcw_transitioned_nonclinical' => $assessment['hcw_transitioned_nonclinical'],
            'hcw_transitioned_data' => $assessment['hcw_transitioned_data'],
            'hcw_transitioned_community' => $assessment['hcw_transitioned_community'],
            'hcw_transitioned_other' => $assessment['hcw_transitioned_other'],
            'plhiv_enrolled_sha' => $assessment['plhiv_enrolled_sha'],
            'plhiv_sha_premium_paid' => $assessment['plhiv_sha_premium_paid'],
            'pbfw_enrolled_sha' => $assessment['pbfw_enrolled_sha'],
            'pbfw_sha_premium_paid' => $assessment['pbfw_sha_premium_paid'],
            'sha_claims_submitted_ontime' => $assessment['sha_claims_submitted_ontime'],
            'sha_reimbursements_monthly' => $assessment['sha_reimbursements_monthly'],
            'ta_visits_total' => $assessment['ta_visits_total'],
            'ta_visits_moh_only' => $assessment['ta_visits_moh_only'],
            'fif_collection_in_place' => $assessment['fif_collection_in_place'],
            'fif_includes_hiv_tb_pmtct' => $assessment['fif_includes_hiv_tb_pmtct'],
            'sha_capitation_hiv_tb' => $assessment['sha_capitation_hiv_tb'],
            'deaths_all_cause' => $assessment['deaths_all_cause'],
            'deaths_hiv_related' => $assessment['deaths_hiv_related'],
            'deaths_hiv_pre_art' => $assessment['deaths_hiv_pre_art'],
            'deaths_tb' => $assessment['deaths_tb'],
            'deaths_maternal' => $assessment['deaths_maternal'],
            'deaths_perinatal' => $assessment['deaths_perinatal'],
            'leadership_commitment' => $assessment['leadership_commitment'],
            'transition_plan' => $assessment['transition_plan'],
            'hiv_in_awp' => $assessment['hiv_in_awp'],
            'hrh_gap' => $assessment['hrh_gap'],
            'staff_multiskilled' => $assessment['staff_multiskilled'],
            'roving_staff' => $assessment['roving_staff'],
            'infrastructure_capacity' => $assessment['infrastructure_capacity'],
            'space_adequacy' => $assessment['space_adequacy'],
            'service_delivery_without_ccc' => $assessment['service_delivery_without_ccc'],
            'avg_wait_time' => $assessment['avg_wait_time'],
            'data_integration_level' => $assessment['data_integration_level'],
            'financing_coverage' => $assessment['financing_coverage'],
            'disruption_risk' => $assessment['disruption_risk'],
            'integration_barriers' => $assessment['integration_barriers'],
            'lab_specimen_referral' => $assessment['lab_specimen_referral'],
            'lab_referral_county_funded' => $assessment['lab_referral_county_funded'],
            'lab_iso15189_accredited' => $assessment['lab_iso15189_accredited'],
            'lab_kenas_fee_support' => $assessment['lab_kenas_fee_support'],
            'lab_lcqi_implementing' => $assessment['lab_lcqi_implementing'],
            'lab_lcqi_internal_audits' => $assessment['lab_lcqi_internal_audits'],
            'lab_eqa_all_tests' => $assessment['lab_eqa_all_tests'],
            'lab_sla_equipment' => $assessment['lab_sla_equipment'],
            'lab_sla_support' => $assessment['lab_sla_support'],
            'lab_lims_in_place' => $assessment['lab_lims_in_place'],
            'lab_lims_emr_integrated' => $assessment['lab_lims_emr_integrated'],
            'lab_lims_interoperable' => $assessment['lab_lims_interoperable'],
            'lab_his_integration_guide' => $assessment['lab_his_integration_guide'],
            'lab_dedicated_his_staff' => $assessment['lab_dedicated_his_staff'],
            'lab_bsc_calibration_current' => $assessment['lab_bsc_calibration_current'],
            'lab_shipping_cost_support' => $assessment['lab_shipping_cost_support'],
            'lab_biosafety_trained' => $assessment['lab_biosafety_trained'],
            'lab_hepb_vaccinated' => $assessment['lab_hepb_vaccinated'],
            'lab_ipc_committee' => $assessment['lab_ipc_committee'],
            'lab_ipc_workplan' => $assessment['lab_ipc_workplan'],
            'lab_moh_virtual_academy' => $assessment['lab_moh_virtual_academy'],
            'comm_hiv_feedback_mechanism' => $assessment['comm_hiv_feedback_mechanism'],
            'comm_roc_feedback_used' => $assessment['comm_roc_feedback_used'],
            'comm_community_representation' => $assessment['comm_community_representation'],
            'comm_plhiv_in_discussions' => $assessment['comm_plhiv_in_discussions'],
            'comm_health_talks_plhiv' => $assessment['comm_health_talks_plhiv'],
            'sc_khis_reports_monthly' => $assessment['sc_khis_reports_monthly'],
            'sc_stockout_arvs' => $assessment['sc_stockout_arvs'],
            'sc_stockout_tb_drugs' => $assessment['sc_stockout_tb_drugs'],
            'sc_stockout_hiv_reagents' => $assessment['sc_stockout_hiv_reagents'],
            'sc_stockout_tb_reagents' => $assessment['sc_stockout_tb_reagents'],
            'phc_chp_referrals' => $assessment['phc_chp_referrals'],
            'phc_chwp_tracing' => $assessment['phc_chwp_tracing'],
            'last_section_saved' => $assessment['last_section_saved'],
            'last_saved_at' => $assessment['last_saved_at'],
            'last_saved_by' => $assessment['last_saved_by'],
            'deleted_by' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown',
            'deleted_at' => date('Y-m-d H:i:s')
        ];

        $columns = array_keys($deleted_data);
        $values = array_map(function($val) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $val) . "'";
        }, array_values($deleted_data));

        $insert_query = "INSERT INTO deleted_facility_integration_assessments (" . implode(',', $columns) . ")
                         VALUES (" . implode(',', $values) . ")";

        if (mysqli_query($conn, $insert_query)) {
            mysqli_query($conn, "DELETE FROM integration_assessment_emr_systems WHERE assessment_id=$did");
            if (mysqli_query($conn, "DELETE FROM integration_assessments WHERE assessment_id=$did")) {
                $_SESSION['success_msg'] = 'Assessment deleted and archived successfully.';
            } else {
                $_SESSION['error_msg'] = 'Error deleting assessment: ' . mysqli_error($conn);
            }
        } else {
            $_SESSION['error_msg'] = 'Error archiving assessment: ' . mysqli_error($conn);
        }
    } else {
        $_SESSION['error_msg'] = 'Assessment not found.';
    }

    header('Location: facility_integration_assessment_list.php');
    exit();
}

$period=$_GET['period']??''; $county=$_GET['county']??''; $agency=$_GET['agency']??'';
$level_of_care=$_GET['level_of_care']??''; $date_from=$_GET['date_from']??''; $date_to=$_GET['date_to']??'';
$art_site=$_GET['art_site']??''; $uses_emr=$_GET['uses_emr']??''; $status_f=$_GET['status']??'';
$leadership=$_GET['leadership']??''; $data_integration=$_GET['data_integration']??'';

$esc = fn($v) => mysqli_real_escape_string($conn, $v);
$where="WHERE 1=1";
if($period)         $where.=" AND assessment_period='{$esc($period)}'";
if($county)         $where.=" AND county_name='{$esc($county)}'";
if($agency)         $where.=" AND agency='{$esc($agency)}'";
if($level_of_care)  $where.=" AND level_of_care_name='{$esc($level_of_care)}'";
if($date_from)      $where.=" AND collection_date>='{$esc($date_from)}'";
if($date_to)        $where.=" AND collection_date<='{$esc($date_to)}'";
if($art_site)       $where.=" AND is_art_site='{$esc($art_site)}'";
if($uses_emr)       $where.=" AND uses_emr='{$esc($uses_emr)}'";
if($status_f)       $where.=" AND assessment_status='{$esc($status_f)}'";
if($leadership)     $where.=" AND leadership_commitment='{$esc($leadership)}'";
if($data_integration) $where.=" AND data_integration_level='{$esc($data_integration)}'";

$page=max(1,(int)($_GET['page']??1)); $limit=20; $offset=($page-1)*$limit;
$total = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM integration_assessments $where"))['c'];
$total_pages = ceil($total/$limit);

$rows_r = mysqli_query($conn,"
    SELECT assessment_id, assessment_period, facility_name, mflcode, county_name,
           level_of_care_name, is_art_site, uses_emr, supported_by_usdos_ip,
           tx_curr, plhiv_enrolled_sha, assessment_status, sections_saved,
           collected_by, collection_date, last_saved_at, last_saved_by, created_at
    FROM integration_assessments $where ORDER BY created_at DESC LIMIT $offset,$limit");

$periods_r     = mysqli_query($conn,"SELECT DISTINCT assessment_period FROM integration_assessments WHERE assessment_period!='' ORDER BY assessment_period DESC");
$counties_r    = mysqli_query($conn,"SELECT DISTINCT county_name FROM integration_assessments WHERE county_name!='' ORDER BY county_name");
$agencies_r    = mysqli_query($conn,"SELECT DISTINCT agency FROM integration_assessments WHERE agency!='' ORDER BY agency");
$care_levels_r = mysqli_query($conn,"SELECT DISTINCT level_of_care_name FROM integration_assessments WHERE level_of_care_name!='' ORDER BY level_of_care_name");

$all_section_defs = ['s1','s2a','s2b','s2c','s3','s4','s5','s6','s7','s8_readiness','s8_lab','s9','s10','s11'];
$total_sections = count($all_section_defs);

$success_msg=$_SESSION['success_msg']??''; $error_msg=$_SESSION['error_msg']??'';
unset($_SESSION['success_msg'],$_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Facility Integration Assessments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root{--navy:#0D1A63;--navy2:#1a3a9e;--teal:#0ABFBC;--green:#27AE60;--amber:#F5A623;--rose:#E74C3C;--bg:#f0f2f7;--card:#fff;--border:#e2e8f0;--muted:#6B7280;--shadow:0 2px 16px rgba(13,26,99,.07);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#1a1e2e;line-height:1.6;}
.container{max-width:1600px;margin:0 auto;padding:20px;}
.page-header{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:20px 28px;border-radius:14px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(13,26,99,.22);}
.page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.hdr-links a:hover{background:rgba(255,255,255,.28);}
.hdr-links a.active{background:#fff;color:var(--navy);font-weight:700;}
.alert{padding:12px 18px;border-radius:9px;margin-bottom:16px;font-size:13.5px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
/* Filters */
.filters{background:var(--card);border-radius:12px;padding:16px 20px;margin-bottom:20px;box-shadow:var(--shadow);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.fg{flex:1;min-width:130px;}
.fg label{display:block;font-size:10px;font-weight:700;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}
.fg input,.fg select{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;transition:.2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--navy);}
.btn-filter{background:var(--navy);color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;height:36px;}
.btn-filter:hover{background:var(--navy2);}
.btn-reset{background:#f3f4f6;color:var(--muted);border:none;padding:9px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;height:36px;}
.btn-reset:hover{background:#e5e7eb;}
/* KPI row */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;}
.kpi{background:var(--card);border-radius:12px;padding:16px 18px;box-shadow:var(--shadow);border-top:3px solid var(--kc,var(--navy));}
.kpi-val{font-size:28px;font-weight:900;color:var(--kc,var(--navy));line-height:1;}
.kpi-lbl{font-size:11px;color:var(--muted);margin-top:3px;font-weight:500;}
/* Table */
.table-card{background:var(--card);border-radius:12px;box-shadow:var(--shadow);overflow:hidden;}
.table-top{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:13px;}
.table-responsive{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{text-align:left;padding:11px 12px;background:#f8fafc;color:var(--navy);font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}
td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f8faff;}
/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;}
.b-success{background:#d4edda;color:#155724;}.b-warning{background:#fff3cd;color:#856404;}
.b-danger{background:#f8d7da;color:#721c24;}.b-info{background:#d1ecf1;color:#0c5460;}
.b-draft{background:#e0e7ff;color:#3730a3;}.b-complete{background:#d4edda;color:#155724;}
.b-submitted{background:#cff4fc;color:#0c5460;}
/* Progress bar */
.prog-bar{width:80px;height:7px;background:#e5e7eb;border-radius:99px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:6px;}
.prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--teal),var(--green));}
/* Actions */
.actions{display:flex;gap:6px;flex-wrap:wrap;}
.btn-icon{padding:5px 9px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;transition:.15s;display:inline-flex;align-items:center;gap:4px;border:none;cursor:pointer;}
.btn-view{background:#e8edf8;color:var(--navy);}
.btn-view:hover{background:#d6dff0;}
.btn-edit{background:#fff3cd;color:#856404;}
.btn-edit:hover{background:#ffe69c;}
.btn-delete{background:#f8d7da;color:#721c24;}
.btn-delete:hover{background:#f5c6cb;}
.btn-delete-disabled{background:#e2e3e5;color:#6c757d;cursor:not-allowed;}
.btn-status{background:#e8edf8;color:var(--navy);font-size:10px;padding:4px 8px;}
.btn-status:hover{background:#d6dff0;}
/* Status dropdown */
.status-form{display:inline-block;margin:0;padding:0;}
.status-select{font-size:10px;padding:4px 6px;border-radius:4px;border:1px solid var(--border);background:#fff;}
.status-submit{background:var(--green);color:#fff;border:none;padding:4px 8px;border-radius:4px;font-size:10px;cursor:pointer;margin-left:4px;}
/* Pagination */
.pagination{display:flex;justify-content:center;gap:6px;margin:20px 0;}
.page-link{display:block;padding:7px 13px;background:var(--card);border:1px solid var(--border);border-radius:7px;color:var(--navy);text-decoration:none;font-size:13px;font-weight:600;transition:.15s;}
.page-link:hover{background:#e8edf8;border-color:var(--navy);}
.page-link.active{background:var(--navy);color:#fff;border-color:var(--navy);}
@media(max-width:700px){.filters{gap:8px;}.kpi-row{grid-template-columns:1fr 1fr;}}
</style>
</head>
<body>
<div class="container">

<div class="page-header">
    <h1><i class="fas fa-clipboard-list"></i> Facility Integration Assessments Results</h1>
    <div class="hdr-links">
        <a href="facility_integration_assessment.php"><i class="fas fa-plus"></i> New Assessment</a>
        <a href="facility_integration_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="facility_integration_assessment_list.php" class="active"><i class="fas fa-list"></i> List</a>
    </div>
</div>

<?php if($success_msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
<?php if($error_msg): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_msg) ?></div><?php endif; ?>

<!-- Filters -->
<form method="GET" class="filters">
    <div class="fg"><label>Period</label>
        <select name="period"><option value="">All Periods</option>
        <?php while($p=mysqli_fetch_assoc($periods_r)): ?>
        <option value="<?= htmlspecialchars($p['assessment_period']) ?>" <?= $period===$p['assessment_period']?'selected':'' ?>><?= htmlspecialchars($p['assessment_period']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>County</label>
        <select name="county"><option value="">All Counties</option>
        <?php while($c=mysqli_fetch_assoc($counties_r)): ?>
        <option value="<?= htmlspecialchars($c['county_name']) ?>" <?= $county===$c['county_name']?'selected':'' ?>><?= htmlspecialchars($c['county_name']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>Agency</label>
        <select name="agency"><option value="">All</option>
        <?php while($a=mysqli_fetch_assoc($agencies_r)): ?>
        <option value="<?= htmlspecialchars($a['agency']) ?>" <?= $agency===$a['agency']?'selected':'' ?>><?= htmlspecialchars($a['agency']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>Level of Care</label>
        <select name="level_of_care"><option value="">All</option>
        <?php while($l=mysqli_fetch_assoc($care_levels_r)): ?>
        <option value="<?= htmlspecialchars($l['level_of_care_name']) ?>" <?= $level_of_care===$l['level_of_care_name']?'selected':'' ?>><?= htmlspecialchars($l['level_of_care_name']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>Status</label>
        <select name="status"><option value="">All</option>
        <?php foreach(['Draft','Complete','Submitted'] as $s): ?>
        <option value="<?= $s ?>" <?= $status_f===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?></select></div>
    <div class="fg"><label>ART Site</label>
        <select name="art_site"><option value="">All</option>
        <option value="Yes" <?= $art_site==='Yes'?'selected':'' ?>>Yes</option>
        <option value="No" <?= $art_site==='No'?'selected':'' ?>>No</option></select></div>
    <div class="fg"><label>Uses EMR</label>
        <select name="uses_emr"><option value="">All</option>
        <option value="Yes" <?= $uses_emr==='Yes'?'selected':'' ?>>Yes</option>
        <option value="No" <?= $uses_emr==='No'?'selected':'' ?>>No</option></select></div>
    <div class="fg"><label>From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
    <div class="fg"><label>To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
    <div style="display:flex;gap:7px;align-items:flex-end">
        <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
        <a href="facility_integration_assessment_list.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<?php
// KPI counts
$kpi_all = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) total,
     SUM(assessment_status='Draft') drafts,
     SUM(assessment_status='Complete') complete,
     SUM(assessment_status='Submitted') submitted,
     COUNT(DISTINCT county_name) counties
     FROM integration_assessments $where"));
?>
<div class="kpi-row">
    <div class="kpi" style="--kc:var(--navy)"><div class="kpi-val"><?= $kpi_all['total'] ?></div><div class="kpi-lbl">Total Assessments</div></div>
    <div class="kpi" style="--kc:#3730a3"><div class="kpi-val"><?= $kpi_all['drafts'] ?></div><div class="kpi-lbl">Draft (In Progress)</div></div>
    <div class="kpi" style="--kc:var(--green)"><div class="kpi-val"><?= $kpi_all['complete'] ?></div><div class="kpi-lbl">Complete (Ready)</div></div>
    <div class="kpi" style="--kc:var(--teal)"><div class="kpi-val"><?= $kpi_all['submitted'] ?></div><div class="kpi-lbl">Submitted</div></div>
    <div class="kpi" style="--kc:var(--amber)"><div class="kpi-val"><?= $kpi_all['counties'] ?></div><div class="kpi-lbl">Counties</div></div>
</div>

<!-- Table -->
<div class="table-card">
    <div class="table-top">
        <span>Showing <strong><?= min($limit,$total) ?></strong> of <strong><?= number_format($total) ?></strong> assessments</span>
        <span style="font-size:12px;color:var(--muted)"><i class="fas fa-info-circle"></i> Progress shows sections saved out of <?= $total_sections ?></span>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Period</th><th>Facility</th><th>MFL</th><th>County</th>
                    <th>Level</th><th>ART</th><th>EMR</th><th>Status</th><th>Progress</th>
                    <th>TX_CURR</th><th>SHA Enrolled</th><th>Collected By</th><th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if(mysqli_num_rows($rows_r)>0): while($row=mysqli_fetch_assoc($rows_r)):
                $ss=json_decode($row['sections_saved']??'[]',true)?:[];
                $n=count($ss); $pct=round($n/$total_sections*100);
                $status=$row['assessment_status']??'Draft';
                $status_cls=['Draft'=>'b-draft','Complete'=>'b-complete','Submitted'=>'b-submitted'][$status]??'b-draft';
                $can_delete = $is_super_admin;
                $can_update_status = $is_admin;
            ?>
            <tr>
                <td><strong style="color:var(--navy)">#<?= $row['assessment_id'] ?></strong></td>
                <td><?= htmlspecialchars($row['assessment_period']??'') ?></td>
                <td><strong><?= htmlspecialchars($row['facility_name']??'') ?></strong></td>
                <td style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($row['mflcode']??'') ?></td>
                <td><?= htmlspecialchars($row['county_name']??'') ?></td>
                <td><?= htmlspecialchars($row['level_of_care_name']??'') ?></td>
                <td><span class="badge <?= $row['is_art_site']==='Yes'?'b-success':'b-danger' ?>"><?= $row['is_art_site']??'—' ?></span></td>
                <td><span class="badge <?= $row['uses_emr']==='Yes'?'b-success':($row['uses_emr']==='No'?'b-warning':'b-info') ?>"><?= $row['uses_emr']??'—' ?></span></td>
                <td>
                    <span class="badge <?= $status_cls ?>"><?= $status ?></span>
                    <?php if ($can_update_status && $status === 'Submitted'): ?>
                    <form method="POST" class="status-form" style="display:inline-block; margin-left:5px;" onsubmit="return confirm('Mark this assessment as Complete? This will allow the report to be finalized.')">
                        <input type="hidden" name="assessment_id" value="<?= $row['assessment_id'] ?>">
                        <input type="hidden" name="new_status" value="Complete">
                        <button type="submit" name="update_status" class="btn-status" title="Mark as Complete">
                            <i class="fas fa-check-circle"></i> Mark Complete
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($can_update_status && $status === 'Complete'): ?>
                    <form method="POST" class="status-form" style="display:inline-block; margin-left:5px;" onsubmit="return confirm('Return this assessment to Submitted status?')">
                        <input type="hidden" name="assessment_id" value="<?= $row['assessment_id'] ?>">
                        <input type="hidden" name="new_status" value="Submitted">
                        <button type="submit" name="update_status" class="btn-status" style="background:#ffc107;color:#000;" title="Return to Submitted">
                            <i class="fas fa-undo"></i> Revert
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
                <td>
                    <span style="font-size:11px;font-weight:700;color:<?= $pct>=100?'#155724':($pct>=50?'#856404':'#721c24') ?>"><?= $n ?>/<?= $total_sections ?></span>
                    <span class="prog-bar"><span class="prog-fill" style="width:<?= $pct ?>%"></span></span>
                </td>
                <td><?= number_format($row['tx_curr']??0) ?></td>
                <td><?= number_format($row['plhiv_enrolled_sha']??0) ?></td>
                <td style="font-size:11px"><?= htmlspecialchars($row['collected_by']??'') ?></td>
                <td style="font-size:11px"><?= $row['collection_date']?date('d M Y',strtotime($row['collection_date'])):'—' ?></td>
                <td class="actions">
                    <!-- VIEW BUTTON - Always visible for all statuses -->
                    <a href="view_facility_integration_assessment.php?id=<?= $row['assessment_id'] ?>" class="btn-icon btn-view" title="View Assessment">
                        <i class="fas fa-eye"></i> View
                    </a>

                    <!-- EDIT BUTTON - For Draft/Complete status, or Admin can edit Submitted -->
                    <?php if ($status === 'Draft' || $status === 'Complete' || ($status === 'Submitted' && $is_admin)): ?>
                    <a href="facility_integration_assessment.php?id=<?= $row['assessment_id'] ?>&edit=1" class="btn-icon btn-edit" title="Edit Assessment">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php endif; ?>

                    <!-- DELETE BUTTON - Super Admin only -->
                    <?php if ($can_delete): ?>
                    <a href="?delete=<?= $row['assessment_id'] ?>" class="btn-icon btn-delete" title="Delete"
                       onclick="return confirm('Delete assessment #<?= $row['assessment_id'] ?> for <?= addslashes($row['facility_name']??'') ?>? This action will archive the data and cannot be undone.')">
                        <i class="fas fa-trash"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="15" style="text-align:center;padding:50px;color:var(--muted)">
                <i class="fas fa-folder-open" style="font-size:38px;display:block;margin-bottom:10px;opacity:.4"></i>
                No assessments found
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if($total_pages>1): ?>
<div class="pagination">
    <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-link"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="page-link <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if($page<$total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-link"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>
</div>
</body>
</html>