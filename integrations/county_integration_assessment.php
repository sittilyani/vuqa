<?php
// integrations/county_integration_assessment.php
// integrations/county_integration_assessment.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');
include('../includes/county_access.php');

if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

// Access guard: non-admins can only operate on counties they are assigned to.
$_guard_cid = (int)($_GET['county_id'] ?? $_GET['county'] ?? $_POST['county_id'] ?? 0);
if ($_guard_cid && !cf_user_can_access_county($_guard_cid)) {
    if (isset($_POST['ajax_save_section']) || isset($_POST['ajax_submit'])
        || (isset($_GET['ajax']) && $_GET['ajax'] === 'check_assessment')) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'You are not assigned to this county.']);
        exit();
    }
    $_SESSION['error_message'] = 'You are not assigned to this county.';
    header('Location: county_integration_assessment_list.php');
    exit();
}

$collected_by = $_SESSION['full_name'] ?? '';
$uid = (int)$_SESSION['user_id'];

// -- AJAX: check existing county assessment ------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_assessment') {
    $cid    = (int)($_GET['county_id'] ?? 0);
    $period = mysqli_real_escape_string($conn, $_GET['period'] ?? '');
    $result = ['exists' => false];
    if ($cid && $period) {
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT assessment_id, assessment_status, sections_saved, county_name, is_completed
             FROM county_integration_assessments
             WHERE county_id=$cid AND assessment_period='$period'
             ORDER BY assessment_id DESC LIMIT 1"));
        if ($row) {
            $result = [
                'exists'         => true,
                'assessment_id'  => $row['assessment_id'],
                'status'         => $row['assessment_status'],
                'sections_saved' => json_decode($row['sections_saved'] ?? '[]', true),
                'county_name'    => $row['county_name'],
                'is_completed'   => (bool)$row['is_completed'],
            ];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($result);
    exit();
}

// -- AJAX: save a single section (Simplified for clarity) ----------------------
if (isset($_POST['ajax_save_section'])) {
    header('Content-Type: application/json');
    $section    = mysqli_real_escape_string($conn, $_POST['section_key'] ?? '');
    $cid        = (int)($_POST['county_id'] ?? 0);
    $period     = mysqli_real_escape_string($conn, $_POST['assessment_period'] ?? '');
    $aid        = (int)($_POST['assessment_id'] ?? 0);
    $saved_by   = mysqli_real_escape_string($conn, $collected_by);

    if (!$cid || !$period || !$section) {
        echo json_encode(['success'=>false,'error'=>'Missing required fields']);
        exit();
    }

    $e = fn($v) => mysqli_real_escape_string($conn, trim($v ?? ''));
    $i = fn($v) => is_numeric($v) ? (int)$v : 'NULL';

    // -- Get or create assessment header ----------------------------------------
    if (!$aid) {
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT assessment_id FROM county_integration_assessments
             WHERE county_id=$cid AND assessment_period='$period' ORDER BY assessment_id DESC LIMIT 1"));
        if ($existing) {
            $aid = (int)$existing['assessment_id'];
        } else {
            $county_name = $e($_POST['county_name'] ?? '');
            $agency_id   = $i($_POST['agency_id'] ?? 0);
            $agency_name = $e($_POST['agency_name'] ?? '');
            $ip_id       = $i($_POST['ip_id'] ?? 0);
            $ip_name     = $e($_POST['ip_name'] ?? '');
            $cd          = $e($_POST['collection_date'] ?? date('Y-m-d'));
            $adate       = date('Y-m-d');

            mysqli_query($conn,
                "INSERT INTO county_integration_assessments
                 (county_id, county_name, assessment_period, assessment_date, agency_id, agency_name, ip_id, ip_name,
                  assessment_status, sections_saved, collected_by, collection_date, is_completed)
                 VALUES ($cid, '$county_name', '$period', '$adate', $agency_id, '$agency_name', $ip_id, '$ip_name',
                         'Draft', '[]', '$saved_by', '$cd', 0)");
            $aid = (int)mysqli_insert_id($conn);
        }
    }

    // -- Build SET clause from section ------------------------------------------
    $sets = [];

    // Section 1: County Profile
    if ($section === 's1') {
        $sets[] = "county_id=$cid";
        $sets[] = "county_name='{$e($_POST['county_name']??'')}'";
        $sets[] = "agency_id={$i($_POST['agency_id']??0)}";
        $sets[] = "agency_name='{$e($_POST['agency_name']??'')}'";
        $sets[] = "ip_id={$i($_POST['ip_id']??0)}";
        $sets[] = "ip_name='{$e($_POST['ip_name']??'')}'";
    }

    // Section 2a: Integration of HIV/TB Services
    if ($section === 's2a') {
        $sets[] = "hiv_tb_integration_plan='{$e($_POST['hiv_tb_integration_plan']??'')}'";
        $sets[] = "hiv_tb_integration_meeting='{$e($_POST['hiv_tb_integration_meeting']??'')}'";
    }

    // Section 2b: EMR Integration
    if ($section === 's2b') {
        $sets[] = "selected_emr_type='{$e($_POST['selected_emr_type']??'')}'";
        $sets[] = "other_emr_specify='{$e($_POST['other_emr_specify']??'')}'";
        $sets[] = "emr_deployment_meetings='{$e($_POST['emr_deployment_meetings']??'')}'";
        $sets[] = "has_his_integration_guide='{$e($_POST['has_his_integration_guide']??'')}'";
        $sets[] = "has_dedicated_his_staff='{$e($_POST['has_dedicated_his_staff']??'')}'";
    }

    // Section 3: HRH Transition
    if ($section === 's3') {
        $sets[] = "has_hrh_transition_plan='{$e($_POST['has_hrh_transition_plan']??'')}'";
        $sets[] = "hcw_total_pepfar={$i($_POST['hcw_total_pepfar']??0)}";
        $sets[] = "hcw_clinical_pepfar={$i($_POST['hcw_clinical_pepfar']??0)}";
        $sets[] = "hcw_nonclinical_pepfar={$i($_POST['hcw_nonclinical_pepfar']??0)}";
        $sets[] = "hcw_data_pepfar={$i($_POST['hcw_data_pepfar']??0)}";
        $sets[] = "hcw_community_pepfar={$i($_POST['hcw_community_pepfar']??0)}";
        $sets[] = "hcw_other_pepfar={$i($_POST['hcw_other_pepfar']??0)}";
        $sets[] = "hcw_transitioned_total={$i($_POST['hcw_transitioned_total']??0)}";
        $sets[] = "hcw_transitioned_clinical={$i($_POST['hcw_transitioned_clinical']??0)}";
        $sets[] = "hcw_transitioned_nonclinical={$i($_POST['hcw_transitioned_nonclinical']??0)}";
        $sets[] = "hcw_transitioned_data={$i($_POST['hcw_transitioned_data']??0)}";
        $sets[] = "hcw_transitioned_community={$i($_POST['hcw_transitioned_community']??0)}";
        $sets[] = "hcw_transitioned_other={$i($_POST['hcw_transitioned_other']??0)}";
    }

    // Section 4: PLHIV Enrollment in SHA
    if ($section === 's4') {
        $sets[] = "has_plhiv_sha_plan='{$e($_POST['has_plhiv_sha_plan']??'')}'";
        $sets[] = "plhiv_sha_review_meeting='{$e($_POST['plhiv_sha_review_meeting']??'')}'";
    }

    // Section 5: County Led Technical Assistance / Mentorship
    if ($section === 's5') {
        $sets[] = "has_ta_mentorship_plan='{$e($_POST['has_ta_mentorship_plan']??'')}'";
        $sets[] = "county_mentorship_support='{$e($_POST['county_mentorship_support']??'')}'";
        $sets[] = "mentorship_teams_involved='{$e($_POST['mentorship_teams_involved']??'')}'";
        $sets[] = "logistical_support_source='{$e($_POST['logistical_support_source']??'')}'";
        $sets[] = "uses_standardized_moh_tools='{$e($_POST['uses_standardized_moh_tools']??'')}'";
        $sets[] = "facilities_visited_ta={$i($_POST['facilities_visited_ta']??0)}";
        $sets[] = "ta_review_meeting='{$e($_POST['ta_review_meeting']??'')}'";
    }

    // Section 6: Lab Support
    if ($section === 's6') {
        $sets[] = "has_isrs_operational_plan='{$e($_POST['has_isrs_operational_plan']??'')}'";
        $sets[] = "isrs_funding_allocated='{$e($_POST['isrs_funding_allocated']??'')}'";
        $sets[] = "has_lab_strategic_plan='{$e($_POST['has_lab_strategic_plan']??'')}'";
        $sets[] = "lab_forecasting_conducted='{$e($_POST['lab_forecasting_conducted']??'')}'";
        $sets[] = "has_commodity_order_team='{$e($_POST['has_commodity_order_team']??'')}'";
        $sets[] = "qms_funding_allocated='{$e($_POST['qms_funding_allocated']??'')}'";
        $sets[] = "has_lab_twg='{$e($_POST['has_lab_twg']??'')}'";
        $sets[] = "has_lmis_integration_guide='{$e($_POST['has_lmis_integration_guide']??'')}'";
        $sets[] = "poct_rtcqi_implementing='{$e($_POST['poct_rtcqi_implementing']??'')}'";
        $sets[] = "rtcqi_support_source='{$e($_POST['rtcqi_support_source']??'')}'";
    }

    // Section 7: TB Prevention Services
    if ($section === 's7') {
        $sets[] = "has_tb_diagnostic_twg='{$e($_POST['has_tb_diagnostic_twg']??'')}'";
        $sets[] = "tb_twg_activities_count={$i($_POST['tb_twg_activities_count']??0)}";
        $sets[] = "hcw_reached_tb_training={$i($_POST['hcw_reached_tb_training']??0)}";
        $sets[] = "chest_xray_in_awp='{$e($_POST['chest_xray_in_awp']??'')}'";
        $sets[] = "cxr_machines_licensed={$i($_POST['cxr_machines_licensed']??0)}";
        $sets[] = "cxr_machines_functional={$i($_POST['cxr_machines_functional']??0)}";
        $sets[] = "cxr_machines_ai_enabled={$i($_POST['cxr_machines_ai_enabled']??0)}";
        $sets[] = "facilities_using_cxr_tb={$i($_POST['facilities_using_cxr_tb']??0)}";
        $sets[] = "cxr_qa_qc_supported='{$e($_POST['cxr_qa_qc_supported']??'')}'";
    }

    // Section 8: County Based Technical Working Groups
    if ($section === 's8') {
        $sets[] = "has_hiv_tb_twg='{$e($_POST['has_hiv_tb_twg']??'')}'";
        $sets[] = "hiv_tb_twg_meetings={$i($_POST['hiv_tb_twg_meetings']??0)}";
        $sets[] = "has_pmtct_twg='{$e($_POST['has_pmtct_twg']??'')}'";
        $sets[] = "pmtct_twg_meetings={$i($_POST['pmtct_twg_meetings']??0)}";
        $sets[] = "has_mnch_twg='{$e($_POST['has_mnch_twg']??'')}'";
        $sets[] = "mnch_twg_meetings={$i($_POST['mnch_twg_meetings']??0)}";
        $sets[] = "has_hiv_prevention_twg='{$e($_POST['has_hiv_prevention_twg']??'')}'";
        $sets[] = "hiv_prevention_twg_meetings={$i($_POST['hiv_prevention_twg_meetings']??0)}";
        $sets[] = "has_integration_oversight_team='{$e($_POST['has_integration_oversight_team']??'')}'";
        $sets[] = "integration_oversight_meeting='{$e($_POST['integration_oversight_meeting']??'')}'";
    }

    // Section 9: Financing and Sustainability
    if ($section === 's9') {
        $sets[] = "has_fif_collection_plan='{$e($_POST['has_fif_collection_plan']??'')}'";
        $sets[] = "receives_sha_capitation='{$e($_POST['receives_sha_capitation']??'')}'";
    }

    // Section 10: Stakeholder Engagement
    if ($section === 's10') {
        $sets[] = "has_stakeholder_engagement_plan='{$e($_POST['has_stakeholder_engagement_plan']??'')}'";
        $sets[] = "stakeholder_meetings_count={$i($_POST['stakeholder_meetings_count']??0)}";
    }

    // Section 11: Mortality Outcomes
    if ($section === 's11') {
        $sets[] = "days_without_maternal_deaths={$i($_POST['days_without_maternal_deaths']??0)}";
    }

    // Section 12: AHD
    if ($section === 's12') {
        $sets[] = "ahd_hubs_available={$i($_POST['ahd_hubs_available']??0)}";
        $sets[] = "ahd_hubs_activated={$i($_POST['ahd_hubs_activated']??0)}";
    }

    // Section 13: Governance
    if ($section === 's13') {
        $sets[] = "has_hiv_integration_oversight='{$e($_POST['has_hiv_integration_oversight']??'')}'";
        $sets[] = "integration_oversight_meeting_held='{$e($_POST['integration_oversight_meeting_held']??'')}'";
    }

    // Section 14: Supply Chain
    if ($section === 's14') {
        $sets[] = "has_hpt_unit='{$e($_POST['has_hpt_unit']??'')}'";
        $sets[] = "hpt_twg_meeting_held='{$e($_POST['hpt_twg_meeting_held']??'')}'";
        $sets[] = "has_valid_fq_report='{$e($_POST['has_valid_fq_report']??'')}'";
        $sets[] = "provides_supply_chain_training='{$e($_POST['provides_supply_chain_training']??'')}'";
    }

    // Section 15: Primary Health Care
    if ($section === 's15') {
        $sets[] = "hiv_tb_in_phc_plans='{$e($_POST['hiv_tb_in_phc_plans']??'')}'";
        $sets[] = "phc_hiv_review_meeting='{$e($_POST['phc_hiv_review_meeting']??'')}'";
        $sets[] = "phc_service_delivery_operationalized='{$e($_POST['phc_service_delivery_operationalized']??'')}'";
    }

    // Update sections_saved
    $ss_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT sections_saved, is_completed FROM county_integration_assessments WHERE assessment_id=$aid"));
    $ss = json_decode($ss_row['sections_saved'] ?? '[]', true) ?: [];
    if (!in_array($section, $ss)) $ss[] = $section;
    $ss_json = $e(json_encode($ss));

    $all_sections = ['s1','s2a','s2b','s3','s4','s5','s6','s7','s8','s9','s10','s11','s12','s13','s14','s15'];
    $status = (count($ss) >= count($all_sections)) ? 'Complete' : 'Draft';
    $is_completed = (count($ss) >= count($all_sections)) ? 1 : 0;

    $sets[] = "sections_saved='$ss_json'";
    $sets[] = "assessment_status='$status'";
    $sets[] = "is_completed=$is_completed";
    $sets[] = "last_section_saved='$section'";
    $sets[] = "last_saved_at=NOW()";
    $sets[] = "last_saved_by='$saved_by'";

    if ($is_completed == 1 && ($ss_row['is_completed'] ?? 0) == 0) {
        $sets[] = "completed_at=NOW()";
        $sets[] = "completed_by='$saved_by'";
    }

    if (!empty($sets)) {
        mysqli_query($conn,
            "UPDATE county_integration_assessments SET ".implode(',',$sets)." WHERE assessment_id=$aid");
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

// -- AJAX: final submit --------------------------------------------------------
if (isset($_POST['ajax_submit'])) {
    header('Content-Type: application/json');
    $aid = (int)($_POST['assessment_id'] ?? 0);
    if ($aid) {
        mysqli_query($conn,
            "UPDATE county_integration_assessments SET assessment_status='Submitted', is_completed=1,
             completed_at=NOW(), completed_by='".mysqli_real_escape_string($conn,$collected_by)."',
             last_saved_at=NOW(), last_saved_by='".mysqli_real_escape_string($conn,$collected_by)."'
             WHERE assessment_id=$aid");
        echo json_encode(['success'=>true,'redirect'=>'county_integration_assessment_list.php']);
    } else {
        echo json_encode(['success'=>false,'error'=>'No assessment ID']);
    }
    exit();
}

// -- Load existing assessment if editing ---------------------------------------
$edit_id = (int)($_GET['id'] ?? 0);
$existing = null;
$sections_saved = [];

// NEW: Check for new assessment parameters from URL
$new_county_id = (int)($_GET['county_id'] ?? 0);
$new_period = $_GET['period'] ?? '';
$new_county_name = $_GET['county_name'] ?? '';

if ($edit_id) {
    $existing = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM county_integration_assessments WHERE assessment_id=$edit_id LIMIT 1"));
    if ($existing) {
        $sections_saved = json_decode($existing['sections_saved'] ?? '[]', true) ?: [];
    }
}

// Get counties, agencies, implementing partners — counties are filtered by assignment
$_cf_part = cf_county_filter_sql();
$counties_r = mysqli_query($conn, "SELECT county_id, county_name, county_code FROM counties WHERE 1=1 $_cf_part ORDER BY county_name");
$counties = [];
if ($counties_r) while ($r = mysqli_fetch_assoc($counties_r)) $counties[] = $r;

$agencies_r = mysqli_query($conn, "SELECT agency_id, agency_name FROM agencies ORDER BY agency_name");
$agencies = [];
if ($agencies_r) while ($r = mysqli_fetch_assoc($agencies_r)) $agencies[] = $r;

$ips_r = mysqli_query($conn, "SELECT ip_id, ip_name FROM implementing_partners ORDER BY ip_name");
$ips = [];
if ($ips_r) while ($r = mysqli_fetch_assoc($ips_r)) $ips[] = $r;

// -- All section definitions for progress tracking -----------------------------
$all_section_defs = [
    's1'  => 'Section 1: County Profile',
    's2a' => 'Section 2a: HIV/TB Services Integration',
    's2b' => 'Section 2b: EMR Integration',
    's3'  => 'Section 3: HRH Transition',
    's4'  => 'Section 4: PLHIV Enrollment in SHA',
    's5'  => 'Section 5: TA and Mentorship',
    's6'  => 'Section 6: Lab Support',
    's7'  => 'Section 7: TB Prevention Services',
    's8'  => 'Section 8: Technical Working Groups',
    's9'  => 'Section 9: Financing and Sustainability',
    's10' => 'Section 10: Stakeholder Engagement',
    's11' => 'Section 11: Mortality Outcomes',
    's12' => 'Section 12: AHD',
    's13' => 'Section 13: Governance',
    's14' => 'Section 14: Supply Chain',
    's15' => 'Section 15: Primary Health Care',
];

// Helper functions
function v($key, $existing) { return htmlspecialchars($existing[$key] ?? ''); }
function sel($key, $val, $existing) { return ($existing[$key] ?? '') === $val ? 'selected' : ''; }
function chk($key, $val, $existing) { return ($existing[$key] ?? '') === $val ? 'checked' : ''; }

$e_data = $existing ?? [];

// MODIFIED: Check both existing assessment OR new assessment parameters
$pre_county_id = $e_data['county_id'] ?? $new_county_id;
$pre_county_name = $e_data['county_name'] ?? $new_county_name;
$pre_period = $e_data['assessment_period'] ?? $new_period;

// MODIFIED: Show form if editing existing OR starting new with valid params
$show_form = ($edit_id && $existing) || ($new_county_id && $new_period);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>County Integration Assessment Tool</title>
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
.wrap{max-width:1280px;margin:0 auto;padding:20px;}

/* Header */
.page-header{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:20px 28px;
    border-radius:14px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;
    box-shadow:0 6px 24px rgba(13,26,99,.22);}
.page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;
    border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.hdr-links a:hover{background:rgba(255,255,255,.28);}

/* Layout */
.layout{display:grid;grid-template-columns:260px 1fr;gap:22px;align-items:start;}
.sidebar{position:sticky;top:16px;background:var(--card);border-radius:14px;
    box-shadow:var(--shadow);overflow:hidden;}
.sidebar-head{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;
    padding:14px 18px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
.sidebar-body{padding:10px 8px;max-height:calc(100vh - 200px);overflow-y:auto;}
.sec-nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;
    cursor:pointer;transition:.15s;font-size:12px;font-weight:500;color:#444;margin-bottom:2px;}
.sec-nav-item:hover{background:#f0f3fb;color:var(--navy);}
.sec-nav-item.saved{color:var(--green);}
.sec-nav-item.saved .sec-dot{background:var(--green);}
.sec-nav-item.unsaved .sec-dot{background:#e5e7eb;}
.sec-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;transition:.3s;}
.sec-nav-item .sec-icon{font-size:12px;width:16px;text-align:center;}
.progress-wrap{padding:12px 16px 14px;border-top:1px solid var(--border);}
.progress-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;justify-content:space-between;}
.progress-bar-outer{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;}
.progress-bar-inner{height:100%;background:linear-gradient(90deg,var(--teal),var(--green));border-radius:99px;transition:width .5s;}

/* Setup card */
.setup-card{background:var(--card);border-radius:14px;padding:20px 22px;margin-bottom:22px;
    box-shadow:var(--shadow);border-left:4px solid var(--teal);}
.setup-card h3{font-size:13px;font-weight:700;color:var(--navy);text-transform:uppercase;
    letter-spacing:.7px;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.setup-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:end;}
.setup-field label{display:block;font-size:11px;font-weight:700;color:var(--muted);
    text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.setup-field select{width:100%;padding:10px 13px;border:1.5px solid var(--border);
    border-radius:8px;font-size:13.5px;transition:.2s;font-family:inherit;background:#fff;}
.setup-field select:focus{outline:none;border-color:var(--navy);}
.btn-load{background:var(--navy);color:#fff;border:none;padding:10px 22px;border-radius:8px;
    font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;height:42px;transition:.2s;}
.btn-load:hover{background:var(--navy2);}
.btn-load:disabled{opacity:0.6;cursor:not-allowed;}

/* County info card */
.county-card{border:2px solid var(--navy);border-radius:10px;padding:14px 18px;
    background:linear-gradient(135deg,#f0f3fb,#fff);margin-top:10px;display:none;}
.county-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
.county-card-name{font-weight:700;color:var(--navy);font-size:15px;display:flex;align-items:center;gap:8px;}

/* Section cards */
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

/* Form elements */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;}
.form-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
.form-group{margin-bottom:14px;}
.form-group.full{grid-column:1/-1;}
.form-group label{display:block;margin-bottom:5px;font-weight:600;color:#374151;font-size:13px;}
.hint{font-size:11px;color:#2563eb;font-style:italic;margin-bottom:6px;padding:4px 10px;
    background:#f8fafc;border-left:3px solid var(--teal);border-radius:0 4px 4px 0;}
.req{color:var(--rose);}
.form-control,.form-select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:7px;
    font-size:13px;transition:.2s;background:#fff;font-family:inherit;}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(13,26,99,.08);}
textarea.form-control{min-height:80px;resize:vertical;}

/* Radio / checkbox */
.yn-group{display:flex;gap:16px;margin-top:5px;flex-wrap:wrap;}
.yn-opt{display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;
    padding:6px 14px;background:#f8fafc;border-radius:7px;border:1.5px solid var(--border);transition:.15s;}
.yn-opt:has(input:checked){background:#eef1ff;border-color:var(--navy);}
.yn-opt input{width:15px;height:15px;accent-color:var(--navy);cursor:pointer;}

/* Save button */
.btn-save-section{background:var(--teal);color:#fff;border:none;padding:9px 22px;border-radius:8px;
    font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;
    transition:.2s;margin-top:18px;}
.btn-save-section:hover{background:#089e9b;}
.btn-save-section.saving{background:#aaa;cursor:not-allowed;}

/* Admin box */
.admin-box{background:#f0f4ff;border:1px solid #c5d0f0;border-radius:9px;padding:12px 16px;
    display:flex;align-items:center;gap:12px;}
.admin-icon{width:40px;height:40px;background:var(--navy);border-radius:10px;display:flex;
    align-items:center;justify-content:center;color:#fff;font-size:17px;flex-shrink:0;}
.admin-name{font-size:14px;font-weight:700;color:var(--navy);}
.admin-label{font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;}

/* Submit zone */
.submit-zone{background:var(--card);border-radius:14px;padding:22px 28px;box-shadow:var(--shadow);
    margin-bottom:28px;text-align:center;}
.submit-progress{font-size:14px;font-weight:600;color:var(--muted);margin-bottom:14px;}
.btn-submit-final{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;
    padding:14px 44px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;
    transition:.2s;display:inline-flex;align-items:center;gap:10px;}
.btn-submit-final:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(13,26,99,.3);}
.btn-submit-final:disabled{background:#aaa;cursor:not-allowed;transform:none;box-shadow:none;}

/* Toast */
.toast{position:fixed;bottom:24px;right:24px;z-index:9999;background:#fff;border-radius:12px;
    padding:14px 20px;box-shadow:0 8px 32px rgba(0,0,0,.18);display:flex;align-items:center;gap:12px;
    font-size:13.5px;font-weight:600;transform:translateY(80px);opacity:0;transition:.3s;pointer-events:none;
    max-width:340px;border-left:4px solid var(--green);}
.toast.show{transform:translateY(0);opacity:1;}
.toast.error{border-left-color:var(--rose);}
.toast-icon{font-size:18px;}
.toast.success .toast-icon{color:var(--green);}
.toast.error .toast-icon{color:var(--rose);}

/* Modal */
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
@media(max-width:640px){.form-grid,.form-grid-3{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="page-header">
    <h1><i class="fas fa-landmark"></i> County Integration Assessment Tool</h1>
    <div class="hdr-links">
        <a href="county_integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
        <?php if ($edit_id): ?>
        <span style="background:rgba(255,255,255,.2);padding:7px 14px;border-radius:8px;font-size:13px;">
            Editing #<?= $edit_id ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<div id="globalAlert"></div>

<!-- Setup Card -->
<div class="setup-card">
    <h3><i class="fas fa-cog"></i> Select County and Period</h3>
    <div class="setup-grid">
        <div class="setup-field">
            <label>County</label>
            <select id="countySelect">
                <option value="">Select County</option>
                <?php foreach ($counties as $c): ?>
                <option value="<?= $c['county_id'] ?>" data-name="<?= htmlspecialchars($c['county_name']) ?>"
                    <?= $pre_county_id==$c['county_id']?'selected':'' ?>><?= htmlspecialchars($c['county_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="setup-field">
            <label>Period</label>
            <select id="periodSelect">
                <option value="">Select Period</option>
                <?php $ps = ['Oct-Dec 2025', 'Jan-Mar 2026','Apr-Jun 2026','Jul-Sep 2026','Oct-Dec 2026'];
                foreach ($ps as $p): ?>
                <option value="<?= $p ?>" <?= $pre_period===$p?'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn-load" id="btnLoad" onclick="loadAssessment()"><i class="fas fa-arrow-right"></i> Load or Start</button>
    </div>

    <div class="county-card" id="countyCard" <?= $show_form ? 'style="display:block"' : '' ?>>
        <div class="county-card-header">
            <div class="county-card-name">
                <i class="fas fa-map-marker-alt" style="color:var(--teal)"></i>
                <span id="cc_name"><?= htmlspecialchars($pre_county_name) ?></span>
            </div>
            <span id="cc_period" style="background:#e8edf8;color:var(--navy);padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700">
                <?= htmlspecialchars($pre_period) ?>
            </span>
        </div>
    </div>
</div>

<!-- Hidden fields -->
<input type="hidden" id="h_assessment_id" value="<?= $edit_id ?>">
<input type="hidden" id="h_county_id" value="<?= $pre_county_id ?>">
<input type="hidden" id="h_period" value="<?= htmlspecialchars($pre_period) ?>">

<?php if ($show_form): ?>
<div class="layout">

<!-- Sidebar -->
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
            <span><?= $sl ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="progress-wrap">
        <div class="progress-label"><span>Progress</span><span id="progressPct">0%</span></div>
        <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar" style="width:0%"></div></div>
    </div>
</aside>

<!-- Main Form -->
<div id="mainForm">

<!-- SECTION 1: COUNTY PROFILE -->
<div class="form-section" id="sec_s1">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-building"></i> Section 1: County Profile</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s1',$sections_saved)?'show':'' ?>" id="badge_s1"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>1. County <span class="req">*</span></label>
                <div class="hint">Indicate the Name of the County</div>
                <select name="s1_county_id" id="s1_county_id" class="form-select">
                    <option value="">Select County</option>
                    <?php foreach ($counties as $c): ?>
                    <option value="<?= $c['county_id'] ?>" data-name="<?= htmlspecialchars($c['county_name']) ?>"
                        <?= ($e_data['county_id']??0)==$c['county_id']?'selected':($pre_county_id==$c['county_id']?'selected':'') ?>>
                        <?= htmlspecialchars($c['county_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>2a. Implementing Agency</label>
                <div class="hint">Select the Name of the Implementing Agency (DOS, CDC, DOW, GF)</div>
                <select name="s1_agency_id" id="s1_agency_id" class="form-select">
                    <option value="">Select Agency</option>
                    <?php foreach ($agencies as $a): ?>
                    <option value="<?= $a['agency_id'] ?>" data-name="<?= htmlspecialchars($a['agency_name']) ?>"
                        <?= ($e_data['agency_id']??0)==$a['agency_id']?'selected':'' ?>>
                        <?= htmlspecialchars($a['agency_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>2b. Implementing Partner</label>
                <div class="hint">Select the Name of the Implementing Partner</div>
                <select name="s1_ip_id" id="s1_ip_id" class="form-select">
                    <option value="">Select Implementing Partner</option>
                    <?php foreach ($ips as $ip): ?>
                    <option value="<?= $ip['ip_id'] ?>" data-name="<?= htmlspecialchars($ip['ip_name']) ?>"
                        <?= ($e_data['ip_id']??0)==$ip['ip_id']?'selected':'' ?>>
                        <?= htmlspecialchars($ip['ip_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s1')"><i class="fas fa-save"></i> Save Section 1</button>
    </div>
</div>

<!-- SECTION 2a: HIV/TB SERVICES INTEGRATION -->
<div class="form-section" id="sec_s2a">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-virus"></i> Section 2a: Integration of HIV/TB Services</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s2a',$sections_saved)?'show':'' ?>" id="badge_s2a"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>3. Does the County have a HIV Prevention, HIV/TB and PMTCT integration in OPD/Clinical care model plan?</label>
                <div class="hint">Please select YES, if the County has Integrated HIV/TB services within OPD or clinical care model, or NO if it has not.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2a_hiv_tb_integration_plan" value="Yes" <?= chk('hiv_tb_integration_plan','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2a_hiv_tb_integration_plan" value="No" <?= chk('hiv_tb_integration_plan','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>4. Has the County held HIV Prevention, HIV/TB and PMTCT service integration meeting in the last 3 months?</label>
                <div class="hint">Please select YES, if the County held HIV/TB service integration meeting in the last 3 months</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2a_hiv_tb_integration_meeting" value="Yes" <?= chk('hiv_tb_integration_meeting','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2a_hiv_tb_integration_meeting" value="No" <?= chk('hiv_tb_integration_meeting','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s2a')"><i class="fas fa-save"></i> Save Section 2a</button>
    </div>
</div>

<!-- SECTION 2b: EMR INTEGRATION -->
<div class="form-section" id="sec_s2b">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-laptop-medical"></i> Section 2b: EMR Integration</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s2b',$sections_saved)?'show':'' ?>" id="badge_s2b"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>5. Which EMR type has the county selected for facility-wide deployment?</label>
                <div class="hint">Please select the EMR type that the county selected for facility-wide deployment</div>
                <select name="s2b_selected_emr_type" class="form-select">
                    <option value="">Select EMR Type</option>
                    <?php foreach(['KenyaEMR','Tiberbu','AfyaKE','Other'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= sel('selected_emr_type',$opt,$e_data) ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>6. Other EMR, Please Specify</label>
                <div class="hint">Please specify if choice above is other EMR </div>
                <input type="text" name="s2b_other_emr_specify" class="form-control" value="<?= v('other_emr_specify',$e_data) ?>">
            </div>
            <div class="form-group">
                <label>7. Has the County had meetings on EMR facility-wide deployment in the last 3 months?</label>
                <div class="hint">Please select YES, if the County has had meetings on EMR facility-wide deployment in the last 3 months, or NO if not.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2b_emr_deployment_meetings" value="Yes" <?= chk('emr_deployment_meetings','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2b_emr_deployment_meetings" value="No" <?= chk('emr_deployment_meetings','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>8. Does the county have a standard systems integration guide (e.g. FHIR) in place for hospital wide HIS integration?</label>
                <div class="hint">Please select YES if there is a standard Systems integration guide/FHIR guide in place for hospital wide HIS integration, or NO if not available.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2b_has_his_integration_guide" value="Yes" <?= chk('has_his_integration_guide','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2b_has_his_integration_guide" value="No" <?= chk('has_his_integration_guide','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>9. Does the county have a dedicated technical staff supporting HIS needs?</label>
                <div class="hint">Please select YES if the county has dedicated technical staff supporting HIS needs, or NO if not available</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s2b_has_dedicated_his_staff" value="Yes" <?= chk('has_dedicated_his_staff','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s2b_has_dedicated_his_staff" value="No" <?= chk('has_dedicated_his_staff','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s2b')"><i class="fas fa-save"></i> Save Section 2b</button>
    </div>
</div>

<!-- SECTION 3: HRH TRANSITION -->
<div class="form-section" id="sec_s3">
    <div class="section-head">
        <div class="section-head-left"> Section 3: HRH Transition (Workforce Absorption)</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s3',$sections_saved)?'show':'' ?>" id="badge_s3"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-group">
            <label>10. Does the County have a HRH transition plan for absorption of PEPFAR IP supported staff?</label>
            <div class="hint">Please select YES if the County has a HRH transition plan for absorption of PEPFAR IP supported staff, or NO if not</div>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="s3_has_hrh_transition_plan" value="Yes" <?= chk('has_hrh_transition_plan','Yes',$e_data) ?>> Yes</label>
                <label class="yn-opt"><input type="radio" name="s3_has_hrh_transition_plan" value="No" <?= chk('has_hrh_transition_plan','No',$e_data) ?>> No</label>
            </div>
        </div>

        <div class="sub-label" style="margin-top:20px"> HCWs Supported by PEPFAR IP in the County</div>
        <div class="form-grid-3">
            <div class="form-group"><label>11. Total HCWs supported by PEPFAR IP</label><input type="number" name="s3_hcw_total_pepfar" class="form-control" value="<?= v('hcw_total_pepfar',$e_data) ?>"></div>
            <div class="form-group"><label>12. Clinical Staff</label><input type="number" name="s3_hcw_clinical_pepfar" class="form-control" value="<?= v('hcw_clinical_pepfar',$e_data) ?>"></div>
            <div class="form-group"><label>13. Non Clinical Staff</label><input type="number" name="s3_hcw_nonclinical_pepfar" class="form-control" value="<?= v('hcw_nonclinical_pepfar',$e_data) ?>"></div>
            <div class="form-group"><label>14. Data Staff</label><input type="number" name="s3_hcw_data_pepfar" class="form-control" value="<?= v('hcw_data_pepfar',$e_data) ?>"></div>
            <div class="form-group"><label>15. Community Staff</label><input type="number" name="s3_hcw_community_pepfar" class="form-control" value="<?= v('hcw_community_pepfar',$e_data) ?>"></div>
            <div class="form-group"><label>16. Other Staff</label><input type="number" name="s3_hcw_other_pepfar" class="form-control" value="<?= v('hcw_other_pepfar',$e_data) ?>"></div>
        </div>

        <div class="sub-label" style="margin-top:20px">HCWs Transitioned to County Support (Payroll)</div>
        <div class="form-grid-3">
            <div class="form-group"><label>17. Total HCWs Transitioned</label><input type="number" name="s3_hcw_transitioned_total" class="form-control" value="<?= v('hcw_transitioned_total',$e_data) ?>"></div>
            <div class="form-group"><label>18. Clinical Staff Transitioned</label><input type="number" name="s3_hcw_transitioned_clinical" class="form-control" value="<?= v('hcw_transitioned_clinical',$e_data) ?>"></div>
            <div class="form-group"><label>19. Non Clinical Staff Transitioned</label><input type="number" name="s3_hcw_transitioned_nonclinical" class="form-control" value="<?= v('hcw_transitioned_nonclinical',$e_data) ?>"></div>
            <div class="form-group"><label>20. Data Staff Transitioned</label><input type="number" name="s3_hcw_transitioned_data" class="form-control" value="<?= v('hcw_transitioned_data',$e_data) ?>"></div>
            <div class="form-group"><label>21. Community Staff Transitioned</label><input type="number" name="s3_hcw_transitioned_community" class="form-control" value="<?= v('hcw_transitioned_community',$e_data) ?>"></div>
            <div class="form-group"><label>22. Other Staff Transitioned</label><input type="number" name="s3_hcw_transitioned_other" class="form-control" value="<?= v('hcw_transitioned_other',$e_data) ?>"></div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s3')"><i class="fas fa-save"></i> Save Section 3</button>
    </div>
</div>

<!-- SECTION 4: PLHIV ENROLLMENT IN SHA -->
<div class="form-section" id="sec_s4">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-id-card"></i> Section 4: PLHIV Enrollment in SHA</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s4',$sections_saved)?'show':'' ?>" id="badge_s4"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>23. Does the County have a plan for PLHIV and PBFW enrolment into SHA?</label>
                <div class="hint">Please select YES, if the County has a plan for PLHIV enrollment in SHA, or NO if not.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s4_has_plhiv_sha_plan" value="Yes" <?= chk('has_plhiv_sha_plan','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s4_has_plhiv_sha_plan" value="No" <?= chk('has_plhiv_sha_plan','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>24. Has the County held a meeting to review progress on PLHIV and PBFW enrolment into SHA in the last 3 months?</label>
                <div class="hint">Please select YES, if the County held a meeting to review progress on PLHIV enrollment in SHA in the last 3 months, or NO if not.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s4_plhiv_sha_review_meeting" value="Yes" <?= chk('plhiv_sha_review_meeting','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s4_plhiv_sha_review_meeting" value="No" <?= chk('plhiv_sha_review_meeting','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s4')"><i class="fas fa-save"></i> Save Section 4</button>
    </div>
</div>

<!-- SECTION 5: TA AND MENTORSHIP -->
<div class="form-section" id="sec_s5">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-chalkboard-teacher"></i> Section 5: County Led Technical Assistance and Mentorship</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s5',$sections_saved)?'show':'' ?>" id="badge_s5"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>25. Does the County have a plan for providing TA/Mentorship for HIV Prevention, HIV/TB, PMTCT and MNCH services in the absence of PEPFAR?</label>
                <div class="hint">Please select YES if the County has a plan for providing TA/Mentorship for HIV/TB services in the absence of PEPFAR, or NO if not.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s5_has_ta_mentorship_plan" value="Yes" <?= chk('has_ta_mentorship_plan','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s5_has_ta_mentorship_plan" value="No" <?= chk('has_ta_mentorship_plan','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>26. What is the current county support for the county-led mentorships across the facilities?</label>
                <div class="hint">Please select the current county support for the county-led mentorships across the facilities</div>
                <select name="s5_county_mentorship_support" class="form-select">
                    <option value="">Select Support Type</option>
                    <?php foreach(['Personnel TA Mentorship','Logistical support','Financial support','No support'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= sel('county_mentorship_support',$opt,$e_data) ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>27. If Personnel TA/Mentorship support, please specify the teams involved</label>
                <div class="hint">Please specify the teams involved in providing County-led mentorship/TA</div>
                <input type="text" name="s5_mentorship_teams_involved" class="form-control" value="<?= v('mentorship_teams_involved',$e_data) ?>">
            </div>
            <div class="form-group">
                <label>28. If Logistical support, please specify the source</label>
                <div class="hint">Please specify whether the logistical support was from the County, IP, or Both</div>
                <select name="s5_logistical_support_source" class="form-select">
                    <option value="">Select Source</option>
                    <?php foreach(['County','IP','Both'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= sel('logistical_support_source',$opt,$e_data) ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>29. Is the County using standardized MOH tools (National or County) for their TA/mentorship?</label>
                <div class="hint">Please select Yes if the County uses standardized MOH tools for their TA/mentorship, or No if not</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s5_uses_standardized_moh_tools" value="Yes" <?= chk('uses_standardized_moh_tools','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s5_uses_standardized_moh_tools" value="No" <?= chk('uses_standardized_moh_tools','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>30. How many health facilities has the county visited for TA/Mentorship in the last 3 months?</label>
                <div class="hint">Indicate the number of health facilities visited for TA/Mentorship on HIV prevention, HIV/TB Services and PMTCT services in the last 3 months</div>
                <input type="number" name="s5_facilities_visited_ta" class="form-control" value="<?= v('facilities_visited_ta',$e_data) ?>">
            </div>
            <div class="form-group">
                <label>31. Has the County held a meeting to review progress on implementation of County-led TA/Mentorship in the last 3 months?</label>
                <div class="hint">Please select YES, if the County held a meeting to review progress on implementation of County-led TA/Mentorship, or NO if not.</div>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s5_ta_review_meeting" value="Yes" <?= chk('ta_review_meeting','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s5_ta_review_meeting" value="No" <?= chk('ta_review_meeting','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s5')"><i class="fas fa-save"></i> Save Section 5</button>
    </div>
</div>

<!-- SECTION 6: LAB SUPPORT -->
<div class="form-section" id="sec_s6">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-flask"></i> Section 6: Lab Support</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s6',$sections_saved)?'show':'' ?>" id="badge_s6"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <?php
            $lab_questions = [
                ['32','has_isrs_operational_plan','Does the County have an approved strategic operational plan for the Integrated Sample Referral System (ISRS)?'],
                ['33','isrs_funding_allocated','Has the County allocated funding for ISRS (Culture, DST, viral load and EID)?'],
                ['34','has_lab_strategic_plan','Does the county have a Laboratory Strategic plan and budget?'],
                ['35','lab_forecasting_conducted','Does the county lab team conduct laboratory commodities forecasting and quantification (F&Q) and allocation?'],
                ['36','has_commodity_order_team','Does the county have a functional commodity order management team?'],
                ['37','qms_funding_allocated','Has the County allocated funding for QMS activities (LCQI, RTCQI, PT, equipment service and calibration)?'],
                ['38','has_lab_twg','Does the county have a Laboratory Technical Working Group (TWG) coordinating lab activities?'],
                ['39','has_lmis_integration_guide','Does the County have an LMIS standardized system integration guide (e.g. FHIR) in place for hospital wide HIS integration?'],
                ['40','poct_rtcqi_implementing','Are POCT sites implementing Rapid HIV Continuous Quality Improvement (RTCQI)?'],
            ];
            foreach ($lab_questions as $q): ?>
            <div class="form-group">
                <label><?= $q[0] ?>. <?= $q[2] ?></label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s6_<?= $q[1] ?>" value="Yes" <?= chk($q[1],'Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s6_<?= $q[1] ?>" value="No" <?= chk($q[1],'No',$e_data) ?>> No</label>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="form-group">
                <label>41. If Yes above, who is supporting the quarterly assessments using the SPI checklist?</label>
                <select name="s6_rtcqi_support_source" class="form-select">
                    <option value="">Select Support Source</option>
                    <?php foreach(['Partner','County','GOK'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= sel('rtcqi_support_source',$opt,$e_data) ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s6')"><i class="fas fa-save"></i> Save Section 6</button>
    </div>
</div>

<!-- SECTION 7: TB PREVENTION SERVICES -->
<div class="form-section" id="sec_s7">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-lungs"></i> Section 7: TB Prevention Services</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s7',$sections_saved)?'show':'' ?>" id="badge_s7"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>42. Does the County have TB diagnostic TWG?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s7_has_tb_diagnostic_twg" value="Yes" <?= chk('has_tb_diagnostic_twg','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s7_has_tb_diagnostic_twg" value="No" <?= chk('has_tb_diagnostic_twg','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group"><label>43. Number of TB diagnostic TWG activities supported by the County</label><input type="number" name="s7_tb_twg_activities_count" class="form-control" value="<?= v('tb_twg_activities_count',$e_data) ?>"></div>
            <div class="form-group"><label>44. Number of HCW reached with County led TB diagnostics capacity building activities</label><input type="number" name="s7_hcw_reached_tb_training" class="form-control" value="<?= v('hcw_reached_tb_training',$e_data) ?>"></div>
            <div class="form-group">
                <label>45. Does the County have chest X-ray services fully integrated into their annual work plans?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s7_chest_xray_in_awp" value="Yes" <?= chk('chest_xray_in_awp','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s7_chest_xray_in_awp" value="No" <?= chk('chest_xray_in_awp','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group"><label>46. Number of chest X-ray machines with valid KNRA operating licenses</label><input type="number" name="s7_cxr_machines_licensed" class="form-control" value="<?= v('cxr_machines_licensed',$e_data) ?>"></div>
            <div class="form-group"><label>47. Number of functional chest X-ray machines</label><input type="number" name="s7_cxr_machines_functional" class="form-control" value="<?= v('cxr_machines_functional',$e_data) ?>"></div>
            <div class="form-group"><label>48. Number of functional chest X-ray machines enabled with AI for TB screening</label><input type="number" name="s7_cxr_machines_ai_enabled" class="form-control" value="<?= v('cxr_machines_ai_enabled',$e_data) ?>"></div>
            <div class="form-group"><label>49. Number of health facilities using chest x-ray for TB screening</label><input type="number" name="s7_facilities_using_cxr_tb" class="form-control" value="<?= v('facilities_using_cxr_tb',$e_data) ?>"></div>
            <div class="form-group">
                <label>50. Does the County support routine QA/QC for chest X-Ray images by radiologists?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s7_cxr_qa_qc_supported" value="Yes" <?= chk('cxr_qa_qc_supported','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s7_cxr_qa_qc_supported" value="No" <?= chk('cxr_qa_qc_supported','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s7')"><i class="fas fa-save"></i> Save Section 7</button>
    </div>
</div>

<!-- SECTION 8: TECHNICAL WORKING GROUPS -->
<div class="form-section" id="sec_s8">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-users-cog"></i> Section 8: County Based Technical Working Groups</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s8',$sections_saved)?'show':'' ?>" id="badge_s8"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>51. Does the County have a HIV Care and Treatment/TB TWG?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s8_has_hiv_tb_twg" value="Yes" <?= chk('has_hiv_tb_twg','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s8_has_hiv_tb_twg" value="No" <?= chk('has_hiv_tb_twg','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group"><label>52. Number of C&T/TB TWG meetings conducted (Jan 26 - Mar 26)</label><input type="number" name="s8_hiv_tb_twg_meetings" class="form-control" value="<?= v('hiv_tb_twg_meetings',$e_data) ?>"></div>
            <div class="form-group">
                <label>53. Does the County have a PMTCT TWG?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s8_has_pmtct_twg" value="Yes" <?= chk('has_pmtct_twg','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s8_has_pmtct_twg" value="No" <?= chk('has_pmtct_twg','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group"><label>54. Number of PMTCT TWG meetings conducted (Jan 26 - Mar 26)</label><input type="number" name="s8_pmtct_twg_meetings" class="form-control" value="<?= v('pmtct_twg_meetings',$e_data) ?>"></div>
            <div class="form-group">
                <label>55. Does the County have a MNCH TWG?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s8_has_mnch_twg" value="Yes" <?= chk('has_mnch_twg','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s8_has_mnch_twg" value="No" <?= chk('has_mnch_twg','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group"><label>56. Number of MNCH TWG meetings conducted (Jan 26 - Mar 26)</label><input type="number" name="s8_mnch_twg_meetings" class="form-control" value="<?= v('mnch_twg_meetings',$e_data) ?>"></div>
            <div class="form-group">
                <label>57. Does the County have a HIV Prevention TWG?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s8_has_hiv_prevention_twg" value="Yes" <?= chk('has_hiv_prevention_twg','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s8_has_hiv_prevention_twg" value="No" <?= chk('has_hiv_prevention_twg','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group"><label>58. Number of HIV Prevention TWG meetings conducted (Jan 26 - Mar 26)</label><input type="number" name="s8_hiv_prevention_twg_meetings" class="form-control" value="<?= v('hiv_prevention_twg_meetings',$e_data) ?>"></div>
            <div class="form-group">
                <label>59. Is there a functional County integration oversight team and/or County HIV transition team?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s8_has_integration_oversight_team" value="Yes" <?= chk('has_integration_oversight_team','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s8_has_integration_oversight_team" value="No" <?= chk('has_integration_oversight_team','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>60. In the last 3 months, has the integration oversight team held a meeting?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s8_integration_oversight_meeting" value="Yes" <?= chk('integration_oversight_meeting','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s8_integration_oversight_meeting" value="No" <?= chk('integration_oversight_meeting','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s8')"><i class="fas fa-save"></i> Save Section 8</button>
    </div>
</div>

<!-- SECTION 9: FINANCING AND SUSTAINABILITY -->
<div class="form-section" id="sec_s9">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-coins"></i> Section 9: Financing and Sustainability</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s9',$sections_saved)?'show':'' ?>" id="badge_s9"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>61. Does the County have FIF collection plan that has incorporated HIV Prevention, HIV/TB, PMTCT and MNCH services?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s9_has_fif_collection_plan" value="Yes" <?= chk('has_fif_collection_plan','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s9_has_fif_collection_plan" value="No" <?= chk('has_fif_collection_plan','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>62. Does the County receive SHA capitation for HIV Prevention, HIV/TB, PMTCT and MNCH services?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s9_receives_sha_capitation" value="Yes" <?= chk('receives_sha_capitation','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s9_receives_sha_capitation" value="No" <?= chk('receives_sha_capitation','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s9')"><i class="fas fa-save"></i> Save Section 9</button>
    </div>
</div>

<!-- SECTION 10: STAKEHOLDER ENGAGEMENT -->
<div class="form-section" id="sec_s10">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-handshake"></i> Section 10: Stakeholder Engagement</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s10',$sections_saved)?'show':'' ?>" id="badge_s10"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>63. Does the County have a stakeholder engagement plan for HIV Prevention, HIV/TB, PMTCT and MNCH service integration?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s10_has_stakeholder_engagement_plan" value="Yes" <?= chk('has_stakeholder_engagement_plan','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s10_has_stakeholder_engagement_plan" value="No" <?= chk('has_stakeholder_engagement_plan','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>64. Number of multi-stakeholder engagement transition review meetings conducted in the last 3 months</label>
                <input type="number" name="s10_stakeholder_meetings_count" class="form-control" value="<?= v('stakeholder_meetings_count',$e_data) ?>">
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s10')"><i class="fas fa-save"></i> Save Section 10</button>
    </div>
</div>

<!-- SECTION 11: MORTALITY OUTCOMES -->
<div class="form-section" id="sec_s11">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-heartbeat"></i> Section 11: Mortality Outcomes</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s11',$sections_saved)?'show':'' ?>" id="badge_s11"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-group">
            <label>65. Number of days free of maternal deaths in the quarter (Jan 26 - Mar 26)</label>
            <div class="hint">Indicate the number of days that maternal deaths were not reported. Should be less than or equal to 92 days.</div>
            <input type="number" name="s11_days_without_maternal_deaths" class="form-control" min="0" max="92" value="<?= v('days_without_maternal_deaths',$e_data) ?>">
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s11')"><i class="fas fa-save"></i> Save Section 11</button>
    </div>
</div>

<!-- SECTION 12: AHD -->
<div class="form-section" id="sec_s12">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-microscope"></i> Section 12: Advanced HIV Disease (AHD)</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s12',$sections_saved)?'show':'' ?>" id="badge_s12"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group"><label>66. Number of AHD hubs available in the County</label><input type="number" name="s12_ahd_hubs_available" class="form-control" value="<?= v('ahd_hubs_available',$e_data) ?>"></div>
            <div class="form-group"><label>67. Number of AHD hubs activated to provide care for AHD</label><input type="number" name="s12_ahd_hubs_activated" class="form-control" value="<?= v('ahd_hubs_activated',$e_data) ?>"></div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s12')"><i class="fas fa-save"></i> Save Section 12</button>
    </div>
</div>

<!-- SECTION 13: GOVERNANCE -->
<div class="form-section" id="sec_s13">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-gavel"></i> Section 13: Governance</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s13',$sections_saved)?'show':'' ?>" id="badge_s13"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>68. Does the County have an HIV service integration oversight team?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s13_has_hiv_integration_oversight" value="Yes" <?= chk('has_hiv_integration_oversight','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s13_has_hiv_integration_oversight" value="No" <?= chk('has_hiv_integration_oversight','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>69. In the last 3 months, has the HIV service integration oversight team held a meeting?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s13_integration_oversight_meeting_held" value="Yes" <?= chk('integration_oversight_meeting_held','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s13_integration_oversight_meeting_held" value="No" <?= chk('integration_oversight_meeting_held','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s13')"><i class="fas fa-save"></i> Save Section 13</button>
    </div>
</div>

<!-- SECTION 14: SUPPLY CHAIN -->
<div class="form-section" id="sec_s14">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-boxes"></i> Section 14: Supply Chain</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s14',$sections_saved)?'show':'' ?>" id="badge_s14"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>70. Does the county have the Health Products and Technologies Unit (HPTU)?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s14_has_hpt_unit" value="Yes" <?= chk('has_hpt_unit','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s14_has_hpt_unit" value="No" <?= chk('has_hpt_unit','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>71. In the last 3 months, has the county HPT TWG held a meeting?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s14_hpt_twg_meeting_held" value="Yes" <?= chk('hpt_twg_meeting_held','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s14_hpt_twg_meeting_held" value="No" <?= chk('hpt_twg_meeting_held','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>72. Does the county have a valid Forecasting and Quantification report for HPTs?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s14_has_valid_fq_report" value="Yes" <?= chk('has_valid_fq_report','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s14_has_valid_fq_report" value="No" <?= chk('has_valid_fq_report','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>73. Does the county organize regular training for supply chain staff in forecasting, quantification, and inventory management?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s14_provides_supply_chain_training" value="Yes" <?= chk('provides_supply_chain_training','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s14_provides_supply_chain_training" value="No" <?= chk('provides_supply_chain_training','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s14')"><i class="fas fa-save"></i> Save Section 14</button>
    </div>
</div>

<!-- SECTION 15: PRIMARY HEALTH CARE -->
<div class="form-section" id="sec_s15">
    <div class="section-head">
        <div class="section-head-left"><i class="fas fa-clinic-medical"></i> Section 15: Primary Health Care</div>
        <div class="section-head-right">
            <span class="saved-badge <?= in_array('s15',$sections_saved)?'show':'' ?>" id="badge_s15"><i class="fas fa-check"></i> Saved</span>
        </div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>74. Has the county incorporated HIV/TB services into its Primary Health Care (PHC) plans/budgets?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s15_hiv_tb_in_phc_plans" value="Yes" <?= chk('hiv_tb_in_phc_plans','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s15_hiv_tb_in_phc_plans" value="No" <?= chk('hiv_tb_in_phc_plans','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>75. In the last 3 months, has the County held meetings to review implementation of HIV/TB service incorporation in its PHC plans?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s15_phc_hiv_review_meeting" value="Yes" <?= chk('phc_hiv_review_meeting','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s15_phc_hiv_review_meeting" value="No" <?= chk('phc_hiv_review_meeting','No',$e_data) ?>> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>76. Has the county operationalized PHC-based service delivery models (e.g., integrated OPD care, chronic care models) for HIV/TB services?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="s15_phc_service_delivery_operationalized" value="Yes" <?= chk('phc_service_delivery_operationalized','Yes',$e_data) ?>> Yes</label>
                    <label class="yn-opt"><input type="radio" name="s15_phc_service_delivery_operationalized" value="No" <?= chk('phc_service_delivery_operationalized','No',$e_data) ?>> No</label>
                </div>
            </div>
        </div>
        <button type="button" class="btn-save-section" onclick="saveSection('s15')"><i class="fas fa-save"></i> Save Section 15</button>
    </div>
</div>

<!-- Data Collection Details -->
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
                <input type="date" id="collection_date_input" class="form-control" value="<?= v('collection_date',$e_data) ?: date('Y-m-d') ?>">
            </div>
        </div>
    </div>
</div>

<!-- Submit Zone -->
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
<?php else: ?>
<div style="text-align:center;padding:60px 20px;color:var(--muted)">
    <i class="fas fa-landmark" style="font-size:56px;margin-bottom:16px;display:block;opacity:.3"></i>
    <p style="font-size:16px;font-weight:600">Select a County and Assessment Period above, then click <strong>Load or Start</strong></p>
</div>
<?php endif; ?>
</div><!-- /wrap -->

<!-- Duplicate Modal -->
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
            <a id="dupEditLink" href="#" class="btn-navy"><i class="fas fa-edit"></i> Open and Continue</a>
            <a href="county_integration_assessment_list.php" class="btn-navy" style="background:var(--teal)"><i class="fas fa-list"></i> View All</a>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
    <i class="fas fa-check-circle toast-icon"></i>
    <span id="toastMsg">Saved successfully</span>
</div>

<script>
// State
let assessmentId   = <?= $edit_id ?: 0 ?>;
let sectionsSaved  = <?= json_encode($sections_saved) ?>;
const allSections  = <?= json_encode(array_keys($all_section_defs)) ?>;

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    if (!t) return;
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'toast ' + type + ' show';
    t.querySelector('.toast-icon').className = 'fas ' + (type==='success'?'fa-check-circle':'fa-exclamation-triangle') + ' toast-icon';
    setTimeout(() => t.classList.remove('show'), 3200);
}

function updateProgress() {
    const n = sectionsSaved.length;
    const total = allSections.length;
    const pct = Math.round(n/total*100);

    const pctEl = document.getElementById('progressPct');
    const barEl = document.getElementById('progressBar');
    if (pctEl) pctEl.textContent = pct + '%';
    if (barEl) barEl.style.width = pct + '%';

    allSections.forEach(sk => {
        const item = document.querySelector(`.sec-nav-item[data-section="${sk}"]`);
        if (!item) return;
        const saved = sectionsSaved.includes(sk);
        item.className = 'sec-nav-item ' + (saved?'saved':'unsaved');
        const icon = item.querySelector('.sec-icon i');
        if (icon) icon.className = 'fas ' + (saved?'fa-check-circle':'fa-circle');
    });

    const btn = document.getElementById('btnFinalSubmit');
    const txt = document.getElementById('submitProgressText');
    if (btn && txt) {
        if (n >= total) {
            btn.disabled = false;
            txt.innerHTML = '<i class="fas fa-check-circle" style="color:var(--green)"></i> All sections saved � ready to submit!';
        } else {
            btn.disabled = true;
            txt.innerHTML = '<i class="fas fa-info-circle"></i> ' + n + ' of ' + total + ' sections saved � complete all to enable submission';
        }
    }
}

function scrollToSection(sk) {
    const el = document.getElementById('sec_' + sk);
    if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
}

// FIXED: Corrected typo from 'aasync' to 'async'
async function loadAssessment() {
    const cid = document.getElementById('countySelect').value;
    const cname = document.getElementById('countySelect').options[document.getElementById('countySelect').selectedIndex].getAttribute('data-name');
    const period = document.getElementById('periodSelect').value;

    if (!cid || !period) {
        alert('Please select both a County and an Assessment Period.');
        return;
    }

    const btn = document.getElementById('btnLoad');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

    try {
        const response = await fetch(`county_integration_assessment.php?ajax=check_assessment&county_id=${cid}&period=${encodeURIComponent(period)}`);
        const data = await response.json();

        // Bind selection to hidden fields
        document.getElementById('h_county_id').value = cid;
        document.getElementById('h_period').value = period;

        if (data.exists) {
            // Existing record: Load by ID
            window.location.href = `county_integration_assessment.php?id=${data.assessment_id}`;
        } else {
            // No record: Proceed by passing selection to the URL to trigger the form
            const params = new URLSearchParams();
            params.set('county_id', cid);
            params.set('period', period);
            params.set('county_name', cname);
            window.location.href = `county_integration_assessment.php?${params.toString()}`;
        }
    } catch (e) {
        console.error(e);
        alert('An error occurred while checking for existing assessment.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Load or Start';
    }
}

function closeDupModal() {
    const modal = document.getElementById('dupModal');
    if (modal) modal.classList.remove('show');
}

const modal = document.getElementById('dupModal');
if (modal) {
    modal.addEventListener('click', function(e) {
        if(e.target === this) closeDupModal();
    });
}

async function saveSection(sectionKey) {
    const cid = document.getElementById('h_county_id')?.value;
    const period = document.getElementById('h_period')?.value;

    if (!cid) { showToast('Please select a county first', 'error'); return; }
    if (!period) { showToast('Please select an assessment period', 'error'); return; }

    const btn = document.querySelector('#sec_' + sectionKey + ' .btn-save-section');
    if (!btn) return;

    const origTxt = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.classList.add('saving');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('ajax_save_section', '1');
    fd.append('section_key', sectionKey);
    fd.append('county_id', cid);
    fd.append('assessment_period', period);
    fd.append('assessment_id', assessmentId);

    const collectionDate = document.getElementById('collection_date_input');
    if (collectionDate) fd.append('collection_date', collectionDate.value);

    // Section 1 data
    const countySelect = document.getElementById('s1_county_id');
    const agencySelect = document.getElementById('s1_agency_id');
    const ipSelect = document.getElementById('s1_ip_id');

    if (countySelect) {
        const selectedOption = countySelect.options[countySelect.selectedIndex];
        fd.append('county_name', selectedOption?.getAttribute('data-name') || '');
    }
    if (agencySelect) {
        fd.append('agency_id', agencySelect.value || 0);
        const selectedOption = agencySelect.options[agencySelect.selectedIndex];
        fd.append('agency_name', selectedOption?.getAttribute('data-name') || '');
    }
    if (ipSelect) {
        fd.append('ip_id', ipSelect.value || 0);
        const selectedOption = ipSelect.options[ipSelect.selectedIndex];
        fd.append('ip_name', selectedOption?.getAttribute('data-name') || '');
    }

    const sec = document.getElementById('sec_' + sectionKey);
    const PREFIX_MAP = {
        s1:'s1_', s2a:'s2a_', s2b:'s2b_', s3:'s3_', s4:'s4_', s5:'s5_',
        s6:'s6_', s7:'s7_', s8:'s8_', s9:'s9_', s10:'s10_', s11:'s11_',
        s12:'s12_', s13:'s13_', s14:'s14_', s15:'s15_'
    };
    const prefix = PREFIX_MAP[sectionKey] || '';

    if (sec) {
        const inputs = sec.querySelectorAll('input,select,textarea');
        for (let i = 0; i < inputs.length; i++) {
            const el = inputs[i];
            if (!el.name) continue;
            if (el.type === 'radio' && !el.checked) continue;
            if (el.type === 'checkbox') {
                if (el.checked) fd.append(el.name.replace(prefix,''), el.value);
                continue;
            }
            const serverName = el.name.startsWith(prefix) ? el.name.replace(prefix,'') : el.name;
            fd.append(serverName, el.value);
        }
    }

    try {
        const data = await fetch('county_integration_assessment.php', {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            assessmentId = data.assessment_id;
            const hAssessmentId = document.getElementById('h_assessment_id');
            if (hAssessmentId) hAssessmentId.value = assessmentId;
            sectionsSaved = data.sections_saved;
            const badge = document.getElementById('badge_' + sectionKey);
            if (badge) badge.classList.add('show');
            updateProgress();
            showToast('Section saved successfully!', 'success');
        } else {
            showToast(data.error || 'Save failed', 'error');
        }
    } catch(e) {
        showToast('Error saving section � please try again', 'error');
        console.error(e);
    }

    btn.innerHTML = origTxt;
    btn.classList.remove('saving');
    btn.disabled = false;
}

async function finalSubmit() {
    if (!assessmentId) { showToast('No assessment to submit', 'error'); return; }
    if (!confirm('Submit this county assessment as final? It will be marked as Submitted.')) return;

    const fd = new FormData();
    fd.append('ajax_submit', '1');
    fd.append('assessment_id', assessmentId);

    try {
        const data = await fetch('county_integration_assessment.php', {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            showToast('Assessment submitted successfully! Redirecting...', 'success');
            setTimeout(() => window.location.href = data.redirect, 1500);
        } else {
            showToast(data.error || 'Submission failed', 'error');
        }
    } catch(e) {
        showToast('Network error � please try again', 'error');
    }
}

// Initialize progress if form is visible
if (document.getElementById('mainForm')) {
    updateProgress();
}

// Keyboard shortcut
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const unsaved = allSections.find(sk => !sectionsSaved.includes(sk));
        if (unsaved) saveSection(unsaved);
    }
});
</script>
</body>
</html>