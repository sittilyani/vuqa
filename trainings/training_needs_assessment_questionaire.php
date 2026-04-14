<?php
session_start();

// TEMPORARY DEBUG
error_reporting(E_ALL);
ini_set('display_errors', 1); // Temporarily turn ON for debugging

include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// -- AJAX: live staff search, same-file pattern --
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_staff') {
    // For debugging, we'll show errors temporarily
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    header('Content-Type: application/json');

    try {
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 2) {
            echo json_encode([]);
            exit();
        }

        $q = mysqli_real_escape_string($conn, $q);
        $rows = [];

        // Check if tables exist first
        $tables_check = mysqli_query($conn, "SHOW TABLES LIKE 'employee_academics'");
        if (mysqli_num_rows($tables_check) == 0) {
            echo json_encode(['error' => 'Employee tables do not exist. Please run the SQL schema first.']);
            exit();
        }

        // Get staff details from county_staff
        $sql = "SELECT cs.*,
                    (SELECT COUNT(*) FROM employee_academics ea WHERE ea.id_number = cs.id_number) as academics_count,
                    (SELECT COUNT(*) FROM employee_professional_registrations epr WHERE epr.id_number = cs.id_number) as registrations_count,
                    (SELECT COUNT(*) FROM employee_work_experience ewe WHERE ewe.id_number = cs.id_number) as experience_count,
                    (SELECT COUNT(*) FROM employee_trainings et WHERE et.id_number = cs.id_number) as trainings_count,
                    (SELECT COUNT(*) FROM employee_languages el WHERE el.id_number = cs.id_number) as languages_count,
                    (SELECT COUNT(*) FROM employee_referees er WHERE er.id_number = cs.id_number) as referees_count,
                    (SELECT COUNT(*) FROM employee_next_of_kin enk WHERE enk.id_number = cs.id_number) as kin_count,
                    (SELECT COUNT(*) FROM employee_disciplinary ed WHERE ed.id_number = cs.id_number) as disciplinary_count,
                    (SELECT COUNT(*) FROM employee_appraisals ea2 WHERE ea2.id_number = cs.id_number) as appraisals_count,
                    (SELECT COUNT(*) FROM employee_leave elv WHERE elv.id_number = cs.id_number) as leave_count
             FROM county_staff cs
             WHERE cs.status = 'active'
               AND (cs.first_name LIKE '%$q%' OR cs.last_name LIKE '%$q%'
                    OR cs.other_name LIKE '%$q%' OR cs.id_number LIKE '%$q%'
                    OR cs.staff_phone LIKE '%$q%')
             ORDER BY cs.first_name, cs.last_name LIMIT 15";

        $res = mysqli_query($conn, $sql);

        if (!$res) {
            throw new Exception("MySQL Error: " . mysqli_error($conn));
        }

        while ($r = mysqli_fetch_assoc($res)) {
            // Format full name
            $r['full_name'] = trim($r['first_name'] . ' ' .
                (!empty($r['other_name']) ? $r['other_name'] . ' ' : '') .
                $r['last_name']);

            $id_esc = mysqli_real_escape_string($conn, $r['id_number']);

            // Initialize all arrays
            $r['academics'] = [];
            $r['registrations'] = [];
            $r['experience'] = [];
            $r['trainings'] = [];
            $r['languages'] = [];
            $r['referees'] = [];
            $r['next_of_kin'] = [];
            $r['disciplinary'] = [];
            $r['appraisals'] = [];
            $r['leave'] = [];

            // Only try to fetch if tables exist
            $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'employee_academics'");
            if (mysqli_num_rows($table_check) > 0) {
                // Academics
                $ra = @mysqli_query($conn, "SELECT qualification_type, qualification_name, institution_name, award_year, completion_status, verification_status FROM employee_academics WHERE id_number='$id_esc' ORDER BY award_year DESC LIMIT 3");
                if ($ra) while ($a = mysqli_fetch_assoc($ra)) $r['academics'][] = $a;

                // Registrations
                $rr = @mysqli_query($conn, "SELECT regulatory_body, registration_number, license_number, registration_date, expiry_date, verification_status FROM employee_professional_registrations WHERE id_number='$id_esc' ORDER BY expiry_date DESC LIMIT 3");
                if ($rr) while ($reg = mysqli_fetch_assoc($rr)) {
                    $reg['is_expired'] = (!empty($reg['expiry_date']) && strtotime($reg['expiry_date']) < time());
                    $reg['expiry_date'] = !empty($reg['expiry_date']) ? date('d M Y', strtotime($reg['expiry_date'])) : '';
                    $reg['registration_date'] = !empty($reg['registration_date']) ? date('d M Y', strtotime($reg['registration_date'])) : '';
                    $r['registrations'][] = $reg;
                }

                // Experience
                $re = @mysqli_query($conn, "SELECT employer_name, employer_type, job_title, start_date, end_date, is_current, verification_status FROM employee_work_experience WHERE id_number='$id_esc' ORDER BY CASE WHEN is_current='Yes' THEN 0 ELSE 1 END, end_date DESC LIMIT 3");
                if ($re) while ($e = mysqli_fetch_assoc($re)) {
                    $e['start_date'] = !empty($e['start_date']) ? date('M Y', strtotime($e['start_date'])) : '';
                    $e['end_date']   = $e['is_current']==='Yes' ? 'Present' : (!empty($e['end_date']) ? date('M Y', strtotime($e['end_date'])) : '');
                    $r['experience'][] = $e;
                }

                // Trainings
                $rt = @mysqli_query($conn, "SELECT training_name, training_provider, training_type, start_date, end_date, funding_source FROM employee_trainings WHERE id_number='$id_esc' ORDER BY end_date DESC LIMIT 3");
                if ($rt) while ($t = mysqli_fetch_assoc($rt)) {
                    $t['start_date'] = !empty($t['start_date']) ? date('d M Y', strtotime($t['start_date'])) : '';
                    $t['end_date']   = !empty($t['end_date'])   ? date('d M Y', strtotime($t['end_date']))   : '';
                    $r['trainings'][] = $t;
                }

                // Languages
                $rl = @mysqli_query($conn, "SELECT language_name, proficiency FROM employee_languages WHERE id_number='$id_esc' ORDER BY language_name LIMIT 3");
                if ($rl) while ($l = mysqli_fetch_assoc($rl)) $r['languages'][] = $l;

                // Referees
                $rf = @mysqli_query($conn, "SELECT referee_name, referee_position, referee_organization, referee_phone FROM employee_referees WHERE id_number='$id_esc' ORDER BY referee_id DESC LIMIT 3");
                if ($rf) while ($f = mysqli_fetch_assoc($rf)) $r['referees'][] = $f;

                // Next of Kin
                $rnk = @mysqli_query($conn, "SELECT kin_name, kin_relationship, kin_phone, is_emergency_contact FROM employee_next_of_kin WHERE id_number='$id_esc' ORDER BY priority_order");
                if ($rnk) while ($nk = mysqli_fetch_assoc($rnk)) $r['next_of_kin'][] = $nk;

                // Disciplinary
                $rd = @mysqli_query($conn, "SELECT case_number, case_type, incident_date, status FROM employee_disciplinary WHERE id_number='$id_esc' ORDER BY incident_date DESC LIMIT 2");
                if ($rd) while ($d = mysqli_fetch_assoc($rd)) {
                    $d['incident_date'] = !empty($d['incident_date']) ? date('d M Y', strtotime($d['incident_date'])) : '';
                    $r['disciplinary'][] = $d;
                }

                // Appraisals
                $ra2 = @mysqli_query($conn, "SELECT appraisal_period, appraisal_year, overall_rating, appraisal_date FROM employee_appraisals WHERE id_number='$id_esc' ORDER BY appraisal_date DESC LIMIT 2");
                if ($ra2) while ($a2 = mysqli_fetch_assoc($ra2)) {
                    $a2['appraisal_date'] = !empty($a2['appraisal_date']) ? date('d M Y', strtotime($a2['appraisal_date'])) : '';
                    $r['appraisals'][] = $a2;
                }

                // Leave
                $rlv = @mysqli_query($conn, "SELECT leave_type, start_date, end_date, status FROM employee_leave WHERE id_number='$id_esc' ORDER BY start_date DESC LIMIT 2");
                if ($rlv) while ($lv = mysqli_fetch_assoc($rlv)) {
                    $lv['start_date'] = !empty($lv['start_date']) ? date('d M Y', strtotime($lv['start_date'])) : '';
                    $lv['end_date'] = !empty($lv['end_date']) ? date('d M Y', strtotime($lv['end_date'])) : '';
                    $r['leave'][] = $lv;
                }
            }

            $rows[] = $r;
        }

        $json = json_encode($rows);
        if ($json === false) {
            echo json_encode(['error' => 'Failed to encode data: ' . json_last_error_msg()]);
        } else {
            echo $json;
        }

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// After debugging, turn errors back off
error_reporting(E_ALL);
ini_set('display_errors', 0);


