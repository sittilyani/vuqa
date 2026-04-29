<?php
// transitions/workplan.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');
include('../includes/county_access.php');

// Check if dompdf is available for PDF export
$dompdf_available = false;
$dompdf_autoload_paths = [
    '../vendor/autoload.php',
    '../vendor/dompdf/dompdf/autoload.inc.php',
    '../dompdf/autoload.inc.php'
];

foreach ($dompdf_autoload_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $dompdf_available = true;
        break;
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Refresh county assignments from DB so online sessions stay accurate
// (session may have been created before county assignments were saved)
cf_refresh_session_from_db($conn);

// Get parameters
$county_id     = isset($_GET['county'])        ? (int)$_GET['county']                                      : 0;
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id']                               : 0;
$period        = isset($_GET['period'])        ? mysqli_real_escape_string($conn, $_GET['period'])         : '';
$export_format = isset($_GET['export'])        ? $_GET['export']                                           : '';

// If only assessment_id given (no county), resolve county_id from DB so the
// access check below can run correctly
if ($assessment_id && !$county_id) {
    $r = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT county_id FROM transition_assessments WHERE assessment_id=$assessment_id LIMIT 1"));
    if ($r) $county_id = (int)$r['county_id'];
}

// Block access to a county the user is not assigned to
// Admins always pass; non-admins must have the county in their assigned list
if ($county_id && !cf_user_can_access_county($county_id)) {
    $_SESSION['error_message'] = 'You are not assigned to this county.';
    header('Location: transition_index.php');
    exit();
}

// If no county specified, show selection interface
if (!$county_id && !$assessment_id && !$export_format) {
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

// Handle exports
if ($export_format === 'pdf') {
    if (!$dompdf_available) {
        die('dompdf not found. Please install dompdf using: composer require dompdf/dompdf');
    }
    exportToPDF($workplan, $conn);
    exit();
} elseif ($export_format === 'word') {
    exportToWord($workplan, $conn);
    exit();
}

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
            .filter-group {
                margin-bottom: 20px;
            }
            .filter-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #0D1A63;
            }
            .filter-group select {
                width: 100%;
                padding: 10px;
                border-radius: 8px;
                border: 2px solid #e0e4f0;
                font-size: 14px;
            }
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
            <form method="GET" action="transition_workplan.php" id="wpForm">
                <div class="filter-group">
                    <label>County:</label>
                    <select name="county" id="selCounty" required>
                        <option value="">-- Select County --</option>
                        <?php
                        // cf_load_counties respects county access; fallback to all if none assigned
                        $county_rows = cf_load_counties($conn);
                        if (empty($county_rows)) {
                            $county_rows = [];
                            $fallback = $conn->query("SELECT county_id, county_name FROM counties ORDER BY county_name");
                            if ($fallback) while ($c = $fallback->fetch_assoc()) $county_rows[] = $c;
                        }
                        foreach ($county_rows as $c) {
                            echo "<option value='{$c['county_id']}'>" . htmlspecialchars($c['county_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Assessment Period:</label>
                    <select name="period" id="selPeriod">
                        <option value="">-- Latest Assessment --</option>
                        <?php
                        $periods = $conn->query("SELECT DISTINCT assessment_period FROM transition_section_submissions ORDER BY assessment_period DESC");
                        if ($periods) while ($p = $periods->fetch_assoc()) {
                            echo "<option value='" . htmlspecialchars($p['assessment_period']) . "'>" . htmlspecialchars($p['assessment_period']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn-generate"><i class="fas fa-magic"></i> Generate AI-Powered Workplan</button>
                <p id="wpStatus" style="margin-top:8px;font-size:12px;color:#888;"></p>
            </form>
        </div>

        <div class="card">
            <h2><i class="fas fa-history"></i> Recent Assessments</h2>
            <div class="assessment-list">
                <?php
                // Apply county access filter so online users only see their counties
                $_cf = cf_county_filter_sql('tss.county_id');
                $recent = $conn->query("
                    SELECT DISTINCT tss.assessment_id, tss.county_id, c.county_name, tss.assessment_period,
                           AVG(tss.cdoh_percent) as avg_score,
                           MAX(tss.submitted_at) as submitted_at
                    FROM transition_section_submissions tss
                    JOIN counties c ON tss.county_id = c.county_id
                    WHERE 1=1 $_cf
                    GROUP BY tss.assessment_id, tss.county_id, c.county_name, tss.assessment_period
                    ORDER BY submitted_at DESC LIMIT 15
                ");
                while ($row = $recent->fetch_assoc()) {
                    $readiness = $row['avg_score'] >= 70 ? 'Transition' : ($row['avg_score'] >= 50 ? 'Support and Monitor' : 'Not Ready');
                    $badge_class = $row['avg_score'] >= 70 ? 'badge-transition' : ($row['avg_score'] >= 50 ? 'badge-support' : 'badge-not-ready');
                    echo "
                    <div class='assessment-item' onclick=\"window.location.href='transition_workplan.php?county={$row['county_id']}&assessment_id={$row['assessment_id']}'\">
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
    $where = array();
    if ($assessment_id) {
        $where[] = "tss.assessment_id = $assessment_id";
    } else {
        if ($county_id) $where[] = "tss.county_id = $county_id";
        if ($period) $where[] = "tss.assessment_period = '$period'";
        // If no period specified, get latest
        if (!$period && $county_id) {
            $latest = $conn->query("SELECT assessment_period FROM transition_section_submissions WHERE county_id = $county_id ORDER BY submitted_at DESC LIMIT 1");
            if ($latest && $latest->num_rows > 0) {
                $latest_row = $latest->fetch_assoc();
                $where[] = "tss.assessment_period = '{$latest_row['assessment_period']}'";
            }
        }
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

    $data = array(
        'county_id' => $county_id,
        'county_name' => '',
        'assessment_id' => 0,
        'assessment_period' => '',
        'assessment_date' => '',
        'assessed_by' => '',
        'overall_readiness' => '',
        'sections' => array(),
        'summary' => array()
    );

    $cdoh_scores = array();
    $ip_scores = array();
    $gap_scores = array();

    while ($row = $result->fetch_assoc()) {
        if (empty($data['county_name'])) {
            $data['county_name'] = $row['county_name'];
            $data['assessment_id'] = $row['assessment_id'];
            $data['assessment_period'] = $row['assessment_period'];
            $data['assessment_date'] = $row['assessment_date'];
            $data['assessed_by'] = $row['assessed_by'];
            $data['overall_readiness'] = $row['overall_readiness'];
        }

        $data['sections'][$row['section_key']] = array(
            'section_key' => $row['section_key'],
            'sub_count' => $row['sub_count'],
            'avg_cdoh' => $row['avg_cdoh'],
            'cdoh_percent' => $row['cdoh_percent'],
            'avg_ip' => $row['avg_ip'],
            'ip_percent' => $row['ip_percent'],
            'cdoh_ip_overlap' => $row['cdoh_ip_overlap'],
            'gap' => ($row['ip_percent'] - $row['cdoh_percent'])
        );

        if ($row['cdoh_percent'] !== null) $cdoh_scores[] = $row['cdoh_percent'];
        if ($row['ip_percent'] !== null) $ip_scores[] = $row['ip_percent'];
        if ($row['ip_percent'] - $row['cdoh_percent'] > 0) $gap_scores[] = $row['ip_percent'] - $row['cdoh_percent'];
    }

    // Fetch raw_scores comments grouped by section_key
    $comments_by_section = array();
    if ($data['assessment_id']) {
        $aid = (int)$data['assessment_id'];

        // Sub-indicator level comments from transition_raw_scores
        $cr = $conn->query("
            SELECT section_key, indicator_code, sub_indicator_code, comments, cdoh_score
            FROM transition_raw_scores
            WHERE assessment_id = $aid AND comments IS NOT NULL AND TRIM(comments) != ''
            ORDER BY section_key, indicator_code, sub_indicator_code
        ");
        if ($cr) {
            while ($crow = $cr->fetch_assoc()) {
                $sk = $crow['section_key'];
                if (!isset($comments_by_section[$sk])) $comments_by_section[$sk] = array();
                $comments_by_section[$sk][] = array(
                    'type'      => 'indicator',
                    'indicator' => $crow['indicator_code'],
                    'sub'       => $crow['sub_indicator_code'],
                    'comment'   => trim($crow['comments']),
                    'score'     => $crow['cdoh_score']
                );
            }
        }

        // Section-level comments from transition_comments
        $sec_cr = $conn->query("
            SELECT section_key, comment_text
            FROM transition_comments
            WHERE assessment_id = $aid AND comment_type = 'section' AND section_key IS NOT NULL AND TRIM(comment_text) != ''
        ");
        if ($sec_cr) {
            while ($srow = $sec_cr->fetch_assoc()) {
                $sk = $srow['section_key'];
                if (!isset($comments_by_section[$sk])) $comments_by_section[$sk] = array();
                // Section comments go first
                array_unshift($comments_by_section[$sk], array(
                    'type'      => 'section',
                    'indicator' => '',
                    'sub'       => '',
                    'comment'   => trim($srow['comment_text']),
                    'score'     => null
                ));
            }
        }
    }
    $data['comments_by_section'] = $comments_by_section;

    $data['summary'] = array(
        'avg_cdoh' => count($cdoh_scores) > 0 ? round(array_sum($cdoh_scores) / count($cdoh_scores), 1) : 0,
        'avg_ip' => count($ip_scores) > 0 ? round(array_sum($ip_scores) / count($ip_scores), 1) : 0,
        'avg_gap' => count($gap_scores) > 0 ? round(array_sum($gap_scores) / count($gap_scores), 1) : 0,
        'total_sections' => count($data['sections']),
        'sections_above_70' => count(array_filter($cdoh_scores, function($s) { return $s >= 70; })),
        'sections_above_50' => count(array_filter($cdoh_scores, function($s) { return $s >= 50; })),
        'max_gap_section' => ''
    );

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
    $critical_sections = array();
    foreach ($assessment_data['sections'] as $key => $section) {
        $critical_sections[] = array(
            'key' => $key,
            'label' => getSectionLabel($key),
            'cdoh' => $section['cdoh_percent'],
            'ip' => $section['ip_percent'],
            'gap' => $section['gap']
        );
    }
    usort($critical_sections, function($a, $b) {
        return $a['cdoh'] <=> $b['cdoh'];
    });
    $critical_sections = array_slice($critical_sections, 0, 5);

    // Generate AI-powered recommendations
    $recommendations = generateAIRecs($assessment_data, $conn);

    // Build the workplan structure
    $workplan = array(
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
        'all_sections' => $assessment_data['sections'],
        'comments_by_section' => $assessment_data['comments_by_section'] ?? array(),
        'summary' => $assessment_data['summary']
    );

    return $workplan;
}

function generateAIRecs($assessment_data, $conn) {
    $recommendations = array();
    $sections = $assessment_data['sections'];

    // Section-specific recommendations based on scores
    $section_recs = array(
        'leadership' => array(
            'threshold' => 60,
            'recs' => array(
                'Schedule quarterly County Assembly Health Committee meetings with documented decisions',
                'Establish a County HIV/TB Technical Working Group with clear terms of reference',
                'Develop a multi-year strategic plan for HIV/TB services aligned with CIDP',
                'Create a county-level HIV/TB coordination forum with quarterly review meetings',
                'Institutionalize the County AIDS Committee with regular reporting mechanisms'
            )
        ),
        'finance' => array(
            'threshold' => 55,
            'recs' => array(
                'Integrate HIV/TB budget into the County Annual Development Plan (ADP)',
                'Train County Treasury staff on HIV/TB program budgeting and tracking',
                'Establish a dedicated budget line for HIV/TB commodities and supplies',
                'Develop a costed sustainability plan for HIV/TB services',
                'Conduct quarterly financial reviews with the County Health Management Team'
            )
        ),
        'hr_management' => array(
            'threshold' => 55,
            'recs' => array(
                'Fast-track absorption of IP-supported staff through the County Public Service Board',
                'Conduct workload analysis to determine optimal staffing levels',
                'Develop and implement task-shifting guidelines to optimize human resources',
                'Establish performance appraisal system for HIV/TB program staff',
                'Create succession plans for key HIV/TB program positions'
            )
        ),
        'commodities' => array(
            'threshold' => 65,
            'recs' => array(
                'Transfer KEMSA/NASCOP ordering credentials to County Pharmacist',
                'Train facility-level staff on integrated supply chain management',
                'Establish a county-level commodity review committee',
                'Implement real-time stock monitoring dashboard',
                'Develop SOPs for emergency commodity distribution'
            )
        ),
        'data_management' => array(
            'threshold' => 60,
            'recs' => array(
                'Transfer ownership of all digital dashboards and EMR systems to County HIS',
                'Train county M&E officers on advanced DHIS2 and DATIM analysis',
                'Establish a Data Quality Assurance (DQA) team within the county',
                'Develop quarterly data review meetings led by County M&E',
                'Implement data validation protocols at facility level'
            )
        ),
        'supervision' => array(
            'threshold' => 55,
            'recs' => array(
                'Transition from IP-led to CDOH-led supervision visits',
                'Allocate county budget for supervision logistics (fuel, per diems)',
                'Develop standardized supervision checklists and reporting templates',
                'Establish quarterly supervision feedback mechanisms',
                'Integrate supervision findings into facility QI plans'
            )
        ),
        'retention_suppression' => array(
            'threshold' => 50,
            'recs' => array(
                'Strengthen defaulter tracking systems at facility level',
                'Establish patient support groups with county funding',
                'Implement enhanced adherence counseling programs',
                'Develop community linkage frameworks for patient follow-up',
                'Monitor viral load suppression rates at site level'
            )
        ),
        'laboratory' => array(
            'threshold' => 60,
            'recs' => array(
                'Train laboratory staff on EQA and proficiency testing',
                'Establish specimen transport systems with county resources',
                'Implement laboratory quality management systems (QMS)',
                'Develop service and maintenance contracts for equipment',
                'Monitor turnaround times for test results'
            )
        ),
        'training' => array(
            'threshold' => 55,
            'recs' => array(
                'Conduct annual training needs assessment',
                'Allocate county budget for in-service training',
                'Train master trainers within the county to sustain capacity',
                'Utilize iHRIS Train for training records management',
                'Develop a mentorship program for new staff'
            )
        ),
        'institutional_ownership' => array(
            'threshold' => 65,
            'recs' => array(
                'Develop county-specific HIV/TB annual work plans based on national frameworks',
                'Cost all HIV/TB activities and integrate into national budget requests',
                'Establish multi-stakeholder coordination forums with CSOs and PLHIV',
                'Ensure alignment between county strategic plans and national HIV/TB frameworks',
                'Regularly track implementation of county HIV/TB work plans'
            )
        )
    );

    // Comments from raw_scores and section-level (passed via assessment_data)
    $comments_by_section = isset($assessment_data['comments_by_section']) ? $assessment_data['comments_by_section'] : array();

    // Generate recommendations based on actual scores
    foreach ($sections as $key => $section) {
        $label = getSectionLabel($key);
        $cdoh = $section['cdoh_percent'];
        $gap = $section['gap'];

        // Build a comments narrative for this section if comments exist
        $comments_narrative = '';
        if (!empty($comments_by_section[$key])) {
            $sec_comments  = array(); // section-level comments
            $ind_comments  = array(); // indicator-level comments
            foreach ($comments_by_section[$key] as $c) {
                if ($c['type'] === 'section') {
                    $sec_comments[] = $c['comment'];
                } else {
                    $ind_comments[] = '[' . $c['sub'] . '] ' . $c['comment'];
                }
            }
            $narrative_parts = array();
            if (!empty($sec_comments)) {
                $narrative_parts[] = implode(' ', $sec_comments);
            }
            if (!empty($ind_comments)) {
                $narrative_parts[] = 'Indicator-level notes: ' . implode('; ', array_slice($ind_comments, 0, 5));
            }
            if (!empty($narrative_parts)) {
                $comments_narrative = 'From the comments in the ' . $label . ' section: ' . implode(' ', $narrative_parts);
            }
        }

        // Low CDOH score - needs capacity building
        if ($cdoh < 50) {
            $recommendations[] = array(
                'section' => $label,
                'priority' => 'Critical',
                'type' => 'Capacity Building',
                'message' => "{$label} shows significant gaps (CDOH: {$cdoh}%). Immediate capacity building required. Focus on institutionalizing basic structures and processes before transitioning advanced functions.",
                'action_items' => getDefaultActions($key, 'low'),
                'comments_narrative' => $comments_narrative
            );
        }
        // Medium CDOH score with high IP gap - needs transition planning
        elseif ($cdoh >= 50 && $cdoh < 70 && $gap > 15) {
            $recommendations[] = array(
                'section' => $label,
                'priority' => 'High',
                'type' => 'Transition Planning',
                'message' => "{$label} shows moderate CDOH capacity ({$cdoh}%) but high dependency on IP (gap: {$gap}%). Phased transition needed with clear handover milestones.",
                'action_items' => getDefaultActions($key, 'medium'),
                'comments_narrative' => $comments_narrative
            );
        }
        // High CDOH score - ready for handover
        elseif ($cdoh >= 70) {
            $recommendations[] = array(
                'section' => $label,
                'priority' => 'Routine',
                'type' => 'Final Handover',
                'message' => "{$label} demonstrates strong county ownership ({$cdoh}%). Accelerate final handover and documentation.",
                'action_items' => getDefaultActions($key, 'high'),
                'comments_narrative' => $comments_narrative
            );
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
        $priority_order = array('Critical' => 1, 'High' => 2, 'Routine' => 3);
        return $priority_order[$a['priority']] <=> $priority_order[$b['priority']];
    });

    return $recommendations;
}

function getDefaultActions($section_key, $level) {
    $actions = array(
        'low' => array(
            'Conduct rapid assessment to identify root causes of capacity gaps',
            'Develop a 3-month intensive mentorship program with dedicated mentors',
            'Establish weekly review meetings with County Health Management Team',
            'Create simplified SOPs and job aids for key functions'
        ),
        'medium' => array(
            'Develop a phased transition plan with clear milestones',
            'Train county staff to shadow IP staff for 3 months',
            'Transfer responsibility for key functions gradually over 6 months',
            'Establish performance monitoring framework with county leadership'
        ),
        'high' => array(
            'Schedule final handover meeting with documentation sign-off',
            'Transfer all assets and intellectual property to county',
            'Conduct final training on sustainable management practices',
            'Provide post-handover support hotline for 3 months'
        )
    );

    return $actions[$level];
}

function getSectionLabel($key) {
    $labels = array(
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
    );
    return isset($labels[$key]) ? $labels[$key] : ucfirst(str_replace('_', ' ', $key));
}

function getWorkplanHTML($workplan) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Transition Workplan - <?= htmlspecialchars($workplan['county']) ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: white;
                color: #333;
                line-height: 1.6;
                padding: 30px;
            }
            .container { max-width: 1200px; margin: 0 auto; }

            .page-header {
                background: #0D1A63;
                color: #fff;
                padding: 20px 25px;
                border-radius: 8px;
                margin-bottom: 20px;
                text-align: center;
            }
            .page-header h1 { font-size: 1.8rem; margin-bottom: 5px; }
            .page-header .subtitle { font-size: 0.9rem; opacity: 0.9; }

            .workplan-meta {
                background: #f8fafc;
                border: 1px solid #e0e4f0;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }
            .meta-item { text-align: center; padding: 8px; }
            .meta-item .label { font-size: 10px; font-weight: 700; color: #666; text-transform: uppercase; }
            .meta-item .value { font-size: 16px; font-weight: 800; color: #0D1A63; margin-top: 5px; }

            .readiness-badge {
                display: inline-block;
                padding: 5px 12px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 12px;
            }
            .badge-transition { background: #d4edda; color: #155724; }
            .badge-support { background: #fff3cd; color: #856404; }
            .badge-not-ready { background: #f8d7da; color: #721c24; }

            .section-title {
                font-size: 1.2rem;
                font-weight: 700;
                color: #0D1A63;
                margin: 25px 0 15px;
                padding-bottom: 8px;
                border-bottom: 3px solid #0D1A63;
            }

            .card {
                background: #fff;
                border: 1px solid #e0e4f0;
                border-radius: 8px;
                margin-bottom: 20px;
                overflow: hidden;
            }
            .card-header {
                background: #f8fafc;
                padding: 12px 15px;
                border-bottom: 1px solid #e0e4f0;
                font-weight: 700;
                color: #0D1A63;
            }
            .card-body { padding: 15px; }

            .recommendation-item {
                background: #f8fafc;
                border-left: 4px solid;
                padding: 12px;
                margin-bottom: 12px;
                border-radius: 6px;
            }
            .rec-critical { border-left-color: #dc3545; }
            .rec-high { border-left-color: #fd7e14; }
            .rec-routine { border-left-color: #28a745; }

            .priority-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 9px;
                font-weight: 700;
            }
            .priority-critical { background: #f8d7da; color: #721c24; }
            .priority-high { background: #ffe5d0; color: #fd7e14; }
            .priority-routine { background: #d4edda; color: #155724; }

            .action-list { margin-top: 8px; padding-left: 20px; }
            .action-list li { margin: 3px 0; font-size: 12px; }

            .timeline-table, .compare-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            .timeline-table th, .compare-table th {
                background: #0D1A63;
                color: #fff;
                padding: 8px;
                text-align: left;
            }
            .timeline-table td, .compare-table td {
                padding: 8px;
                border-bottom: 1px solid #e0e4f0;
            }

            .phase-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 10px;
                font-weight: 600;
            }
            .phase-1 { background: #cfe2ff; color: #004085; }
            .phase-2 { background: #fff3cd; color: #856404; }
            .phase-3 { background: #d4edda; color: #155724; }

            .footer-note {
                margin-top: 30px;
                padding: 15px;
                text-align: center;
                font-size: 10px;
                color: #666;
                border-top: 1px solid #e0e4f0;
            }

            @media print {
                body { padding: 0; }
                .no-print { display: none; }
                .page-break { page-break-before: always; }
            }
        </style>
    </head>
    <body>
    <div class="container">
        <div class="page-header">
            <h1>Transition Workplan</h1>
            <div class="subtitle">Implementing Partner-to-County Government Handover Plan</div>
            <div style="margin-top: 10px;"><?= htmlspecialchars($workplan['county']) ?> County | <?= htmlspecialchars($workplan['period']) ?></div>
        </div>

        <!-- Workplan Meta Information -->
        <div class="workplan-meta">
            <div class="meta-item"><div class="label">County</div><div class="value"><?= htmlspecialchars($workplan['county']) ?></div></div>
            <div class="meta-item"><div class="label">Assessment Period</div><div class="value"><?= htmlspecialchars($workplan['period']) ?></div></div>
            <div class="meta-item"><div class="label">Assessment Date</div><div class="value"><?= date('d M Y', strtotime($workplan['assessment_date'])) ?></div></div>
            <div class="meta-item"><div class="label">Overall Readiness</div><div class="value"><span class="readiness-badge <?= $workplan['overall_readiness'] == 'Transition' ? 'badge-transition' : ($workplan['overall_readiness'] == 'Support and Monitor' ? 'badge-support' : 'badge-not-ready') ?>"><?= $workplan['overall_readiness'] ?: 'Not Rated' ?></span></div></div>
            <div class="meta-item"><div class="label">Transition Timeline</div><div class="value"><?= $workplan['timeline_months'] ?> Months</div></div>
        </div>

        <!-- Executive Summary -->
        <div class="card">
            <div class="card-header">Executive Summary</div>
            <div class="card-body">
                <p>Based on the transition assessment conducted in <strong><?= $workplan['period'] ?></strong>,
                <?= htmlspecialchars($workplan['county']) ?> County demonstrates an average CDOH autonomy score of <strong><?= $workplan['avg_cdoh'] ?>%</strong>
                and an average IP involvement score of <strong><?= $workplan['avg_ip'] ?>%</strong>. The overall gap between IP involvement and county autonomy is
                <strong><?= $workplan['avg_gap'] ?>%</strong>, indicating areas where transition support is critical.</p>
                <p style="margin-top: 10px;">The county is classified as <strong><?= $workplan['overall_readiness'] ?: 'Not Rated' ?></strong>.
                A <strong><?= $workplan['timeline_months'] ?>-month transition period</strong> is recommended, running from
                <strong><?= $workplan['start_date'] ?></strong> to <strong><?= $workplan['end_date'] ?></strong>.</p>
            </div>
        </div>

        <!-- Critical Sections -->
        <div class="section-title">Critical Sections Requiring Immediate Attention</div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Section</th><th>CDOH Score</th><th>IP Score</th><th>Gap</th><th>Priority Level</th> </tr>
                        </thead>
                    <tbody>
                        <?php foreach ($workplan['critical_sections'] as $section): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($section['label']) ?></strong></td>
                            <td><?= round($section['cdoh']) ?>%</td>
                            <td><?= $section['ip'] ? round($section['ip']) . '%' : 'N/A' ?></td>
                            <td><?= $section['gap'] > 0 ? '+' . round($section['gap']) . '%' : '0%' ?></td>
                            <td><span class="priority-badge <?= $section['cdoh'] < 50 ? 'priority-critical' : ($section['cdoh'] < 70 ? 'priority-high' : 'priority-routine') ?>"><?= $section['cdoh'] < 50 ? 'Critical' : ($section['cdoh'] < 70 ? 'High' : 'Routine') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AI-Powered Recommendations -->
        <div class="section-title">AI-Powered Recommendations</div>
        <div class="card">
            <div class="card-body">
                <?php foreach ($workplan['recommendations'] as $rec):
                    $rec_class = $rec['priority'] == 'Critical' ? 'rec-critical' : ($rec['priority'] == 'High' ? 'rec-high' : 'rec-routine');
                ?>
                <div class="recommendation-item <?= $rec_class ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 8px;">
                        <strong><?= htmlspecialchars($rec['section']) ?></strong>
                        <div>
                            <span class="priority-badge <?= $rec['priority'] == 'Critical' ? 'priority-critical' : ($rec['priority'] == 'High' ? 'priority-high' : 'priority-routine') ?>">
                                <?= $rec['priority'] ?> Priority
                            </span>
                            <span class="priority-badge" style="background: #e0e4f0; color: #666;"><?= $rec['type'] ?></span>
                        </div>
                    </div>
                    <p style="margin-bottom: 8px;"><?= htmlspecialchars($rec['message']) ?></p>
                    <?php if (!empty($rec['comments_narrative'])): ?>
                    <div style="background: #f0f4ff; border-left: 3px solid #2D008A; padding: 9px 12px; margin-bottom: 10px; border-radius: 4px; font-size: 12px; font-style: italic; color: #3a3a6a;">
                        <i class="fas fa-comment-alt" style="margin-right: 6px; color: #2D008A;"></i>
                        <?= htmlspecialchars($rec['comments_narrative']) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <strong>Key Action Items:</strong>
                        <ul class="action-list">
                            <?php foreach ($rec['action_items'] as $action): ?>
                            <li><?= htmlspecialchars($action) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (isset($rec['specific_actions'])): ?>
                        <strong style="margin-top: 8px; display: block;">Specific Technical Actions:</strong>
                        <ul class="action-list">
                            <?php foreach ($rec['specific_actions'] as $action): ?>
                            <li><?= htmlspecialchars($action) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Phased Transition Timeline -->
        <div class="section-title">Phased Transition Timeline</div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Phase</th><th>Duration</th><th>Key Activities</th><th>Responsible Parties</th> </tr>
                        </thead>
                    <tbody>
                        <tr>
                            <td><span class="phase-badge phase-1">Phase 1: Co-Management</span></td>
                            <td>Months 1-<?= floor($workplan['timeline_months'] / 3) ?></td>
                            <td><ul style="margin-left: 20px;"><li>IP and County staff work side-by-side</li><li>County assumes 25% of operational costs</li><li>Sign transition MOUs</li><li>Begin capacity building in critical areas</li></ul></td>
                            <td>IP + County Health Team</td>
                        </tr>
                        <tr>
                            <td><span class="phase-badge phase-2">Phase 2: Active Transition</span></td>
                            <td>Months <?= floor($workplan['timeline_months'] / 3) + 1 ?>-<?= floor($workplan['timeline_months'] * 2 / 3) ?></td>
                            <td><ul style="margin-left: 20px;"><li>IP shifts to advisory role; County leads implementation</li><li>County assumes 75% of operational costs</li><li>Complete transfer of assets and systems</li><li>County-led supervision and QI activities</li></ul></td>
                            <td>County Health Team (IP advisory)</td>
                        </tr>
                        <tr>
                            <td><span class="phase-badge phase-3">Phase 3: Full Handover & Exit</span></td>
                            <td>Months <?= floor($workplan['timeline_months'] * 2 / 3) + 1 ?>-<?= $workplan['timeline_months'] ?></td>
                            <td><ul style="margin-left: 20px;"><li>IP presence ceases</li><li>County assumes 100% financial and operational responsibility</li><li>Final M&E and close-out reporting</li><li>Post-handover support established</li></ul></td>
                            <td>County Health Team</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Risk Register -->
        <div class="section-title">Risk Register & Mitigation Strategies</div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Risk</th><th>Likelihood</th><th>Impact</th><th>Mitigation Strategy</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>County budget cycle misalignment</td><td>High</td><td>Critical</td><td>Align handover with County Fiscal Strategy Paper (CFSP); early engagement with County Assembly</td></tr>
                        <tr><td>Key county staff attrition</td><td>Medium</td><td>High</td><td>Succession planning; train at least 2 staff per function; knowledge transfer documentation</td></tr>
                        <tr><td>Data loss during transition</td><td>Medium</td><td>High</td><td>Data migration plan; backup all systems to county servers; sign-off from County HIS</td></tr>
                        <tr><td>Political instability/elections</td><td>Medium</td><td>Medium</td><td>Secure MOU signatures before political season; engage cross-party leadership</td></tr>
                        <tr><td>Loss of institutional knowledge</td><td>Low</td><td>Medium</td><td>Comprehensive documentation; exit interviews; mentorship program with IP staff</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- M&E Framework -->
        <div class="section-title">Transition Monitoring Framework</div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Indicator</th><th>Baseline</th><th>Target (End of Transition)</th><th>Data Source</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Average CDOH Score (all sections)</td><td><?= $workplan['avg_cdoh'] ?>%</td><td><?= min(100, $workplan['avg_cdoh'] + 15) ?>%</td><td>Transition Dashboard</td></tr>
                        <tr><td>Sections with CDOH Score =70%</td><td><?= $workplan['summary']['sections_above_70'] ?? 0 ?>/<?= $workplan['summary']['total_sections'] ?? 0 ?></td><td><?= min($workplan['summary']['total_sections'] ?? 0, ($workplan['summary']['sections_above_70'] ?? 0) + 5) ?>/<?= $workplan['summary']['total_sections'] ?? 0 ?></td><td>Comparison Dashboard</td></tr>
                        <tr><td>IP-supported staff absorbed</td><td>0%</td><td>100%</td><td>HR Handover Notes</td></tr>
                        <tr><td>HIV/TB budget line in County ADP</td><td>No</td><td>Yes</td><td>County Annual Development Plan</td></tr>
                        <tr><td>Counties with reduced gap (IP - CDOH)</td><td><?= $workplan['avg_gap'] ?>% avg gap</td><td>&lt;10% avg gap</td><td>Comparison Dashboard</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($workplan['comments_by_section'])): ?>
        <!-- Assessor Field Notes Summary -->
        <div class="section-title">Assessor Field Notes Summary</div>
        <div class="card">
            <div class="card-body">
                <p style="font-size:12px; color:#666; margin-bottom:14px;">
                    The following are verbatim notes recorded by the assessor during the field assessment, organised by section.
                </p>
                <?php foreach ($workplan['comments_by_section'] as $sec_key => $sec_notes): ?>
                <div style="margin-bottom:16px;">
                    <div style="font-weight:700; color:#2D008A; font-size:13px; margin-bottom:6px;">
                        <i class="fas fa-folder-open" style="margin-right:6px;"></i>
                        <?= htmlspecialchars(getSectionLabel($sec_key)) ?>
                    </div>
                    <?php foreach ($sec_notes as $note): ?>
                    <div style="background:#fdfcf9; border-left:3px solid <?= $note['type']==='section' ? '#04B04B' : '#AC80EE' ?>; padding:7px 12px; margin-bottom:5px; border-radius:4px; font-size:12px;">
                        <?php if ($note['type'] === 'section'): ?>
                            <em style="color:#04B04B; font-size:10px; display:block; margin-bottom:2px;">Section comment</em>
                        <?php else: ?>
                            <em style="color:#555; font-size:10px; display:block; margin-bottom:2px;"><?= htmlspecialchars($note['sub']) ?></em>
                        <?php endif; ?>
                        <?= htmlspecialchars($note['comment']) ?>
                        <?php if ($note['score'] !== null): ?>
                            <span style="float:right; font-weight:700; color:#2D008A;">Score: <?= $note['score'] ?>/4</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="footer-note">
            This workplan was generated based on assessment data and assessor field notes from the Transition Benchmarking Tool.<br>
            Generated on: <?= date('d F Y H:i:s') ?>
        </div>
    </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function exportToPDF($workplan, $conn) {
    $html = getWorkplanHTML($workplan);

    try {
        // Use fully qualified class names
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Output PDF
        $filename = "Transition_Workplan_" . str_replace(' ', '_', $workplan['county']) . "_" . date('Ymd') . ".pdf";
        $dompdf->stream($filename, array('Attachment' => true));
    } catch (Exception $e) {
        die('Error generating PDF: ' . $e->getMessage());
    }
    exit();
}

function exportToWord($workplan, $conn) {
    $html = getWorkplanHTML($workplan);

    // Add Word-specific meta tags
    $html = str_replace('</head>',
        '<meta charset="UTF-8">
        <meta name="generator" content="Microsoft Word 15">
        <meta name="ProgId" content="Word">
        <style>
            @page { size: A4; margin: 2.54cm; }
            body { margin: 0; padding: 20px; }
        </style>
        </head>',
        $html);

    // Set headers for Word download
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="Transition_Workplan_' . str_replace(' ', '_', $workplan['county']) . '_' . date('Ymd') . '.doc"');
    header('Cache-Control: max-age=0');

    echo $html;
    exit();
}

function renderWorkplan($workplan, $assessment_data, $conn) {
    $html = getWorkplanHTML($workplan);

    // Add Font Awesome and export buttons for web view only
    $html = str_replace('</head>',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        </head>',
        $html);

    // Add export buttons to the HTML for web view
    $html = str_replace('</body>', '
    <div style="position: fixed; bottom: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000;" class="no-print">
        <a href="?county=' . $assessment_data['county_id'] . '&assessment_id=' . $assessment_data['assessment_id'] . '&export=pdf"
           style="background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
        <a href="?county=' . $assessment_data['county_id'] . '&assessment_id=' . $assessment_data['assessment_id'] . '&export=word"
           style="background: #0D1A63; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-file-word"></i> Export Word
        </a>
        <button onclick="window.print()"
                style="background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
    </body>', $html);

    echo $html;
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
            <a href="transition_workplan.php" class="btn" style="background: #6c757d; margin-left: 10px;"><i class="fas fa-arrow-left"></i> Go Back</a>
        </div>
    </body>
    </html>
    <?php
}
