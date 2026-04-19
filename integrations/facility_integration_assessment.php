<?php
// integrations/facility_integration_assessment.php
session_start();

// Fix the include path - use absolute path relative to the file
$base_path = dirname(__DIR__); // Go up one level from 'integrations' folder
$config_path = $base_path . '/includes/config.php';
$session_check_path = $base_path . '/includes/session_check.php';

// Check if config file exists
if (!file_exists($config_path)) {
    die('Configuration file not found. Please check the path: ' . $config_path);
}

include($config_path);
include($session_check_path);

// Verify database connection
if (!isset($conn) || !$conn) {
    die('Database connection failed. Please check your config.php file.');
}

// Test the connection
if (!mysqli_ping($conn)) {
    die('Database connection lost. Please check your database server.');
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$collected_by = $_SESSION['full_name'] ?? '';
$uid = (int)$_SESSION['user_id'];

// Determine if this is view mode or edit mode
$edit_mode = isset($_GET['edit']) || !isset($_GET['id']);
$view_only = isset($_GET['id']) && !isset($_GET['edit']);

// For view mode, check if assessment exists and set read-only
if ($view_only && isset($_GET['id'])) {
    $check_id = (int)$_GET['id'];
    $check_query = mysqli_query($conn, "SELECT assessment_status FROM integration_assessments WHERE assessment_id = $check_id");
    if ($check_row = mysqli_fetch_assoc($check_query)) {
        $assessment_status = $check_row['assessment_status'];
        $user_role = $_SESSION['role'] ?? '';
        $is_admin = in_array($user_role, ['Admin', 'Super Admin']);

        // If submitted and not admin, force view-only mode
        if ($assessment_status === 'Submitted' && !$is_admin) {
            $view_only = true;
            $edit_mode = false;
            $_SESSION['warning_msg'] = 'This assessment has been submitted and can only be viewed. Contact an administrator to edit.';
        }
    }
}

// ── AJAX: facility search ─────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_facility') {
    $q = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT facility_id, facility_name, mflcode, county_name, subcounty_name,
                    owner, sdp, agency, emr, emrstatus, infrastructuretype,
                    latitude, longitude, level_of_care_name
             FROM facilities
             WHERE (facility_name LIKE '%$q%' OR mflcode LIKE '%$q%' OR county_name LIKE '%$q%')
             ORDER BY facility_name LIMIT 20");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── AJAX: check existing assessment for facility+period ───────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_assessment') {
    $fid    = (int)($_GET['facility_id'] ?? 0);
    $period = mysqli_real_escape_string($conn, $_GET['period'] ?? '');
    $result = ['exists' => false];
    if ($fid && $period) {
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT assessment_id, assessment_status, sections_saved, facility_name
             FROM integration_assessments
             WHERE facility_id=$fid AND assessment_period='$period'
             ORDER BY assessment_id DESC LIMIT 1"));
        if ($row) {
            $result = [
                'exists'         => true,
                'assessment_id'  => $row['assessment_id'],
                'status'         => $row['assessment_status'],
                'sections_saved' => json_decode($row['sections_saved'] ?? '[]', true),
                'facility_name'  => $row['facility_name'],
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}

// ── AJAX: save a single section (only if not view-only) ───────────────────────
if (isset($_POST['ajax_save_section'])) {
    // Check if this is view-only mode
    if ($view_only) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'Cannot save in view-only mode']);
        exit();
    }

    header('Content-Type: application/json');
    $section    = mysqli_real_escape_string($conn, $_POST['section_key'] ?? '');
    $fid        = (int)($_POST['facility_id'] ?? 0);
    $period     = mysqli_real_escape_string($conn, $_POST['assessment_period'] ?? '');
    $aid        = (int)($_POST['assessment_id'] ?? 0);
    $saved_by   = mysqli_real_escape_string($conn, $collected_by);

    if (!$fid || !$period || !$section) {
        echo json_encode(['success'=>false,'error'=>'Missing required fields']);
        exit();
    }

    $e = fn($v) => mysqli_real_escape_string($conn, trim($v ?? ''));
    $i = fn($v) => is_numeric($v) ? (int)$v : 'NULL';

    // ── Get or create assessment header ────────────────────────────────────────
    if (!$aid) {
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT assessment_id, sections_saved FROM integration_assessments
             WHERE facility_id=$fid AND assessment_period='$period' ORDER BY assessment_id DESC LIMIT 1"));
        if ($existing) {
            $aid = (int)$existing['assessment_id'];
        } else {
            // Create draft header
            $fn  = $e($_POST['facility_name']  ?? '');
            $mfl = $e($_POST['mflcode']         ?? '');
            $cn  = $e($_POST['county_name']     ?? '');
            $sc  = $e($_POST['subcounty_name']  ?? '');
            $ow  = $e($_POST['owner']           ?? '');
            $sdp = $e($_POST['sdp']             ?? '');
            $ag  = $e($_POST['agency']          ?? '');
            $em  = $e($_POST['emr']             ?? '');
            $es  = $e($_POST['emrstatus']       ?? '');
            $it  = $e($_POST['infrastructuretype'] ?? '');
            $lat = is_numeric($_POST['latitude'] ?? '') ? (float)$_POST['latitude'] : 'NULL';
            $lng = is_numeric($_POST['longitude'] ?? '') ? (float)$_POST['longitude'] : 'NULL';
            $lv  = $e($_POST['level_of_care_name'] ?? '');
            $cd  = $e($_POST['collection_date'] ?? date('Y-m-d'));
            mysqli_query($conn,
                "INSERT INTO integration_assessments
                 (facility_id, assessment_period, facility_name, mflcode, county_name,
                  subcounty_name, owner, sdp, agency, emr, emrstatus, infrastructuretype,
                  latitude, longitude, level_of_care_name, assessment_status,
                  sections_saved, collected_by, collection_date)
                 VALUES ($fid,'$period','$fn','$mfl','$cn','$sc','$ow','$sdp','$ag',
                         '$em','$es','$it',$lat,$lng,'$lv','Draft',
                         '[]','$saved_by','$cd')");
            $aid = (int)mysqli_insert_id($conn);
        }
    }

    // ── Build SET clause from section (same as before) ──────────────────────────
    $sets = [];

    if ($section === 's1') {
        $sets[] = "supported_by_usdos_ip='{$e($_POST['supported_by_usdos_ip']??'')}'";
        $sets[] = "is_art_site='{$e($_POST['is_art_site']??'')}'";
    }
    if ($section === 's2a') {
        $sets[] = "hiv_tb_integrated='{$e($_POST['hiv_tb_integrated']??'')}'";
        $sets[] = "hiv_tb_integration_model='{$e($_POST['hiv_tb_integration_model']??'')}'";
        $sets[] = "tx_curr={$i($_POST['tx_curr']??'')}";
        $sets[] = "tx_curr_pmtct={$i($_POST['tx_curr_pmtct']??'')}";
        $sets[] = "plhiv_integrated_care={$i($_POST['plhiv_integrated_care']??'')}";
    }
    if ($section === 's2b') {
        foreach(['pmtct_integrated_mnch','hts_integrated_opd','hts_integrated_ipd','hts_integrated_mnch',
                 'prep_integrated_opd','prep_integrated_ipd','prep_integrated_mnch'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
    }
    if ($section === 's2c') {
        $emr_reasons = isset($_POST['no_emr_reasons']) && is_array($_POST['no_emr_reasons'])
            ? $e(implode(',', $_POST['no_emr_reasons'])) : '';
        $sets[] = "uses_emr='{$e($_POST['uses_emr']??'')}'";
        $sets[] = "no_emr_reasons='$emr_reasons'";
        $sets[] = "single_unified_emr='{$e($_POST['single_unified_emr']??'')}'";
        foreach(['emr_at_opd','emr_opd_other','emr_at_ipd','emr_ipd_other','emr_at_mnch','emr_mnch_other',
                 'emr_at_ccc','emr_ccc_other','emr_at_pmtct','emr_pmtct_other','emr_at_lab','emr_lab_other',
                 'lab_manifest_in_use','tibu_lite_lims_in_use','emr_at_pharmacy','emr_pharmacy_other',
                 'pharmacy_webadt_in_use','emr_interoperable_his'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
        // EMR systems — delete and re-insert
        mysqli_query($conn,"DELETE FROM integration_assessment_emr_systems WHERE assessment_id=$aid");
        if (!empty($_POST['emr_type']) && is_array($_POST['emr_type'])) {
            foreach ($_POST['emr_type'] as $k => $et) {
                if (empty(trim($et))) continue;
                $et_s = $e($et); $fb_s = $e($_POST['emr_funded_by'][$k]??'');
                $ds_s = $e($_POST['emr_date_started'][$k]??'');
                $ds_v = $ds_s ? "'$ds_s'" : 'NULL';
                mysqli_query($conn,"INSERT INTO integration_assessment_emr_systems
                    (assessment_id,facility_id,emr_type,funded_by,date_started,sort_order)
                    VALUES ($aid,$fid,'$et_s','$fb_s',$ds_v,".($k+1).")");
            }
        }
    }
    if ($section === 's3') {
        foreach(['hcw_total_pepfar','hcw_clinical_pepfar','hcw_nonclinical_pepfar','hcw_data_pepfar',
                 'hcw_community_pepfar','hcw_other_pepfar','hcw_transitioned_clinical','hcw_transitioned_nonclinical',
                 'hcw_transitioned_data','hcw_transitioned_community','hcw_transitioned_other'] as $f)
            $sets[] = "$f={$i($_POST[$f]??'')}";
    }
    if ($section === 's4') {
        foreach(['plhiv_enrolled_sha','plhiv_sha_premium_paid','pbfw_enrolled_sha','pbfw_sha_premium_paid'] as $f)
            $sets[] = "$f={$i($_POST[$f]??'')}";
        foreach(['sha_claims_submitted_ontime','sha_reimbursements_monthly'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
    }
    if ($section === 's5') {
        $sets[] = "ta_visits_total={$i($_POST['ta_visits_total']??'')}";
        $sets[] = "ta_visits_moh_only={$i($_POST['ta_visits_moh_only']??'')}";
    }
    if ($section === 's6') {
        foreach(['fif_collection_in_place','fif_includes_hiv_tb_pmtct','sha_capitation_hiv_tb'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
    }
    if ($section === 's7') {
        foreach(['deaths_all_cause','deaths_hiv_related','deaths_hiv_pre_art','deaths_tb','deaths_maternal','deaths_perinatal'] as $f)
            $sets[] = "$f={$i($_POST[$f]??'')}";
    }
    if ($section === 's8_readiness') {
        foreach(['leadership_commitment','transition_plan','hiv_in_awp','hrh_gap','staff_multiskilled',
                 'roving_staff','infrastructure_capacity','space_adequacy','service_delivery_without_ccc',
                 'avg_wait_time','data_integration_level','financing_coverage','disruption_risk'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
        $sets[] = "integration_barriers='{$e($_POST['integration_barriers']??'')}'";
    }
    if ($section === 's8_lab') {
        foreach(['lab_specimen_referral','lab_referral_county_funded','lab_iso15189_accredited',
                 'lab_kenas_fee_support','lab_lcqi_implementing','lab_lcqi_internal_audits',
                 'lab_eqa_all_tests','lab_sla_equipment','lab_sla_support','lab_lims_in_place',
                 'lab_lims_emr_integrated','lab_lims_interoperable','lab_his_integration_guide',
                 'lab_dedicated_his_staff','lab_bsc_calibration_current','lab_shipping_cost_support',
                 'lab_biosafety_trained','lab_hepb_vaccinated','lab_ipc_committee','lab_ipc_workplan',
                 'lab_moh_virtual_academy'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
    }
    if ($section === 's9') {
        foreach(['comm_hiv_feedback_mechanism','comm_roc_feedback_used','comm_community_representation',
                 'comm_plhiv_in_discussions'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
        $sets[] = "comm_health_talks_plhiv={$i($_POST['comm_health_talks_plhiv']??'')}";
    }
    if ($section === 's10') {
        foreach(['sc_khis_reports_monthly','sc_stockout_arvs','sc_stockout_tb_drugs',
                 'sc_stockout_hiv_reagents','sc_stockout_tb_reagents'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
    }
    if ($section === 's11') {
        foreach(['phc_chp_referrals','phc_chwp_tracing'] as $f)
            $sets[] = "$f='{$e($_POST[$f]??'')}'";
    }

    // Update sections_saved
    $ss_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT sections_saved FROM integration_assessments WHERE assessment_id=$aid"));
    $ss = json_decode($ss_row['sections_saved'] ?? '[]', true) ?: [];
    if (!in_array($section, $ss)) $ss[] = $section;
    $ss_json = $e(json_encode($ss));

    // Determine all_sections count (14 logical sections)
    $all_sections = ['s1','s2a','s2b','s2c','s3','s4','s5','s6','s7','s8_readiness','s8_lab','s9','s10','s11'];
    $status = (count($ss) >= count($all_sections)) ? 'Complete' : 'Draft';

    $sets[] = "sections_saved='$ss_json'";
    $sets[] = "assessment_status='$status'";
    $sets[] = "last_section_saved='$section'";
    $sets[] = "last_saved_at=NOW()";
    $sets[] = "last_saved_by='$saved_by'";

    if (!empty($sets)) {
        mysqli_query($conn,
            "UPDATE integration_assessments SET ".implode(',',$sets)." WHERE assessment_id=$aid");
    }

    echo json_encode([
        'success'        => true,
        'assessment_id'  => $aid,
        'sections_saved' => $ss,
        'status'         => $status,
        'section'        => $section,
    ]);
    exit();
}

// ── AJAX: final submit (only if not view-only) ────────────────────────────────────────
if (isset($_POST['ajax_submit'])) {
    if ($view_only) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'Cannot submit in view-only mode']);
        exit();
    }

    header('Content-Type: application/json');
    $aid = (int)($_POST['assessment_id'] ?? 0);
    if ($aid) {
        mysqli_query($conn,
            "UPDATE integration_assessments SET assessment_status='Submitted',
             last_saved_by='".mysqli_real_escape_string($conn,$collected_by)."',
             last_saved_at=NOW() WHERE assessment_id=$aid");
        echo json_encode(['success'=>true,'redirect'=>'facility_integration_assessment_list.php']);
    } else {
        echo json_encode(['success'=>false,'error'=>'No assessment ID']);
    }
    exit();
}

// ── Load existing assessment if editing or viewing ───────────────────────────────────────
$edit_id = (int)($_GET['id'] ?? 0);
$existing = null;
$sections_saved = [];
$is_readonly = $view_only;

if ($edit_id) {
    $existing = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM integration_assessments WHERE assessment_id=$edit_id LIMIT 1"));
    if ($existing) {
        $sections_saved = json_decode($existing['sections_saved'] ?? '[]', true) ?: [];

        // If assessment is submitted and user is not admin, force read-only
        if ($existing['assessment_status'] === 'Submitted' && !in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'])) {
            $is_readonly = true;
        }
    }
}

// ── All section definitions for progress tracking ─────────────────────────────
$all_section_defs = [
    's1'           => 'Section 1: Facility Profile',
    's2a'          => 'Section 2a: HIV/TB Services',
    's2b'          => 'Section 2b: PMTCT, HTS & PrEP',
    's2c'          => 'Section 2c: EMR Integration',
    's3'           => 'Section 3: HRH Transition',
    's4'           => 'Section 4: PLHIV & PBFW (SHA)',
    's5'           => 'Section 5: TA / Mentorship',
    's6'           => 'Section 6: Financing',
    's7'           => 'Section 7: Mortality Outcomes',
    's8_readiness' => 'Section 8a: Integration Readiness',
    's8_lab'       => 'Section 8b: Lab Support',
    's9'           => 'Section 9: Community Engagement',
    's10'          => 'Section 10: Supply Chain',
    's11'          => 'Section 11: Primary Health Care',
];

// Helper: pre-fill value
function v($key, $existing) {
    return htmlspecialchars($existing[$key] ?? '');
}
function sel($key, $val, $existing) {
    return ($existing[$key] ?? '') === $val ? 'selected' : '';
}
function chk($key, $val, $existing) {
    return ($existing[$key] ?? '') === $val ? 'checked' : '';
}
function is_readonly_attr($is_readonly) {
    return $is_readonly ? 'readonly disabled' : '';
}

$e_data = $existing ?? [];
$page_title = $view_only ? 'View Assessment' : ($edit_id ? 'Edit Assessment' : 'New Assessment');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Facility Integration Assessment Tool</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root{
    --navy:#0D1A63; --navy2:#1a3a9e; --teal:#0ABFBC; --green:#27AE60;
    --amber:#F5A623; --rose:#E74C3C; --purple:#8B5CF6;
    --bg:#f0f2f7; --card:#fff; --border:#e2e8f0; --muted:#6B7280;
    --shadow:0 2px 16px rgba(13,26,99,.08);
    --sec-radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#1a1e2e;line-height:1.6;}
.wrap{max-width:1180px;margin:0 auto;padding:20px;}

/* ── Header ── */
.page-header{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:20px 28px;
    border-radius:14px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;
    box-shadow:0 6px 24px rgba(13,26,99,.22);}
.page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;
    border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.hdr-links a:hover{background:rgba(255,255,255,.28);}

/* ── Progress sidebar + layout ── */
.layout{display:grid;grid-template-columns:240px 1fr;gap:22px;align-items:start;}
.sidebar{position:sticky;top:16px;background:var(--card);border-radius:14px;
    box-shadow:var(--shadow);overflow:hidden;}
.sidebar-head{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;
    padding:14px 18px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.sidebar-body{padding:10px 8px;}
.sec-nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;
    cursor:pointer;transition:.15s;font-size:12.5px;font-weight:500;color:#444;margin-bottom:2px;}
.sec-nav-item:hover{background:#f0f3fb;color:var(--navy);}
.sec-nav-item.active-sec{background:#eef1ff;color:var(--navy);font-weight:700;}
.sec-nav-item.saved{color:var(--green);}
.sec-nav-item.saved .sec-dot{background:var(--green);}
.sec-nav-item.unsaved .sec-dot{background:#e5e7eb;}
.sec-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;transition:.3s;}
.sec-nav-item .sec-icon{font-size:13px;width:16px;text-align:center;}
.progress-wrap{padding:12px 16px 14px;border-top:1px solid var(--border);}
.progress-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;justify-content:space-between;}
.progress-bar-outer{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;}
.progress-bar-inner{height:100%;background:linear-gradient(90deg,var(--teal),var(--green));border-radius:99px;transition:width .5s;}

/* ── Alert ── */
.alert{padding:12px 18px;border-radius:9px;margin-bottom:18px;font-size:13.5px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
.alert-warning{background:#fff3cd;color:#856404;border:1px solid #ffeaa7;}

/* ── Section cards ── */
.form-section{background:var(--card);border-radius:var(--sec-radius);margin-bottom:20px;
    box-shadow:var(--shadow);overflow:hidden;border-left:4px solid var(--navy);
    scroll-margin-top:20px;}
.section-head{background:linear-gradient(90deg,var(--navy),var(--navy2));color:#fff;
    padding:13px 22px;display:flex;justify-content:space-between;align-items:center;}
.section-head-left{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;}
.section-head-right{display:flex;align-items:center;gap:10px;}
.saved-badge{background:rgba(39,174,96,.9);color:#fff;padding:3px 10px;border-radius:20px;
    font-size:11px;font-weight:700;display:none;}
.saved-badge.show{display:inline-flex;align-items:center;gap:5px;}
.section-body{padding:22px;}

/* ── Save section btn ── */
.btn-save-section{background:var(--teal);color:#fff;border:none;padding:9px 22px;border-radius:8px;
    font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;
    transition:.2s;margin-top:18px;}
.btn-save-section:hover{background:#089e9b;}
.btn-save-section.saving{background:#aaa;cursor:not-allowed;}

/* ── Facility search ── */
.search-wrap{position:relative;}
.search-wrap input{width:100%;padding:11px 44px 11px 14px;border:2px solid var(--border);
    border-radius:9px;font-size:14px;transition:.2s;font-family:inherit;background:#fff;}
.search-wrap input:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(13,26,99,.1);}
.s-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#aaa;font-size:15px;pointer-events:none;}
.s-spinner{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--navy);font-size:14px;display:none;}
.results-dropdown{position:absolute;z-index:999;width:100%;background:#fff;border:1.5px solid #dce3f5;
    border-radius:10px;margin-top:4px;box-shadow:0 8px 28px rgba(13,26,99,.15);max-height:280px;overflow-y:auto;display:none;}
.result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:.15s;}
.result-item:last-child{border-bottom:none;}
.result-item:hover{background:#f0f3fb;}
.ri-name{font-weight:700;color:var(--navy);font-size:13px;}
.ri-meta{font-size:11px;color:#777;margin-top:2px;}
.ri-badge{font-size:10px;background:#e8edf8;color:var(--navy);border-radius:4px;padding:1px 6px;margin-left:4px;font-weight:600;}
.no-results{padding:14px;color:#999;font-size:13px;text-align:center;}

/* ── Facility card ── */
.facility-card{border:2px solid var(--navy);border-radius:10px;padding:14px 18px;
    background:linear-gradient(135deg,#f0f3fb,#fff);margin-top:8px;display:none;}
.fac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-top:8px;}
.fg label{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:#999;font-weight:700;display:block;margin-bottom:1px;}
.fg span{font-size:12.5px;color:#222;font-weight:500;}

/* ── Form elements ── */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;}
.form-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
.form-group{margin-bottom:14px;}
.form-group.full{grid-column:1/-1;}
.form-group label{display:block;margin-bottom:5px;font-weight:600;color:#374151;font-size:13px;}
.hint{font-size:11px;color:blue;font-style:italic;margin-bottom:6px;padding:4px 10px;
    background:#f8fafc;border-left:3px solid var(--teal);border-radius:0 4px 4px 0;}
.req{color:var(--rose);}
.form-control,.form-select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:7px;
    font-size:13px;transition:.2s;background:#fff;font-family:inherit;}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(13,26,99,.08);}
.form-control[readonly]{background:#f8f9fc;color:#666;}
textarea.form-control{min-height:80px;resize:vertical;}

/* ── Radio / checkbox ── */
.yn-group{display:flex;gap:16px;margin-top:5px;flex-wrap:wrap;}
.yn-opt{display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;
    padding:6px 14px;background:#f8fafc;border-radius:7px;border:1.5px solid var(--border);transition:.15s;}
.yn-opt:has(input:checked){background:#eef1ff;border-color:var(--navy);}
.yn-opt input{width:15px;height:15px;accent-color:var(--navy);cursor:pointer;}
.cb-group{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px;}
.cb-opt{display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;
    padding:5px 12px;background:#f8fafc;border-radius:7px;border:1.5px solid var(--border);transition:.15s;}
.cb-opt:has(input:checked){background:#eef1ff;border-color:var(--navy);}
.cb-opt input{width:14px;height:14px;accent-color:var(--navy);}

/* ── Sub-label ── */
.sub-label{font-size:11.5px;font-weight:700;color:var(--navy);text-transform:uppercase;
    letter-spacing:.8px;margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid #e8edf8;
    display:flex;align-items:center;gap:7px;}

/* ── EMR repeater ── */
.emr-entry{background:#f8f9fc;border:1px solid #e0e4f0;border-radius:9px;padding:12px 14px;margin-bottom:8px;}
.emr-entry-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.emr-num{font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;}
.remove-emr{background:#fee2e2;color:#dc2626;border:none;border-radius:5px;padding:3px 9px;font-size:12px;font-weight:600;cursor:pointer;}
.add-emr-btn{width:100%;background:#eef1ff;color:var(--navy);border:2px dashed var(--navy);border-radius:8px;
    padding:9px;font-size:13px;font-weight:600;cursor:pointer;margin-top:4px;transition:.2s;}
.add-emr-btn:hover{background:#dde3ff;}

/* ── Admin box ── */
.admin-box{background:#f0f4ff;border:1px solid #c5d0f0;border-radius:9px;padding:12px 16px;
    display:flex;align-items:center;gap:12px;}
.admin-icon{width:40px;height:40px;background:var(--navy);border-radius:10px;display:flex;
    align-items:center;justify-content:center;color:#fff;font-size:17px;flex-shrink:0;}
.admin-name{font-size:14px;font-weight:700;color:var(--navy);}
.admin-label{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;}

/* ── Submit zone ── */
.submit-zone{background:var(--card);border-radius:14px;padding:22px 28px;box-shadow:var(--shadow);
    margin-bottom:28px;text-align:center;}
.submit-progress{font-size:14px;font-weight:600;color:var(--muted);margin-bottom:14px;}
.btn-submit-final{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;
    padding:14px 44px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;
    transition:.2s;display:inline-flex;align-items:center;gap:10px;}
.btn-submit-final:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(13,26,99,.3);}
.btn-submit-final:disabled{background:#aaa;cursor:not-allowed;transform:none;box-shadow:none;}

/* ── Toast ── */
.toast{position:fixed;bottom:24px;right:24px;z-index:9999;background:#fff;border-radius:12px;
    padding:14px 20px;box-shadow:0 8px 32px rgba(0,0,0,.18);display:flex;align-items:center;gap:12px;
    font-size:13.5px;font-weight:600;transform:translateY(80px);opacity:0;transition:.3s;pointer-events:none;
    max-width:340px;border-left:4px solid var(--green);}
.toast.show{transform:translateY(0);opacity:1;}
.toast.error{border-left-color:var(--rose);}
.toast-icon{font-size:18px;}
.toast.success .toast-icon{color:var(--green);}
.toast.error .toast-icon{color:var(--rose);}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;
    align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:16px;width:90%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;}
.modal-head{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:18px 24px;}
.modal-head h4{font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px;}
.modal-body{padding:22px 24px;font-size:14px;line-height:1.7;}
.modal-foot{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;}
.btn-navy{background:var(--navy);color:#fff;border:none;padding:9px 22px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;}
.btn-navy:hover{background:var(--navy2);}
.btn-outline{background:none;color:var(--muted);border:1.5px solid var(--border);padding:9px 18px;
    border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
.btn-outline:hover{border-color:var(--navy);color:var(--navy);}
.sections-status{margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:6px;}
.sec-status-item{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;
    padding:6px 10px;border-radius:7px;}
.sec-status-item.done{background:#d4edda;color:#155724;}
.sec-status-item.todo{background:#f8d7da;color:#721c24;}

@media(max-width:960px){.layout{grid-template-columns:1fr;}.sidebar{position:static;}}
@media(max-width:640px){.form-grid,.form-grid-3{grid-template-columns:1fr;}.yn-group{flex-wrap:wrap;}}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="page-header">
    <h1><i class="fas fa-clipboard-check"></i>Facility Integration Assessment Tool</h1>
    <div class="hdr-links">
        <a href="integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
        <?php if ($edit_id): ?>
        <span style="background:rgba(255,255,255,.2);padding:7px 14px;border-radius:8px;font-size:13px;">
            Editing #<?= $edit_id ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<div id="globalAlert"></div>

<div class="layout">
<!-- ══ SIDEBAR PROGRESS ══════════════════════════════════════════════════════ -->
<aside class="sidebar">
    <div class="sidebar-head"><i class="fas fa-tasks"></i> Assessment Progress</div>
    <div class="sidebar-body" id="sidebarNav">
        <?php foreach ($all_section_defs as $sk => $sl):
            $saved = in_array($sk, $sections_saved);
        ?>
        <div class="sec-nav-item <?= $saved?'saved':'unsaved' ?>" data-section="<?= $sk ?>"
             onclick="scrollToSection('<?= $sk ?>')">
            <span class="sec-dot"></span>
            <span class="sec-icon"><i class="fas <?= $saved?'fa-check-circle':'fa-circle' ?>"></i></span>
            <span style="font-size:11.5px"><?= $sl ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="progress-wrap">
        <div class="progress-label">
            <span>Progress</span>
            <span id="progressPct">0%</span>
        </div>
        <div class="progress-bar-outer">
            <div class="progress-bar-inner" id="progressBar" style="width:0%"></div>
        </div>
    </div>
</aside>

<!-- ══ MAIN FORM ════════════════════════════════════════════════════════════ -->
<div id="mainForm">

<!-- ─── SECTION 1: FACILITY PROFILE ──────────────────────────────────────── -->
<div class="form-section" id="sec_s1">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-hospital"></i> Section 1: Facility Profile</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s1',$sections_saved)?'show':'' ?>" id="badge_s1">
                <i class="fas fa-check"></i> Saved
            </span>
        </div>
    </div>
    <div class="section-body">

        <div class="form-grid">
            <div class="form-group">
                <label>Assessment Period <span class="req">*</span></label>
                <select name="assessment_period" id="assessment_period" class="form-select" required>
                    <option value="">-- Select Period --</option>
                    <?php foreach(['Oct-Dec 2025','Jan-Mar 2026','Apr-Jun 2026', 'Jul-Sep 2026', 'Oct-Dec 2026'] as $p): ?>
                    <option value="<?= $p ?>" <?= isset($existing['assessment_period'])&&$existing['assessment_period']===$p?'selected':'' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Facility <span class="req">*</span></label>
            <div class="hint">Type facility name or MFL code to search. Selecting a facility auto-fills all location fields.<span style="color: red; font-weight: bold;">MFL Code is precise</span></div>

            <div class="search-wrap" id="facSearchWrap">
                <input type="text" id="facilitySearch" placeholder="Type facility name or MFL code..."
                       autocomplete="off" value="<?= v('facility_name',$e_data) ?>">
                <i class="fas fa-hospital s-icon" id="facSearchIcon"></i>
                <i class="fas fa-spinner fa-spin s-spinner" id="facSpinner"></i>
                <div class="results-dropdown" id="facResults"></div>
            </div>
        </div>

        <div class="facility-card" id="facilityCard" style="<?= $edit_id&&$existing?'display:block':'display:none' ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <strong style="color:var(--navy);font-size:14px" id="fc_name"><?= v('facility_name',$e_data) ?></strong>
                <button type="button" onclick="clearFacility()" style="background:none;border:none;color:var(--rose);cursor:pointer;font-size:13px"><i class="fas fa-times-circle"></i> Change</button>
            </div>
            <div class="fac-grid">
                <div class="fg"><label>MFL Code</label><span id="fc_mfl"><?= v('mflcode',$e_data) ?></span></div>
                <div class="fg"><label>County</label><span id="fc_county"><?= v('county_name',$e_data) ?></span></div>
                <div class="fg"><label>Sub-County</label><span id="fc_subcounty"><?= v('subcounty_name',$e_data) ?></span></div>
                <div class="fg"><label>Level of Care</label><span id="fc_level"><?= v('level_of_care_name',$e_data) ?></span></div>
                <div class="fg"><label>Owner</label><span id="fc_owner"><?= v('owner',$e_data) ?></span></div>
                <div class="fg"><label>SDP</label><span id="fc_sdp"><?= v('sdp',$e_data) ?></span></div>
                <div class="fg"><label>Agency</label><span id="fc_agency"><?= v('agency',$e_data) ?></span></div>
                <div class="fg"><label>EMR</label><span id="fc_emr"><?= v('emr',$e_data) ?></span></div>
                <div class="fg"><label>EMR Status</label><span id="fc_emrstatus"><?= v('emrstatus',$e_data) ?></span></div>
            </div>
        </div>

        <!-- Hidden facility fields -->
        <input type="hidden" id="h_assessment_id"    value="<?= $edit_id ?>">
        <input type="hidden" id="h_facility_id"      value="<?= v('facility_id',$e_data) ?>">
        <input type="hidden" id="h_mflcode"          value="<?= v('mflcode',$e_data) ?>">
        <input type="hidden" id="h_county_name"      value="<?= v('county_name',$e_data) ?>">
        <input type="hidden" id="h_subcounty_name"   value="<?= v('subcounty_name',$e_data) ?>">
        <input type="hidden" id="h_owner"            value="<?= v('owner',$e_data) ?>">
        <input type="hidden" id="h_sdp"              value="<?= v('sdp',$e_data) ?>">
        <input type="hidden" id="h_agency"           value="<?= v('agency',$e_data) ?>">
        <input type="hidden" id="h_emr"              value="<?= v('emr',$e_data) ?>">
        <input type="hidden" id="h_emrstatus"        value="<?= v('emrstatus',$e_data) ?>">
        <input type="hidden" id="h_infra"            value="<?= v('infrastructuretype',$e_data) ?>">
        <input type="hidden" id="h_lat"              value="<?= v('latitude',$e_data) ?>">
        <input type="hidden" id="h_lng"              value="<?= v('longitude',$e_data) ?>">
        <input type="hidden" id="h_level"            value="<?= v('level_of_care_name',$e_data) ?>">

        <div class="form-grid" style="margin-top:16px">
            <div class="form-group">
                <label>Q7. Is this facility supported by US DoS/CDC/DOW IP?</label>
                <div class="hint">Select Yes if supported by a US Department of State/Centres for Disease Control or Department of War Implementing Partner?</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s1_supported_by_usdos_ip" value="Yes" <?= chk('supported_by_usdos_ip','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s1_supported_by_usdos_ip" value="No" <?= chk('supported_by_usdos_ip','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q8. Is this facility an ART site?</label>
                <div class="hint">Select Yes if the facility provides Antiretroviral Therapy</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s1_is_art_site" value="Yes" <?= chk('is_art_site','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s1_is_art_site" value="No" <?= chk('is_art_site','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s1')">
            <i class="fas fa-save"></i> Save Section 1
        </button>
    </div>
</div>

<!-- ─── SECTION 2a: HIV/TB Services ──────────────────────────────────────── -->
<div class="form-section" id="sec_s2a">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-virus"></i> Section 2a: Integration of HIV/TB Services</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s2a',$sections_saved)?'show':'' ?>" id="badge_s2a"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-group">
            <label>Q9. Has the facility integrated HIV/TB services within OPD or Chronic Care model?</label>
            <div class="hint">Please select YES, if the health facility has Integrated HIV/TB services within OPD or clinical care model,or NO if it has not.</div>

            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="s2a_hiv_tb_integrated" value="Yes" <?= chk('hiv_tb_integrated','Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="s2a_hiv_tb_integrated" value="No" <?= chk('hiv_tb_integrated','No',$e_data) ?>> No</label>
            </div>
        </div>
        <div class="form-group">
            <label>Q10. If yes, specify the integration model</label>
            <div class="hint">
                <p>The integration models are based on the  national blue print and integration advisory memo. </p>
                <p>It includes the following models and their descriptions.</p>
                <p>OPD - HIV and TB services integrated in OPD</p>
                <p>Chronic Care Center - This model refers to HIV/ TB services provided in the chronic care centers</p>
            </div>
            <input type="text" name="s2a_hiv_tb_integration_model" class="form-control" value="<?= v('hiv_tb_integration_model',$e_data) ?>" placeholder="e.g. OPD, Chronic Care Center, DSD...">
        </div>
        <div class="form-grid-3">
            <?php foreach([
                ['s2a_tx_curr','tx_curr','Q11. TX_CURR (last reporting month)','Indicate the Current on Treatment —reported last month to the baseline'],
                ['s2a_tx_curr_pmtct','tx_curr_pmtct','Q12. TX_CURR PMTCT','Indicate the Current on Treatment for PMTCT — reported last month to the baseline'],
                ['s2a_plhiv_integrated_care','plhiv_integrated_care','Q13. PLHIVs in integrated care','Indicate the total number of PLHIVs receiving HIV/TB care through integrated service models from the health facility that has integrated HIV/TB services.'],
            ] as [$fn,$db,$lbl,$hint]): ?>
            <div class="form-group">
                <label><?= $lbl ?></label>
                <div class="hint"><?= $hint ?></div>
                <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0" value="<?= v($db,$e_data) ?>">
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s2a')">
            <i class="fas fa-save"></i> Save Section 2a
        </button>
    </div>
</div>

<!-- ─── SECTION 2b: PMTCT / HTS / PrEP ──────────────────────────────────── -->
<div class="form-section" id="sec_s2b">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-baby"></i> Section 2b: Integration — PMTCT, HTS &amp; PrEP</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s2b',$sections_saved)?'show':'' ?>" id="badge_s2b"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php $q2b = [
            ['Q14','s2b_pmtct_integrated_mnch','pmtct_integrated_mnch','Has the facility integrated PMTCT in MNCH?','Select YES if the health facility has integrated PMTCT services in MNCH, or NO if not, or Not Applicable'],
            ['Q15','s2b_hts_integrated_opd','hts_integrated_opd','Has the facility integrated HTS in OPD?','Select YES if the health facility has integrated HTS services in OPD, or NO if not, or Not Applicable'],
            ['Q16','s2b_hts_integrated_ipd','hts_integrated_ipd','Has the facility integrated HTS in IPD?','Select YES if the health facility has integrated HTS services in IPD, or NO if not, or Not Applicable'],
            ['Q17','s2b_hts_integrated_mnch','hts_integrated_mnch','Has the facility integrated HTS in MNCH?','Select YES if the health facility has integrated HTS services in MNCH, or NO if not, or Not Applicable'],
            ['Q18','s2b_prep_integrated_opd','prep_integrated_opd','Has the facility integrated PrEP in OPD?','Select YES if the health facility integrated HIV Prevention services (HTS & PrEP) in OPD, or NO if not, or Not Applicable'],
            ['Q19','s2b_prep_integrated_ipd','prep_integrated_ipd','Has the facility integrated PrEP in IPD?','Select YES if the health facility integrated HIV Prevention services (HTS & PrEP) in IPD, or NO if not, or Not Applicable'],
            ['Q20','s2b_prep_integrated_mnch','prep_integrated_mnch','Has the facility integrated PrEP in MNCH?','Select YES if the health facility integrated HIV Prevention services (HTS & PrEP) in MNCH, or NO if not, or Not Applicable'],
        ];
        foreach ($q2b as [$qn,$fn,$db,$lbl,$hint]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="hint"><?= $hint ?></div>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= chk($db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= chk($db,'No',$e_data) ?>> No</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="NA" <?= chk($db,'NA',$e_data) ?>> N/A</label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s2b')"><i class="fas fa-save"></i> Save Section 2b</button>
    </div>
</div>

<!-- ─── SECTION 2c: EMR Integration ─────────────────────────────────────── -->
<div class="form-section" id="sec_s2c">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-laptop-medical"></i> Section 2c: EMR Integration</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s2c',$sections_saved)?'show':'' ?>" id="badge_s2c"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Q21. Does this facility use any EMR system?</label>
                <div class="hint">Select YES if the health facility is using any EMR system or NO if not</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2c_uses_emr" value="Yes" id="uses_emr_yes" <?= chk('uses_emr','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2c_uses_emr" value="No" id="uses_emr_no" <?= chk('uses_emr','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q24. Facility has a single unified EMR system?</label>
                <div class="hint">Select YES if the health facility has a single unified EMR system, or NO if the facility doesn’t have.</div>
                <p style="font-size: 12px;"><span style="font-weight:bold;">Definition of single unified EMR system:</span></p>
                <P style="font-size: 12px;">A single unified EMR system refers to a hospital or facility wide EMR system that is the only sole EMR system being used across all the SDPs</p>
                <p style="font-size: 12px;"><span style="font-style:italic;">(including OPD, IPD, MNCH, CCC, finance and billing management, commodity management etc.).</span></p>
                <p style="font-size: 12px;">No other EMR system is in use in the site other than the one being referred to as the single unified EMR system.</p>
                <p style="font-size: 12px;"><span style="font-weight:bold; color: red;">Note:</span> A site is said to have a single unified EMR if there is no parallel EMR system at the health facility and only uses one (same) EMR system across all points at the facility.</p>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2c_single_unified_emr" value="Yes" <?= chk('single_unified_emr','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2c_single_unified_emr" value="No" <?= chk('single_unified_emr','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <div id="emrYesSection" style="display:<?= ($e_data['uses_emr']??'')==='Yes'?'block':'none' ?>">
            <div class="sub-label"><i class="fas fa-plus-circle"></i> Q22. EMR Systems in Use</div>
            <div id="emrRepeater">
                <div class="emr-entry" data-n="1">
                    <div class="emr-entry-header"><span class="emr-num">EMR System 1</span></div>
                    <div class="form-grid-3">
                        <div class="form-group"><label>EMR Type / Name</label>
                            <input type="text" name="s2c_emr_type[]" class="form-control" placeholder="e.g. KenyaEMR, Tiberbu, AfyaKE"></div>
                        <div class="form-group"><label>Funded By</label>
                            <input type="text" name="s2c_emr_funded_by[]" class="form-control" placeholder="e.g. PEPFAR, National Government, County Government, Facility, Private Partner"></div>
                        <div class="form-group"><label>Date Started</label>
                            <input type="date" name="s2c_emr_date_started[]" class="form-control"></div>
                    </div>
                </div>
            </div>
            <button type="button" class="add-emr-btn" onclick="addEMR()">+ Add Another EMR System</button>
        </div>
        <div id="emrNoSection" style="display:<?= ($e_data['uses_emr']??'')==='No'?'block':'none' ?>;margin-top:14px">
            <div class="form-group">
                <label>Q23. If No, reasons (select all that apply):</label>
                <?php $emr_reasons_saved = explode(',', $e_data['no_emr_reasons'] ?? ''); ?>
                <div class="cb-group">
                    <?php foreach(['No hardware','No internet','No electricity','No trained staff','Other'] as $opt): ?>
                    <label class="cb-opt">
                        <input type="checkbox" name="s2c_no_emr_reasons[]" value="<?= $opt ?>"
                               <?= in_array($opt,$emr_reasons_saved)?'checked':'' ?>>
                        <?= $opt ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="sub-label" style="margin-top:18px"><i class="fas fa-hospital-alt"></i> TaifaCare powered by KenyaEMR(OpenMRS) use by Department</div>
        <div class="hint">Please select <span style="font-weight: bold;">YES</span> if the type of EMR is Taifacare powered by KenyaEMR that has HIV/TB module but No if other type of EMR that does not integrate HIV/TB patient management</div>
        <div class="hint">For other EMR systems please specify</div>
        <?php $depts=[
            ['Q25','s2c_emr_at_opd','emr_at_opd','s2c_emr_opd_other','emr_opd_other','OPD'],
            ['Q27','s2c_emr_at_ipd','emr_at_ipd','s2c_emr_ipd_other','emr_ipd_other','IPD'],
            ['Q29','s2c_emr_at_mnch','emr_at_mnch','s2c_emr_mnch_other','emr_mnch_other','MNCH'],
            ['Q31','s2c_emr_at_ccc','emr_at_ccc','s2c_emr_ccc_other','emr_ccc_other','CCC'],
            ['Q33','s2c_emr_at_pmtct','emr_at_pmtct','s2c_emr_pmtct_other','emr_pmtct_other','PMTCT'],
            ['Q35','s2c_emr_at_lab','emr_at_lab','s2c_emr_lab_other','emr_lab_other','Lab'],
            ['Q39','s2c_emr_at_pharmacy','emr_at_pharmacy','s2c_emr_pharmacy_other','emr_pharmacy_other','Pharmacy'],
        ]; ?>
        <div class="form-grid">
        <?php foreach ($depts as [$qn,$yn,$yn_db,$on,$on_db,$dept]): ?>
        <div class="form-group">
            <label><?= $qn ?>. EMR in use at <?= $dept ?>?</label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $yn ?>" value="Yes" <?= chk($yn_db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $yn ?>" value="No" <?= chk($yn_db,'No',$e_data) ?>> No</label>
            </div>
            <input type="text" name="<?= $on ?>" class="form-control" style="margin-top:6px" placeholder="If other, specify EMR name" value="<?= v($on_db,$e_data) ?>">
        </div>
        <?php endforeach; ?>
        </div>
        <div class="form-grid" style="margin-top:10px">
            <?php foreach([
                ['Q37','s2c_lab_manifest_in_use','lab_manifest_in_use','Lab Manifest in use?',['Yes','No']],
                ['Q41','s2c_pharmacy_webadt_in_use','pharmacy_webadt_in_use','Pharmacy WebADT in use?',['Yes','No']],
                ['Q42','s2c_emr_interoperable_his','emr_interoperable_his','EMR interoperable with other HIS (EID, WebADT, Lab)?',['Yes','No']],
            ] as [$qn,$fn,$db,$lbl,$opts]): ?>
            <div class="form-group">
                <label><?= $qn ?>. <?= $lbl ?></label>
                <div class="yn-group">
                    <?php foreach($opts as $opt): ?>
                    <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="<?= $opt ?>" <?= chk($db,$opt,$e_data) ?>> <?= $opt ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="form-group">
                <label>Q38. Tibu Lite (LIMS) in use?</label>
                <div class="hint">Select Yes if Tibu lite is used in the facility, or no if not</div>
                <div class="yn-group">
                    <?php foreach(['Yes','No'] as $opt): ?>
                    <label class="yn-opt"><input type="radio" name="s2c_tibu_lite_lims_in_use" value="<?= $opt ?>" <?= chk('tibu_lite_lims_in_use',$opt,$e_data) ?>> <?= $opt ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s2c')"><i class="fas fa-save"></i> Save Section 2c</button>
    </div>
</div>

<!-- ─── SECTION 3: HRH Transition ───────────────────────────────────────── -->
<div class="form-section" id="sec_s3">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-users"></i> Section 3: HRH Transition (Workforce Absorption)</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s3',$sections_saved)?'show':'' ?>" id="badge_s3"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="sub-label">Total HCWs Supported by PEPFAR IP</div>
        <div class="hint">Indicate the number of HCWs supported by PEPFAR Implementing Partner</div>
        <div class="form-grid-3">
        <?php foreach([
            ['s3_hcw_total_pepfar','hcw_total_pepfar','Q43. Total HCWs supported by PEPFAR IP'],
            ['s3_hcw_clinical_pepfar','hcw_clinical_pepfar','Q44. Clinical Staff'],
            ['s3_hcw_nonclinical_pepfar','hcw_nonclinical_pepfar','Q45. Non-Clinical Staff(Mentor mothers, Peer Educators, Accountants etc)'],
            ['s3_hcw_data_pepfar','hcw_data_pepfar','Q46. Data Staff(Data clerks, HRIOs etc)'],
            ['s3_hcw_community_pepfar','hcw_community_pepfar','Q47. Community-based Staff'],
            ['s3_hcw_other_pepfar','hcw_other_pepfar','Q48. Other'],
        ] as [$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $lbl ?></label>
            <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0" value="<?= v($db,$e_data) ?>">
        </div>
        <?php endforeach; ?>
        </div>
        <div class="sub-label">HCWs Transitioned to County Support(Workforce Transition)</div>
        <div class="hint">
            <span style="color: red; font-weight: bold;">Note:</span> The HCW tranistion to County support refers to those HCWs who were previously supported by PEPFAR IP and were absorped or tranisitioned to County payroll <span style="font-weight: bold; font-style: italic;">regardless of the roles they are undertaking</span> at the facility or County.  Thus, reporting is not only limited to those HWCs who have transitioned from PEPFAR support to County payroll support and still continue with providing HIV services in supported facility or facilities in the County but also those undertaking other roles other than HIV service provision geared towards provision of health service integration.
        </div>
        <div class="form-grid-3">
        <?php foreach([
            ['s3_hcw_transitioned_clinical','hcw_transitioned_clinical','Q50. Clinical Staff'],
            ['s3_hcw_transitioned_nonclinical','hcw_transitioned_nonclinical','Q51. Non-Clinical Staff'],
            ['s3_hcw_transitioned_data','hcw_transitioned_data','Q52. Data Staff'],
            ['s3_hcw_transitioned_community','hcw_transitioned_community','Q53. Community-based Staff'],
            ['s3_hcw_transitioned_other','hcw_transitioned_other','Q54. Other'],
        ] as [$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $lbl ?></label>
            <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0" value="<?= v($db,$e_data) ?>">
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s3')"><i class="fas fa-save"></i> Save Section 3</button>
    </div>
</div>

<!-- ─── SECTION 4: PLHIV & PBFW ─────────────────────────────────────────── -->
<div class="form-section" id="sec_s4">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-id-card"></i> Section 4: PLHIV &amp; PBFW Enrollment into SHA</div>
        <div class="hint">Get this information from the administrator or the available EMR</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s4',$sections_saved)?'show':'' ?>" id="badge_s4"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid-3">
        <?php foreach([
            ['s4_plhiv_enrolled_sha','plhiv_enrolled_sha','Q56. Indicate the total number of PLHIVs enrolled into SHA'],
            ['s4_plhiv_sha_premium_paid','plhiv_sha_premium_paid','Q57. Indicate the total number of PLHIVs with premium SHA fully paid'],
            ['s4_pbfw_enrolled_sha','pbfw_enrolled_sha','Q58. Indicate the total number of PBFW enrolled/registered into SHA'],
            ['s4_pbfw_sha_premium_paid','pbfw_sha_premium_paid','Q59. Indicate the total number of PBFW with premium SHA fully paid'],
        ] as [$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $lbl ?></label>
            <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0" value="<?= v($db,$e_data) ?>">
        </div>
        <?php endforeach; ?>
        </div>
        <div class="form-grid">
        <?php foreach([
            ['Q60','s4_sha_claims_submitted_ontime','sha_claims_submitted_ontime','Has the facility been submitting SHA claims on time? <span style="font-style: italic; color: blue;">Select YES if the health facility been submitting SHA claims on time, or NO if it has not.</span>'],
            ['Q61','s4_sha_reimbursements_monthly','sha_reimbursements_monthly','Has the facility received any SHA reimbursements in the last 3 months?<span style="font-style: italic; color: blue;">Select YES if the health facility has received SHA reimbursements in the last 3 months, or NO if it has not.</span>'],
        ] as [$qn,$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= chk($db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= chk($db,'No',$e_data) ?>> No</label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s4')"><i class="fas fa-save"></i> Save Section 4</button>
    </div>
</div>

<!-- ─── SECTION 5: TA / Mentorship ──────────────────────────────────────── -->
<div class="form-section" id="sec_s5">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-chalkboard-teacher"></i> Section 5: County TA / Mentorship</div>
        <div class="hint">This is mentorship done by any USG supported partner on HIV/TB/RMNCH</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s5',$sections_saved)?'show':'' ?>" id="badge_s5"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Q62. TA/Mentorship visits on HIV/TB/PMTCT in last 3 months (total)</label>
                <div class="hint">Indicate the number of TA/Mentorship visits on HIV Prevention, HIV/TB services, and PMTCT services were done at the facility in the last 3 months</div>
                <input type="number" name="s5_ta_visits_total" class="form-control" min="0" placeholder="0" value="<?= v('ta_visits_total',$e_data) ?>">
            </div>
            <div class="form-group">
                <label>Q63. Of total TA visits, how many were by MOH only (without IP staff)?</label>
                <div class="hint">Indicate if the total TA/ Mentorships visits, how many were done by the MOH only (County / Sub-County teams alone without IP staff</div>
                <input type="number" name="s5_ta_visits_moh_only" class="form-control" min="0" placeholder="0" value="<?= v('ta_visits_moh_only',$e_data) ?>">
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s5')"><i class="fas fa-save"></i> Save Section 5</button>
    </div>
</div>

<!-- ─── SECTION 6: Financing ─────────────────────────────────────────────── -->
<div class="form-section" id="sec_s6">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-coins"></i> Section 6: Financing and Sustainability</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s6',$sections_saved)?'show':'' ?>" id="badge_s6"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php foreach([
            ['Q64','s6_fif_collection_in_place','fif_collection_in_place','Does the facility have a FIF collection mechanism? <span style="color: blue;">Please select YES if the health facility has FIF collection in place, or NO if it does not. For private facilities that charge for all services, select YES</span>'],
            ['Q65','s6_fif_includes_hiv_tb_pmtct','fif_includes_hiv_tb_pmtct','FIF collection incorporates HIV/TB, PMTCT & MNCH services? <span style="color: blue;">Please select YES if the FIF collection has incorporated HIV/TB services, or NO if it has not.</span>'],
            ['Q66','s6_sha_capitation_hiv_tb','sha_capitation_hiv_tb','Is facility receiving SHA capitation for HIV/TB, PMTCT & MNCH? <span style="color: blue;">Please select YES if the health facility receiving SHA capitation for HIV/TB services, or NO if it is not.</span>'],
        ] as [$qn,$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= chk($db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= chk($db,'No',$e_data) ?>> No</label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s6')"><i class="fas fa-save"></i> Save Section 6</button>
    </div>
</div>

<!-- ─── SECTION 7: Mortality Outcomes ───────────────────────────────────── -->
<div class="form-section" id="sec_s7">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-heartbeat"></i> Section 7: Mortality Outcomes (Sustainability of quality of care)</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s7',$sections_saved)?'show':'' ?>" id="badge_s7"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid-3">
        <?php foreach([
            ['s7_deaths_all_cause','deaths_all_cause','Q67. Total deaths (all-cause mortality)<span style="color: blue;">Indicate the Number of deaths from any cause in the quarter (Jan 2026 - Mar 2026)</span>'],
            ['s7_deaths_hiv_related','deaths_hiv_related','Q68. HIV-related deaths<span style="color: blue;">Indicate the Number of HIV related deaths in the quarter (Jan 2026 - Mar 2026)Note: Value enterd here should not be more than the value entered in question 67</span>'],
            ['s7_deaths_hiv_pre_art','deaths_hiv_pre_art','Q69. HIV deaths before ART linkage <span style="color: blue;">Indicate the Number of HIV related deaths that occurred before the client was linked to ART in the quarter (Jan 2026 - Mar 2026). Note: Value enterd here should not be more than the value entered in question 67</span>'],
            ['s7_deaths_tb','deaths_tb','Q70. TB deaths <span style="color: blue;">"Indicate the Number of TB deaths in the quarter (Jan 2026 - Mar 2026) Note: Value enterd here should not be more than the value entered in question 67</span>'],
            ['s7_deaths_maternal','deaths_maternal','Q71. Maternal deaths <span style="color: blue;">Indicate the Number of Maternal deaths  in the quarter (Jan 2026 - Mar 2026) Note: Value enterd here should not be more than the value entered in question 67</span>'],
            ['s7_deaths_perinatal','deaths_perinatal','Q72. Perinatal deaths (stillbirths + early neonatal <7 days) <span style="color: blue;">"Indicate the Number of Perinatal deaths (i.e., stillbirths and early neonatal deaths <7 days)  in the quarter (Jan 2026 - Mar 2026). Note: Value enterd here should not be more than the value entered in question 67</span>'],
        ] as [$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $lbl ?></label>
            <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0" value="<?= v($db,$e_data) ?>">
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s7')"><i class="fas fa-save"></i> Save Section 7</button>
    </div>
</div>

<!-- ─── SECTION 8a: Integration Readiness proposed questions for AI scoring and reports generation ───────────────────────────────── -->
<div class="form-section" id="sec_s8_readiness">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-project-diagram"></i> Section 8a: Integration Readiness &amp; Sustainability</div>
        <div class="hint">These are proposed additional questions for AI scoring and reports generation</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s8_readiness',$sections_saved)?'show':'' ?>" id="badge_s8_readiness"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php
        $readiness_qs = [
            ['Q86','s8r_leadership_commitment','leadership_commitment','How would you rate the facility leadership commitment to HIV integration',['High','Moderate','Low']],
            ['Q87','s8r_transition_plan','transition_plan','Is there a transition/integration plan?',['Yes - Implemented','Yes - Not Implemented','No']],
            ['Q88','s8r_hiv_in_awp','hiv_in_awp','To what extend are HIV/TB services included in AWP/Budget? <span style="color: blue;">Select Fully, if there is inclusion in the AWP and funds released for servivces, select Partially if dunds are allocated but not released for the services</span>',['Fully','Partially','No']],
            ['Q89','s8r_hrh_gap','hrh_gap','What is the estimated HRH gap (%) <span style="color: blue;">Check against WISN scores for County Staff and give estimate for HRH gap in offering HIV/TB services</span>',['0-10%','10-30%','>30%']],
            ['Q90','s8r_staff_multiskilled','staff_multiskilled','Are staff multi-skilled <span style="color: blue;">Select yes if the county staff are multi-skilled in offering both HIV/TB/MNCH services alongside other services?, Partial if they require mentorship to offer all services, other wise select NO</span>',['Yes','Partial','No']],
            ['Q91','s8r_roving_staff','roving_staff','Is there roving/visiting HIV/TB staff? <span style="color: blue;">Slect yes, if an implementing partner clinician sees patients on scheduled days or HRIO visits the facility to collect data routinely, irregular if the visits are ad hoc</span>',['Yes - Regular','Yes - Irregular','No']],
            ['Q92','s8r_infrastructure_capacity','infrastructure_capacity','How do you rate the infrastructure capacity for integration',['Adequate','Minor changes needed','Major redesign needed']],
            ['Q93','s8r_space_adequacy','space_adequacy','How would you define the space adequacy for integration?',['Adequate','Congested','Severely Inadequate']],
            ['Q94','s8r_service_delivery_without_ccc','service_delivery_without_ccc','Can HIV services run without CCC? <span style="color: blue;"> Select yes, if the PLHIV will receive quality services if the standalone CCC is closed or implementing partner HRH withdrawn, partially if the reamining staff will require capacity support to offer these services</span>',['Yes','Partially','No']],
            ['Q95','s8r_avg_wait_time','avg_wait_time','What is the estimated average patient waiting time <span style="color: blue;">interview clients or get data from facility for average waiting time from EMR - checkin time vs checkout time</span>',['<1 hour','1-3 hours','>3 hours']],
            ['Q96','s8r_data_integration_level','data_integration_level','Indicate the data integration level <span style="color: blue;">Select Fully integrated if data is accessible in any department from a unified system or if systems are interoperable, partial if the systems are there but does not cover all departments and fragmented if systems do not communicate at all</span>',['Fully Integrated','Partial','Fragmented']],
            ['Q97','s8r_financing_coverage','financing_coverage','Select the financing coverage for HIV services',['High','Moderate','Low']],
            ['Q98','s8r_disruption_risk','disruption_risk','Indicate the risk of service disruption if integration of HIV services takes place <span style="color: blue;">Ask for opinion form patients or staff</span>',['Low','Moderate','High']],
        ];
        foreach ($readiness_qs as [$qn,$fn,$db,$lbl,$opts]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <select name="<?= $fn ?>" class="form-select">
                <option value="">Select</option>
                <?php foreach($opts as $opt): ?>
                <option value="<?= $opt ?>" <?= sel($db,$opt,$e_data) ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
        <div class="form-group full">
            <label>Q99. Key barriers to integration</label>
            <div class="hint">List all key barriers to integration that may have negative or positive impact for action</div>
            <textarea name="s8r_integration_barriers" class="form-control"><?= v('integration_barriers',$e_data) ?></textarea>
        </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s8_readiness')"><i class="fas fa-save"></i> Save Section 8a</button>
    </div>
</div>

<!-- ─── SECTION 8b: Lab Support (NEW Q72–Q92) ───────────────────────────── -->
<div class="form-section" id="sec_s8_lab">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-flask"></i> Section 8b: Lab Support</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s8_lab',$sections_saved)?'show':'' ?>" id="badge_s8_lab"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php
        $lab_yn = [
            ['Q72','s8l_lab_specimen_referral','lab_specimen_referral','Does the facility have an integrated specimen referral system?<span style="color: blue;">Please select YES if the facility has an integrated specimen referral system, or NO if it does not.</span>',['Yes','No']],
            ['Q73','s8l_lab_referral_county_funded','lab_referral_county_funded','Is the specimen referral system fully funded by the county?<span style="color: blue;">Please select YES if the system is fully funded, or NO if it is partially funded or not funded by the county.</span>',['Yes','No']],
            ['Q74','s8l_lab_iso15189_accredited','lab_iso15189_accredited','Is the laboratory accredited to ISO 15189?<span style="color: blue;">Please select YES if the laboratory is accredited, or NO if it is not.</span>',['Yes','No']],
            ['Q76','s8l_lab_lcqi_implementing','lab_lcqi_implementing','Is the laboratory implementing LCQI?<span style="color: blue;">Please select YES if the laboratory is implementing LCQI, or NO if it is not.</span>',['Yes','No']],
            ['Q77','s8l_lab_lcqi_internal_audits','lab_lcqi_internal_audits','If LCQI: does the lab conduct regular internal audits using LCQI checklist?<span style="color: blue;">Please select YES if the laboratory is implementing LCQI, or NO if it is not.</span>',['Yes','No']],
            ['Q78','s8l_lab_eqa_all_tests','lab_eqa_all_tests','Does the facility participate in EQA for all tests performed?<span style="color: blue;">Please select YES if the facility participates in External Quality Assurance for all tests, or NO if it does not.</span>',['Yes','No']],
            ['Q79','s8l_lab_sla_equipment','lab_sla_equipment','Does the facility have service-level agreements for equipment?<span style="color: blue;">Please select YES if service-level agreements are in place for equipment, or NO if they are not.</span>',['Yes','No']],
            ['Q81','s8l_lab_lims_in_place','lab_lims_in_place','Is there a Lab Information Management System (LIMS)?<span style="color: blue;">Please select YES if a LIMS is in place, or NO if there is not.</span>',['Yes','No']],
            ['Q82','s8l_lab_lims_emr_integrated','lab_lims_emr_integrated','Is the LIMS integrated into the facility EMR?<span style="color: blue;">Please select YES if the LIMS is integrated with the facilitys Electronic Medical Record, or NO if it is not.</span>',['Yes','No']],
            ['Q83','s8l_lab_lims_interoperable','lab_lims_interoperable','Is the LIMS interoperable with electronic lab equipment?<span style="color: blue;">Please select YES if the LIMS is interoperable with lab equipment, or NO if it is not.</span>',['Yes','No']],
            ['Q84','s8l_lab_his_integration_guide','lab_his_integration_guide','Is there a standard HIS integration guide (e.g. FHIR) in place?<span style="color: blue;">Please select YES if there is a standard Systems  integration guide/ e.g FHIR guide  in place for hospital wide HIS integration /within the county, or NO if it is not available. Or NO if not</span>',['Yes','No']],
            ['Q85','s8l_lab_dedicated_his_staff','lab_dedicated_his_staff','Does the facility have dedicated technical staff supporting HIS needs?<span style="color: blue;">Please select YES  if  the facility  has  dedicated technical staff supporting HIS needs . Or NO if not available</span>',['Yes','No']],
            ['Q88','s8l_lab_biosafety_trained','lab_biosafety_trained','Have all laboratory staff been trained in Biosafety?<span style="color: blue;">Please select YES if all relevant staff have been trained, or NO if they have not.</span>',['Yes','No']],
            ['Q89','s8l_lab_hepb_vaccinated','lab_hepb_vaccinated','Have all laboratory staff been vaccinated against Hepatitis B?<span style="color: blue;">Please select YES if all relevant staff have been vaccinated, or NO if they have not.</span>',['Yes','No']],
            ['Q90','s8l_lab_ipc_committee','lab_ipc_committee','Does the facility have an active IPC committee?<span style="color: blue;">Please select YES if the facility has an active Infection Prevention and Control committee and workplan, or NO if it does not.</span>',['Yes','No']],
            ['Q91','s8l_lab_ipc_workplan','lab_ipc_workplan','Does the facility have an active IPC workplan?<span style="color: blue;">Please select YES if the facility has an active Infection Prevention and Control committee and workplan, or NO if it does not.</span>',['Yes','No']],
            ['Q92','s8l_lab_moh_virtual_academy','lab_moh_virtual_academy','Does the facility have access to MOH virtual academy for Lab trainings?<span style="color: blue;">Please select YES if the county has access to the MOH virtual academy for training, or NO if it does not.</span>',['Yes','No']],
        ];
        foreach ($lab_yn as [$qn,$fn,$db,$lbl,$opts]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="yn-group">
                <?php foreach($opts as $opt): ?>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="<?= $opt ?>" <?= chk($db,$opt,$e_data) ?>> <?= $opt ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Questions with partner/county/gok options -->
        <?php foreach([
            ['Q75','s8l_lab_kenas_fee_support','lab_kenas_fee_support','Q75. Who is supporting KENAS accreditation/assessment fee?<span style="color: blue;">Please select who is supporting payment of KENAS accreditation/assessment fee</span> '],
            ['Q80','s8l_lab_sla_support','lab_sla_support','Q80. Who is supporting the service-level agreements?<span style="color: blue;">Please select YES if service-level agreements are in place for equipment, or NO if they are not.</span>'],
            ['Q87','s8l_lab_shipping_cost_support','lab_shipping_cost_support','Q87. Who is supporting the cost of shipping equipment?<span style="color: blue;">Please select who is supporting the cost of shipping these equipment?; partner, county, national government</span>'],
        ] as [$qn,$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $lbl ?></label>
            <div class="yn-group">
                <?php foreach(['Partner','County','GOK','N/A'] as $opt): ?>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="<?= $opt ?>" <?= chk($db,$opt,$e_data) ?>> <?= $opt ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="form-group">
            <label>Q86. Is the Biosafety Cabinet (BSC) calibration status current?</label>
            <div class="hint">Please select YES if the calibration is current, NO if it is not, or NA if not applicable.</div>
            <div class="yn-group">
                <?php foreach(['Yes','No','NA'] as $opt): ?>
                <label class="yn-opt"><input type="radio" name="s8l_lab_bsc_calibration_current" value="<?= $opt ?>" <?= chk('lab_bsc_calibration_current',$opt,$e_data) ?>> <?= $opt ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="button" class="btn-save-section" onclick="saveSection('s8_lab')"><i class="fas fa-save"></i> Save Section 8b</button>
    </div>
</div>

<!-- ─── SECTION 9: Community Engagement (NEW Q93–Q97) ────────────────────── -->
<div class="form-section" id="sec_s9">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-users-cog"></i> Section 9: Community Engagement</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s9',$sections_saved)?'show':'' ?>" id="badge_s9"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php foreach([
            ['Q93','s9_comm_hiv_feedback_mechanism','comm_hiv_feedback_mechanism','Does the facility have a mechanism (e.g. exit interviews) for obtaining feedback on HIV service integration? <span style="color: blue;">Please select YES if the have a mechanism e.g exit interviews) for obtaining comodity feedback on itegration of HIV services, on NO if not.</span>'],
            ['Q94','s9_comm_roc_feedback_used','comm_roc_feedback_used','Does the facility use beneficiary (RoC) feedback to inform HIV service integration decisions?<span style="color: blue;">Please select YES, if the  health facility use beneficiary (RoCs) feedback to inform HIV service integration decision, or NO if Not.</span>'],
            ['Q96','s9_comm_community_representation','comm_community_representation','Does the facility have community representation in the HIV service integration committee?<span style="color: blue;">Please select YES, if the health facility have a community representation in the HIV service integration committee, or NO if Not.</span>'],
            ['Q97','s9_comm_plhiv_in_discussions','comm_plhiv_in_discussions','Does the facility involve PLHIV representatives in HIV service integration discussions?<span style="color: blue;">Please select YES, if the health facility involve PLHIV representatives in HIV service integration dicussions, or NO if Not.</span>'],
        ] as [$qn,$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= chk($db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= chk($db,'No',$e_data) ?>> No</label>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="form-group">
            <label>Q95. In the last 3 months, how many health talks has the facility held with PLHIVs on integration of HIV services?</label>
            <div class="hint">Please enter the number of health talks the health facility held with PLHIVs on integration of HIV services.</div>
            <input type="number" name="s9_comm_health_talks_plhiv" class="form-control" min="0" placeholder="0" value="<?= v('comm_health_talks_plhiv',$e_data) ?>">
        </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s9')"><i class="fas fa-save"></i> Save Section 9</button>
    </div>
</div>

<!-- ─── SECTION 10: Supply Chain (NEW Q98–Q102) ──────────────────────────── -->
<div class="form-section" id="sec_s10">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-boxes"></i> Section 10: Supply Chain &amp; Commodity Management</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s10',$sections_saved)?'show':'' ?>" id="badge_s10"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php foreach([
            ['Q98','s10_sc_khis_reports_monthly','sc_khis_reports_monthly','Has the facility been consistently submitting commodity consumption reports (FMARPS/FCDRR) in KHIS monthly?<span style="color: blue;">Please select YES if the health facility been consistently submitting commodity consumption reports (FMARPS-MOH729B, FCDRR-MOH730B) in KHIS on a monthly basis, or NO if not.</span>'],
            ['Q99','s10_sc_stockout_arvs','sc_stockout_arvs','In the last 3 months, has the facility had a stock-out of HIV ARVs?<span style="color: blue;">Please select YES if  the health facility had a stock out of HIV ARVs , or NO if not.</span>'],
            ['Q100','s10_sc_stockout_tb_drugs','sc_stockout_tb_drugs','In the last 3 months, has the facility had a stock-out of TB drugs (Anti-TBs, TPT)?<span style="color: blue;">Please select YES if  health facility had a stock out of TB drugs (Anti-TBs, TPT), or NO if not.</span>'],
            ['Q101','s10_sc_stockout_hiv_reagents','sc_stockout_hiv_reagents','In the last 3 months, has the facility had a stock-out of HIV lab reagents (VL, CD4)?<span style="color: blue;">Please select YES if  the health facility had a stock out of HIV lab reagents (VL, CD4), or NO if not.</span>'],
            ['Q102','s10_sc_stockout_tb_reagents','sc_stockout_tb_reagents','In the last 3 months, has the facility had a stock-out of TB testing reagents (LFA, LAMP, TRUNAT, GeneXpert)?<span style="color: blue;">Please select YES if the health facility had a stock out of TB testing reagents (TB LFA, TB LAMP, TRUNAT, GENE EXPERT), or NO if not.</span>'],
        ] as [$qn,$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= chk($db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= chk($db,'No',$e_data) ?>> No</label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s10')"><i class="fas fa-save"></i> Save Section 10</button>
    </div>
</div>

<!-- ─── SECTION 11: Primary Health Care (NEW Q103–Q104) ─────────────────── -->
<div class="form-section" id="sec_s11">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-clinic-medical"></i> Section 11: Primary Health Care</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s11',$sections_saved)?'show':'' ?>" id="badge_s11"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
        <?php foreach([
            ['Q103','s11_phc_chp_referrals','phc_chp_referrals','Does the facility receive documented referrals of PLHIV (e.g. LTFU, sick clients) from Community Health Promoters (CHPs)?<span style="color: blue;">Please select YES ifthe health facility receive documented referrals of PLHIV (e.g., those lost to follow-up or sick clients) from Community Health Promoters (CHPs), or NO if not.</span>'],
            ['Q104','s11_phc_chwp_tracing','phc_chwp_tracing','Does the facility work with CHW/Ps to trace and link back PLHIV who have disengaged from HIV treatment?<span style="color: blue;">Please select YES if   the health facility work with Community Health Workers/ Promoters (CHW/Ps) to trace and link back PLHIV who have disengaged from HIV treatment, or NO if not.</span>'],
        ] as [$qn,$fn,$db,$lbl]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $lbl ?></label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= chk($db,'Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= chk($db,'No',$e_data) ?>> No</label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s11')"><i class="fas fa-save"></i> Save Section 11</button>
    </div>
</div>

<!-- ─── DATA COLLECTION DETAILS ─────────────────────────────────────────── -->
<div class="form-section" style="border-left-color:var(--teal)">
    <div class="section-head" style="background:linear-gradient(90deg,var(--teal),#089e9b)">
        <div class="section-head-left"><i class="fas fa-user-check"></i> Data Collection Details</div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Collected By</label>
                <div class="admin-box">
                    <div class="admin-icon"><i class="fas fa-user-check"></i></div>
                    <div>
                        <div class="admin-label">Logged-in Officer</div>
                        <div class="admin-name"><?= htmlspecialchars($collected_by ?: 'Not identified') ?></div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Date of Data Collection</label>
                <input type="date" id="collection_date_input" class="form-control"
                       style="max-width:220px" value="<?= v('collection_date',$e_data) ?: date('Y-m-d') ?>">
            </div>
        </div>
    </div>
</div>

<!-- ─── SUBMIT ZONE ──────────────────────────────────────────────────────── -->
<div class="submit-zone">
    <div class="submit-progress" id="submitProgressText">
        <i class="fas fa-info-circle"></i> Save all sections to unlock final submission
    </div>
    <button type="button" class="btn-submit-final" id="btnFinalSubmit" disabled onclick="finalSubmit()">
        <i class="fas fa-paper-plane"></i> Submit Final Assessment
    </button>
</div>

</div><!-- /mainForm -->
</div><!-- /layout -->
</div><!-- /wrap -->

<!-- ── DUPLICATE FACILITY MODAL ──────────────────────────────────────────── -->
<div class="modal-overlay" id="dupModal">
    <div class="modal-box">
        <div class="modal-head">
            <h4><i class="fas fa-exclamation-triangle"></i> Assessment Already Exists</h4>
        </div>
        <div class="modal-body">
            <p id="dupModalMsg"></p>
            <div class="sections-status" id="dupSectionsStatus"></div>
        </div>
        <div class="modal-foot">
            <button class="btn-outline" onclick="closeDupModal()"><i class="fas fa-times"></i> Cancel</button>
            <a id="dupEditLink" href="#" class="btn-navy"><i class="fas fa-edit"></i> Open & Continue</a>
            <a href="integration_assessment_list.php" class="btn-navy" style="background:var(--teal)"><i class="fas fa-list"></i> View All</a>
        </div>
    </div>
</div>

<!-- ── TOAST ─────────────────────────────────────────────────────────────── -->
<div class="toast" id="toast">
    <i class="fas fa-check-circle toast-icon"></i>
    <span id="toastMsg">Saved successfully</span>
</div>

<script>
// ── State ─────────────────────────────────────────────────────────────────────
let assessmentId   = <?= $edit_id ?: 0 ?>;
let sectionsSaved  = <?= json_encode($sections_saved) ?>;
const allSections  = <?= json_encode(array_keys($all_section_defs)) ?>;
let facilityData   = {};

// ── Toast helper ──────────────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'toast ' + type + ' show';
    t.querySelector('.toast-icon').className = 'fas ' + (type==='success'?'fa-check-circle':'fa-exclamation-triangle') + ' toast-icon';
    setTimeout(() => t.classList.remove('show'), 3200);
}

// ── Progress update ───────────────────────────────────────────────────────────
function updateProgress() {
    const n = sectionsSaved.length;
    const total = allSections.length;
    const pct = Math.round(n/total*100);

    document.getElementById('progressPct').textContent = pct + '%';
    document.getElementById('progressBar').style.width = pct + '%';

    // Update sidebar dots
    allSections.forEach(sk => {
        const item = document.querySelector(`.sec-nav-item[data-section="${sk}"]`);
        if (!item) return;
        const saved = sectionsSaved.includes(sk);
        item.className = 'sec-nav-item ' + (saved?'saved':'unsaved');
        item.querySelector('.sec-icon i').className = 'fas ' + (saved?'fa-check-circle':'fa-circle');
    });

    // Enable submit when all saved
    const btn = document.getElementById('btnFinalSubmit');
    const txt = document.getElementById('submitProgressText');
    if (n >= total) {
        btn.disabled = false;
        txt.innerHTML = '<i class="fas fa-check-circle" style="color:var(--green)"></i> All sections saved — ready to submit!';
    } else {
        btn.disabled = true;
        txt.innerHTML = `<i class="fas fa-info-circle"></i> ${n} of ${total} sections saved — complete all to enable submission`;
    }
}

updateProgress(); // initial run

// ── Scroll to section ─────────────────────────────────────────────────────────
function scrollToSection(sk) {
    const el = document.getElementById('sec_' + sk);
    if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
}

// ── Facility search ───────────────────────────────────────────────────────────
function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
const facInput   = document.getElementById('facilitySearch');
const facResults = document.getElementById('facResults');
const facSpinner = document.getElementById('facSpinner');
const facIcon    = document.getElementById('facSearchIcon');

facInput.addEventListener('input', debounce(async function() {
    const q = facInput.value.trim();
    if (q.length < 2) { facResults.style.display='none'; return; }
    facSpinner.style.display='block'; facIcon.style.display='none';
    try {
        const rows = await fetch(`facility_integration_assessment.php?ajax=search_facility&q=${encodeURIComponent(q)}`).then(r=>r.json());
        facSpinner.style.display='none'; facIcon.style.display='block';
        if (!rows.length) {
            facResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No facilities found</div>';
        } else {
            facResults.innerHTML = rows.map(r =>
                `<div class="result-item" onclick='pickFacility(${JSON.stringify(r).replace(/'/g,"&#39;")})'>
                    <div class="ri-name">${r.facility_name} <span class="ri-badge">${r.mflcode||''}</span></div>
                    <div class="ri-meta"><i class="fas fa-map-marker-alt" style="color:var(--navy)"></i>
                        ${r.county_name||''} | ${r.subcounty_name||''} | ${r.level_of_care_name||''}</div>
                </div>`).join('');
        }
        facResults.style.display = 'block';
    } catch(e) { facSpinner.style.display='none'; facIcon.style.display='block'; }
}, 350));

async function pickFacility(r) {
    facResults.style.display = 'none';
    facInput.value = r.facility_name;
    facilityData = r;

    // Set hidden fields
    document.getElementById('h_facility_id').value   = r.facility_id;
    document.getElementById('h_mflcode').value        = r.mflcode||'';
    document.getElementById('h_county_name').value    = r.county_name||'';
    document.getElementById('h_subcounty_name').value = r.subcounty_name||'';
    document.getElementById('h_owner').value          = r.owner||'';
    document.getElementById('h_sdp').value            = r.sdp||'';
    document.getElementById('h_agency').value         = r.agency||'';
    document.getElementById('h_emr').value            = r.emr||'';
    document.getElementById('h_emrstatus').value      = r.emrstatus||'';
    document.getElementById('h_infra').value          = r.infrastructuretype||'';
    document.getElementById('h_lat').value            = r.latitude||'';
    document.getElementById('h_lng').value            = r.longitude||'';
    document.getElementById('h_level').value          = r.level_of_care_name||'';

    // Populate card
    ['fc_name','fc_mfl','fc_county','fc_subcounty','fc_level','fc_owner','fc_sdp','fc_agency','fc_emr','fc_emrstatus'].forEach(id => {
        const map = {fc_name:'facility_name',fc_mfl:'mflcode',fc_county:'county_name',
            fc_subcounty:'subcounty_name',fc_level:'level_of_care_name',fc_owner:'owner',
            fc_sdp:'sdp',fc_agency:'agency',fc_emr:'emr',fc_emrstatus:'emrstatus'};
        document.getElementById(id).textContent = r[map[id]] || '—';
    });
    document.getElementById('facilityCard').style.display = 'block';

    // Check if assessment exists for selected period
    const period = document.getElementById('assessment_period').value;
    if (period) await checkExistingAssessment(r.facility_id, period);
}

function clearFacility() {
    document.getElementById('h_facility_id').value = '';
    document.getElementById('facilityCard').style.display = 'none';
    facInput.value = '';
    facilityData = {};
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#facSearchWrap')) facResults.style.display='none';
});
document.getElementById('assessment_period').addEventListener('change', async function() {
    const fid = document.getElementById('h_facility_id').value;
    if (fid && this.value) await checkExistingAssessment(fid, this.value);
});

// ── Check for existing assessment ─────────────────────────────────────────────
async function checkExistingAssessment(facilityId, period) {
    try {
        const data = await fetch(
            `facility_integration_assessment.php?ajax=check_assessment&facility_id=${facilityId}&period=${encodeURIComponent(period)}`
        ).then(r=>r.json());

        if (data.exists && data.assessment_id != assessmentId) {
            const ss = data.sections_saved || [];
            const total = allSections.length;
            const msg = `An assessment for <strong>${data.facility_name}</strong> — <strong>${period}</strong> already exists (ID #${data.assessment_id}, status: <strong>${data.status}</strong>, ${ss.length}/${total} sections saved).`;
            document.getElementById('dupModalMsg').innerHTML = msg;
            document.getElementById('dupEditLink').href = `facility_integration_assessment.php?id=${data.assessment_id}`;

            // Show section statuses
            const allDefs = <?= json_encode($all_section_defs) ?>;
            const html = Object.entries(allDefs).map(([sk,sl]) =>
                `<div class="sec-status-item ${ss.includes(sk)?'done':'todo'}">
                    <i class="fas ${ss.includes(sk)?'fa-check-circle':'fa-times-circle'}"></i>
                    <span>${sl}</span>
                </div>`).join('');
            document.getElementById('dupSectionsStatus').innerHTML = html;
            document.getElementById('dupModal').classList.add('show');
        }
    } catch(e) {}
}

function closeDupModal() { document.getElementById('dupModal').classList.remove('show'); }
document.getElementById('dupModal').addEventListener('click', function(e) { if(e.target===this) closeDupModal(); });

// ── EMR Yes/No toggle ──────────────────────────────────────────────────────────
document.querySelectorAll('input[name="s2c_uses_emr"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('emrYesSection').style.display = this.value==='Yes'?'block':'none';
        document.getElementById('emrNoSection').style.display  = this.value==='No' ?'block':'none';
    });
});

let emrCount = 1;
function addEMR() {
    emrCount++;
    document.getElementById('emrRepeater').insertAdjacentHTML('beforeend',
        `<div class="emr-entry" data-n="${emrCount}">
            <div class="emr-entry-header">
                <span class="emr-num">EMR System ${emrCount}</span>
                <button type="button" class="remove-emr" onclick="removeEMR(this)">✕ Remove</button>
            </div>
            <div class="form-grid-3">
                <div class="form-group"><label>EMR Type / Name</label>
                    <input type="text" name="s2c_emr_type[]" class="form-control" placeholder="e.g. KenyaEMR"></div>
                <div class="form-group"><label>Funded By</label>
                    <input type="text" name="s2c_emr_funded_by[]" class="form-control" placeholder="e.g. PEPFAR"></div>
                <div class="form-group"><label>Date Started</label>
                    <input type="date" name="s2c_emr_date_started[]" class="form-control"></div>
            </div>
        </div>`);
}
function removeEMR(btn) {
    const entries = document.querySelectorAll('#emrRepeater .emr-entry');
    if (entries.length <= 1) { showToast('At least one EMR entry is required', 'error'); return; }
    btn.closest('.emr-entry').remove();
    document.querySelectorAll('#emrRepeater .emr-entry').forEach((el,i) =>
        el.querySelector('.emr-num').textContent = 'EMR System '+(i+1));
}

// ── Save section ──────────────────────────────────────────────────────────────
async function saveSection(sectionKey) {
    const fid = document.getElementById('h_facility_id').value;
    const period = document.getElementById('assessment_period').value;

    if (!fid) { showToast('Please select a facility first', 'error'); document.getElementById('facilitySearch').focus(); return; }
    if (!period) { showToast('Please select an assessment period', 'error'); document.getElementById('assessment_period').focus(); return; }

    const btn = document.querySelector(`#sec_${sectionKey} .btn-save-section`);
    const origTxt = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    btn.classList.add('saving');
    btn.disabled = true;

    // Gather form data for this section
    const fd = new FormData();
    fd.append('ajax_save_section', '1');
    fd.append('section_key', sectionKey);
    fd.append('facility_id', fid);
    fd.append('assessment_period', period);
    fd.append('assessment_id', assessmentId);
    fd.append('facility_name', document.getElementById('h_facility_id').value ? facInput.value : '');
    fd.append('collection_date', document.getElementById('collection_date_input').value);
    // All facility hidden fields
    ['h_mflcode','h_county_name','h_subcounty_name','h_owner','h_sdp','h_agency','h_emr','h_emrstatus','h_infra','h_lat','h_lng','h_level'].forEach(id => {
        const map = {h_mflcode:'mflcode',h_county_name:'county_name',h_subcounty_name:'subcounty_name',
            h_owner:'owner',h_sdp:'sdp',h_agency:'agency',h_emr:'emr',h_emrstatus:'emrstatus',
            h_infra:'infrastructuretype',h_lat:'latitude',h_lng:'longitude',h_level:'level_of_care_name'};
        fd.append(map[id], document.getElementById(id).value);
    });

    // Gather inputs in this section with prefix
    const sec = document.getElementById('sec_' + sectionKey);
    const PREFIX_MAP = {
        s1:'s1_', s2a:'s2a_', s2b:'s2b_', s2c:'s2c_', s3:'s3_', s4:'s4_',
        s5:'s5_', s6:'s6_', s7:'s7_', s8_readiness:'s8r_', s8_lab:'s8l_',
        s9:'s9_', s10:'s10_', s11:'s11_'
    };
    const prefix = PREFIX_MAP[sectionKey] || '';

    // Collect all inputs/selects/textareas in this section
    sec.querySelectorAll('input,select,textarea').forEach(el => {
        if (!el.name || el.name.startsWith('h_')) return;
        if (el.type === 'radio' && !el.checked) return;
        if (el.type === 'checkbox') {
            if (el.checked) fd.append(el.name.replace(prefix,''), el.value);
            return;
        }
        // Strip section prefix when sending to server
        const serverName = el.name.startsWith(prefix) ? el.name.replace(prefix,'') : el.name;
        fd.append(serverName, el.value);
    });

    try {
        const data = await fetch('facility_integration_assessment.php', {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            assessmentId = data.assessment_id;
            document.getElementById('h_assessment_id').value = assessmentId;
            sectionsSaved = data.sections_saved;

            // Update badge
            const badge = document.getElementById('badge_' + sectionKey);
            if (badge) badge.classList.add('show');

            updateProgress();
            showToast('Section saved successfully!', 'success');
        } else {
            showToast(data.error || 'Save failed', 'error');
        }
    } catch(e) {
        showToast('Error saving section, check network or complete all responses and please try again', 'error');
    }

    btn.innerHTML = origTxt;
    btn.classList.remove('saving');
    btn.disabled = false;
}

// ── Final submit ──────────────────────────────────────────────────────────────
async function finalSubmit() {
    if (!assessmentId) { showToast('No assessment to submit', 'error'); return; }
    if (!confirm('Submit this assessment as final? It will be marked as Submitted.')) return;

    const fd = new FormData();
    fd.append('ajax_submit', '1');
    fd.append('assessment_id', assessmentId);

    try {
        const data = await fetch('facility_integration_assessment.php', {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            showToast('Assessment submitted successfully! Redirecting…', 'success');
            setTimeout(() => window.location.href = data.redirect, 1500);
        } else {
            showToast(data.error || 'Submission failed', 'error');
        }
    } catch(e) {
        showToast('Network error — please try again', 'error');
    }
}

// ── Keyboard shortcut Ctrl+S saves currently visible section ─────────────────
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        // Find first visible section that isn't saved
        const unsaved = allSections.find(sk => !sectionsSaved.includes(sk));
        if (unsaved) saveSection(unsaved);
    }
});
</script>
</body>
</html>
