<?php
// transitions/transition_assessment.php
// Start output buffering to prevent premature output
ob_start();

session_start();
include('../includes/config.php');
include('../includes/session_check.php');

// Set JSON header for AJAX responses early
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Check if this is an AJAX request
if ($is_ajax || isset($_POST['ajax_save_section']) || isset($_POST['ajax_submit']) || isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Session expired. Please login again.']);
        exit();
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$collected_by = $_SESSION['full_name'] ?? '';
$uid = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = in_array($user_role, ['Admin', 'Super Admin']);

// Get parameters
$county_id = isset($_GET['county']) ? (int)$_GET['county'] : 0;
$period = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';
$sections = isset($_GET['sections']) ? explode(',', $_GET['sections']) : [];

// Determine mode
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$view_only = isset($_GET['view']) && !isset($_GET['edit']);

// -- AJAX: check existing assessment -----------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_assessment') {
    $cid    = (int)($_GET['county_id'] ?? 0);
    $period = mysqli_real_escape_string($conn, $_GET['period'] ?? '');
    $result = ['exists' => false];
    if ($cid && $period) {
        $row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT assessment_id, assessment_status, readiness_level, county_name, assessment_date
             FROM transition_assessments
             WHERE county_id=$cid AND assessment_period='$period'
             ORDER BY assessment_id DESC LIMIT 1"));
        if ($row) {
            $ss_result = mysqli_query($conn,
                "SELECT section_key, submitted_at, sub_count, avg_cdoh, avg_ip
                 FROM transition_section_submissions WHERE assessment_id = {$row['assessment_id']}");
            $ss = [];
            while ($r = mysqli_fetch_assoc($ss_result)) $ss[$r['section_key']] = $r;
            $result = [
                'exists'         => true,
                'assessment_id'  => $row['assessment_id'],
                'status'         => $row['assessment_status'],
                'readiness_level'=> $row['readiness_level'],
                'county_name'    => $row['county_name'],
                'sections_saved' => array_keys($ss),
                'section_data'   => $ss
            ];
        }
    }
    echo json_encode($result);
    exit();
}

// -- AJAX: save a single section ---------------------------------------------
if (isset($_POST['ajax_save_section'])) {
    $section_key     = mysqli_real_escape_string($conn, $_POST['section_key'] ?? '');
    $cid             = (int)($_POST['county_id'] ?? 0);
    $period_safe     = mysqli_real_escape_string($conn, $_POST['assessment_period'] ?? '');
    $aid             = (int)($_POST['assessment_id'] ?? 0);
    $assessed_by     = mysqli_real_escape_string($conn, $collected_by);
    $assessment_date = mysqli_real_escape_string($conn, $_POST['assessment_date'] ?? date('Y-m-d'));
    $county_name     = mysqli_real_escape_string($conn, $_POST['county_name'] ?? '');

    if (!$cid || !$period_safe || !$section_key) {
        echo json_encode(['success'=>false,'error'=>'Missing required fields']);
        exit();
    }

    // -- Get or create assessment header -------------------------------------
    if (!$aid) {
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT assessment_id FROM transition_assessments
             WHERE county_id=$cid AND assessment_period='$period_safe' ORDER BY assessment_id DESC LIMIT 1"));
        if ($existing) {
            $aid = (int)$existing['assessment_id'];
        } else {
            mysqli_query($conn,
                "INSERT INTO transition_assessments
                 (county_id, county_name, assessment_period, assessment_date, assessed_by, assessment_status, readiness_level)
                 VALUES ($cid, '$county_name', '$period_safe', '$assessment_date', '$assessed_by', 'Draft', 'Not Rated')");
            $aid = (int)mysqli_insert_id($conn);
        }
    }

    // -- Delete existing raw scores for this section -------------------------
    mysqli_query($conn,
        "DELETE FROM transition_raw_scores
         WHERE assessment_id=$aid AND section_key='$section_key'");

    $saved = 0;
    $sum_cdoh = 0; $cnt_cdoh = 0;
    $sum_ip   = 0; $cnt_ip   = 0;

    // Process scores array: scores[composite_key][cdoh/ip/comments]
    if (!empty($_POST['scores']) && is_array($_POST['scores'])) {
        foreach ($_POST['scores'] as $composite_key => $vals) {
            $ck_safe  = mysqli_real_escape_string($conn, $composite_key);
            $parts    = explode('_', $composite_key);
            $sub_code = end($parts);
            $sub_safe = mysqli_real_escape_string($conn, $sub_code);
            $ind_code = preg_replace('/\.\d+$/', '', $sub_code);
            $ind_safe = mysqli_real_escape_string($conn, $ind_code);

            $cdoh  = isset($vals['cdoh']) && $vals['cdoh'] !== '' ? (int)$vals['cdoh'] : 'NULL';
            $ip    = isset($vals['ip'])   && $vals['ip']   !== '' ? (int)$vals['ip']   : 'NULL';
            $comm  = mysqli_real_escape_string($conn, $vals['comments'] ?? '');

            if ($cdoh === 'NULL' && $ip === 'NULL') continue;

            mysqli_query($conn,
                "INSERT INTO transition_raw_scores
                 (assessment_id, section_key, indicator_code, sub_indicator_code,
                  composite_key, cdoh_score, ip_score, comments, scored_by)
                 VALUES ($aid,'$section_key','$ind_safe','$sub_safe',
                         '$ck_safe',$cdoh,$ip,'$comm','$assessed_by')
                 ON DUPLICATE KEY UPDATE
                   cdoh_score=VALUES(cdoh_score), ip_score=VALUES(ip_score),
                   comments=VALUES(comments), scored_at=NOW()");
            $saved++;
            if ($cdoh !== 'NULL') { $sum_cdoh += $cdoh; $cnt_cdoh++; }
            if ($ip   !== 'NULL') { $sum_ip   += $ip;   $cnt_ip++;   }
        }
    }

    // Also process direct radio inputs (not nested in scores array)
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'scores[') === 0 && !is_array($value)) {
            // Parse composite key from name like scores[composite_key][cdoh]
            if (preg_match('/scores\[([^\]]+)\]\[([^\]]+)\]/', $key, $matches)) {
                $composite_key = $matches[1];
                $type = $matches[2]; // 'cdoh' or 'ip'

                $ck_safe  = mysqli_real_escape_string($conn, $composite_key);
                $parts    = explode('_', $composite_key);
                $sub_code = end($parts);
                $sub_safe = mysqli_real_escape_string($conn, $sub_code);
                $ind_code = preg_replace('/\.\d+$/', '', $sub_code);
                $ind_safe = mysqli_real_escape_string($conn, $ind_code);

                $score_val = $value !== '' ? (int)$value : 'NULL';

                // Check if record exists
                $existing = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT id, cdoh_score, ip_score FROM transition_raw_scores
                     WHERE assessment_id=$aid AND composite_key='$ck_safe'"));

                if ($existing) {
                    if ($type == 'cdoh') {
                        mysqli_query($conn,
                            "UPDATE transition_raw_scores SET cdoh_score=$score_val, scored_at=NOW()
                             WHERE assessment_id=$aid AND composite_key='$ck_safe'");
                        if ($score_val !== 'NULL') { $sum_cdoh += $score_val; $cnt_cdoh++; }
                    } else if ($type == 'ip') {
                        mysqli_query($conn,
                            "UPDATE transition_raw_scores SET ip_score=$score_val, scored_at=NOW()
                             WHERE assessment_id=$aid AND composite_key='$ck_safe'");
                        if ($score_val !== 'NULL') { $sum_ip += $score_val; $cnt_ip++; }
                    }
                } else {
                    mysqli_query($conn,
                        "INSERT INTO transition_raw_scores
                         (assessment_id, section_key, indicator_code, sub_indicator_code,
                          composite_key, cdoh_score, ip_score, scored_by)
                         VALUES ($aid,'$section_key','$ind_safe','$sub_safe',
                                 '$ck_safe',
                                 " . ($type == 'cdoh' ? $score_val : 'NULL') . ",
                                 " . ($type == 'ip' ? $score_val : 'NULL') . ",
                                 '$assessed_by')");
                    if ($type == 'cdoh' && $score_val !== 'NULL') { $sum_cdoh += $score_val; $cnt_cdoh++; }
                    if ($type == 'ip' && $score_val !== 'NULL') { $sum_ip += $score_val; $cnt_ip++; }
                }
                $saved++;
            }
        }
    }

    $avg_c_val = $cnt_cdoh > 0 ? round($sum_cdoh/$cnt_cdoh, 2) : 'NULL';
    $avg_i_val = $cnt_ip   > 0 ? round($sum_ip/$cnt_ip,     2) : 'NULL';

    // Upsert section submission record
    mysqli_query($conn,
        "INSERT INTO transition_section_submissions
         (assessment_id, county_id, assessment_period, section_key,
          submitted_by, sub_count, avg_cdoh, avg_ip)
         VALUES ($aid,$cid,'$period_safe','$section_key',
                 '$assessed_by',$saved,$avg_c_val,$avg_i_val)
         ON DUPLICATE KEY UPDATE
           county_id=$cid, assessment_period='$period_safe',
           submitted_by='$assessed_by', submitted_at=NOW(),
           sub_count=$saved, avg_cdoh=$avg_c_val, avg_ip=$avg_i_val");

    // Update assessment readiness
    $ov = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT AVG(cdoh_score) as avg_cdoh_raw, AVG(ip_score) as avg_ip_raw,
                COUNT(*) as total_scored
         FROM transition_raw_scores WHERE assessment_id=$aid"));
    $oc_raw = $ov['avg_cdoh_raw'] !== null ? (float)$ov['avg_cdoh_raw'] : 0;
    $oi_raw = $ov['avg_ip_raw']   !== null ? (float)$ov['avg_ip_raw']   : 0;
    $total_scored = (int)$ov['total_scored'];

    $oc_pct = $total_scored > 0 ? round(($oc_raw / 4) * 100) : 0;
    $rd = $oc_pct >= 70 ? 'Transition' : ($oc_pct >= 50 ? 'Support and Monitor' : 'Not Ready');

    mysqli_query($conn,
        "UPDATE transition_assessments
         SET assessment_status='Draft', readiness_level='$rd',
             last_saved_at=NOW(), last_saved_by='$assessed_by'
         WHERE assessment_id=$aid");

    // Get updated sections list
    $ss_result = mysqli_query($conn,
        "SELECT section_key, submitted_at, sub_count, avg_cdoh, avg_ip
         FROM transition_section_submissions WHERE assessment_id=$aid");
    $all_ss = [];
    while ($r = mysqli_fetch_assoc($ss_result)) $all_ss[$r['section_key']] = $r;

    echo json_encode([
        'success'        => true,
        'assessment_id'  => $aid,
        'section_key'    => $section_key,
        'saved'          => $saved,
        'submitted_at'   => date('d M Y H:i'),
        'avg_cdoh_pct'   => $avg_c_val !== 'NULL' ? round((float)$avg_c_val/4*100) : 0,
        'avg_ip_pct'     => $avg_i_val !== 'NULL' ? round((float)$avg_i_val/4*100) : 0,
        'overall_cdoh_pct'=> $oc_pct,
        'readiness_level' => $rd,
        'sections_saved'  => array_keys($all_ss),
        'section_data'    => $all_ss
    ]);
    exit();
}

// -- AJAX: final submit -------------------------------------------------------
if (isset($_POST['ajax_submit'])) {
    $aid = (int)($_POST['assessment_id'] ?? 0);
    if ($aid) {
        // Calculate final scores
        $ov = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(cdoh_score) as avg_cdoh_raw, AVG(ip_score) as avg_ip_raw,
                    COUNT(*) as total_scored
             FROM transition_raw_scores WHERE assessment_id=$aid"));
        $oc_raw = $ov['avg_cdoh_raw'] !== null ? (float)$ov['avg_cdoh_raw'] : 0;
        $total_scored = (int)$ov['total_scored'];
        $oc_pct = $total_scored > 0 ? round(($oc_raw / 4) * 100) : 0;
        $rd = $oc_pct >= 70 ? 'Transition' : ($oc_pct >= 50 ? 'Support and Monitor' : 'Not Ready');

        mysqli_query($conn,
            "UPDATE transition_assessments
             SET assessment_status='Submitted', readiness_level='$rd',
                 submitted_at=NOW(), submitted_by='".mysqli_real_escape_string($conn,$collected_by)."',
                 last_saved_at=NOW(), last_saved_by='".mysqli_real_escape_string($conn,$collected_by)."'
             WHERE assessment_id=$aid");
        echo json_encode(['success'=>true,'redirect'=>'transition_dashboard.php?county='.$_POST['county_id']]);
    } else {
        echo json_encode(['success'=>false,'error'=>'No assessment ID']);
    }
    exit();
}

// Redirect if missing required params and not editing/viewing
if (!$edit_id && (!$county_id || !$period || empty($sections))) {
    header('Location: transition_index.php');
    exit();
}

// -- Load existing assessment if editing or viewing ---------------------------
$existing = null;
$sections_saved = [];
$submitted_sections = [];
$is_readonly = $view_only;
$existing_raw = [];

if ($edit_id) {
    $existing = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM transition_assessments WHERE assessment_id=$edit_id LIMIT 1"));
    if ($existing) {
        $county_id = (int)$existing['county_id'];
        $period = $existing['assessment_period'];
        $sections = isset($existing['sections_selected']) ?
            json_decode($existing['sections_selected'], true) : [];
        if (empty($sections)) $sections = array_keys($all_sections);

        // Load section submissions
        $ss_result = mysqli_query($conn,
            "SELECT section_key, submitted_at, sub_count, avg_cdoh, avg_ip
             FROM transition_section_submissions WHERE assessment_id=$edit_id");
        while ($r = mysqli_fetch_assoc($ss_result)) {
            $submitted_sections[$r['section_key']] = $r;
            $sections_saved[] = $r['section_key'];
        }

        // If submitted and not admin, force read-only
        if (($existing['assessment_status'] === 'Submitted') && !$is_admin) {
            $is_readonly = true;
        }
    }
}

// If no edit_id but county+period provided, check for existing
$assessment_id = 0;
if (!$edit_id && $county_id && $period) {
    $chk = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT assessment_id, assessment_status, readiness_level
         FROM transition_assessments
         WHERE county_id=$county_id AND assessment_period='$period'
         ORDER BY assessment_date DESC LIMIT 1"));
    if ($chk) {
        $assessment_id = (int)$chk['assessment_id'];
        // Load existing raw scores
        $rr = mysqli_query($conn,
            "SELECT composite_key, cdoh_score, ip_score, comments
             FROM transition_raw_scores WHERE assessment_id = $assessment_id");
        if ($rr) while ($row = mysqli_fetch_assoc($rr)) {
            $existing_raw[$row['composite_key']] = $row;
        }
        // Load section submissions
        $sr = mysqli_query($conn,
            "SELECT section_key, submitted_at, sub_count, avg_cdoh, avg_ip
             FROM transition_section_submissions WHERE assessment_id = $assessment_id");
        if ($sr) while ($row = mysqli_fetch_assoc($sr)) {
            $submitted_sections[$row['section_key']] = $row;
            $sections_saved[] = $row['section_key'];
        }
    }
} elseif ($edit_id && $existing) {
    $assessment_id = $edit_id;
    // Load raw scores
    $rr = mysqli_query($conn,
        "SELECT composite_key, cdoh_score, ip_score, comments
         FROM transition_raw_scores WHERE assessment_id = $assessment_id");
    if ($rr) while ($row = mysqli_fetch_assoc($rr)) {
        $existing_raw[$row['composite_key']] = $row;
    }
}

// Get county name
$county_name = '';
if ($county_id) {
    $county_result = $conn->query("SELECT county_name FROM counties WHERE county_id = $county_id");
    if ($county_result && $county_result->num_rows > 0) {
        $county_name = $county_result->fetch_assoc()['county_name'];
    }
} elseif ($existing) {
    $county_name = $existing['county_name'] ?? '';
}

// Define scoring criteria
$scoring_criteria = [
    4 => ['label' => 'Fully adequate with evidence', 'class' => 'level-4'],
    3 => ['label' => 'Partially adequate with evidence', 'class' => 'level-3'],
    2 => ['label' => 'Structures/functions defined some evidence', 'class' => 'level-2'],
    1 => ['label' => 'Structures/functions defined NO evidence', 'class' => 'level-1'],
    0 => ['label' => 'Inadequate structures/functions', 'class' => 'level-0']
];

// Define all sections with their detailed indicators
$all_sections = [
    'leadership' => [
        'title' => 'COUNTY LEVEL LEADERSHIP AND GOVERNANCE',
        'icon' => 'fa-landmark',
        'color' => '#0D1A63',
        'has_ip' => false,
        'indicators' => [
            'T1' => [
                'code' => 'T1',
                'name' => 'Transition of County Legislature Health Leadership and Governance',
                'sub_indicators' => [
                    'T1.1' => 'Does the county have a legally constituted mechanism that oversees the health department? (e.g. County assembly health committee)',
                    'T1.2' => 'Does the county have an overall vision for the County Department of Health (CDOH) that is overseen by the County assembly health committee?',
                    'T1.3' => 'Are the roles of the County assembly health committee well-defined in the county health system?',
                    'T1.4' => 'Are County assembly health committee meetings held regularly as stipulated; decisions documented; and reflect accountability and resource stewardship?',
                    'T1.5' => 'Does the County assembly health committee composition include members who are recognized for leadership and/or area of expertise and are representative of stakeholders including PLHIV/TB patients?',
                    'T1.6' => 'Does the County assembly health committee ensure that public interest is considered in decision making?',
                    'T1.7' => 'How committed and accountable is the County assembly health committee in following up on agreed action items?',
                    'T1.8' => 'Does the County assembly health committee have a risk management policy/framework?',
                    'T1.9' => 'How much oversight is given to HIV/TB activities in the county by the health committee of the county assembly?',
                    'T1.10' => 'Is the leadership arrangement/structure for the HIV/TB program adequate to increase coverage and quality of HIV/TB services?',
                    'T1.11' => 'Does the HIV/TB program planning and funding allow for sustainability?'
                ]
            ],
            'T2' => [
                'code' => 'T2',
                'name' => 'Transition of County Executive (CHMT) in Health Leadership and Governance',
                'sub_indicators' => [
                    'T2.1' => 'Is the CHMT responsive to the requirements of the County\'s Oversight structures, i.e. County assembly health committee?',
                    'T2.2' => 'Is the CHMT accountable to clients/patients seeking services within the county?',
                    'T2.3' => 'Is the CHMT involving the private sector and community based organizations in the planning of health services including HIV/TB services?',
                    'T2.4' => 'Are CHMT meetings held regularly as stipulated; decisions documented including for the HIV/TB program; and reflect accountability and resource stewardship?',
                    'T2.5' => 'Is the CHMT implementing policies and regulations set by national level?',
                    'T2.6' => 'Does the CHMT hold joint monitoring teams and joint high-level meetings with development partners supporting the county?',
                    'T2.7' => 'Does the CHMT plan and manage health services to meet local needs?',
                    'T2.8' => 'Does the CHMT mobilize local resources for the HIV/TB program?',
                    'T2.9' => 'Is the CHMT involved in the supervision of HIV/TB services in the county?',
                    'T2.10' => 'Has the CHMT ensured that the leadership arrangement/structure for the HIV/TB program is adequate?',
                    'T2.11' => 'Has the CHMT ensured that the HIV/TB program planning and funding allow for sustainability?'
                ]
            ],
            'T3' => [
                'code' => 'T3',
                'name' => 'Transition of County Health Planning: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T3.1' => 'Creating a costed county annual work plan for HIV/TB services',
                    'T3.2' => 'Identifying key HIV program priorities that sustains good coverage and high HIV service quality',
                    'T3.3' => 'Track implementation of the costed county annual work plan for HIV/TB services',
                    'T3.4' => 'Identifying HRH needs for HIV/TB that will support the delivery of the agreed package of activities',
                    'T3.5' => 'Having in place a system for forecasting, including HRH needs for HIV/TB',
                    'T3.6' => 'Coordinating the scope of activities and resource contributions of all partners for HIV/TB in county',
                    'T3.7' => 'Convening meetings with key county HIV/TB services program staff and implementing partners to review performance',
                    'T3.8' => 'Convening meetings with community HIV/TB stakeholders to review community needs',
                    'T3.9' => 'Convening to review program performance for HIV/TB',
                    'T3.10' => 'Providing technical guidance for county AIDS/TB coordination',
                    'T3.11' => 'Providing support to the County AIDS Committee'
                ]
            ]
        ]
    ],
    'supervision' => [
        'title' => 'COUNTY LEVEL ROUTINE SUPERVISION AND MENTORSHIP',
        'icon' => 'fa-clipboard-check',
        'color' => '#1a3a9e',
        'has_ip' => true,
        'indicators' => [
            'T4A' => [
                'code' => 'T4A',
                'name' => 'Transition of routine Supervision and Mentorship: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T4A.1' => 'Developing the county HIV/TB programme routine supervision plan',
                    'T4A.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T4A.3' => 'Conducting routine supervision visits to county (public)/private/faith-based facilities',
                    'T4A.4' => 'Completing supervision checklist',
                    'T4A.5' => 'Mobilizing support to address issues identified during supervision',
                    'T4A.6' => 'Financial facilitation for county supervision (paying allowances to supervisors)',
                    'T4A.7' => 'Developing the action plan and following up on issues identified during the supervision',
                    'T4A.8' => 'Planning for staff mentorship including cross learning visits',
                    'T4A.9' => 'Spending time with staff to identify individual\'s strengths',
                    'T4A.10' => 'Identifying and working with facility staff to pursue mentorship goals',
                    'T4A.11' => 'Paying for mentorship activities',
                    'T4A.12' => 'Documenting outcomes of the mentorship'
                ]
            ],
            'T4B' => [
                'code' => 'T4B',
                'name' => 'Transition of routine Supervision and mentorship: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T4B.1' => 'Developing the county HIV/TB supervision plan',
                    'T4B.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T4B.3' => 'Conducting supervision visits to county (public)/private/faith-based facilities',
                    'T4B.4' => 'Completing supervision forms',
                    'T4B.5' => 'Mobilizing support to address issues identified during supervision',
                    'T4B.6' => 'Financial facilitation for county supervision (paying allowances to supervisors)',
                    'T4B.7' => 'Developing the action plan and following up on issues identified during the supervision',
                    'T4B.8' => 'Planning for staff mentorship including cross learning visits',
                    'T4B.9' => 'Spending time with staff to identify individual\'s strengths',
                    'T4B.10' => 'Identifying and working with facility staff to pursue mentorship goals',
                    'T4B.11' => 'Paying for mentorship activities',
                    'T4B.12' => 'Documenting outcomes of the mentorship'
                ]
            ]
        ]
    ],
    'special_initiatives' => [
        'title' => 'COUNTY LEVEL HIV/TB PROGRAM SPECIAL INITIATIVES (RRI, Leap, Surge, SIMS)',
        'icon' => 'fa-bolt',
        'color' => '#2a4ab0',
        'has_ip' => true,
        'indicators' => [
            'T5A' => [
                'code' => 'T5A',
                'name' => 'Transition of HIV/TB program special initiatives: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T5A.1' => 'Developing the county RRI, LEAP, Surge or SIMS plan or any other initiative',
                    'T5A.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T5A.3' => 'Conducting LEAP, SURGE, SIMS or RRI visits to public/private/faith based facilities',
                    'T5A.4' => 'Completing relevant initiative tools / reporting templates',
                    'T5A.5' => 'Mobilizing support to address issues identified during site visits',
                    'T5A.6' => 'Financial facilitation for site visits (paying allowances to the team)',
                    'T5A.7' => 'Developing the action plan and following up on issues identified during site visits',
                    'T5A.8' => 'Reporting special initiative implementation progress to higher levels'
                ]
            ],
            'T5B' => [
                'code' => 'T5B',
                'name' => 'Transition of HIV program special initiatives: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T5B.1' => 'Developing the county RRI, LEAP, Surge or SIMS plan or any other initiative',
                    'T5B.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T5B.3' => 'Conducting LEAP, SURGE, SIMS or RRI visits to public/private/faith based facilities',
                    'T5B.4' => 'Completing relevant initiative tools/ reporting templates',
                    'T5B.5' => 'Mobilizing support to address issues identified during site visits',
                    'T5B.6' => 'Financial facilitation for site visits (paying allowances to the team)',
                    'T5B.7' => 'Developing the action plan and following up on issues identified during site visits',
                    'T5B.8' => 'Reporting special initiative implementation progress to higher levels'
                ]
            ]
        ]
    ],
    'quality_improvement' => [
        'title' => 'COUNTY LEVEL QUALITY IMPROVEMENT',
        'icon' => 'fa-chart-line',
        'color' => '#3a5ac8',
        'has_ip' => true,
        'indicators' => [
            'T6A' => [
                'code' => 'T6A',
                'name' => 'Transition of Quality Improvement (QI): Level of Involvement of the IP',
                'sub_indicators' => [
                    'T6A.1' => 'Selecting priorities and developing / adapting QI plan',
                    'T6A.2' => 'Training facility staff',
                    'T6A.3' => 'Providing technical support to QI teams',
                    'T6A.4' => 'Reviewing/tracking facility QI reports',
                    'T6A.5' => 'Funding QI Initiatives',
                    'T6A.6' => 'Other support QI activities',
                    'T6A.7' => 'Convening/managing county-wide QI forum'
                ]
            ],
            'T6B' => [
                'code' => 'T6B',
                'name' => 'Transition of Quality Improvement: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T6B.1' => 'Selecting priorities and developing/adapting QI plan',
                    'T6B.2' => 'Training facility staff',
                    'T6B.3' => 'Providing technical support to QI teams',
                    'T6B.4' => 'Reviewing/tracking facility QI reports',
                    'T6B.5' => 'Funding QI Initiatives',
                    'T6B.6' => 'Other support QI activities',
                    'T6B.7' => 'Convening/managing county-wide QI forum'
                ]
            ]
        ]
    ],
    'identification_linkage' => [
        'title' => 'COUNTY LEVEL HIV/TB PATIENT IDENTIFICATION AND LINKAGE TO TREATMENT',
        'icon' => 'fa-user-plus',
        'color' => '#4a6ae0',
        'has_ip' => true,
        'indicators' => [
            'T7A' => [
                'code' => 'T7A',
                'name' => 'Transition of Patient identification and linkage to treatment: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T7A.1' => 'Recruitment of HIV testing services (HTS) counselors',
                    'T7A.2' => 'Remuneration of HIV testing counselors (Funds for paying HTS Counselors)',
                    'T7A.3' => 'Ensuring that HTS eligibility screening registers and SOPS are available',
                    'T7A.4' => 'Ensuring that HIV testing consumables/supplies are available',
                    'T7A.5' => 'Ensuring availability of adequate and appropriate HIV testing space/environment',
                    'T7A.6' => 'Ensuring effective procedures of linkage of HIV positive patients',
                    'T7A.7' => 'Ensuring documentation of linkage of HIV positive patients',
                    'T7A.8' => 'Training and providing refresher training to HIV testing counsellors',
                    'T7A.9' => 'HTS quality monitoring including conducting observed practices for HTS counsellors',
                    'T7A.10' => 'Providing transport and airtime for follow up and testing of sexual and other contacts',
                    'T7A.11' => 'Documenting, tracking and reporting ART, PEP and PrEP among those eligible'
                ]
            ],
            'T7B' => [
                'code' => 'T7B',
                'name' => 'Transition of Patient identification and linkage to treatment: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T7B.1' => 'Recruitment of HIV testing services (HTS) counselors',
                    'T7B.2' => 'Remuneration of HIV testing counselors (Funds for paying HTS Counselors)',
                    'T7B.3' => 'Ensuring that HTS eligibility screening registers and SOPS are available',
                    'T7B.4' => 'Ensuring that HIV testing consumables/supplies are available',
                    'T7B.5' => 'Ensuring availability of adequate and appropriate HIV testing space/environment',
                    'T7B.6' => 'Ensuring effective procedures of linkage of HIV positive patients',
                    'T7B.7' => 'Ensuring documentation of linkage of HIV positive patients',
                    'T7B.8' => 'Training and providing refresher training to HIV testing counsellors',
                    'T7B.9' => 'HTS quality monitoring including conducting observed practices for HTS counsellors',
                    'T7B.10' => 'Providing transport and airtime for follow up and testing of sexual and other contacts',
                    'T7B.11' => 'Documenting, tracking and reporting ART, PEP and PrEP among those eligible'
                ]
            ]
        ]
    ],
    'retention_suppression' => [
        'title' => 'COUNTY LEVEL PATIENT RETENTION, ADHERENCE AND VIRAL SUPPRESSION SERVICES',
        'icon' => 'fa-heartbeat',
        'color' => '#5a7af8',
        'has_ip' => true,
        'indicators' => [
            'T8A' => [
                'code' => 'T8A',
                'name' => 'Transition of Patient retention, adherence and Viral suppression services: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T8A.1' => 'Provision of patient referral forms, appointment diaries and defaulter management tools to facilities',
                    'T8A.2' => 'Ensuring effective procedure to track missed appointments of patients on treatment',
                    'T8A.3' => 'Ensuring effective procedures and tracking of referrals and transfers between health facilities',
                    'T8A.4' => 'Ensuring effective procedures and tracking of referrals between different units within the same health facility',
                    'T8A.5' => 'Paying allowances to track and bring patients with missed appointments or lost to follow-up back to care',
                    'T8A.6' => 'Paying allowances to community health volunteers for HIV/TB related activities',
                    'T8A.7' => 'Supporting of patient support groups for HIV/TB related activities',
                    'T8A.8' => 'Linking facilities with community groups supporting PLHIV/TB for patient follow-up and support',
                    'T8A.9' => 'Strengthening on patient cohort analysis and reporting',
                    'T8A.10' => 'Ensure dissemination/updates of the most updated treatment guidelines',
                    'T8A.11' => 'Supporting enhanced adherence counselling for patients with poor adherence',
                    'T8A.12' => 'Supporting HIV/TB treatment optimization ensuring all cases are on an appropriate regimen',
                    'T8A.13' => 'Funding /Supporting MDT meetings to discuss difficult HIV/TB cases',
                    'T8A.14' => 'Tracking Viral suppression rates by population at site level'
                ]
            ],
            'T8B' => [
                'code' => 'T8B',
                'name' => 'Transition of Patient retention, adherence and Viral suppression services: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T8B.1' => 'Provision of patient referral forms, appointment diaries and defaulter management tools to facilities',
                    'T8B.2' => 'Ensuring effective procedure to track missed appointments of patients on treatment',
                    'T8B.3' => 'Ensuring effective procedures and tracking of referrals and transfers between health facilities',
                    'T8B.4' => 'Ensuring effective procedures and tracking of referrals between different units within the same health facility',
                    'T8B.5' => 'Paying for processes to track and bring patients with missed appointments or lost to follow-up back to care',
                    'T8B.6' => 'Funding community health volunteers and patient support groups',
                    'T8B.7' => 'Linking facilities with community groups supporting PLHIV for patient follow-up and support',
                    'T8B.8' => 'Provide funding for community visits to track patients',
                    'T8B.9' => 'Strengthening on patient cohort analysis and reporting',
                    'T8B.10' => 'Ensure dissemination/updates of the most updated treatment guidelines',
                    'T8B.11' => 'Supporting enhanced adherence counselling for patients with poor adherence',
                    'T8B.12' => 'Supporting HIV treatment optimization ensuring all cases are on an appropriate regimen',
                    'T8B.13' => 'Funding /Supporting MDT meetings to discuss difficult HIV cases',
                    'T8B.14' => 'Tracking Viral suppression rates by population at site level'
                ]
            ]
        ]
    ],
    'prevention_kp' => [
        'title' => 'COUNTY LEVEL HIV PREVENTION AND KEY POPULATION SERVICES',
        'icon' => 'fa-shield-alt',
        'color' => '#6a8aff',
        'has_ip' => true,
        'indicators' => [
            'T9A' => [
                'code' => 'T9A',
                'name' => 'Transition of HIV/TB prevention and Key population services: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T9A.1' => 'Conducting targeted HIV testing of Members of Key population groups',
                    'T9A.2' => 'Providing AGYW services for HIV prevention in safe spaces or Youth friendly settings',
                    'T9A.3' => 'Providing VMMC services for HIV prevention',
                    'T9A.4' => 'Providing condoms and lubricants to members of Key populations',
                    'T9A.5' => 'Provision of KP friendly services including provision of safe spaces',
                    'T9A.6' => 'Providing PrEP to all HIV negative clients at risk of HIV',
                    'T9A.7' => 'Provision of Post Exposure Prophylaxis',
                    'T9A.8' => 'Conducting outreach to Key population hot spots to increase enrollment',
                    'T9A.9' => 'Tracking of enrollment into HIV prevention services and outcomes in Key populations'
                ]
            ],
            'T9B' => [
                'code' => 'T9B',
                'name' => 'Transition of HIV prevention and Key population services: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T9B.1' => 'Conducting targeted HIV testing of Members of priority population groups',
                    'T9B.2' => 'Providing AGYW services for HIV prevention in safe spaces or Youth friendly settings',
                    'T9B.3' => 'Providing VMMC services for HIV prevention',
                    'T9B.4' => 'Providing condoms and lubricants to members of Key populations',
                    'T9B.5' => 'Provision of KP friendly services including provision of safe spaces',
                    'T9B.6' => 'Providing PrEP to all HIV negative clients at risk of HIV',
                    'T9B.7' => 'Provision of Post Exposure Prophylaxis',
                    'T9B.8' => 'Conducting outreach to Key population hot spots to increase enrollment',
                    'T9B.9' => 'Tracking of enrollment into HIV prevention services and outcomes by populations'
                ]
            ]
        ]
    ],
    'finance' => [
        'title' => 'COUNTY LEVEL FINANCE MANAGEMENT',
        'icon' => 'fa-coins',
        'color' => '#7a9aff',
        'has_ip' => true,
        'indicators' => [
            'T10A' => [
                'code' => 'T10A',
                'name' => 'Transition of Financial Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T10A.1' => 'Preparing an annual county budget which integrates HIV care & treatment',
                    'T10A.2' => 'Allocating available program resources',
                    'T10A.3' => 'Tracking program expenditures and income',
                    'T10A.4' => 'Producing financial reports',
                    'T10A.5' => 'Reallocating funding to respond to budget variances and program needs',
                    'T10A.6' => 'Conducts external audit',
                    'T10A.7' => 'Responding to audits/reviews',
                    'T10A.8' => 'Funding the overall county HIV/TB response (HIV/TB funding for the past 5 years)',
                    'T10A.9' => 'Reducing the HIV/TB response funding as a result of the county?s domestic resource mobilization (HIV/TB funding for the last FY)'
                ]
            ],
            'T10B' => [
                'code' => 'T10B',
                'name' => 'Transition of Financial Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T10B.1' => 'Preparing an annual county budget which integrates HIV care & treatment',
                    'T10B.2' => 'Allocating available program resources',
                    'T10B.3' => 'Tracking program expenditures and income',
                    'T10B.4' => 'Producing financial reports',
                    'T10B.5' => 'Reallocating funding to respond to budget variances and program needs',
                    'T10B.6' => 'Conducts external audit',
                    'T10B.7' => 'Responding to audits/reviews',
                    'T10B.8' => 'Funding the overall county HIV/TB response (HIV/TB funding for the past 5 years)',
                    'T10B.9' => 'Reducing the HIV/TB response funding as a result of the county?s domestic resource mobilization (HIV/TB funding for the last FY)'
                ]
            ]
        ]
    ],
    'sub_grants' => [
        'title' => 'COUNTY LEVEL MANAGING SUB-GRANTS OR OTHER GRANTS/COOPERATIVE AGREEMENTS',
        'icon' => 'fa-file-invoice',
        'color' => '#8a5cf6',
        'has_ip' => true,
        'indicators' => [
            'T11A' => [
                'code' => 'T11A',
                'name' => 'Transition of Managing Sub-Grants: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T11A.1' => 'Defining the TOR for last/renewed sub-grant',
                    'T11A.2' => 'Planning and developing the budget',
                    'T11A.3' => 'Managing the competitive bidding process for procurements/purchases',
                    'T11A.4' => 'Tracking sub-grant expenditures',
                    'T11A.5' => 'Disbursing funds for procurements/purchases',
                    'T11A.6' => 'Reporting results'
                ]
            ],
            'T11B' => [
                'code' => 'T11B',
                'name' => 'Transition of Managing Sub-Grants: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T11B.1' => 'Defining the TOR for last/renewed sub-grant',
                    'T11B.2' => 'Planning and developing the budget',
                    'T11B.3' => 'Managing the competitive bidding process for procurements/purchases',
                    'T11B.4' => 'Tracking sub-grant expenditures',
                    'T11B.5' => 'Disbursing funds for procurements/purchases',
                    'T11B.6' => 'Reporting results'
                ]
            ]
        ]
    ],
    'commodities' => [
        'title' => 'COUNTY LEVEL COMMODITIES MANAGEMENT',
        'icon' => 'fa-boxes',
        'color' => '#9b6cf6',
        'has_ip' => true,
        'indicators' => [
            'T12A' => [
                'code' => 'T12A',
                'name' => 'Transition of Commodities Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T12A.1' => 'Developing/adapting commodity supply chain SOPs',
                    'T12A.2' => 'Monitoring consumption of ARVs, anti-TB drugs, Cotrimoxazole, HIV test kits, phlebotomy supplies, cryovials for HIV VL, DBS bundles, GeneXpert catrigdes, sputum mugs (other specific laboratory commodities?)',
                    'T12A.3' => 'Monthly commodities reporting',
                    'T12A.4' => 'Building capacity/training of HF pharmacy and laboratory staff in commodity management',
                    'T12A.5' => 'Managing commodity storage spaces within the facilities',
                    'T12A.6' => 'Submitting stock orders to National level supply chain organization e.g. NASCOP, KEMSA, etc',
                    'T12A.7' => 'Distributing supplies to testing sites, treatment facilities and labs'
                ]
            ],
            'T12B' => [
                'code' => 'T12B',
                'name' => 'Transition of Commodities Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T12B.1' => 'Developing/adapting commodity supply chain SOPs',
                    'T12B.2' => 'Monitoring consumption of ARVs, anti-TB drugs, Cotrimoxazole, HIV test kits, phlebotomy supplies, cryovials for HIV VL, DBS bundles, GeneXpert catrigdes, sputum mugs (other specific laboratory commodities?)',
                    'T12B.3' => 'Monthly commodities reporting',
                    'T12B.4' => 'Building capacity/training of HF pharmacy and laboratory staff in commodity management',
                    'T12B.5' => 'Managing commodity storage spaces within the facilities',
                    'T12B.6' => 'Submitting stock orders to National level supply chain organization e.g. NASCOP, KEMSA, etc',
                    'T12B.7' => 'Distributing supplies to testing sites, treatment facilities and labs'
                ]
            ]
        ]
    ],
    'equipment' => [
        'title' => 'COUNTY LEVEL EQUIPMENT PROCUREMENT AND USE',
        'icon' => 'fa-tools',
        'color' => '#ac7cf6',
        'has_ip' => true,
        'indicators' => [
            'T13A' => [
                'code' => 'T13A',
                'name' => 'Transition of Equipment Procurement and Use: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T13A.1' => 'Determining the need for key HIV/TB specific equipment (Fridges, freezers, centrifuges, Biosafety cabinets, CD4 machines, Gene Xpert machines, etc.)',
                    'T13A.2' => 'Establishing equipment quantification and need based Prioritization of equipment',
                    'T13A.3' => 'Development of specifications, ordering/procuring equipments',
                    'T13A.4' => 'Funding equipment procurement',
                    'T13A.5' => 'Maintaining and calibrating/certifying equipments',
                    'T13A.6' => 'Equipment inventory, supervising and training use of equipments'
                ]
            ],
            'T13B' => [
                'code' => 'T13B',
                'name' => 'Transition of Procurement and Use: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T13B.1' => 'Determining the need for key HIV/TB specific equipment (Fridges, freezers, centrifuges, Biosafety cabinets, CD4 machines, Gene Xpert machines, etc.)',
                    'T13B.2' => 'Establishing equipment quantification and need based Prioritization of equipment',
                    'T13B.3' => 'Development of specifications, ordering/procuring equipments',
                    'T13B.4' => 'Funding equipment procurement',
                    'T13B.5' => 'Maintaining and calibrating/certifying equipments',
                    'T13B.6' => 'Equipment inventory, supervising and training use of equipments'
                ]
            ]
        ]
    ],
    'laboratory' => [
        'title' => 'COUNTY LEVEL LABORATORY SERVICES',
        'icon' => 'fa-flask',
        'color' => '#bd8cf6',
        'has_ip' => true,
        'indicators' => [
            'T14A' => [
                'code' => 'T14A',
                'name' => 'Transition of Laboratory Services: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T14A.1' => 'Distributing QC proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14A.2' => 'Re-Distributing EQA proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14A.3' => 'Compiling and reporting on proficiency testing results',
                    'T14A.4' => 'Conducting supervision/CAPA visits to laboratories',
                    'T14A.5' => 'Training laboratory and HTS staff on good practices',
                    'T14A.6' => 'Ordering laboratory reagents',
                    'T14A.7' => 'Ordering laboratory consumables',
                    'T14A.8' => 'Funding and Managing specimen transport systems (CD4, EID, VL, Gene Xpert, DST)',
                    'T14A.9' => 'Monitoring TAT for test results (CD4, EID, VL, Gene Xpert, DST)',
                    'T14A.10' => 'Implementing laboratory quality management systems (QMS) for HIV/TB',
                    'T14A.11' => 'Supporting biosafety activities and health care waste management',
                    'T14A.12' => 'Supporting service and maintenance contracts for laboratory equipment'
                ]
            ],
            'T14B' => [
                'code' => 'T14B',
                'name' => 'Transition of Laboratory Services: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T14B.1' => 'Distributing QC proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14B.2' => 'Re-Distributing EQA proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14B.3' => 'Compiling and reporting on proficiency testing results',
                    'T14B.4' => 'Conducting supervision/CAPA visits to laboratories',
                    'T14B.5' => 'Training laboratory and HTS staff on good practices',
                    'T14B.6' => 'Ordering laboratory reagents',
                    'T14B.7' => 'Ordering laboratory consumables',
                    'T14B.8' => 'Funding and Managing specimen transport systems (CD4, EID, VL, Gene Xpert, DST)',
                    'T14B.9' => 'Monitoring TAT for test results (CD4, EID, VL, Gene Xpert, DST)',
                    'T14B.10' => 'Implementing laboratory quality management systems (QMS) for HIV/TB',
                    'T14B.11' => 'Supporting biosafety activities and health care waste management',
                    'T14B.12' => 'Supporting service and maintenance contracts for laboratory equipment'
                ]
            ]
        ]
    ],
    'inventory' => [
        'title' => 'COUNTY LEVEL INVENTORY MANAGEMENT',
        'icon' => 'fa-clipboard-list',
        'color' => '#ce9cf6',
        'has_ip' => true,
        'indicators' => [
            'T15A' => [
                'code' => 'T15A',
                'name' => 'Transition of Inventory Management for Equipment & Commodities: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T15A.1' => 'Needs determination function which develops quantity and resource requirements, consisting of Inventory Planning and Budgeting',
                    'T15A.2' => 'Inventory in storage function including Receipt and Inspection process, and Storing process ? (verify Ordering and Commodities/ Stores list updated on transactional basis)',
                    'T15A.3' => 'Inventory Disposition Function including Loaning, Issuing and, Disposal Processes ? (Check USG Assets & Equipment Disposal Guidelines)',
                    'T15A.4' => 'Program monitoring function of Inventory control which provides sufficient transaction audit trails to support balances of inventory on the IP?s General Ledger ? (verify annual Assets Inventory Audit Report)',
                    'T15A.5' => 'Designated qualified and certified Supply Chain Management professional and, membership',
                    'T15A.6' => 'Oversight Supervision of the Inventory Management functions'
                ]
            ],
            'T15B' => [
                'code' => 'T15B',
                'name' => 'Transition of Inventory Management for Equipment & Commodities: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T15B.1' => 'Needs determination function which develops quantity and resource requirements, consisting of Inventory Planning and Budgeting',
                    'T15B.2' => 'Inventory in storage function including Receipt and Inspection process, and Storing process ? (verify Ordering and Commodities/ Stores list updated on transactional basis)',
                    'T15B.3' => 'Inventory Disposition Function including Loaning, Issuing and, Disposal Processes ? (Check USG Assets & Equipment Disposal Guidelines)',
                    'T15B.4' => 'Program monitoring function of Inventory control which provides sufficient transaction audit trails to support balances of inventory on the IP?s General Ledger ? (verify annual Assets Inventory Audit Report)',
                    'T15B.5' => 'Designated qualified and certified Supply Chain Management professional and, membership',
                    'T15B.6' => 'Oversight Supervision of the Inventory Management functions'
                ]
            ]
        ]
    ],
    'training' => [
        'title' => 'COUNTY LEVEL IN-SERVICE TRAINING',
        'icon' => 'fa-chalkboard-teacher',
        'color' => '#dfacf6',
        'has_ip' => true,
        'indicators' => [
            'T16A' => [
                'code' => 'T16A',
                'name' => 'Transition of In-service Training: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T16A.1' => 'Assessing staff training needs',
                    'T16A.2' => 'Selecting/adapting curricula',
                    'T16A.3' => 'Planning training schedule',
                    'T16A.4' => 'Arranging/funding/providing training venue',
                    'T16A.5' => 'Providing or paying trainers/facilitators',
                    'T16A.6' => 'Paying participant per diem',
                    'T16A.7' => 'Use of integrated human resource information system (iHRIS Train)'
                ]
            ],
            'T16B' => [
                'code' => 'T16B',
                'name' => 'Transition of In-service Training: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T16B.1' => 'Assessing staff training needs',
                    'T16B.2' => 'Selecting/adapting curricula',
                    'T16B.3' => 'Planning training schedule',
                    'T16B.4' => 'Arranging/funding/providing training venue',
                    'T16B.5' => 'Providing or paying trainers/facilitators',
                    'T16B.6' => 'Paying participant per diem',
                    'T16B.7' => 'Use of integrated human resource information system (iHRIS Train)'
                ]
            ]
        ]
    ],
    'hr_management' => [
        'title' => 'COUNTY LEVEL HUMAN RESOURCE MANAGEMENT',
        'icon' => 'fa-users',
        'color' => '#f0bcf6',
        'has_ip' => true,
        'indicators' => [
            'T17A' => [
                'code' => 'T17A',
                'name' => 'Transition of HIV/TB Human Resource Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T17A.1' => 'Presence of active of county public service board that recruits HIV/TB services staff (check: Gazette notice, minutes of meeting proceedings)',
                    'T17A.2' => 'Determining staffing needs for the HIV/TB program',
                    'T17A.3' => 'Advertising/posting positions for the HIV/TB program',
                    'T17A.4' => 'Shortlisting/interviewing candidates for the HIV/TB program',
                    'T17A.5' => 'Performance appraisal for the HIV/TB program',
                    'T17A.6' => 'Paying staff salaries for the HIV/TB program',
                    'T17A.7' => 'Appointing HIV/TB program staff (recruitment)',
                    'T17A.8' => 'Absorbing previously IP recruited staff through the county public service board (transitioned staff)',
                    'T17A.9' => 'Supporting facilities to effectively utilize the few available staff to execute health facility roles e.g. development of task shifting plans at health facilities',
                    'T17A.10' => 'Use of integrated human resource information system (iHRS) (government HRH management and development)'
                ]
            ],
            'T17B' => [
                'code' => 'T17B',
                'name' => 'Transition of HIV/TB Human Resource Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T17B.1' => 'Presence of active of county public service board that recruits HIV/TB services staff (check: Gazette notice, minutes of meeting proceedings)',
                    'T17B.2' => 'Determining staffing needs for the HIV/TB program',
                    'T17B.3' => 'Advertising/posting positions for the HIV/TB program',
                    'T17B.4' => 'Shortlisting/interviewing candidates for the HIV/TB program',
                    'T17B.5' => 'Performance appraisal for the HIV/TB program',
                    'T17B.6' => 'Paying staff salaries for the HIV/TB program',
                    'T17B.7' => 'Appointing HIV/TB program staff (recruitment)',
                    'T17B.8' => 'Absorbing previously IP recruited staff through the county public service board (transitioned staff)',
                    'T17B.9' => 'Supporting facilities to effectively utilize the few available staff to execute health facility roles e.g. development of task shifting plans at health facilities',
                    'T17B.10' => 'Use of integrated human resource information system (iHRS) (government HRH management and development)'
                ]
            ]
        ]
    ],
    'data_management' => [
        'title' => 'COUNTY LEVEL HIV/TB PROGRAM DATA MANAGEMENT',
        'icon' => 'fa-database',
        'color' => '#0ABFBC',
        'has_ip' => true,
        'indicators' => [
            'T18A' => [
                'code' => 'T18A',
                'name' => 'Transition of HIV/TB Program Data Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T18A.1' => 'Collecting and entering data from facilities in to DHIS2',
                    'T18A.2' => 'Collecting and entering data from facilities in to DATIM',
                    'T18A.3' => 'Checking completeness and accuracy',
                    'T18A.4' => 'Conduct DQA on regular basis',
                    'T18A.5' => 'Giving feedback and support to facilities for data quality',
                    'T18A.6' => 'Analyzing data and producing reports sent to MOH',
                    'T18A.7' => 'Monitoring results and determining remedial actions',
                    'T18A.8' => 'Managing IT infrastructure for HIV/TB data management',
                    'T18A.9' => 'Training & mentorship of health facility staff in data management'
                ]
            ],
            'T18B' => [
                'code' => 'T18B',
                'name' => 'Transition of HIV/TB Program Data Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T18B.1' => 'Collecting and entering data from facilities in to DHIS2',
                    'T18B.2' => 'Collecting and entering data from facilities in to DATIM',
                    'T18B.3' => 'Checking completeness and accuracy',
                    'T18B.4' => 'Conduct DQA on regular basis',
                    'T18B.5' => 'Giving feedback and support to facilities for data quality',
                    'T18B.6' => 'Analyzing data and producing reports sent to MOH',
                    'T18B.7' => 'Monitoring results and determining remedial actions',
                    'T18B.8' => 'Managing IT infrastructure for HIV/TB data management',
                    'T18B.9' => 'Training & mentorship of health facility staff in data management'
                ]
            ]
        ]
    ],
    'patient_monitoring' => [
        'title' => 'COUNTY LEVEL PATIENT MONITORING SYSTEM',
        'icon' => 'fa-chart-pie',
        'color' => '#27AE60',
        'has_ip' => true,
        'indicators' => [
            'T19A' => [
                'code' => 'T19A',
                'name' => 'Transition of Patient Monitoring System: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T19A.1' => 'Providing patient monitoring system/tools',
                    'T19A.2' => 'Entering patient data into the system/tools',
                    'T19A.3' => 'Checking completeness and accuracy',
                    'T19A.4' => 'Analyzing data and producing reports',
                    'T19A.5' => 'Tracking overall county lost-to-follow up, transfer, death & retention rates',
                    'T19A.6' => 'Managing Electronic Medical Record systems for patient monitoring',
                    'T19A.7' => 'Training Health facility staff in monitoring, evaluation & reporting (at least 2 of the three)'
                ]
            ],
            'T19B' => [
                'code' => 'T19B',
                'name' => 'Transition of Patient Monitoring System: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T19B.1' => 'Providing patient monitoring system/tools',
                    'T19B.2' => 'Entering patient data into the system/tools',
                    'T19B.3' => 'Checking completeness and accuracy',
                    'T19B.4' => 'Analyzing data and producing reports',
                    'T19B.5' => 'Tracking overall county lost-to-follow up, transfer, death & retention rates',
                    'T19B.6' => 'Managing Electronic Medical Record systems for patient monitoring',
                    'T19B.7' => 'Training Health facility staff in monitoring, evaluation & reporting (at least 2 of the three)'
                ]
            ]
        ]
    ],
    'institutional_ownership' => [
        'title' => 'COUNTY LEVEL INSTITUTIONAL OWNERSHIP INDICATOR',
        'icon' => 'fa-building',
        'color' => '#F5A623',
        'has_ip' => false,
        'indicators' => [
            'IO1' => [
                'code' => 'IO1',
                'name' => 'Operationalization of national HIV/TB plan at institutional level',
                'sub_indicators' => [
                    'IO1.1' => 'Does the county routinely develop HIV/TB AWPs that are based on the CIDP?',
                    'IO1.2' => 'Has the county costed its HIV/TB AWP and integrated it with the last national budget request?',
                    'IO1.3' => 'Are different levels of HIV/TB treatment staff involved in the development of the HIV/TB AWP?',
                    'IO1.4' => 'Are stakeholders from HIV/TB programs and PLHIV/TB involved in the development of HIV/TB AWPs?',
                    'IO1.5' => 'Is the implementation of the county HIV/TB work plan monitored and tracked by the County health team?'
                ]
            ],
            'IO2' => [
                'code' => 'IO2',
                'name' => 'Institutional coordination of HIV/TB prevention, care and treatment activities',
                'sub_indicators' => [
                    'IO2.1' => 'Does the CDOH have a list of all active HIV/TB services CSOs and implementing partners in the county with contact information?',
                    'IO2.2' => 'Does the county provide a functional forum for experience exchange on at least a quarterly basis?',
                    'IO2.3' => 'Does the county disseminate information, standards and best practices to implementers and stakeholders in a timely manner?',
                    'IO2.4' => 'Does the county work to ensure a rational geographic distribution, program coverage and scale-up of HIV/TB services?'
                ]
            ],
            'IO3' => [
                'code' => 'IO3',
                'name' => 'Congruence of expectations between levels of the health system',
                'sub_indicators' => [
                    'IO3.1' => 'Is the county strategic plan aligned to the National HIV/TB framework developed by NACC?',
                    'IO3.2' => 'Does the county team perceive the national framework for HIV/TB care and treatment programs is relevant to their county needs?',
                    'IO3.3' => 'Is the policy formulation and capacity building functions of NACC/NASCOP to the county helpful in resolving implementation challenges?',
                    'IO3.4' => 'Is the county team aware of its HIV/TB program service targets? If yes, are they using this data to inform annual HIV/TB plans?',
                    'IO3.5' => 'Does the county team perceive that the HIV service targets/objectives expected of their county are realistic?',
                    'IO3.6' => 'Is the financial grant from the national level adequate to meet the HIV/TB service targets expected of the county team?'
                ]
            ]
        ]
    ]
];

