<?php
session_start();
include '../includes/config.php';

$msg = "";
$error = "";

// Fetch staff statuses for dropdown
$staff_status_result = mysqli_query($conn, "SELECT staff_status_id, staff_status_name FROM staff_status ORDER BY staff_status_name");

// Fetch employment statuses for dropdown
$employment_status_result = mysqli_query($conn, "SELECT employment_status_id, employment_status_name FROM employment_status ORDER BY employment_status_name");

// Fetch departments for dropdown
$departments = mysqli_query($conn, "SELECT department_name FROM departments ORDER BY department_name");

// Fetch cadres for dropdown
$cadres = mysqli_query($conn, "SELECT cadre_name FROM cadres ORDER BY cadre_name");

// Fetch facilities for dropdown
$facilities = mysqli_query($conn, "SELECT facility_name FROM facilities ORDER BY facility_name");

if (isset($_POST['submit'])) {
    // Get form data - use proper escaping for all fields
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $other_name = mysqli_real_escape_string($conn, $_POST['other_name']);
    $sex = mysqli_real_escape_string($conn, $_POST['sex']);
    $date_of_birth   = mysqli_real_escape_string($conn, $_POST['date_of_birth']);
    $date_of_joining = mysqli_real_escape_string($conn, $_POST['date_of_joining']);
    $staff_phone = mysqli_real_escape_string($conn, $_POST['staff_phone']);
    $id_number = mysqli_real_escape_string($conn, $_POST['id_number']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    // Get the names directly from form (since we're storing names, not IDs)
    $facility_name = mysqli_real_escape_string($conn, $_POST['facility_name']);
    $county_name = mysqli_real_escape_string($conn, $_POST['county_name']);
    $subcounty_name = mysqli_real_escape_string($conn, $_POST['subcounty_name']);
    $level_of_care_name = mysqli_real_escape_string($conn, $_POST['level_of_care_name']);
    $department_name = mysqli_real_escape_string($conn, $_POST['department_name']);
    $cadre_name = mysqli_real_escape_string($conn, $_POST['cadre_name']);

    // Get status names from dropdowns
    $staff_status_name = mysqli_real_escape_string($conn, $_POST['staff_status_name']);
    $employment_status_name = mysqli_real_escape_string($conn, $_POST['employment_status_name']);

    $created_by = $_SESSION['full_name'];

    // Insert query with all fields
    $insert = "INSERT INTO county_staff (
        first_name, last_name, other_name, sex, date_of_birth, date_of_joining, staff_phone, id_number, email,
        facility_name, county_name, subcounty_name, level_of_care_name,
        department_name, cadre_name, status, staff_status, employment_status, created_by
    ) VALUES (
        '$first_name', '$last_name', '$other_name', '$sex', '$date_of_birth', '$date_of_joining', '$staff_phone',
        '$id_number', '$email', '$facility_name',
        '$county_name', '$subcounty_name', '$level_of_care_name',
        '$department_name', '$cadre_name', 'active', '$staff_status_name', '$employment_status_name', '$created_by'
    )";

    if (mysqli_query($conn, $insert)) {
        $msg = "Staff added successfully!";
        // Clear form data after successful submission
        $_POST = array();
    } else {
        $error = "Insert Error: " . mysqli_error($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Staff</title>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Optimized CSS -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #f4f7fc;
        }

        .container {
            max-width: 800px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .header {
            background: #0D1A63;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h2 {
            font-size: 28px;
            font-weight: 600;
        }

        .content {
            padding: 30px;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

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

        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }

        label i {
            color: #ff4d4d;
            font-style: normal;
            margin-left: 3px;
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        input[readonly] {
            background: #f8f9fa;
            cursor: not-allowed;
            color: #495057;
            border-color: #dee2e6;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #0D1A63;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .section-title {
            grid-column: span 2;
            color: #0D1A63;
            font-size: 18px;
            font-weight: 600;
            margin-top: 10px;
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e0e0e0;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width,
            .section-title {
                grid-column: span 1;
            }

            .header h2 {
                font-size: 24px;
            }

            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>➕ Add New Staff Member</h2>
        </div>

        <div class="content">
            <?php if ($msg): ?>
                <div class="alert success"><?php echo $msg; ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>

            <form method="POST" id="staffForm">
                <div class="form-grid">
                    <!-- Personal Information Section -->
                    <div class="section-title">Personal Information</div>

                    <div class="form-group">
                        <label>First Name <i>*</i></label>
                        <input type="text" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Last Name <i>*</i></label>
                        <input type="text" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Other Name</label>
                        <input type="text" name="other_name" value="<?php echo isset($_POST['other_name']) ? htmlspecialchars($_POST['other_name']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label>Sex <i>*</i></label>
                        <select name="sex" required>
                            <option value="">Select Sex</option>
                            <option value="Male" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo (isset($_POST['sex']) && $_POST['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date of Birth <i>*</i></label>
                        <input type="date" name="date_of_birth" id="date_of_birth"
                               value="<?php echo isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : ''; ?>"
                               max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label>Date of Joining <i>*</i></label>
                        <input type="date" name="date_of_joining" id="date_of_joining"
                               value="<?php echo isset($_POST['date_of_joining']) ? htmlspecialchars($_POST['date_of_joining']) : ''; ?>"
                               max="<?php echo date('Y-m-d'); ?>"
                               required>
                        <small id="doj_hint" style="display:block;margin-top:6px;font-size:12px;color:#888;">
                            Enter Date of Birth first — joining date must be at least 18 years after birth and not a future date.
                        </small>
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="staff_phone" value="<?php echo isset($_POST['staff_phone']) ? htmlspecialchars($_POST['staff_phone']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label>ID Number <i>*</i></label>
                        <input type="text" name="id_number" value="<?php echo isset($_POST['id_number']) ? htmlspecialchars($_POST['id_number']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <!-- Facility Information Section -->
                    <div class="section-title">Facility Information</div>

                    <div class="form-group full-width">
                        <label>Facility <i>*</i></label>
                        <select name="facility_name" id="facility" required>
                            <option value="">Select Facility</option>
                            <?php
                            mysqli_data_seek($facilities, 0); // Reset pointer
                            while ($row = mysqli_fetch_assoc($facilities)):
                            ?>
                                <option value="<?php echo htmlspecialchars($row['facility_name']); ?>"
                                    <?php echo (isset($_POST['facility_name']) && $_POST['facility_name'] == $row['facility_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['facility_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>County</label>
                        <input type="text" name="county_name" id="county" value="<?php echo isset($_POST['county_name']) ? htmlspecialchars($_POST['county_name']) : ''; ?>" placeholder="Auto-filled or type manually">
                    </div>

                    <div class="form-group">
                        <label>Subcounty</label>
                        <input type="text" name="subcounty_name" id="subcounty" value="<?php echo isset($_POST['subcounty_name']) ? htmlspecialchars($_POST['subcounty_name']) : ''; ?>" readonly>
                    </div>

                    <div class="form-group full-width">
                        <label>Level of Care</label>
                        <input type="text" name="level_of_care_name" id="level" value="<?php echo isset($_POST['level_of_care_name']) ? htmlspecialchars($_POST['level_of_care_name']) : ''; ?>" readonly>
                    </div>

                    <!-- Job Information Section -->
                    <div class="section-title">Job Information</div>

                    <div class="form-group">
                        <label>Department <i>*</i></label>
                        <select name="department_name" required>
                            <option value="">Select Department</option>
                            <?php
                            mysqli_data_seek($departments, 0); // Reset pointer
                            while ($row = mysqli_fetch_assoc($departments)):
                            ?>
                                <option value="<?php echo htmlspecialchars($row['department_name']); ?>"
                                    <?php echo (isset($_POST['department_name']) && $_POST['department_name'] == $row['department_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['department_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cadre <i>*</i></label>
                        <select name="cadre_name" required>
                            <option value="">Select Cadre</option>
                            <?php
                            mysqli_data_seek($cadres, 0); // Reset pointer
                            while ($row = mysqli_fetch_assoc($cadres)):
                            ?>
                                <option value="<?php echo htmlspecialchars($row['cadre_name']); ?>"
                                    <?php echo (isset($_POST['cadre_name']) && $_POST['cadre_name'] == $row['cadre_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['cadre_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <!-- Status Information Section -->
                    <div class="section-title">Status Information</div>

                    <div class="form-group">
                        <label>Staff Status <i>*</i></label>
                        <select name="staff_status_name" required>
                            <option value="">Select Staff Status</option>
                            <?php
                            mysqli_data_seek($staff_status_result, 0); // Reset pointer
                            while ($row = mysqli_fetch_assoc($staff_status_result)):
                            ?>
                                <option value="<?php echo htmlspecialchars($row['staff_status_name']); ?>"
                                    <?php echo (isset($_POST['staff_status_name']) && $_POST['staff_status_name'] == $row['staff_status_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['staff_status_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Employment Status <i>*</i></label>
                        <select name="employment_status_name" required>
                            <option value="">Select Employment Status</option>
                            <?php
                            mysqli_data_seek($employment_status_result, 0); // Reset pointer
                            while ($row = mysqli_fetch_assoc($employment_status_result)):
                            ?>
                                <option value="<?php echo htmlspecialchars($row['employment_status_name']); ?>"
                                    <?php echo (isset($_POST['employment_status_name']) && $_POST['employment_status_name'] == $row['employment_status_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['employment_status_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" name="submit" class="btn-submit">
                    💾 Save Staff Member
                </button>
            </form>
        </div>
    </div>

    <script>
    $(document).ready(function() {

        // ── Date of Birth → Date of Joining constraint ─────────────────────
        var today = '<?php echo date('Y-m-d'); ?>';

        function updateDojConstraints() {
            var dob = $('#date_of_birth').val();
            if (!dob) {
                $('#date_of_joining').attr('min', '').attr('max', today);
                $('#doj_hint').text('Enter Date of Birth first — joining date must be at least 18 years after birth and not a future date.');
                return;
            }

            // Calculate min joining date = DOB + 18 years
            var dobDate   = new Date(dob);
            var minJoin   = new Date(dobDate);
            minJoin.setFullYear(minJoin.getFullYear() + 18);

            // Format as YYYY-MM-DD
            var minJoinStr = minJoin.toISOString().split('T')[0];

            $('#date_of_joining')
                .attr('min', minJoinStr)
                .attr('max', today);

            // If a joining date was already selected that now violates the rule, clear it
            var currentDoj = $('#date_of_joining').val();
            if (currentDoj && (currentDoj < minJoinStr || currentDoj > today)) {
                $('#date_of_joining').val('');
            }

            // Update hint text
            var minDisplay = minJoin.toLocaleDateString('en-KE', {day:'2-digit', month:'short', year:'numeric'});
            $('#doj_hint').html(
                '<span style="color:#0D1A63;font-weight:600">✓ Earliest joining date: ' + minDisplay + '</span>' +
                ' &nbsp;|&nbsp; Cannot be a future date.'
            );
        }

        // Run on DOB change
        $('#date_of_birth').on('change', function() {
            updateDojConstraints();
        });

        // Validate DOJ on change too
        $('#date_of_joining').on('change', function() {
            var dob = $('#date_of_birth').val();
            var doj = $(this).val();
            if (!dob) {
                alert('Please enter Date of Birth first.');
                $(this).val('');
                return;
            }
            var dobDate = new Date(dob);
            var minJoin = new Date(dobDate);
            minJoin.setFullYear(minJoin.getFullYear() + 18);
            var todayDate = new Date(today);

            if (new Date(doj) < minJoin) {
                alert('Date of Joining must be at least 18 years after Date of Birth.');
                $(this).val('');
            } else if (new Date(doj) > todayDate) {
                alert('Date of Joining cannot be a future date.');
                $(this).val('');
            }
        });

        // On page load, re-apply constraints if DOB was already set (after failed submit)
        if ($('#date_of_birth').val()) {
            updateDojConstraints();
        }

        // ── Facility → County / Subcounty / Level AJAX ─────────────────────
        $('#facility').change(function() {
            var facility_name = $(this).val();

            if (facility_name) {
                $.ajax({
                    url: 'get_facility_details.php',
                    type: 'POST',
                    data: {facility_name: facility_name},
                    dataType: 'json',
                    success: function(data) {
                        if (!data.error) {
                            $('#county').val(data.county_name || '');
                            $('#subcounty').val(data.subcounty_name || '');
                            $('#level').val(data.level_of_care_name || '');
                        } else {
                            alert('Error: ' + data.error);
                            $('#county, #subcounty, #level').val('');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error:', error);
                        console.log('Response:', xhr.responseText);
                        alert('Error fetching facility details. Please check console.');
                        $('#county, #subcounty, #level').val('');
                    }
                });
            } else {
                $('#county, #subcounty, #level').val('');
            }
        });

        // Trigger change if facility was previously selected (after form submission)
        var selectedFacility = $('#facility').val();
        if (selectedFacility) {
            $('#facility').trigger('change');
        }
    });
    </script>
</body>
</html>