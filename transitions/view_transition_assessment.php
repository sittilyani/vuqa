<?php
// transitions/view_transition_assessment.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: transition_dashboard.php');
    exit();
}

// Get user role for permission checks
$user_role = $_SESSION['role'] ?? '';
$is_admin = in_array($user_role, ['Admin', 'Super Admin']);

// Get main assessment
$query = "SELECT ta.*, c.county_name
          FROM transition_assessments ta
          JOIN counties c ON ta.county_id = c.county_id
          WHERE ta.assessment_id = $id";
$result = mysqli_query($conn, $query);
if (mysqli_num_rows($result) == 0) {
    header('Location: transition_dashboard.php');
    exit();
}
$assessment = mysqli_fetch_assoc($result);

$status = $assessment['assessment_status'] ?? 'draft';
$is_submitted = ($status === 'submitted');
$is_draft = ($status === 'draft');
$readiness_level = $assessment['readiness_level'] ?? 'Not Rated';

// Get section submissions
$sections_query = "
    SELECT tss.*,
           ROUND(tss.cdoh_percent, 1) as cdoh_pct,
           ROUND(tss.ip_percent, 1) as ip_pct,
           ROUND(tss.cdoh_ip_overlap, 1) as overlap_pct,
           ROUND(GREATEST(0, tss.ip_percent - tss.cdoh_percent), 1) as gap_pct
    FROM transition_section_submissions tss
    WHERE tss.assessment_id = $id
    ORDER BY tss.section_key
";
$sections_result = mysqli_query($conn, $sections_query);
$sections_data = [];
if ($sections_result) {
    while ($row = mysqli_fetch_assoc($sections_result)) {
        $sections_data[$row['section_key']] = $row;
    }
}

// Section labels
$section_labels = [
    'leadership' => 'Leadership & Governance',
    'supervision' => 'Supervision & Mentorship',
    'special_initiatives' => 'Special Initiatives',
    'quality_improvement' => 'Quality Improvement',
    'identification_linkage' => 'Patient Identification & Linkage',
    'retention_suppression' => 'Patient Retention & Viral Suppression',
    'prevention_kp' => 'Prevention & Key Populations',
    'finance' => 'Financial Management',
    'sub_grants' => 'Sub-Grants Management',
    'commodities' => 'Commodities Management',
    'equipment' => 'Equipment Management',
    'laboratory' => 'Laboratory Services',
    'inventory' => 'Inventory Management',
    'training' => 'In-Service Training',
    'hr_management' => 'Human Resource Management',
    'data_management' => 'Data Management',
    'patient_monitoring' => 'Patient Monitoring',
    'institutional_ownership' => 'Institutional Ownership'
];

// Calculate overall scores
$cdoh_scores = array_filter(array_column($sections_data, 'cdoh_pct'));
$ip_scores = array_filter(array_column($sections_data, 'ip_pct'));
$avg_cdoh = count($cdoh_scores) > 0 ? round(array_sum($cdoh_scores) / count($cdoh_scores), 1) : 0;
$avg_ip = count($ip_scores) > 0 ? round(array_sum($ip_scores) / count($ip_scores), 1) : 0;

// Determine readiness category
if ($avg_cdoh >= 70) {
    $readiness_cat = 'Transition Ready';
    $readiness_color = 'success';
} elseif ($avg_cdoh >= 50) {
    $readiness_cat = 'Support and Monitor';
    $readiness_color = 'warning';
} else {
    $readiness_cat = 'Not Ready';
    $readiness_color = 'danger';
}

// Count sections needing attention
$critical_sections = 0;
$high_priority_sections = 0;
foreach ($sections_data as $section) {
    if ($section['cdoh_pct'] < 40) $critical_sections++;
    elseif ($section['cdoh_pct'] < 60) $high_priority_sections++;
}

// AI Report generation
$ai_report = [];

// Overall readiness assessment
if ($avg_cdoh >= 70) {
    $ai_report[] = "? The county demonstrates strong transition readiness with an overall CDOH score of {$avg_cdoh}%. IP support can be phased out over the next 6 months.";
} elseif ($avg_cdoh >= 50) {
    $ai_report[] = "?? The county shows moderate transition readiness ({$avg_cdoh}%). A phased transition over 9-12 months with continued IP mentorship is recommended.";
} else {
    $ai_report[] = "?? The county has low transition readiness ({$avg_cdoh}%). Significant capacity building and IP support will be required over 12-18 months.";
}

// Critical sections analysis
if ($critical_sections > 0) {
    $ai_report[] = "?? {$critical_sections} section(s) have critically low CDOH scores (<40%). These require immediate attention and intensive capacity building.";
}
if ($high_priority_sections > 0) {
    $ai_report[] = "?? {$high_priority_sections} section(s) have moderate-low scores (40-60%). Prioritize these for phased capacity strengthening.";
}

