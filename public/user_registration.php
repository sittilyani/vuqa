<?php
session_start();
include('../includes/config.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../public/login.php");
    exit;
}

// Check if user has permission (Admin only)
if ($_SESSION['userrole'] !== 'Admin') {
    $_SESSION['error_message'] = "You don't have permission to add users.";
    header("Location: userslist.php");
    exit;
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = "User Registration";
$staff_data = null;
$id_number = isset($_GET['id_number']) ? trim($_GET['id_number']) : '';

// Fetch available counties for the multiselect
$counties_result = $conn->query("SELECT county_id, county_name FROM counties ORDER BY county_name ASC");
$counties = [];
while ($row = $counties_result->fetch_assoc()) {
    $counties[] = $row;
}


// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid CSRF token";
        header("Location: user_registration.php");
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $userrole = $_POST['userrole'] ?? '';
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Handle multiselect counties - convert array to comma-separated string
    $assigned_counties = isset($_POST['assigned_county']) ? implode(',', $_POST['assigned_county']) : '';

    if (empty($username) || empty($userrole) || empty($first_name) || empty($last_name)) {
        $_SESSION['error_message'] = "Username, Role, and Name fields are required";
        header("Location: user_registration.php");
        exit;
    }

    // Check if username already exists
    $check_stmt = $conn->prepare("SELECT user_id FROM tblusers WHERE username = ?");
    $check_stmt->bind_param('s', $username);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "Username already exists!";
        header("Location: user_registration.php");
        exit;
    }
    $check_stmt->close();

    $default_password = '@lvctvuga';
    $hashed_password = password_hash($default_password, PASSWORD_BCRYPT);
    $created_by = $_SESSION['full_name'] ?? 'Admin';

    // Updated INSERT to include assigned_county
    $sql = "INSERT INTO tblusers (
        username, first_name, last_name, email, password,
        userrole, assigned_county, status, login_attempts,
        account_locked_until, date_created, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', 0, NULL, NOW(), ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssss",
        $username, $first_name, $last_name, $email, $hashed_password,
        $userrole, $assigned_counties, $created_by
    );

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "User account created successfully. Default password is $default_password";
        header("Location: userslist.php");
        exit;
    } else {
        $_SESSION['error_message'] = "Registration failed: " . $stmt->error;
        header("Location: user_registration.php");
        exit;
    }
    $stmt->close();
}

$roles_result = $conn->query("SELECT role FROM userroles ORDER BY role");
$roles = [];
while ($row = $roles_result->fetch_assoc()) { $roles[] = $row['role']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background: #f4f7fc; font-family: sans-serif; padding: 40px; }
        .card { border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-width: 750px; margin: auto; }
        .card-header { background: #0d1a63; color: white; border-radius: 15px 15px 0 0 !important; }
        .btn-primary { background: #0d1a63; border: none; }
        .select2-container--default .select2-selection--multiple { border: 1px solid #ced4da; border-radius: 0.25rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header p-4">
            <h3><i class="fas fa-user-plus"></i> <?php echo $page_title; ?></h3>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo $staff_data['first_name'] ?? ''; ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo $staff_data['last_name'] ?? ''; ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?php echo $staff_data['email'] ?? ''; ?>">
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label>Username</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label>User Role</label>
                        <select name="userrole" class="form-control" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role; ?>"><?php echo $role; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label>Assigned Counties (Multiselect)</label>
                    <select name="assigned_county[]" class="form-control select2" multiple="multiple" data-placeholder="Select one or more counties">
                        <?php foreach ($counties as $county): ?>
                            <option value="<?php echo htmlspecialchars($county['county_name']); ?>">
                                <?php echo htmlspecialchars($county['county_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="alert alert-warning py-2 mt-2">
                    <i class="fas fa-key"></i> Default Password: <strong>@lvctvuga</strong>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <a href="userslist.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary px-5">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                width: '100%'
            });
        });
    </script>
</body>
</html>