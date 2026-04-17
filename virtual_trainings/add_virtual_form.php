<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit();
}

$error = '';
$success = '';
$import_success = '';
$import_errors = [];

// Get logged-in user for administered_by field
$administered_by = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';

// Get counties for dropdown
$counties = [];
$county_result = $conn->query("SELECT county_name FROM counties ORDER BY county_name");
if ($county_result) {
        while ($row = $county_result->fetch_assoc()) {
                $counties[] = $row['county_name'];
        }
}

// Get cadres for dropdown
$cadres = [];
$cadre_result = $conn->query("SELECT cadre_name FROM cadres ORDER BY cadre_name");
if ($cadre_result) {
        while ($row = $cadre_result->fetch_assoc()) {
                $cadres[] = $row['cadre_name'];
        }
}

// Handle single form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_training'])) {
        $participant_name = mysqli_real_escape_string($conn, trim($_POST['participant_name']));
        $sex = mysqli_real_escape_string($conn, $_POST['sex']);
        $county = mysqli_real_escape_string($conn, $_POST['county']);
        $cadre_name = mysqli_real_escape_string($conn, $_POST['cadre_name']);
        $email = mysqli_real_escape_string($conn, trim($_POST['email']));
        $date = mysqli_real_escape_string($conn, $_POST['date']);
        $cme_title = mysqli_real_escape_string($conn, trim($_POST['cme_title']));
        $disability = mysqli_real_escape_string($conn, $_POST['disability']);
        $work_station = mysqli_real_escape_string($conn, trim($_POST['work_station']));
        $department = mysqli_real_escape_string($conn, trim($_POST['department']));

        // Validate data
        if (empty($participant_name) || empty($cme_title) || empty($date)) {
                $error = "Please fill in all required fields (Participant Name, CME Title, and Date).";
        } else {
                $sql = "INSERT INTO virtual_trainings (participant_name, sex, county, cadre_name, email, date, cme_title, disability, work_station, department, administered_by, created_at)
                                VALUES ('$participant_name', '$sex', '$county', '$cadre_name', '$email', '$date', '$cme_title', '$disability', '$work_station', '$department', '$administered_by', NOW())";

                if ($conn->query($sql) === TRUE) {
                        $_SESSION['success_msg'] = 'Training record added successfully!';
                        header('Location: add_virtual_form.php?success=1');
                        exit();
                } else {
                        $error = "Error: " . $sql . "<br>" . $conn->error;
                }
        }
}

