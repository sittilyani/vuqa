<?php
// transitions/view_transition.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$assessment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$assessment_id) {
    header('Location: transition_index.php');
    exit();
}

// Get assessment details
$assessment_query = "
    SELECT ta.*, c.county_name
    FROM transition_assessments ta
    JOIN counties c ON ta.county_id = c.county_id
    WHERE ta.assessment_id = $assessment_id
";
$assessment_result = mysqli_query($conn, $assessment_query);
if (mysqli_num_rows($assessment_result) == 0) {
    header('Location: transition_index.php');
    exit();
}
$assessment = mysqli_fetch_assoc($assessment_result);

// Get all scores with section and indicator information
$scores_query = "
    SELECT
        ts.*,
        ti.indicator_code,
        ti.indicator_text,
        ti.verification_guidance,
        ti.max_score,
        ts2.section_id,
        ts2.section_code,
        ts2.section_name,
        ts2.section_category
    FROM transition_raw_scores ts
    JOIN transition_indicators ti ON ts.indicator_id = ti.indicator_id
    JOIN transition_sections ts2 ON ti.section_id = ts2.section_id
    WHERE ts.assessment_id = $assessment_id
    ORDER BY ts2.display_order, ti.display_order
";
$scores_result = mysqli_query($conn, $scores_query);

// Group scores by section
$sections = [];
while ($row = mysqli_fetch_assoc($scores_result)) {
    $section_id = $row['section_id'];
    if (!isset($sections[$section_id])) {
        $sections[$section_id] = [
            'section_code' => $row['section_code'],
            'section_name' => $row['section_name'],
            'section_category' => $row['section_category'],
            'indicators' => [],
            'total_cdoh' => 0,
            'total_ip' => 0,
            'indicator_count' => 0
        ];
    }
    $sections[$section_id]['indicators'][] = $row;
    $sections[$section_id]['total_cdoh'] += $row['cdoh_score'] ?? 0;
    $sections[$section_id]['total_ip'] += $row['ip_score'] ?? 0;
    $sections[$section_id]['indicator_count']++;
}

// Calculate section averages
foreach ($sections as &$section) {
    $section['avg_cdoh'] = $section['indicator_count'] > 0 ?
        round(($section['total_cdoh'] / ($section['indicator_count'] * 4)) * 100, 1) : 0;
    $section['avg_ip'] = $section['indicator_count'] > 0 ?
        round(($section['total_ip'] / ($section['indicator_count'] * 4)) * 100, 1) : 0;
}

