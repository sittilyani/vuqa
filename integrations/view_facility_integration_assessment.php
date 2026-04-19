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
    header('Location: facility_integration_assessment_list.php');
    exit();
}

// Get user role for permission checks
$user_role = $_SESSION['role'] ?? '';
$is_super_admin = ($user_role === 'Super Admin');

// Get main assessment
$query = "SELECT * FROM integration_assessments WHERE assessment_id = $id";
$result = mysqli_query($conn, $query);
if (mysqli_num_rows($result) == 0) {
    header('Location: facility_integration_assessment_list.php');
    exit();
}
$assessment = mysqli_fetch_assoc($result);

$status = $assessment['assessment_status'] ?? 'Draft';
$is_submitted = ($status === 'Submitted');
$is_complete = ($status === 'Complete');
$is_draft = ($status === 'Draft');

// Get EMR systems
$emr_systems = mysqli_query($conn, "SELECT * FROM integration_assessment_emr_systems WHERE assessment_id = $id ORDER BY sort_order");

// Helper function for scoring
function s($v, $m) {
    if (is_null($v) || $v === '') return 1;
    return $m[$v] ?? 1;
}

// Calculate scores for all sections
$lead = s($assessment['leadership_commitment'] ?? '', ['High'=>3, 'Moderate'=>2, 'Low'=>1]);
$plan = s($assessment['transition_plan'] ?? '', ['Yes - Implemented'=>3, 'Yes - Not Implemented'=>2, 'No'=>1]);
$awp = s($assessment['hiv_in_awp'] ?? '', ['Fully'=>3, 'Partially'=>2, 'No'=>1]);

$hrh = s($assessment['hrh_gap'] ?? '', ['0-10%'=>3, '10-30%'=>2, '>30%'=>1]);
$multi = s($assessment['staff_multiskilled'] ?? '', ['Yes'=>3, 'Partial'=>2, 'No'=>1]);
$rov = s($assessment['roving_staff'] ?? '', ['Yes - Regular'=>3, 'Yes - Irregular'=>2, 'No'=>1]);

$infra = s($assessment['infrastructure_capacity'] ?? '', ['Adequate'=>3, 'Minor changes needed'=>2, 'Major redesign needed'=>1]);
$space = s($assessment['space_adequacy'] ?? '', ['Adequate'=>3, 'Congested'=>2, 'Severely Inadequate'=>1]);

$serv = s($assessment['service_delivery_without_ccc'] ?? '', ['Yes'=>3, 'Partially'=>2, 'No'=>1]);
$wait = s($assessment['avg_wait_time'] ?? '', ['<1 hour'=>3, '1-3 hours'=>2, '>3 hours'=>1]);

$data = s($assessment['data_integration_level'] ?? '', ['Fully Integrated'=>3, 'Partial'=>2, 'Fragmented'=>1]);
$fin = s($assessment['financing_coverage'] ?? '', ['High'=>3, 'Moderate'=>2, 'Low'=>1]);

// Calculate weighted scores
$leadership_score = (($lead + $plan + $awp) / 9 * 100) * 0.15;
$hrh_score = (($hrh + $multi + $rov) / 9 * 100) * 0.20;
$infra_score = (($infra + $space) / 6 * 100) * 0.10;
$service_score = (($serv + $wait) / 6 * 100) * 0.25;
$data_score = (($data / 3) * 100) * 0.15;
$finance_score = (($fin / 3) * 100) * 0.15;

$total = $leadership_score + $hrh_score + $infra_score + $service_score + $data_score + $finance_score;

// Determine readiness category
if ($total >= 80) { $cat = 'Fully Ready'; $clr = 'success'; }
elseif ($total >= 60) { $cat = 'Moderately Ready'; $clr = 'warning'; }
elseif ($total >= 40) { $cat = 'Low Readiness'; $clr = 'orange'; }
else { $cat = 'Not Ready'; $clr = 'danger'; }

// Raw values for AI report
$leadership_raw = $assessment['leadership_commitment'] ?? '';
$roving_raw = $assessment['roving_staff'] ?? '';
$data_raw = $assessment['data_integration_level'] ?? '';
$risk_raw = $assessment['disruption_risk'] ?? '';