// Handle Excel import
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['import_excel'])) {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] == 0) {
                $file_name = $_FILES['excel_file']['name'];
                $file_tmp = $_FILES['excel_file']['tmp_name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_extensions = ['xlsx', 'xls', 'csv'];

                if (in_array($file_ext, $allowed_extensions)) {
                        require_once '../vendor/autoload.php'; // Make sure PhpSpreadsheet is installed

                        try {
                                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_tmp);
                                $worksheet = $spreadsheet->getActiveSheet();
                                $rows = $worksheet->toArray();

                                // Remove header row
                                $header = array_shift($rows);

                                $success_count = 0;
                                $error_count = 0;

                                foreach ($rows as $row_index => $row) {
                                        // Skip empty rows
                                        if (empty(array_filter($row))) {
                                                continue;
                                        }

                                        // Map columns (adjust indices based on your Excel file structure)
                                        $participant_name = isset($row[0]) ? mysqli_real_escape_string($conn, trim($row[0])) : '';
                                        $sex = isset($row[1]) ? mysqli_real_escape_string($conn, $row[1]) : '';
                                        $county = isset($row[2]) ? mysqli_real_escape_string($conn, $row[2]) : '';
                                        $cadre_name = isset($row[3]) ? mysqli_real_escape_string($conn, $row[3]) : '';
                                        $email = isset($row[4]) ? mysqli_real_escape_string($conn, trim($row[4])) : '';
                                        $date = isset($row[5]) ? mysqli_real_escape_string($conn, date('Y-m-d', strtotime($row[5]))) : '';
                                        $cme_title = isset($row[6]) ? mysqli_real_escape_string($conn, trim($row[6])) : '';
                                        $disability = isset($row[7]) ? mysqli_real_escape_string($conn, $row[7]) : 'No';
                                        $work_station = isset($row[8]) ? mysqli_real_escape_string($conn, trim($row[8])) : '';
                                        $department = isset($row[9]) ? mysqli_real_escape_string($conn, trim($row[9])) : '';

                                        // Validate required fields
                                        if (empty($participant_name) || empty($cme_title) || empty($date)) {
                                                $error_count++;
                                                $import_errors[] = "Row " . ($row_index + 2) . ": Missing required fields (Participant Name, CME Title, or Date)";
                                                continue;
                                        }

                                        $sql = "INSERT INTO virtual_trainings (participant_name, sex, county, cadre_name, email, date, cme_title, disability, work_station, department, administered_by, created_at)
                                                        VALUES ('$participant_name', '$sex', '$county', '$cadre_name', '$email', '$date', '$cme_title', '$disability', '$work_station', '$department', '$administered_by', NOW())";

                                        if ($conn->query($sql) === TRUE) {
                                                $success_count++;
                                        } else {
                                                $error_count++;
                                                $import_errors[] = "Row " . ($row_index + 2) . ": " . $conn->error;
                                        }
                                }

                                $_SESSION['import_success'] = "Successfully imported $success_count records. $error_count failed.";
                                if (!empty($import_errors)) {
                                        $_SESSION['import_errors'] = $import_errors;
                                }
                                /*header('Location: add_virtual_training.php?import=1'); */
                                exit();

                        } catch (Exception $e) {
                                $error = "Error reading Excel file: " . $e->getMessage();
                        }
                } else {
                        $error = "Please upload a valid Excel file (.xlsx, .xls, or .csv)";
                }
        } else {
                $error = "Please select a file to upload.";
        }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Add Virtual Training - Transition Tracker</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
                * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                }

                body {
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        background: #f0f2f7;
                        color: #333;
                        line-height: 1.6;
                }

                .container {
                        max-width: 1000px;
                        margin: 0 auto;
                        padding: 40px 20px;
                }

                /* Header */
                .page-header {
                        background: linear-gradient(135deg, #0D1A63 0%, #1a3a9e 100%);
                        color: #fff;
                        padding: 22px 30px;
                        border-radius: 14px;
                        margin-bottom: 30px;
                        box-shadow: 0 6px 24px rgba(13,26,99,.25);
                }

                .page-header h1 {
                        font-size: 1.6rem;
                        font-weight: 700;
                        display: flex;
                        align-items: center;
                        gap: 10px;
                }

                .page-header p {
                        font-size: 13px;
                        opacity: 0.8;
                        margin-top: 5px;
                }

                .back-link {
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        background: #fff;
                        padding: 8px 16px;
                        border-radius: 8px;
                        text-decoration: none;
                        color: #0D1A63;
                        font-size: 13px;
                        font-weight: 600;
                        margin-bottom: 20px;
                        transition: all .2s;
                        box-shadow: 0 1px 3px rgba(0,0,0,.1);
                }

                .back-link:hover {
                        background: #e8edf8;
                        transform: translateX(-2px);
                }

                /* Alerts */
                .alert {
                        padding: 14px 18px;
                        border-radius: 10px;
                        margin-bottom: 20px;
                        font-size: 14px;
                        display: flex;
                        align-items: center;
                        gap: 10px;
                }

                .alert-success {
                        background: #d4edda;
                        color: #155724;
                        border-left: 4px solid #28a745;
                }

                .alert-error {
                        background: #f8d7da;
                        color: #721c24;
                        border-left: 4px solid #dc3545;
                }

                .alert-warning {
                        background: #fff3cd;
                        color: #856404;
                        border-left: 4px solid #ffc107;
                }

                /* Tabs */
                .tabs {
                        display: flex;
                        gap: 10px;
                        margin-bottom: 25px;
                        border-bottom: 2px solid #e0e4f0;
                }

                .tab-btn {
                        background: none;
                        border: none;
                        padding: 12px 25px;
                        font-size: 14px;
                        font-weight: 600;
                        color: #666;
                        cursor: pointer;
                        transition: all .2s;
                        position: relative;
                }

                .tab-btn.active {
                        color: #0D1A63;
                }

                .tab-btn.active::after {
                        content: '';
                        position: absolute;
                        bottom: -2px;
                        left: 0;
                        right: 0;
                        height: 2px;
                        background: #0D1A63;
                }

                .tab-btn:hover {
                        color: #0D1A63;
                }

                .tab-content {
                        display: none;
                }

                .tab-content.active {
                        display: block;
                }

                /* Form Card */
                .form-card {
                        background: #fff;
                        border-radius: 16px;
                        box-shadow: 0 4px 20px rgba(0,0,0,.08);
                        overflow: hidden;
                }

                .form-header {
                        background: linear-gradient(90deg, #f8fafc, #fff);
                        padding: 20px 25px;
                        border-bottom: 1px solid #e8ecf5;
                }

                .form-header h2 {
                        font-size: 18px;
                        font-weight: 700;
                        color: #0D1A63;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                }

                .form-body {
                        padding: 25px;
                }

                /* Form Grid */
                .form-grid {
                        display: grid;
                        grid-template-columns: repeat(2, 1fr);
                        gap: 20px;
                }

                .form-group {
                        margin-bottom: 5px;
                }

                .form-group.full-width {
                        grid-column: span 2;
                }

                .form-group label {
                        display: block;
                        margin-bottom: 8px;
                        font-weight: 600;
                        color: #444;
                        font-size: 12px;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                }

                .form-group label i {
                        margin-right: 6px;
                        color: #0D1A63;
                }

                .form-control, .form-select {
                        width: 100%;
                        padding: 12px 15px;
                        border: 2px solid #e0e4f0;
                        border-radius: 10px;
                        font-size: 14px;
                        font-family: inherit;
                        transition: all .2s;
                        background: #fff;
                }

                .form-control:focus, .form-select:focus {
                        outline: none;
                        border-color: #0D1A63;
                        box-shadow: 0 0 0 3px rgba(13,26,99,.1);
                }

                /* Radio Group */
                .radio-group {
                        display: flex;
                        gap: 20px;
                        margin-top: 8px;
                }

                .radio-option {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        cursor: pointer;
                }

                .radio-option input[type="radio"] {
                        width: 16px;
                        height: 16px;
                        accent-color: #0D1A63;
                        cursor: pointer;
                }

                /* File Upload */
                .file-upload {
                        border: 2px dashed #e0e4f0;
                        border-radius: 10px;
                        padding: 30px;
                        text-align: center;
                        cursor: pointer;
                        transition: all .2s;
                }

                .file-upload:hover {
                        border-color: #0D1A63;
                        background: #f8fafc;
                }

                .file-upload i {
                        font-size: 48px;
                        color: #0D1A63;
                        margin-bottom: 10px;
                }

                .file-upload p {
                        color: #666;
                        font-size: 13px;
                }

                .file-upload input[type="file"] {
                        display: none;
                }

                /* Button */
                .btn-group {
                        display: flex;
                        gap: 15px;
                        margin-top: 20px;
                }

                .btn {
                        padding: 12px 25px;
                        border-radius: 10px;
                        font-size: 14px;
                        font-weight: 600;
                        text-decoration: none;
                        transition: all .2s;
                        display: inline-flex;
                        align-items: center;
                        gap: 8px;
                        cursor: pointer;
                        border: none;
                }

                .btn-primary {
                        background: #0D1A63;
                        color: #fff;
                        flex: 1;
                        justify-content: center;
                }

                .btn-primary:hover {
                        background: #1a2a7a;
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(13,26,99,.3);
                }

                .btn-secondary {
                        background: #f3f4f6;
                        color: #666;
                        flex: 1;
                        justify-content: center;
                }

                .btn-secondary:hover {
                        background: #e5e7eb;
                }

                .btn-success {
                        background: #28a745;
                        color: #fff;
                }

                .btn-success:hover {
                        background: #218838;
                }

                /* Required Field */
                .required {
                        color: #dc3545;
                        margin-left: 4px;
                }

                small {
                        color: #999;
                        font-size: 11px;
                        display: block;
                        margin-top: 4px;
                }

                .footer {
                        text-align: center;
                        margin-top: 30px;
                        padding: 20px;
                        color: #999;
                        font-size: 12px;
                }

                /* Creator Info */
                .creator-info {
                        background: #f8fafc;
                        border-radius: 10px;
                        padding: 12px 15px;
                        margin-bottom: 20px;
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        border: 1px solid #e8ecf5;
                }

                .creator-icon {
                        width: 40px;
                        height: 40px;
                        background: #0D1A63;
                        border-radius: 10px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: #fff;
                        font-size: 16px;
                }

                .creator-details {
                        flex: 1;
                }

                .creator-label {
                        font-size: 10px;
                        text-transform: uppercase;
                        color: #666;
                        letter-spacing: 0.5px;
                }

                .creator-name {
                        font-size: 14px;
                        font-weight: 600;
                        color: #0D1A63;
                }

                /* Template Download */
                .template-link {
                        margin-top: 15px;
                        text-align: center;
                }

                .template-link a {
                        color: #0D1A63;
                        text-decoration: none;
                        font-size: 13px;
                }

                .template-link a:hover {
                        text-decoration: underline;
                }

                @media (max-width: 768px) {
                        .form-grid {
                                grid-template-columns: 1fr;
                        }
                        .form-group.full-width {
                                grid-column: span 1;
                        }
                }
        </style>
</head>
<body>
<div class="container">
        <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="page-header">
                <h1>
                        <i class="fas fa-laptop"></i>
                        Virtual Training Management
                </h1>
                <p>Add virtual training participants individually or via Excel import</p>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> Training record added successfully!
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['import'])): ?>
                <?php if (isset($_SESSION['import_success'])): ?>
                <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['import_success']) ?>
                </div>
                <?php unset($_SESSION['import_success']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['import_errors']) && !empty($_SESSION['import_errors'])): ?>
                <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <details>
                                <summary>Import completed with errors (<?= count($_SESSION['import_errors']) ?>)</summary>
                                <ul style="margin-top: 10px; margin-left: 20px;">
                                        <?php foreach ($_SESSION['import_errors'] as $err): ?>
                                        <li><?= htmlspecialchars($err) ?></li>
                                        <?php endforeach; ?>
                                </ul>
                        </details>
                </div>
                <?php unset($_SESSION['import_errors']); ?>
                <?php endif; ?>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('single')">
                        <i class="fas fa-user-plus"></i> Single Entry
                </button>
                <button class="tab-btn" onclick="switchTab('bulk')">
                        <i class="fas fa-file-excel"></i> Bulk Import (Excel)
                </button>
                <button class="tab-btn" onclick="switchTab('list')">
                        <i class="fas fa-list"></i> View Records
                </button>
        </div>

        <!-- Single Entry Tab -->
        <div id="tab-single" class="tab-content active">
                <div class="form-card">
                        <div class="form-header">
                                <h2>
                                        <i class="fas fa-plus-circle"></i>
                                        Add Participant Record
                                </h2>
                        </div>
                        <div class="form-body">
                                <div class="creator-info">
                                        <div class="creator-icon">
                                                <i class="fas fa-user-check"></i>
                                        </div>
                                        <div class="creator-details">
                                                <div class="creator-label">Record will be administered by</div>
                                                <div class="creator-name"><?= htmlspecialchars($administered_by) ?></div>
                                        </div>
                                </div>

                                <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
                                        <div class="form-grid">
                                                <div class="form-group">
                                                        <label><i class="fas fa-user"></i> Participant Name <span class="required">*</span></label>
                                                        <input type="text" name="participant_name" class="form-control" required>
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-venus-mars"></i> Sex</label>
                                                        <div class="radio-group">
                                                                <label class="radio-option">
                                                                        <input type="radio" name="sex" value="Male"> Male
                                                                </label>
                                                                <label class="radio-option">
                                                                        <input type="radio" name="sex" value="Female"> Female
                                                                </label>
                                                                <label class="radio-option">
                                                                        <input type="radio" name="sex" value="Other"> Other
                                                                </label>
                                                        </div>
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-map-marker-alt"></i> County</label>
                                                        <select name="county" class="form-select">
                                                                <option value="">Select County</option>
                                                                <?php foreach ($counties as $county): ?>
                                                                <option value="<?= htmlspecialchars($county) ?>"><?= htmlspecialchars($county) ?></option>
                                                                <?php endforeach; ?>
                                                        </select>
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-briefcase"></i> Cadre</label>
                                                        <select name="cadre_name" class="form-select">
                                                                <option value="">Select Cadre</option>
                                                                <?php foreach ($cadres as $cadre): ?>
                                                                <option value="<?= htmlspecialchars($cadre) ?>"><?= htmlspecialchars($cadre) ?></option>
                                                                <?php endforeach; ?>
                                                        </select>
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-envelope"></i> Email</label>
                                                        <input type="email" name="email" class="form-control" placeholder="participant@example.com">
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-calendar"></i> Training Date <span class="required">*</span></label>
                                                        <input type="date" name="date" class="form-control" required>
                                                </div>

                                                <div class="form-group full-width">
                                                        <label><i class="fas fa-certificate"></i> CME Title <span class="required">*</span></label>
                                                        <input type="text" name="cme_title" class="form-control" placeholder="e.g., Advanced HIV Management, Pharmacovigilance Training" required>
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-wheelchair"></i> Disability Status</label>
                                                        <div class="radio-group">
                                                                <label class="radio-option">
                                                                        <input type="radio" name="disability" value="Yes"> Yes
                                                                </label>
                                                                <label class="radio-option">
                                                                        <input type="radio" name="disability" value="No" checked> No
                                                                </label>
                                                        </div>
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-hospital"></i> Work Station</label>
                                                        <input type="text" name="work_station" class="form-control" placeholder="e.g., Mbagathi Hospital, County HQ">
                                                </div>

                                                <div class="form-group">
                                                        <label><i class="fas fa-building"></i> Department</label>
                                                        <input type="text" name="department" class="form-control" placeholder="e.g., Clinical Services, Pharmacy, Nursing">
                                                </div>
                                        </div>

                                        <div class="btn-group">
                                                <button type="submit" name="save_training" class="btn btn-primary">
                                                        <i class="fas fa-save"></i> Save Record
                                                </button>
                                                <button type="reset" class="btn btn-secondary">
                                                        <i class="fas fa-undo"></i> Reset
                                                </button>
                                        </div>
                                </form>
                        </div>
                </div>
        </div>

        <!-- Bulk Import Tab -->
        <div id="tab-bulk" class="tab-content">
                <div class="form-card">
                        <div class="form-header">
                                <h2>
                                        <i class="fas fa-file-excel"></i>
                                        Bulk Import from Excel
                                </h2>
                        </div>
                        <div class="form-body">
                                <div class="creator-info">
                                        <div class="creator-icon">
                                                <i class="fas fa-user-check"></i>
                                        </div>
                                        <div class="creator-details">
                                                <div class="creator-label">Records will be administered by</div>
                                                <div class="creator-name"><?= htmlspecialchars($administered_by) ?></div>
                                        </div>
                                </div>

                                <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>" enctype="multipart/form-data">
                                        <div class="file-upload" onclick="document.getElementById('excel_file').click()">
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <p>Click to upload Excel file (.xlsx, .xls, or .csv)</p>
                                                <p style="font-size: 11px; color: #999;">Maximum file size: 10MB</p>
                                                <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls,.csv">
                                        </div>
                                        <div id="file-name" style="text-align: center; margin-top: 10px; font-size: 12px; color: #0D1A63;"></div>

                                        <div class="template-link">
                                                <a href="virtual_training_template.xlsx" download>
                                                        <i class="fas fa-download"></i> Download Excel Template
                                                </a>
                                        </div>

                                        <div class="info-box" style="margin-top: 20px; background: #f8fafc; border-left: 3px solid #0D1A63; padding: 15px;">
                                                <p style="font-size: 12px;">
                                                        <i class="fas fa-info-circle"></i>
                                                        <strong>Excel File Format (columns in order):</strong><br>
                                                        Column A: Participant Name (Required)<br>
                                                        Column B: Sex (Male/Female/Other)<br>
                                                        Column C: County<br>
                                                        Column D: Cadre Name<br>
                                                        Column E: Email<br>
                                                        Column F: Date (YYYY-MM-DD) (Required)<br>
                                                        Column G: CME Title (Required)<br>
                                                        Column H: Disability (Yes/No)<br>
                                                        Column I: Work Station<br>
                                                        Column J: Department
                                                </p>
                                        </div>

                                        <div class="btn-group">
                                                <button type="submit" name="import_excel" class="btn btn-success">
                                                        <i class="fas fa-upload"></i> Import Data
                                                </button>
                                        </div>
                                </form>
                        </div>
                </div>
        </div>

        <!-- View Records Tab -->
        <div id="tab-list" class="tab-content">
                <div class="form-card">
                        <div class="form-header">
                                <h2>
                                        <i class="fas fa-list"></i>
                                        Recent Virtual Training Records
                                </h2>
                        </div>
                        <div class="form-body">
                                <div style="overflow-x: auto;">
                                        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                                                <thead>
                                                        <tr>
                                                                <th style="padding: 10px; background: #f8fafc; text-align: left;">Name</th>
                                                                <th style="padding: 10px; background: #f8fafc; text-align: left;">CME Title</th>
                                                                <th style="padding: 10px; background: #f8fafc; text-align: left;">County</th>
                                                                <th style="padding: 10px; background: #f8fafc; text-align: left;">Date</th>
                                                                <th style="padding: 10px; background: #f8fafc; text-align: left;">Added By</th>
                                                        </tr>
                                                </thead>
                                                <tbody>
                                                        <?php
                                                        $records_query = "SELECT participant_name, cme_title, county, date, administered_by
                                                                                            FROM virtual_trainings
                                                                                            ORDER BY created_at DESC
                                                                                            LIMIT 20";
                                                        $records_result = $conn->query($records_query);
                                                        if ($records_result && $records_result->num_rows > 0) {
                                                                while ($row = $records_result->fetch_assoc()) {
                                                                        echo '<tr>
                                                                                        <td style="padding: 10px; border-bottom: 1px solid #e8ecf5;">' . htmlspecialchars($row['participant_name']) . '</td>
                                                                                        <td style="padding: 10px; border-bottom: 1px solid #e8ecf5;">' . htmlspecialchars($row['cme_title']) . '</td>
                                                                                        <td style="padding: 10px; border-bottom: 1px solid #e8ecf5;">' . htmlspecialchars($row['county'] ?? '—') . '</td>
                                                                                        <td style="padding: 10px; border-bottom: 1px solid #e8ecf5;">' . date('d M Y', strtotime($row['date'])) . '</td>
                                                                                        <td style="padding: 10px; border-bottom: 1px solid #e8ecf5;">' . htmlspecialchars($row['administered_by'] ?? '—') . '</td>
                                                                                    </tr>';
                                                                }
                                                        } else {
                                                                echo '<tr><td colspan="5" style="padding: 30px; text-align: center; color: #999;">No records found</td></tr>';
                                                        }
                                                        ?>
                                                </tbody>
                                        </table>
                                </div>
                                <div style="margin-top: 15px; text-align: center;">
                                        <a href="virtual_trainings_list.php" class="btn btn-secondary" style="display: inline-flex;">
                                                <i class="fas fa-arrow-right"></i> View All Records
                                        </a>
                                </div>
                        </div>
                </div>
        </div>

        <div class="footer">
                <i class="fas fa-database"></i> Transition Benchmarking System | Virtual Training Management
        </div>
</div>

<script>
        function switchTab(tabName) {
                // Hide all tabs
                document.querySelectorAll('.tab-content').forEach(tab => {
                        tab.classList.remove('active');
                });

                // Show selected tab
                document.getElementById('tab-' + tabName).classList.add('active');

                // Update active button
                document.querySelectorAll('.tab-btn').forEach(btn => {
                        btn.classList.remove('active');
                });
                event.target.classList.add('active');
        }

        // File name display
        document.getElementById('excel_file').addEventListener('change', function(e) {
                const fileName = e.target.files[0]?.name;
                document.getElementById('file-name').innerHTML = fileName ? 'Selected: ' + fileName : '';
        });
</script>
</body>
</html>