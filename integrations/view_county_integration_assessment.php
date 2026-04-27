<?php
// view_county_integration_assessment.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: county_integration_assessment_list.php');
    exit();
}

// Get user role for permission checks
$user_role = $_SESSION['role'] ?? '';
$is_super_admin = ($user_role === 'Super Admin');

// Get main assessment
$query = "SELECT * FROM county_integration_assessments WHERE assessment_id = $id";
$result = mysqli_query($conn, $query);
if (mysqli_num_rows($result) == 0) {
    header('Location: county_integration_assessment_list.php');
    exit();
}
$assessment = mysqli_fetch_assoc($result);

$status = $assessment['assessment_status'] ?? 'Draft';
$is_submitted = ($status === 'Submitted');
$is_complete = ($status === 'Complete');
$is_draft = ($status === 'Draft');

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
$transition_plan_raw = $assessment['transition_plan'] ?? '';
$integration_oversight_raw = $assessment['has_integration_oversight_team'] ?? '';

// Calculate HRW transition percentage
$total_pepfar = ($assessment['hcw_total_pepfar'] ?? 0);
$total_transitioned = ($assessment['hcw_transitioned_total'] ?? 0);
$transition_percentage = $total_pepfar > 0 ? round(($total_transitioned / $total_pepfar) * 100) : 0;

// Calculate EMR coverage percentage
$emr_depts = ['opd', 'ipd', 'mnch', 'ccc', 'pmtct', 'lab', 'pharmacy'];
$emr_count = 0;
foreach ($emr_depts as $dept) {
    $field = 'emr_at_' . $dept;
    if (isset($assessment[$field]) && $assessment[$field] == 'Yes') {
        $emr_count++;
    }
}
$emr_coverage = round(($emr_count / 7) * 100);

// Basic recommendations
$rec = [];
if ($hrh < 2) $rec[] = 'Address HRH gaps through strategic recruitment and task-shifting';
if ($serv < 2) $rec[] = 'Implement Differentiated Service Delivery (DSD) models';
if ($infra < 2) $rec[] = 'Improve infrastructure and space allocation for integrated services';
if ($lead < 2) $rec[] = 'Strengthen county leadership commitment to integration';
if ($data < 2) $rec[] = 'Enhance data integration across health information systems';
if ($fin < 2) $rec[] = 'Strengthen financial sustainability through FIF and SHA optimization';
if (empty($rec)) $rec[] = 'Proceed with full integration across all county health facilities';

// AI Report generation
$ai_report = [];

if ($risk_raw === 'High') {
    $ai_report[] = "?? High risk of service disruption detected. Recommend a phased integration approach over 12+ months to ensure continuity of HIV/TB services across the county.";
}
if ($leadership_raw === 'Low') {
    $ai_report[] = "?? Low leadership commitment identified. Critical to engage county executive leadership, establish an integration steering committee, and secure formal county government buy-in before proceeding.";
}
if ($roving_raw === 'Yes - Regular') {
    $ai_report[] = "? Regular roving staff support is available. Leverage this for structured mentorship and develop a gradual transition plan to phase out external dependency while building county-level capacity.";
}
if ($data_raw === 'Fragmented') {
    $ai_report[] = "?? Fragmented data systems detected across county facilities. High risk of patient data loss during transition. Prioritize EMR standardization and interoperability before full integration.";
}
if ($hrh < 2) {
    $ai_report[] = "?? Significant HRH gaps identified at county level. Consider hub-and-spoke model where high-volume facilities serve as mentorship hubs for surrounding health centers.";
}
if ($infra < 2) {
    $ai_report[] = "?? Infrastructure limitations observed across county facilities. Recommend conducting facility readiness assessments and prioritizing renovations for high-volume sites.";
}
if ($serv < 2) {
    $ai_report[] = "?? Service delivery readiness is low. Differentiated Service Delivery (DSD) and multi-month dispensing (MMD) should be prioritized to reduce facility burden.";
}
if ($transition_percentage > 50) {
    $ai_report[] = "? Strong HRH transition progress with {$transition_percentage}% of PEPFAR-supported staff already transitioned to county payroll. This demonstrates county commitment and should be replicated across remaining cadres.";
} elseif ($transition_percentage > 0 && $transition_percentage <= 50) {
    $ai_report[] = "?? Moderate HRH transition progress at {$transition_percentage}%. Accelerate absorption of remaining PEPFAR-supported staff, particularly clinical and data cadres.";
} elseif ($transition_percentage == 0 && $total_pepfar > 0) {
    $ai_report[] = "?? No HRH transition to county payroll has occurred despite {$total_pepfar} PEPFAR-supported staff. Urgently develop and implement HRH transition plan.";
}

