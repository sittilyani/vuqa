<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get the staff ID from URL or use logged-in user's ID
$staff_id = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;

// If no staff_id provided, try to get from user session
if ($staff_id == 0 && isset($_SESSION['id_number'])) {
    // Get staff_id from id_number
    $stmt = $conn->prepare("SELECT staff_id FROM county_staff WHERE id_number = ?");
    $stmt->bind_param('s', $_SESSION['id_number']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $staff_id = $row['staff_id'];
    }
    $stmt->close();
}

// If still no staff_id, redirect
if ($staff_id == 0) {
    $_SESSION['error_msg'] = "Staff record not found.";
    header('Location: dashboard.php');
    exit();
}

// Fetch staff details
$stmt = $conn->prepare("SELECT * FROM county_staff WHERE staff_id = ?");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$result = $stmt->get_result();
$staff = $result->fetch_assoc();
$stmt->close();

if (!$staff) {
    $_SESSION['error_msg'] = "Staff record not found.";
    header('Location: dashboard.php');
    exit();
}

// Fetch statutory details
$statutory = null;
$stmt = $conn->prepare("SELECT * FROM employee_statutory WHERE id_number = ?");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
$statutory = $result->fetch_assoc();
$stmt->close();

// Fetch academics
$academics = [];
$stmt = $conn->prepare("SELECT * FROM employee_academics WHERE id_number = ? ORDER BY end_date DESC");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $academics[] = $row;
}
$stmt->close();

// Fetch work experience
$experiences = [];
$stmt = $conn->prepare("SELECT * FROM employee_work_experience WHERE id_number = ? ORDER BY
                        CASE WHEN is_current = 'Yes' THEN 0 ELSE 1 END, end_date DESC");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $experiences[] = $row;
}
$stmt->close();

// Fetch professional registrations
$registrations = [];
$stmt = $conn->prepare("SELECT * FROM employee_professional_registrations WHERE id_number = ? ORDER BY expiry_date DESC");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $registrations[] = $row;
}
$stmt->close();

// Fetch trainings
$trainings = [];
$stmt = $conn->prepare("SELECT * FROM employee_trainings WHERE id_number = ? ORDER BY end_date DESC");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $trainings[] = $row;
}
$stmt->close();

// Fetch languages
$languages = [];
$stmt = $conn->prepare("SELECT * FROM employee_languages WHERE id_number = ? ORDER BY language_name");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $languages[] = $row;
}
$stmt->close();

// Fetch referees
$referees = [];
$stmt = $conn->prepare("SELECT * FROM employee_referees WHERE id_number = ?");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $referees[] = $row;
}
$stmt->close();

// Fetch appraisals
$appraisals = [];
$stmt = $conn->prepare("SELECT * FROM employee_appraisals WHERE id_number = ?");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appraisals[] = $row;
}
$stmt->close();


// Fetch leaves
$leaves = [];
$stmt = $conn->prepare("SELECT * FROM employee_leave WHERE id_number = ?");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $leaves[] = $row;
}
$stmt->close();

// Fetch disciplinary records
$disciplinary = [];
$stmt = $conn->prepare("SELECT * FROM employee_disciplinary WHERE id_number = ? ORDER BY incident_date DESC");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $disciplinary[] = $row;
}
$stmt->close();

// Fetch next of kin
$kin_list = [];
$stmt = $conn->prepare("SELECT * FROM employee_next_of_kin WHERE id_number = ? ORDER BY priority_order");
$stmt->bind_param('s', $staff['id_number']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $kin_list[] = $row;
}
$stmt->close();