// Filter sections based on selection
$active_sections = array_intersect_key($all_sections, array_flip($sections));

// Calculate total indicators for progress tracking
$total_indicators = 0;
foreach ($active_sections as $section) {
    foreach ($section['indicators'] as $indicator) {
        $total_indicators += count($indicator['sub_indicators']);
    }
}

// Build section definitions for sidebar
$section_defs = [];
foreach ($active_sections as $key => $section) {
    $section_defs[$key] = $section['title'];
}

// Helper functions
function v($key, $existing) { return htmlspecialchars($existing[$key] ?? ''); }
function sel($key, $val, $existing) { return ($existing[$key] ?? '') === $val ? 'selected' : ''; }
function chk($key, $val, $existing) { return ($existing[$key] ?? '') === $val ? 'checked' : ''; }
function is_readonly_attr($is_readonly) { return $is_readonly ? 'readonly disabled' : ''; }

$e_data = $existing ?? [];
$pre_county_id = (int)($e_data['county_id'] ?? $county_id);
$pre_county_name = (string)($e_data['county_name'] ?? $county_name);
$pre_period = (string)($e_data['assessment_period'] ?? $period);
$show_form = ($edit_id && $existing) || ($pre_county_id && $pre_period && !empty($active_sections));

$page_title = $view_only ? 'View Transition Assessment' : ($edit_id ? 'Edit Assessment' : 'New Assessment');