if ($integration_oversight_raw === 'Yes') {
    $ai_report[] = "? County integration oversight team is functional. Ensure quarterly review meetings are held and action points are tracked.";
} else {
    $ai_report[] = "?? No functional integration oversight team. Establish immediately with clear terms of reference and multi-stakeholder representation.";
}

if ($assessment['has_fif_collection_plan'] === 'Yes') {
    $ai_report[] = "? FIF collection plan incorporating HIV/TB services exists. Strengthen implementation and ensure funds are utilized for integration activities.";
} else {
    $ai_report[] = "?? No FIF collection plan incorporating HIV/TB services. Establish FIF mechanism to generate local revenue for integration sustainability.";
}

if ($assessment['receives_sha_capitation'] === 'Yes') {
    $ai_report[] = "? County receives SHA capitation for HIV/TB services. Maximize this revenue stream by ensuring all PLHIV are enrolled and claims submitted on time.";
} else {
    $ai_report[] = "?? County not receiving SHA capitation for HIV/TB services. Advocate for inclusion and support facilities with enrollment and claims submission.";
}

if ($assessment['has_lab_strategic_plan'] === 'Yes') {
    $ai_report[] = "? Laboratory strategic plan and budget in place. Ensure alignment with integration priorities and adequate funding allocation.";
} else {
    $ai_report[] = "?? County lacks laboratory strategic plan. Develop plan to guide lab services integration and sustainability.";
}

if ($total >= 80) {
    $ai_report[] = "?? County is highly ready for integration. Recommended: Full Integration Model across all facilities with digital health optimization and peer learning networks.";
} elseif ($total >= 60) {
    $ai_report[] = "?? County is moderately ready for integration. Recommended: Hybrid Integration Model combining One-Stop-Shop facilities with DSD for stable patients, phased over 9-12 months.";
} elseif ($total >= 40) {
    $ai_report[] = "?? County has low readiness for integration. Recommended: Phased integration starting with high-volume facilities, supported by intensive TA and mentorship over 12-18 months.";
} else {
    $ai_report[] = "?? County not ready for integration. Maintain vertical support while strengthening governance, HRH, infrastructure, and financial systems. Reassess in 12 months.";
}