// Scoring criteria descriptions
$score_labels = [
    4 => ['label' => 'Fully adequate with evidence', 'class' => 'score-4'],
    3 => ['label' => 'Partially adequate with evidence', 'class' => 'score-3'],
    2 => ['label' => 'Structures/functions defined some evidence', 'class' => 'score-2'],
    1 => ['label' => 'Structures/functions defined NO evidence', 'class' => 'score-1'],
    0 => ['label' => 'Inadequate structures/functions', 'class' => 'score-0']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Assessment - <?= htmlspecialchars($assessment['county_name']) ?></title>
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

        .summary-card {
            background: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 24px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
            align-items: center;
        }
        .summary-item {
            flex: 1;
            min-width: 150px;
        }
        .summary-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .summary-value {
            font-size: 28px;
            font-weight: 800;
            color: #0D1A63;
        }
        .readiness-badge {
            display: inline-block;
            padding: 6px 20px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
        }
        .badge-transition { background: #d4edda; color: #155724; }
        .badge-support { background: #fff3cd; color: #856404; }
        .badge-not-ready { background: #f8d7da; color: #721c24; }

        .section-card {
            background: #fff;
            border-radius: 14px;
            margin-bottom: 20px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            overflow: hidden;
            border-left: 4px solid var(--color);
        }
        .section-header {
            background: linear-gradient(90deg, #f8fafc, #fff);
            padding: 16px 22px;
            border-bottom: 1px solid #e8ecf5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #0D1A63;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-score {
            display: flex;
            gap: 20px;
        }
        .score-box {
            text-align: center;
            min-width: 80px;
        }
        .score-box-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #666;
        }
        .score-box-value {
            font-size: 20px;
            font-weight: 700;
        }
        .score-box-value.cdoh { color: #0D1A63; }
        .score-box-value.ip { color: #FFC107; }

        .table-responsive {
            overflow-x: auto;
            padding: 0 22px 22px 22px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 12px 10px;
            background: #f8fafc;
            color: #0D1A63;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 2px solid #e0e4f0;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #e8ecf5;
            vertical-align: middle;
        }
        tr:hover td {
            background: #f8faff;
        }

        .score-indicator {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            font-weight: 700;
            color: #fff;
        }
        .score-4 { background: #28a745; }
        .score-3 { background: #17a2b8; }
        .score-2 { background: #ffc107; color: #333; }
        .score-1 { background: #fd7e14; }
        .score-0 { background: #dc3545; }

        .gap-indicator {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .gap-high { background: #f8d7da; color: #721c24; }
        .gap-medium { background: #fff3cd; color: #856404; }
        .gap-low { background: #d4edda; color: #155724; }

        .comments-box {
            background: #f8f9fc;
            border-left: 3px solid #0D1A63;
            padding: 10px 15px;
            margin-top: 5px;
            border-radius: 5px;
            font-size: 12px;
            color: #555;
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

        .actions-bar {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
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
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover { background: #e0a800; }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>
            <i class="fas fa-clipboard-check"></i>
            Assessment Details: <?= htmlspecialchars($assessment['county_name']) ?>
        </h1>
        <div class="hdr-links">
            <a href="transition_index.php"><i class="fas fa-plus"></i> New</a>
            <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        </div>
    </div>

    <div class="back-link">
        <a href="transition_dashboard.php?county=<?= $assessment['county_id'] ?>">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <!-- Summary Card -->
    <div class="summary-card">
        <div class="summary-item">
            <div class="summary-label">Assessment Period</div>
            <div class="summary-value"><?= htmlspecialchars($assessment['assessment_period']) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Assessment Date</div>
            <div class="summary-value"><?= date('d M Y', strtotime($assessment['assessment_date'])) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Assessed By</div>
            <div class="summary-value"><?= htmlspecialchars($assessment['assessed_by'] ?? 'N/A') ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Overall CDOH Score</div>
            <div class="summary-value"><?= $assessment['overall_cdoh_score'] ?>%</div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Overall IP Score</div>
            <div class="summary-value" style="color: #FFC107;"><?= $assessment['overall_ip_score'] ?>%</div>
        </div>
        <div class="summary-item">
            <div class="summary-label">Readiness Level</div>
            <div>
                <span class="readiness-badge
                    <?= $assessment['overall_cdoh_score'] >= 70 ? 'badge-transition' :
                       ($assessment['overall_cdoh_score'] >= 50 ? 'badge-support' : 'badge-not-ready') ?>">
                    <?= $assessment['readiness_level'] ?? 'Not Rated' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Sections -->
    <?php foreach ($sections as $section_id => $section):
        $color = $section['avg_cdoh'] >= 70 ? '#28a745' : ($section['avg_cdoh'] >= 50 ? '#ffc107' : '#dc3545');
    ?>
    <div class="section-card" style="--color: <?= $color ?>">
        <div class="section-header">
            <div class="section-title">
                <i class="fas fa-folder"></i>
                <?= htmlspecialchars($section['section_name']) ?>
                <span style="font-size: 12px; font-weight: 400; color: #999; margin-left: 10px;">
                    <?= $section['section_code'] ?>
                </span>
            </div>
            <div class="section-score">
                <div class="score-box">
                    <div class="score-box-label">CDOH</div>
                    <div class="score-box-value cdoh"><?= $section['avg_cdoh'] ?>%</div>
                </div>
                <div class="score-box">
                    <div class="score-box-label">IP</div>
                    <div class="score-box-value ip"><?= $section['avg_ip'] ?>%</div>
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Indicator</th>
                        <th style="width: 80px;">CDOH</th>
                        <th style="width: 80px;">IP</th>
                        <th style="width: 100px;">Gap</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($section['indicators'] as $indicator):
                        $cdoh = $indicator['cdoh_score'] ?? 0;
                        $ip = $indicator['ip_score'] ?? 0;
                        $gap = $ip - $cdoh;
                        $gap_class = $gap >= 3 ? 'gap-high' : ($gap >= 2 ? 'gap-medium' : 'gap-low');
                    ?>
                    <tr>
                        <td>
                            <strong><?= $indicator['indicator_code'] ?></strong><br>
                            <span style="font-size: 12px; color: #666;"><?= htmlspecialchars($indicator['indicator_text']) ?></span>
                        </td>
                        <td>
                            <span class="score-indicator score-<?= $cdoh ?>"><?= $cdoh ?></span>
                        </td>
                        <td>
                            <?php if ($indicator['ip_score'] !== null): ?>
                            <span class="score-indicator score-<?= $ip ?>"><?= $ip ?></span>
                            <?php else: ?>
                            <span style="color: #999;">�</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ip !== null): ?>
                            <span class="gap-indicator <?= $gap_class ?>">
                                <?= $gap > 0 ? '+' . $gap : $gap ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($indicator['comments'])): ?>
                            <div class="comments-box">
                                <?= nl2br(htmlspecialchars($indicator['comments'])) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Actions -->
    <div class="actions-bar">
        <a href="edit_transition.php?id=<?= $assessment_id ?>" class="btn btn-primary">
            <i class="fas fa-edit"></i> Edit Assessment
        </a>
        <a href="transition_dashboard.php?county=<?= $assessment['county_id'] ?>" class="btn btn-secondary">
            <i class="fas fa-chart-bar"></i> View County Dashboard
        </a>
        <a href="transition_index.php" class="btn btn-secondary">
            <i class="fas fa-home"></i> Home
        </a>
    </div>
</div>
</body>
</html>