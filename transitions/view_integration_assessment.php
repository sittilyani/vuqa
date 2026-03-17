<?php
// view_integration_assessment.php
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Integration Assessment #<?= $id ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }

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

        /* Cards */
        .card {
            background: #fff;
            border-radius: 12px;
            margin-bottom: 22px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            overflow: hidden;
            border-left: 4px solid #0D1A63;
        }
        .card-head {
            background: linear-gradient(90deg, #0D1A63, #1a3a9e);
            color: #fff;
            padding: 12px 22px;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-body {
            padding: 22px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 16px;
        }
        .info-item {
            border-bottom: 1px dashed #e0e4f0;
            padding-bottom: 8px;
        }
        .info-item label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #999;
            font-weight: 600;
            display: block;
            margin-bottom: 2px;
        }
        .info-item span {
            font-size: 14px;
            color: #222;
            font-weight: 500;
            word-break: break-word;
        }

        .sub-label {
            font-size: 13px;
            font-weight: 700;
            color: #0D1A63;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 20px 0 12px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e8edf8;
        }

        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 8px 10px;
            background: #f8fafc;
            color: #0D1A63;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #e8ecf5;
        }

        .actions-bar {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        <h1><i class="fas fa-clipboard-check"></i> Assessment #<?= $id ?> Details</h1>
        <div class="hdr-links">
            <a href="integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
            <a href="integration_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        </div>
    </div>

    <div class="back-link">
        <a href="integration_assessment_list.php"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <!-- Facility Info -->
    <div class="card">
        <div class="card-head"><i class="fas fa-hospital"></i> Facility Information</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Assessment Period</label><span><?= htmlspecialchars($assessment['assessment_period'] ?? '—') ?></span></div>
                <div class="info-item"><label>Facility Name</label><span><?= htmlspecialchars($assessment['facility_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>MFL Code</label><span><?= htmlspecialchars($assessment['mflcode'] ?? '—') ?></span></div>
                <div class="info-item"><label>County</label><span><?= htmlspecialchars($assessment['county_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Sub-County</label><span><?= htmlspecialchars($assessment['subcounty_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Level of Care</label><span><?= htmlspecialchars($assessment['level_of_care_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Owner</label><span><?= htmlspecialchars($assessment['owner'] ?? '—') ?></span></div>
                <div class="info-item"><label>SDP</label><span><?= htmlspecialchars($assessment['sdp'] ?? '—') ?></span></div>
                <div class="info-item"><label>Agency</label><span><?= htmlspecialchars($assessment['agency'] ?? '—') ?></span></div>
                <div class="info-item"><label>EMR</label><span><?= htmlspecialchars($assessment['emr'] ?? '—') ?></span></div>
                <div class="info-item"><label>EMR Status</label><span><?= htmlspecialchars($assessment['emrstatus'] ?? '—') ?></span></div>
                <div class="info-item"><label>Infrastructure</label><span><?= htmlspecialchars($assessment['infrastructuretype'] ?? '—') ?></span></div>
                <div class="info-item"><label>Coordinates</label><span><?= ($assessment['latitude'] && $assessment['longitude']) ? $assessment['latitude'] . ', ' . $assessment['longitude'] : '—' ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 2a: HIV/TB Services -->
    <div class="card">
        <div class="card-head"><i class="fas fa-virus"></i> HIV/TB Services</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>US DoS IP Supported</label><span class="badge <?= $assessment['supported_by_usdos_ip'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['supported_by_usdos_ip'] ?? '—') ?></span></div>
                <div class="info-item"><label>ART Site</label><span class="badge <?= $assessment['is_art_site'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['is_art_site'] ?? '—') ?></span></div>
                <div class="info-item"><label>HIV/TB Integrated</label><span class="badge <?= $assessment['hiv_tb_integrated'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['hiv_tb_integrated'] ?? '—') ?></span></div>
                <div class="info-item"><label>Integration Model</label><span><?= htmlspecialchars($assessment['hiv_tb_integration_model'] ?? '—') ?></span></div>
                <div class="info-item"><label>TX_CURR</label><span><?= number_format($assessment['tx_curr'] ?? 0) ?></span></div>
                <div class="info-item"><label>TX_CURR PMTCT</label><span><?= number_format($assessment['tx_curr_pmtct'] ?? 0) ?></span></div>
                <div class="info-item"><label>PLHIV Integrated Care</label><span><?= number_format($assessment['plhiv_integrated_care'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 2b: Integration -->
    <div class="card">
        <div class="card-head"><i class="fas fa-baby"></i> Service Integration</div>
        <div class="card-body">
            <div class="info-grid">
                <?php
                $integration_items = [
                    'PMTCT in MNCH' => $assessment['pmtct_integrated_mnch'],
                    'HTS in OPD' => $assessment['hts_integrated_opd'],
                    'HTS in IPD' => $assessment['hts_integrated_ipd'],
                    'HTS in MNCH' => $assessment['hts_integrated_mnch'],
                    'PrEP in OPD' => $assessment['prep_integrated_opd'],
                    'PrEP in IPD' => $assessment['prep_integrated_ipd'],
                    'PrEP in MNCH' => $assessment['prep_integrated_mnch'],
                ];
                foreach ($integration_items as $label => $value):
                ?>
                <div class="info-item">
                    <label><?= $label ?></label>
                    <span class="badge <?= $value == 'Yes' ? 'badge-success' : ($value == 'No' ? 'badge-danger' : 'badge-info') ?>">
                        <?= htmlspecialchars($value ?? '—') ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Section 2c: EMR -->
    <div class="card">
        <div class="card-head"><i class="fas fa-laptop-medical"></i> EMR Integration</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Uses EMR</label><span class="badge <?= $assessment['uses_emr'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['uses_emr'] ?? '—') ?></span></div>
                <div class="info-item"><label>Single Unified EMR</label><span class="badge <?= $assessment['single_unified_emr'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['single_unified_emr'] ?? '—') ?></span></div>
            </div>

            <?php if ($assessment['uses_emr'] == 'Yes' && mysqli_num_rows($emr_systems) > 0): ?>
            <div class="sub-label">EMR Systems in Use</div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>EMR Type</th>
                        <th>Funded By</th>
                        <th>Date Started</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; while ($emr = mysqli_fetch_assoc($emr_systems)): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($emr['emr_type']) ?></td>
                        <td><?= htmlspecialchars($emr['funded_by'] ?? '—') ?></td>
                        <td><?= $emr['date_started'] ? date('d M Y', strtotime($emr['date_started'])) : '—' ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($assessment['uses_emr'] == 'No' && !empty($assessment['no_emr_reasons'])): ?>
            <div class="info-item"><label>No EMR Reasons</label><span><?= htmlspecialchars($assessment['no_emr_reasons']) ?></span></div>
            <?php endif; ?>

            <div class="sub-label">EMR by Department</div>
            <div class="info-grid">
                <?php
                $dept_emr = [
                    'OPD EMR' => $assessment['emr_at_opd'],
                    'OPD Other' => $assessment['emr_opd_other'],
                    'IPD EMR' => $assessment['emr_at_ipd'],
                    'IPD Other' => $assessment['emr_ipd_other'],
                    'MNCH EMR' => $assessment['emr_at_mnch'],
                    'MNCH Other' => $assessment['emr_mnch_other'],
                    'CCC EMR' => $assessment['emr_at_ccc'],
                    'CCC Other' => $assessment['emr_ccc_other'],
                    'PMTCT EMR' => $assessment['emr_at_pmtct'],
                    'PMTCT Other' => $assessment['emr_pmtct_other'],
                    'Lab EMR' => $assessment['emr_at_lab'],
                    'Lab Other' => $assessment['emr_lab_other'],
                    'Pharmacy EMR' => $assessment['emr_at_pharmacy'],
                    'Pharmacy Other' => $assessment['emr_pharmacy_other'],
                ];
                foreach ($dept_emr as $label => $value):
                    if (empty($value)) continue;
                ?>
                <div class="info-item">
                    <label><?= $label ?></label>
                    <span><?= htmlspecialchars($value) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="info-grid" style="margin-top:16px">
                <div class="info-item"><label>Lab Manifest</label><span class="badge <?= $assessment['lab_manifest_in_use'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['lab_manifest_in_use'] ?? '—') ?></span></div>
                <div class="info-item"><label>Tibu Lite</label><span class="badge <?= $assessment['tibu_lite_lims_in_use'] == 'Yes' ? 'badge-success' : ($assessment['tibu_lite_lims_in_use'] == 'Partial' ? 'badge-warning' : 'badge-danger') ?>"><?= htmlspecialchars($assessment['tibu_lite_lims_in_use'] ?? '—') ?></span></div>
                <div class="info-item"><label>Pharmacy WebADT</label><span class="badge <?= $assessment['pharmacy_webadt_in_use'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['pharmacy_webadt_in_use'] ?? '—') ?></span></div>
                <div class="info-item"><label>EMR Interoperable</label><span class="badge <?= $assessment['emr_interoperable_his'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['emr_interoperable_his'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 3: HRH -->
    <div class="card">
        <div class="card-head"><i class="fas fa-users"></i> HRH Transition</div>
        <div class="card-body">
            <div class="sub-label">HCWs Supported by PEPFAR IP</div>
            <div class="info-grid">
                <div class="info-item"><label>Total HCWs</label><span><?= number_format($assessment['hcw_total_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Clinical</label><span><?= number_format($assessment['hcw_clinical_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Non-Clinical</label><span><?= number_format($assessment['hcw_nonclinical_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Data</label><span><?= number_format($assessment['hcw_data_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Community</label><span><?= number_format($assessment['hcw_community_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Other</label><span><?= number_format($assessment['hcw_other_pepfar'] ?? 0) ?></span></div>
            </div>

            <div class="sub-label">HCWs Transitioned to County</div>
            <div class="info-grid">
                <div class="info-item"><label>Total Transitioned</label><span><?= number_format($assessment['hcw_transitioned_total'] ?? 0) ?></span></div>
                <div class="info-item"><label>Clinical</label><span><?= number_format($assessment['hcw_transitioned_clinical'] ?? 0) ?></span></div>
                <div class="info-item"><label>Non-Clinical</label><span><?= number_format($assessment['hcw_transitioned_nonclinical'] ?? 0) ?></span></div>
                <div class="info-item"><label>Data</label><span><?= number_format($assessment['hcw_transitioned_data'] ?? 0) ?></span></div>
                <div class="info-item"><label>Community</label><span><?= number_format($assessment['hcw_transitioned_community'] ?? 0) ?></span></div>
                <div class="info-item"><label>Other</label><span><?= number_format($assessment['hcw_transitioned_other'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 4: SHA Enrollment -->
    <div class="card">
        <div class="card-head"><i class="fas fa-id-card"></i> SHA Enrollment</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>PLHIVs Enrolled</label><span><?= number_format($assessment['plhiv_enrolled_sha'] ?? 0) ?></span></div>
                <div class="info-item"><label>PLHIVs Premium Paid</label><span><?= number_format($assessment['plhiv_sha_premium_paid'] ?? 0) ?></span></div>
                <div class="info-item"><label>PBFW Enrolled</label><span><?= number_format($assessment['pbfw_enrolled_sha'] ?? 0) ?></span></div>
                <div class="info-item"><label>PBFW Premium Paid</label><span><?= number_format($assessment['pbfw_sha_premium_paid'] ?? 0) ?></span></div>
                <div class="info-item"><label>Claims Submitted On Time</label><span class="badge <?= $assessment['sha_claims_submitted_ontime'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sha_claims_submitted_ontime'] ?? '—') ?></span></div>
                <div class="info-item"><label>Reimbursements Monthly</label><span class="badge <?= $assessment['sha_reimbursements_monthly'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sha_reimbursements_monthly'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 5 & 6: TA and Financing -->
    <div class="card">
        <div class="card-head"><i class="fas fa-chalkboard-teacher"></i> TA & Financing</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>TA Visits Total</label><span><?= number_format($assessment['ta_visits_total'] ?? 0) ?></span></div>
                <div class="info-item"><label>TA Visits MOH Only</label><span><?= number_format($assessment['ta_visits_moh_only'] ?? 0) ?></span></div>
                <div class="info-item"><label>FIF Collection in Place</label><span class="badge <?= $assessment['fif_collection_in_place'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['fif_collection_in_place'] ?? '—') ?></span></div>
                <div class="info-item"><label>FIF Includes HIV/TB</label><span class="badge <?= $assessment['fif_includes_hiv_tb_pmtct'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['fif_includes_hiv_tb_pmtct'] ?? '—') ?></span></div>
                <div class="info-item"><label>SHA Capitation</label><span class="badge <?= $assessment['sha_capitation_hiv_tb'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sha_capitation_hiv_tb'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 7: Mortality -->
    <div class="card">
        <div class="card-head"><i class="fas fa-heartbeat"></i> Mortality Outcomes</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>All-Cause Deaths</label><span><?= number_format($assessment['deaths_all_cause'] ?? 0) ?></span></div>
                <div class="info-item"><label>HIV Related</label><span><?= number_format($assessment['deaths_hiv_related'] ?? 0) ?></span></div>
                <div class="info-item"><label>HIV Pre-ART</label><span><?= number_format($assessment['deaths_hiv_pre_art'] ?? 0) ?></span></div>
                <div class="info-item"><label>TB Deaths</label><span><?= number_format($assessment['deaths_tb'] ?? 0) ?></span></div>
                <div class="info-item"><label>Maternal Deaths</label><span><?= number_format($assessment['deaths_maternal'] ?? 0) ?></span></div>
                <div class="info-item"><label>Perinatal Deaths</label><span><?= number_format($assessment['deaths_perinatal'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Admin -->
    <div class="card">
        <div class="card-head"><i class="fas fa-user-check"></i> Data Collection Details</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Collected By</label><span><?= htmlspecialchars($assessment['collected_by'] ?? '—') ?></span></div>
                <div class="info-item"><label>Collection Date</label><span><?= $assessment['collection_date'] ? date('d M Y', strtotime($assessment['collection_date'])) : '—' ?></span></div>
                <div class="info-item"><label>Created At</label><span><?= $assessment['created_at'] ? date('d M Y H:i', strtotime($assessment['created_at'])) : '—' ?></span></div>
                <div class="info-item"><label>Last Updated</label><span><?= $assessment['updated_at'] ? date('d M Y H:i', strtotime($assessment['updated_at'])) : '—' ?></span></div>
            </div>
        </div>
    </div>

    <div class="actions-bar">
        <a href="edit_integration_assessment.php?id=<?= $id ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Edit Assessment</a>
        <a href="integration_assessment_list.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>
</div>
</body>
</html>