// Count TWGs
$twg_count = 0;
if ($assessment['has_hiv_tb_twg'] === 'Yes') $twg_count++;
if ($assessment['has_pmtct_twg'] === 'Yes') $twg_count++;
if ($assessment['has_mnch_twg'] === 'Yes') $twg_count++;
if ($assessment['has_hiv_prevention_twg'] === 'Yes') $twg_count++;
if ($assessment['has_lab_twg'] === 'Yes') $twg_count++;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View County Assessment #<?= $id ?> | <?= htmlspecialchars($assessment['county_name'] ?? '') ?></title>
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
        .badge-purple { background: #6f42c1; color: #fff; }

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

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        .kpi-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            border: 1px solid #e0e4f0;
        }
        .kpi-value {
            font-size: 24px;
            font-weight: 800;
            color: #0D1A63;
        }
        .kpi-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            margin-top: 4px;
        }

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
            <i class="fas fa-landmark"></i>
            County Assessment #<?= $id ?>
            <span class="status-badge status-<?= strtolower($status) ?>"><?= $status ?></span>
        </h1>
        <div class="hdr-links">
            <a href="county_integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
            <?php if ($is_draft): ?>
            <a href="county_integration_assessment.php?id=<?= $id ?>" style="background: #28a745;">
                <i class="fas fa-edit"></i> Continue Editing
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-link">
        <a href="county_integration_assessment_list.php"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>

    <!-- Overall Score Card
    <div class="score-card">
        <div class="score-value"><?= round($total) ?>%</div>
        <div class="score-label">County Integration Readiness Score</div>
        <div class="badge badge-<?= $clr ?>" style="margin-top: 10px; font-size: 14px;"><?= $cat ?></div>
        <div class="progress" style="background: rgba(255,255,255,0.3); margin-top: 15px;">
            <div class="progress-bar bg-<?= $clr ?>" style="width: <?= $total ?>%"></div>
        </div>
    </div>  -->

    <!-- Key Metrics Dashboard -->
    <div class="card">
        <div class="card-head"><i class="fas fa-chart-line"></i> Key County Metrics</div>
        <div class="card-body">
            <div class="kpi-grid">
                <div class="kpi-card"><div class="kpi-value"><?= number_format($total_pepfar) ?></div><div class="kpi-label">HCWs PEPFAR Supported</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= number_format($total_transitioned) ?></div><div class="kpi-label">HCWs Transitioned to County</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $transition_percentage ?>%</div><div class="kpi-label">Transition Rate</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $emr_coverage ?>%</div><div class="kpi-label">EMR Coverage</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $twg_count ?></div><div class="kpi-label">Active TWGs</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= number_format($assessment['facilities_visited_ta'] ?? 0) ?></div><div class="kpi-label">Facilities Visited (TA)</div></div>
            </div>
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

    <!-- AI-Powered Integration Report -->
    <?php if (!empty($ai_report) && ($is_submitted || $is_complete)): ?>
    <div class="card">
        <div class="card-head"><i class="fas fa-robot"></i> AI-Powered County Integration Report</div>
        <div class="card-body">
            <ol style="margin-left: 20px;">
                <?php foreach ($ai_report as $line): ?>
                    <li style="margin-bottom: 10px;"><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <!-- Key Recommendations -->
    <div class="card">
        <div class="card-head"><i class="fas fa-lightbulb"></i> Strategic Recommendations</div>
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
                Overall Recommendation: <?= $cat ?> county with a readiness score of <?= round($total) ?>%.
                <?php if ($total >= 80): ?>
                Proceed with full integration across all facilities.
                <?php elseif ($total >= 60): ?>
                Proceed with phased integration starting with high-volume facilities.
                <?php elseif ($total >= 40): ?>
                Strengthen key areas before proceeding with integration.
                <?php else: ?>
                Maintain vertical support while building county capacity.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- County Profile -->
    <div class="card">
        <div class="card-head"><i class="fas fa-building"></i> County Profile</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Assessment Period</label><span><?= htmlspecialchars($assessment['assessment_period'] ?? '—') ?></span></div>
                <div class="info-item"><label>County Name</label><span><?= htmlspecialchars($assessment['county_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Implementing Agency</label><span><?= htmlspecialchars($assessment['agency_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Implementing Partner</label><span><?= htmlspecialchars($assessment['ip_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Collection Date</label><span><?= $assessment['collection_date'] ? date('d M Y', strtotime($assessment['collection_date'])) : '—' ?></span></div>
                <div class="info-item"><label>Collected By</label><span><?= htmlspecialchars($assessment['collected_by'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 2a: HIV/TB Services Integration -->
    <div class="card">
        <div class="card-head"><i class="fas fa-virus"></i> Section 2a: HIV/TB Services Integration</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HIV/TB Integration Plan</label><span class="badge <?= $assessment['hiv_tb_integration_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['hiv_tb_integration_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>Integration Meeting Held (last 3 months)</label><span class="badge <?= $assessment['hiv_tb_integration_meeting'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['hiv_tb_integration_meeting'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 2b: EMR Integration -->
    <div class="card">
        <div class="card-head"><i class="fas fa-laptop-medical"></i> Section 2b: EMR Integration</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Selected EMR Type</label><span><?= htmlspecialchars($assessment['selected_emr_type'] ?? '—') ?></span></div>
                <div class="info-item"><label>Other EMR Specify</label><span><?= htmlspecialchars($assessment['other_emr_specify'] ?? '—') ?></span></div>
                <div class="info-item"><label>EMR Deployment Meetings</label><span class="badge <?= $assessment['emr_deployment_meetings'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['emr_deployment_meetings'] ?? '—') ?></span></div>
                <div class="info-item"><label>HIS Integration Guide</label><span class="badge <?= $assessment['has_his_integration_guide'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_his_integration_guide'] ?? '—') ?></span></div>
                <div class="info-item"><label>Dedicated HIS Staff</label><span class="badge <?= $assessment['has_dedicated_his_staff'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_dedicated_his_staff'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 3: HRH Transition -->
    <div class="card">
        <div class="card-head"><i class="fas fa-users"></i> Section 3: HRH Transition</div>
        <div class="card-body">
            <div class="info-item" style="margin-bottom: 15px;">
                <label>HRH Transition Plan</label>
                <span class="badge <?= $assessment['has_hrh_transition_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_hrh_transition_plan'] ?? '—') ?></span>
            </div>
            <div class="sub-label">HCWs Supported by PEPFAR IP</div>
            <div class="info-grid">
                <div class="info-item"><label>Total HCWs</label><span><?= number_format($assessment['hcw_total_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Clinical Staff</label><span><?= number_format($assessment['hcw_clinical_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Non-Clinical Staff</label><span><?= number_format($assessment['hcw_nonclinical_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Data Staff</label><span><?= number_format($assessment['hcw_data_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Community Staff</label><span><?= number_format($assessment['hcw_community_pepfar'] ?? 0) ?></span></div>
                <div class="info-item"><label>Other Staff</label><span><?= number_format($assessment['hcw_other_pepfar'] ?? 0) ?></span></div>
            </div>
            <div class="sub-label">HCWs Transitioned to County Support</div>
            <div class="info-grid">
                <div class="info-item"><label>Total Transitioned</label><span><?= number_format($assessment['hcw_transitioned_total'] ?? 0) ?></span></div>
                <div class="info-item"><label>Clinical Staff</label><span><?= number_format($assessment['hcw_transitioned_clinical'] ?? 0) ?></span></div>
                <div class="info-item"><label>Non-Clinical Staff</label><span><?= number_format($assessment['hcw_transitioned_nonclinical'] ?? 0) ?></span></div>
                <div class="info-item"><label>Data Staff</label><span><?= number_format($assessment['hcw_transitioned_data'] ?? 0) ?></span></div>
                <div class="info-item"><label>Community Staff</label><span><?= number_format($assessment['hcw_transitioned_community'] ?? 0) ?></span></div>
                <div class="info-item"><label>Other Staff</label><span><?= number_format($assessment['hcw_transitioned_other'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 4: PLHIV Enrollment in SHA -->
    <div class="card">
        <div class="card-head"><i class="fas fa-id-card"></i> Section 4: PLHIV Enrollment in SHA</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>PLHIV SHA Plan</label><span class="badge <?= $assessment['has_plhiv_sha_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_plhiv_sha_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>PLHIV SHA Review Meeting</label><span class="badge <?= $assessment['plhiv_sha_review_meeting'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['plhiv_sha_review_meeting'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 5: TA & Mentorship -->
    <div class="card">
        <div class="card-head"><i class="fas fa-chalkboard-teacher"></i> Section 5: TA & Mentorship</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>TA Mentorship Plan</label><span class="badge <?= $assessment['has_ta_mentorship_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_ta_mentorship_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>County Mentorship Support</label><span><?= htmlspecialchars($assessment['county_mentorship_support'] ?? '—') ?></span></div>
                <div class="info-item"><label>Teams Involved</label><span><?= htmlspecialchars($assessment['mentorship_teams_involved'] ?? '—') ?></span></div>
                <div class="info-item"><label>Logistical Support Source</label><span><?= htmlspecialchars($assessment['logistical_support_source'] ?? '—') ?></span></div>
                <div class="info-item"><label>Uses Standardized MOH Tools</label><span class="badge <?= $assessment['uses_standardized_moh_tools'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['uses_standardized_moh_tools'] ?? '—') ?></span></div>
                <div class="info-item"><label>Facilities Visited (TA)</label><span><?= number_format($assessment['facilities_visited_ta'] ?? 0) ?></span></div>
                <div class="info-item"><label>TA Review Meeting Held</label><span class="badge <?= $assessment['ta_review_meeting'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['ta_review_meeting'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 6: Lab Support -->
    <div class="card">
        <div class="card-head"><i class="fas fa-flask"></i> Section 6: Lab Support</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>ISRS Operational Plan</label><span class="badge <?= $assessment['has_isrs_operational_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_isrs_operational_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>ISRS Funding Allocated</label><span class="badge <?= $assessment['isrs_funding_allocated'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['isrs_funding_allocated'] ?? '—') ?></span></div>
                <div class="info-item"><label>Lab Strategic Plan</label><span class="badge <?= $assessment['has_lab_strategic_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_lab_strategic_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>Lab Forecasting Conducted</label><span class="badge <?= $assessment['lab_forecasting_conducted'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['lab_forecasting_conducted'] ?? '—') ?></span></div>
                <div class="info-item"><label>Commodity Order Team</label><span class="badge <?= $assessment['has_commodity_order_team'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_commodity_order_team'] ?? '—') ?></span></div>
                <div class="info-item"><label>QMS Funding Allocated</label><span class="badge <?= $assessment['qms_funding_allocated'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['qms_funding_allocated'] ?? '—') ?></span></div>
                <div class="info-item"><label>Lab TWG</label><span class="badge <?= $assessment['has_lab_twg'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_lab_twg'] ?? '—') ?></span></div>
                <div class="info-item"><label>LMIS Integration Guide</label><span class="badge <?= $assessment['has_lmis_integration_guide'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_lmis_integration_guide'] ?? '—') ?></span></div>
                <div class="info-item"><label>POCT RTCQI Implementing</label><span class="badge <?= $assessment['poct_rtcqi_implementing'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['poct_rtcqi_implementing'] ?? '—') ?></span></div>
                <div class="info-item"><label>RTCQI Support Source</label><span><?= htmlspecialchars($assessment['rtcqi_support_source'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 7: TB Prevention Services -->
    <div class="card">
        <div class="card-head"><i class="fas fa-lungs"></i> Section 7: TB Prevention Services</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>TB Diagnostic TWG</label><span class="badge <?= $assessment['has_tb_diagnostic_twg'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_tb_diagnostic_twg'] ?? '—') ?></span></div>
                <div class="info-item"><label>TB TWG Activities Count</label><span><?= number_format($assessment['tb_twg_activities_count'] ?? 0) ?></span></div>
                <div class="info-item"><label>HCW Reached (TB Training)</label><span><?= number_format($assessment['hcw_reached_tb_training'] ?? 0) ?></span></div>
                <div class="info-item"><label>Chest X-ray in AWP</label><span class="badge <?= $assessment['chest_xray_in_awp'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['chest_xray_in_awp'] ?? '—') ?></span></div>
                <div class="info-item"><label>CXR Machines Licensed</label><span><?= number_format($assessment['cxr_machines_licensed'] ?? 0) ?></span></div>
                <div class="info-item"><label>CXR Machines Functional</label><span><?= number_format($assessment['cxr_machines_functional'] ?? 0) ?></span></div>
                <div class="info-item"><label>CXR Machines AI Enabled</label><span><?= number_format($assessment['cxr_machines_ai_enabled'] ?? 0) ?></span></div>
                <div class="info-item"><label>Facilities Using CXR for TB</label><span><?= number_format($assessment['facilities_using_cxr_tb'] ?? 0) ?></span></div>
                <div class="info-item"><label>CXR QA/QC Supported</label><span class="badge <?= $assessment['cxr_qa_qc_supported'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['cxr_qa_qc_supported'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 8: Technical Working Groups -->
    <div class="card">
        <div class="card-head"><i class="fas fa-users-cog"></i> Section 8: Technical Working Groups</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HIV Care & Treatment/TB TWG</label><span class="badge <?= $assessment['has_hiv_tb_twg'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_hiv_tb_twg'] ?? '—') ?></span></div>
                <div class="info-item"><label>HIV/TB TWG Meetings</label><span><?= number_format($assessment['hiv_tb_twg_meetings'] ?? 0) ?></span></div>
                <div class="info-item"><label>PMTCT TWG</label><span class="badge <?= $assessment['has_pmtct_twg'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_pmtct_twg'] ?? '—') ?></span></div>
                <div class="info-item"><label>PMTCT TWG Meetings</label><span><?= number_format($assessment['pmtct_twg_meetings'] ?? 0) ?></span></div>
                <div class="info-item"><label>MNCH TWG</label><span class="badge <?= $assessment['has_mnch_twg'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_mnch_twg'] ?? '—') ?></span></div>
                <div class="info-item"><label>MNCH TWG Meetings</label><span><?= number_format($assessment['mnch_twg_meetings'] ?? 0) ?></span></div>
                <div class="info-item"><label>HIV Prevention TWG</label><span class="badge <?= $assessment['has_hiv_prevention_twg'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_hiv_prevention_twg'] ?? '—') ?></span></div>
                <div class="info-item"><label>HIV Prevention TWG Meetings</label><span><?= number_format($assessment['hiv_prevention_twg_meetings'] ?? 0) ?></span></div>
                <div class="info-item"><label>Integration Oversight Team</label><span class="badge <?= $assessment['has_integration_oversight_team'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_integration_oversight_team'] ?? '—') ?></span></div>
                <div class="info-item"><label>Integration Oversight Meeting</label><span class="badge <?= $assessment['integration_oversight_meeting'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['integration_oversight_meeting'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 9: Financing & Sustainability -->
    <div class="card">
        <div class="card-head"><i class="fas fa-coins"></i> Section 9: Financing & Sustainability</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>FIF Collection Plan</label><span class="badge <?= $assessment['has_fif_collection_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_fif_collection_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>Receives SHA Capitation</label><span class="badge <?= $assessment['receives_sha_capitation'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['receives_sha_capitation'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 10: Stakeholder Engagement -->
    <div class="card">
        <div class="card-head"><i class="fas fa-handshake"></i> Section 10: Stakeholder Engagement</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>Stakeholder Engagement Plan</label><span class="badge <?= $assessment['has_stakeholder_engagement_plan'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_stakeholder_engagement_plan'] ?? '—') ?></span></div>
                <div class="info-item"><label>Stakeholder Meetings Count</label><span><?= number_format($assessment['stakeholder_meetings_count'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 11: Mortality Outcomes -->
    <div class="card">
        <div class="card-head"><i class="fas fa-heartbeat"></i> Section 11: Mortality Outcomes</div>
        <div class="card-body">
            <div class="info-item">
                <label>Days Without Maternal Deaths</label>
                <span><?= number_format($assessment['days_without_maternal_deaths'] ?? 0) ?> days</span>
            </div>
        </div>
    </div>

    <!-- Section 12: AHD -->
    <div class="card">
        <div class="card-head"><i class="fas fa-microscope"></i> Section 12: Advanced HIV Disease (AHD)</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>AHD Hubs Available</label><span><?= number_format($assessment['ahd_hubs_available'] ?? 0) ?></span></div>
                <div class="info-item"><label>AHD Hubs Activated</label><span><?= number_format($assessment['ahd_hubs_activated'] ?? 0) ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 13: Governance -->
    <div class="card">
        <div class="card-head"><i class="fas fa-gavel"></i> Section 13: Governance</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HIV Integration Oversight Team</label><span class="badge <?= $assessment['has_hiv_integration_oversight'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_hiv_integration_oversight'] ?? '—') ?></span></div>
                <div class="info-item"><label>Oversight Meeting Held</label><span class="badge <?= $assessment['integration_oversight_meeting_held'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['integration_oversight_meeting_held'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 14: Supply Chain -->
    <div class="card">
        <div class="card-head"><i class="fas fa-boxes"></i> Section 14: Supply Chain</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HPT Unit</label><span class="badge <?= $assessment['has_hpt_unit'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_hpt_unit'] ?? '—') ?></span></div>
                <div class="info-item"><label>HPT TWG Meeting Held</label><span class="badge <?= $assessment['hpt_twg_meeting_held'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['hpt_twg_meeting_held'] ?? '—') ?></span></div>
                <div class="info-item"><label>Valid F&Q Report</label><span class="badge <?= $assessment['has_valid_fq_report'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['has_valid_fq_report'] ?? '—') ?></span></div>
                <div class="info-item"><label>Supply Chain Training</label><span class="badge <?= $assessment['provides_supply_chain_training'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['provides_supply_chain_training'] ?? '—') ?></span></div>
            </div>
        </div>
    </div>

    <!-- Section 15: Primary Health Care -->
    <div class="card">
        <div class="card-head"><i class="fas fa-clinic-medical"></i> Section 15: Primary Health Care</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>HIV/TB in PHC Plans</label><span class="badge <?= $assessment['hiv_tb_in_phc_plans'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['hiv_tb_in_phc_plans'] ?? '—') ?></span></div>
                <div class="info-item"><label>PHC HIV Review Meeting</label><span class="badge <?= $assessment['phc_hiv_review_meeting'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['phc_hiv_review_meeting'] ?? '—') ?></span></div>
                <div class="info-item"><label>PHC Service Delivery Operationalized</label><span class="badge <?= $assessment['phc_service_delivery_operationalized'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($assessment['phc_service_delivery_operationalized'] ?? '—') ?></span></div>
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
            <a href="county_integration_assessment.php?id=<?= $id ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Continue Editing
            </a>
        <?php endif; ?>

        <?php if ($is_submitted || $is_complete): ?>
            <a href="county_integration_workplan.php?id=<?= $id ?>" class="btn btn-success">
                <i class="fas fa-robot"></i> Generate AI Report & Workplan
            </a>
        <?php endif; ?>

        <a href="county_integration_assessment_list.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
    </div>
</div>
</body>
</html>