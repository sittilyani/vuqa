<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// -- AJAX: live staff search, same-file pattern (allocate_asset.php approach) --
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_staff') {
    $q = mysqli_real_escape_string($conn, trim($_GET['q'] ?? ''));
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT staff_id, first_name, other_name, last_name, id_number,
                    sex, staff_phone, email, cadre_name, department_name,
                    facility_name, county_name, subcounty_name, employment_status,
                    date_of_birth, date_of_joining
             FROM county_staff
             WHERE status = 'active'
               AND (first_name LIKE '%$q%' OR last_name LIKE '%$q%'
                    OR other_name LIKE '%$q%' OR id_number LIKE '%$q%'
                    OR staff_phone LIKE '%$q%')
             ORDER BY first_name, last_name LIMIT 15");
        while ($r = mysqli_fetch_assoc($res)) {
            $r['full_name'] = trim($r['first_name'] . ' ' .
                (!empty($r['other_name']) ? $r['other_name'] . ' ' : '') . $r['last_name']);

            // Age from DOB
            if (!empty($r['date_of_birth'])) {
                $age = (new DateTime($r['date_of_birth']))->diff(new DateTime())->y;
                $r['age'] = $age;
                if      ($age < 26) $r['age_range'] = '18-25';
                elseif  ($age < 36) $r['age_range'] = '26-35';
                elseif  ($age < 46) $r['age_range'] = '36-45';
                elseif  ($age < 56) $r['age_range'] = '46-55';
                else                $r['age_range'] = '56 and above';
                $r['date_of_birth'] = date('d M Y', strtotime($r['date_of_birth']));
            } else {
                $r['age'] = '';
                $r['age_range'] = '';
                $r['date_of_birth'] = '';
            }

            // Years of service from date_of_joining
            if (!empty($r['date_of_joining'])) {
                $yrs = (new DateTime($r['date_of_joining']))->diff(new DateTime())->y;
                $r['years_of_service'] = $yrs;
                if      ($yrs <= 5)  $r['yos_band'] = '5 yrs or below';
                elseif  ($yrs <= 10) $r['yos_band'] = '6-10 yrs';
                elseif  ($yrs <= 15) $r['yos_band'] = '11-15 yrs';
                elseif  ($yrs <= 20) $r['yos_band'] = '16-20 yrs';
                else                 $r['yos_band'] = 'over 21 yrs';
                $r['date_of_joining'] = date('d M Y', strtotime($r['date_of_joining']));
            } else {
                $r['years_of_service'] = '';
                $r['yos_band'] = '';
                $r['date_of_joining'] = '';
            }

            $id_esc = mysqli_real_escape_string($conn, $r['id_number']);

            // Academics
            $r['academics'] = [];
            $ra = mysqli_query($conn, "SELECT qualification_type, qualification_name, institution_name, course_name, award_year, completion_status, verification_status FROM employee_academics WHERE id_number='$id_esc' ORDER BY award_year DESC");
            if ($ra) while ($a = mysqli_fetch_assoc($ra)) $r['academics'][] = $a;

            // Registrations
            $r['registrations'] = [];
            $rr = mysqli_query($conn, "SELECT regulatory_body, registration_number, license_number, registration_date, expiry_date, verification_status FROM employee_professional_registrations WHERE id_number='$id_esc' ORDER BY expiry_date DESC");
            if ($rr) while ($reg = mysqli_fetch_assoc($rr)) {
                $reg['is_expired']        = (!empty($reg['expiry_date']) && strtotime($reg['expiry_date']) < time());
                $reg['expiry_date']       = !empty($reg['expiry_date'])       ? date('d M Y', strtotime($reg['expiry_date'])) : '';
                $reg['registration_date'] = !empty($reg['registration_date']) ? date('d M Y', strtotime($reg['registration_date'])) : '';
                $r['registrations'][] = $reg;
            }

            // Experience
            $r['experience'] = [];
            $re = mysqli_query($conn, "SELECT employer_name, employer_type, job_title, start_date, end_date, is_current, verification_status FROM employee_work_experience WHERE id_number='$id_esc' ORDER BY CASE WHEN is_current='Yes' THEN 0 ELSE 1 END, end_date DESC");
            if ($re) while ($e = mysqli_fetch_assoc($re)) {
                $e['start_date'] = !empty($e['start_date']) ? date('M Y', strtotime($e['start_date'])) : '';
                $e['end_date']   = $e['is_current']==='Yes' ? 'Present' : (!empty($e['end_date']) ? date('M Y', strtotime($e['end_date'])) : '');
                $r['experience'][] = $e;
            }

            // Trainings
            $r['trainings'] = [];
            $rt = mysqli_query($conn, "SELECT training_name, training_provider, training_type, start_date, end_date, funding_source FROM employee_trainings WHERE id_number='$id_esc' ORDER BY end_date DESC");
            if ($rt) while ($t = mysqli_fetch_assoc($rt)) {
                $t['start_date'] = !empty($t['start_date']) ? date('d M Y', strtotime($t['start_date'])) : '';
                $t['end_date']   = !empty($t['end_date'])   ? date('d M Y', strtotime($t['end_date']))   : '';
                $r['trainings'][] = $t;
            }
            $rows[] = $r;
        }
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

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
    <style>
        /* radio_buttons.css inlined - removes 404 dependency */
        input[type="radio"], input[type="checkbox"] {
            accent-color: #011f88;
            width: 16px;
            height: 16px;
            cursor: pointer;
            vertical-align: middle;
        }
    </style>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f0f2f7;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        /* -- HEADER -- */
        .header {
            background: linear-gradient(135deg, #011f88 0%, #1a3a9e 100%);
            color: white;
            padding: 24px 30px;
            border-radius: 10px;
            margin-bottom: 28px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(1,31,136,0.25);
        }
        .header h1 { font-size: 1.7rem; margin-bottom: 6px; }
        .header p  { font-size: 0.95rem; opacity: 0.85; }

        /* -- SECTION CARD -- */
        .form-section {
            background: white;
            border-radius: 10px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            border-left: 4px solid #011f88;
        }
        .section-title {
            color: #011f88;
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eef0f7;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title::before {
            content: '';
            width: 8px; height: 8px;
            background: #011f88;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* -- STAFF LOOKUP -- */
        .lookup-row {
            display: flex;
            gap: 14px;
            align-items: flex-end;
            margin-bottom: 20px;
        }
        .lookup-row .form-group { flex: 1; margin-bottom: 0; }
        .lookup-btn {
            background: #011f88;
            color: white;
            border: none;
            padding: 11px 22px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: background .2s;
            height: 46px;
        }
        .lookup-btn:hover { background: #0d2ea8; }

        /* -- STAFF PREVIEW PANEL -- */
        .staff-preview {
            display: none;
            background: #f0f4ff;
            border: 1px solid #c5d0f0;
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .staff-preview.visible { display: block; }
        .staff-preview-title {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #011f88;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .staff-preview-title i { font-size: 14px; }
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        .preview-item label {
            font-size: 11px;
            color: #666;
            display: block;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: .4px;
            font-weight: 600;
        }
        .preview-item .pval {
            font-size: 14px;
            font-weight: 600;
            color: #1a1e2e;
        }
        .preview-item.full-span { grid-column: 1 / -1; }

        .quals-list {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 4px;
        }
        .quals-list li {
            background: #dde4ff;
            color: #011f88;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        /* -- FORM ELEMENTS -- */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #444;
            font-size: 14px;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color .2s, box-shadow .2s;
            background: white;
            font-family: inherit;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #011f88;
            box-shadow: 0 0 0 3px rgba(1,31,136,.1);
        }
        .form-control[readonly] {
            background: #f8f9fc;
            color: #555;
            cursor: default;
        }
        textarea.form-control { min-height: 90px; resize: vertical; }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
        }

        /* -- RADIO / CHECKBOX -- */
        .radio-group, .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
            margin-top: 6px;
        }
        .radio-option, .checkbox-option {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 14px;
            cursor: pointer;
        }
        .radio-option input, .checkbox-option input { width: auto; }

        /* -- CHALLENGE SCALE -- */
        .scale-explanation {
            background: #f0f4ff;
            padding: 10px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
            color: #011f88;
        }
        .scale-header { display: flex; justify-content: space-between; font-weight: 700; }

        .challenge-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding: 10px 14px;
            background: #f9f9fb;
            border-radius: 6px;
        }
        .challenge-label { flex: 1; font-weight: 500; font-size: 14px; }
        .rating-options { display: flex; gap: 14px; }
        .rating-option {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .radio-input { margin: 0; width: auto; }
        .radio-label { font-size: 13px; color: #666; margin-top: 4px; }

        /* -- CHECKBOXES 2-COL -- */
        .checkbox-2col {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 8px 24px;
            margin-top: 10px;
        }
        .checkbox-2col label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 400;
            color: #333;
            cursor: pointer;
            padding: 5px 0;
        }
        .checkbox-2col input[type=checkbox] { width: auto; flex-shrink: 0; }

        /* -- TRAINING REPEATER -- */
        .training-repeater { margin-top: 10px; }

        .training-entry {
            background: #f8f9fc;
            border: 1px solid #e0e4f0;
            border-radius: 8px;
            padding: 16px 18px;
            margin-bottom: 12px;
            position: relative;
        }
        .training-entry-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        .training-entry-num {
            font-size: 12px;
            font-weight: 700;
            color: #011f88;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .remove-training {
            background: #fee2e2;
            color: #dc2626;
            border: none;
            border-radius: 5px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: none;
        }
        .remove-training:hover { background: #fecaca; }

        .training-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .add-training-btn {
            background: #e8f0ff;
            color: #011f88;
            border: 2px dashed #011f88;
            border-radius: 8px;
            padding: 11px 22px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background .2s;
            width: 100%;
            justify-content: center;
            margin-top: 4px;
        }
        .add-training-btn:hover { background: #d0ddff; }

        /* -- ADMINISTERED BY BOX -- */
        .administered-box {
            background: #f0f4ff;
            border: 1px solid #c5d0f0;
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 10px;
        }
        .administered-icon {
            width: 46px; height: 46px;
            background: #011f88;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }
        .administered-name {
            font-size: 17px;
            font-weight: 700;
            color: #011f88;
        }
        .administered-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 2px;
        }

        /* -- SUBMIT BTN -- */
        .btn-submit {
            background: #011f88;
            color: white;
            padding: 14px 40px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            display: block;
            width: 100%;
            max-width: 320px;
            margin: 30px auto;
            transition: background .2s, transform .15s;
        }
        .btn-submit:hover { background: #0d2ea8; transform: translateY(-1px); }

        /* -- MESSAGES -- */
        .success-message {
            background: #d1fae5; color: #065f46;
            padding: 14px; border-radius: 6px; margin-bottom: 20px; text-align: center;
        }
        .error-message {
            background: #fee2e2; color: #991b1b;
            padding: 14px; border-radius: 6px; margin-bottom: 20px; text-align: center;
        }

        /* -- SEARCH WRAP (allocate_asset.php style) -- */
        .search-wrap { position: relative; margin-bottom: 20px; }
        .search-wrap input {
            width: 100%; padding: 12px 44px 12px 16px;
            border: 2px solid #e0e0e0; border-radius: 9px; font-size: 14px;
            transition: border-color .25s; font-family: inherit;
        }
        .search-wrap input:focus { outline: none; border-color: #011f88; box-shadow: 0 0 0 3px rgba(1,31,136,.1); }
        .search-wrap .s-icon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #aaa; font-size: 15px; pointer-events: none; }
        .search-wrap .s-spinner { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #011f88; font-size: 14px; display: none; }
        .results-dropdown {
            position: absolute; z-index: 999; width: 100%; background: #fff;
            border: 1.5px solid #dce3f5; border-radius: 10px; margin-top: 4px;
            box-shadow: 0 8px 28px rgba(1,31,136,.15); max-height: 300px; overflow-y: auto; display: none;
        }
        .result-item { padding: 11px 15px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background .15s; }
        .result-item:last-child { border-bottom: none; }
        .result-item:hover { background: #f0f3fb; }
        .ri-name  { font-weight: 700; color: #011f88; font-size: 13.5px; }
        .ri-meta  { font-size: 11.5px; color: #777; margin-top: 2px; }
        .ri-badge { display: inline-block; font-size: 10px; background: #e8edf8; color: #011f88; border-radius: 4px; padding: 1px 6px; margin-left: 6px; font-weight: 600; }
        .no-results { padding: 14px; color: #999; font-size: 13px; text-align: center; }

        /* -- RESPONSIVE -- */
        @media (max-width: 768px) {
            .form-grid, .training-grid, .preview-grid { grid-template-columns: 1fr; }
            .challenge-item { flex-direction: column; align-items: flex-start; gap: 10px; }
            .checkbox-2col { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h1>Training Needs Assessment Questionnaire</h1>
        <p>County Department of Health | Staff Development | Capacity Building</p>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="success-message">&#10003; Training Needs Assessment submitted successfully!</div>
    <?php endif; ?>

    <form id="tnaForm" action="submit_training_needs.php" method="POST">

        <!-- Hidden id_number submitted with form -->
        <input type="hidden" name="id_number"       id="hidden_id_number">
        <input type="hidden" name="administered_by" value="<?php echo htmlspecialchars($administered_by); ?>">
        <input type="hidden" name="facility_name"   id="hidden_facility_name">
        <input type="hidden" name="county_name"     id="hidden_county_name">
        <input type="hidden" name="subcounty_name"  id="hidden_subcounty_name">

        <!-- SECTION 2 ? PERSONAL DATA  (ID lookup ? auto-fill) -->
        <div class="form-section">
            <h2 class="section-title">2. Personal Data</h2>

            <!-- Staff live search (allocate_asset.php pattern) -->
            <div class="search-wrap" id="staffSearchWrap">
                <input type="text" id="staffSearch"
                       placeholder="Type name, ID number or phone to search staff..."
                       autocomplete="off">
                <i class="fas fa-id-card s-icon" id="staffSearchIcon"></i>
                <i class="fas fa-spinner fa-spin s-spinner" id="staffSpinner"></i>
                <div class="results-dropdown" id="staffResults"></div>
            </div>

            <!-- Staff preview panel (read-only, for verification) -->
            <div class="staff-preview" id="staffPreview">
                <div class="staff-preview-title">
                    <i class="fas fa-check-circle"></i> Staff Record Found &mdash; For Verification Only
                </div>

                <!-- Core details grid -->
                <div class="preview-grid">
                    <div class="preview-item"><label>Full Name</label><div class="pval" id="pv_name"></div></div>
                    <div class="preview-item"><label>ID Number</label><div class="pval" id="pv_id"></div></div>
                    <div class="preview-item"><label>Cadre</label><div class="pval" id="pv_cadre"></div></div>
                    <div class="preview-item"><label>Department</label><div class="pval" id="pv_department"></div></div>
                    <div class="preview-item"><label>Facility</label><div class="pval" id="pv_facility"></div></div>
                    <div class="preview-item"><label>County</label><div class="pval" id="pv_county"></div></div>
                    <div class="preview-item"><label>Subcounty</label><div class="pval" id="pv_subcounty"></div></div>
                    <div class="preview-item"><label>Sex</label><div class="pval" id="pv_sex"></div></div>
                    <div class="preview-item"><label>Date of Birth</label><div class="pval" id="pv_dob"></div></div>
                    <div class="preview-item"><label>Date of Joining</label><div class="pval" id="pv_doj"></div></div>
                    <div class="preview-item"><label>Employment Status</label><div class="pval" id="pv_emp_status"></div></div>
                    <div class="preview-item"><label>Phone</label><div class="pval" id="pv_phone"></div></div>
                    <div class="preview-item"><label>Email</label><div class="pval" id="pv_email"></div></div>
                </div>

                <!-- Academic qualifications table -->
                <div id="pv_quals_wrap" style="display:none;margin-top:14px">
                    <div style="font-size:11px;font-weight:700;color:#011f88;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Academic Qualifications on Record</div>
                    <div id="pv_quals_table"></div>
                </div>

                <!-- Professional registrations table -->
                <div id="pv_regs_wrap" style="display:none;margin-top:14px">
                    <div style="font-size:11px;font-weight:700;color:#011f88;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Professional Registrations on Record</div>
                    <div id="pv_regs_table"></div>
                </div>

                <!-- Work experience table -->
                <div id="pv_exp_wrap" style="display:none;margin-top:14px">
                    <div style="font-size:11px;font-weight:700;color:#011f88;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Work Experience on Record</div>
                    <div id="pv_exp_table"></div>
                </div>

                <!-- Past trainings table -->
                <div id="pv_train_wrap" style="display:none;margin-top:14px">
                    <div style="font-size:11px;font-weight:700;color:#011f88;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px">Training Records on File</div>
                    <div id="pv_train_table"></div>
                </div>

                <!-- No records notice -->
                <div id="pv_no_records" style="display:none;margin-top:12px;font-size:13px;color:#888;font-style:italic">
                    No academic, registration, experience or training records found yet for this staff member.
                </div>
            </div>

            <div class="form-group">
                <label>Position</label>
                <select class="form-select" name="position">
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
                <input type="text" name="designation" class="form-control" placeholder="e.g. Senior Clinical Officer">
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

        <!-- JOB CONTENT  -->
        <div class="form-section">
            <h2 class="section-title">Job Content</h2>
            <div class="form-group">
                <label for="duties_responsibilities">i. What are your duties and responsibilities?</label>
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
                <label>vi. How did you acquire the skills that enable you perform your duties?</label>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="skills_acquisition" value="Experience"> Experience</label>
                    <label class="radio-option"><input type="radio" name="skills_acquisition" value="Attachment"> Attachment</label>
                    <label class="radio-option"><input type="radio" name="skills_acquisition" value="Training"> Training</label>
                    <label class="radio-option"><input type="radio" name="skills_acquisition" value="Mentorship"> Mentorship</label>
                    <label class="radio-option"><input type="radio" name="skills_acquisition" value="Induction"> Induction</label>
                    <label class="radio-option"><input type="radio" name="skills_acquisition" value="Research"> Research</label>
                </div>
            </div>

            <div class="form-group" style="margin-top:24px">
                <label><b>vii. In a scale of 1-5, rate the level of challenge in each area (1 = Least, 5 = Most Challenging)</b></label>
                <div class="scale-explanation">
                    <div class="scale-header"><span>1 – Least Challenging</span><span>5 – Most Challenging</span></div>
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
                <div class="challenge-item">
                    <div class="challenge-label"><?php echo $flabel; ?></div>
                    <div class="rating-options">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <div class="rating-option">
                            <input type="radio" id="<?php echo $fname . '_' . $i; ?>" name="<?php echo $fname; ?>" value="<?php echo $i; ?>" class="radio-input">
                            <label for="<?php echo $fname . '_' . $i; ?>" class="radio-label"><?php echo $i; ?></label>
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

        <!-- SECTION PERFORMANCE MEASURES -->
        <div class="form-section">
            <h2 class="section-title">Performance Measures</h2>

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

        <!-- SECTION 6 – TECHNICAL SKILL LEVELS  -->
        <div class="form-section">
            <h2 class="section-title">Technical Skill Levels</h2>
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
                <label style="font-weight:700;font-size:14px">iv. From the following core competences, please tick the ones you have been trained on:</label>
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

        <!-- TRAINING  (repeating entries + county-sponsored) -->
        <div class="form-section">
            <h2 class="section-title">Training</h2>

            <!-- i (a) County-sponsored training -->
            <div class="form-group">
                <label>i. (a) Have you attended any training sponsored by the County Government?</label>
                <div class="radio-group">
                    <label class="radio-option"><input type="radio" name="attended_training" value="Yes"> Yes</label>
                    <label class="radio-option"><input type="radio" name="attended_training" value="No"> No</label>
                </div>
            </div>
            <div class="form-group">
                <label for="training_details">(b) If yes, please specify the area of training, duration and year</label>
                <textarea id="training_details" name="training_details" class="form-control"></textarea>
            </div>

            <!-- ii Proposed training – REPEATING ENTRY -->
            <div class="form-group" style="margin-top:24px">
                <label style="font-size:15px;font-weight:700">
                    ii. Proposed areas of training for the next three years
                    <span style="font-weight:400;font-size:13px;color:#666"> – specify institution and duration</span>
                </label>

                <div class="training-repeater" id="trainingRepeater">
                    <!-- First entry (always visible) -->
                    <div class="training-entry" data-entry="1">
                        <div class="training-entry-header">
                            <span class="training-entry-num">Training Option 1</span>
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
                                    <?php for ($y = date('Y'); $y <= date('Y') + 4; $y++): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" class="add-training-btn" id="addTrainingBtn">
                    + Add Another Training Option
                </button>
            </div>

            <!-- Administered By -->
            <div class="form-group" style="margin-top:30px;border-top:2px solid #eef0f7;padding-top:22px">
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
                       style="max-width:220px"
                       value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>

        <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Submit Assessment</button>
    </form>
</div>

<!-- Font Awesome + jQuery -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function () {

    // -- STAFF LIVE SEARCH (allocate_asset.php pattern) -------------------
    function debounce(fn, delay) {
        let t;
        return function(...args) { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
    }

    let selectedStaff = null;
    const staffInput   = document.getElementById('staffSearch');
    const staffResults = document.getElementById('staffResults');
    const staffSpinner = document.getElementById('staffSpinner');
    const staffIcon    = document.getElementById('staffSearchIcon');

    staffInput.addEventListener('input', debounce(async function () {
        const q = staffInput.value.trim();
        if (q.length < 2) { staffResults.style.display = 'none'; return; }
        staffSpinner.style.display = 'block'; staffIcon.style.display = 'none';
        try {
            const res  = await fetch(`training_needs_assessment_questionaire.php?ajax=search_staff&q=${encodeURIComponent(q)}`);
            const rows = await res.json();
            staffSpinner.style.display = 'none'; staffIcon.style.display = 'block';
            renderStaffResults(rows);
        } catch(e) {
            staffSpinner.style.display = 'none'; staffIcon.style.display = 'block';
        }
    }, 350));

    function renderStaffResults(rows) {
        if (!rows || !rows.length) {
            staffResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No active staff found</div>';
        } else {
            staffResults.innerHTML = rows.map(r => {
                const name = [r.first_name, r.other_name, r.last_name].filter(Boolean).join(' ');
                return `<div class="result-item" onclick='selectStaff(${JSON.stringify(r).replace(/'/g, "&#39;")})'>
                    <div class="ri-name">${name} <span class="ri-badge">${r.id_number}</span></div>
                    <div class="ri-meta">
                        <i class="fas fa-hospital" style="color:#011f88"></i> ${r.facility_name || ''}
                        &nbsp;|&nbsp; ${r.cadre_name || ''}
                        &nbsp;|&nbsp; ${r.county_name || ''}
                    </div>
                </div>`;
            }).join('');
        }
        staffResults.style.display = 'block';
    }

    window.selectStaff = function(r) {
        selectedStaff = r;
        const name = [r.first_name, r.other_name, r.last_name].filter(Boolean).join(' ');
        staffInput.value = name + ' (' + r.id_number + ')';
        staffResults.style.display = 'none';

        // Set hidden ID number + facility/county from staff record
        $('#hidden_id_number').val(r.id_number);
        $('#hidden_facility_name').val(r.facility_name || '');
        $('#hidden_county_name').val(r.county_name || '');
        $('#hidden_subcounty_name').val(r.subcounty_name || '');

        // Populate preview panel
        $('#pv_name').text(r.full_name || name);
        $('#pv_id').text(r.id_number || '—');
        $('#pv_cadre').text(r.cadre_name || '—');
        $('#pv_department').text(r.department_name || '—');
        $('#pv_facility').text(r.facility_name || '—');
        $('#pv_county').text(r.county_name || '—');
        $('#pv_subcounty').text(r.subcounty_name || '—');
        $('#pv_sex').text(r.sex || '—');
        $('#pv_dob').text(r.date_of_birth || '—');
        $('#pv_doj').text(r.date_of_joining || '—');
        $('#pv_emp_status').text(r.employment_status || '—');
        $('#pv_phone').text(r.staff_phone || '—');
        $('#pv_email').text(r.email || '—');

        // Helper: build a mini inline table
        function miniTable(headers, rows) {
            let t = '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:4px">';
            t += '<thead><tr>';
            headers.forEach(h => t += `<th style="background:#011f88;color:#fff;padding:5px 8px;text-align:left;font-weight:600">${h}</th>`);
            t += '</tr></thead><tbody>';
            rows.forEach((cells, ri) => {
                const bg = ri % 2 === 0 ? '#f4f6fb' : '#fff';
                t += `<tr style="background:${bg}">`;
                cells.forEach(c => t += `<td style="padding:5px 8px;border-bottom:1px solid #e8eaf0">${c || '—'}</td>`);
                t += '</tr>';
            });
            return t + '</tbody></table>';
        }

        let anyRecord = false;

        // Academics
        if (r.academics && r.academics.length > 0) {
            anyRecord = true;
            const acRows = r.academics.map(a => [a.qualification_type, a.course_name || a.qualification_name || '—', a.institution_name, a.award_year || '—', a.completion_status, a.verification_status]);
            $('#pv_quals_table').html(miniTable(['Qualification','Course / Name','Institution','Year','Status','Verified'], acRows));
            $('#pv_quals_wrap').show();
        } else { $('#pv_quals_wrap').hide(); }

        // Registrations
        if (r.registrations && r.registrations.length > 0) {
            anyRecord = true;
            const regRows = r.registrations.map(reg => {
                const expFlag = reg.is_expired ? ' <span style="color:#dc2626;font-weight:700">(EXPIRED)</span>' : '';
                return [reg.regulatory_body, reg.registration_number, reg.license_number || '—', reg.registration_date, reg.expiry_date + expFlag, reg.verification_status];
            });
            $('#pv_regs_table').html(miniTable(['Regulatory Body','Reg. No.','Licence No.','Reg. Date','Expiry','Verified'], regRows));
            $('#pv_regs_wrap').show();
        } else { $('#pv_regs_wrap').hide(); }

        // Experience
        if (r.experience && r.experience.length > 0) {
            anyRecord = true;
            const expRows = r.experience.map(e => [e.employer_name, e.job_title, e.employer_type, e.start_date + ' – ' + e.end_date, e.verification_status]);
            $('#pv_exp_table').html(miniTable(['Employer','Job Title','Type','Period','Verified'], expRows));
            $('#pv_exp_wrap').show();
        } else { $('#pv_exp_wrap').hide(); }

        // Trainings
        if (r.trainings && r.trainings.length > 0) {
            anyRecord = true;
            const trnRows = r.trainings.map(tr => [tr.training_name, tr.training_provider, tr.training_type, tr.start_date + ' – ' + tr.end_date, tr.funding_source || '—']);
            $('#pv_train_table').html(miniTable(['Training','Provider','Type','Period','Funded By'], trnRows));
            $('#pv_train_wrap').show();
        } else { $('#pv_train_wrap').hide(); }

        $('#pv_no_records').toggle(!anyRecord);

        $('#staffPreview').addClass('visible');
    };

    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#staffSearchWrap')) staffResults.style.display = 'none';
    });

    // -- CHALLENGE DUTIES TOGGLE ------------------------------------------
    $('input[name="knowledge_skills_challenges"]').on('change', function () {
        if ($(this).val() === 'Yes') {
            $('#challengingDutiesGroup').show();
        } else {
            $('#challengingDutiesGroup').hide();
            $('#challenging_duties').val('');
        }
    });

    // -- TRAINING REPEATER ------------------------------------------------
    let entryCount = 1;

    $('#addTrainingBtn').on('click', function () {
        entryCount++;
        const yearOptions = buildYearOptions();
        const html = `
        <div class="training-entry" data-entry="${entryCount}">
            <div class="training-entry-header">
                <span class="training-entry-num">Training Option ${entryCount}</span>
                <button type="button" class="remove-training" onclick="removeTraining(this)" style="display:inline-flex">? Remove</button>
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
        $('#trainingRepeater').append(html);
        const $new = $('#trainingRepeater .training-entry:last');
        $('html,body').animate({ scrollTop: $new.offset().top - 120 }, 300);
    });

    function buildYearOptions() {
        let opts = '';
        const cur = new Date().getFullYear();
        for (let y = cur; y <= cur + 4; y++) opts += `<option value="${y}">${y}</option>`;
        return opts;
    }

    // -- FORM VALIDATION --------------------------------------------------
    $('#tnaForm').on('submit', function (e) {
        if (!$('#hidden_id_number').val()) {
            e.preventDefault();
            alert('Please search for and select a staff member before submitting.');
            staffInput.focus();
            return false;
        }
        return true;
    });
});

// Remove training entry
function removeTraining(btn) {
    const $entry = $(btn).closest('.training-entry');
    if ($('#trainingRepeater .training-entry').length <= 1) {
        alert('At least one training option is required.');
        return;
    }
    $entry.remove();
    $('#trainingRepeater .training-entry').each(function (i) {
        $(this).find('.training-entry-num').text('Training Option ' + (i + 1));
        $(this).attr('data-entry', i + 1);
    });
}
</script>
</body>
</html>