$full_name = trim($staff['first_name'] . ' ' . $staff['last_name'] . (!empty($staff['other_name']) ? ' ' . $staff['other_name'] : ''));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Profile - <?php echo htmlspecialchars($full_name); ?></title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #0D1A63;
            --primary-light: #1a2a7a;
            --secondary: #667eea;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }

        body {
            background: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Profile Header */
        .profile-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .profile-title {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .profile-photo {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid rgba(255,255,255,0.3);
            background: white;
        }

        .profile-photo-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            border: 4px solid rgba(255,255,255,0.3);
        }

        .profile-info h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }

        .profile-badges {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .badge-id {
            background: rgba(255,255,255,0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
        }

        .badge-status {
            background: var(--success);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
        }

        /* Navigation Tabs */
        .nav-tabs-wrapper {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .nav-tabs {
            border-bottom: none;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .nav-tabs .nav-link {
            color: #666;
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-tabs .nav-link:hover {
            background: #f0f0f0;
        }

        .nav-tabs .nav-link.active {
            background: var(--primary);
            color: white;
        }

        .nav-tabs .nav-link i {
            margin-right: 8px;
        }

        /* Section Cards */
        .section-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header h3 {
            color: var(--primary);
            font-size: 20px;
            margin: 0;
        }

        .section-header h3 i {
            margin-right: 10px;
            color: var(--secondary);
        }

        .btn-add {
            background: var(--success);
            color: white;
            padding: 8px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-add:hover {
            background: #218838;
            color: white;
            transform: translateY(-2px);
        }

        .btn-edit {
            background: var(--warning);
            color: #212529;
            padding: 6px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.3s ease;
        }

        .btn-edit:hover {
            background: #e0a800;
            color: #212529;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }

        .info-item label {
            font-size: 12px;
            color: #666;
            display: block;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-item .value {
            font-size: 16px;
            font-weight: 500;
            color: #333;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }

        tr:hover {
            background: #f5f5f5;
        }

        /* Status Badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .status-verified {
            background: var(--success);
            color: white;
        }

        .status-pending {
            background: var(--warning);
            color: #212529;
        }

        .status-rejected {
            background: var(--danger);
            color: white;
        }

        .status-current {
            background: var(--info);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 5px;
        }

        .btn-sm {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-sm i {
            font-size: 12px;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .profile-title {
                flex-direction: column;
            }

            .nav-tabs .nav-link {
                width: 100%;
                text-align: left;
            }
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Alert Messages */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Loading Spinner */
        .tab-loading {
            display: none;
            text-align: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-title">
                <?php if (!empty($staff['photo'])): ?>
                    <img src="display_photo.php?staff_id=<?php echo $staff['staff_id']; ?>"
                         alt="Profile Photo" class="profile-photo">
                <?php else: ?>
                    <div class="profile-photo-placeholder">
                        <i class="fas fa-user"></i>
                    </div>
                <?php endif; ?>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($full_name); ?></h1>
                    <div class="profile-badges">
                        <span class="badge-id">
                            <i class="fas fa-id-card"></i> ID: <?php echo htmlspecialchars($staff['id_number']); ?>
                        </span>
                        <span class="badge-status">
                            <i class="fas fa-circle"></i> <?php echo ucfirst($staff['status']); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div>
                <a href="edit_profile_photo.php?staff_id=<?php echo $staff['staff_id']; ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-camera"></i> Update Photo
                </a>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="nav-tabs-wrapper">
            <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="personal-tab" data-toggle="tab" href="#personal" role="tab">
                        <i class="fas fa-user"></i> Personal Info
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="statutory-tab" data-toggle="tab" href="#statutory" role="tab">
                        <i class="fas fa-file-contract"></i> Statutory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="disciplinary-tab" data-toggle="tab" href="#disciplinary" role="tab">
                        <i class="fas fa-gavel"></i> Disciplinary
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="academics-tab" data-toggle="tab" href="#academics" role="tab">
                        <i class="fas fa-graduation-cap"></i> Academics
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="experience-tab" data-toggle="tab" href="#experience" role="tab">
                        <i class="fas fa-briefcase"></i> Work Experience
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="professional-tab" data-toggle="tab" href="#professional" role="tab">
                        <i class="fas fa-certificate"></i> Professional
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="trainings-tab" data-toggle="tab" href="#trainings" role="tab">
                        <i class="fas fa-chalkboard-teacher"></i> Trainings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="languages-tab" data-toggle="tab" href="#languages" role="tab">
                        <i class="fas fa-language"></i> Languages
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="referees-tab" data-toggle="tab" href="#referees" role="tab">
                        <i class="fas fa-address-book"></i> Referees
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="kin-tab" data-toggle="tab" href="#kin" role="tab">
                        <i class="fas fa-users"></i> Next of Kin
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="appraisals-tab" data-toggle="tab" href="#appraisals" role="tab">
                        <i class="fas fa-chart-line"></i> Appraisal
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="leave-tab" data-toggle="tab" href="#leave" role="tab">
                        <i class="fas fa-calendar-minus"></i> Leave
                    </a>
                </li>

            </ul>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Personal Information Tab -->
            <div class="tab-pane fade show active" id="personal" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <a href="view_staff.php?staff_id=<?php echo $staff['staff_id']; ?>" class="btn-add">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>First Name</label>
                            <div class="value"><?php echo htmlspecialchars($staff['first_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Last Name</label>
                            <div class="value"><?php echo htmlspecialchars($staff['last_name']); ?></div>
                        </div>
                        <?php if (!empty($staff['other_name'])): ?>
                        <div class="info-item">
                            <label>Other Name</label>
                            <div class="value"><?php echo htmlspecialchars($staff['other_name']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <label>ID Number</label>
                            <div class="value"><?php echo htmlspecialchars($staff['id_number']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Sex</label>
                            <div class="value"><?php echo htmlspecialchars($staff['sex']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Phone</label>
                            <div class="value"><?php echo htmlspecialchars($staff['staff_phone'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Email</label>
                            <div class="value"><?php echo htmlspecialchars($staff['email'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Facility</label>
                            <div class="value"><?php echo htmlspecialchars($staff['facility_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Department</label>
                            <div class="value"><?php echo htmlspecialchars($staff['department_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Cadre</label>
                            <div class="value"><?php echo htmlspecialchars($staff['cadre_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>County</label>
                            <div class="value"><?php echo htmlspecialchars($staff['county_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <label>Subcounty</label>
                            <div class="value"><?php echo htmlspecialchars($staff['subcounty_name']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Appraisals Tab -->
            <div class="tab-pane fade" id="appraisals" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-chart-line"></i> Performance Appraisals</h3>
                        <a href="add_appraisal.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Appraisal
                        </a>
                    </div>
                    <?php if (!empty($appraisals)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th>Year</th>
                                    <th>Appraisal Date</th>
                                    <th>Supervisor</th>
                                    <th>Rating</th>
                                    <th>Next Appraisal</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appraisals as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['appraisal_period']); ?></td>
                                    <td><?php echo htmlspecialchars($row['appraisal_year']); ?></td>
                                    <td><?php echo $row['appraisal_date'] ? date('d/m/Y', strtotime($row['appraisal_date'])) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($row['supervisor_name'] ?? '—'); ?></td>
                                    <td>
                                        <?php if (!empty($row['overall_rating'])): ?>
                                        <?php
                                        $rating = (float)$row['overall_rating'];
                                        $pct = round($rating / 5 * 100);
                                        $rc = $rating >= 4 ? 'var(--success)' : ($rating >= 3 ? 'var(--info)' : ($rating >= 2 ? 'var(--warning)' : 'var(--danger)'));
                                        ?>
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <div style="width:60px;height:7px;background:#eee;border-radius:4px;overflow:hidden;">
                                                <div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo $rc; ?>;border-radius:4px;"></div>
                                            </div>
                                            <strong style="color:<?php echo $rc; ?>"><?php echo number_format($rating,2); ?></strong>
                                        </div>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td><?php echo !empty($row['next_appraisal_date']) ? date('d/m/Y', strtotime($row['next_appraisal_date'])) : '—'; ?></td>
                                    <td class="action-btns">
                                        <a href="add_appraisal.php?id=<?php echo $row['appraisal_id']; ?>" class="btn-sm" style="background: var(--warning); color: #212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_appraisal.php?id=<?php echo $row['appraisal_id']; ?>" class="btn-sm" style="background: var(--danger); color: white;" onclick="return confirm('Delete this appraisal?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No appraisal records added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="trainings" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-chalkboard-teacher"></i> Trainings & Certifications</h3>
                        <a href="add_training.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Training
                        </a>
                    </div>
                    <?php if (!empty($trainings)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Training Name</th>
                                    <th>Provider</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th>Certificate No.</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($trainings as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['training_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['training_provider']); ?></td>
                                    <td><?php echo $row['training_type']; ?></td>
                                    <td>
                                        <?php
                                        echo date('M Y', strtotime($row['start_date'])) . ' - ' . date('M Y', strtotime($row['end_date']));
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['certificate_number']); ?></td>
                                    <td class="action-btns">
                                        <a href="edit_training.php?id=<?php echo $row['training_id']; ?>" class="btn-sm" style="background: var(--warning); color: #212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if (!empty($row['document_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($row['document_path']); ?>" class="btn-sm" style="background: var(--info); color: white;" target="_blank">
                                            <i class="fas fa-file-pdf"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No trainings added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Languages Tab -->
            <div class="tab-pane fade" id="languages" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-language"></i> Languages</h3>
                        <a href="add_language.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Language
                        </a>
                    </div>
                    <?php if (!empty($languages)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Language</th>
                                    <th>Proficiency</th>
                                    <th>Speaking</th>
                                    <th>Writing</th>
                                    <th>Reading</th>
                                    <th>Certification</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($languages as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['language_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['proficiency']); ?></td>
                                    <td><?php echo htmlspecialchars($row['speaking']); ?></td>
                                    <td><?php echo htmlspecialchars($row['writing']); ?></td>
                                    <td><?php echo htmlspecialchars($row['reading']); ?></td>
                                    <td><?php echo htmlspecialchars($row['certification'] ?? '—'); ?></td>
                                    <td class="action-btns">
                                        <a href="add_language.php?id=<?php echo $row['language_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_language.php?id=<?php echo $row['language_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this language?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No languages added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="referees" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-address-book"></i> Referees</h3>
                        <a href="add_referee.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Referee
                        </a>
                    </div>
                    <?php if (!empty($referees)): ?>
                    <div class="info-grid">
                        <?php foreach ($referees as $row): ?>
                        <div class="info-item">
                            <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                                <strong><?php echo htmlspecialchars($row['referee_name']); ?></strong>
                                <div class="action-btns">
                                    <a href="add_referee.php?id=<?php echo $row['referee_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="delete_referee.php?id=<?php echo $row['referee_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this referee?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                            <p><strong>Title:</strong> <?php echo htmlspecialchars($row['referee_title'] ?? '—'); ?></p>
                            <p><strong>Position:</strong> <?php echo htmlspecialchars($row['referee_position']); ?></p>
                            <p><strong>Organisation:</strong> <?php echo htmlspecialchars($row['referee_organization']); ?></p>
                            <p><strong>Phone:</strong> <?php echo htmlspecialchars($row['referee_phone']); ?></p>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($row['referee_email'] ?? '—'); ?></p>
                            <p><strong>Relationship:</strong> <?php echo htmlspecialchars($row['referee_relationship']); ?></p>
                            <p><strong>Years Known:</strong> <?php echo $row['years_known']; ?></p>
                            <p><strong>Can Contact:</strong> <?php echo htmlspecialchars($row['can_contact']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No referees added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Next of Kin Tab (Multiple) -->
            <div class="tab-pane fade" id="kin" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-users"></i> Next of Kin (Multiple)</h3>
                        <a href="add_kin.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Next of Kin
                        </a>
                    </div>
                    <?php if (!empty($kin_list)): ?>
                    <div class="info-grid">
                        <?php foreach ($kin_list as $row): ?>
                        <div class="info-item">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                <strong><?php echo htmlspecialchars($row['kin_name']); ?></strong>
                                <?php if ($row['is_emergency_contact'] == 'Yes'): ?>
                                    <span class="status-badge" style="background: var(--danger); color: white;">Emergency</span>
                                <?php endif; ?>
                            </div>
                            <p><strong>Relationship:</strong> <?php echo htmlspecialchars($row['kin_relationship']); ?></p>
                            <p><strong>Phone:</strong> <?php echo htmlspecialchars($row['kin_phone']); ?></p>
                            <?php if (!empty($row['kin_alternate_phone'])): ?>
                                <p><strong>Alt Phone:</strong> <?php echo htmlspecialchars($row['kin_alternate_phone']); ?></p>
                            <?php endif; ?>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($row['kin_email'] ?? 'N/A'); ?></p>
                            <p><strong>Address:</strong> <?php echo htmlspecialchars($row['kin_address'] ?? 'N/A'); ?></p>
                            <p><strong>County:</strong> <?php echo htmlspecialchars($row['kin_county'] ?? 'N/A'); ?></p>
                            <div style="margin-top: 10px;">
                                <a href="edit_kin.php?id=<?php echo $row['kin_id']; ?>" class="btn-sm" style="background: var(--warning); color: #212529;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No next of kin added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statutory Tab -->
            <div class="tab-pane fade" id="statutory" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-file-contract"></i> Statutory Details</h3>
                        <a href="edit_statutory.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-edit"></i> <?php echo $statutory ? 'Update' : 'Add'; ?>
                        </a>
                    </div>
                    <?php if ($statutory): ?>
                    <div class="info-grid">
                        <div class="info-item"><label>KRA PIN</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['kra_pin'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>NHIF Number</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nhif_number'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>NSSF Number</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nssf_number'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Huduma Number</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['huduma_number'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Passport Number</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['passport_number'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Birth Certificate</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['birth_cert_number'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Disability</label>
                            <div class="value"><?php echo $statutory['disability'] ?? 'No'; ?></div></div>
                        <?php if (($statutory['disability'] ?? 'No') == 'Yes'): ?>
                        <div class="info-item"><label>Disability Description</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['disability_description'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Disability Cert No.</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['disability_cert_number'] ?? 'N/A'); ?></div></div>
                        <?php endif; ?>
                    </div>
                    <h4 style="margin:25px 0 15px;color:var(--primary);">Next of Kin / Emergency Contact</h4>
                    <div class="info-grid">
                        <?php if (!empty($statutory['nok_name'])): ?>
                        <div class="info-item"><label>NOK Name</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nok_name']); ?></div></div>
                        <div class="info-item"><label>Relationship</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nok_relationship'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Phone</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nok_phone'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Alternate Phone</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nok_alternate_phone'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Email</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nok_email'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Postal Address</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['nok_postal_address'] ?? 'N/A'); ?></div></div>
                        <?php endif; ?>
                        <?php if (!empty($statutory['emergency_contact_name'])): ?>
                        <div class="info-item"><label>Emergency Contact</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['emergency_contact_name']); ?></div></div>
                        <div class="info-item"><label>Emergency Phone</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['emergency_contact_phone'] ?? 'N/A'); ?></div></div>
                        <div class="info-item"><label>Emergency Relationship</label>
                            <div class="value"><?php echo htmlspecialchars($statutory['emergency_contact_relationship'] ?? 'N/A'); ?></div></div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No statutory details added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Disciplinary Tab -->
            <div class="tab-pane fade" id="disciplinary" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-gavel"></i> Disciplinary Records</h3>
                        <a href="add_disciplinary.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Record Case
                        </a>
                    </div>
                    <?php if (!empty($disciplinary)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Case No.</th>
                                    <th>Case Type</th>
                                    <th>Incident Date</th>
                                    <th>Penalty</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($disciplinary as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['case_number'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($row['case_type']); ?></td>
                                    <td><?php echo !empty($row['incident_date']) ? date('d/m/Y', strtotime($row['incident_date'])) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($row['penalty'] ?? '—'); ?></td>
                                    <td>
                                        <?php
                                        $dc = ['Open'=>'var(--danger)','Closed'=>'var(--success)','Under Investigation'=>'var(--warning)','Appealed'=>'var(--info)'];
                                        $dcolor = $dc[$row['status']] ?? '#6c757d';
                                        $dtxt = ($row['status']==='Under Investigation') ? '#212529' : 'white';
                                        ?>
                                        <span class="status-badge" style="background:<?php echo $dcolor;?>;color:<?php echo $dtxt;?>"><?php echo $row['status']; ?></span>
                                    </td>
                                    <td class="action-btns">
                                        <a href="add_disciplinary.php?id=<?php echo $row['disciplinary_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_disciplinary.php?id=<?php echo $row['disciplinary_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this record?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-check-circle" style="color:var(--success)"></i> No disciplinary records on file.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Academics Tab -->
            <div class="tab-pane fade" id="academics" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-graduation-cap"></i> Academic Qualifications</h3>
                        <a href="add_academic.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Academic
                        </a>
                    </div>
                    <?php if (!empty($academics)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Qualification</th>
                                    <th>Institution</th>
                                    <th>Course</th>
                                    <th>Grade</th>
                                    <th>Year</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($academics as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['qualification_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['institution_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['qualification_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['grade']); ?></td>
                                    <td><?php echo $row['award_year']; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($row['verification_status']); ?>">
                                            <?php echo $row['verification_status']; ?>
                                        </span>
                                    </td>
                                    <td class="action-btns">
                                        <a href="add_academic.php?id=<?php echo $row['academic_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_academic.php?id=<?php echo $row['academic_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this record?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No academic records added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Work Experience Tab -->
            <div class="tab-pane fade" id="experience" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-briefcase"></i> Work Experience</h3>
                        <a href="add_experience.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Experience
                        </a>
                    </div>
                    <?php if (!empty($experiences)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Employer</th>
                                    <th>Job Title</th>
                                    <th>Department</th>
                                    <th>Period</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($experiences as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['employer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>
                                        <?php
                                        echo date('M Y', strtotime($row['start_date'])) . '-';
                                        echo $row['is_current'] === 'Yes'
                                            ? 'Present <span class="status-current">Current</span>'
                                            : date('M Y', strtotime($row['end_date']));
                                        ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($row['verification_status']); ?>">
                                            <?php echo $row['verification_status']; ?>
                                        </span>
                                    </td>
                                    <td class="action-btns">
                                        <a href="add_experience.php?id=<?php echo $row['experience_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_experience.php?id=<?php echo $row['experience_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this record?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No work experience added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Professional Registrations Tab -->
            <div class="tab-pane fade" id="professional" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-certificate"></i> Professional Registrations</h3>
                        <a href="add_registration.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Add Registration
                        </a>
                    </div>
                    <?php if (!empty($registrations)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Regulatory Body</th>
                                    <th>Reg. No.</th>
                                    <th>License No.</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrations as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['regulatory_body']); ?></td>
                                    <td><?php echo htmlspecialchars($row['registration_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['license_number']); ?></td>
                                    <td>
                                        <?php echo !empty($row['expiry_date']) ? date('d/m/Y', strtotime($row['expiry_date'])) : '—'; ?>
                                        <?php if (!empty($row['expiry_date']) && strtotime($row['expiry_date']) < time()): ?>
                                            <span class="status-badge" style="background:var(--danger);color:white;margin-left:4px;">Expired</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($row['verification_status']); ?>">
                                            <?php echo $row['verification_status']; ?>
                                        </span>
                                    </td>
                                    <td class="action-btns">
                                        <a href="add_registration.php?id=<?php echo $row['registration_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_registration.php?id=<?php echo $row['registration_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this record?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No professional registrations added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Leave Tab -->
            <div class="tab-pane fade" id="leave" role="tabpanel">
                <div class="section-card">
                    <div class="section-header">
                        <h3><i class="fas fa-calendar-minus"></i> Leave Records</h3>
                        <a href="add_leave.php?id_number=<?php echo urlencode($staff['id_number']); ?>" class="btn-add">
                            <i class="fas fa-plus"></i> Record Leave
                        </a>
                    </div>
                    <?php if (!empty($leaves)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Leave Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days Req.</th>
                                    <th>Days Appr.</th>
                                    <th>Approver</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leaves as $row):
                                    $lc = ['Approved'=>'status-verified','Pending'=>'status-pending','Rejected'=>'status-rejected','Cancelled'=>''];
                                    $lcls = $lc[$row['status']] ?? '';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['leave_type']); ?></td>
                                    <td><?php echo !empty($row['start_date']) ? date('d/m/Y', strtotime($row['start_date'])) : '—'; ?></td>
                                    <td><?php echo !empty($row['end_date'])   ? date('d/m/Y', strtotime($row['end_date']))   : '—'; ?></td>
                                    <td><?php echo $row['days_requested'] ?? '—'; ?></td>
                                    <td><?php echo $row['days_approved']  ?? '—'; ?></td>
                                    <td><?php echo htmlspecialchars($row['approver_name'] ?? '—'); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $lcls; ?>" <?php if(!$lcls) echo 'style="background:#6c757d;color:white;"'; ?>>
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td class="action-btns">
                                        <a href="add_leave.php?id=<?php echo $row['leave_id']; ?>" class="btn-sm" style="background:var(--warning);color:#212529;">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete_leave.php?id=<?php echo $row['leave_id']; ?>" class="btn-sm" style="background:var(--danger);color:white;" onclick="return confirm('Delete this record?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No leave records added yet.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Required Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Handle tab persistence using localStorage
        $(document).ready(function() {
            // Check for saved tab
            var savedTab = localStorage.getItem('activeProfileTab');
            if (savedTab) {
                $('#profileTabs a[href="' + savedTab + '"]').tab('show');
            }

            // Save tab when changed
            $('#profileTabs a').on('shown.bs.tab', function(e) {
                localStorage.setItem('activeProfileTab', $(e.target).attr('href'));
            });

            // Handle hash in URL
            if (window.location.hash) {
                $('#profileTabs a[href="' + window.location.hash + '"]').tab('show');
            }
        });

        // Show loading when switching tabs
        $('#profileTabs a').on('show.bs.tab', function(e) {
            // You can add a loading indicator here if needed
        });
    </script>
</body>
</html>