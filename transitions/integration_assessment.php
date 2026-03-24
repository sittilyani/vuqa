<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// -- AJAX: facility live search ------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_facility') {
    $q = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT facility_id, facility_name, mflcode, county_name, subcounty_name,
                    owner, sdp, agency, emr, emrstatus, infrastructuretype,
                    latitude, longitude, level_of_care_name
             FROM facilities
             WHERE (facility_name LIKE '%$q%' OR mflcode LIKE '%$q%'
                    OR county_name LIKE '%$q%')
             ORDER BY facility_name LIMIT 20");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// -- Handle POST submission ----------------------------------------------------
$msg = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {

    $fid  = (int)($_POST['facility_id'] ?? 0);
    if (!$fid) { $error = 'Please select a facility before submitting.'; }
    else {
        $e = function($v) use ($conn) { return mysqli_real_escape_string($conn, trim($v ?? '')); };
        $i = function($v) { return is_numeric($v) ? (int)$v : 'NULL'; };

        // Build no_emr_reasons from checkboxes
        $emr_reasons = isset($_POST['no_emr_reasons']) && is_array($_POST['no_emr_reasons'])
            ? $e(implode(',', $_POST['no_emr_reasons'])) : '';

        $sql = "INSERT INTO integration_assessments (
            facility_id, assessment_period,
            facility_name, mflcode, county_name, subcounty_name, owner, sdp, agency,
            emr, emrstatus, infrastructuretype, latitude, longitude, level_of_care_name,
            supported_by_usdos_ip, is_art_site,
            hiv_tb_integrated, hiv_tb_integration_model,
            tx_curr, tx_curr_pmtct, plhiv_integrated_care,
            pmtct_integrated_mnch, hts_integrated_opd, hts_integrated_ipd, hts_integrated_mnch,
            prep_integrated_opd, prep_integrated_ipd, prep_integrated_mnch,
            uses_emr, no_emr_reasons, single_unified_emr,
            emr_at_opd, emr_opd_other, emr_at_ipd, emr_ipd_other,
            emr_at_mnch, emr_mnch_other, emr_at_ccc, emr_ccc_other,
            emr_at_pmtct, emr_pmtct_other, emr_at_lab, emr_lab_other,
            lab_manifest_in_use, tibu_lite_lims_in_use, emr_at_pharmacy, emr_pharmacy_other,
            pharmacy_webadt_in_use, emr_interoperable_his,
            hcw_total_pepfar, hcw_clinical_pepfar, hcw_nonclinical_pepfar,
            hcw_data_pepfar, hcw_community_pepfar, hcw_other_pepfar,
            hcw_transitioned_clinical, hcw_transitioned_nonclinical,
            hcw_transitioned_data, hcw_transitioned_community, hcw_transitioned_other,
            plhiv_enrolled_sha, plhiv_sha_premium_paid, pbfw_enrolled_sha,
            pbfw_sha_premium_paid, sha_claims_submitted_ontime, sha_reimbursements_monthly,
            ta_visits_total, ta_visits_moh_only, fif_collection_in_place,
            fif_includes_hiv_tb_pmtct, sha_capitation_hiv_tb,
            deaths_all_cause, deaths_hiv_related, deaths_hiv_pre_art,
            deaths_tb, deaths_maternal, deaths_perinatal,
            leadership_commitment, transition_plan, hiv_in_awp, hrh_gap,
            staff_multiskilled, roving_staff, infrastructure_capacity, space_adequacy,
            service_delivery_without_ccc, avg_wait_time, data_integration_level,
            financing_coverage, disruption_risk, integration_barriers,
            collected_by, collection_date
        ) VALUES (
            $fid, '{$e($_POST['assessment_period'])}',
            '{$e($_POST['facility_name'])}', '{$e($_POST['mflcode'])}',
            '{$e($_POST['county_name'])}', '{$e($_POST['subcounty_name'])}',
            '{$e($_POST['owner'])}', '{$e($_POST['sdp'])}', '{$e($_POST['agency'])}',
            '{$e($_POST['emr'])}', '{$e($_POST['emrstatus'])}', '{$e($_POST['infrastructuretype'])}',
            " . (is_numeric($_POST['latitude'] ?? '') ? (float)$_POST['latitude'] : 'NULL') . ",
            " . (is_numeric($_POST['longitude'] ?? '') ? (float)$_POST['longitude'] : 'NULL') . ",
            '{$e($_POST['level_of_care_name'])}',
            '{$e($_POST['supported_by_usdos_ip'] ?? '')}', '{$e($_POST['is_art_site'] ?? '')}',
            '{$e($_POST['hiv_tb_integrated'] ?? '')}', '{$e($_POST['hiv_tb_integration_model'] ?? '')}',
            {$i($_POST['tx_curr'] ?? '')}, {$i($_POST['tx_curr_pmtct'] ?? '')}, {$i($_POST['plhiv_integrated_care'] ?? '')},
            '{$e($_POST['pmtct_integrated_mnch'] ?? '')}', '{$e($_POST['hts_integrated_opd'] ?? '')}',
            '{$e($_POST['hts_integrated_ipd'] ?? '')}', '{$e($_POST['hts_integrated_mnch'] ?? '')}',
            '{$e($_POST['prep_integrated_opd'] ?? '')}', '{$e($_POST['prep_integrated_ipd'] ?? '')}',
            '{$e($_POST['prep_integrated_mnch'] ?? '')}',
            '{$e($_POST['uses_emr'] ?? '')}', '$emr_reasons', '{$e($_POST['single_unified_emr'] ?? '')}',
            '{$e($_POST['emr_at_opd'] ?? '')}', '{$e($_POST['emr_opd_other'] ?? '')}',
            '{$e($_POST['emr_at_ipd'] ?? '')}', '{$e($_POST['emr_ipd_other'] ?? '')}',
            '{$e($_POST['emr_at_mnch'] ?? '')}', '{$e($_POST['emr_mnch_other'] ?? '')}',
            '{$e($_POST['emr_at_ccc'] ?? '')}', '{$e($_POST['emr_ccc_other'] ?? '')}',
            '{$e($_POST['emr_at_pmtct'] ?? '')}', '{$e($_POST['emr_pmtct_other'] ?? '')}',
            '{$e($_POST['emr_at_lab'] ?? '')}', '{$e($_POST['emr_lab_other'] ?? '')}',
            '{$e($_POST['lab_manifest_in_use'] ?? '')}', '{$e($_POST['tibu_lite_lims_in_use'] ?? '')}',
            '{$e($_POST['emr_at_pharmacy'] ?? '')}', '{$e($_POST['emr_pharmacy_other'] ?? '')}',
            '{$e($_POST['pharmacy_webadt_in_use'] ?? '')}', '{$e($_POST['emr_interoperable_his'] ?? '')}',
            {$i($_POST['hcw_total_pepfar'] ?? '')}, {$i($_POST['hcw_clinical_pepfar'] ?? '')},
            {$i($_POST['hcw_nonclinical_pepfar'] ?? '')}, {$i($_POST['hcw_data_pepfar'] ?? '')},
            {$i($_POST['hcw_community_pepfar'] ?? '')}, {$i($_POST['hcw_other_pepfar'] ?? '')},
            {$i($_POST['hcw_transitioned_clinical'] ?? '')}, {$i($_POST['hcw_transitioned_nonclinical'] ?? '')},
            {$i($_POST['hcw_transitioned_data'] ?? '')}, {$i($_POST['hcw_transitioned_community'] ?? '')},
            {$i($_POST['hcw_transitioned_other'] ?? '')},
            {$i($_POST['plhiv_enrolled_sha'] ?? '')}, {$i($_POST['plhiv_sha_premium_paid'] ?? '')},
            {$i($_POST['pbfw_enrolled_sha'] ?? '')}, {$i($_POST['pbfw_sha_premium_paid'] ?? '')},
            '{$e($_POST['sha_claims_submitted_ontime'] ?? '')}', '{$e($_POST['sha_reimbursements_monthly'] ?? '')}',
            {$i($_POST['ta_visits_total'] ?? '')}, {$i($_POST['ta_visits_moh_only'] ?? '')},
            '{$e($_POST['fif_collection_in_place'] ?? '')}',
            '{$e($_POST['fif_includes_hiv_tb_pmtct'] ?? '')}', '{$e($_POST['sha_capitation_hiv_tb'] ?? '')}',
            {$i($_POST['deaths_all_cause'] ?? '')}, {$i($_POST['deaths_hiv_related'] ?? '')},
            {$i($_POST['deaths_hiv_pre_art'] ?? '')}, {$i($_POST['deaths_tb'] ?? '')},
            {$i($_POST['deaths_maternal'] ?? '')}, {$i($_POST['deaths_perinatal'] ?? '')},
            '{$e($_POST['leadership_commitment'] ?? '')}', '{$e($_POST['transition_plan'] ?? '')}',
            '{$e($_POST['hiv_in_awp'] ?? '')}', '{$e($_POST['hrh_gap'] ?? '')}',
            '{$e($_POST['staff_multiskilled'] ?? '')}', '{$e($_POST['roving_staff'] ?? '')}',
            '{$e($_POST['infrastructure_capacity'] ?? '')}', '{$e($_POST['space_adequacy'] ?? '')}',
            '{$e($_POST['service_delivery_without_ccc'] ?? '')}', '{$e($_POST['avg_wait_time'] ?? '')}',
            '{$e($_POST['data_integration_level'] ?? '')}', '{$e($_POST['financing_coverage'] ?? '')}',
            '{$e($_POST['disruption_risk'] ?? '')}', '{$e($_POST['integration_barriers'] ?? '')}',
            '{$e($_SESSION['full_name'] ?? '')}', '{$e($_POST['collection_date'] ?? date('Y-m-d'))}'
        )";

        if (mysqli_query($conn, $sql)) {
            $new_id = mysqli_insert_id($conn);
            // Save repeating EMR systems
            if (!empty($_POST['emr_type']) && is_array($_POST['emr_type'])) {
                foreach ($_POST['emr_type'] as $k => $et) {
                    if (empty(trim($et))) continue;
                    $et = $e($et);
                    $fb = $e($_POST['emr_funded_by'][$k] ?? '');
                    $ds = $e($_POST['emr_date_started'][$k] ?? '');
                    $ds_val = !empty($ds) ? "'$ds'" : 'NULL';
                    mysqli_query($conn, "INSERT INTO integration_assessment_emr_systems
                        (assessment_id, facility_id, emr_type, funded_by, date_started, sort_order)
                        VALUES ($new_id, $fid, '$et', '$fb', $ds_val, " . ($k + 1) . ")");
                }
            }
            $_SESSION['success_msg'] = 'Integration assessment submitted successfully!';
            header('Location: integration_dashboard.php');
            exit();
        } else {
            $error = 'Error saving: ' . mysqli_error($conn);
        }
    }
}