// Clear output buffer and start HTML output
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - <?= htmlspecialchars($pre_county_name) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root{
            --navy:#0D1A63; --navy2:#1a3a9e; --teal:#0ABFBC; --green:#27AE60;
            --amber:#F5A623; --rose:#E74C3C; --purple:#8B5CF6;
            --bg:#f0f2f7; --card:#fff; --border:#e2e8f0; --muted:#6B7280;
            --shadow:0 2px 16px rgba(13,26,99,.08);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#1a1e2e;line-height:1.6;}
        .wrap{max-width:1400px;margin:0 auto;padding:20px;}

        /* Header */
        .page-header{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:20px 28px;
            border-radius:14px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;
            box-shadow:0 6px 24px rgba(13,26,99,.22);flex-wrap:wrap;gap:15px;}
        .page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
        .hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;
            border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;display:inline-flex;align-items:center;gap:6px;}
        .hdr-links a:hover{background:rgba(255,255,255,.28);}

        /* Layout */
        .layout{display:grid;grid-template-columns:280px 1fr;gap:22px;align-items:start;}
        .sidebar{position:sticky;top:16px;background:var(--card);border-radius:14px;
            box-shadow:var(--shadow);overflow:hidden;max-height:calc(100vh - 40px);display:flex;flex-direction:column;}
        .sidebar-head{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;
            padding:14px 18px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
        .sidebar-body{padding:10px 8px;overflow-y:auto;flex:1;}
        .sec-nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:9px;
            cursor:pointer;transition:.15s;font-size:12px;font-weight:500;color:#444;margin-bottom:2px;}
        .sec-nav-item:hover{background:#f0f3fb;color:var(--navy);}
        .sec-nav-item.saved{color:var(--green);}
        .sec-nav-item.saved .sec-dot{background:var(--green);}
        .sec-nav-item.unsaved .sec-dot{background:#e5e7eb;}
        .sec-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;transition:.3s;}
        .sec-nav-item .sec-icon{font-size:12px;width:16px;text-align:center;}
        .progress-wrap{padding:12px 16px 14px;border-top:1px solid var(--border);}
        .progress-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;display:flex;justify-content:space-between;}
        .progress-bar-outer{height:8px;background:#e5e7eb;border-radius:99px;overflow:hidden;}
        .progress-bar-inner{height:100%;background:linear-gradient(90deg,var(--teal),var(--green));border-radius:99px;transition:width .5s;}

        /* Section cards */
        .form-section{background:var(--card);border-radius:14px;margin-bottom:20px;
            box-shadow:var(--shadow);overflow:hidden;border-left:4px solid var(--navy);scroll-margin-top:20px;}
        .section-head{background:linear-gradient(90deg,var(--navy),var(--navy2));color:#fff;
            padding:13px 22px;display:flex;justify-content:space-between;align-items:center;}
        .section-head-left{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700;}
        .section-head-right{display:flex;align-items:center;gap:10px;}
        .saved-badge{background:rgba(39,174,96,.9);color:#fff;padding:3px 10px;border-radius:20px;
            font-size:11px;font-weight:700;display:none;}
        .saved-badge.show{display:inline-flex;align-items:center;gap:5px;}
        .section-body{padding:22px;}

        /* Score grid */
        .score-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:15px;}
        .score-grid.single{grid-template-columns:1fr;}
        .score-column{background:#f8f9fc;border-radius:8px;padding:15px;}
        .score-column h4{font-size:13px;font-weight:700;margin-bottom:15px;display:flex;align-items:center;gap:8px;}
        .score-column.cdoh h4{color:var(--navy);}
        .score-column.ip h4{color:#FFC107;}
        .radio-group{display:flex;gap:10px;flex-wrap:wrap;}
        .radio-option{flex:1;min-width:60px;}
        .radio-option input[type="radio"]{display:none;}
        .radio-option label{display:flex;flex-direction:column;align-items:center;padding:10px 5px;
            background:#fff;border:2px solid #e0e4f0;border-radius:8px;cursor:pointer;transition:all .2s;}
        .radio-option input[type="radio"]:checked + label{border-color:var(--color);background:var(--bg-color);}
        .radio-option .score{font-weight:700;font-size:16px;}
        .radio-option .label{font-size:9px;text-align:center;color:#666;margin-top:3px;}
        .level-4{--color:#28a745;--bg-color:#d4edda;}
        .level-3{--color:#17a2b8;--bg-color:#d1ecf1;}
        .level-2{--color:#ffc107;--bg-color:#fff3cd;}
        .level-1{--color:#fd7e14;--bg-color:#ffe5d0;}
        .level-0{--color:#dc3545;--bg-color:#f8d7da;}

        /* Sub-indicator */
        .sub-indicator{background:#fff;border-radius:10px;padding:15px;margin-bottom:15px;border:1px solid #e0e4f0;}
        .sub-indicator-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;}
        .sub-indicator-code{font-weight:700;color:var(--navy);background:#e8edf8;padding:3px 10px;border-radius:20px;font-size:12px;}
        .sub-indicator-text{font-size:13px;color:#555;margin-bottom:15px;line-height:1.5;}
        .comments-section{margin-top:15px;padding-top:15px;border-top:1px dashed #e0e4f0;}
        .comments-section textarea{width:100%;padding:10px;border:1.5px solid #e0e4f0;border-radius:8px;font-size:12px;resize:vertical;min-height:60px;font-family:inherit;}
        .comments-section textarea:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(13,26,99,.08);}

        /* Indicator card */
        .indicator-card{background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:25px;border-left:4px solid var(--color, var(--navy));}
        .indicator-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;}
        .indicator-code{background:var(--navy);color:#fff;padding:5px 15px;border-radius:30px;font-weight:700;font-size:14px;}
        .indicator-title{font-size:16px;font-weight:700;color:var(--navy);margin-bottom:15px;}

        /* Form elements */
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;}
        .form-group{margin-bottom:14px;}
        .form-group label{display:block;margin-bottom:5px;font-weight:600;color:#374151;font-size:13px;}
        .form-control,.form-select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:7px;
            font-size:13px;transition:.2s;background:#fff;font-family:inherit;}
        .form-control:focus,.form-select:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(13,26,99,.08);}
        .form-control[readonly],.form-select[readonly],.form-control[disabled],.form-select[disabled]{background:#f8f9fc;color:#666;}

        /* Save button */
        .btn-save-section{background:var(--teal);color:#fff;border:none;padding:9px 22px;border-radius:8px;
            font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;
            transition:.2s;margin-top:18px;}
        .btn-save-section:hover{background:#089e9b;}
        .btn-save-section.saving{background:#aaa;cursor:not-allowed;}

        /* Submit zone */
        .submit-zone{background:var(--card);border-radius:14px;padding:22px 28px;box-shadow:var(--shadow);
            margin-bottom:28px;text-align:center;}
        .submit-progress{font-size:14px;font-weight:600;color:var(--muted);margin-bottom:14px;}
        .btn-submit-final{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;
            padding:14px 44px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;
            transition:.2s;display:inline-flex;align-items:center;gap:10px;}
        .btn-submit-final:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(13,26,99,.3);}
        .btn-submit-final:disabled{background:#aaa;cursor:not-allowed;transform:none;box-shadow:none;}

        /* Toast */
        .toast{position:fixed;bottom:24px;right:24px;z-index:9999;background:#fff;border-radius:12px;
            padding:14px 20px;box-shadow:0 8px 32px rgba(0,0,0,.18);display:flex;align-items:center;gap:12px;
            font-size:13.5px;font-weight:600;transform:translateY(80px);opacity:0;transition:.3s;pointer-events:none;
            max-width:340px;border-left:4px solid var(--green);}
        .toast.show{transform:translateY(0);opacity:1;}
        .toast.error{border-left-color:var(--rose);}
        .toast-icon{font-size:18px;}
        .toast.success .toast-icon{color:var(--green);}
        .toast.error .toast-icon{color:var(--rose);}

        /* Modal */
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;
            align-items:center;justify-content:center;}
        .modal-overlay.show{display:flex;}
        .modal-box{background:#fff;border-radius:16px;width:90%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;}
        .modal-head{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:18px 24px;}
        .modal-head h4{font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px;}
        .modal-body{padding:22px 24px;font-size:14px;line-height:1.7;}
        .modal-foot{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;}
        .btn-navy{background:var(--navy);color:#fff;border:none;padding:9px 22px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;}
        .btn-navy:hover{background:var(--navy2);}
        .btn-outline{background:none;color:var(--muted);border:1.5px solid var(--border);padding:9px 18px;
            border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-outline:hover{border-color:var(--navy);color:var(--navy);}
        .sections-status{margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:6px;}
        .sec-status-item{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;
            padding:6px 10px;border-radius:7px;}
        .sec-status-item.done{background:#d4edda;color:#155724;}
        .sec-status-item.todo{background:#f8d7da;color:#721c24;}

        /* IP badge */
        .ip-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;margin-left:10px;}
        .ip-badge.yes{background:#FFC107;color:#000;}
        .ip-badge.no{background:#6c757d;color:#fff;}

        /* Setup card */
        .setup-card{background:var(--card);border-radius:14px;padding:20px 22px;margin-bottom:22px;
            box-shadow:var(--shadow);border-left:4px solid var(--teal);}
        .setup-card h3{font-size:13px;font-weight:700;color:var(--navy);text-transform:uppercase;
            letter-spacing:.7px;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
        .setup-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:end;}
        .setup-field label{display:block;font-size:11px;font-weight:700;color:var(--muted);
            text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
        .setup-field select{width:100%;padding:10px 13px;border:1.5px solid var(--border);
            border-radius:8px;font-size:13.5px;transition:.2s;font-family:inherit;background:#fff;}
        .setup-field select:focus{outline:none;border-color:var(--navy);}
        .btn-load{background:var(--navy);color:#fff;border:none;padding:10px 22px;border-radius:8px;
            font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;height:42px;transition:.2s;}
        .btn-load:hover{background:var(--navy2);}
        .btn-load:disabled{opacity:0.6;cursor:not-allowed;}
        .county-card{border:2px solid var(--navy);border-radius:10px;padding:14px 18px;
            background:linear-gradient(135deg,#f0f3fb,#fff);margin-top:10px;display:none;}
        .county-card-header{display:flex;justify-content:space-between;align-items:center;}
        .county-card-name{font-weight:700;color:var(--navy);font-size:15px;display:flex;align-items:center;gap:8px;}

        @media(max-width:960px){.layout{grid-template-columns:1fr;}.sidebar{position:static;max-height:none;}}
        @media(max-width:640px){.score-grid{grid-template-columns:1fr;}.setup-grid{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="page-header">
    <h1><i class="fas fa-clipboard-check"></i> <?= $page_title ?></h1>
    <div class="hdr-links">
        <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="transition_index.php"><i class="fas fa-home"></i> Home</a>
        <?php if ($edit_id): ?>
        <span style="background:rgba(255,255,255,.2);padding:7px 14px;border-radius:8px;font-size:13px;">
            <?= $view_only ? 'Viewing' : 'Editing' ?> #<?= $edit_id ?>
        </span>
        <?php endif; ?>
    </div>
</div>

<div id="globalAlert"></div>

<!-- Setup Card (only show when starting new) -->
<?php if (!$edit_id): ?>
<div class="setup-card">
    <h3><i class="fas fa-cog"></i> Select County and Assessment Period</h3>
    <div class="setup-grid">
        <div class="setup-field">
            <label>County <span style="color:var(--rose)">*</span></label>
            <select id="countySelect">
                <option value="">Select County</option>
                <?php
                $counties_r = mysqli_query($conn, "SELECT county_id, county_name, county_code FROM counties ORDER BY county_name");
                while ($c = mysqli_fetch_assoc($counties_r)):
                ?>
                <option value="<?= $c['county_id'] ?>" data-name="<?= htmlspecialchars($c['county_name']) ?>"
                    <?= $pre_county_id==$c['county_id']?'selected':'' ?>>
                    <?= htmlspecialchars($c['county_name']) ?> (<?= $c['county_code'] ?>)
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="setup-field">
            <label>Assessment Period <span style="color:var(--rose)">*</span></label>
            <select id="periodSelect">
                <option value="">Select Period</option>
                <?php
                $periods = ['Oct-Dec 2025','Jan-Mar 2026','Apr-Jun 2026','Jul-Sep 2026','Oct-Dec 2026'];
                foreach ($periods as $p): ?>
                <option value="<?= $p ?>" <?= $pre_period===$p?'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button class="btn-load" id="btnLoad" onclick="loadAssessment()">
                <i class="fas fa-arrow-right"></i> Load or Start
            </button>
        </div>
    </div>

    <div class="county-card" id="countyCard" <?= ($pre_county_id && $pre_period && $show_form) ? 'style="display:block"' : '' ?>>
        <div class="county-card-header">
            <div class="county-card-name">
                <i class="fas fa-map-marker-alt" style="color:var(--teal)"></i>
                <span id="cc_name"><?= htmlspecialchars($pre_county_name) ?></span>
            </div>
            <span id="cc_period" style="background:#e8edf8;color:var(--navy);padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700">
                <?= htmlspecialchars($pre_period) ?>
            </span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Hidden fields -->
<input type="hidden" id="h_assessment_id" value="<?= $assessment_id ?>">
<input type="hidden" id="h_county_id" value="<?= $pre_county_id ?>">
<input type="hidden" id="h_period" value="<?= htmlspecialchars($pre_period) ?>">
<input type="hidden" id="h_county_name" value="<?= htmlspecialchars($pre_county_name) ?>">

<?php if ($show_form): ?>
<div class="layout">

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-head"><i class="fas fa-tasks"></i> Assessment Progress</div>
    <div class="sidebar-body" id="sidebarNav">
        <?php foreach ($section_defs as $sk => $sl):
            $saved = in_array($sk, $sections_saved);
        ?>
        <div class="sec-nav-item <?= $saved?'saved':'unsaved' ?>" data-section="<?= $sk ?>"
             onclick="scrollToSection('<?= $sk ?>')">
            <span class="sec-dot"></span>
            <span class="sec-icon"><i class="fas <?= $saved?'fa-check-circle':'fa-circle' ?>"></i></span>
            <span><?= $sl ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="progress-wrap">
        <div class="progress-label"><span>Progress</span><span id="progressPct">0%</span></div>
        <div class="progress-bar-outer"><div class="progress-bar-inner" id="progressBar" style="width:0%"></div></div>
    </div>
</aside>

<!-- Main Form -->
<div id="mainForm">

<?php foreach ($active_sections as $key => $section): ?>
<div class="form-section" id="sec_<?= $key ?>">
    <div class="section-head">
        <div class="section-head-left">
            <i class="fas <?= $section['icon'] ?? 'fa-file' ?>"></i> <?= $section['title'] ?>
        </div>
        <div class="section-head-right">
            <?php if (!$section['has_ip']): ?>
            <span class="ip-badge no">CDOH Only</span>
            <?php elseif (preg_match('/^IO/', array_key_first($section['indicators']))): ?>
            <span class="ip-badge yes">CDOH + IP</span>
            <?php else: ?>
            <span class="ip-badge" style="background:#e0e8ff;color:var(--navy);">A=IP / B=CDOH</span>
            <?php endif; ?>
            <span class="saved-badge <?= in_array($key,$sections_saved)?'show':'' ?>" id="badge_<?= $key ?>">
                <i class="fas fa-check"></i> Saved
            </span>
        </div>
    </div>
    <div class="section-body">
        <?php foreach ($section['indicators'] as $indicator_code => $indicator): ?>
        <div class="indicator-card" style="--color: <?= $section['color'] ?? '#0D1A63' ?>">
            <div class="indicator-header">
                <span class="indicator-code"><?= $indicator_code ?></span>
                <span style="font-size:12px;color:#666;"><?= count($indicator['sub_indicators']) ?> sub-indicators</span>
            </div>
            <div class="indicator-title"><?= $indicator['name'] ?></div>

            <?php foreach ($indicator['sub_indicators'] as $sub_code => $sub_text):
                $composite_key = $key . '_' . $indicator_code . '_' . $sub_code;
                $ex = $existing_raw[$composite_key] ?? [];

                $is_ip_only      = (bool)preg_match('/^T\d+A/', $indicator_code);
                $is_cdoh_b       = (bool)preg_match('/^T\d+B/', $indicator_code);
                $is_leadership   = in_array($indicator_code, ['T1','T2']);
                $is_planning     = ($indicator_code === 'T3');
                $is_io           = (bool)preg_match('/^IO/', $indicator_code);

                $labels_ip       = [4=>'Dominates',3=>'Supportive',2=>'Involved',1=>'Partial',0=>'Not involved'];
                $labels_autonomy = [4=>'Independent',3=>'Mostly indep.',2=>'Not indep.',1=>'Minimally',0=>'Not involved'];
                $labels_adequacy = [4=>'Fully',3=>'Partially',2=>'Some evid.',1=>'No evid.',0=>'Inadequate'];
                $labels_io       = [4=>'Complete',3=>'Most',2=>'About half',1=>'Few',0=>'No/N/A'];
            ?>
            <div class="sub-indicator">
                <div class="sub-indicator-header">
                    <span class="sub-indicator-code"><?= $sub_code ?></span>
                </div>
                <div class="sub-indicator-text"><?= $sub_text ?></div>

                <?php if ($is_ip_only): ?>
                <!-- IP score only -->
                <div class="score-grid single">
                    <div class="score-column ip">
                        <h4><i class="fas fa-handshake"></i> IP Involvement Score</h4>
                        <div class="radio-group">
                            <?php foreach ($scoring_criteria as $score => $criteria): ?>
                            <div class="radio-option <?= $criteria['class'] ?>">
                                <input type="radio"
                                       name="scores[<?= $composite_key ?>][ip]"
                                       value="<?= $score ?>"
                                       id="ip_<?= $composite_key ?>_<?= $score ?>"
                                       <?= isset($ex['ip_score']) && $ex['ip_score'] !== null && (string)$ex['ip_score'] === (string)$score ? 'checked' : '' ?>
                                       <?= is_readonly_attr($is_readonly) ?>>
                                <label for="ip_<?= $composite_key ?>_<?= $score ?>">
                                    <span class="score"><?= $score ?></span>
                                    <span class="label"><?= $labels_ip[$score] ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php elseif ($is_cdoh_b || $is_planning): ?>
                <!-- CDOH score only, autonomy labels -->
                <div class="score-grid single">
                    <div class="score-column cdoh">
                        <h4><i class="fas fa-building"></i> CDOH Autonomy Score</h4>
                        <div class="radio-group">
                            <?php foreach ($scoring_criteria as $score => $criteria): ?>
                            <div class="radio-option <?= $criteria['class'] ?>">
                                <input type="radio"
                                       name="scores[<?= $composite_key ?>][cdoh]"
                                       value="<?= $score ?>"
                                       id="cdoh_<?= $composite_key ?>_<?= $score ?>"
                                       <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>
                                       <?= is_readonly_attr($is_readonly) ?>>
                                <label for="cdoh_<?= $composite_key ?>_<?= $score ?>">
                                    <span class="score"><?= $score ?></span>
                                    <span class="label"><?= $labels_autonomy[$score] ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php elseif ($is_leadership): ?>
                <!-- CDOH score only, adequacy labels -->
                <div class="score-grid single">
                    <div class="score-column cdoh">
                        <h4><i class="fas fa-building"></i> CDOH Score</h4>
                        <div class="radio-group">
                            <?php foreach ($scoring_criteria as $score => $criteria): ?>
                            <div class="radio-option <?= $criteria['class'] ?>">
                                <input type="radio"
                                       name="scores[<?= $composite_key ?>][cdoh]"
                                       value="<?= $score ?>"
                                       id="cdoh_<?= $composite_key ?>_<?= $score ?>"
                                       <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>
                                       <?= is_readonly_attr($is_readonly) ?>>
                                <label for="cdoh_<?= $composite_key ?>_<?= $score ?>">
                                    <span class="score"><?= $score ?></span>
                                    <span class="label"><?= $labels_adequacy[$score] ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php elseif ($is_io): ?>
                <!-- IO: both CDOH and IP -->
                <div class="score-grid">
                    <div class="score-column cdoh">
                        <h4><i class="fas fa-building"></i> CDOH Score</h4>
                        <div class="radio-group">
                            <?php foreach ($scoring_criteria as $score => $criteria): ?>
                            <div class="radio-option <?= $criteria['class'] ?>">
                                <input type="radio"
                                       name="scores[<?= $composite_key ?>][cdoh]"
                                       value="<?= $score ?>"
                                       id="cdoh_<?= $composite_key ?>_<?= $score ?>"
                                       <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>
                                       <?= is_readonly_attr($is_readonly) ?>>
                                <label for="cdoh_<?= $composite_key ?>_<?= $score ?>">
                                    <span class="score"><?= $score ?></span>
                                    <span class="label"><?= $labels_io[$score] ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="score-column ip">
                        <h4><i class="fas fa-handshake"></i> IP Score</h4>
                        <div class="radio-group">
                            <?php foreach ($scoring_criteria as $score => $criteria): ?>
                            <div class="radio-option <?= $criteria['class'] ?>">
                                <input type="radio"
                                       name="scores[<?= $composite_key ?>][ip]"
                                       value="<?= $score ?>"
                                       id="ip_<?= $composite_key ?>_<?= $score ?>"
                                       <?= isset($ex['ip_score']) && $ex['ip_score'] !== null && (string)$ex['ip_score'] === (string)$score ? 'checked' : '' ?>
                                       <?= is_readonly_attr($is_readonly) ?>>
                                <label for="ip_<?= $composite_key ?>_<?= $score ?>">
                                    <span class="score"><?= $score ?></span>
                                    <span class="label"><?= $labels_io[$score] ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <!-- Fallback: CDOH only -->
                <div class="score-grid single">
                    <div class="score-column cdoh">
                        <h4><i class="fas fa-building"></i> CDOH Score</h4>
                        <div class="radio-group">
                            <?php foreach ($scoring_criteria as $score => $criteria): ?>
                            <div class="radio-option <?= $criteria['class'] ?>">
                                <input type="radio"
                                       name="scores[<?= $composite_key ?>][cdoh]"
                                       value="<?= $score ?>"
                                       id="cdoh_<?= $composite_key ?>_<?= $score ?>"
                                       <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>
                                       <?= is_readonly_attr($is_readonly) ?>>
                                <label for="cdoh_<?= $composite_key ?>_<?= $score ?>">
                                    <span class="score"><?= $score ?></span>
                                    <span class="label"><?= $labels_adequacy[$score] ?></span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Comments -->
                <div class="comments-section">
                    <textarea name="scores[<?= $composite_key ?>][comments]"
                              placeholder="Add comments or verification notes for this indicator..."
                              rows="2" <?= is_readonly_attr($is_readonly) ?>><?= htmlspecialchars($ex['comments'] ?? '') ?></textarea>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!$is_readonly): ?>
        <button type="button" class="btn-save-section" onclick="saveSection('<?= $key ?>')">
            <i class="fas fa-save"></i> Save This Section
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Data Collection Details -->
<div class="form-section" style="border-left-color:var(--teal)">
    <div class="section-head" style="background:linear-gradient(90deg,var(--teal),#089e9b)">
        <div class="section-head-left"><i class="fas fa-user-check"></i> Assessment Details</div>
    </div>
    <div class="section-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Assessed By</label>
                <div style="background:#f0f4ff;border:1px solid #c5d0f0;border-radius:9px;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;background:var(--navy);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:17px;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div>
                        <div style="font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.5px;">Logged-in Officer</div>
                        <div style="font-size:14px;font-weight:700;color:var(--navy);"><?= htmlspecialchars($collected_by ?: 'Not identified') ?></div>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Assessment Date</label>
                <input type="date" id="assessment_date_input" class="form-control"
                       value="<?= $e_data['assessment_date'] ?? date('Y-m-d') ?>" <?= is_readonly_attr($is_readonly) ?>>
            </div>
        </div>
    </div>
</div>

<!-- Submit Zone -->
<?php if (!$is_readonly && !($existing['assessment_status'] ?? '') === 'Submitted'): ?>
<div class="submit-zone">
    <div class="submit-progress" id="submitProgressText">
        <i class="fas fa-info-circle"></i> Save all sections to unlock final submission
    </div>
    <button type="button" class="btn-submit-final" id="btnFinalSubmit" disabled onclick="finalSubmit()">
        <i class="fas fa-paper-plane"></i> Submit Final Assessment
    </button>
</div>
<?php endif; ?>

</div><!-- /mainForm -->
</div><!-- /layout -->
<?php else: ?>
<div style="text-align:center;padding:60px 20px;color:var(--muted)">
    <i class="fas fa-clipboard-check" style="font-size:56px;margin-bottom:16px;display:block;opacity:.3"></i>
    <p style="font-size:16px;font-weight:600">Select a County and Assessment Period above, then click <strong>Load or Start</strong></p>
</div>
<?php endif; ?>
</div><!-- /wrap -->

<!-- Duplicate Modal -->
<div class="modal-overlay" id="dupModal">
    <div class="modal-box">
        <div class="modal-head">
            <h4><i class="fas fa-exclamation-triangle"></i> Assessment Already Exists</h4>
        </div>
        <div class="modal-body">
            <p id="dupModalMsg"></p>
            <div class="sections-status" id="dupSectionsStatus"></div>
        </div>
        <div class="modal-foot">
            <button class="btn-outline" onclick="closeDupModal()"><i class="fas fa-times"></i> Cancel</button>
            <a id="dupEditLink" href="#" class="btn-navy"><i class="fas fa-edit"></i> Open and Continue</a>
            <a href="transition_dashboard.php" class="btn-navy" style="background:var(--teal)"><i class="fas fa-list"></i> Dashboard</a>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
    <i class="fas fa-check-circle toast-icon"></i>
    <span id="toastMsg">Saved successfully</span>
</div>

<script>
// State
let assessmentId   = <?= $assessment_id ?: 0 ?>;
let sectionsSaved  = <?= json_encode($sections_saved) ?>;
const allSections  = <?= json_encode(array_keys($active_sections)) ?>;
const isReadOnly   = <?= $is_readonly ? 'true' : 'false' ?>;
const sectionData  = <?= json_encode($submitted_sections) ?>;

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    if (!t) return;
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'toast ' + type + ' show';
    t.querySelector('.toast-icon').className = 'fas ' + (type==='success'?'fa-check-circle':'fa-exclamation-triangle') + ' toast-icon';
    setTimeout(() => t.classList.remove('show'), 3200);
}

function updateProgress() {
    const n = sectionsSaved.length;
    const total = allSections.length;
    const pct = total > 0 ? Math.round(n/total*100) : 0;

    const pctEl = document.getElementById('progressPct');
    const barEl = document.getElementById('progressBar');
    if (pctEl) pctEl.textContent = pct + '%';
    if (barEl) barEl.style.width = pct + '%';

    allSections.forEach(sk => {
        const item = document.querySelector(`.sec-nav-item[data-section="${sk}"]`);
        if (!item) return;
        const saved = sectionsSaved.includes(sk);
        item.className = 'sec-nav-item ' + (saved?'saved':'unsaved');
        const icon = item.querySelector('.sec-icon i');
        if (icon) icon.className = 'fas ' + (saved?'fa-check-circle':'fa-circle');
    });

    const btn = document.getElementById('btnFinalSubmit');
    const txt = document.getElementById('submitProgressText');
    if (btn && txt && !isReadOnly) {
        if (n >= total) {
            btn.disabled = false;
            txt.innerHTML = '<i class="fas fa-check-circle" style="color:var(--green)"></i> All sections saved – ready to submit!';
        } else {
            btn.disabled = true;
            txt.innerHTML = '<i class="fas fa-info-circle"></i> ' + n + ' of ' + total + ' sections saved – complete all to enable submission';
        }
    }
}

function scrollToSection(sk) {
    const el = document.getElementById('sec_' + sk);
    if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
}

async function loadAssessment() {
    const cid = document.getElementById('countySelect').value;
    const cname = document.getElementById('countySelect').options[document.getElementById('countySelect').selectedIndex]?.getAttribute('data-name') || '';
    const period = document.getElementById('periodSelect').value;

    if (!cid || !period) {
        showToast('Please select both a County and an Assessment Period', 'error');
        return;
    }

    const btn = document.getElementById('btnLoad');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

    try {
        const response = await fetch(`transition_assessment.php?ajax=check_assessment&county_id=${cid}&period=${encodeURIComponent(period)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const data = await response.json();

        if (data.exists) {
            // Show modal for existing assessment
            const total = allSections.length;
            const ss = data.sections_saved || [];
            document.getElementById('dupModalMsg').innerHTML =
                'An assessment for <strong>' + data.county_name + '</strong> – <strong>' + period + '</strong> already exists<br>' +
                '(ID #' + data.assessment_id + ', status: <strong>' + data.status + '</strong>, readiness: <strong>' + (data.readiness_level || 'Not Rated') + '</strong>)';

            let html = '';
            const allLabels = <?= json_encode($section_defs) ?>;
            for (const [sk, sl] of Object.entries(allLabels)) {
                const sData = data.section_data?.[sk];
                html += '<div class="sec-status-item ' + (ss.includes(sk)?'done':'todo') + '">' +
                    '<i class="fas ' + (ss.includes(sk)?'fa-check-circle':'fa-times-circle') + '"></i>' +
                    '<span>' + sl + (sData ? ' (CDOH: ' + Math.round((sData.avg_cdoh||0)/4*100) + '%)' : '') + '</span>' +
                '</div>';
            }
            document.getElementById('dupSectionsStatus').innerHTML = html;
            document.getElementById('dupEditLink').href = `transition_assessment.php?id=${data.assessment_id}`;
            document.getElementById('dupModal').classList.add('show');
        } else {
            // No existing assessment - redirect with parameters
            window.location.href = `transition_assessment.php?county=${cid}&period=${encodeURIComponent(period)}&sections=<?= implode(',', array_keys($all_sections)) ?>`;
        }
    } catch (e) {
        console.error(e);
        showToast('An error occurred while checking for existing assessment', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-arrow-right"></i> Load or Start';
    }
}

function closeDupModal() {
    const modal = document.getElementById('dupModal');
    if (modal) modal.classList.remove('show');
}

const modal = document.getElementById('dupModal');
if (modal) {
    modal.addEventListener('click', function(e) {
        if(e.target === this) closeDupModal();
    });
}

async function saveSection(sectionKey) {
    if (isReadOnly) {
        showToast('Cannot save in view-only mode', 'error');
        return;
    }

    const cid = document.getElementById('h_county_id')?.value;
    const period = document.getElementById('h_period')?.value;
    const cname = document.getElementById('h_county_name')?.value;

    if (!cid) { showToast('Please select a county first', 'error'); return; }
    if (!period) { showToast('Please select an assessment period', 'error'); return; }

    const btn = document.querySelector('#sec_' + sectionKey + ' .btn-save-section');
    if (!btn) return;

    const origTxt = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.classList.add('saving');
    btn.disabled = true;

    const fd = new FormData();
    fd.append('ajax_save_section', '1');
    fd.append('section_key', sectionKey);
    fd.append('county_id', cid);
    fd.append('assessment_period', period);
    fd.append('assessment_id', assessmentId);
    fd.append('county_name', cname);

    const adate = document.getElementById('assessment_date_input');
    if (adate) fd.append('assessment_date', adate.value);

    // Collect all scores from this section
    const sec = document.getElementById('sec_' + sectionKey);
    if (sec) {
        // Collect radio buttons
        const radios = sec.querySelectorAll('input[type="radio"]');
        const processedGroups = new Set();

        radios.forEach(radio => {
            const name = radio.name;
            if (!processedGroups.has(name)) {
                processedGroups.add(name);
                const checked = sec.querySelector(`input[name="${name}"]:checked`);
                if (checked) {
                    fd.append(name, checked.value);
                }
            }
        });

        // Collect comments
        const textareas = sec.querySelectorAll('textarea');
        textareas.forEach(ta => {
            if (ta.name) {
                fd.append(ta.name, ta.value.trim());
            }
        });
    }

    try {
        const response = await fetch('transition_assessment.php', {
            method: 'POST',
            body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const text = await response.text();

        // Check if response is HTML
        if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
            console.error('Received HTML instead of JSON. Session may have expired.');
            showToast('Session expired. Please refresh the page and login again.', 'error');
            return;
        }

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Response text:', text.substring(0, 500));
            throw new Error('Server returned invalid JSON. Check PHP errors.');
        }

        if (data.success) {
            assessmentId = data.assessment_id;
            const hAid = document.getElementById('h_assessment_id');
            if (hAid) hAid.value = assessmentId;
            sectionsSaved = data.sections_saved;

            const badge = document.getElementById('badge_' + sectionKey);
            if (badge) badge.classList.add('show');

            updateProgress();
            showToast('Section saved successfully! CDOH: ' + data.avg_cdoh_pct + '%', 'success');
        } else {
            showToast(data.error || 'Save failed', 'error');
        }
    } catch(e) {
        console.error('Save error:', e);
        showToast('Error saving section – please try again', 'error');
    } finally {
        btn.innerHTML = origTxt;
        btn.classList.remove('saving');
        btn.disabled = false;
    }
}

async function finalSubmit() {
    if (!assessmentId) { showToast('No assessment to submit', 'error'); return; }
    if (!confirm('Submit this transition assessment as final? It will be marked as Submitted and cannot be edited.')) return;

    const fd = new FormData();
    fd.append('ajax_submit', '1');
    fd.append('assessment_id', assessmentId);
    fd.append('county_id', document.getElementById('h_county_id')?.value || '');

    try {
        const response = await fetch('transition_assessment.php', {
            method: 'POST',
            body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        const data = await response.json();
        if (data.success) {
            showToast('Assessment submitted successfully! Redirecting...', 'success');
            setTimeout(() => window.location.href = data.redirect, 1500);
        } else {
            showToast(data.error || 'Submission failed', 'error');
        }
    } catch(e) {
        showToast('Network error – please try again', 'error');
    }
}

// Initialize progress if form is visible
if (document.getElementById('mainForm')) {
    updateProgress();
}

// Keyboard shortcut (only if not read-only)
if (!isReadOnly) {
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const unsaved = allSections.find(sk => !sectionsSaved.includes(sk));
            if (unsaved) saveSection(unsaved);
        }
    });
}
</script>
</body>
</html>