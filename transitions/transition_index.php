<?php
// transitions/transition_index.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get counties for dropdown
$counties = $conn->query("SELECT county_id, county_name FROM counties ORDER BY county_name");

// Get assessment periods - last 8 quarters
$quarters = [];
for ($i = 0; $i < 8; $i++) {
    $year = date('Y') - floor($i/4);
    $q_num = 4 - ($i % 4);
    $quarters[] = "Q$q_num $year";
}

// Get saved assessments for quick load - FIXED: Use correct table and column names
$saved_assessments = $conn->query("
    SELECT ta.assessment_id, c.county_name, ta.assessment_period,
           ta.overall_cdoh_score as total_score, ta.readiness_level as level,
           ta.created_at
    FROM transition_assessments ta
    JOIN counties c ON ta.county_id = c.county_id
    ORDER BY ta.created_at DESC
    LIMIT 10
");

// Sections definition
$sections = [
    'leadership' => [
        'title' => 'COUNTY LEVEL LEADERSHIP AND GOVERNANCE',
        'icon' => 'fa-landmark',
        'color' => '#0D1A63',
        'indicators' => [
            'T1' => 'Transition of County Legislature Health Leadership and Governance',
            'T2' => 'Transition of County Executive (CHMT) in Health Leadership and Governance',
            'T3' => 'Transition of County Health Planning: Level of Autonomy of the CDOH'
        ]
    ],
    'supervision' => [
        'title' => 'COUNTY LEVEL ROUTINE SUPERVISION AND MENTORSHIP',
        'icon' => 'fa-clipboard-check',
        'color' => '#1a3a9e',
        'indicators' => [
            'T4A' => 'Transition of routine Supervision and Mentorship: Level of Involvement of the IP',
            'T4B' => 'Transition of routine Supervision and mentorship: Level of Autonomy of the CDOH'
        ]
    ],
    'special_initiatives' => [
        'title' => 'COUNTY LEVEL HIV/TB PROGRAM SPECIAL INITIATIVES (RRI, Leap, Surge, SIMS)',
        'icon' => 'fa-bolt',
        'color' => '#2a4ab0',
        'indicators' => [
            'T5A' => 'Transition of HIV/TB program special initiatives: Level of Involvement of the IP',
            'T5B' => 'Transition of HIV program special initiatives: Level of Autonomy of the CDOH'
        ]
    ],
    'quality_improvement' => [
        'title' => 'COUNTY LEVEL QUALITY IMPROVEMENT',
        'icon' => 'fa-chart-line',
        'color' => '#3a5ac8',
        'indicators' => [
            'T6A' => 'Transition of Quality Improvement (QI): Level of Involvement of the IP',
            'T6B' => 'Transition of Quality Improvement: Level of Autonomy of the CDOH'
        ]
    ],
    'identification_linkage' => [
        'title' => 'COUNTY LEVEL HIV/TB PATIENT IDENTIFICATION AND LINKAGE TO TREATMENT',
        'icon' => 'fa-user-plus',
        'color' => '#4a6ae0',
        'indicators' => [
            'T7A' => 'Transition of Patient identification and linkage to treatment: Level of Involvement of the IP',
            'T7B' => 'Transition of Patient identification and linkage to treatment: Level of Autonomy of the CDOH'
        ]
    ],
    'retention_suppression' => [
        'title' => 'COUNTY LEVEL PATIENT RETENTION, ADHERENCE AND VIRAL SUPPRESSION SERVICES',
        'icon' => 'fa-heartbeat',
        'color' => '#5a7af8',
        'indicators' => [
            'T8A' => 'Transition of Patient retention, adherence and Viral suppression services: Level of Involvement of the IP',
            'T8B' => 'Transition of Patient retention, adherence and Viral suppression services: Level of Autonomy of the CDOH'
        ]
    ],
    'prevention_kp' => [
        'title' => 'COUNTY LEVEL HIV PREVENTION AND KEY POPULATION SERVICES',
        'icon' => 'fa-shield-alt',
        'color' => '#6a8aff',
        'indicators' => [
            'T9A' => 'Transition of HIV/TB prevention and Key population services: Level of Involvement of the IP',
            'T9B' => 'Transition of HIV prevention and Key population services: Level of Autonomy of the CDOH'
        ]
    ],
    'finance' => [
        'title' => 'COUNTY LEVEL FINANCE MANAGEMENT',
        'icon' => 'fa-coins',
        'color' => '#7a9aff',
        'indicators' => [
            'T10A' => 'Transition of Financial Management: Level of Involvement of the IP',
            'T10B' => 'Transition of Financial Management: Level of Autonomy of the CDOH'
        ]
    ],
    'sub_grants' => [
        'title' => 'COUNTY LEVEL MANAGING SUB-GRANTS',
        'icon' => 'fa-file-invoice',
        'color' => '#8a5cf6',
        'indicators' => [
            'T11A' => 'Transition of Managing Sub-Grants: Level of Involvement of the IP',
            'T11B' => 'Transition of Managing Sub-Grants: Level of Autonomy of the CDOH'
        ]
    ],
    'commodities' => [
        'title' => 'COUNTY LEVEL COMMODITIES MANAGEMENT',
        'icon' => 'fa-boxes',
        'color' => '#9b6cf6',
        'indicators' => [
            'T12A' => 'Transition of Commodities Management: Level of Involvement of the IP',
            'T12B' => 'Transition of Commodities Management: Level of Autonomy of the CDOH'
        ]
    ],
    'equipment' => [
        'title' => 'COUNTY LEVEL EQUIPMENT PROCUREMENT AND USE',
        'icon' => 'fa-tools',
        'color' => '#ac7cf6',
        'indicators' => [
            'T13A' => 'Transition of Equipment Procurement and Use: Level of Involvement of the IP',
            'T13B' => 'Transition of Procurement and Use: Level of Autonomy of the CDOH'
        ]
    ],
    'laboratory' => [
        'title' => 'COUNTY LEVEL LABORATORY SERVICES',
        'icon' => 'fa-flask',
        'color' => '#bd8cf6',
        'indicators' => [
            'T14A' => 'Transition of Laboratory Services: Level of Involvement of the IP',
            'T14B' => 'Transition of Laboratory Services: Level of Autonomy of the CDOH'
        ]
    ],
    'inventory' => [
        'title' => 'COUNTY LEVEL INVENTORY MANAGEMENT',
        'icon' => 'fa-clipboard-list',
        'color' => '#ce9cf6',
        'indicators' => [
            'T15A' => 'Transition of Inventory Management for Equipment & Commodities: Level of Involvement of the IP',
            'T15B' => 'Transition of Inventory Management for Equipment & Commodities: Level of Autonomy of the CDOH'
        ]
    ],
    'training' => [
        'title' => 'COUNTY LEVEL IN-SERVICE TRAINING',
        'icon' => 'fa-chalkboard-teacher',
        'color' => '#dfacf6',
        'indicators' => [
            'T16A' => 'Transition of In-service Training: Level of Involvement of the IP',
            'T16B' => 'Transition of In-service Training: Level of Autonomy of the CDOH'
        ]
    ],
    'hr_management' => [
        'title' => 'COUNTY LEVEL HUMAN RESOURCE MANAGEMENT',
        'icon' => 'fa-users',
        'color' => '#f0bcf6',
        'indicators' => [
            'T17A' => 'Transition of HIV/TB Human Resource Management: Level of Involvement of the IP',
            'T17B' => 'Transition of HIV/TB Human Resource Management: Level of Autonomy of the CDOH'
        ]
    ],
    'data_management' => [
        'title' => 'COUNTY LEVEL HIV/TB PROGRAM DATA MANAGEMENT',
        'icon' => 'fa-database',
        'color' => '#0ABFBC',
        'indicators' => [
            'T18A' => 'Transition of HIV/TB Program Data Management: Level of Involvement of the IP',
            'T18B' => 'Transition of HIV/TB Program Data Management: Level of Autonomy of the CDOH'
        ]
    ],
    'patient_monitoring' => [
        'title' => 'COUNTY LEVEL PATIENT MONITORING SYSTEM',
        'icon' => 'fa-chart-pie',
        'color' => '#27AE60',
        'indicators' => [
            'T19A' => 'Transition of Patient Monitoring System: Level of Involvement of the IP',
            'T19B' => 'Transition of Patient Monitoring System: Level of Autonomy of the CDOH'
        ]
    ],
    'institutional_ownership' => [
        'title' => 'COUNTY LEVEL INSTITUTIONAL OWNERSHIP INDICATOR',
        'icon' => 'fa-building',
        'color' => '#F5A623',
        'indicators' => [
            'IO1' => 'Operationalization of national HIV/TB plan at institutional level',
            'IO2' => 'Institutional coordination of HIV/TB prevention, care and treatment activities',
            'IO3' => 'Congruence of expectations between levels of the health system'
        ]
    ]
];

// Calculate total indicators for progress tracking
$total_indicators = 0;
foreach ($sections as $section) {
    $total_indicators += count($section['indicators']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transition Benchmarking - Assessment Dashboard</title>
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
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header h1 small {
            font-size: 14px;
            font-weight: 400;
            opacity: 0.8;
            margin-left: 10px;
        }
        .page-header .hdr-links a {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-left: 8px;
            transition: background .2s;
        }
        .page-header .hdr-links a:hover {
            background: rgba(255,255,255,.28);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .action-card {
            background: #fff;
            border-radius: 14px;
            padding: 22px;
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
            display: flex;
            align-items: center;
            gap: 18px;
            transition: transform .2s, box-shadow .2s;
            border-left: 4px solid #0D1A63;
            cursor: pointer;
        }
        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(13,26,99,.15);
        }
        .action-icon {
            width: 54px;
            height: 54px;
            background: linear-gradient(135deg, #0D1A63, #1a3a9e);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 24px;
        }
        .action-content {
            flex: 1;
        }
        .action-content h3 {
            font-size: 16px;
            font-weight: 700;
            color: #0D1A63;
            margin-bottom: 4px;
        }
        .action-content p {
            font-size: 13px;
            color: #666;
        }

        /* Assessment Setup */
        .setup-card {
            background: #fff;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
        }
        .setup-title {
            font-size: 16px;
            font-weight: 700;
            color: #0D1A63;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .setup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .setup-field {
            position: relative;
        }
        .setup-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .setup-field select, .setup-field input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e4f0;
            border-radius: 10px;
            font-size: 14px;
            transition: all .2s;
        }
        .setup-field select:focus, .setup-field input:focus {
            outline: none;
            border-color: #0D1A63;
            box-shadow: 0 0 0 3px rgba(13,26,99,.1);
        }
        .btn-start {
            background: #0D1A63;
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-start:hover {
            background: #1a2a7a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13,26,99,.3);
        }

        /* Sections Grid */
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .section-card {
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
            transition: all .2s;
            border: 1px solid #e8ecf5;
            cursor: pointer;
        }
        .section-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(13,26,99,.15);
            border-color: #0D1A63;
        }
        .section-card.selected {
            border: 3px solid #0D1A63;
            background: #f8faff;
        }
        .section-header {
            padding: 18px 20px;
            background: linear-gradient(135deg, #f8faff, #fff);
            border-bottom: 1px solid #e8ecf5;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-icon {
            width: 42px;
            height: 42px;
            background: var(--color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
        }
        .section-header h3 {
            font-size: 14px;
            font-weight: 700;
            color: #0D1A63;
            line-height: 1.4;
        }
        .section-body {
            padding: 15px 20px;
        }
        .indicator-list {
            list-style: none;
        }
        .indicator-item {
            padding: 8px 0;
            border-bottom: 1px dashed #e8ecf5;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #555;
        }
        .indicator-item:last-child {
            border-bottom: none;
        }
        .indicator-check {
            width: 20px;
            height: 20px;
            border: 2px solid #0D1A63;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            background: #fff;
            transition: all .2s;
        }
        .indicator-check.checked {
            background: #0D1A63;
            color: #fff;
        }
        .indicator-code {
            font-weight: 700;
            color: #0D1A63;
            min-width: 40px;
        }

        /* Recent Assessments */
        .recent-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,.05);
        }
        .recent-title {
            font-size: 16px;
            font-weight: 700;
            color: #0D1A63;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .recent-table {
            width: 100%;
            border-collapse: collapse;
        }
        .recent-table th {
            text-align: left;
            padding: 12px 10px;
            background: #f8fafc;
            color: #0D1A63;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .recent-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e8ecf5;
        }
        .recent-table tr:hover td {
            background: #f8faff;
        }
        .badge-level {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
        }
        .level-low { background: #f8d7da; color: #721c24; }
        .level-medium { background: #fff3cd; color: #856404; }
        .level-high { background: #d1ecf1; color: #0c5460; }
        .level-full { background: #d4edda; color: #155724; }

        .btn-outline {
            background: transparent;
            border: 2px solid #0D1A63;
            color: #0D1A63;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-outline:hover {
            background: #0D1A63;
            color: #fff;
        }

        .progress-summary {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 15px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
        }
        .progress-bar {
            flex: 1;
            height: 10px;
            background: #e0e4f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #0D1A63;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .footer-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>
            <i class="fas fa-chart-line"></i>
            Transition Benchmarking Assessment
            <small>Comprehensive Transition Monitoring Tool</small>
        </h1>
        <div class="hdr-links">
            <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="transition_reports.php"><i class="fas fa-download"></i> Reports</a>
        </div>
    </div>

    Quick Actions 
    <div class="quick-actions">
        <div class="action-card" onclick="startNewAssessment()">
            <div class="action-icon"><i class="fas fa-plus"></i></div>
            <div class="action-content">
                <h3>Start New Assessment</h3>
                <p>Create a complete transition benchmarking assessment for a county</p>
            </div>
        </div>
        <div class="action-card" onclick="continueAssessment()">
            <div class="action-icon"><i class="fas fa-play-circle"></i></div>
            <div class="action-content">
                <h3>Continue Draft</h3>
                <p>Resume a previously started assessment</p>
            </div>
        </div>
        <div class="action-card" onclick="viewReports()">
            <div class="action-icon"><i class="fas fa-chart-pie"></i></div>
            <div class="action-content">
                <h3>View Analytics</h3>
                <p>See transition progress across counties and indicators</p>
            </div>
        </div>
    </div>

    <!-- Assessment Setup -->
    <div class="setup-card" id="setupCard">
        <div class="setup-title">
            <i class="fas fa-clipboard-list"></i>
            Assessment Information
        </div>
        <div class="setup-grid">
            <div class="setup-field">
                <label>County <span style="color: #dc3545;">*</span></label>
                <select id="countySelect" required>
                    <option value="">-- Select County --</option>
                    <?php while ($county = $counties->fetch_assoc()): ?>
                    <option value="<?= $county['county_id'] ?>"><?= htmlspecialchars($county['county_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="setup-field">
                <label>Assessment Period <span style="color: #dc3545;">*</span></label>
                <select id="periodSelect" required>
                    <option value="">-- Select Period --</option>
                    <?php foreach ($quarters as $quarter): ?>
                    <option value="<?= $quarter ?>"><?= $quarter ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="setup-field">
                <label>Assessment Date</label>
                <input type="date" id="dateSelect" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="setup-field" style="display: flex; align-items: flex-end;">
                <button class="btn-start" onclick="initializeAssessment()">
                    <i class="fas fa-arrow-right"></i> Initialize Assessment
                </button>
            </div>
        </div>
    </div>

    <!-- Sections Selection -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 style="font-size: 18px; color: #0D1A63;">
            <i class="fas fa-layer-group"></i> Select Sections to Complete
        </h2>
        <div>
            <span id="selectedCount" style="font-weight: 700; color: #0D1A63;">0</span> of <span><?= count($sections) ?></span> sections selected
        </div>
    </div>

    <div class="sections-grid" id="sectionsGrid">
        <?php foreach ($sections as $key => $section): ?>
        <div class="section-card" data-section="<?= $key ?>" onclick="toggleSection('<?= $key ?>')">
            <div class="section-header">
                <div class="section-icon" style="background: <?= $section['color'] ?>">
                    <i class="fas <?= $section['icon'] ?>"></i>
                </div>
                <h3><?= $section['title'] ?></h3>
            </div>
            <div class="section-body">
                <ul class="indicator-list">
                    <?php foreach ($section['indicators'] as $code => $desc): ?>
                    <li class="indicator-item">
                        <span class="indicator-check" id="check_<?= $key ?>_<?= $code ?>">
                            <i class="fas fa-check"></i>
                        </span>
                        <span class="indicator-code"><?= $code ?>:</span>
                        <span style="flex: 1;"><?= htmlspecialchars(substr($desc, 0, 60)) ?>...</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Progress Summary -->
    <div class="progress-summary" id="progressSummary" style="display: none;">
        <div style="min-width: 120px;">
            <span style="font-size: 13px; color: #666;">Total Indicators</span>
            <div style="font-size: 24px; font-weight: 800; color: #0D1A63;"><?= $total_indicators ?></div>
        </div>
        <div style="flex: 1;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span style="font-size: 13px; color: #666;">Completion Progress</span>
                <span style="font-weight: 700; color: #0D1A63;" id="progressPercent">0%</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill" style="width: 0%;"></div>
            </div>
        </div>
        <div>
            <button class="btn-start" onclick="proceedToAssessment()" id="proceedBtn" disabled>
                <i class="fas fa-arrow-right"></i> Proceed to Assessment
            </button>
        </div>
    </div>

    Recent Assessments 
    <div class="recent-card" style="margin-top: 30px;">
        <div class="recent-title">
            <i class="fas fa-history"></i>
            Recent Assessments
        </div>
        <table class="recent-table">
            <thead>
                <tr>
                    <th>County</th>
                    <th>Period</th>
                    <th>CDOH Score</th>
                    <th>Level</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($saved_assessments && $saved_assessments->num_rows > 0): ?>
                    <?php while ($row = $saved_assessments->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['county_name']) ?></strong></td>
                        <td><?= htmlspecialchars($row['assessment_period']) ?></td>
                        <td><?= $row['total_score'] ?>/100</td>
                        <td>
                            <span class="badge-level
                                <?= strtolower(str_replace(' ', '-', $row['level'] ?? 'not-ready')) ?>">
                                <?= $row['level'] ?? 'Not Rated' ?>
                            </span>
                        </td>
                        <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                        <td>
                            <a href="view_transition.php?id=<?= $row['assessment_id'] ?>" class="btn-outline" style="padding: 4px 10px;">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="edit_transition.php?id=<?= $row['assessment_id'] ?>" class="btn-outline" style="padding: 4px 10px; margin-left: 5px;">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 30px; color: #999;">
                            <i class="fas fa-folder-open" style="font-size: 30px; margin-bottom: 10px; display: block;"></i>
                            No assessments yet. Start by creating a new assessment.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div> -->

    <!-- Footer Actions -->
    <div class="footer-actions">
        <div>
            <a href="kct.docx" class="btn-outline">
                <i class="fas fa-book"></i> Assessment Guide
            </a>
            <a href="transition_faq.php" class="btn-outline" style="margin-left: 10px;">
                <i class="fas fa-question-circle"></i> FAQ
            </a>
        </div>
        <div>
            <span style="color: #999; font-size: 12px;">
                <i class="fas fa-info-circle"></i>
                Select at least one section to begin
            </span>
        </div>
    </div>
</div>

<script>
// Selected sections storage
let selectedSections = new Set();
let totalIndicators = <?= $total_indicators ?>;

// Calculate indicators per section
let indicatorsPerSection = {};
<?php foreach ($sections as $key => $section): ?>
indicatorsPerSection['<?= $key ?>'] = <?= count($section['indicators']) ?>;
<?php endforeach; ?>

function toggleSection(sectionKey) {
    const card = document.querySelector(`[data-section="${sectionKey}"]`);

    if (selectedSections.has(sectionKey)) {
        selectedSections.delete(sectionKey);
        card.classList.remove('selected');

        // Uncheck all indicators in this section
        const indicators = document.querySelectorAll(`[id^="check_${sectionKey}_"]`);
        indicators.forEach(ind => {
            ind.classList.remove('checked');
        });
    } else {
        selectedSections.add(sectionKey);
        card.classList.add('selected');

        // Check all indicators in this section
        const indicators = document.querySelectorAll(`[id^="check_${sectionKey}_"]`);
        indicators.forEach(ind => {
            ind.classList.add('checked');
        });
    }

    updateProgress();
}

function updateProgress() {
    const selectedCount = selectedSections.size;
    document.getElementById('selectedCount').textContent = selectedCount;

    // Calculate indicator progress
    let completedIndicators = 0;
    selectedSections.forEach(section => {
        completedIndicators += indicatorsPerSection[section] || 0;
    });

    const percent = Math.round((completedIndicators / totalIndicators) * 100);
    document.getElementById('progressPercent').textContent = percent + '%';
    document.getElementById('progressFill').style.width = percent + '%';

    // Show progress summary if at least one section selected
    const progressSummary = document.getElementById('progressSummary');
    const proceedBtn = document.getElementById('proceedBtn');

    if (selectedCount > 0) {
        progressSummary.style.display = 'flex';
        proceedBtn.disabled = false;
    } else {
        progressSummary.style.display = 'none';
        proceedBtn.disabled = true;
    }
}

function initializeAssessment() {
    const county = document.getElementById('countySelect').value;
    const period = document.getElementById('periodSelect').value;

    if (!county || !period) {
        alert('Please select both County and Assessment Period to continue.');
        return;
    }

    // Store in session/localStorage
    sessionStorage.setItem('assessment_county', county);
    sessionStorage.setItem('assessment_period', period);
    sessionStorage.setItem('assessment_date', document.getElementById('dateSelect').value);

    // Highlight setup card
    document.getElementById('setupCard').style.border = '3px solid #0D1A63';

    // Scroll to sections
    document.getElementById('sectionsGrid').scrollIntoView({ behavior: 'smooth' });
}

function proceedToAssessment() {
    const county = sessionStorage.getItem('assessment_county');
    const period = sessionStorage.getItem('assessment_period');

    if (!county || !period) {
        alert('Please initialize the assessment first by selecting County and Period.');
        return;
    }

    if (selectedSections.size === 0) {
        alert('Please select at least one section to assess.');
        return;
    }

    // Build URL with selected sections
    const sections = Array.from(selectedSections).join(',');
    const url = `transition_assessment.php?county=${county}&period=${encodeURIComponent(period)}&sections=${sections}`;

    console.log('Navigating to:', url); // For debugging
    window.location.href = url;
}

function startNewAssessment() {
    // Reset selections
    selectedSections.clear();
    document.querySelectorAll('.section-card').forEach(card => {
        card.classList.remove('selected');
    });
    document.querySelectorAll('[id^="check_"]').forEach(ind => {
        ind.classList.remove('checked');
    });

    // Clear form
    document.getElementById('countySelect').value = '';
    document.getElementById('periodSelect').value = '';
    document.getElementById('dateSelect').value = '<?= date('Y-m-d') ?>';

    // Clear session
    sessionStorage.removeItem('assessment_county');
    sessionStorage.removeItem('assessment_period');
    sessionStorage.removeItem('assessment_date');

    // Hide progress
    document.getElementById('progressSummary').style.display = 'none';

    // Scroll to setup
    document.getElementById('setupCard').scrollIntoView({ behavior: 'smooth' });
}

function continueAssessment() {
    // Check for drafts in localStorage
    const drafts = JSON.parse(localStorage.getItem('transition_drafts') || '[]');
    if (drafts.length > 0) {
        // Show draft selection modal (simplified for now)
        const lastDraft = drafts[drafts.length - 1];
        if (confirm(`Continue with draft from ${lastDraft.county} (${lastDraft.date})?`)) {
            // Load draft data
            selectedSections = new Set(lastDraft.sections);
            // Re-render UI
            location.reload();
        }
    } else {
        alert('No saved drafts found. Start a new assessment.');
    }
}

function viewReports() {
    window.location.href = 'transition_reports.php';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check for existing session
    const savedCounty = sessionStorage.getItem('assessment_county');
    const savedPeriod = sessionStorage.getItem('assessment_period');

    if (savedCounty && savedPeriod) {
        document.getElementById('countySelect').value = savedCounty;
        document.getElementById('periodSelect').value = savedPeriod;
        document.getElementById('setupCard').style.border = '3px solid #0D1A63';
    }

    updateProgress();
});
</script>
</body>
</html>