<?php
// transitions/workplan.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get parameters
$county_id = isset($_GET['county']) ? (int)$_GET['county'] : 0;
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
$period = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';

// If no county specified, show selection interface
if (!$county_id && !$assessment_id) {
    showCountySelection($conn);
    exit();
}

// Get assessment data
$assessment_data = getAssessmentData($conn, $county_id, $assessment_id, $period);

if (!$assessment_data) {
    showNoDataError();
    exit();
}

// Generate AI-powered workplan
$workplan = generateWorkplan($assessment_data, $conn);

// Output the workplan as HTML
renderWorkplan($workplan, $assessment_data, $conn);
exit();

// ==================== FUNCTIONS ====================

function showCountySelection($conn) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Generate Transition Workplan</title>
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
            }
            .page-header h1 { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
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
            .page-header .hdr-links a:hover { background: rgba(255,255,255,.28); }
            .card {
                background: #fff;
                border-radius: 14px;
                padding: 25px;
                margin-bottom: 20px;
                box-shadow: 0 2px 14px rgba(0,0,0,.07);
            }
            .card h2 {
                font-size: 1.2rem;
                color: #0D1A63;
                margin-bottom: 20px;
                border-bottom: 2px solid #e0e4f0;
                padding-bottom: 10px;
            }
            .assessment-list {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 15px;
            }
            .assessment-item {
                border: 2px solid #e0e4f0;
                border-radius: 10px;
                padding: 15px;
                transition: all .2s;
                cursor: pointer;
            }
            .assessment-item:hover {
                border-color: #0D1A63;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(13,26,99,.1);
            }
            .assessment-item h3 {
                color: #0D1A63;
                font-size: 1rem;
                margin-bottom: 8px;
            }
            .assessment-item .date {
                font-size: 12px;
                color: #666;
                margin-bottom: 10px;
            }
            .readiness-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
            .badge-transition { background: #d4edda; color: #155724; }
            .badge-support { background: #fff3cd; color: #856404; }
            .badge-not-ready { background: #f8d7da; color: #721c24; }
            .btn-generate {
                background: #0D1A63;
                color: #fff;
                border: none;
                padding: 10px 20px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                margin-top: 15px;
                width: 100%;
            }
            .btn-generate:hover { background: #1a3a9e; }
        </style>
    </head>
    <body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-clipboard-list"></i> Generate Transition Workplan</h1>
            <div class="hdr-links">
                <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
                <a href="transition_index.php"><i class="fas fa-home"></i> Home</a>
            </div>
        </div>

        <div class="card">
            <h2><i class="fas fa-map-marker-alt"></i> Select County & Assessment</h2>
            <form method="GET" action="workplan.php">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">County:</label>
                    <select name="county" required style="width: 100%; padding: 10px; border-radius: 8px; border: 2px solid #e0e4f0;">
                        <option value="">-- Select County --</option>
                        <?php
                        $counties = $conn->query("SELECT county_id, county_name FROM counties ORDER BY county_name");
                        while ($c = $counties->fetch_assoc()) {
                            echo "<option value='{$c['county_id']}'>{$c['county_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Assessment Period:</label>
                    <select name="period" style="width: 100%; padding: 10px; border-radius: 8px; border: 2px solid #e0e4f0;">
                        <option value="">-- Latest Assessment --</option>
                        <?php
                        $periods = $conn->query("SELECT DISTINCT assessment_period FROM transition_section_submissions ORDER BY assessment_period DESC");
                        while ($p = $periods->fetch_assoc()) {
                            echo "<option value='{$p['assessment_period']}'>{$p['assessment_period']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn-generate"><i class="fas fa-magic"></i> Generate AI-Powered Workplan</button>
            </form>
        </div>

        <div class="card">
            <h2><i class="fas fa-history"></i> Recent Assessments</h2>
            <div class="assessment-list">
                <?php
                $recent = $conn->query("
                    SELECT DISTINCT tss.assessment_id, tss.county_id, c.county_name, tss.assessment_period,
                           AVG(tss.cdoh_percent) as avg_score,
                           MAX(tss.submitted_at) as submitted_at
                    FROM transition_section_submissions tss
                    JOIN counties c ON tss.county_id = c.county_id
                    GROUP BY tss.assessment_id, tss.county_id, c.county_name, tss.assessment_period
                    ORDER BY submitted_at DESC LIMIT 10
                ");
                while ($row = $recent->fetch_assoc()) {
                    $readiness = $row['avg_score'] >= 70 ? 'Transition' : ($row['avg_score'] >= 50 ? 'Support and Monitor' : 'Not Ready');
                    $badge_class = $row['avg_score'] >= 70 ? 'badge-transition' : ($row['avg_score'] >= 50 ? 'badge-support' : 'badge-not-ready');
                    echo "
                    <div class='assessment-item' onclick=\"window.location.href='workplan.php?county={$row['county_id']}&assessment_id={$row['assessment_id']}'\">
                        <h3>{$row['county_name']}</h3>
                        <div class='date'><i class='fas fa-calendar'></i> {$row['assessment_period']}</div>
                        <div><span class='readiness-badge $badge_class'>{$readiness}</span></div>
                        <div style='margin-top: 8px; font-size: 12px; color: #666;'>Score: " . round($row['avg_score']) . "%</div>
                        <div style='margin-top: 5px; font-size: 11px; color: #999;'><i class='fas fa-clock'></i> " . date('d M Y', strtotime($row['submitted_at'])) . "</div>
                    </div>";
                }
                ?>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
}

function getAssessmentData($conn, $county_id, $assessment_id, $period) {
    // Build query to get all section submissions
    $where = [];
    if ($assessment_id) {
        $where[] = "tss.assessment_id = $assessment_id";
    } else {
        if ($county_id) $where[] = "tss.county_id = $county_id";
        if ($period) $where[] = "tss.assessment_period = '$period'";
    }

    if (empty($where)) return null;

    $query = "
        SELECT
            tss.*,
            c.county_name,
            ta.assessment_date,
            ta.assessed_by,
            ta.readiness_level as overall_readiness
        FROM transition_section_submissions tss
        JOIN counties c ON tss.county_id = c.county_id
        LEFT JOIN transition_assessments ta ON tss.assessment_id = ta.assessment_id
        WHERE " . implode(" AND ", $where) . "
        ORDER BY tss.section_key
    ";

    $result = $conn->query($query);

    if (!$result || $result->num_rows == 0) return null;

    $data = [
        'county_id' => $county_id,
        'county_name' => '',
        'assessment_id' => 0,
        'assessment_period' => '',
        'assessment_date' => '',
        'assessed_by' => '',
        'overall_readiness' => '',
        'sections' => [],
        'summary' => []
    ];

    $cdoh_scores = [];
    $ip_scores = [];
    $gap_scores = [];

    while ($row = $result->fetch_assoc()) {
        if (empty($data['county_name'])) {
            $data['county_name'] = $row['county_name'];
            $data['assessment_id'] = $row['assessment_id'];
            $data['assessment_period'] = $row['assessment_period'];
            $data['assessment_date'] = $row['assessment_date'];
            $data['assessed_by'] = $row['assessed_by'];
            $data['overall_readiness'] = $row['overall_readiness'];
        }

        $data['sections'][$row['section_key']] = [
            'section_key' => $row['section_key'],
            'sub_count' => $row['sub_count'],
            'avg_cdoh' => $row['avg_cdoh'],
            'cdoh_percent' => $row['cdoh_percent'],
            'avg_ip' => $row['avg_ip'],
            'ip_percent' => $row['ip_percent'],
            'cdoh_ip_overlap' => $row['cdoh_ip_overlap'],
            'gap' => $row['ip_percent'] - $row['cdoh_percent']
        ];

        if ($row['cdoh_percent'] !== null) $cdoh_scores[] = $row['cdoh_percent'];
        if ($row['ip_percent'] !== null) $ip_scores[] = $row['ip_percent'];
        if ($row['ip_percent'] - $row['cdoh_percent'] > 0) $gap_scores[] = $row['ip_percent'] - $row['cdoh_percent'];
    }

    $data['summary'] = [
        'avg_cdoh' => count($cdoh_scores) > 0 ? round(array_sum($cdoh_scores) / count($cdoh_scores), 1) : 0,
        'avg_ip' => count($ip_scores) > 0 ? round(array_sum($ip_scores) / count($ip_scores), 1) : 0,
        'avg_gap' => count($gap_scores) > 0 ? round(array_sum($gap_scores) / count($gap_scores), 1) : 0,
        'total_sections' => count($data['sections']),
        'sections_above_70' => count(array_filter($cdoh_scores, fn($s) => $s >= 70)),
        'sections_above_50' => count(array_filter($cdoh_scores, fn($s) => $s >= 50)),
        'max_gap_section' => ''
    ];

    // Find section with highest gap
    $max_gap = 0;
    foreach ($data['sections'] as $key => $section) {
        if ($section['gap'] > $max_gap) {
            $max_gap = $section['gap'];
            $data['summary']['max_gap_section'] = $key;
            $data['summary']['max_gap_value'] = $section['gap'];
        }
    }

    return $data;
}

function generateWorkplan($assessment_data, $conn) {
    $county = $assessment_data['county_name'];
    $period = $assessment_data['assessment_period'];
    $avg_cdoh = $assessment_data['summary']['avg_cdoh'];
    $avg_ip = $assessment_data['summary']['avg_ip'];
    $avg_gap = $assessment_data['summary']['avg_gap'];
    $overall_readiness = $assessment_data['overall_readiness'];

    // Determine transition timeline based on readiness
    $timeline_months = $avg_cdoh >= 70 ? 6 : ($avg_cdoh >= 50 ? 9 : 12);
    $end_date = date('F Y', strtotime("+$timeline_months months"));
    $start_date = date('F Y');

    // Identify top 5 critical sections (lowest CDOH scores or highest gaps)
    $critical_sections = [];
    foreach ($assessment_data['sections'] as $key => $section) {
        $critical_sections[] = [
            'key' => $key,
            'label' => getSectionLabel($key),
            'cdoh' => $section['cdoh_percent'],
            'ip' => $section['ip_percent'],
            'gap' => $section['gap']
        ];
    }
    usort($critical_sections, fn($a, $b) => $a['cdoh'] <=> $b['cdoh']);
    $critical_sections = array_slice($critical_sections, 0, 5);

    // Generate AI-powered recommendations
    $recommendations = generateAIRecs($assessment_data, $conn);

    // Build the workplan structure
    $workplan = [
        'county' => $county,
        'period' => $period,
        'assessment_date' => $assessment_data['assessment_date'],
        'assessed_by' => $assessment_data['assessed_by'],
        'overall_readiness' => $overall_readiness,
        'avg_cdoh' => $avg_cdoh,
        'avg_ip' => $avg_ip,
        'avg_gap' => $avg_gap,
        'timeline_months' => $timeline_months,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'critical_sections' => $critical_sections,
        'recommendations' => $recommendations,
        'all_sections' => $assessment_data['sections']
    ];

    return $workplan;
}

function generateAIRecs($assessment_data, $conn) {
    $recommendations = [];
    $sections = $assessment_data['sections'];

    // Section-specific recommendations based on scores
    $section_recs = [
        'leadership' => [
            'threshold' => 60,
            'recs' => [
                'Schedule quarterly County Assembly Health Committee meetings with documented decisions',
                'Establish a County HIV/TB Technical Working Group with clear terms of reference',
                'Develop a multi-year strategic plan for HIV/TB services aligned with CIDP',
                'Create a county-level HIV/TB coordination forum with quarterly review meetings',
                'Institutionalize the County AIDS Committee with regular reporting mechanisms'
            ]
        ],
        'finance' => [
            'threshold' => 55,
            'recs' => [
                'Integrate HIV/TB budget into the County Annual Development Plan (ADP)',
                'Train County Treasury staff on HIV/TB program budgeting and tracking',
                'Establish a dedicated budget line for HIV/TB commodities and supplies',
                'Develop a costed sustainability plan for HIV/TB services',
                'Conduct quarterly financial reviews with the County Health Management Team'
            ]
        ],
        'hr_management' => [
            'threshold' => 55,
            'recs' => [
                'Fast-track absorption of IP-supported staff through the County Public Service Board',
                'Conduct workload analysis to determine optimal staffing levels',
                'Develop and implement task-shifting guidelines to optimize human resources',
                'Establish performance appraisal system for HIV/TB program staff',
                'Create succession plans for key HIV/TB program positions'
            ]
        ],
        'commodities' => [
            'threshold' => 65,
            'recs' => [
                'Transfer KEMSA/NASCOP ordering credentials to County Pharmacist',
                'Train facility-level staff on integrated supply chain management',
                'Establish a county-level commodity review committee',
                'Implement real-time stock monitoring dashboard',
                'Develop SOPs for emergency commodity distribution'
            ]
        ],
        'data_management' => [
            'threshold' => 60,
            'recs' => [
                'Transfer ownership of all digital dashboards and EMR systems to County HIS',
                'Train county M&E officers on advanced DHIS2 and DATIM analysis',
                'Establish a Data Quality Assurance (DQA) team within the county',
                'Develop quarterly data review meetings led by County M&E',
                'Implement data validation protocols at facility level'
            ]
        ],
        'supervision' => [
            'threshold' => 55,
            'recs' => [
                'Transition from IP-led to CDOH-led supervision visits',
                'Allocate county budget for supervision logistics (fuel, per diems)',
                'Develop standardized supervision checklists and reporting templates',
                'Establish quarterly supervision feedback mechanisms',
                'Integrate supervision findings into facility QI plans'
            ]
        ],
        'retention_suppression' => [
            'threshold' => 50,
            'recs' => [
                'Strengthen defaulter tracking systems at facility level',
                'Establish patient support groups with county funding',
                'Implement enhanced adherence counseling programs',
                'Develop community linkage frameworks for patient follow-up',
                'Monitor viral load suppression rates at site level'
            ]
        ],
        'laboratory' => [
            'threshold' => 60,
            'recs' => [
                'Train laboratory staff on EQA and proficiency testing',
                'Establish specimen transport systems with county resources',
                'Implement laboratory quality management systems (QMS)',
                'Develop service and maintenance contracts for equipment',
                'Monitor turnaround times for test results'
            ]
        ],
        'training' => [
            'threshold' => 55,
            'recs' => [
                'Conduct annual training needs assessment',
                'Allocate county budget for in-service training',
                'Train master trainers within the county to sustain capacity',
                'Utilize iHRIS Train for training records management',
                'Develop a mentorship program for new staff'
            ]
        ],
        'institutional_ownership' => [
            'threshold' => 65,
            'recs' => [
                'Develop county-specific HIV/TB annual work plans based on national frameworks',
                'Cost all HIV/TB activities and integrate into national budget requests',
                'Establish multi-stakeholder coordination forums with CSOs and PLHIV',
                'Ensure alignment between county strategic plans and national HIV/TB frameworks',
                'Regularly track implementation of county HIV/TB work plans'
            ]
        ]
    ];

    // Generate recommendations based on actual scores
    foreach ($sections as $key => $section) {
        $label = getSectionLabel($key);
        $cdoh = $section['cdoh_percent'];
        $gap = $section['gap'];

        // Low CDOH score - needs capacity building
        if ($cdoh < 50) {
            $recommendations[] = [
                'section' => $label,
                'priority' => 'Critical',
                'type' => 'Capacity Building',
                'message' => "{$label} shows significant gaps (CDOH: {$cdoh}%). Immediate capacity building required. " .
                            "Focus on institutionalizing basic structures and processes before transitioning advanced functions.",
                'action_items' => getDefaultActions($key, 'low')
            ];
        }
        // Medium CDOH score with high IP gap - needs transition planning
        elseif ($cdoh >= 50 && $cdoh < 70 && $gap > 15) {
            $recommendations[] = [
                'section' => $label,
                'priority' => 'High',
                'type' => 'Transition Planning',
                'message' => "{$label} shows moderate CDOH capacity ({$cdoh}%) but high dependency on IP (gap: {$gap}%). " .
                            "Phased transition needed with clear handover milestones.",
                'action_items' => getDefaultActions($key, 'medium')
            ];
        }
        // High CDOH score - ready for handover
        elseif ($cdoh >= 70) {
            $recommendations[] = [
                'section' => $label,
                'priority' => 'Routine',
                'type' => 'Final Handover',
                'message' => "{$label} demonstrates strong county ownership ({$cdoh}%). Accelerate final handover and documentation.",
                'action_items' => getDefaultActions($key, 'high')
            ];
        }

        // Add specific recommendations if available
        if (isset($section_recs[$key]) && $cdoh < $section_recs[$key]['threshold']) {
            $rec_index = count($recommendations) - 1;
            $specific_recs = array_slice($section_recs[$key]['recs'], 0, 3);
            $recommendations[$rec_index]['specific_actions'] = $specific_recs;
        }
    }

    // Sort recommendations by priority
    usort($recommendations, function($a, $b) {
        $priority_order = ['Critical' => 1, 'High' => 2, 'Routine' => 3];
        return $priority_order[$a['priority']] <=> $priority_order[$b['priority']];
    });

    return $recommendations;
}

function getDefaultActions($section_key, $level) {
    $actions = [
        'low' => [
            'Conduct rapid assessment to identify root causes of capacity gaps',
            'Develop a 3-month intensive mentorship program with dedicated mentors',
            'Establish weekly review meetings with County Health Management Team',
            'Create simplified SOPs and job aids for key functions'
        ],
        'medium' => [
            'Develop a phased transition plan with clear milestones',
            'Train county staff to shadow IP staff for 3 months',
            'Transfer responsibility for key functions gradually over 6 months',
            'Establish performance monitoring framework with county leadership'
        ],
        'high' => [
            'Schedule final handover meeting with documentation sign-off',
            'Transfer all assets and intellectual property to county',
            'Conduct final training on sustainable management practices',
            'Provide post-handover support hotline for 3 months'
        ]
    ];

    return $actions[$level];
}

function getSectionLabel($key) {
    $labels = [
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
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function renderWorkplan($workplan, $assessment_data, $conn) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Transition Workplan - <?= htmlspecialchars($workplan['county']) ?></title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f0f2f7;
                color: #333;
                line-height: 1.6;
            }
            .container { max-width: 1300px; margin: 0 auto; padding: 20px; }

            .page-header {
                background: linear-gradient(135deg, #0D1A63 0%, #1a3a9e 100%);
                color: #fff;
                padding: 25px 30px;
                border-radius: 14px;
                margin-bottom: 24px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            .page-header h1 { font-size: 1.4rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
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
            .page-header .hdr-links a:hover { background: rgba(255,255,255,.28); }

            .print-btn {
                background: #fff;
                color: #0D1A63;
                border: none;
                padding: 8px 20px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: all .2s;
            }
            .print-btn:hover { background: #f0f2f7; transform: translateY(-1px); }

            .workplan-meta {
                background: #fff;
                border-radius: 14px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 2px 14px rgba(0,0,0,.07);
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            .meta-item {
                text-align: center;
                padding: 10px;
                background: #f8fafc;
                border-radius: 10px;
            }
            .meta-item .label { font-size: 11px; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
            .meta-item .value { font-size: 18px; font-weight: 800; color: #0D1A63; margin-top: 5px; }
            .readiness-badge {
                display: inline-block;
                padding: 8px 16px;
                border-radius: 30px;
                font-weight: 700;
                font-size: 14px;
            }
            .badge-transition { background: #d4edda; color: #155724; }
            .badge-support { background: #fff3cd; color: #856404; }
            .badge-not-ready { background: #f8d7da; color: #721c24; }

            .section-title {
                font-size: 1rem;
                font-weight: 700;
                letter-spacing: 1px;
                text-transform: uppercase;
                color: #0D1A63;
                margin: 25px 0 15px;
                padding-bottom: 8px;
                border-bottom: 3px solid #0D1A63;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .card {
                background: #fff;
                border-radius: 14px;
                padding: 0;
                margin-bottom: 20px;
                box-shadow: 0 2px 14px rgba(0,0,0,.07);
                overflow: hidden;
            }
            .card-header {
                background: #f8fafc;
                padding: 15px 20px;
                border-bottom: 2px solid #e0e4f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
            }
            .card-header h3 { font-size: 1rem; color: #0D1A63; display: flex; align-items: center; gap: 8px; }
            .card-body { padding: 20px; }

            .recommendation-item {
                background: #f8fafc;
                border-left: 4px solid;
                padding: 15px;
                margin-bottom: 15px;
                border-radius: 8px;
            }
            .rec-critical { border-left-color: #dc3545; }
            .rec-high { border-left-color: #fd7e14; }
            .rec-routine { border-left-color: #28a745; }

            .priority-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 10px;
                font-weight: 700;
                margin-left: 8px;
            }
            .priority-critical { background: #f8d7da; color: #721c24; }
            .priority-high { background: #ffe5d0; color: #fd7e14; }
            .priority-routine { background: #d4edda; color: #155724; }

            .action-list { margin-top: 10px; padding-left: 20px; }
            .action-list li { margin: 5px 0; font-size: 13px; }

            .timeline-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
            }
            .timeline-table th {
                background: #0D1A63;
                color: #fff;
                padding: 10px;
                text-align: left;
                font-weight: 600;
            }
            .timeline-table td {
                padding: 10px;
                border-bottom: 1px solid #e0e4f0;
            }
            .timeline-table tr:hover td { background: #f8fafc; }

            .phase-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
            }
            .phase-1 { background: #cfe2ff; color: #004085; }
            .phase-2 { background: #fff3cd; color: #856404; }
            .phase-3 { background: #d4edda; color: #155724; }

            .chart-container {
                height: 400px;
                margin-bottom: 20px;
            }

            .grid-2 {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            @media print {
                .print-btn, .hdr-links, .page-header .hdr-links { display: none; }
                .container { padding: 0; }
                .card { box-shadow: none; border: 1px solid #ddd; }
                .page-header { background: #0D1A63; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }

            @media (max-width: 768px) {
                .grid-2 { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-clipboard-list"></i>
                Transition Workplan
                <small style="font-size: 12px;">AI-Powered & Data-Driven</small>
            </h1>
            <div style="display: flex; gap: 10px;">
                <button class="print-btn" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
                <div class="hdr-links">
                    <a href="transition_dashboard.php?county=<?= $workplan['county_id'] ?? '' ?>"><i class="fas fa-chart-bar"></i> Dashboard</a>
                    <a href="transition_index.php"><i class="fas fa-home"></i> Home</a>
                </div>
            </div>
        </div>

        <!-- Workplan Meta Information -->
        <div class="workplan-meta">
            <div class="meta-item">
                <div class="label">County</div>
                <div class="value"><?= htmlspecialchars($workplan['county']) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Assessment Period</div>
                <div class="value"><?= htmlspecialchars($workplan['period']) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Assessment Date</div>
                <div class="value"><?= date('d M Y', strtotime($workplan['assessment_date'])) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Assessed By</div>
                <div class="value"><?= htmlspecialchars($workplan['assessed_by']) ?></div>
            </div>
            <div class="meta-item">
                <div class="label">Overall Readiness</div>
                <div class="value">
                    <span class="readiness-badge <?= $workplan['overall_readiness'] == 'Transition' ? 'badge-transition' : ($workplan['overall_readiness'] == 'Support and Monitor' ? 'badge-support' : 'badge-not-ready') ?>">
                        <?= $workplan['overall_readiness'] ?: 'Not Rated' ?>
                    </span>
                </div>
            </div>
            <div class="meta-item">
                <div class="label">Transition Timeline</div>
                <div class="value"><?= $workplan['timeline_months'] ?> Months</div>
                <div style="font-size: 11px;"><?= $workplan['start_date'] ?> - <?= $workplan['end_date'] ?></div>
            </div>
        </div>

        <!-- Executive Summary -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Executive Summary</h3>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 15px;">Based on the transition assessment conducted in <strong><?= $workplan['period'] ?></strong>,
                <?= htmlspecialchars($workplan['county']) ?> County demonstrates an average CDOH autonomy score of <strong><?= $workplan['avg_cdoh'] ?>%</strong>
                and an average IP involvement score of <strong><?= $workplan['avg_ip'] ?>%</strong>. The overall gap between IP involvement and county autonomy is
                <strong><?= $workplan['avg_gap'] ?>%</strong>, indicating areas where transition support is critical.</p>

                <p>The county is classified as <strong><?= $workplan['overall_readiness'] ?: 'Not Rated' ?></strong>.
                A <strong><?= $workplan['timeline_months'] ?>-month transition period</strong> is recommended, running from
                <strong><?= $workplan['start_date'] ?></strong> to <strong><?= $workplan['end_date'] ?></strong>.</p>
            </div>
        </div>

        <!-- Score Visualization -->
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> CDOH Score Distribution</h3>
                </div>
                <div class="card-body">
                    <canvas id="cdohChart" style="height: 300px;"></canvas>
                    <div style="margin-top: 15px; text-align: center;">
                        <div style="display: inline-flex; gap: 20px; flex-wrap: wrap;">
                            <div><span style="background: #28a745; width: 12px; height: 12px; display: inline-block; border-radius: 2px;"></span> Ready (=70%): <?= $workplan['summary']['sections_above_70'] ?? 0 ?></div>
                            <div><span style="background: #ffc107; width: 12px; height: 12px; display: inline-block; border-radius: 2px;"></span> Moderate (50-69%): <?= ($workplan['summary']['sections_above_50'] ?? 0) - ($workplan['summary']['sections_above_70'] ?? 0) ?></div>
                            <div><span style="background: #dc3545; width: 12px; height: 12px; display: inline-block; border-radius: 2px;"></span> Critical (<50%): <?= ($workplan['summary']['total_sections'] ?? 0) - ($workplan['summary']['sections_above_50'] ?? 0) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> CDOH vs IP Comparison</h3>
                </div>
                <div class="card-body">
                    <canvas id="compareChart" style="height: 300px;"></canvas>
                    <div style="margin-top: 15px; text-align: center; font-size: 12px; color: #666;">
                        <i class="fas fa-info-circle"></i> Gap = IP% - CDOH% | Higher gap indicates dependency on IP
                    </div>
                </div>
            </div>
        </div>

        <!-- Critical Sections (Top Priorities) -->
        <div class="section-title">
            <i class="fas fa-exclamation-triangle"></i> Critical Sections Requiring Immediate Attention
        </div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Section</th><th>CDOH Score</th><th>IP Score</th><th>Gap</th><th>Priority Level</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workplan['critical_sections'] as $section): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($section['label']) ?></strong></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span><?= round($section['cdoh']) ?>%</span>
                                    <div style="flex: 1; background: #e0e4f0; height: 6px; border-radius: 3px; width: 80px;">
                                        <div style="width: <?= $section['cdoh'] ?>%; background: <?= $section['cdoh'] >= 70 ? '#28a745' : ($section['cdoh'] >= 50 ? '#ffc107' : '#dc3545') ?>; height: 6px; border-radius: 3px;"></div>
                                    </div>
                                </div>
                            </td>
                            <td><?= $section['ip'] ? round($section['ip']) . '%' : 'N/A' ?></td>
                            <td style="color: <?= $section['gap'] > 20 ? '#dc3545' : ($section['gap'] > 10 ? '#fd7e14' : '#28a745') ?>">
                                <?= $section['gap'] > 0 ? '+' . round($section['gap']) . '%' : '0%' ?>
                            </td>
                            <td>
                                <span class="priority-badge <?= $section['cdoh'] < 50 ? 'priority-critical' : ($section['cdoh'] < 70 ? 'priority-high' : 'priority-routine') ?>">
                                    <?= $section['cdoh'] < 50 ? 'Critical' : ($section['cdoh'] < 70 ? 'High' : 'Routine') ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AI-Generated Recommendations -->
        <div class="section-title">
            <i class="fas fa-robot"></i> AI-Powered Recommendations
        </div>
        <div class="card">
            <div class="card-body">
                <?php foreach ($workplan['recommendations'] as $rec):
                    $rec_class = $rec['priority'] == 'Critical' ? 'rec-critical' : ($rec['priority'] == 'High' ? 'rec-high' : 'rec-routine');
                ?>
                <div class="recommendation-item <?= $rec_class ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                        <strong><i class="fas fa-folder"></i> <?= htmlspecialchars($rec['section']) ?></strong>
                        <div>
                            <span class="priority-badge <?= $rec['priority'] == 'Critical' ? 'priority-critical' : ($rec['priority'] == 'High' ? 'priority-high' : 'priority-routine') ?>">
                                <?= $rec['priority'] ?> Priority
                            </span>
                            <span class="priority-badge" style="background: #e0e4f0; color: #666;"><?= $rec['type'] ?></span>
                        </div>
                    </div>
                    <p style="margin-bottom: 10px;"><?= htmlspecialchars($rec['message']) ?></p>
                    <div>
                        <strong>Key Action Items:</strong>
                        <ul class="action-list">
                            <?php foreach ($rec['action_items'] as $action): ?>
                            <li><i class="fas fa-check-circle" style="color: #28a745; font-size: 12px;"></i> <?= htmlspecialchars($action) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (isset($rec['specific_actions'])): ?>
                        <strong style="margin-top: 10px; display: block;">Specific Technical Actions:</strong>
                        <ul class="action-list">
                            <?php foreach ($rec['specific_actions'] as $action): ?>
                            <li><i class="fas fa-microchip" style="color: #0D1A63; font-size: 12px;"></i> <?= htmlspecialchars($action) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Phased Transition Timeline -->
        <div class="section-title">
            <i class="fas fa-calendar-alt"></i> Phased Transition Timeline
        </div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Phase</th><th>Duration</th><th>Key Activities</th><th>Responsible Parties</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="phase-badge phase-1">Phase 1: Co-Management</span></td>
                            <td>Months 1-<?= floor($workplan['timeline_months'] / 3) ?></td>
                            <td>
                                <ul style="margin-left: 20px;">
                                    <li>IP and County staff work side-by-side</li>
                                    <li>County assumes 25% of operational costs</li>
                                    <li>Sign transition MOUs between IP and County</li>
                                    <li>Begin capacity building in critical areas</li>
                                </ul>
                            </td>
                            <td>IP + County Health Team</td>
                        </tr>
                        <tr>
                            <td><span class="phase-badge phase-2">Phase 2: Active Transition</span></td>
                            <td>Months <?= floor($workplan['timeline_months'] / 3) + 1 ?>-<?= floor($workplan['timeline_months'] * 2 / 3) ?></td>
                            <td>
                                <ul style="margin-left: 20px;">
                                    <li>IP shifts to advisory role; County leads implementation</li>
                                    <li>County assumes 75% of operational costs</li>
                                    <li>Complete transfer of assets and systems</li>
                                    <li>County-led supervision and QI activities</li>
                                </ul>
                            </td>
                            <td>County Health Team (IP advisory)</td>
                        </tr>
                        <tr>
                            <td><span class="phase-badge phase-3">Phase 3: Full Handover & Exit</span></td>
                            <td>Months <?= floor($workplan['timeline_months'] * 2 / 3) + 1 ?>-<?= $workplan['timeline_months'] ?></td>
                            <td>
                                <ul style="margin-left: 20px;">
                                    <li>IP presence ceases</li>
                                    <li>County assumes 100% financial and operational responsibility</li>
                                    <li>Final M&E and close-out reporting</li>
                                    <li>Post-handover support hotline established</li>
                                </ul>
                            </td>
                            <td>County Health Team</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Asset & Staff Transition -->
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes"></i> Asset Transfer Plan</h3>
                </div>
                <div class="card-body">
                    <p style="margin-bottom: 15px;">Based on the assessment, the following asset transfer activities are recommended:</p>
                    <ul class="action-list">
                        <li><i class="fas fa-file-alt"></i> Conduct joint physical inventory of all USAID-funded assets</li>
                        <li><i class="fas fa-exchange-alt"></i> Categorize assets: Transfer to County / Repurpose / Liquidate</li>
                        <li><i class="fas fa-copyright"></i> Transfer intellectual property (training materials, SOPs, software licenses)</li>
                        <li><i class="fas fa-building"></i> Handover office/facility lease agreements to county</li>
                        <li><i class="fas fa-signature"></i> Sign formal asset transfer certificates with County Supply Chain</li>
                    </ul>
                    <div style="margin-top: 15px; padding: 10px; background: #f8fafc; border-radius: 8px;">
                        <i class="fas fa-clock"></i> <strong>Timeline:</strong> Complete by end of Phase 1 (Month <?= floor($workplan['timeline_months'] / 3) ?>)
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-users"></i> Staff Transition Plan</h3>
                </div>
                <div class="card-body">
                    <p style="margin-bottom: 15px;">Critical HR actions for sustaining program capacity:</p>
                    <ul class="action-list">
                        <li><i class="fas fa-file-signature"></i> Submit IP-supported staff list to County Public Service Board</li>
                        <li><i class="fas fa-chart-line"></i> Conduct workload analysis to justify staff absorption</li>
                        <li><i class="fas fa-hand-holding-usd"></i> Integrate staff salaries into county budget for next fiscal year</li>
                        <li><i class="fas fa-chalkboard-teacher"></i> Develop task-shifting guidelines for critical functions</li>
                        <li><i class="fas fa-check-double"></i> Finalize retention bonuses and exit packages for non-absorbed staff</li>
                    </ul>
                    <div style="margin-top: 15px; padding: 10px; background: #f8fafc; border-radius: 8px;">
                        <i class="fas fa-clock"></i> <strong>Timeline:</strong> Complete by end of Phase 2 (Month <?= floor($workplan['timeline_months'] * 2 / 3) ?>)
                    </div>
                </div>
            </div>
        </div>

        <!-- Risk Register -->
        <div class="section-title">
            <i class="fas fa-shield-alt"></i> Risk Register & Mitigation Strategies
        </div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Risk</th><th>Likelihood</th><th>Impact</th><th>Mitigation Strategy</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>County budget cycle misalignment</td>
                            <td><span style="color: #dc3545;">High</span></td>
                            <td>Critical</td>
                            <td>Align handover with County Fiscal Strategy Paper (CFSP); early engagement with County Assembly</td>
                        </tr>
                        <tr>
                            <td>Key county staff attrition</td>
                            <td><span style="color: #fd7e14;">Medium</span></td>
                            <td>High</td>
                            <td>Succession planning; train at least 2 staff per function; knowledge transfer documentation</td>
                        </tr>
                        <tr>
                            <td>Data loss during transition</td>
                            <td><span style="color: #fd7e14;">Medium</span></td>
                            <td>High</td>
                            <td>Data migration plan; backup all systems to county servers; sign-off from County HIS</td>
                        </tr>
                        <tr>
                            <td>Political instability/elections</td>
                            <td><span style="color: #fd7e14;">Medium</span></td>
                            <td>Medium</td>
                            <td>Secure MOU signatures before political season; engage cross-party leadership</td>
                        </tr>
                        <tr>
                            <td>Loss of institutional knowledge</td>
                            <td><span style="color: #ffc107;">Low</span></td>
                            <td>Medium</td>
                            <td>Comprehensive documentation; exit interviews; mentorship program with IP staff</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- M&E for Transition -->
        <div class="section-title">
            <i class="fas fa-chart-simple"></i> Transition Monitoring Framework
        </div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Indicator</th><th>Baseline</th><th>Target (End of Transition)</th><th>Data Source</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Average CDOH Score (all sections)</td>
                            <td><?= $workplan['avg_cdoh'] ?>%</td>
                            <td><?= min(100, $workplan['avg_cdoh'] + 15) ?>%</td>
                            <td>Transition Dashboard</td>
                        </tr>
                        <tr>
                            <td>Sections with CDOH Score =70%</td>
                            <td><?= $workplan['summary']['sections_above_70'] ?? 0 ?>/<?= $workplan['summary']['total_sections'] ?? 0 ?></td>
                            <td><?= min($workplan['summary']['total_sections'] ?? 0, ($workplan['summary']['sections_above_70'] ?? 0) + 5) ?>/<?= $workplan['summary']['total_sections'] ?? 0 ?></td>
                            <td>Comparison Dashboard</td>
                        </tr>
                        <tr>
                            <td>IP-supported staff absorbed</td>
                            <td>0%</td>
                            <td>100%</td>
                            <td>HR Handover Notes</td>
                        </tr>
                        <tr>
                            <td>HIV/TB budget line in County ADP</td>
                            <td>No</td>
                            <td>Yes</td>
                            <td>County Annual Development Plan</td>
                        </tr>
                        <tr>
                            <td>Counties with reduced gap (IP - CDOH)</td>
                            <td><?= $workplan['avg_gap'] ?>% avg gap</td>
                            <td>&lt;10% avg gap</td>
                            <td>Comparison Dashboard</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px; padding: 20px; background: #f8fafc; border-radius: 10px; font-size: 12px; color: #666;">
            <i class="fas fa-robot"></i> This workplan was generated using AI based on assessment data from the Transition Benchmarking Tool.<br>
            Last updated: <?= date('d F Y H:i:s') ?> | Version: 1.0
        </div>
    </div>

    <script>
        // CDOH Score Distribution Chart
        const cdohData = <?= json_encode(array_values(array_map(fn($s) => round($s['cdoh_percent']), $workplan['all_sections']))) ?>;
        const sectionLabels = <?= json_encode(array_values(array_map(fn($k) => getSectionLabel($k), array_keys($workplan['all_sections'])))) ?>;

        new Chart(document.getElementById('cdohChart'), {
            type: 'bar',
            data: {
                labels: sectionLabels,
                datasets: [{
                    label: 'CDOH Score (%)',
                    data: cdohData,
                    backgroundColor: cdohData.map(v => v >= 70 ? '#28a745' : (v >= 50 ? '#ffc107' : '#dc3545')),
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => `${ctx.raw}%` } }
                },
                scales: {
                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#e0e4f0' } },
                    x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 10 } }, grid: { display: false } }
                }
            }
        });

        // CDOH vs IP Comparison Chart
        const cdohValues = <?= json_encode(array_values(array_map(fn($s) => round($s['cdoh_percent']), $workplan['all_sections']))) ?>;
        const ipValues = <?= json_encode(array_values(array_map(fn($s) => $s['ip_percent'] ? round($s['ip_percent']) : null, $workplan['all_sections']))) ?>;

        new Chart(document.getElementById('compareChart'), {
            type: 'bar',
            data: {
                labels: sectionLabels,
                datasets: [
                    { label: 'CDOH Autonomy (%)', data: cdohValues, backgroundColor: 'rgba(13, 26, 99, 0.8)', borderRadius: 6 },
                    { label: 'IP Involvement (%)', data: ipValues, backgroundColor: 'rgba(255, 193, 7, 0.7)', borderRadius: 6 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw}%` } } },
                scales: {
                    y: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#e0e4f0' } },
                    x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 9 } }, grid: { display: false } }
                }
            }
        });
    </script>
    </body>
    </html>
    <?php
}

function showNoDataError() {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>No Data Found</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f0f2f7; display: flex; justify-content: center; align-items: center; height: 100vh; }
            .error-card { background: #fff; padding: 40px; border-radius: 14px; text-align: center; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,.1); }
            .error-card i { font-size: 48px; color: #dc3545; margin-bottom: 20px; }
            .error-card h2 { color: #0D1A63; margin-bottom: 10px; }
            .error-card p { color: #666; margin-bottom: 20px; }
            .btn { background: #0D1A63; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 8px; display: inline-block; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <i class="fas fa-database"></i>
            <h2>No Assessment Data Found</h2>
            <p>No assessment data exists for the selected county and period. Please complete an assessment first.</p>
            <a href="transition_index.php" class="btn"><i class="fas fa-plus"></i> Start New Assessment</a>
            <a href="workplan.php" class="btn" style="background: #6c757d; margin-left: 10px;"><i class="fas fa-arrow-left"></i> Go Back</a>
        </div>
    </body>
    </html>
    <?php
}
?>