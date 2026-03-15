<?php
session_start();
include '../includes/config.php';
include '../includes/session_check.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../public/login.php");
    exit;
}

$id_number = $_GET['id_number'] ?? '';
$edit_id   = (int)($_GET['id'] ?? 0);

// Fetch qualifications from database
$qualifications = $conn->query("SELECT qualification_id, qualification_name FROM qualifications ORDER BY qualification_name");

// If edit, load record and infer id_number
if ($edit_id) {
    $row = $conn->query("SELECT * FROM employee_academics WHERE academic_id=$edit_id")->fetch_assoc();
    if (!$row) {
        header("Location: county_staff_list.php");
        exit;
    }
    $id_number = $row['id_number'];
}
if (!$id_number) {
    header("Location: county_staff_list.php");
    exit;
}

$staff = $conn->query("SELECT * FROM county_staff WHERE id_number='".mysqli_real_escape_string($conn,$id_number)."'")->fetch_assoc();
if (!$staff) {
    header("Location: county_staff_list.php");
    exit;
}
$full_name = trim($staff['first_name'].' '.$staff['last_name'].(!empty($staff['other_name'])?' '.$staff['other_name']:''));

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_esc = mysqli_real_escape_string($conn, $id_number);
    $fields = ['qualification_name','qualification_type','institution_name',
               'specialization','grade','award_year','start_date','end_date',
               'certificate_number','completion_status','verification_status','remarks'];
    $sets = [];
    foreach ($fields as $f) {
        $val = mysqli_real_escape_string($conn, trim($_POST[$f] ?? ''));
        $sets[] = "$f='$val'";
    }

    // Get qualification name if ID is provided but name is empty
    if (!empty($_POST['qualification_id']) && empty($_POST['qualification_name'])) {
        $qual_id = (int)$_POST['qualification_id'];
        $qual_result = $conn->query("SELECT qualification_name FROM qualifications WHERE qualification_id = $qual_id");
        if ($qual_row = $qual_result->fetch_assoc()) {
            // Update the qualification_name in the sets array
            foreach ($sets as &$set) {
                if (strpos($set, 'qualification_name=') === 0) {
                    $set = "qualification_name='" . mysqli_real_escape_string($conn, $qual_row['qualification_name']) . "'";
                    break;
                }
            }
        }
    }

    $pid = (int)($_POST['edit_id'] ?? 0);
    if ($pid) {
        $conn->query("UPDATE employee_academics SET ".implode(',',$sets)." WHERE academic_id=$pid");
    } else {
        $conn->query("INSERT INTO employee_academics (id_number,".implode(',',array_column(array_map(fn($s)=>explode('=',$s),$sets),0)).") VALUES ('$id_esc',".implode(',',array_column(array_map(fn($s)=>explode('=',$s,2),$sets),1)).")");
    }
    $_SESSION['success_message'] = "Academic record saved.";
    header("Location: employee_profile.php?id_number=".urlencode($id_number)."#academics");
    exit;
}

