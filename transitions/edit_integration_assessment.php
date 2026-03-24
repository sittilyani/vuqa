<?php
// edit_integration_assessment.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: integration_assessment_list.php');
    exit();
}

// Get main assessment
$query = "SELECT * FROM integration_assessments WHERE assessment_id = $id";
$result = mysqli_query($conn, $query);
if (mysqli_num_rows($result) == 0) {
    header('Location: integration_assessment_list.php');
    exit();
}
$assessment = mysqli_fetch_assoc($result);

// Get EMR systems
$emr_systems = mysqli_query($conn, "SELECT * FROM integration_assessment_emr_systems WHERE assessment_id = $id ORDER BY sort_order");
$emr_count = mysqli_num_rows($emr_systems);

// Handle POST submission
$msg = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assessment'])) {

    $e = function($v) use ($conn) { return mysqli_real_escape_string($conn, trim($v ?? '')); };
    $i = function($v) { return is_numeric($v) ? (int)$v : 'NULL'; };

    // Build no_emr_reasons from checkboxes
    $emr_reasons = isset($_POST['no_emr_reasons']) && is_array($_POST['no_emr_reasons'])
        ? $e(implode(',', $_POST['no_emr_reasons'])) : '';

    $sql = "UPDATE integration_assessments SET
        assessment_period = '{$e($_POST['assessment_period'])}',
        facility_name = '{$e($_POST['facility_name'])}',
        mflcode = '{$e($_POST['mflcode'])}',
        county_name = '{$e($_POST['county_name'])}',
        subcounty_name = '{$e($_POST['subcounty_name'])}',
        owner = '{$e($_POST['owner'])}',
        sdp = '{$e($_POST['sdp'])}',
        agency = '{$e($_POST['agency'])}',
        emr = '{$e($_POST['emr'])}',
        emrstatus = '{$e($_POST['emrstatus'])}',
        infrastructuretype = '{$e($_POST['infrastructuretype'])}',
        latitude = " . (is_numeric($_POST['latitude'] ?? '') ? (float)$_POST['latitude'] : 'NULL') . ",
        longitude = " . (is_numeric($_POST['longitude'] ?? '') ? (float)$_POST['longitude'] : 'NULL') . ",
        level_of_care_name = '{$e($_POST['level_of_care_name'])}',
        supported_by_usdos_ip = '{$e($_POST['supported_by_usdos_ip'] ?? '')}',
        is_art_site = '{$e($_POST['is_art_site'] ?? '')}',
        hiv_tb_integrated = '{$e($_POST['hiv_tb_integrated'] ?? '')}',
        hiv_tb_integration_model = '{$e($_POST['hiv_tb_integration_model'] ?? '')}',
        tx_curr = {$i($_POST['tx_curr'] ?? '')},
        tx_curr_pmtct = {$i($_POST['tx_curr_pmtct'] ?? '')},
        plhiv_integrated_care = {$i($_POST['plhiv_integrated_care'] ?? '')},
        pmtct_integrated_mnch = '{$e($_POST['pmtct_integrated_mnch'] ?? '')}',
        hts_integrated_opd = '{$e($_POST['hts_integrated_opd'] ?? '')}',
        hts_integrated_ipd = '{$e($_POST['hts_integrated_ipd'] ?? '')}',
        hts_integrated_mnch = '{$e($_POST['hts_integrated_mnch'] ?? '')}',
        prep_integrated_opd = '{$e($_POST['prep_integrated_opd'] ?? '')}',
        prep_integrated_ipd = '{$e($_POST['prep_integrated_ipd'] ?? '')}',
        prep_integrated_mnch = '{$e($_POST['prep_integrated_mnch'] ?? '')}',
        uses_emr = '{$e($_POST['uses_emr'] ?? '')}',
        no_emr_reasons = '$emr_reasons',
        single_unified_emr = '{$e($_POST['single_unified_emr'] ?? '')}',
        emr_at_opd = '{$e($_POST['emr_at_opd'] ?? '')}',
        emr_opd_other = '{$e($_POST['emr_opd_other'] ?? '')}',
        emr_at_ipd = '{$e($_POST['emr_at_ipd'] ?? '')}',
        emr_ipd_other = '{$e($_POST['emr_ipd_other'] ?? '')}',
        emr_at_mnch = '{$e($_POST['emr_at_mnch'] ?? '')}',
        emr_mnch_other = '{$e($_POST['emr_mnch_other'] ?? '')}',
        emr_at_ccc = '{$e($_POST['emr_at_ccc'] ?? '')}',
        emr_ccc_other = '{$e($_POST['emr_ccc_other'] ?? '')}',
        emr_at_pmtct = '{$e($_POST['emr_at_pmtct'] ?? '')}',
        emr_pmtct_other = '{$e($_POST['emr_pmtct_other'] ?? '')}',
        emr_at_lab = '{$e($_POST['emr_at_lab'] ?? '')}',
        emr_lab_other = '{$e($_POST['emr_lab_other'] ?? '')}',
        lab_manifest_in_use = '{$e($_POST['lab_manifest_in_use'] ?? '')}',
        tibu_lite_lims_in_use = '{$e($_POST['tibu_lite_lims_in_use'] ?? '')}',
        emr_at_pharmacy = '{$e($_POST['emr_at_pharmacy'] ?? '')}',
        emr_pharmacy_other = '{$e($_POST['emr_pharmacy_other'] ?? '')}',
        pharmacy_webadt_in_use = '{$e($_POST['pharmacy_webadt_in_use'] ?? '')}',
        emr_interoperable_his = '{$e($_POST['emr_interoperable_his'] ?? '')}',
        hcw_total_pepfar = {$i($_POST['hcw_total_pepfar'] ?? '')},
        hcw_clinical_pepfar = {$i($_POST['hcw_clinical_pepfar'] ?? '')},
        hcw_nonclinical_pepfar = {$i($_POST['hcw_nonclinical_pepfar'] ?? '')},
        hcw_data_pepfar = {$i($_POST['hcw_data_pepfar'] ?? '')},
        hcw_community_pepfar = {$i($_POST['hcw_community_pepfar'] ?? '')},
        hcw_other_pepfar = {$i($_POST['hcw_other_pepfar'] ?? '')},
        hcw_transitioned_clinical = {$i($_POST['hcw_transitioned_clinical'] ?? '')},
        hcw_transitioned_nonclinical = {$i($_POST['hcw_transitioned_nonclinical'] ?? '')},
        hcw_transitioned_data = {$i($_POST['hcw_transitioned_data'] ?? '')},
        hcw_transitioned_community = {$i($_POST['hcw_transitioned_community'] ?? '')},
        hcw_transitioned_other = {$i($_POST['hcw_transitioned_other'] ?? '')},
        hcw_transitioned_total = {$i($_POST['hcw_transitioned_total'] ?? '')},
        plhiv_enrolled_sha = {$i($_POST['plhiv_enrolled_sha'] ?? '')},
        plhiv_sha_premium_paid = {$i($_POST['plhiv_sha_premium_paid'] ?? '')},
        pbfw_enrolled_sha = {$i($_POST['pbfw_enrolled_sha'] ?? '')},
        pbfw_sha_premium_paid = {$i($_POST['pbfw_sha_premium_paid'] ?? '')},
        sha_claims_submitted_ontime = '{$e($_POST['sha_claims_submitted_ontime'] ?? '')}',
        sha_reimbursements_monthly = '{$e($_POST['sha_reimbursements_monthly'] ?? '')}',
        ta_visits_total = {$i($_POST['ta_visits_total'] ?? '')},
        ta_visits_moh_only = {$i($_POST['ta_visits_moh_only'] ?? '')},
        fif_collection_in_place = '{$e($_POST['fif_collection_in_place'] ?? '')}',
        fif_includes_hiv_tb_pmtct = '{$e($_POST['fif_includes_hiv_tb_pmtct'] ?? '')}',
        sha_capitation_hiv_tb = '{$e($_POST['sha_capitation_hiv_tb'] ?? '')}',
        deaths_all_cause = {$i($_POST['deaths_all_cause'] ?? '')},
        deaths_hiv_related = {$i($_POST['deaths_hiv_related'] ?? '')},
        deaths_hiv_pre_art = {$i($_POST['deaths_hiv_pre_art'] ?? '')},
        deaths_tb = {$i($_POST['deaths_tb'] ?? '')},
        deaths_maternal = {$i($_POST['deaths_maternal'] ?? '')},
        deaths_perinatal = {$i($_POST['deaths_perinatal'] ?? '')},
        leadership_commitment = '{$e($_POST['leadership_commitment'] ?? '')}',
        transition_plan = '{$e($_POST['transition_plan'] ?? '')}',
        hiv_in_awp = '{$e($_POST['hiv_in_awp'] ?? '')}',
        hrh_gap = '{$e($_POST['hrh_gap'] ?? '')}',
        staff_multiskilled = '{$e($_POST['staff_multiskilled'] ?? '')}',
        roving_staff = '{$e($_POST['roving_staff'] ?? '')}',
        infrastructure_capacity = '{$e($_POST['infrastructure_capacity'] ?? '')}',
        space_adequacy = '{$e($_POST['space_adequacy'] ?? '')}',
        service_delivery_without_ccc = '{$e($_POST['service_delivery_without_ccc'] ?? '')}',
        avg_wait_time = '{$e($_POST['avg_wait_time'] ?? '')}',
        data_integration_level = '{$e($_POST['data_integration_level'] ?? '')}',
        financing_coverage = '{$e($_POST['financing_coverage'] ?? '')}',
        disruption_risk = '{$e($_POST['disruption_risk'] ?? '')}',
        integration_barriers = '{$e($_POST['integration_barriers'] ?? '')}',
        collected_by = '{$e($_POST['collected_by'] ?? '')}',
        collection_date = '{$e($_POST['collection_date'] ?? date('Y-m-d'))}'
        WHERE assessment_id = $id";

    if (mysqli_query($conn, $sql)) {
        // Delete existing EMR systems
        mysqli_query($conn, "DELETE FROM integration_assessment_emr_systems WHERE assessment_id = $id");

        // Save new EMR systems
        if (!empty($_POST['emr_type']) && is_array($_POST['emr_type'])) {
            foreach ($_POST['emr_type'] as $k => $et) {
                if (empty(trim($et))) continue;
                $et = $e($et);
                $fb = $e($_POST['emr_funded_by'][$k] ?? '');
                $ds = $e($_POST['emr_date_started'][$k] ?? '');
                $ds_val = !empty($ds) ? "'$ds'" : 'NULL';
                mysqli_query($conn, "INSERT INTO integration_assessment_emr_systems
                    (assessment_id, facility_id, emr_type, funded_by, date_started, sort_order)
                    VALUES ($id, {$assessment['facility_id']}, '$et', '$fb', $ds_val, " . ($k + 1) . ")");
            }
        }

        $_SESSION['success_msg'] = 'Assessment updated successfully!';
        header('Location: view_integration_assessment.php?id=' . $id);
        exit();
    } else {
        $error = 'Error updating: ' . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Integration Assessment #<?= $id ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 1100px; margin: 0 auto; padding: 20px; }

        .page-header {
            background: linear-gradient(135deg, #0D1A63 0%, #1a3a9e 100%);
            color: #fff;
            padding: 22px 30px;
            border-radius: 14px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 6px 24px rgba(13,26,99,.25);
        }
        .page-header h1 {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header .hdr-links a {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-left: 8px;
            transition: background .2s;
        }
        .page-header .hdr-links a:hover {
            background: rgba(255,255,255,.28);
        }

        .back-link {
            margin-bottom: 16px;
        }
        .back-link a {
            color: #0D1A63;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .alert {
            padding: 13px 18px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-section {
            background: #fff;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            overflow: hidden;
            border-left: 4px solid #0D1A63;
        }
        .section-head {
            background: linear-gradient(90deg, #0D1A63, #1a3a9e);
            color: #fff;
            padding: 12px 22px;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-body {
            padding: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group.full {
            grid-column: 1/-1;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #444;
            font-size: 13.5px;
        }
        .form-group label small {
            font-weight: 400;
            color: #888;
        }
        .req {
            color: #dc3545;
            font-style: normal;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #ddd;
            border-radius: 7px;
            font-size: 13.5px;
            transition: border-color .2s;
            background: #fff;
            font-family: inherit;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #0D1A63;
            box-shadow: 0 0 0 3px rgba(13,26,99,.1);
        }
        input[type=number].form-control {
            -moz-appearance: textfield;
        }

        .yn-group {
            display: flex;
            gap: 20px;
            margin-top: 6px;
        }
        .yn-opt {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13.5px;
            cursor: pointer;
        }
        .yn-opt input {
            width: 16px;
            height: 16px;
            accent-color: #0D1A63;
            cursor: pointer;
        }
        .cb-group {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 6px;
        }
        .cb-opt {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            cursor: pointer;
        }
        .cb-opt input {
            width: 16px;
            height: 16px;
            accent-color: #0D1A63;
            cursor: pointer;
            flex-shrink: 0;
        }

        .emr-entry {
            background: #f8f9fc;
            border: 1px solid #e0e4f0;
            border-radius: 9px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }
        .emr-entry-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .emr-num {
            font-size: 11px;
            font-weight: 700;
            color: #0D1A63;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .remove-emr {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 5px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .remove-emr:hover {
            background: #fecaca;
        }
        .add-emr-btn {
            width: 100%;
            background: #eef1ff;
            color: #0D1A63;
            border: 2px dashed #0D1A63;
            border-radius: 8px;
            padding: 9px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 4px;
            transition: background .2s;
        }
        .add-emr-btn:hover {
            background: #dde3ff;
        }

        .sub-label {
            font-size: 12px;
            font-weight: 700;
            color: #0D1A63;
            text-transform: uppercase;
            letter-spacing: .8px;
            margin: 18px 0 12px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e8edf8;
        }

        .actions-bar {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 28px 0;
        }
        .btn {
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: #0D1A63;
            color: #fff;
        }
        .btn-primary:hover { background: #1a2a7a; }
        .btn-secondary {
            background: #f3f4f6;
            color: #666;
        }
        .btn-secondary:hover { background: #e5e7eb; }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1><i class="fas fa-edit"></i> Edit Assessment #<?= $id ?></h1>
        <div class="hdr-links">
            <a href="view_integration_assessment.php?id=<?= $id ?>"><i class="fas fa-eye"></i> View</a>
            <a href="integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
        </div>
    </div>

    <div class="back-link">
        <a href="view_integration_assessment.php?id=<?= $id ?>"><i class="fas fa-arrow-left"></i> Back to View</a>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="update_assessment" value="1">

        <!-- Facility Info (Read-only) -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-hospital"></i> Facility Information (Read-only)</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Facility Name</label>
                        <input type="text" name="facility_name" class="form-control" value="<?= htmlspecialchars($assessment['facility_name']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>MFL Code</label>
                        <input type="text" name="mflcode" class="form-control" value="<?= htmlspecialchars($assessment['mflcode']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>County</label>
                        <input type="text" name="county_name" class="form-control" value="<?= htmlspecialchars($assessment['county_name']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Sub-County</label>
                        <input type="text" name="subcounty_name" class="form-control" value="<?= htmlspecialchars($assessment['subcounty_name']) ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assessment Period -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-calendar"></i> Assessment Details</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Assessment Period <span class="req">*</span></label>
                        <select name="assessment_period" class="form-select" required>
                            <option value="">-- Select Period --</option>
                            <option value="Jan-Mar 2025" <?= $assessment['assessment_period'] == 'Jan-Mar 2025' ? 'selected' : '' ?>>Jan–Mar 2025</option>
                            <option value="Apr-Jun 2025" <?= $assessment['assessment_period'] == 'Apr-Jun 2025' ? 'selected' : '' ?>>Apr–Jun 2025</option>
                            <option value="Jul-Sep 2025" <?= $assessment['assessment_period'] == 'Jul-Sep 2025' ? 'selected' : '' ?>>Jul–Sep 2025</option>
                            <option value="Oct-Dec 2025" <?= $assessment['assessment_period'] == 'Oct-Dec 2025' ? 'selected' : '' ?>>Oct–Dec 2025</option>
                            <option value="Jan-Mar 2026" <?= $assessment['assessment_period'] == 'Jan-Mar 2026' ? 'selected' : '' ?>>Jan–Mar 2026</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 1: Facility Profile (Q7-Q8) -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-clipboard-check"></i> Section 1: Facility Profile</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Q7. Supported by US DoS IP?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="supported_by_usdos_ip" value="Yes" <?= $assessment['supported_by_usdos_ip'] == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="supported_by_usdos_ip" value="No" <?= $assessment['supported_by_usdos_ip'] == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q8. Is this an ART site?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="is_art_site" value="Yes" <?= $assessment['is_art_site'] == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="is_art_site" value="No" <?= $assessment['is_art_site'] == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2a: HIV/TB -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-virus"></i> Section 2a: HIV/TB Services</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Q9. HIV/TB services integrated?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="hiv_tb_integrated" value="Yes" <?= $assessment['hiv_tb_integrated'] == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="hiv_tb_integrated" value="No" <?= $assessment['hiv_tb_integrated'] == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q10. Integration model</label>
                        <input type="text" name="hiv_tb_integration_model" class="form-control" value="<?= htmlspecialchars($assessment['hiv_tb_integration_model'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Q11. TX_CURR</label>
                        <input type="number" name="tx_curr" class="form-control" value="<?= $assessment['tx_curr'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Q12. TX_CURR PMTCT</label>
                        <input type="number" name="tx_curr_pmtct" class="form-control" value="<?= $assessment['tx_curr_pmtct'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Q13. PLHIV integrated care</label>
                        <input type="number" name="plhiv_integrated_care" class="form-control" value="<?= $assessment['plhiv_integrated_care'] ?? 0 ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2b: Integration -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-baby"></i> Section 2b: PMTCT, HTS & PrEP</div>
            <div class="section-body">
                <div class="form-grid">
                    <?php
                    $q2b = [
                        ['pmtct_integrated_mnch', 'PMTCT integrated in MNCH?'],
                        ['hts_integrated_opd', 'HTS integrated in OPD?'],
                        ['hts_integrated_ipd', 'HTS integrated in IPD?'],
                        ['hts_integrated_mnch', 'HTS integrated in MNCH?'],
                        ['prep_integrated_opd', 'PrEP integrated in OPD?'],
                        ['prep_integrated_ipd', 'PrEP integrated in IPD?'],
                        ['prep_integrated_mnch', 'PrEP integrated in MNCH?'],
                    ];
                    foreach ($q2b as [$fn, $ql]): ?>
                    <div class="form-group">
                        <label><?= $ql ?></label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="Yes" <?= ($assessment[$fn] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="<?= $fn ?>" value="No" <?= ($assessment[$fn] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Section 2c: EMR -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-laptop-medical"></i> Section 2c: EMR Integration</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Q21. Uses any EMR?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="uses_emr" value="Yes" id="uses_emr_yes" <?= $assessment['uses_emr'] == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="uses_emr" value="No" id="uses_emr_no" <?= $assessment['uses_emr'] == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q24. Single unified EMR?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="single_unified_emr" value="Yes" <?= ($assessment['single_unified_emr'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="single_unified_emr" value="No" <?= ($assessment['single_unified_emr'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                </div>

                <!-- EMR Systems -->
                <div id="emrYesSection" style="<?= $assessment['uses_emr'] == 'Yes' ? 'display:block' : 'display:none' ?>">
                    <div class="sub-label"><i class="fas fa-plus-circle"></i> Q22. EMR Systems in Use</div>
                    <div id="emrRepeater">
                        <?php
                        $emr_index = 0;
                        mysqli_data_seek($emr_systems, 0);
                        if (mysqli_num_rows($emr_systems) > 0):
                            while ($emr = mysqli_fetch_assoc($emr_systems)):
                                $emr_index++;
                        ?>
                        <div class="emr-entry" data-n="<?= $emr_index ?>">
                            <div class="emr-entry-header">
                                <span class="emr-num">EMR System <?= $emr_index ?></span>
                                <button type="button" class="remove-emr" onclick="removeEMR(this)" <?= $emr_index == 1 ? 'style="display:none"' : '' ?>>? Remove</button>
                            </div>
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label>EMR Type / Name</label>
                                    <input type="text" name="emr_type[]" class="form-control" value="<?= htmlspecialchars($emr['emr_type']) ?>">
                                </div>
                                <div class="form-group">
                                    <label>Funded By</label>
                                    <input type="text" name="emr_funded_by[]" class="form-control" value="<?= htmlspecialchars($emr['funded_by'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label>Date Started</label>
                                    <input type="date" name="emr_date_started[]" class="form-control" value="<?= $emr['date_started'] ?? '' ?>">
                                </div>
                            </div>
                        </div>
                        <?php endwhile; else: ?>
                        <div class="emr-entry" data-n="1">
                            <div class="emr-entry-header">
                                <span class="emr-num">EMR System 1</span>
                            </div>
                            <div class="form-grid-3">
                                <div class="form-group">
                                    <label>EMR Type / Name</label>
                                    <input type="text" name="emr_type[]" class="form-control" placeholder="e.g. KenyaEMR">
                                </div>
                                <div class="form-group">
                                    <label>Funded By</label>
                                    <input type="text" name="emr_funded_by[]" class="form-control" placeholder="e.g. PEPFAR">
                                </div>
                                <div class="form-group">
                                    <label>Date Started</label>
                                    <input type="date" name="emr_date_started[]" class="form-control">
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="add-emr-btn" onclick="addEMR()">+ Add Another EMR System</button>
                </div>

                <!-- No EMR Reasons -->
                <?php
                $no_emr_reasons = explode(',', $assessment['no_emr_reasons'] ?? '');
                ?>
                <div id="emrNoSection" style="<?= $assessment['uses_emr'] == 'No' ? 'display:block' : 'display:none' ?>; margin-top:14px">
                    <div class="form-group">
                        <label>Q23. Reasons (select all that apply):</label>
                        <div class="cb-group">
                            <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No hardware" <?= in_array('No hardware', $no_emr_reasons) ? 'checked' : '' ?>> No hardware</label>
                            <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No internet" <?= in_array('No internet', $no_emr_reasons) ? 'checked' : '' ?>> No internet</label>
                            <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No electricity" <?= in_array('No electricity', $no_emr_reasons) ? 'checked' : '' ?>> No electricity</label>
                            <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="No trained staff" <?= in_array('No trained staff', $no_emr_reasons) ? 'checked' : '' ?>> No trained staff</label>
                            <label class="cb-opt"><input type="checkbox" name="no_emr_reasons[]" value="Other" <?= in_array('Other', $no_emr_reasons) ? 'checked' : '' ?>> Other</label>
                        </div>
                    </div>
                </div>

                <!-- EMR by Department -->
                <div class="sub-label" style="margin-top:20px">EMR by Department</div>
                <div class="form-grid">
                    <?php
                    $depts = [
                        ['emr_at_opd', 'emr_opd_other', 'OPD'],
                        ['emr_at_ipd', 'emr_ipd_other', 'IPD'],
                        ['emr_at_mnch', 'emr_mnch_other', 'MNCH'],
                        ['emr_at_ccc', 'emr_ccc_other', 'CCC'],
                        ['emr_at_pmtct', 'emr_pmtct_other', 'PMTCT'],
                        ['emr_at_lab', 'emr_lab_other', 'Lab'],
                        ['emr_at_pharmacy', 'emr_pharmacy_other', 'Pharmacy'],
                    ];
                    foreach ($depts as [$yn_name, $other_name, $dept]): ?>
                    <div class="form-group">
                        <label>EMR at <?= $dept ?>?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="<?= $yn_name ?>" value="Yes" <?= ($assessment[$yn_name] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="<?= $yn_name ?>" value="No" <?= ($assessment[$yn_name] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                        <input type="text" name="<?= $other_name ?>" class="form-control" style="margin-top:7px" value="<?= htmlspecialchars($assessment[$other_name] ?? '') ?>" placeholder="If other, specify">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-grid" style="margin-top:10px">
                    <div class="form-group">
                        <label>Q37. Lab Manifest in use?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="lab_manifest_in_use" value="Yes" <?= ($assessment['lab_manifest_in_use'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="lab_manifest_in_use" value="No" <?= ($assessment['lab_manifest_in_use'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q38. Tibu Lite (LIMS) in use?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="tibu_lite_lims_in_use" value="Yes" <?= ($assessment['tibu_lite_lims_in_use'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="tibu_lite_lims_in_use" value="No" <?= ($assessment['tibu_lite_lims_in_use'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                            <label class="yn-opt"><input type="radio" name="tibu_lite_lims_in_use" value="Partial" <?= ($assessment['tibu_lite_lims_in_use'] ?? '') == 'Partial' ? 'checked' : '' ?>> Partial</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q41. Pharmacy WebADT in use?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="pharmacy_webadt_in_use" value="Yes" <?= ($assessment['pharmacy_webadt_in_use'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="pharmacy_webadt_in_use" value="No" <?= ($assessment['pharmacy_webadt_in_use'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q42. EMR interoperable with HIS?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="emr_interoperable_his" value="Yes" <?= ($assessment['emr_interoperable_his'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="emr_interoperable_his" value="No" <?= ($assessment['emr_interoperable_his'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: HRH -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-users"></i> Section 3: HRH Transition</div>
            <div class="section-body">
                <div class="sub-label">HCWs Supported by PEPFAR IP</div>
                <div class="form-grid-3">
                    <?php
                    $hrh_fields = [
                        'hcw_total_pepfar' => 'Total HCWs',
                        'hcw_clinical_pepfar' => 'Clinical',
                        'hcw_nonclinical_pepfar' => 'Non-Clinical',
                        'hcw_data_pepfar' => 'Data',
                        'hcw_community_pepfar' => 'Community',
                        'hcw_other_pepfar' => 'Other',
                    ];
                    foreach ($hrh_fields as $field => $label): ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <input type="number" name="<?= $field ?>" class="form-control" value="<?= $assessment[$field] ?? 0 ?>">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="sub-label">HCWs Transitioned to County</div>
                <div class="form-grid-3">
                    <?php
                    $trans_fields = [
                        'hcw_transitioned_total' => 'Total Transitioned',
                        'hcw_transitioned_clinical' => 'Clinical',
                        'hcw_transitioned_nonclinical' => 'Non-Clinical',
                        'hcw_transitioned_data' => 'Data',
                        'hcw_transitioned_community' => 'Community',
                        'hcw_transitioned_other' => 'Other',
                    ];
                    foreach ($trans_fields as $field => $label): ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <input type="number" name="<?= $field ?>" class="form-control" value="<?= $assessment[$field] ?? 0 ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Section 4: SHA -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-id-card"></i> Section 4: SHA Enrollment</div>
            <div class="section-body">
                <div class="form-grid-3">
                    <?php
                    $sha_fields = [
                        'plhiv_enrolled_sha' => 'PLHIVs enrolled in SHA',
                        'plhiv_sha_premium_paid' => 'PLHIVs premium paid',
                        'pbfw_enrolled_sha' => 'PBFW enrolled in SHA',
                        'pbfw_sha_premium_paid' => 'PBFW premium paid',
                    ];
                    foreach ($sha_fields as $field => $label): ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <input type="number" name="<?= $field ?>" class="form-control" value="<?= $assessment[$field] ?? 0 ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Q60. SHA claims submitted on time?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="sha_claims_submitted_ontime" value="Yes" <?= ($assessment['sha_claims_submitted_ontime'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="sha_claims_submitted_ontime" value="No" <?= ($assessment['sha_claims_submitted_ontime'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q61. SHA reimbursements monthly?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="sha_reimbursements_monthly" value="Yes" <?= ($assessment['sha_reimbursements_monthly'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="sha_reimbursements_monthly" value="No" <?= ($assessment['sha_reimbursements_monthly'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 5: TA -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-chalkboard-teacher"></i> Section 5: TA / Mentorship</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Q62. Total TA visits</label>
                        <input type="number" name="ta_visits_total" class="form-control" value="<?= $assessment['ta_visits_total'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Q63. TA visits by MOH only</label>
                        <input type="number" name="ta_visits_moh_only" class="form-control" value="<?= $assessment['ta_visits_moh_only'] ?? 0 ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 6: Financing -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-coins"></i> Section 6: Financing</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Q64. FIF collection in place?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="fif_collection_in_place" value="Yes" <?= ($assessment['fif_collection_in_place'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="fif_collection_in_place" value="No" <?= ($assessment['fif_collection_in_place'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q65. FIF includes HIV/TB/PMTCT?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="fif_includes_hiv_tb_pmtct" value="Yes" <?= ($assessment['fif_includes_hiv_tb_pmtct'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="fif_includes_hiv_tb_pmtct" value="No" <?= ($assessment['fif_includes_hiv_tb_pmtct'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Q66. SHA capitation for HIV/TB?</label>
                        <div class="yn-group">
                            <label class="yn-opt"><input type="radio" name="sha_capitation_hiv_tb" value="Yes" <?= ($assessment['sha_capitation_hiv_tb'] ?? '') == 'Yes' ? 'checked' : '' ?>> Yes</label>
                            <label class="yn-opt"><input type="radio" name="sha_capitation_hiv_tb" value="No" <?= ($assessment['sha_capitation_hiv_tb'] ?? '') == 'No' ? 'checked' : '' ?>> No</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 7: Mortality -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-heartbeat"></i> Section 7: Mortality</div>
            <div class="section-body">
                <div class="form-grid-3">
                    <?php
                    $mort_fields = [
                        'deaths_all_cause' => 'All-cause mortality',
                        'deaths_hiv_related' => 'HIV related',
                        'deaths_hiv_pre_art' => 'HIV pre-ART',
                        'deaths_tb' => 'TB deaths',
                        'deaths_maternal' => 'Maternal deaths',
                        'deaths_perinatal' => 'Perinatal deaths',
                    ];
                    foreach ($mort_fields as $field => $label): ?>
                    <div class="form-group">
                        <label><?= $label ?></label>
                        <input type="number" name="<?= $field ?>" class="form-control" value="<?= $assessment[$field] ?? 0 ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Section 8: Integration Readiness & Sustainability (NEW) -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-project-diagram"></i> Section 8: Integration Readiness & Sustainability</div>
            <div class="section-body">
                <div class="form-grid">
                    <!-- Leadership -->
                    <div class="form-group">
                        <label>Q86. Leadership commitment to HIV integration</label>
                        <select name="leadership_commitment" class="form-control">
                            <option value="">Select</option>
                            <option value="High" <?= ($assessment['leadership_commitment'] ?? '') == 'High' ? 'selected' : '' ?>>High</option>
                            <option value="Moderate" <?= ($assessment['leadership_commitment'] ?? '') == 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                            <option value="Low" <?= ($assessment['leadership_commitment'] ?? '') == 'Low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Q87. Is there a transition/integration plan?</label>
                        <select name="transition_plan" class="form-control">
                            <option value="">Select</option>
                            <option value="Yes - Implemented" <?= ($assessment['transition_plan'] ?? '') == 'Yes - Implemented' ? 'selected' : '' ?>>Yes - Implemented</option>
                            <option value="Yes - Not Implemented" <?= ($assessment['transition_plan'] ?? '') == 'Yes - Not Implemented' ? 'selected' : '' ?>>Yes - Not Implemented</option>
                            <option value="No" <?= ($assessment['transition_plan'] ?? '') == 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Q88. HIV services included in AWP/Budget?</label>
                        <select name="hiv_in_awp" class="form-control">
                            <option value="">Select</option>
                            <option value="Fully" <?= ($assessment['hiv_in_awp'] ?? '') == 'Fully' ? 'selected' : '' ?>>Fully</option>
                            <option value="Partially" <?= ($assessment['hiv_in_awp'] ?? '') == 'Partially' ? 'selected' : '' ?>>Partially</option>
                            <option value="No" <?= ($assessment['hiv_in_awp'] ?? '') == 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <!-- HR -->
                    <div class="form-group">
                        <label>Q89. Estimated HRH gap (%)</label>
                        <select name="hrh_gap" class="form-control">
                            <option value="">Select</option>
                            <option value="0-10%" <?= ($assessment['hrh_gap'] ?? '') == '0-10%' ? 'selected' : '' ?>>0-10%</option>
                            <option value="10-30%" <?= ($assessment['hrh_gap'] ?? '') == '10-30%' ? 'selected' : '' ?>>10-30%</option>
                            <option value=">30%" <?= ($assessment['hrh_gap'] ?? '') == '>30%' ? 'selected' : '' ?>>&gt;30%</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Q90. Are staff multi-skilled?</label>
                        <select name="staff_multiskilled" class="form-control">
                            <option value="">Select</option>
                            <option value="Yes" <?= ($assessment['staff_multiskilled'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                            <option value="Partial" <?= ($assessment['staff_multiskilled'] ?? '') == 'Partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="No" <?= ($assessment['staff_multiskilled'] ?? '') == 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Q91. Is there roving/visiting HIV/TB staff?</label>
                        <select name="roving_staff" class="form-control">
                            <option value="">Select</option>
                            <option value="Yes - Regular" <?= ($assessment['roving_staff'] ?? '') == 'Yes - Regular' ? 'selected' : '' ?>>Yes - Regular</option>
                            <option value="Yes - Irregular" <?= ($assessment['roving_staff'] ?? '') == 'Yes - Irregular' ? 'selected' : '' ?>>Yes - Irregular</option>
                            <option value="No" <?= ($assessment['roving_staff'] ?? '') == 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <!-- Infrastructure -->
                    <div class="form-group">
                        <label>Q92. Infrastructure capacity for integration</label>
                        <select name="infrastructure_capacity" class="form-control">
                            <option value="">Select</option>
                            <option value="Adequate" <?= ($assessment['infrastructure_capacity'] ?? '') == 'Adequate' ? 'selected' : '' ?>>Adequate</option>
                            <option value="Minor changes needed" <?= ($assessment['infrastructure_capacity'] ?? '') == 'Minor changes needed' ? 'selected' : '' ?>>Minor changes needed</option>
                            <option value="Major redesign needed" <?= ($assessment['infrastructure_capacity'] ?? '') == 'Major redesign needed' ? 'selected' : '' ?>>Major redesign needed</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Q93. Space adequacy</label>
                        <select name="space_adequacy" class="form-control">
                            <option value="">Select</option>
                            <option value="Adequate" <?= ($assessment['space_adequacy'] ?? '') == 'Adequate' ? 'selected' : '' ?>>Adequate</option>
                            <option value="Congested" <?= ($assessment['space_adequacy'] ?? '') == 'Congested' ? 'selected' : '' ?>>Congested</option>
                            <option value="Severely Inadequate" <?= ($assessment['space_adequacy'] ?? '') == 'Severely Inadequate' ? 'selected' : '' ?>>Severely Inadequate</option>
                        </select>
                    </div>

                    <!-- Service -->
                    <div class="form-group">
                        <label>Q94. Can HIV services run without CCC?</label>
                        <select name="service_delivery_without_ccc" class="form-control">
                            <option value="">Select</option>
                            <option value="Yes" <?= ($assessment['service_delivery_without_ccc'] ?? '') == 'Yes' ? 'selected' : '' ?>>Yes</option>
                            <option value="Partially" <?= ($assessment['service_delivery_without_ccc'] ?? '') == 'Partially' ? 'selected' : '' ?>>Partially</option>
                            <option value="No" <?= ($assessment['service_delivery_without_ccc'] ?? '') == 'No' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Q95. Average patient waiting time</label>
                        <select name="avg_wait_time" class="form-control">
                            <option value="">Select</option>
                            <option value="<1 hour" <?= ($assessment['avg_wait_time'] ?? '') == '<1 hour' ? 'selected' : '' ?>><1 hour</option>
                            <option value="1-3 hours" <?= ($assessment['avg_wait_time'] ?? '') == '1-3 hours' ? 'selected' : '' ?>>1-3 hours</option>
                            <option value=">3 hours" <?= ($assessment['avg_wait_time'] ?? '') == '>3 hours' ? 'selected' : '' ?>> >3 hours</option>
                        </select>
                    </div>

                    <!-- Data -->
                    <div class="form-group">
                        <label>Q96. Data integration level</label>
                        <select name="data_integration_level" class="form-control">
                            <option value="">Select</option>
                            <option value="Fully Integrated" <?= ($assessment['data_integration_level'] ?? '') == 'Fully Integrated' ? 'selected' : '' ?>>Fully Integrated</option>
                            <option value="Partial" <?= ($assessment['data_integration_level'] ?? '') == 'Partial' ? 'selected' : '' ?>>Partial</option>
                            <option value="Fragmented" <?= ($assessment['data_integration_level'] ?? '') == 'Fragmented' ? 'selected' : '' ?>>Fragmented</option>
                        </select>
                    </div>

                    <!-- Finance -->
                    <div class="form-group">
                        <label>Q97. Financing coverage for HIV services</label>
                        <select name="financing_coverage" class="form-control">
                            <option value="">Select</option>
                            <option value="High" <?= ($assessment['financing_coverage'] ?? '') == 'High' ? 'selected' : '' ?>>High</option>
                            <option value="Moderate" <?= ($assessment['financing_coverage'] ?? '') == 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                            <option value="Low" <?= ($assessment['financing_coverage'] ?? '') == 'Low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>

                    <!-- Risk -->
                    <div class="form-group">
                        <label>Q98. Risk of service disruption</label>
                        <select name="disruption_risk" class="form-control">
                            <option value="">Select</option>
                            <option value="Low" <?= ($assessment['disruption_risk'] ?? '') == 'Low' ? 'selected' : '' ?>>Low</option>
                            <option value="Moderate" <?= ($assessment['disruption_risk'] ?? '') == 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                            <option value="High" <?= ($assessment['disruption_risk'] ?? '') == 'High' ? 'selected' : '' ?>>High</option>
                        </select>
                    </div>

                    <!-- Open -->
                    <div class="form-group full">
                        <label>Q99. Key barriers to integration</label>
                        <textarea name="integration_barriers" class="form-control" rows="4"><?= htmlspecialchars($assessment['integration_barriers'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin -->
        <div class="form-section">
            <div class="section-head"><i class="fas fa-user-check"></i> Data Collection</div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Collected By</label>
                        <input type="text" name="collected_by" class="form-control" value="<?= htmlspecialchars($assessment['collected_by'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Collection Date</label>
                        <input type="date" name="collection_date" class="form-control" value="<?= $assessment['collection_date'] ?? date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="actions-bar">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Assessment</button>
            <a href="view_integration_assessment.php?id=<?= $id ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</a>
        </div>
    </form>
</div>

<script>
// EMR Yes/No toggle
document.querySelectorAll('input[name="uses_emr"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('emrYesSection').style.display = this.value === 'Yes' ? 'block' : 'none';
        document.getElementById('emrNoSection').style.display  = this.value === 'No'  ? 'block' : 'none';
    });
});

// EMR repeater
let emrCount = <?= max($emr_count, 1) ?>;
function addEMR() {
    emrCount++;
    const html = `<div class="emr-entry" data-n="${emrCount}">
        <div class="emr-entry-header">
            <span class="emr-num">EMR System ${emrCount}</span>
            <button type="button" class="remove-emr" onclick="removeEMR(this)">? Remove</button>
        </div>
        <div class="form-grid-3">
            <div class="form-group"><label>EMR Type / Name</label>
                <input type="text" name="emr_type[]" class="form-control" placeholder="e.g. KenyaEMR">
            </div>
            <div class="form-group"><label>Funded By</label>
                <input type="text" name="emr_funded_by[]" class="form-control" placeholder="e.g. PEPFAR">
            </div>
            <div class="form-group"><label>Date Started</label>
                <input type="date" name="emr_date_started[]" class="form-control">
            </div>
        </div>
    </div>`;
    document.getElementById('emrRepeater').insertAdjacentHTML('beforeend', html);
}

function removeEMR(btn) {
    const entries = document.querySelectorAll('#emrRepeater .emr-entry');
    if (entries.length <= 1) {
        alert('At least one EMR entry is required when EMR is in use.');
        return;
    }
    btn.closest('.emr-entry').remove();
    // Renumber
    document.querySelectorAll('#emrRepeater .emr-entry').forEach((el,i) => {
        el.querySelector('.emr-num').textContent = 'EMR System ' + (i+1);
    });
}
</script>
</body>
</html>