// Section-specific insights
$section_insights = [
    'leadership' => 'Leadership commitment is essential for driving transition.',
    'finance' => 'Financial management capacity is critical for sustainability.',
    'hr_management' => 'HRH transition requires county public service board engagement.',
    'data_management' => 'Data systems need strengthening before IP exit.',
    'commodities' => 'Commodity supply chain requires county system integration.',
    'laboratory' => 'Lab services need quality management systems strengthening.',
    'institutional_ownership' => 'Institutional ownership indicates sustainability readiness.'
];

foreach ($sections_data as $key => $section) {
    if ($section['cdoh_pct'] < 40 && isset($section_insights[$key])) {
        $ai_report[] = "?? {$section_labels[$key]}: {$section_insights[$key]} Current score: {$section['cdoh_pct']}%.";
    }
}

// Gap analysis
$largest_gap = 0;
$largest_gap_section = '';
foreach ($sections_data as $key => $section) {
    if ($section['gap_pct'] > $largest_gap) {
        $largest_gap = $section['gap_pct'];
        $largest_gap_section = $section_labels[$key];
    }
}
if ($largest_gap > 0) {
    $ai_report[] = "?? The largest gap between IP involvement and CDOH autonomy is in {$largest_gap_section} ({$largest_gap}%). Focus transition efforts here.";
}