// Administered by
$collected_by = $_SESSION['full_name'] ?? '';
$uid = intval($_SESSION['user_id']);
$ur = mysqli_query($conn, "SELECT full_name FROM tblusers WHERE user_id = $uid");
if ($ur && mysqli_num_rows($ur) > 0) $collected_by = mysqli_fetch_assoc($ur)['full_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Integration Assessment</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f7;color:#333;line-height:1.6;}
.container{max-width:1100px;margin:0 auto;padding:20px;}

.page-header{background:linear-gradient(135deg,#0D1A63 0%,#1a3a9e 100%);color:#fff;padding:22px 30px;border-radius:14px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(13,26,99,.25);}
.page-header h1{font-size:1.4rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.page-header .hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;border-radius:8px;font-size:13px;margin-left:8px;transition:background .2s;}
.page-header .hdr-links a:hover{background:rgba(255,255,255,.28);}

.alert{padding:13px 18px;border-radius:9px;margin-bottom:18px;font-size:14px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}

/* Section cards */
.form-section{background:#fff;border-radius:12px;margin-bottom:22px;box-shadow:0 2px 14px rgba(0,0,0,.07);overflow:hidden;border-left:4px solid #0D1A63;}
.section-head{background:linear-gradient(90deg,#0D1A63,#1a3a9e);color:#fff;padding:12px 22px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:10px;}
.section-body{padding:22px;}

/* Facility search */
.search-wrap{position:relative;margin-bottom:16px;}
.search-wrap input{width:100%;padding:12px 44px 12px 16px;border:2px solid #e0e0e0;border-radius:9px;font-size:14px;transition:border-color .25s;font-family:inherit;}
.search-wrap input:focus{outline:none;border-color:#0D1A63;box-shadow:0 0 0 3px rgba(13,26,99,.1);}
.s-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#aaa;font-size:15px;pointer-events:none;}
.s-spinner{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#0D1A63;font-size:14px;display:none;}
.results-dropdown{position:absolute;z-index:999;width:100%;background:#fff;border:1.5px solid #dce3f5;border-radius:10px;margin-top:4px;box-shadow:0 8px 28px rgba(13,26,99,.15);max-height:300px;overflow-y:auto;display:none;}
.result-item{padding:11px 15px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:background .15s;}
.result-item:last-child{border-bottom:none;}
.result-item:hover{background:#f0f3fb;}
.ri-name{font-weight:700;color:#0D1A63;font-size:13.5px;}
.ri-meta{font-size:11.5px;color:#777;margin-top:2px;}
.ri-badge{display:inline-block;font-size:10px;background:#e8edf8;color:#0D1A63;border-radius:4px;padding:1px 6px;margin-left:6px;font-weight:600;}
.no-results{padding:14px;color:#999;font-size:13px;text-align:center;}

/* Facility card */
.facility-card{border:2px solid #0D1A63;border-radius:10px;padding:16px 18px;background:linear-gradient(135deg,#f0f3fb,#fff);margin-top:8px;display:none;}
.facility-card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.facility-card-name{font-weight:700;color:#0D1A63;font-size:15px;}
.facility-card-clear{color:#dc3545;cursor:pointer;font-size:13px;background:none;border:none;}
.fac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;}
.fg{} .fg label{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#999;font-weight:600;display:block;margin-bottom:2px;}
.fg span{font-size:13px;color:#222;font-weight:500;}

/* Form elements */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;}
.form-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;}
.form-group{margin-bottom:16px;}
.form-group.full{grid-column:1/-1;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;color:#444;font-size:13.5px;}
.form-group label small{font-weight:400;color:#888;}
.req{color:#dc3545;font-style:normal;}
.form-control,.form-select{width:100%;padding:10px 13px;border:1.5px solid #ddd;border-radius:7px;font-size:13.5px;transition:border-color .2s;background:#fff;font-family:inherit;}
.form-control:focus,.form-select:focus{outline:none;border-color:#0D1A63;box-shadow:0 0 0 3px rgba(13,26,99,.1);}
.form-control[readonly]{background:#f8f9fc;color:#666;cursor:default;}
textarea.form-control{min-height:80px;resize:vertical;}
input[type=number].form-control{-moz-appearance:textfield;}

/* Radio / checkbox */
.yn-group{display:flex;gap:20px;margin-top:6px;}
.yn-opt{display:flex;align-items:center;gap:7px;font-size:13.5px;cursor:pointer;}
.yn-opt input{width:16px;height:16px;accent-color:#0D1A63;cursor:pointer;}
.cb-group{display:flex;flex-wrap:wrap;gap:14px;margin-top:6px;}
.cb-opt{display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;}
.cb-opt input{width:16px;height:16px;accent-color:#0D1A63;cursor:pointer;flex-shrink:0;}

/* EMR repeater */
.emr-entry{background:#f8f9fc;border:1px solid #e0e4f0;border-radius:9px;padding:14px 16px;margin-bottom:10px;}
.emr-entry-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.emr-num{font-size:11px;font-weight:700;color:#0D1A63;text-transform:uppercase;letter-spacing:.5px;}
.remove-emr{background:#fee2e2;color:#dc2626;border:none;border-radius:5px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;}
.remove-emr:hover{background:#fecaca;}
.add-emr-btn{width:100%;background:#eef1ff;color:#0D1A63;border:2px dashed #0D1A63;border-radius:8px;padding:9px;font-size:13.5px;font-weight:600;cursor:pointer;margin-top:4px;transition:background .2s;}
.add-emr-btn:hover{background:#dde3ff;}

/* Section divider label */
.sub-label{font-size:12px;font-weight:700;color:#0D1A63;text-transform:uppercase;letter-spacing:.8px;margin:18px 0 12px;padding-bottom:6px;border-bottom:1px solid #e8edf8;}

/* Admin box */
.admin-box{background:#f0f4ff;border:1px solid #c5d0f0;border-radius:9px;padding:14px 18px;display:flex;align-items:center;gap:14px;margin-top:8px;}
.admin-icon{width:42px;height:42px;background:#0D1A63;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;flex-shrink:0;}
.admin-name{font-size:15px;font-weight:700;color:#0D1A63;}
.admin-label{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.5px;}

.btn-submit{background:#0D1A63;color:#fff;padding:14px 40px;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;display:block;width:100%;max-width:340px;margin:28px auto;transition:background .2s,transform .15s;}
.btn-submit:hover{background:#1a2a7a;transform:translateY(-1px);}

@media(max-width:768px){.form-grid,.form-grid-3,.fac-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="container">

<div class="page-header">
    <h1><i class="fas fa-clipboard-check"></i> Integration Assessment Tool</h1>
    <div class="hdr-links">
        <a href="integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<form id="iaForm" method="POST">
<input type="hidden" name="submit_assessment" value="1">
<input type="hidden" name="facility_id"   id="h_facility_id">
<input type="hidden" name="mflcode"       id="h_mflcode">
<input type="hidden" name="county_name"   id="h_county_name">
<input type="hidden" name="subcounty_name" id="h_subcounty_name">
<input type="hidden" name="owner"         id="h_owner">
<input type="hidden" name="sdp"           id="h_sdp">
<input type="hidden" name="agency"        id="h_agency">
<input type="hidden" name="emr"           id="h_emr">
<input type="hidden" name="emrstatus"     id="h_emrstatus">
<input type="hidden" name="infrastructuretype" id="h_infra">
<input type="hidden" name="latitude"      id="h_lat">
<input type="hidden" name="longitude"     id="h_lng">
<input type="hidden" name="level_of_care_name" id="h_level">

<!-- ═══ SECTION 1: FACILITY PROFILE ════════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-hospital"></i> Section 1: Facility Profile</div>
    <div class="section-body">

        <div class="form-group">
            <label>Assessment Period <span class="req">*</span></label>
            <select name="assessment_period" class="form-select" required>
                <option value="">-- Select Period --</option>
                <option value="Jan-Mar 2025">Jan–Mar 2025</option>
                <option value="Apr-Jun 2025">Apr–Jun 2025</option>
                <option value="Jul-Sep 2025">Jul–Sep 2025</option>
                <option value="Oct-Dec 2025" selected>Oct–Dec 2025</option>
                <option value="Jan-Mar 2026">Jan–Mar 2026</option>
            </select>
        </div>

        <!-- Facility live search -->
        <div class="form-group">
            <label>Facility <span class="req">*</span></label>
            <div class="search-wrap" id="facSearchWrap">
                <input type="text" id="facilitySearch" name="facility_name"
                       placeholder="Type facility name or MFL code to search..."
                       autocomplete="off" required>
                <i class="fas fa-hospital s-icon" id="facSearchIcon"></i>
                <i class="fas fa-spinner fa-spin s-spinner" id="facSpinner"></i>
                <div class="results-dropdown" id="facResults"></div>
            </div>
        </div>

        <!-- Facility details card (auto-filled) -->
        <div class="facility-card" id="facilityCard">
            <div class="facility-card-header">
                <div class="facility-card-name" id="fc_name"></div>
                <button type="button" class="facility-card-clear" onclick="clearFacility()">
                    <i class="fas fa-times-circle"></i> Change
                </button>
            </div>
            <div class="fac-grid">
                <div class="fg"><label>MFL Code</label><span id="fc_mfl"></span></div>
                <div class="fg"><label>County</label><span id="fc_county"></span></div>
                <div class="fg"><label>Sub-County</label><span id="fc_subcounty"></span></div>
                <div class="fg"><label>Level of Care</label><span id="fc_level"></span></div>
                <div class="fg"><label>Owner</label><span id="fc_owner"></span></div>
                <div class="fg"><label>SDP</label><span id="fc_sdp"></span></div>
                <div class="fg"><label>Agency</label><span id="fc_agency"></span></div>
                <div class="fg"><label>EMR</label><span id="fc_emr"></span></div>
                <div class="fg"><label>EMR Status</label><span id="fc_emrstatus"></span></div>
                <div class="fg"><label>Infrastructure</label><span id="fc_infra"></span></div>
            </div>
        </div>

        <div class="form-grid" style="margin-top:18px">
            <div class="form-group">
                <label>Q7. Is the health facility supported by US DoS IP?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="supported_by_usdos_ip" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="supported_by_usdos_ip" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q8. Is the health facility an ART site?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="is_art_site" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="is_art_site" value="No"> No</label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 2a: HIV/TB Services ════════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-virus"></i> Section 2a: Integration of HIV/TB Services</div>
    <div class="section-body">
        <div class="form-group">
            <label>Q9. Has the health facility integrated HIV/TB services within OPD or Chronic care model?</label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="hiv_tb_integrated" value="Yes"> Yes</label>
                <label class="yn-opt"><input type="radio" name="hiv_tb_integrated" value="No"> No</label>
            </div>
        </div>
        <div class="form-group">
            <label>Q10. If yes, specify the type of integration model</label>
            <input type="text" name="hiv_tb_integration_model" class="form-control" placeholder="e.g. One-stop shop, Differentiated service delivery...">
        </div>
        <div class="form-grid-3">
            <div class="form-group">
                <label>Q11. TX_CURR <small>(last month of reporting)</small></label>
                <input type="number" name="tx_curr" class="form-control" min="0" placeholder="0">
            </div>
            <div class="form-group">
                <label>Q12. TX_CURR PMTCT <small>(last month of reporting)</small></label>
                <input type="number" name="tx_curr_pmtct" class="form-control" min="0" placeholder="0">
            </div>
            <div class="form-group">
                <label>Q13. Total PLHIVs receiving HIV/TB care through integrated models</label>
                <input type="number" name="plhiv_integrated_care" class="form-control" min="0" placeholder="0">
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 2b: PMTCT / HTS / PrEP ════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-baby"></i> Section 2b: Integration — PMTCT, HTS &amp; PrEP</div>
    <div class="section-body">
        <?php
        $q2b = [
            ['Q14', 'pmtct_integrated_mnch',  'Has the facility integrated PMTCT services in MNCH?'],
            ['Q15', 'hts_integrated_opd',      'Has the facility integrated HTS services in OPD?'],
            ['Q16', 'hts_integrated_ipd',      'Has the facility integrated HTS services in IPD?'],
            ['Q17', 'hts_integrated_mnch',     'Has the facility integrated HTS services in MNCH?'],
            ['Q18', 'prep_integrated_opd',     'Has the facility integrated PrEP services in OPD?'],
            ['Q19', 'prep_integrated_ipd',     'Has the facility integrated PrEP services in IPD?'],
            ['Q20', 'prep_integrated_mnch',    'Has the facility integrated PrEP services in MNCH?'],
        ];
        ?>
        <div class="form-grid">
        <?php foreach ($q2b as [$qn, $fn, $ql]): ?>
        <div class="form-group">
            <label><?= $qn ?>. <?= $ql ?></label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes"> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No"> No</label>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══ SECTION 2c: EMR Integration ════════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-laptop-medical"></i> Section 2c: EMR Integration</div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Q21. Does this facility use any EMR system?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="uses_emr" value="Yes" id="uses_emr_yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="uses_emr" value="No"  id="uses_emr_no"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q24. Health facility has a single unified EMR system?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="single_unified_emr" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="single_unified_emr" value="No"> No</label>
                </div>
            </div>
        </div>

        <!-- Q22: EMR systems repeater -->
        <div id="emrYesSection">
            <div class="sub-label"><i class="fas fa-plus-circle"></i> Q22. EMR Systems in Use (add all)</div>
            <div id="emrRepeater">
                <div class="emr-entry" data-n="1">
                    <div class="emr-entry-header">
                        <span class="emr-num">EMR System 1</span>
                    </div>
                    <div class="form-grid-3">
                        <div class="form-group"><label>EMR Type / Name</label>
                            <input type="text" name="emr_type[]" class="form-control" placeholder="e.g. KenyaEMR, OpenMRS">
                        </div>
                        <div class="form-group"><label>Funded By</label>
                            <input type="text" name="emr_funded_by[]" class="form-control" placeholder="e.g. PEPFAR, Government">
                        </div>
                        <div class="form-group"><label>Date Started Use</label>
                            <input type="date" name="emr_date_started[]" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" class="add-emr-btn" onclick="addEMR()">&#43; Add Another EMR System</button>
        </div>

        <!-- Q23: No EMR reasons -->
        <div id="emrNoSection" style="display:none;margin-top:14px">
            <div class="form-group">
                <label>Q23. If No, reasons (select all that apply):</label>
                <div class="cb-group">
                    <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No hardware"> No hardware</label>
                    <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No internet"> No internet</label>
                    <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No electricity"> No electricity</label>
                    <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No trained staff"> No trained staff</label>
                    <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="Other"> Other</label>
                </div>
            </div>
        </div>

        <div class="sub-label" style="margin-top:20px"><i class="fas fa-hospital-alt"></i> EMR by Department</div>
        <?php
        $depts = [
            ['Q25','emr_at_opd',   'emr_opd_other',   'OPD'],
            ['Q27','emr_at_ipd',   'emr_ipd_other',   'IPD'],
            ['Q29','emr_at_mnch',  'emr_mnch_other',  'MNCH'],
            ['Q31','emr_at_ccc',   'emr_ccc_other',   'CCC'],
            ['Q33','emr_at_pmtct', 'emr_pmtct_other', 'PMTCT'],
            ['Q35','emr_at_lab',   'emr_lab_other',   'Lab'],
            ['Q39','emr_at_pharmacy','emr_pharmacy_other','Pharmacy'],
        ];
        ?>
        <div class="form-grid">
        <?php foreach ($depts as [$qn, $yn_name, $other_name, $dept]): ?>
        <div class="form-group">
            <label><?= $qn ?>. EMR system in use at <?= $dept ?>?</label>
            <div class="yn-group">
                <label class="yn-opt"><input type="radio" name="<?= $yn_name ?>" value="Yes"> Yes</label>
                <label class="yn-opt"><input type="radio" name="<?= $yn_name ?>" value="No"> No</label>
            </div>
            <input type="text" name="<?= $other_name ?>" class="form-control" style="margin-top:7px"
                   placeholder="If other, specify EMR name">
        </div>
        <?php endforeach; ?>
        </div>

        <div class="form-grid" style="margin-top:10px">
            <div class="form-group">
                <label>Q37. Lab Manifest in use at the Health Facility?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="lab_manifest_in_use" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="lab_manifest_in_use" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q38. Tibu Lite (LIMS) in use at the Health Facility?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="tibu_lite_lims_in_use" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="tibu_lite_lims_in_use" value="No"> No</label>
                    <label class="yn-opt"><input type="radio" name="tibu_lite_lims_in_use" value="Partial"> Partial</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q41. Pharmacy WebADT in use at the Health Facility?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="pharmacy_webadt_in_use" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="pharmacy_webadt_in_use" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q42. Is the EMR interoperable with other HIS systems? <small>(EID, WebADT, Lab)</small></label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="emr_interoperable_his" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="emr_interoperable_his" value="No"> No</label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 3: HRH Transition ══════════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-users"></i> Section 3: HRH Transition (Workforce Absorption)</div>
    <div class="section-body">
        <div class="sub-label">Total HCWs Supported by PEPFAR IP</div>
        <div class="form-grid-3">
            <?php
            $hrh = [
                ['hcw_total_pepfar',     'Q43. Total HCWs supported by PEPFAR IP'],
                ['hcw_clinical_pepfar',  'Q44. Clinical Staff'],
                ['hcw_nonclinical_pepfar','Q45. Non-Clinical Staff'],
                ['hcw_data_pepfar',      'Q46. Data Staff'],
                ['hcw_community_pepfar', 'Q47. Community-based Staff'],
                ['hcw_other_pepfar',     'Q48. Other'],
            ];
            foreach ($hrh as [$fn, $ql]): ?>
            <div class="form-group">
                <label><?= $ql ?></label>
                <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0">
            </div>
            <?php endforeach; ?>
        </div>

        <div class="sub-label">HCWs Transitioned to County Support (Oct–Dec 2025)</div>
        <div class="form-grid-3">
            <?php
            $trans = [
                ['hcw_transitioned_clinical',   'Q50. Clinical Staff'],
                ['hcw_transitioned_nonclinical','Q51. Non-Clinical Staff'],
                ['hcw_transitioned_data',       'Q52. Data Staff'],
                ['hcw_transitioned_community',  'Q53. Community-based Staff'],
                ['hcw_transitioned_other',      'Q54. Other'],
            ];
            foreach ($trans as [$fn, $ql]): ?>
            <div class="form-group">
                <label><?= $ql ?></label>
                <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══ SECTION 4: PLHIV & PBFW Enrollment ═════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-id-card"></i> Section 4: PLHIV &amp; PBFW Enrollment into SHA</div>
    <div class="section-body">
        <div class="form-grid-3">
            <?php
            $sha = [
                ['plhiv_enrolled_sha',       'Q56. Total PLHIVs enrolled into SHA'],
                ['plhiv_sha_premium_paid',   'Q57. PLHIVs enrolled with premium fully paid'],
                ['pbfw_enrolled_sha',        'Q58. Number of PBFW enrolled into SHA'],
                ['pbfw_sha_premium_paid',    'Q59. PBFW enrolled with premium fully paid'],
            ];
            foreach ($sha as [$fn, $ql]): ?>
            <div class="form-group">
                <label><?= $ql ?></label>
                <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0">
            </div>
            <?php endforeach; ?>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label>Q60. Has the facility been submitting SHA claims on time?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="sha_claims_submitted_ontime" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="sha_claims_submitted_ontime" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q61. In the last 3 months, has the facility consistently received SHA reimbursements monthly?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="sha_reimbursements_monthly" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="sha_reimbursements_monthly" value="No"> No</label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 5: County TA / Mentorship ══════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-chalkboard-teacher"></i> Section 5: County Led Technical Assistance / Mentorship</div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Q62. How many TA/Mentorship visits on HIV Prevention, HIV/TB and PMTCT were done in the last 3 months?</label>
                <input type="number" name="ta_visits_total" class="form-control" min="0" placeholder="0">
            </div>
            <div class="form-group">
                <label>Q63. Of the total TA visits, how many were done by MOH only (without IP staff)?</label>
                <input type="number" name="ta_visits_moh_only" class="form-control" min="0" placeholder="0">
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 6: Financing and Sustainability ════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-coins"></i> Section 6: Financing and Sustainability</div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Q64. Does the health facility have FIF collection mechanism in place?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="fif_collection_in_place" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="fif_collection_in_place" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q65. Has FIF collection incorporated HIV Prevention, HIV/TB, PMTCT &amp; MNCH services?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="fif_includes_hiv_tb_pmtct" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="fif_includes_hiv_tb_pmtct" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label>Q66. Is the facility receiving SHA capitation for HIV Prevention, HIV/TB, PMTCT &amp; MNCH services?</label>
                <div class="yn-group">
                    <label class="yn-opt"><input type="radio" name="sha_capitation_hiv_tb" value="Yes"> Yes</label>
                    <label class="yn-opt"><input type="radio" name="sha_capitation_hiv_tb" value="No"> No</label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SECTION 7: Mortality Outcomes ══════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-heartbeat"></i> Section 7: Mortality Outcomes (Oct–Dec 2025)</div>
    <div class="section-body">
        <div class="form-grid-3">
            <?php
            $mort = [
                ['deaths_all_cause',    'Q67. Total deaths from any cause (All-cause mortality)'],
                ['deaths_hiv_related',  'Q68. HIV related deaths'],
                ['deaths_hiv_pre_art',  'Q69. HIV deaths before ART linkage (late identification)'],
                ['deaths_tb',           'Q70. TB deaths'],
                ['deaths_maternal',     'Q71. Maternal deaths'],
                ['deaths_perinatal',    'Q72. Perinatal deaths (stillbirths + early neonatal <7 days)'],
            ];
            foreach ($mort as [$fn, $ql]): ?>
            <div class="form-group">
                <label><?= $ql ?></label>
                <input type="number" name="<?= $fn ?>" class="form-control" min="0" placeholder="0">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══ SECTION 8: Integration Readiness ════════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-project-diagram"></i> Section 8: Integration Readiness & Sustainability(Observations)</div>
    <div class="section-body">

        <div class="form-grid">

            <!-- Leadership -->
            <div class="form-group">
                <label>Q86. Leadership commitment to HIV integration</label>
                <select name="leadership_commitment" class="form-control">
                    <option value="">Select</option>
                    <option>High</option>
                    <option>Moderate</option>
                    <option>Low</option>
                </select>
            </div>

            <div class="form-group">
                <label>Q87. Is there a transition/integration plan?</label>
                <select name="transition_plan" class="form-control">
                    <option value="">Select</option>
                    <option>Yes - Implemented</option>
                    <option>Yes - Not Implemented</option>
                    <option>No</option>
                </select>
            </div>

            <div class="form-group">
                <label>Q88. HIV services included in AWP/Budget?</label>
                <select name="hiv_in_awp" class="form-control">
                    <option value="">Select</option>
                    <option>Fully</option>
                    <option>Partially</option>
                    <option>No</option>
                </select>
            </div>

            <!-- HR -->
            <div class="form-group">
                <label>Q89. Estimated HRH gap (%)</label>
                <select name="hrh_gap" class="form-control">
                    <option value="">Select</option>
                    <option>0-10%</option>
                    <option>10-30%</option>
                    <option>>30%</option>
                </select>
            </div>

            <div class="form-group">
                <label>Q90. Are staff multi-skilled?</label>
                <select name="staff_multiskilled" class="form-control">
                    <option value="">Select</option>
                    <option>Yes</option>
                    <option>Partial</option>
                    <option>No</option>
                </select>
            </div>

            <div class="form-group">
                <label>Q91. Is there roving/visiting HIV/TB staff?</label>
                <select name="roving_staff" class="form-control">
                    <option value="">Select</option>
                    <option>Yes - Regular</option>
                    <option>Yes - Irregular</option>
                    <option>No</option>
                </select>
            </div>

            <!-- Infrastructure -->
            <div class="form-group">
                <label>Q92. Infrastructure capacity for integration</label>
                <select name="infrastructure_capacity" class="form-control">
                    <option value="">Select</option>
                    <option>Adequate</option>
                    <option>Minor changes needed</option>
                    <option>Major redesign needed</option>
                </select>
            </div>

            <div class="form-group">
                <label>Q93. Space adequacy</label>
                <select name="space_adequacy" class="form-control">
                    <option value="">Select</option>
                    <option>Adequate</option>
                    <option>Congested</option>
                    <option>Severely Inadequate</option>
                </select>
            </div>

            <!-- Service -->
            <div class="form-group">
                <label>Q94. Can HIV services run without CCC?</label>
                <select name="service_delivery_without_ccc" class="form-control">
                    <option value="">Select</option>
                    <option>Yes</option>
                    <option>Partially</option>
                    <option>No</option>
                </select>
            </div>

            <div class="form-group">
                <label>Q95. Average patient waiting time</label>
                <select name="avg_wait_time" class="form-control">
                    <option value="">Select</option>
                    <option><1 hour</option>
                    <option>1-3 hours</option>
                    <option>>3 hours</option>
                </select>
            </div>

            <!-- Data -->
            <div class="form-group">
                <label>Q96. Data integration level</label>
                <select name="data_integration_level" class="form-control">
                    <option value="">Select</option>
                    <option>Fully Integrated</option>
                    <option>Partial</option>
                    <option>Fragmented</option>
                </select>
            </div>

            <!-- Finance -->
            <div class="form-group">
                <label>Q97. Financing coverage for HIV services</label>
                <select name="financing_coverage" class="form-control">
                    <option value="">Select</option>
                    <option>High</option>
                    <option>Moderate</option>
                    <option>Low</option>
                </select>
            </div>

            <!-- Risk -->
            <div class="form-group">
                <label>Q98. Risk of service disruption</label>
                <select name="disruption_risk" class="form-control">
                    <option value="">Select</option>
                    <option>Low</option>
                    <option>Moderate</option>
                    <option>High</option>
                </select>
            </div>

            <!-- Open -->
            <div class="form-group full">
                <label>Q99. Key barriers to integration</label>
                <textarea name="integration_barriers" class="form-control"></textarea>
            </div>

        </div>

    </div>
</div>
<!-- ═══ ADMINISTRATION ══════════════════════════════════════════════════════ -->
<div class="form-section">
    <div class="section-head"><i class="fas fa-user-check"></i> Data Collection Details</div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Data Collected By</label>
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
                <input type="date" name="collection_date" class="form-control"
                       style="max-width:220px" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
    </div>
</div>

<button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Assessment</button>
</form>
</div>

<script>
// ── Facility live search ──────────────────────────────────────────────────────
function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

const facInput   = document.getElementById('facilitySearch');
const facResults = document.getElementById('facResults');
const facSpinner = document.getElementById('facSpinner');
const facIcon    = document.getElementById('facSearchIcon');

facInput.addEventListener('input', debounce(async function() {
    const q = facInput.value.trim();
    if (q.length < 2) { facResults.style.display = 'none'; return; }
    facSpinner.style.display = 'block'; facIcon.style.display = 'none';
    try {
        const res  = await fetch(`integration_assessment.php?ajax=search_facility&q=${encodeURIComponent(q)}`);
        const rows = await res.json();
        facSpinner.style.display = 'none'; facIcon.style.display = 'block';
        renderFacResults(rows);
    } catch(e) {
        facSpinner.style.display = 'none'; facIcon.style.display = 'block';
    }
}, 350));

function renderFacResults(rows) {
    if (!rows.length) {
        facResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No facilities found</div>';
    } else {
        facResults.innerHTML = rows.map(r =>
            `<div class="result-item" onclick='selectFacility(${JSON.stringify(r).replace(/'/g,"&#39;")})'>
                <div class="ri-name">${r.facility_name} <span class="ri-badge">${r.mflcode||''}</span></div>
                <div class="ri-meta"><i class="fas fa-map-marker-alt" style="color:#0D1A63"></i>
                    ${r.county_name||''} &nbsp;|&nbsp; ${r.subcounty_name||''} &nbsp;|&nbsp; ${r.level_of_care_name||''}
                </div>
            </div>`
        ).join('');
    }
    facResults.style.display = 'block';
}

function selectFacility(r) {
    facResults.style.display = 'none';
    facInput.value = r.facility_name;
    // Set hidden fields
    document.getElementById('h_facility_id').value  = r.facility_id;
    document.getElementById('h_mflcode').value       = r.mflcode || '';
    document.getElementById('h_county_name').value   = r.county_name || '';
    document.getElementById('h_subcounty_name').value= r.subcounty_name || '';
    document.getElementById('h_owner').value         = r.owner || '';
    document.getElementById('h_sdp').value           = r.sdp || '';
    document.getElementById('h_agency').value        = r.agency || '';
    document.getElementById('h_emr').value           = r.emr || '';
    document.getElementById('h_emrstatus').value     = r.emrstatus || '';
    document.getElementById('h_infra').value         = r.infrastructuretype || '';
    document.getElementById('h_lat').value           = r.latitude || '';
    document.getElementById('h_lng').value           = r.longitude || '';
    document.getElementById('h_level').value         = r.level_of_care_name || '';
    // Populate visible card
    document.getElementById('fc_name').textContent     = r.facility_name;
    document.getElementById('fc_mfl').textContent      = r.mflcode || '—';
    document.getElementById('fc_county').textContent   = r.county_name || '—';
    document.getElementById('fc_subcounty').textContent= r.subcounty_name || '—';
    document.getElementById('fc_level').textContent    = r.level_of_care_name || '—';
    document.getElementById('fc_owner').textContent    = r.owner || '—';
    document.getElementById('fc_sdp').textContent      = r.sdp || '—';
    document.getElementById('fc_agency').textContent   = r.agency || '—';
    document.getElementById('fc_emr').textContent      = r.emr || '—';
    document.getElementById('fc_emrstatus').textContent= r.emrstatus || '—';
    document.getElementById('fc_infra').textContent    = r.infrastructuretype || '—';
    document.getElementById('facilityCard').style.display = 'block';
}

function clearFacility() {
    document.getElementById('h_facility_id').value = '';
    document.getElementById('facilityCard').style.display = 'none';
    facInput.value = '';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#facSearchWrap')) facResults.style.display = 'none';
});

// ── EMR Yes/No toggle ─────────────────────────────────────────────────────────
document.querySelectorAll('input[name="uses_emr"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('emrYesSection').style.display = this.value === 'Yes' ? 'block' : 'none';
        document.getElementById('emrNoSection').style.display  = this.value === 'No'  ? 'block' : 'none';
    });
});

// ── EMR repeater ──────────────────────────────────────────────────────────────
let emrCount = 1;
function addEMR() {
    emrCount++;
    const n = emrCount;
    const html = `<div class="emr-entry" data-n="${n}">
        <div class="emr-entry-header">
            <span class="emr-num">EMR System ${n}</span>
            <button type="button" class="remove-emr" onclick="removeEMR(this)">&#10005; Remove</button>
        </div>
        <div class="form-grid-3">
            <div class="form-group"><label>EMR Type / Name</label>
                <input type="text" name="emr_type[]" class="form-control" placeholder="e.g. KenyaEMR">
            </div>
            <div class="form-group"><label>Funded By</label>
                <input type="text" name="emr_funded_by[]" class="form-control" placeholder="e.g. PEPFAR">
            </div>
            <div class="form-group"><label>Date Started Use</label>
                <input type="date" name="emr_date_started[]" class="form-control">
            </div>
        </div>
    </div>`;
    document.getElementById('emrRepeater').insertAdjacentHTML('beforeend', html);
    document.querySelector('#emrRepeater .emr-entry:last-child').scrollIntoView({behavior:'smooth',block:'center'});
}
function removeEMR(btn) {
    const entries = document.querySelectorAll('#emrRepeater .emr-entry');
    if (entries.length <= 1) { alert('At least one EMR entry is required when EMR is in use.'); return; }
    btn.closest('.emr-entry').remove();
    document.querySelectorAll('#emrRepeater .emr-entry').forEach((el,i) => {
        el.querySelector('.emr-num').textContent = 'EMR System ' + (i+1);
    });
}

// ── Form validation ───────────────────────────────────────────────────────────
document.getElementById('iaForm').addEventListener('submit', function(e) {
    if (!document.getElementById('h_facility_id').value) {
        e.preventDefault();
        alert('Please search for and select a facility before submitting.');
        facInput.focus();
    }
});
</script>
</body>
</html>