$v   = fn($f) => htmlspecialchars($row[$f] ?? '');
$sel = fn($f,$o) => (($row[$f] ?? '')===$o)?'selected':'';
$sel_qualification = function($qualification_id) use ($row) {
    return ((int)($row['qualification_id'] ?? 0) === (int)$qualification_id) ? 'selected' : '';
};
$back_tab = 'academics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= $edit_id?'Edit':'Add' ?> Academic – <?= htmlspecialchars($full_name) ?></title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Select2 CSS for better dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f4f7fc;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-page-header {
            background: linear-gradient(135deg, #0D1A63 0%, #1a2a7a 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .fph-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .fph-left h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .fph-left p {
            opacity: 0.9;
            font-size: 14px;
        }

        .profile-back {
            background: white;
            color: #0D1A63;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .profile-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.2);
        }

        .form-card {
            background: white;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .form-card-head {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 2px solid #e0e0e0;
            font-weight: 600;
            color: #0D1A63;
            font-size: 16px;
        }

        .form-card-head i {
            margin-right: 10px;
            color: #667eea;
        }

        .form-card-body {
            padding: 25px;
        }

        .fg {
            display: grid;
            gap: 20px;
        }

        .fg-3 {
            grid-template-columns: repeat(3, 1fr);
        }

        .form-group {
            margin-bottom: 5px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 13px;
        }

        .req {
            color: #ff4d4d;
            font-style: normal;
            margin-left: 3px;
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #0D1A63;
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(13,26,99,0.2);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .info-note {
            background: #e7f3ff;
            border: 1px solid #b8daff;
            color: #004085;
            padding: 12px 15px;
            border-radius: 8px;
            margin: 20px 0 0;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-note i {
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .fg-3 {
                grid-template-columns: 1fr;
            }

            .form-page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .fph-left {
                flex-direction: column;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-page-header">
            <div class="fph-left">
                <i class="fas fa-graduation-cap fa-2x"></i>
                <div>
                    <h1><?= $edit_id ? 'Edit' : 'Add' ?> Academic Qualification</h1>
                    <p><?= htmlspecialchars($full_name) ?> &nbsp;·&nbsp; ID: <?= htmlspecialchars($id_number) ?></p>
                </div>
            </div>
            <a href="employee_profile.php?id_number=<?= urlencode($id_number) ?>#academics" class="profile-back">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>
        </div>

        <form method="POST">
            <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
            <input type="hidden" name="id_number" value="<?= htmlspecialchars($id_number) ?>">

            <div class="form-card">
                <div class="form-card-head">
                    <i class="fas fa-graduation-cap"></i> Qualification Details
                </div>
                <div class="form-card-body">
                    <div class="fg fg-3">
                        <div class="form-group full">
                            <label>Qualification <span class="req">*</span></label>
                            <select name="qualification_id" id="qualification_id" required>
                                <option value="">-- Select Qualification --</option>
                                <?php
                                if ($qualifications && $qualifications->num_rows > 0):
                                    while ($qualification = $qualifications->fetch_assoc()):
                                ?>
                                    <option value="<?= $qualification['qualification_id'] ?>"
                                        <?= $sel_qualification($qualification['qualification_id']) ?>>
                                        <?= htmlspecialchars($qualification['qualification_name']) ?>
                                    </option>
                                <?php
                                    endwhile;
                                else:
                                ?>
                                    <option value="" disabled>No qualifications found</option>
                                <?php endif; ?>
                            </select>
                            <input type="hidden" name="qualification_name" id="qualification_name" value="<?= $v('qualification_name') ?>">
                        </div>

                        <div class="form-group">
                            <label>Qualification Type</label>
                            <select name="qualification_type">
                                <option value="">-- Select Type --</option>
                                <?php foreach(['Certificate','Diploma','Higher Diploma','Degree','Masters','PhD','Post Graduate Diploma','Other'] as $q): ?>
                                    <option value="<?= $q ?>" <?= $sel('qualification_type', $q) ?>><?= $q ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Institution Name <span class="req">*</span></label>
                            <input type="text" name="institution_name" value="<?= $v('institution_name') ?>" required placeholder="University / College / School">
                        </div>

                        <div class="form-group">
                            <label>Specialization</label>
                            <input type="text" name="specialization" value="<?= $v('specialization') ?>" placeholder="e.g. Pediatrics, Public Health">
                        </div>

                        <div class="form-group">
                            <label>Grade / Class</label>
                            <input type="text" name="grade" value="<?= $v('grade') ?>" placeholder="e.g. Second Class Upper, B+">
                        </div>

                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?= $v('start_date') ?>">
                        </div>

                        <div class="form-group">
                            <label>End / Graduation Date</label>
                            <input type="date" name="end_date" value="<?= $v('end_date') ?>">
                        </div>

                        <div class="form-group">
                            <label>Award Year</label>
                            <input type="number" name="award_year" value="<?= $v('award_year') ?>" min="1970" max="<?= date('Y') ?>" placeholder="<?= date('Y') ?>">
                        </div>

                        <div class="form-group">
                            <label>Certificate Number</label>
                            <input type="text" name="certificate_number" value="<?= $v('certificate_number') ?>" placeholder="e.g. CERT-2023-001">
                        </div>

                        <div class="form-group">
                            <label>Completion Status</label>
                            <select name="completion_status">
                                <?php foreach(['Completed','In Progress','Discontinued'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $sel('completion_status', $s) ?: ($s === 'Completed' && !$edit_id ? 'selected' : '') ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Verification Status</label>
                            <select name="verification_status">
                                <?php foreach(['Pending','Verified','Rejected'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $sel('verification_status', $s) ?: ($s === 'Pending' && !$edit_id ? 'selected' : '') ?>><?= $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Remarks</label>
                            <textarea name="remarks" placeholder="Any additional notes..."><?= $v('remarks') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                <span>The qualification name will be automatically populated from the selected qualification.</span>
            </div>

            <div class="form-actions">
                <a href="employee_profile.php?id_number=<?= urlencode($id_number) ?>#academics" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> <?= $edit_id ? 'Update' : 'Save' ?> Academic Record
                </button>
            </div>
        </form>
    </div>

    <!-- jQuery and Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize Select2 for better dropdown experience
            $('#qualification_id').select2({
                placeholder: '-- Select Qualification --',
                allowClear: true,
                width: '100%'
            });

            // Auto-populate qualification_name when qualification is selected
            $('#qualification_id').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                if (selectedOption.val()) {
                    var qualName = selectedOption.text();
                    $('#qualification_name').val(qualName);
                } else {
                    $('#qualification_name').val('');
                }
            });

            // Trigger change on page load if editing
            <?php if ($edit_id && !empty($row['qualification_id'])): ?>
            $('#qualification_id').trigger('change');
            <?php endif; ?>
        });
    </script>
</body>
</html>