// IP exit recommendation
$ip_exit_ready = [];
$ip_exit_needs = [];
foreach ($sections_data as $key => $section) {
    if ($section['cdoh_pct'] >= 70) {
        $ip_exit_ready[] = $section_labels[$key];
    } elseif ($section['cdoh_pct'] < 50) {
        $ip_exit_needs[] = $section_labels[$key];
    }
}
if (!empty($ip_exit_ready)) {
    $ai_report[] = "? Sections ready for immediate IP exit: " . implode(', ', array_slice($ip_exit_ready, 0, 5)) . (count($ip_exit_ready) > 5 ? ' and others' : '');
}
if (!empty($ip_exit_needs)) {
    $ai_report[] = "?? Sections requiring continued IP support: " . implode(', ', array_slice($ip_exit_needs, 0, 5)) . (count($ip_exit_needs) > 5 ? ' and others' : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Transition Assessment #<?= $id ?> | <?= htmlspecialchars($assessment['county_name'] ?? '') ?></title>
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

        .progress { height: 8px; background: #eee; border-radius: 5px; margin: 10px 0; }
        .progress-bar { height: 100%; border-radius: 5px; transition: width 0.3s; }
        .bg-success { background: #28a745; }
        .bg-warning { background: #ffc107; }
        .bg-danger { background: #dc3545; }

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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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

        .scores-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .scores-table th {
            background: #f8fafc;
            padding: 10px 12px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #666;
            border-bottom: 2px solid #e0e4f0;
        }
        .scores-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e8ecf5;
            vertical-align: middle;
        }
        .scores-table tr:hover td {
            background: #f8faff;
        }

        .pct-bar {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pct-track {
            flex: 1;
            height: 8px;
            background: #f0f0f0;
            border-radius: 99px;
            overflow: hidden;
        }
        .pct-fill {
            height: 100%;
            border-radius: 99px;
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
        .btn-secondary { background: #f3f4f6; color: #666; }
        .btn-secondary:hover { background: #e5e7eb; }

        @media (max-width: 768px) {
            .container { padding: 12px; }
            .info-grid { grid-template-columns: 1fr; }
            .actions-bar { justify-content: center; }
            .scores-table { font-size: 11px; }
            .scores-table th, .scores-table td { padding: 6px 8px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>
            <i class="fas fa-chart-line"></i>
            Transition Assessment #<?= $id ?>
            <span class="status-badge status-<?= strtolower($status) ?>"><?= ucfirst($status) ?></span>
        </h1>
        <div class="hdr-links">
            <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <?php if ($is_draft && $is_admin): ?>
            <a href="transition_assessment.php?id=<?= $id ?>" style="background: #28a745;">
                <i class="fas fa-edit"></i> Continue Editing
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-link">
        <a href="transition_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <!-- Overall Score Card -->
    <div class="score-card">
        <div class="score-value"><?= $avg_cdoh ?>%</div>
        <div class="score-label">Overall CDOH Transition Readiness Score</div>
        <div class="badge badge-<?= $readiness_color ?>" style="margin-top: 10px; font-size: 14px;"><?= $readiness_cat ?></div>
        <div class="progress" style="background: rgba(255,255,255,0.3); margin-top: 15px;">
            <div class="progress-bar bg-<?= $readiness_color ?>" style="width: <?= $avg_cdoh ?>%"></div>
        </div>
    </div>

    <!-- Key Metrics Dashboard -->
    <div class="card">
        <div class="card-head"><i class="fas fa-chart-line"></i> Assessment Summary</div>
        <div class="card-body">
            <div class="kpi-grid">
                <div class="kpi-card"><div class="kpi-value"><?= count($sections_data) ?></div><div class="kpi-label">Sections Assessed</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $avg_cdoh ?>%</div><div class="kpi-label">Avg CDOH Score</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $avg_ip ?>%</div><div class="kpi-label">Avg IP Score</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $critical_sections ?></div><div class="kpi-label">Critical Sections (&lt;40%)</div></div>
                <div class="kpi-card"><div class="kpi-value"><?= $high_priority_sections ?></div><div class="kpi-label">Priority Sections (40-60%)</div></div>
            </div>
        </div>
    </div>

    <!-- AI-Powered Report -->
    <?php if ($is_submitted): ?>
    <div class="card">
        <div class="card-head"><i class="fas fa-robot"></i> AI-Powered Transition Report</div>
        <div class="card-body">
            <ol style="margin-left: 20px;">
                <?php foreach ($ai_report as $line): ?>
                    <li style="margin-bottom: 10px;"><?= htmlspecialchars($line) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section Scores Table -->
    <div class="card">
        <div class="card-head"><i class="fas fa-table"></i> Section Scores Breakdown</div>
        <div class="card-body" style="overflow-x: auto;">
            <table class="scores-table">
                <thead>
                    <tr>
                        <th>Section</th>
                        <th>CDOH %</th>
                        <th>IP %</th>
                        <th>Overlap</th>
                        <th>Gap</th>
                        <th>Status</th>
                    </thead>
                <tbody>
                    <?php foreach ($sections_data as $key => $section):
                        $cdoh = $section['cdoh_pct'];
                        $ip = $section['ip_pct'];
                        $overlap = $section['overlap_pct'];
                        $gap = $section['gap_pct'];
                        $status_color = $cdoh >= 70 ? '#28a745' : ($cdoh >= 50 ? '#ffc107' : '#dc3545');
                        $status_text = $cdoh >= 70 ? 'Transition Ready' : ($cdoh >= 50 ? 'Needs Support' : 'Critical');
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($section_labels[$key] ?? $key) ?></strong></td>
                        <td>
                            <div class="pct-bar">
                                <div class="pct-track"><div class="pct-fill" style="width: <?= $cdoh ?>%;background:<?= $status_color ?>"></div></div>
                                <span style="font-weight:700;color:<?= $status_color ?>;min-width:38px"><?= $cdoh ?>%</span>
                            </div>
                        </td>
                        <td style="font-weight:600;color:#b8860b"><?= $ip ?>%</td>
                        <td style="font-weight:600;color:#27AE60"><?= $overlap ?>%</td>
                        <td style="font-weight:600;color:#dc3545"><?= $gap ?>%</td>
                        <td><span class="badge badge-<?= $cdoh >= 70 ? 'success' : ($cdoh >= 50 ? 'warning' : 'danger') ?>" style="font-size:10px"><?= $status_text ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Assessment Information -->
    <div class="card">
        <div class="card-head"><i class="fas fa-info-circle"></i> Assessment Information</div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>County</label><span><?= htmlspecialchars($assessment['county_name'] ?? '—') ?></span></div>
                <div class="info-item"><label>Assessment Period</label><span><?= htmlspecialchars($assessment['assessment_period'] ?? '—') ?></span></div>
                <div class="info-item"><label>Assessment Date</label><span><?= $assessment['assessment_date'] ? date('d M Y', strtotime($assessment['assessment_date'])) : '—' ?></span></div>
                <div class="info-item"><label>Assessed By</label><span><?= htmlspecialchars($assessment['assessed_by'] ?? '—') ?></span></div>
                <div class="info-item"><label>Readiness Level</label><span><?= htmlspecialchars($assessment['readiness_level'] ?? '—') ?></span></div>
                <div class="info-item"><label>Created At</label><span><?= $assessment['created_at'] ? date('d M Y H:i', strtotime($assessment['created_at'])) : '—' ?></span></div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="actions-bar">
        <?php if ($is_draft && $is_admin): ?>
            <a href="transition_assessment.php?id=<?= $id ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Continue Editing
            </a>
        <?php endif; ?>

        <?php if ($is_submitted): ?>
            <a href="transition_workplan.php?assessment_id=<?= $id ?>" class="btn btn-success">
                <i class="fas fa-robot"></i> Generate AI Workplan
            </a>
        <?php endif; ?>

        <a href="transition_dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>
</body>
</html>