// Basic recommendations
$rec = [];
if ($hrh < 2) $rec[] = 'Use Hub-Spoke Model';
if ($serv < 2) $rec[] = 'Implement DSD (MMD)';
if ($infra < 2) $rec[] = 'Improve infrastructure';
if ($lead < 2) $rec[] = 'Strengthen leadership';
if (empty($rec)) $rec[] = 'Proceed with full integration';

// AI Report generation from section 8
$ai_report = [];

if ($risk_raw === 'High') {
    $ai_report[] = "High risk of service disruption identified. Recommend a phased integration approach over >12 months to avoid patient loss and service interruption.";
}
if ($leadership_raw === 'Low') {
    $ai_report[] = "Low leadership commitment detected. Recommend strengthening governance, facility leadership engagement, and ownership before full integration.";
}
if ($roving_raw === 'Yes - Regular') {
    $ai_report[] = "Regular roving staff support is available. Recommend structured mentorship and gradual transition plan to phase out dependency while building internal capacity.";
}
if ($data_raw === 'Fragmented') {
    $ai_report[] = "Fragmented data systems detected. High risk of patient data loss during integration. Recommend strengthening EMR integration and data harmonization before full transition.";
}
if ($hrh < 2) {
    $ai_report[] = "Significant HRH gaps identified. Recommend task-shifting, multi-skilling, and possible hub-and-spoke model implementation.";
}
if ($infra < 2) {
    $ai_report[] = "Infrastructure limitations observed. Recommend redesigning patient flow or adopting partial integration models.";
}
if ($serv < 2) {
    $ai_report[] = "Service delivery readiness is low. Recommend differentiated service delivery (DSD) and multi-month dispensing to reduce facility burden.";
}
if ($total >= 80) {
    $ai_report[] = "Facility is highly ready. Recommend Full Integration Model with digital optimization.";
} elseif ($total >= 60) {
    $ai_report[] = "Facility is moderately ready. Recommend Hybrid Integration Model combining One-Stop-Shop and DSD.";
} elseif ($total >= 40) {
    $ai_report[] = "Facility has low readiness. Recommend phased integration with strong external support.";
} else {
    $ai_report[] = "Facility not ready for integration. Maintain vertical support while strengthening systems.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Integration Assessment #<?= $id ?> | <?= htmlspecialchars($assessment['facility_name'] ?? '') ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

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
            flex-wrap: wrap;
            gap: 15px;
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
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .page-header .hdr-links a:hover {
            background: rgba(255,255,255,.28);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            margin-left: 10px;
        }
        .status-draft { background: #e0e7ff; color: #3730a3; }
        .status-complete { background: #d4edda; color: #155724; }
        .status-submitted { background: #cff4fc; color: #0c5460; }

        .back-link { margin-bottom: 16px; }
        .back-link a {
            color: #0D1A63;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

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
        .card-body { padding: 22px; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
        .badge-success { background: #28a745; color: #fff; }
        .badge-warning { background: #ffc107; color: #000; }
        .badge-danger { background: #dc3545; color: #fff; }
        .badge-info { background: #17a2b8; color: #fff; }
        .badge-orange { background: #fd7e14; color: #fff; }

        .progress { height: 8px; background: #eee; border-radius: 5px; margin: 10px 0; }
        .progress-bar { height: 100%; border-radius: 5px; transition: width 0.3s; }
        .bg-success { background: #28a745; }
        .bg-warning { background: #ffc107; }
        .bg-danger { background: #dc3545; }
        .bg-orange { background: #fd7e14; }
        .bg-info { background: #17a2b8; }

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
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
            flex-wrap: wrap;
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
            border: none;
            cursor: pointer;
        }
        .btn-primary { background: #0D1A63; color: #fff; }
        .btn-primary:hover { background: #1a2a7a; }
        .btn-success { background: #28a745; color: #fff; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #f3f4f6; color: #666; }
        .btn-secondary:hover { background: #e5e7eb; }

        .score-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .score-value {
            font-size: 48px;
            font-weight: 800;
        }
        .score-label {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 5px;
        }

        .section-score {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 10px;
        }
        .warning-text { color: #856404; }
        .danger-text { color: #721c24; }
        .success-text { color: #155724; }

        @media (max-width: 768px) {
            .container { padding: 12px; }
            .info-grid { grid-template-columns: 1fr; }
            .actions-bar { justify-content: center; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>
            <i class="fas fa-clipboard-check"></i>
            Assessment #<?= $id ?>
            <span class="status-badge status-<?= strtolower($status) ?>"><?= $status ?></span>
        </h1>
        <div class="hdr-links">
            <a href="facility_integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
            <a href="facility_integration_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <?php if ($is_draft): ?>
            <a href="facility_integration_assessment.php?id=<?= $id ?>" style="background: #28a745;">
                <i class="fas fa-edit"></i> Continue Editing
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-link">
        <a href="facility_integration_assessment_list.php"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <!-- Overall Score Card -->
    <div class="score-card">
        <div class="score-value"><?= round($total) ?>%</div>
        <div class="score-label">Integration Readiness Score</div>
        <div class="badge badge-<?= $clr ?>" style="margin-top: 10px; font-size: 14px;"><?= $cat ?></div>
        <div class="progress" style="background: rgba(255,255,255,0.3); margin-top: 15px;">
            <div class="progress-bar bg-<?= $clr ?>" style="width: <?= $total ?>%"></div>
        </div>
    </div>

    <!-- Section Scores Breakdown -->
    <div class="card">
        <div class="card-head"><i class="fas fa-chart-pie"></i> Section Scores Breakdown</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <label>Leadership & Governance (15%)</label>
                    <span><?= round($leadership_score, 1) ?>%</span>
                </div>
                <div class="info-item">
                    <label>HRH Capacity (20%)</label>
                    <span><?= round($hrh_score, 1) ?>%</span>
                </div>
                <div class="info-item">
                    <label>Infrastructure & Space (10%)</label>
                    <span><?= round($infra_score, 1) ?>%</span>
                </div>
                <div class="info-item">
                    <label>Service Delivery (25%)</label>
                    <span><?= round($service_score, 1) ?>%</span>
                </div>
                <div class="info-item">
                    <label>Data Integration (15%)</label>
                    <span><?= round($data_score, 1) ?>%</span>
                </div>
                <div class="info-item">
                    <label>Financial Sustainability (15%)</label>
                    <span><?= round($finance_score, 1) ?>%</span>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($ai_report) && $is_submitted): ?>
    <div class="card">
        <div class="card-head"><i class="fas fa-robot"></i> AI-Powered Integration Report</div>
        <div class="card-body">
            <ol style="margin-left: 20px;">
                <?php foreach ($ai_report as $line): ?>
                    <li style="margin-bottom: 10px;"><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head"><i class="fas fa-lightbulb"></i> Key Recommendations</div>
        <div class="card-body">
            <ul style="margin-left: 20px;">
                <?php foreach($rec as $r): ?>
                    <li><?= htmlspecialchars($r) ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="progress" style="margin-top: 15px;">
                <div class="progress-bar bg-<?= $clr ?>" style="width: <?= $total ?>%"></div>
            </div>
            <p style="margin-top: 15px; font-weight: bold;">
                Overall Recommendation: <?= $cat ?> facility with a readiness score of <?= round($total) ?>%.
            </p>
        </div>
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
                <div class="info-item"><label>Infrastructure Type</label><span><?= htmlspecialchars($assessment['infrastructuretype'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 1: Facility Profile -->
    <div class="card">
        <div class="card-head"><i class="fas fa-user-md"></i> Section 1: Facility Profile</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Supported by US DoS/IP</label><span class="badge <?= $assessment['supported_by_usdos_ip'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['supported_by_usdos_ip'] ?? '—') ?></span></div>
                <div class="info-item"><label>ART Site</label><span class="badge <?= $assessment['is_art_site'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['is_art_site'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 2a: HIV/TB Services -->
    <div class="card">
        <div class="card-head"><i class="fas fa-virus"></i> Section 2a: HIV/TB Services</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HIV/TB Integrated</label><span class="badge <?= $assessment['hiv_tb_integrated'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['hiv_tb_integrated'] ?? '—') ?></span></div>
                <div class="info-item"><label>Integration Model</label><span><?= htmlspecialchars($assessment['hiv_tb_integration_model'] ?? '—') ?></span></div>
                <div class="info-item"><label>TX_CURR</label><span><?= number_format($assessment['tx_curr'] ?? 0) ?></span></div>
                <div class="info-item"><label>TX_CURR PMTCT</label><span><?= number_format($assessment['tx_curr_pmtct'] ?? 0) ?></span></div>
                <div class="info-item"><label>PLHIV in Integrated Care</label><span><?= number_format($assessment['plhiv_integrated_care'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 2b: Service Integration -->
    <div class="card">
        <div class="card-head"><i class="fas fa-baby"></i> Section 2b: Service Integration (PMTCT, HTS & PrEP)</div>
        <div class="card-body">
            <div class="info-grid">
                <?php
                $integration_items = [
                    'PMTCT in MNCH' => $assessment['pmtct_integrated_mnch'] ?? '—',
                    'HTS in OPD' => $assessment['hts_integrated_opd'] ?? '—',
                    'HTS in IPD' => $assessment['hts_integrated_ipd'] ?? '—',
                    'HTS in MNCH' => $assessment['hts_integrated_mnch'] ?? '—',
                    'PrEP in OPD' => $assessment['prep_integrated_opd'] ?? '—',
                    'PrEP in IPD' => $assessment['prep_integrated_ipd'] ?? '—',
                    'PrEP in MNCH' => $assessment['prep_integrated_mnch'] ?? '—',
                ];
                foreach ($integration_items as $label => $value):
                    $badge_class = $value == 'Yes' ? 'badge-success' : ($value == 'No' ? 'badge-danger' : 'badge-info');
                ?>
                <div class="info-item">
                    <label><?= $label ?></label>
                    <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($value) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Section 2c: EMR Integration -->
    <div class="card">
        <div class="card-head"><i class="fas fa-laptop-medical"></i> Section 2c: EMR Integration</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Uses EMR</label><span class="badge <?= $assessment['uses_emr'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['uses_emr'] ?? '—') ?></span></div>
                <div class="info-item"><label>Single Unified EMR</label><span class="badge <?= $assessment['single_unified_emr'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['single_unified_emr'] ?? '—') ?></span></div>
                <?php if ($assessment['uses_emr'] == 'No' && !empty($assessment['no_emr_reasons'])): ?>
                <div class="info-item"><label>No EMR Reasons</label><span><?= htmlspecialchars($assessment['no_emr_reasons']) ?></span></div>
                <?php endif; ?>
            </div>

            <?php if ($assessment['uses_emr'] == 'Yes' && mysqli_num_rows($emr_systems) > 0): ?>
            <div class="sub-label">EMR Systems in Use</div>
            <table>
                <thead><tr><th>#</th><th>EMR Type</th><th>Funded By</th><th>Date Started</th></tr></thead>
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

            <div class="sub-label">EMR Coverage by Department</div>
            <div class="info-grid">
                <?php
                $dept_emr = [
                    'OPD EMR' => $assessment['emr_at_opd'] ?? '—',
                    'OPD Other' => $assessment['emr_opd_other'] ?? '',
                    'IPD EMR' => $assessment['emr_at_ipd'] ?? '—',
                    'IPD Other' => $assessment['emr_ipd_other'] ?? '',
                    'MNCH EMR' => $assessment['emr_at_mnch'] ?? '—',
                    'MNCH Other' => $assessment['emr_mnch_other'] ?? '',
                    'CCC EMR' => $assessment['emr_at_ccc'] ?? '—',
                    'CCC Other' => $assessment['emr_ccc_other'] ?? '',
                    'PMTCT EMR' => $assessment['emr_at_pmtct'] ?? '—',
                    'PMTCT Other' => $assessment['emr_pmtct_other'] ?? '',
                    'Lab EMR' => $assessment['emr_at_lab'] ?? '—',
                    'Lab Other' => $assessment['emr_lab_other'] ?? '',
                    'Pharmacy EMR' => $assessment['emr_at_pharmacy'] ?? '—',
                    'Pharmacy Other' => $assessment['emr_pharmacy_other'] ?? '',
                ];
                foreach ($dept_emr as $label => $value):
                    if (empty($value) || $value === '—') continue;
                ?>
                <div class="info-item">
                    <label><?= $label ?></label>
                    <span><?= htmlspecialchars($value) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="info-grid" style="margin-top: 16px;">
                <div class="info-item"><label>Lab Manifest in Use</label><span class="badge <?= $assessment['lab_manifest_in_use'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['lab_manifest_in_use'] ?? '—') ?></span></div>
                <div class="info-item"><label>Tibu Lite LIMS</label><span class="badge <?= $assessment['tibu_lite_lims_in_use'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['tibu_lite_lims_in_use'] ?? '—') ?></span></div>
                <div class="info-item"><label>Pharmacy WebADT</label><span class="badge <?= $assessment['pharmacy_webadt_in_use'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['pharmacy_webadt_in_use'] ?? '—') ?></span></div>
                <div class="info-item"><label>EMR Interoperable with HIS</label><span class="badge <?= $assessment['emr_interoperable_his'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['emr_interoperable_his'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 3: HRH Transition -->
    <div class="card">
        <div class="card-head"><i class="fas fa-users"></i> Section 3: HRH Transition</div>
        <div class="card-body">
            <div class="sub-label">HCWs Supported by PEPFAR IP</div>
            <div class="info-grid">
                <div class="info-item"><label>Total HCWs</label><span><?= number_format($assessment['hcw_total_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Clinical Staff</label><span><?= number_format($assessment['hcw_clinical_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Non-Clinical Staff</label><span><?= number_format($assessment['hcw_nonclinical_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Data Staff</label><span><?= number_format($assessment['hcw_data_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Community-Based Staff</label><span><?= number_format($assessment['hcw_community_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Other Staff</label><span><?= number_format($assessment['hcw_other_pepfar'] ?? 0) ?></span></div>
            </div>

            <div class="sub-label">HCWs Transitioned to County Support</div>
            <div class="info-grid">
                <div class="info-item"><label>Clinical Staff</label><span><?= number_format($assessment['hcw_transitioned_clinical'] ?? 0) ?></span></div>
                <div class="info-item"><label>Non-Clinical Staff</label><span><?= number_format($assessment['hcw_transitioned_nonclinical'] ?? 0) ?></span></div>
                <div class="info-item"><label>Data Staff</label><span><?= number_format($assessment['hcw_transitioned_data'] ?? 0) ?></span></div>
                <div class="info-item"><label>Community-Based Staff</label><span><?= number_format($assessment['hcw_transitioned_community'] ?? 0) ?></span></div>
                <div class="info-item"><label>Other Staff</label><span><?= number_format($assessment['hcw_transitioned_other'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 4: PLHIV & PBFW SHA Enrollment -->
    <div class="card">
        <div class="card-head"><i class="fas fa-id-card"></i> Section 4: PLHIV & PBFW SHA Enrollment</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>PLHIV Enrolled in SHA</label><span><?= number_format($assessment['plhiv_enrolled_sha'] ?? 0) ?></span></div>
                <div class="info-item"><label>PLHIV SHA Premium Paid</label><span><?= number_format($assessment['plhiv_sha_premium_paid'] ?? 0) ?></span></div>
                <div class="info-item"><label>PBFW Enrolled in SHA</label><span><?= number_format($assessment['pbfw_enrolled_sha'] ?? 0) ?></span></div>
                <div class="info-item"><label>PBFW SHA Premium Paid</label><span><?= number_format($assessment['pbfw_sha_premium_paid'] ?? 0) ?></span></div>
                <div class="info-item"><label>SHA Claims Submitted On Time</label><span class="badge <?= $assessment['sha_claims_submitted_ontime'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sha_claims_submitted_ontime'] ?? '—') ?></span></div>
                <div class="info-item"><label>SHA Reimbursements Monthly</label><span class="badge <?= $assessment['sha_reimbursements_monthly'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sha_reimbursements_monthly'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 5: TA/Mentorship -->
    <div class="card">
        <div class="card-head"><i class="fas fa-chalkboard-teacher"></i> Section 5: TA/Mentorship</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>TA/Mentorship Visits (Total)</label><span><?= number_format($assessment['ta_visits_total'] ?? 0) ?></span></div>
                <div class="info-item"><label>TA/Mentorship Visits (MOH Only)</label><span><?= number_format($assessment['ta_visits_moh_only'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 6: Financing -->
    <div class="card">
        <div class="card-head"><i class="fas fa-coins"></i> Section 6: Financing</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>FIF Collection in Place</label><span class="badge <?= $assessment['fif_collection_in_place'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['fif_collection_in_place'] ?? '—') ?></span></div>
                <div class="info-item"><label>FIF Includes HIV/TB/PMTCT</label><span class="badge <?= $assessment['fif_includes_hiv_tb_pmtct'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['fif_includes_hiv_tb_pmtct'] ?? '—') ?></span></div>
                <div class="info-item"><label>SHA Capitation for HIV/TB</label><span class="badge <?= $assessment['sha_capitation_hiv_tb'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sha_capitation_hiv_tb'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 7: Mortality -->
    <div class="card">
        <div class="card-head"><i class="fas fa-heartbeat"></i> Section 7: Mortality Outcomes</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>All-Cause Deaths</label><span><?= number_format($assessment['deaths_all_cause'] ?? 0) ?></span></div>
                <div class="info-item"><label>HIV-Related Deaths</label><span><?= number_format($assessment['deaths_hiv_related'] ?? 0) ?></span></div>
                <div class="info-item"><label>HIV Deaths Pre-ART</label><span><?= number_format($assessment['deaths_hiv_pre_art'] ?? 0) ?></span></div>
                <div class="info-item"><label>TB Deaths</label><span><?= number_format($assessment['deaths_tb'] ?? 0) ?></span></div>
                <div class="info-item"><label>Maternal Deaths</label><span><?= number_format($assessment['deaths_maternal'] ?? 0) ?></span></div>
                <div class="info-item"><label>Perinatal Deaths</label><span><?= number_format($assessment['deaths_perinatal'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 8a: Integration Readiness -->
    <div class="card">
        <div class="card-head"><i class="fas fa-project-diagram"></i> Section 8a: Integration Readiness</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Leadership Commitment</label><span class="badge <?= $assessment['leadership_commitment'] == 'High' ? 'badge-success' : ($assessment['leadership_commitment'] == 'Moderate' ? 'badge-warning' : 'badge-danger') ?>"><?= htmlspecialchars($assessment['leadership_commitment'] ?? '—') ?></span></div>
                <div class="info-item"><label>Transition Plan</label><span><?= htmlspecialchars($assessment['transition_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>HIV in AWP</label><span><?= htmlspecialchars($assessment['hiv_in_awp'] ?? '—') ?></span></div>
                <div class="info-item"><label>HRH Gap</label><span><?= htmlspecialchars($assessment['hrh_gap'] ?? '—') ?></span></div>
                <div class="info-item"><label>Staff Multi-skilled</label><span><?= htmlspecialchars($assessment['staff_multiskilled'] ?? '—') ?></span></div>
                <div class="info-item"><label>Roving Staff</label><span><?= htmlspecialchars($assessment['roving_staff'] ?? '—') ?></span></div>
                <div class="info-item"><label>Infrastructure Capacity</label><span><?= htmlspecialchars($assessment['infrastructure_capacity'] ?? '—') ?></span></div>
                <div class="info-item"><label>Space Adequacy</label><span><?= htmlspecialchars($assessment['space_adequacy'] ?? '—') ?></span></div>
                <div class="info-item"><label>Service Delivery Without CCC</label><span><?= htmlspecialchars($assessment['service_delivery_without_ccc'] ?? '—') ?></span></div>
                <div class="info-item"><label>Average Wait Time</label><span><?= htmlspecialchars($assessment['avg_wait_time'] ?? '—') ?></span></div>
                <div class="info-item"><label>Data Integration Level</label><span><?= htmlspecialchars($assessment['data_integration_level'] ?? '—') ?></span></div>
                <div class="info-item"><label>Financing Coverage</label><span><?= htmlspecialchars($assessment['financing_coverage'] ?? '—') ?></span></div>
                <div class="info-item"><label>Disruption Risk</label><span class="badge <?= $assessment['disruption_risk'] == 'High' ? 'badge-danger' : ($assessment['disruption_risk'] == 'Moderate' ? 'badge-warning' : 'badge-success') ?>"><?= htmlspecialchars($assessment['disruption_risk'] ?? '—') ?></span></div>
            </div>
            <?php if (!empty($assessment['integration_barriers'])): ?>
            <div class="info-item" style="margin-top: 15px;">
                <label>Key Barriers to Integration</label>
                <span><?= nl2br(htmlspecialchars($assessment['integration_barriers'])) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 8b: Lab Support -->
    <div class="card">
        <div class="card-head"><i class="fas fa-flask"></i> Section 8b: Lab Support</div>
        <div class="card-body">
            <div class="info-grid">
                <?php
                $lab_items = [
                    'Specimen Referral System' => $assessment['lab_specimen_referral'] ?? '—',
                    'Referral County Funded' => $assessment['lab_referral_county_funded'] ?? '—',
                    'ISO 15189 Accredited' => $assessment['lab_iso15189_accredited'] ?? '—',
                    'KENAS Fee Support' => $assessment['lab_kenas_fee_support'] ?? '—',
                    'LCQI Implementing' => $assessment['lab_lcqi_implementing'] ?? '—',
                    'LCQI Internal Audits' => $assessment['lab_lcqi_internal_audits'] ?? '—',
                    'EQA All Tests' => $assessment['lab_eqa_all_tests'] ?? '—',
                    'SLA Equipment' => $assessment['lab_sla_equipment'] ?? '—',
                    'SLA Support' => $assessment['lab_sla_support'] ?? '—',
                    'LIMS in Place' => $assessment['lab_lims_in_place'] ?? '—',
                    'LIMS EMR Integrated' => $assessment['lab_lims_emr_integrated'] ?? '—',
                    'LIMS Interoperable' => $assessment['lab_lims_interoperable'] ?? '—',
                    'HIS Integration Guide' => $assessment['lab_his_integration_guide'] ?? '—',
                    'Dedicated HIS Staff' => $assessment['lab_dedicated_his_staff'] ?? '—',
                    'BSC Calibration Current' => $assessment['lab_bsc_calibration_current'] ?? '—',
                    'Shipping Cost Support' => $assessment['lab_shipping_cost_support'] ?? '—',
                    'Biosafety Trained' => $assessment['lab_biosafety_trained'] ?? '—',
                    'Hepatitis B Vaccinated' => $assessment['lab_hepb_vaccinated'] ?? '—',
                    'IPC Committee' => $assessment['lab_ipc_committee'] ?? '—',
                    'IPC Workplan' => $assessment['lab_ipc_workplan'] ?? '—',
                    'MOH Virtual Academy Access' => $assessment['lab_moh_virtual_academy'] ?? '—',
                ];
                foreach ($lab_items as $label => $value):
                    $badge_class = $value == 'Yes' ? 'badge-success' : ($value == 'No' ? 'badge-danger' : 'badge-info');
                ?>
                <div class="info-item">
                    <label><?= $label ?></label>
                    <span class="badge <?= $badge_class ?>"><?= htmlspecialchars($value) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Section 9: Community Engagement -->
    <div class="card">
        <div class="card-head"><i class="fas fa-users-cog"></i> Section 9: Community Engagement</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HIV Feedback Mechanism</label><span class="badge <?= $assessment['comm_hiv_feedback_mechanism'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['comm_hiv_feedback_mechanism'] ?? '—') ?></span></div>
                <div class="info-item"><label>ROC Feedback Used</label><span class="badge <?= $assessment['comm_roc_feedback_used'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['comm_roc_feedback_used'] ?? '—') ?></span></div>
                <div class="info-item"><label>Community Representation</label><span class="badge <?= $assessment['comm_community_representation'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['comm_community_representation'] ?? '—') ?></span></div>
                <div class="info-item"><label>PLHIV in Discussions</label><span class="badge <?= $assessment['comm_plhiv_in_discussions'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['comm_plhiv_in_discussions'] ?? '—') ?></span></div>
                <div class="info-item"><label>Health Talks with PLHIV (last 3 months)</label><span><?= number_format($assessment['comm_health_talks_plhiv'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 10: Supply Chain -->
    <div class="card">
        <div class="card-head"><i class="fas fa-boxes"></i> Section 10: Supply Chain</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>KHIS Reports Submitted Monthly</label><span class="badge <?= $assessment['sc_khis_reports_monthly'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['sc_khis_reports_monthly'] ?? '—') ?></span></div>
                <div class="info-item"><label>Stock-out of ARVs (last 3 months)</label><span class="badge <?= $assessment['sc_stockout_arvs'] == 'Yes' ? 'badge-danger' : 'badge-success' ?>"><?= htmlspecialchars($assessment['sc_stockout_arvs'] ?? '—') ?></span></div>
                <div class="info-item"><label>Stock-out of TB Drugs (last 3 months)</label><span class="badge <?= $assessment['sc_stockout_tb_drugs'] == 'Yes' ? 'badge-danger' : 'badge-success' ?>"><?= htmlspecialchars($assessment['sc_stockout_tb_drugs'] ?? '—') ?></span></div>
                <div class="info-item"><label>Stock-out of HIV Reagents (last 3 months)</label><span class="badge <?= $assessment['sc_stockout_hiv_reagents'] == 'Yes' ? 'badge-danger' : 'badge-success' ?>"><?= htmlspecialchars($assessment['sc_stockout_hiv_reagents'] ?? '—') ?></span></div>
                <div class="info-item"><label>Stock-out of TB Reagents (last 3 months)</label><span class="badge <?= $assessment['sc_stockout_tb_reagents'] == 'Yes' ? 'badge-danger' : 'badge-success' ?>"><?= htmlspecialchars($assessment['sc_stockout_tb_reagents'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 11: Primary Health Care -->
    <div class="card">
        <div class="card-head"><i class="fas fa-clinic-medical"></i> Section 11: Primary Health Care</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>CHP Referrals Received</label><span class="badge <?= $assessment['phc_chp_referrals'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['phc_chp_referrals'] ?? '—') ?></span></div>
                <div class="info-item"><label>CHWP Tracing for LTFU</label><span class="badge <?= $assessment['phc_chwp_tracing'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['phc_chwp_tracing'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Data Collection Details -->
    <div class="card">
        <div class="card-head"><i class="fas fa-user-check"></i> Data Collection Details</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Collected By</label><span><?= htmlspecialchars($assessment['collected_by'] ?? '—') ?></span></div>
                <div class="info-item"><label>Collection Date</label><span><?= $assessment['collection_date'] ? date('d M Y', strtotime($assessment['collection_date'])) : '—' ?></span></div>
                <div class="info-item"><label>Last Saved By</label><span><?= htmlspecialchars($assessment['last_saved_by'] ?? '—') ?></span></div>
                <div class="info-item"><label>Last Saved At</label><span><?= $assessment['last_saved_at'] ? date('d M Y H:i', strtotime($assessment['last_saved_at'])) : '—' ?></span></div>
                <div class="info-item"><label>Created At</label><span><?= $assessment['created_at'] ? date('d M Y H:i', strtotime($assessment['created_at'])) : '—' ?></span></div>
                <div class="info-item"><label>Last Updated</label><span><?= $assessment['updated_at'] ? date('d M Y H:i', strtotime($assessment['updated_at'])) : '—' ?></span></div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="actions-bar">
        <?php if ($is_draft): ?>
            <a href="facility_integration_assessment.php?id=<?= $id ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Continue Editing
            </a>
        <?php endif; ?>

        <?php if ($is_submitted): ?>
            <a href="facility_integration_workplan.php?id=<?= $id ?>" class="btn btn-success">
                <i class="fas fa-robot"></i> Generate AI Report & Workplan
            </a>
        <?php endif; ?>
        <?php if ($is_complete): ?>
            <a href="facility_integration_workplan.php?id=<?= $id ?>" class="btn btn-success">
                <i class="fas fa-robot"></i> Generate AI Report & Workplan
            </a>
        <?php endif; ?>

        <a href="facility_integration_assessment_list.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>
</body>
</html>