// -- Administered by -----------------------------------------------------------
$administered_by = '';
$uid = intval($_SESSION['user_id']);
$urow = mysqli_query($conn, "SELECT full_name FROM tblusers WHERE user_id = $uid");
if ($urow && mysqli_num_rows($urow) > 0) {
    $administered_by = mysqli_fetch_assoc($urow)['full_name'];
}
if (empty($administered_by) && isset($_SESSION['full_name'])) {
    $administered_by = $_SESSION['full_name'];
}

// Fetch positions for dropdown
$positions = $conn->query("SELECT positionname FROM positions ORDER BY positionname");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Needs Assessment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f3fb;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }

        .page-header {
            background: linear-gradient(135deg, #0D1A63 0%, #1a3a8f 100%);
            color: #fff; padding: 22px 30px; border-radius: 14px;
            margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 8px 24px rgba(13,26,99,.25);
        }
        .page-header h1 { font-size: 22px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .page-header .hdr-links a {
            color: #fff; text-decoration: none; background: rgba(255,255,255,.15);
            padding: 7px 14px; border-radius: 8px; font-size: 13px; margin-left: 8px;
            transition: background .2s;
        }
        .page-header .hdr-links a:hover { background: rgba(255,255,255,.28); }

        .alert {
            padding: 13px 18px; border-radius: 9px; margin-bottom: 18px; font-size: 14px;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .card {
            background: #fff; border-radius: 14px; box-shadow: 0 2px 16px rgba(0,0,0,.06);
            margin-bottom: 24px;
        }
        .card-head {
            background: linear-gradient(90deg, #0D1A63, #1a3a8f);
            color: #fff; padding: 14px 22px; border-radius: 14px 14px 0 0;
            font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px;
        }
        .card-body { padding: 22px; }

        /* Search + picker */
        .search-wrap { position: relative; }
        .search-wrap input {
            width: 100%; padding: 12px 42px 12px 16px;
            border: 2px solid #e0e0e0; border-radius: 9px; font-size: 14px;
            transition: border-color .25s;
        }
        .search-wrap input:focus {
            outline: none; border-color: #0D1A63;
            box-shadow: 0 0 0 3px rgba(13,26,99,.1);
        }
        .search-wrap .search-icon {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            color: #aaa; font-size: 15px;
        }
        .search-wrap .spinner {
            position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
            color: #0D1A63; font-size: 14px; display: none;
        }

        .results-list {
            position: absolute; z-index: 999; width: 100%; background: #fff;
            border: 1.5px solid #dce3f5; border-radius: 10px; margin-top: 4px;
            box-shadow: 0 8px 28px rgba(13,26,99,.15); max-height: 300px; overflow-y: auto; display: none;
        }
        .results-list .result-item {
            padding: 11px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0;
            transition: background .15s;
        }
        .results-list .result-item:last-child { border-bottom: none; }
        .results-list .result-item:hover { background: #f0f3fb; }
        .results-list .result-item .ri-name  { font-weight: 700; color: #0D1A63; font-size: 13.5px; }
        .results-list .result-item .ri-meta  { font-size: 11.5px; color: #777; margin-top: 2px; }
        .results-list .result-item .ri-badge {
            display: inline-block; font-size: 10px; background: #e8edf8; color: #0D1A63;
            border-radius: 4px; padding: 1px 6px; margin-left: 6px; font-weight: 600;
        }
        .results-list .no-results { padding: 14px 15px; color: #999; font-size: 13px; text-align: center; }

        /* Selected card with tabs for different sections */
        .selected-card {
            border: 2px solid #0D1A63; border-radius: 11px;
            background: linear-gradient(135deg, #f0f3fb, #fff); margin-top: 10px; display: none;
        }
        .selected-card .sc-header {
            display: flex; justify-content: space-between; align-items: center;
            background: #0D1A63; color: white; padding: 12px 18px;
            border-radius: 9px 9px 0 0;
        }
        .selected-card .sc-title { font-weight: 700; font-size: 16px; }
        .selected-card .sc-clear {
            color: white; cursor: pointer; font-size: 13px;
            background: rgba(255,255,255,.2); padding: 4px 10px; border-radius: 20px;
        }
        .selected-card .sc-clear:hover { background: rgba(255,255,255,.3); }

        /* Tabs */
        .staff-tabs {
            display: flex; flex-wrap: wrap; gap: 2px;
            background: #f0f3fb; padding: 10px 10px 0 10px;
            border-bottom: 1px solid #dce3f5;
        }
        .staff-tab {
            padding: 8px 15px; font-size: 12px; font-weight: 600;
            background: #e0e4f0; color: #555; cursor: pointer;
            border-radius: 8px 8px 0 0; transition: all .2s;
        }
        .staff-tab:hover { background: #d0d4e0; }
        .staff-tab.active {
            background: white; color: #0D1A63; border: 1px solid #dce3f5;
            border-bottom: 2px solid white; margin-bottom: -1px;
        }
        .staff-tab .count {
            background: #0D1A63; color: white; padding: 2px 6px;
            border-radius: 20px; font-size: 10px; margin-left: 5px;
        }

        /* Tab content */
        .tab-content {
            padding: 18px; display: none;
        }
        .tab-content.active { display: block; }

        .info-grid {
            display: grid; grid-template-columns: repeat(3,1fr); gap: 12px;
        }
        .info-item label {
            font-size: 10px; text-transform: uppercase; letter-spacing: .4px;
            color: #999; font-weight: 600; display: block;
        }
        .info-item span { font-size: 13px; color: #333; font-weight: 500; }

        .preview-table {
            width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 4px;
        }
        .preview-table th {
            background: #0D1A63; color: #fff; padding: 5px 8px;
            text-align: left; font-weight: 600;
        }
        .preview-table td {
            padding: 5px 8px; border-bottom: 1px solid #e8eaf0;
        }
        .preview-table tr:nth-child(even) { background: #f4f6fb; }
        .preview-table tr:nth-child(odd) { background: #fff; }

        .summary-badge {
            display: inline-block; background: #e8edf8; color: #0D1A63;
            padding: 3px 10px; border-radius: 20px; font-size: 11px;
            margin: 0 5px 5px 0;
        }

        /* Form elements */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; margin-bottom: 7px; font-weight: 600;
            color: #444; font-size: 14px;
        }
        .form-control, .form-select {
            width: 100%; padding: 10px 13px;
            border: 2px solid #e0e0e0; border-radius: 6px; font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
            background: white; font-family: inherit;
        }
        .form-control:focus, .form-select:focus {
            outline: none; border-color: #0D1A63;
            box-shadow: 0 0 0 3px rgba(13,26,99,.1);
        }
        textarea.form-control { min-height: 90px; resize: vertical; }

        .radio-group {
            display: flex; flex-wrap: wrap; gap: 18px; margin-top: 6px;
        }
        .radio-option {
            display: flex; align-items: center; gap: 7px; font-size: 14px; cursor: pointer;
        }
        .radio-option input[type="radio"] {
            accent-color: #0D1A63; width: 16px; height: 16px;
        }

        .checkbox-2col {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 8px 24px; margin-top: 10px;
        }
        .checkbox-2col label {
            display: flex; align-items: center; gap: 8px;
            font-size: 14px; font-weight: 400; color: #333;
            cursor: pointer; padding: 5px 0;
        }
        .checkbox-2col input[type="checkbox"] {
            accent-color: #0D1A63; width: 16px; height: 16px;
        }
        /* Checkbox Group Styles */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
        }

        .checkbox-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            padding: 6px 12px;
            background: #f8fafc;
            border-radius: 30px;
            transition: all .2s;
            border: 1px solid #e0e4f0;
        }

        .checkbox-option:hover {
            background: #e8edf8;
            border-color: #0D1A63;
        }

        .checkbox-option input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #0D1A63;
            cursor: pointer;
            margin: 0;
        }

        .checkbox-option span {
            font-size: 13px;
            color: #333;
            font-weight: 500;
        }

        /* When checkbox is checked, highlight the option */
        .checkbox-option:has(input[type="checkbox"]:checked) {
            background: #0D1A63;
            border-color: #0D1A63;
        }

        .checkbox-option:has(input[type="checkbox"]:checked) span {
            color: #fff;
        }

        .training-entry {
            background: #f8f9fc; border: 1px solid #e0e4f0;
            border-radius: 8px; padding: 16px 18px; margin-bottom: 12px;
        }
        .training-entry-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;
        }
        .training-entry-num {
            font-size: 12px; font-weight: 700; color: #0D1A63;
            text-transform: uppercase; letter-spacing: .5px;
        }
        .remove-training {
            background: #fee2e2; color: #dc2626; border: none;
            border-radius: 5px; padding: 4px 10px; font-size: 12px;
            font-weight: 600; cursor: pointer;
        }
        .remove-training:hover { background: #fecaca; }

        .training-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .add-training-btn {
            background: #e8f0ff; color: #0D1A63;
            border: 2px dashed #0D1A63; border-radius: 8px;
            padding: 11px 22px; font-size: 14px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center;
            gap: 8px; width: 100%; justify-content: center;
            margin-top: 4px; transition: background .2s;
        }
        .add-training-btn:hover { background: #d0ddff; }

        .administered-box {
            background: #f0f4ff; border: 1px solid #c5d0f0;
            border-radius: 8px; padding: 16px 20px;
            display: flex; align-items: center; gap: 16px;
        }
        .administered-icon {
            width: 46px; height: 46px; background: #0D1A63;
            border-radius: 10px; display: flex; align-items: center;
            justify-content: center; font-size: 20px; color: white;
        }
        .administered-name {
            font-size: 17px; font-weight: 700; color: #0D1A63;
        }
        .administered-label {
            font-size: 11px; color: #666; text-transform: uppercase;
            letter-spacing: .6px; margin-bottom: 2px;
        }

        .btn-submit {
            background: #0D1A63; color: white;
            padding: 14px 40px; border: none; border-radius: 6px;
            cursor: pointer; font-size: 16px; font-weight: 700;
            display: block; width: 100%; max-width: 320px;
            margin: 30px auto; transition: background .2s;
        }
        .btn-submit:hover { background: #1a2a7a; }
        .btn-submit:disabled { background: #ccc; cursor: not-allowed; }

        .divider { border: none; border-top: 1px dashed #dce3f5; margin: 20px 0; }
    </style>
</head>
<body>
<div class="container">

    <div class="page-header">
        <h1><i class="fas fa-clipboard-list"></i> Training Needs Assessment Questionnaire</h1>
        <div class="hdr-links">
            <a href="training_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="staff_training_form.php"><i class="fas fa-plus"></i> Add Training</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Training Needs Assessment submitted successfully!</div>
    <?php endif; ?>

    <form id="tnaForm" action="submit_training_needs.php" method="POST">
        <!-- Hidden fields -->
        <input type="hidden" name="id_number"       id="h_id_number">
        <input type="hidden" name="administered_by" value="<?php echo htmlspecialchars($administered_by); ?>">
        <input type="hidden" name="facility_name"   id="h_facility_name">
        <input type="hidden" name="county_name"     id="h_county_name">
        <input type="hidden" name="subcounty_name"  id="h_subcounty_name">

        <!-- SECTION 1: Staff Selection -->
        <div class="card">
            <div class="card-head"><i class="fas fa-user-search"></i> Step 1: Select Staff to assess <span style="font-style:italic; color: white; font-size: 14px;">(If the staff is not avalable - please )</span><a href="../staff/initial_participant_registration.php"><span style="color:yellow;">Register staff</span></a></div>
            <div class="card-body">
                <div class="search-wrap" id="staffSearchWrap">
                    <input type="text" id="staffSearch"
                           placeholder="Type name, ID number or phone number to search..."
                           autocomplete="off">
                    <i class="fas fa-search search-icon" id="staffSearchIcon"></i>
                    <i class="fas fa-spinner fa-spin spinner" id="staffSpinner"></i>
                    <div class="results-list" id="staffResults"></div>
                </div>

                <!-- Staff preview card with tabs for all employee data -->
                <div class="selected-card" id="staffCard">
                    <div class="sc-header">
                        <div class="sc-title" id="sc_name"></div>
                        <span class="sc-clear" onclick="clearStaff()"><i class="fas fa-times-circle"></i> Change Staff</span>
                    </div>

                    <!-- Summary badges -->
                    <div style="padding: 10px 18px; background: #f8f9fc; border-bottom: 1px solid #dce3f5;">
                        <span class="summary-badge" id="summary_academics">Academics: 0</span>
                        <span class="summary-badge" id="summary_registrations">Registrations: 0</span>
                        <span class="summary-badge" id="summary_experience">Experience: 0</span>
                        <span class="summary-badge" id="summary_trainings">Trainings: 0</span>
                        <span class="summary-badge" id="summary_languages">Languages: 0</span>
                        <span class="summary-badge" id="summary_referees">Referees: 0</span>
                        <span class="summary-badge" id="summary_kin">Next of Kin: 0</span>
                    </div>

                    <!-- Tabs navigation -->
                    <div class="staff-tabs">
                        <div class="staff-tab active" onclick="showTab('personal')">Personal Info</div>
                        <div class="staff-tab" onclick="showTab('academics')">Academics <span class="count" id="tab_academics">0</span></div>
                        <div class="staff-tab" onclick="showTab('registrations')">Registrations <span class="count" id="tab_registrations">0</span></div>
                        <div class="staff-tab" onclick="showTab('experience')">Experience <span class="count" id="tab_experience">0</span></div>
                        <div class="staff-tab" onclick="showTab('trainings')">Trainings <span class="count" id="tab_trainings">0</span></div>
                        <div class="staff-tab" onclick="showTab('languages')">Languages <span class="count" id="tab_languages">0</span></div>
                        <div class="staff-tab" onclick="showTab('referees')">Referees <span class="count" id="tab_referees">0</span></div>
                        <div class="staff-tab" onclick="showTab('kin')">Next of Kin <span class="count" id="tab_kin">0</span></div>
                        <div class="staff-tab" onclick="showTab('disciplinary')">Disciplinary <span class="count" id="tab_disciplinary">0</span></div>
                        <div class="staff-tab" onclick="showTab('appraisals')">Appraisals <span class="count" id="tab_appraisals">0</span></div>
                        <div class="staff-tab" onclick="showTab('leave')">Leave <span class="count" id="tab_leave">0</span></div>
                    </div>

                    <!-- Tab: Personal Info -->
                    <div id="tab_personal" class="tab-content active">
                        <div class="info-grid">
                            <div class="info-item"><label>ID Number</label><span id="sc_id"></span></div>
                            <div class="info-item"><label>Phone</label><span id="sc_phone"></span></div>
                            <div class="info-item"><label>Email</label><span id="sc_email"></span></div>
                            <div class="info-item"><label>Facility</label><span id="sc_facility"></span></div>
                            <div class="info-item"><label>Department</label><span id="sc_dept"></span></div>
                            <div class="info-item"><label>Cadre</label><span id="sc_cadre"></span></div>
                            <div class="info-item"><label>County</label><span id="sc_county"></span></div>
                            <div class="info-item"><label>Sub-County</label><span id="sc_sub"></span></div>
                            <div class="info-item"><label>Sex</label><span id="sc_sex"></span></div>
                            <div class="info-item"><label>Employment</label><span id="sc_emp"></span></div>
                            <div class="info-item"><label>Date of Birth</label><span id="sc_dob"></span></div>
                            <div class="info-item"><label>Date Joined</label><span id="sc_doj"></span></div>
                        </div>
                    </div>

                    <!-- Tab: Academics -->
                    <div id="tab_academics_tab" class="tab-content">
                        <div id="pv_quals_table"></div>
                        <div id="pv_quals_empty" style="display:none; padding:20px; text-align:center; color:#888;">No academic records found</div>
                    </div>

                    <!-- Tab: Registrations -->
                    <div id="tab_registrations_tab" class="tab-content">
                        <div id="pv_regs_table"></div>
                        <div id="pv_regs_empty" style="display:none; padding:20px; text-align:center; color:#888;">No professional registrations found</div>
                    </div>

                    <!-- Tab: Experience -->
                    <div id="tab_experience_tab" class="tab-content">
                        <div id="pv_exp_table"></div>
                        <div id="pv_exp_empty" style="display:none; padding:20px; text-align:center; color:#888;">No work experience found</div>
                    </div>

                    <!-- Tab: Trainings -->
                    <div id="tab_trainings_tab" class="tab-content">
                        <div id="pv_train_table"></div>
                        <div id="pv_train_empty" style="display:none; padding:20px; text-align:center; color:#888;">No training records found</div>
                    </div>

                    <!-- Tab: Languages -->
                    <div id="tab_languages_tab" class="tab-content">
                        <div id="pv_lang_table"></div>
                        <div id="pv_lang_empty" style="display:none; padding:20px; text-align:center; color:#888;">No language records found</div>
                    </div>

                    <!-- Tab: Referees -->
                    <div id="tab_referees_tab" class="tab-content">
                        <div id="pv_ref_table"></div>
                        <div id="pv_ref_empty" style="display:none; padding:20px; text-align:center; color:#888;">No referee records found</div>
                    </div>

                    <!-- Tab: Next of Kin -->
                    <div id="tab_kin_tab" class="tab-content">
                        <div id="pv_kin_table"></div>
                        <div id="pv_kin_empty" style="display:none; padding:20px; text-align:center; color:#888;">No next of kin records found</div>
                    </div>

                    <!-- Tab: Disciplinary -->
                    <div id="tab_disciplinary_tab" class="tab-content">
                        <div id="pv_disc_table"></div>
                        <div id="pv_disc_empty" style="display:none; padding:20px; text-align:center; color:#888;">No disciplinary records found</div>
                    </div>

                    <!-- Tab: Appraisals -->
                    <div id="tab_appraisals_tab" class="tab-content">
                        <div id="pv_app_table"></div>
                        <div id="pv_app_empty" style="display:none; padding:20px; text-align:center; color:#888;">No appraisal records found</div>
                    </div>

                    <!-- Tab: Leave -->
                    <div id="tab_leave_tab" class="tab-content">
                        <div id="pv_leave_table"></div>
                        <div id="pv_leave_empty" style="display:none; padding:20px; text-align:center; color:#888;">No leave records found</div>
                    </div>
                </div>

                <!-- Additional fields after staff selection -->
                <div style="margin-top:20px">
                    <div class="form-group">
                        <label>Position</label>
                        <select class="form-select" name="position" id="position">
                            <option value="">-- Select Position --</option>
                            <?php
                            if ($positions) {
                                while ($row = $positions->fetch_assoc()) {
                                    echo "<option value='" . htmlspecialchars($row['positionname']) . "'>"
                                       . htmlspecialchars($row['positionname']) . "</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Designation</label>
                        <input type="text" name="designation" class="form-control" id="designation" placeholder="e.g. Senior Clinical Officer">
                    </div>

                    <div class="form-group">
                        <label>Years in Current Job Group</label>
                        <div class="radio-group">
                            <label class="radio-option"><input type="radio" name="years_current_job_group" value="Below 5 yrs"> Below 5 yrs</label>
                            <label class="radio-option"><input type="radio" name="years_current_job_group" value="6-10 yrs"> 6-10 yrs</label>
                            <label class="radio-option"><input type="radio" name="years_current_job_group" value="11-15 yrs"> 11-15 yrs</label>
                            <label class="radio-option"><input type="radio" name="years_current_job_group" value="over 16 years"> Over 16 yrs</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- JOB CONTENT SECTION -->
        <div class="card">
            <div class="card-head"><i class="fas fa-tasks"></i> Job Content</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="duties_responsibilities">i. What are your duties and responsibilities in HIV/TB/MNCH management?</label>
                    <textarea id="duties_responsibilities" name="duties_responsibilities" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>ii. Do you experience any knowledge/skills related challenges in carrying out the duties and responsibilities above?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="knowledge_skills_challenges" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="knowledge_skills_challenges" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group" id="challengingDutiesGroup" style="display:none">
                    <label for="challenging_duties">iii. If YES, please identify the duties that present the greatest knowledge/skills challenges</label>
                    <textarea id="challenging_duties" name="challenging_duties" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="other_challenges">iv. What other challenges affect the performance of your duties and responsibilities?</label>
                    <textarea id="other_challenges" name="other_challenges" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>v. Do you possess all the necessary skills to perform your duties?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="possess_necessary_skills" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="possess_necessary_skills" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="skills_explanation">Please explain your response</label>
                    <textarea id="skills_explanation" name="skills_explanation" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>vi. How did you acquire the skills that enable you to perform your duties? (Select all that apply)</label>
                    <div class="checkbox-group">
                        <label class="checkbox-option">
                            <input type="checkbox" name="skills_acquisition[]" value="Experience">
                            <span>Experience</span>
                        </label>
                        <label class="checkbox-option">
                            <input type="checkbox" name="skills_acquisition[]" value="Attachment">
                            <span>Attachment</span>
                        </label>
                        <label class="checkbox-option">
                            <input type="checkbox" name="skills_acquisition[]" value="Training">
                            <span>Training</span>
                        </label>
                        <label class="checkbox-option">
                            <input type="checkbox" name="skills_acquisition[]" value="Mentorship">
                            <span>Mentorship</span>
                        </label>
                        <label class="checkbox-option">
                            <input type="checkbox" name="skills_acquisition[]" value="Induction">
                            <span>Induction</span>
                        </label>
                        <label class="checkbox-option">
                            <input type="checkbox" name="skills_acquisition[]" value="Research">
                            <span>Research</span>
                        </label>
                    </div>
                    <small style="color: #666; font-size: 11px; display: block; margin-top: 8px;">
                        <i class="fas fa-info-circle"></i> Check all that apply
                    </small>
                </div>

                <!-- Challenge Scale -->
                <div class="form-group" style="margin-top:24px">
                    <label><b>vii. In a scale of 1-5, rate the level of challenge in each area (1 = Least, 5 = Most Challenging)</b></label>
                    <div style="background:#f0f4ff; padding:10px 16px; border-radius:6px; margin-bottom:16px; font-size:13px; color:#0D1A63;">
                        <div style="display:flex; justify-content:space-between; font-weight:700;">
                            <span>1 – Least Challenging</span>
                            <span>5 – Most Challenging</span>
                        </div>
                    </div>

                    <?php
                    $challenges = [
                        'challenge_knowledge'   => 'a. Inadequate knowledge and skills',
                        'challenge_equipment'   => 'b. Inadequate equipment/tools',
                        'challenge_workload'    => 'c. Heavy workload',
                        'challenge_motivation'  => 'd. Motivation',
                        'challenge_teamwork'    => 'e. Teamwork',
                        'challenge_management'  => 'f. Management Support',
                        'challenge_environment' => 'g. Conducive Environment',
                    ];
                    foreach ($challenges as $fname => $flabel): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding:10px 14px; background:#f9f9fb; border-radius:6px;">
                        <div style="flex:1; font-weight:500; font-size:14px;"><?php echo $flabel; ?></div>
                        <div style="display:flex; gap:14px;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div style="display:flex; flex-direction:column; align-items:center;">
                                <input type="radio" name="<?php echo $fname; ?>" value="<?php echo $i; ?>" style="accent-color:#0D1A63; width:16px; height:16px;">
                                <label style="font-size:13px; color:#666; margin-top:4px;"><?php echo $i; ?></label>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-group">
                    <label for="suggestions">viii. Suggest ways of addressing the challenges above</label>
                    <textarea id="suggestions" name="suggestions" class="form-control"></textarea>
                </div>
            </div>
        </div>

        <!-- PERFORMANCE MEASURES SECTION -->
        <div class="card">
            <div class="card-head"><i class="fas fa-chart-line"></i> Performance Measures</div>
            <div class="card-body">
                <div class="form-group">
                    <label>a. Do you set targets for your Unit/Division/Department?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="set_targets" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="set_targets" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="targets_explanation">b. If No, explain</label>
                    <textarea id="targets_explanation" name="targets_explanation" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>c. Do you set own targets?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="set_own_targets" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="set_own_targets" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="own_targets_areas">d. If Yes, which areas?</label>
                    <textarea id="own_targets_areas" name="own_targets_areas" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>ii. Do you perform duties unrelated to your job?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="unrelated_duties" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="unrelated_duties" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="skills_unrelated_explanation">iii. If Yes, please specify</label>
                    <textarea id="skills_unrelated_explanation" name="skills_unrelated_explanation" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>iv. Do you possess the skills to perform those duties?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="necessary_technical_skills1" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="necessary_technical_skills1" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="necessary_technical_skills_explanation1">Explain</label>
                    <textarea id="necessary_technical_skills_explanation1" name="necessary_technical_skills_explanation" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="performance_evaluation">v. How is your performance evaluated?</label>
                    <textarea id="performance_evaluation" name="performance_evaluation" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="least_score_aspects">vi. On what aspects did you score least during your last evaluation?</label>
                    <textarea id="least_score_aspects" name="least_score_aspects" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="score_reasons">vii. Please list reasons for those scores</label>
                    <textarea id="score_reasons" name="score_reasons" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label for="improvement_suggestions">viii. Suggest three (3) ways of improving your performance</label>
                    <textarea id="improvement_suggestions" name="improvement_suggestions" class="form-control"></textarea>
                </div>
            </div>
        </div>

        <!-- TECHNICAL SKILL LEVELS SECTION -->
        <div class="card">
            <div class="card-head"><i class="fas fa-cogs"></i> Technical Skill Levels</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="necessary_technical_skills">i. Identify the technical skills necessary for the performance of your job</label>
                    <textarea id="necessary_technical_skills" name="necessary_technical_skills" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>ii. Do you possess the skills identified above?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="possess_technical_skills" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="possess_technical_skills" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="technical_skills_list">iii. If Yes, please list any three (3) such skills</label>
                    <textarea id="technical_skills_list" name="technical_skills_list" class="form-control"></textarea>
                </div>

                <div class="form-group">
                    <label style="font-weight:700;font-size:14px">iv. From the list below, please select the core competences that you posses:</label>
                    <div class="checkbox-2col">
                        <?php
                        $competences = [
                            'research_methods'                => 'Research Methods',
                            'training_needs_assessment'       => 'Training Needs Assessment',
                            'presentations'                   => 'Presentations',
                            'proposal_report_writing'         => 'Proposal & Report Writing',
                            'human_relations_skills'          => 'Human Relations Skills',
                            'financial_management'            => 'Financial Management',
                            'monitoring_evaluation'           => 'Monitoring & Evaluation',
                            'leadership_management'           => 'Leadership & Management',
                            'communication'                   => 'Communication',
                            'negotiation_networking'          => 'Negotiation Networking',
                            'policy_formulation'              => 'Policy Formulation & Implementation',
                            'report_writing'                  => 'Report Writing',
                            'minute_writing'                  => 'Minute Writing',
                            'speech_writing'                  => 'Speech Writing',
                            'time_management'                 => 'Time Management',
                            'negotiation_skills'              => 'Negotiation Skills',
                            'guidance_counseling'             => 'Guidance & Counseling',
                            'integrity'                       => 'Integrity',
                            'performance_management'          => 'Performance Management',
                        ];
                        foreach ($competences as $fname => $flabel): ?>
                        <label>
                            <input type="checkbox" name="<?php echo $fname; ?>" value="<?php echo htmlspecialchars($flabel); ?>">
                            <?php echo htmlspecialchars($flabel); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- TRAINING SECTION -->
        <div class="card">
            <div class="card-head"><i class="fas fa-chalkboard-teacher"></i> Training</div>
            <div class="card-body">
                <div class="form-group">
                    <label>i. (a) Have you attended any HIV/TB/MNCH training?</label>
                    <div class="radio-group">
                        <label class="radio-option"><input type="radio" name="attended_training" value="Yes"> Yes</label>
                        <label class="radio-option"><input type="radio" name="attended_training" value="No"> No</label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="training_details">(b) If yes, please specify the area of training, duration and year</label>
                    <textarea id="training_details" name="training_details" class="form-control"></textarea>
                </div>

                <!-- Proposed training repeater -->
                <div class="form-group" style="margin-top:24px">
                    <label style="font-size:15px;font-weight:700">
                        ii. Proposed areas of training for the next three years
                        <span style="font-weight:400;font-size:13px;color:#666"> – specify institution and duration</span>
                    </label>

                    <div class="training-repeater" id="trainingRepeater">
                        <!-- First entry -->
                        <div class="training-entry" data-entry="1">
                            <div class="training-entry-header">
                                <span class="training-entry-num">Training Option 1</span>
                                <button type="button" class="remove-training" onclick="removeTraining(this)" style="display:none">? Remove</button>
                            </div>
                            <div class="training-grid">
                                <div class="form-group">
                                    <label>Area of Training</label>
                                    <input type="text" name="proposed_training_area[]" class="form-control" placeholder="e.g. Advanced Clinical Care">
                                </div>
                                <div class="form-group">
                                    <label>Institution</label>
                                    <input type="text" name="proposed_training_institution[]" class="form-control" placeholder="e.g. Kenya Medical Training College">
                                </div>
                                <div class="form-group">
                                    <label>Duration</label>
                                    <input type="text" name="proposed_training_duration[]" class="form-control" placeholder="e.g. 3 months / 2 weeks">
                                </div>
                                <div class="form-group">
                                    <label>Preferred Year</label>
                                    <select name="proposed_training_year[]" class="form-select">
                                        <option value="">-- Select Year --</option>
                                        <?php for ($y = date('Y'); $y <= date('Y') + 4; $y++): ?>
                                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button" class="add-training-btn" id="addTrainingBtn">
                        <i class="fas fa-plus"></i> Add Another Training Option
                    </button>
                </div>

                <!-- Administered By -->
                <div class="form-group" style="margin-top:30px; border-top:2px solid #eef0f7; padding-top:22px">
                    <label style="font-size:14px;font-weight:700;color:#444">Administered By</label>
                    <div class="administered-box">
                        <div class="administered-icon"><i class="fas fa-user"></i></div>
                        <div>
                            <div class="administered-label">Logged-in Officer</div>
                            <div class="administered-name"><?php echo htmlspecialchars($administered_by ?: 'Not identified – please log in'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top:18px">
                    <label for="submission_date">Submission Date</label>
                    <input type="date" name="submission_date" id="submission_date" class="form-control"
                           style="max-width:220px" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn" disabled>
            <i class="fas fa-save"></i> Submit Assessment
        </button>
    </form>
</div>

<script>
// Debounce function
function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func(...args), wait);
    };
}

let selectedStaff = null;

function checkSubmit() {
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = !selectedStaff;
    }
}

// Tab switching - FIXED: Added event parameter
function showTab(tabName, element) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });

    // Show selected tab
    const tabId = 'tab_' + tabName + '_tab';
    const selectedTab = document.getElementById(tabId);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }

    // Update tab buttons
    document.querySelectorAll('.staff-tab').forEach(tab => {
        tab.classList.remove('active');
    });

    // Add active class to clicked tab
    if (element) {
        element.classList.add('active');
    }
}

// Helper: build mini table
function miniTable(headers, rows) {
    if (!rows || rows.length === 0) return '';
    let t = '<table class="preview-table">';
    t += '<thead><tr>';
    headers.forEach(h => t += `<th>${h}</th>`);
    t += '</tr></thead><tbody>';
    rows.forEach((cells) => {
        t += '<tr>';
        cells.forEach(c => t += `<td>${c || '—'}</td>`);
        t += '</tr>';
    });
    t += '</tbody></table>';
    return t;
}

// Staff search
const staffInput = document.getElementById('staffSearch');
const staffResults = document.getElementById('staffResults');
const staffSpinner = document.getElementById('staffSpinner');
const staffIcon = document.getElementById('staffSearchIcon');

if (staffInput) {
    staffInput.addEventListener('input', debounce(async function() {
        const q = staffInput.value.trim();

        if (q.length < 2) {
            if (staffResults) staffResults.style.display = 'none';
            return;
        }

        if (staffSpinner) staffSpinner.style.display = 'block';
        if (staffIcon) staffIcon.style.display = 'none';
        if (staffResults) staffResults.innerHTML = '';

        try {
            const url = `training_needs_assessment_questionaire.php?ajax=search_staff&q=${encodeURIComponent(q)}`;
            console.log('Fetching:', url);

            const response = await fetch(url);
            console.log('Response status:', response.status);

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const text = await response.text();
            console.log('Raw response:', text.substring(0, 200));

            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON parse error:', e);
                console.error('Response text:', text);
                throw new Error('Invalid JSON response from server');
            }

            if (staffSpinner) staffSpinner.style.display = 'none';
            if (staffIcon) staffIcon.style.display = 'block';

            if (data.error) {
                console.error('Server error:', data.error);
                if (staffResults) {
                    staffResults.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i> Server error: ' + data.error + '</div>';
                }
            } else if (!data || data.length === 0) {
                if (staffResults) {
                    staffResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No active staff found</div>';
                }
            } else {
                if (staffResults) {
                    staffResults.innerHTML = data.map(r => {
                        const name = [r.first_name, r.other_name, r.last_name].filter(Boolean).join(' ');
                        // Properly escape the JSON string for onclick
                        const escapedData = JSON.stringify(r).replace(/'/g, "\\'");
                        return `<div class="result-item" onclick='selectStaff(${escapedData})'>
                            <div class="ri-name">${name} <span class="ri-badge">${r.id_number || ''}</span></div>
                            <div class="ri-meta">
                                <i class="fas fa-hospital"></i> ${r.facility_name || '—'} |
                                <i class="fas fa-phone"></i> ${r.staff_phone || '—'} |
                                Academics: ${r.academics_count || 0} | Reg: ${r.registrations_count || 0} | Exp: ${r.experience_count || 0}
                            </div>
                        </div>`;
                    }).join('');
                }
            }
            if (staffResults) staffResults.style.display = 'block';

        } catch (error) {
            console.error('Error:', error);
            if (staffSpinner) staffSpinner.style.display = 'none';
            if (staffIcon) staffIcon.style.display = 'block';
            if (staffResults) {
                staffResults.innerHTML = '<div class="no-results"><i class="fas fa-exclamation-triangle"></i> Error: ' + error.message + '</div>';
                staffResults.style.display = 'block';
            }
        }
    }, 350));
}

window.selectStaff = function(r) {
    selectedStaff = r;

    // Update hidden fields
    const idNumberField = document.getElementById('h_id_number');
    const facilityField = document.getElementById('h_facility_name');
    const countyField = document.getElementById('h_county_name');
    const subcountyField = document.getElementById('h_subcounty_name');

    if (idNumberField) idNumberField.value = r.id_number;
    if (facilityField) facilityField.value = r.facility_name || '';
    if (countyField) countyField.value = r.county_name || '';
    if (subcountyField) subcountyField.value = r.subcounty_name || '';

    // Update staff name
    const name = [r.first_name, r.other_name, r.last_name].filter(Boolean).join(' ');
    const scName = document.getElementById('sc_name');
    if (scName) scName.textContent = name;

    // Update personal info
    const personalFields = {
        'sc_id': r.id_number,
        'sc_phone': r.staff_phone,
        'sc_email': r.email,
        'sc_facility': r.facility_name,
        'sc_dept': r.department_name,
        'sc_cadre': r.cadre_name,
        'sc_county': r.county_name,
        'sc_sub': r.subcounty_name,
        'sc_sex': r.sex,
        'sc_emp': r.employment_status,
        'sc_dob': r.date_of_birth,
        'sc_doj': r.date_of_joining
    };

    for (let [id, value] of Object.entries(personalFields)) {
        const element = document.getElementById(id);
        if (element) element.textContent = value || '—';
    }

    // Update summary badges
    const badgeMappings = {
        'summary_academics': `Academics: ${r.academics_count || 0}`,
        'summary_registrations': `Registrations: ${r.registrations_count || 0}`,
        'summary_experience': `Experience: ${r.experience_count || 0}`,
        'summary_trainings': `Trainings: ${r.trainings_count || 0}`,
        'summary_languages': `Languages: ${r.languages_count || 0}`,
        'summary_referees': `Referees: ${r.referees_count || 0}`,
        'summary_kin': `Next of Kin: ${r.kin_count || 0}`
    };

    for (let [id, value] of Object.entries(badgeMappings)) {
        const element = document.getElementById(id);
        if (element) element.textContent = value;
    }

    // Update tab counts
    const tabMappings = {
        'tab_academics': r.academics_count,
        'tab_registrations': r.registrations_count,
        'tab_experience': r.experience_count,
        'tab_trainings': r.trainings_count,
        'tab_languages': r.languages_count,
        'tab_referees': r.referees_count,
        'tab_kin': r.kin_count,
        'tab_disciplinary': r.disciplinary_count,
        'tab_appraisals': r.appraisals_count,
        'tab_leave': r.leave_count
    };

    for (let [id, value] of Object.entries(tabMappings)) {
        const element = document.getElementById(id);
        if (element) element.textContent = value || 0;
    }

    // Academics
    const qualsTable = document.getElementById('pv_quals_table');
    const qualsEmpty = document.getElementById('pv_quals_empty');
    if (qualsTable && r.academics && r.academics.length > 0) {
        const acRows = r.academics.map(a => [
            a.qualification_type || '—',
            //a.course_name || a.qualification_name || '—',
            a.institution_name || '—',
            a.award_year || '—',
            a.completion_status || '—',
            a.verification_status || '—'
        ]);
        qualsTable.innerHTML = miniTable(
            ['Qualification', 'Course', 'Institution', 'Year', 'Status', 'Verified'], acRows
        );
        qualsTable.style.display = 'block';
        if (qualsEmpty) qualsEmpty.style.display = 'none';
    } else if (qualsTable) {
        qualsTable.innerHTML = '';
        if (qualsEmpty) qualsEmpty.style.display = 'block';
    }

    // Registrations
    const regsTable = document.getElementById('pv_regs_table');
    const regsEmpty = document.getElementById('pv_regs_empty');
    if (regsTable && r.registrations && r.registrations.length > 0) {
        const regRows = r.registrations.map(reg => {
            const expFlag = reg.is_expired ? ' (EXPIRED)' : '';
            return [
                reg.regulatory_body || '—',
                reg.registration_number || '—',
                reg.license_number || '—',
                reg.registration_date || '—',
                (reg.expiry_date || '—') + expFlag,
                reg.verification_status || '—'
            ];
        });
        regsTable.innerHTML = miniTable(
            ['Reg Body', 'Reg No.', 'Licence', 'Reg Date', 'Expiry', 'Status'], regRows
        );
        regsTable.style.display = 'block';
        if (regsEmpty) regsEmpty.style.display = 'none';
    } else if (regsTable) {
        regsTable.innerHTML = '';
        if (regsEmpty) regsEmpty.style.display = 'block';
    }

    // Experience
    const expTable = document.getElementById('pv_exp_table');
    const expEmpty = document.getElementById('pv_exp_empty');
    if (expTable && r.experience && r.experience.length > 0) {
        const expRows = r.experience.map(e => [
            e.employer_name || '—',
            e.job_title || '—',
            e.employer_type || '—',
            (e.start_date || '—') + ' – ' + (e.end_date || '—'),
            e.verification_status || '—'
        ]);
        expTable.innerHTML = miniTable(
            ['Employer', 'Job Title', 'Type', 'Period', 'Status'], expRows
        );
        expTable.style.display = 'block';
        if (expEmpty) expEmpty.style.display = 'none';
    } else if (expTable) {
        expTable.innerHTML = '';
        if (expEmpty) expEmpty.style.display = 'block';
    }

    // Trainings
    const trainTable = document.getElementById('pv_train_table');
    const trainEmpty = document.getElementById('pv_train_empty');
    if (trainTable && r.trainings && r.trainings.length > 0) {
        const trnRows = r.trainings.map(tr => [
            tr.training_name || '—',
            tr.training_provider || '—',
            tr.training_type || '—',
            (tr.start_date || '—') + ' – ' + (tr.end_date || '—'),
            tr.funding_source || '—'
        ]);
        trainTable.innerHTML = miniTable(
            ['Training', 'Provider', 'Type', 'Period', 'Funded By'], trnRows
        );
        trainTable.style.display = 'block';
        if (trainEmpty) trainEmpty.style.display = 'none';
    } else if (trainTable) {
        trainTable.innerHTML = '';
        if (trainEmpty) trainEmpty.style.display = 'block';
    }

    // Languages
    const langTable = document.getElementById('pv_lang_table');
    const langEmpty = document.getElementById('pv_lang_empty');
    if (langTable && r.languages && r.languages.length > 0) {
        const langRows = r.languages.map(l => [
            l.language_name || '—',
            l.proficiency || '—'
        ]);
        langTable.innerHTML = miniTable(['Language', 'Proficiency'], langRows);
        langTable.style.display = 'block';
        if (langEmpty) langEmpty.style.display = 'none';
    } else if (langTable) {
        langTable.innerHTML = '';
        if (langEmpty) langEmpty.style.display = 'block';
    }

    // Referees
    const refTable = document.getElementById('pv_ref_table');
    const refEmpty = document.getElementById('pv_ref_empty');
    if (refTable && r.referees && r.referees.length > 0) {
        const refRows = r.referees.map(f => [
            f.referee_name || '—',
            f.referee_position || '—',
            f.referee_organization || '—',
            f.referee_phone || '—'
        ]);
        refTable.innerHTML = miniTable(['Name', 'Position', 'Organization', 'Phone'], refRows);
        refTable.style.display = 'block';
        if (refEmpty) refEmpty.style.display = 'none';
    } else if (refTable) {
        refTable.innerHTML = '';
        if (refEmpty) refEmpty.style.display = 'block';
    }

    // Next of Kin
    const kinTable = document.getElementById('pv_kin_table');
    const kinEmpty = document.getElementById('pv_kin_empty');
    if (kinTable && r.next_of_kin && r.next_of_kin.length > 0) {
        const kinRows = r.next_of_kin.map(nk => [
            nk.kin_name || '—',
            nk.kin_relationship || '—',
            nk.kin_phone || '—',
            nk.is_emergency_contact || '—'
        ]);
        kinTable.innerHTML = miniTable(['Name', 'Relationship', 'Phone', 'Emergency'], kinRows);
        kinTable.style.display = 'block';
        if (kinEmpty) kinEmpty.style.display = 'none';
    } else if (kinTable) {
        kinTable.innerHTML = '';
        if (kinEmpty) kinEmpty.style.display = 'block';
    }

    // Disciplinary
    const discTable = document.getElementById('pv_disc_table');
    const discEmpty = document.getElementById('pv_disc_empty');
    if (discTable && r.disciplinary && r.disciplinary.length > 0) {
        const discRows = r.disciplinary.map(d => [
            d.case_number || '—',
            d.case_type || '—',
            d.incident_date || '—',
            d.status || '—'
        ]);
        discTable.innerHTML = miniTable(['Case #', 'Type', 'Incident Date', 'Status'], discRows);
        discTable.style.display = 'block';
        if (discEmpty) discEmpty.style.display = 'none';
    } else if (discTable) {
        discTable.innerHTML = '';
        if (discEmpty) discEmpty.style.display = 'block';
    }

    // Appraisals
    const appTable = document.getElementById('pv_app_table');
    const appEmpty = document.getElementById('pv_app_empty');
    if (appTable && r.appraisals && r.appraisals.length > 0) {
        const appRows = r.appraisals.map(a => [
            a.appraisal_period || '—',
            a.appraisal_year || '—',
            a.overall_rating || '—',
            a.appraisal_date || '—'
        ]);
        appTable.innerHTML = miniTable(['Period', 'Year', 'Rating', 'Date'], appRows);
        appTable.style.display = 'block';
        if (appEmpty) appEmpty.style.display = 'none';
    } else if (appTable) {
        appTable.innerHTML = '';
        if (appEmpty) appEmpty.style.display = 'block';
    }

    // Leave
    const leaveTable = document.getElementById('pv_leave_table');
    const leaveEmpty = document.getElementById('pv_leave_empty');
    if (leaveTable && r.leave && r.leave.length > 0) {
        const leaveRows = r.leave.map(l => [
            l.leave_type || '—',
            l.start_date || '—',
            l.end_date || '—',
            l.status || '—'
        ]);
        leaveTable.innerHTML = miniTable(['Leave Type', 'Start', 'End', 'Status'], leaveRows);
        leaveTable.style.display = 'block';
        if (leaveEmpty) leaveEmpty.style.display = 'none';
    } else if (leaveTable) {
        leaveTable.innerHTML = '';
        if (leaveEmpty) leaveEmpty.style.display = 'block';
    }

    // Show the staff card
    const staffCard = document.getElementById('staffCard');
    if (staffCard) staffCard.style.display = 'block';
    if (staffResults) staffResults.style.display = 'none';
    if (staffInput) staffInput.value = name;

    checkSubmit();
};

function clearStaff() {
    selectedStaff = null;

    const fields = ['h_id_number', 'h_facility_name', 'h_county_name', 'h_subcounty_name'];
    fields.forEach(id => {
        const element = document.getElementById(id);
        if (element) element.value = '';
    });

    const staffCard = document.getElementById('staffCard');
    if (staffCard) staffCard.style.display = 'none';
    if (staffInput) staffInput.value = '';

    checkSubmit();
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    if (staffResults && !e.target.closest('#staffSearchWrap')) {
        staffResults.style.display = 'none';
    }
});

// Challenge duties toggle
const challengeRadios = document.querySelectorAll('input[name="knowledge_skills_challenges"]');
challengeRadios.forEach(radio => {
    radio.addEventListener('change', function() {
        const challengingDutiesGroup = document.getElementById('challengingDutiesGroup');
        const challengingDuties = document.getElementById('challenging_duties');

        if (this.value === 'Yes') {
            if (challengingDutiesGroup) challengingDutiesGroup.style.display = 'block';
        } else {
            if (challengingDutiesGroup) challengingDutiesGroup.style.display = 'none';
            if (challengingDuties) challengingDuties.value = '';
        }
    });
});

// Training repeater
let entryCount = 1;

document.getElementById('addTrainingBtn')?.addEventListener('click', function() {
    entryCount++;
    const yearOptions = buildYearOptions();
    const html = `
    <div class="training-entry" data-entry="${entryCount}">
        <div class="training-entry-header">
            <span class="training-entry-num">Training Option ${entryCount}</span>
            <button type="button" class="remove-training" onclick="removeTraining(this)">? Remove</button>
        </div>
        <div class="training-grid">
            <div class="form-group">
                <label>Area of Training</label>
                <input type="text" name="proposed_training_area[]" class="form-control" placeholder="e.g. Advanced Clinical Care">
            </div>
            <div class="form-group">
                <label>Institution</label>
                <input type="text" name="proposed_training_institution[]" class="form-control" placeholder="e.g. Kenya Medical Training College">
            </div>
            <div class="form-group">
                <label>Duration</label>
                <input type="text" name="proposed_training_duration[]" class="form-control" placeholder="e.g. 3 months / 2 weeks">
            </div>
            <div class="form-group">
                <label>Preferred Year</label>
                <select name="proposed_training_year[]" class="form-select">
                    <option value="">-- Select Year --</option>
                    ${yearOptions}
                </select>
            </div>
        </div>
    </div>`;

    document.getElementById('trainingRepeater')?.insertAdjacentHTML('beforeend', html);
});

function buildYearOptions() {
    let opts = '';
    const cur = new Date().getFullYear();
    for (let y = cur; y <= cur + 4; y++) {
        opts += `<option value="${y}">${y}</option>`;
    }
    return opts;
}

window.removeTraining = function(btn) {
    const entry = btn.closest('.training-entry');
    const allEntries = document.querySelectorAll('.training-entry');

    if (allEntries.length <= 1) {
        alert('At least one training option is required.');
        return;
    }

    if (entry) entry.remove();

    // Renumber remaining entries
    document.querySelectorAll('.training-entry').forEach((el, index) => {
        const numSpan = el.querySelector('.training-entry-num');
        if (numSpan) numSpan.textContent = 'Training Option ' + (index + 1);
        el.dataset.entry = index + 1;
    });
    entryCount = document.querySelectorAll('.training-entry').length;
};

// Form validation
const tnaForm = document.getElementById('tnaForm');
if (tnaForm) {
    tnaForm.addEventListener('submit', function(e) {
        if (!selectedStaff) {
            e.preventDefault();
            alert('Please select a staff member before submitting.');
            if (staffInput) staffInput.focus();
            return false;
        }
        return true;
    });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Hide remove button for first training entry
    const firstRemoveBtn = document.querySelector('.training-entry .remove-training');
    if (firstRemoveBtn) {
        firstRemoveBtn.style.display = 'none';
    }

    // Set up tab click handlers
    document.querySelectorAll('.staff-tab').forEach(tab => {
        tab.addEventListener('click', function(e) {
            const tabName = this.textContent.trim().toLowerCase().split(' ')[0];
            showTab(tabName, this);
        });
    });
});
</script>

</body>
</html>