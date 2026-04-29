<?php
session_start();
include '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../public/login.php");
    exit();
}

$success = '';
$error = '';

// Retrieve user details for the specified user_id
if (isset($_GET['id'])) {
    $user_id = (int)$_GET['id'];

    $sql = "SELECT * FROM tblusers WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        $_SESSION['error_message'] = "User not found!";
        header("Location: userslist.php");
        exit();
    }
} else {
    $_SESSION['error_message'] = "No user ID specified!";
    header("Location: userslist.php");
    exit();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit'])) {
    $user_id = (int)$_POST['user_id'];
    $username = trim($_POST['username']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $sex = $_POST['sex'];
    $mobile = trim($_POST['mobile']);

    // Handle photo upload as BLOB
    $update_photo = false;
    $photo_blob = null;

    // Handle file upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $file_tmp = $_FILES['photo']['tmp_name'];
        $file_type = $_FILES['photo']['type'];
        $file_size = $_FILES['photo']['size'];

        // Validate file type and size
        if (!in_array($file_type, $allowed_types)) {
            $error = "Invalid file type. Only JPEG, PNG, and GIF are allowed.";
        } elseif ($file_size > $max_size) {
            $error = "File size exceeds 5MB limit.";
        } else {
            // Read file content as BLOB
            $photo_blob = file_get_contents($file_tmp);
            $update_photo = true;
        }
    }

    // Handle webcam photo
    if (empty($error) && isset($_POST['webcam_photo']) && !empty($_POST['webcam_photo'])) {
        // Decode base64 image from webcam
        $webcam_data = $_POST['webcam_photo'];
        $webcam_data = str_replace('data:image/jpeg;base64,', '', $webcam_data);
        $webcam_data = str_replace(' ', '+', $webcam_data);
        $image_data = base64_decode($webcam_data);

        if ($image_data !== false) {
            $photo_blob = $image_data;
            $update_photo = true;
        } else {
            $error = "Failed to process webcam photo.";
        }
    }

    // Handle password change if provided
    $hashed_password = null;
    $password_sql = "";

    if (!empty($_POST['new_password'])) {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long!";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        }
    }

    // Only allow Admin to update userrole
    $userrole = $user['userrole']; // Keep existing by default
    if ($_SESSION['userrole'] === 'Admin' && isset($_POST['userrole']) && !empty($_POST['userrole'])) {
        $userrole = $_POST['userrole'];
    }

    // Assigned counties (Admin only): write the CSV `assigned_county` column.
    // Accepts either name="assigned_county[]" (matches user_registration.php)
    // or "assigned_counties[]" (legacy).
    $assigned_county_csv = $user['assigned_county'] ?? '';
    $update_assigned = false;
    if ($_SESSION['userrole'] === 'Admin' && isset($_POST['assigned_counties_present'])) {
        $ac_in = $_POST['assigned_county'] ?? $_POST['assigned_counties'] ?? [];
        if (!is_array($ac_in)) $ac_in = [];
        $ac_in = array_values(array_unique(array_filter(array_map('intval', $ac_in))));
        $assigned_county_csv = implode(',', $ac_in);
        $update_assigned = true;
    }

    // Validate required fields
    if (empty($username) || empty($first_name) || empty($last_name) || empty($email) || empty($sex) || empty($mobile)) {
        $error = "Required fields are missing!";
    }

    if (empty($error)) {
        // Check if username already exists (excluding current user)
        $check_sql = "SELECT user_id FROM tblusers WHERE username = ? AND user_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('si', $username, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Username already exists! Please choose a different username.";
        } else {
            // Build update query dynamically
            if ($update_photo) {
                // Update with photo
                $sql = "UPDATE tblusers SET
                        username = ?,
                        first_name = ?,
                        last_name = ?,
                        email = ?,
                        sex = ?,
                        mobile = ?,
                        photo = ?";

                $types = "ssssssb";
                $params = [$username, $first_name, $last_name, $email, $sex, $mobile, null];

                if ($hashed_password) {
                    $sql .= ", password = ?";
                    $types .= "s";
                    $params[] = $hashed_password;
                }

                if ($_SESSION['userrole'] === 'Admin' && isset($_POST['userrole']) && !empty($_POST['userrole'])) {
                    $sql .= ", userrole = ?";
                    $types .= "s";
                    $params[] = $userrole;
                }

                if ($update_assigned) {
                    $sql .= ", assigned_counties = ?";
                    $types .= "s";
                    $params[] = $assigned_counties_json;
                }

                $sql .= " WHERE user_id = ?";
                $types .= "i";
                $params[] = $user_id;

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);

                // Send the BLOB data using send_long_data
                if ($photo_blob) {
                    $stmt->send_long_data(6, $photo_blob); // Index 6 is the photo column
                }
            } else {
                // Update without photo
                $sql = "UPDATE tblusers SET
                        username = ?,
                        first_name = ?,
                        last_name = ?,
                        email = ?,
                        sex = ?,
                        mobile = ?";

                $types = "ssssss";
                $params = [$username, $first_name, $last_name, $email, $sex, $mobile];

                if ($hashed_password) {
                    $sql .= ", password = ?";
                    $types .= "s";
                    $params[] = $hashed_password;
                }

                if ($_SESSION['userrole'] === 'Admin' && isset($_POST['userrole']) && !empty($_POST['userrole'])) {
                    $sql .= ", userrole = ?";
                    $types .= "s";
                    $params[] = $userrole;
                }

                if ($update_assigned) {
                    $sql .= ", assigned_counties = ?";
                    $types .= "s";
                    $params[] = $assigned_counties_json;
                }

                $sql .= " WHERE user_id = ?";
                $types .= "i";
                $params[] = $user_id;

                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
            }

            if ($stmt->execute()) {
                $success = "User updated successfully!";
                // Refresh user data
                $sql = "SELECT * FROM tblusers WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
            } else {
                $error = "Error updating user: " . $stmt->error;
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Get user roles for dropdown
$roles_result = $conn->query("SELECT role FROM userroles ORDER BY role");
$roles = [];
while ($row = $roles_result->fetch_assoc()) {
    $roles[] = $row['role'];
}

// Get counties for the assigned-counties widget and decode current assignments
$counties_result = $conn->query("SELECT county_id, county_name FROM counties ORDER BY county_name");
$all_counties = [];
if ($counties_result) {
    while ($row = $counties_result->fetch_assoc()) $all_counties[] = $row;
}
$current_assigned = json_decode($user['assigned_counties'] ?? '', true);
if (!is_array($current_assigned)) $current_assigned = [];
$current_assigned = array_map('intval', $current_assigned);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update User - <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            
            min-height: 100vh;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }

        .card-header {
            background: #0d1a63;
            color: white;
            padding: 25px 30px;
        }

        .card-header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }

        .card-header h2 i {
            margin-right: 10px;
        }

        .card-body {
            padding: 30px;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            margin-bottom: 15px;
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

        .form-control, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-control:focus, select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .photo-section {
            grid-column: span 2;
            display: flex;
            gap: 30px;
            align-items: center;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
        }

        .current-photo {
            text-align: center;
        }

        .current-photo img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .current-photo p {
            margin-top: 10px;
            color: #666;
            font-size: 12px;
        }

        .photo-preview {
            text-align: center;
        }

        #photo-preview-img {
            max-width: 200px;
            max-height: 200px;
            border-radius: 10px;
            display: none;
            margin: 0 auto 10px;
        }

        .webcam-container {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }

        #video, #canvas {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            border-radius: 10px;
            display: none;
        }

        #preview {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            border-radius: 10px;
            display: none;
            border: 3px solid #667eea;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #0d1a63;
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .admin-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 10px;
        }

        .photo-hint {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .photo-section {
                flex-direction: column;
            }

            .btn-group {
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
        <div class="card">
            <div class="card-header">
                <h2>
                    <i class="fas fa-user-edit"></i> Update User: <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                    <?php if ($_SESSION['userrole'] === 'Admin'): ?>
                        <span class="admin-badge"><i class="fas fa-crown"></i> Admin Mode</span>
                    <?php endif; ?>
                </h2>
            </div>

            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="update_user.php?id=<?php echo $user['user_id']; ?>" enctype="multipart/form-data">
                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                    <input type="hidden" name="webcam_photo" id="webcam_photo">

                    <div class="form-grid">
                        <!-- Basic Information -->
                        <div class="form-group">
                            <label>Username <i>*</i></label>
                            <input type="text" class="form-control" name="username"
                                   value="<?php echo htmlspecialchars($user['username']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>First Name <i>*</i></label>
                            <input type="text" class="form-control" name="first_name"
                                   value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Last Name <i>*</i></label>
                            <input type="text" class="form-control" name="last_name"
                                   value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Email <i>*</i></label>
                            <input type="email" class="form-control" name="email"
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Sex <i>*</i></label>
                            <select class="form-control" name="sex" required>
                                <option value="">Select Sex</option>
                                <option value="Male" <?php echo ($user['sex'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($user['sex'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Mobile <i>*</i></label>
                            <input type="text" class="form-control" name="mobile"
                                   value="<?php echo htmlspecialchars($user['mobile']); ?>" required>
                        </div>

                        <!-- User Role - Only Admin can edit -->
                        <?php if ($_SESSION['userrole'] === 'Admin'): ?>
                        <div class="form-group full-width">
                            <label>User Role</label>
                            <div style="background: #e7f3ff; padding: 10px; border-radius: 8px; margin-bottom: 10px;">
                                <i class="fas fa-shield-alt"></i> Current Role: <strong><?php echo htmlspecialchars($user['userrole']); ?></strong>
                            </div>
                            <select class="form-control" name="userrole" id="userrole_select">
                                <option value="">Select New Role (Leave empty to keep current)</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>">
                                        <?php echo htmlspecialchars($role); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Assigned Counties (Admin only) -->
                        <div class="form-group full-width" id="assigned_counties_wrap">
                            <label>Assigned Counties</label>
                            <div style="font-size:12px;color:#666;margin-bottom:8px;">
                                Tick the counties this user is allowed to view and assess.
                                Admin / Super Admin roles automatically see every county.
                            </div>
                            <input type="hidden" name="assigned_counties_present" value="1">
                            <div style="display:flex;gap:8px;margin-bottom:8px;">
                                <input type="text" id="county_filter" class="form-control"
                                       placeholder="Filter counties..." style="flex:1;padding:8px 12px;">
                                <button type="button" class="btn btn-secondary" id="btn_select_all_counties"
                                        style="padding:8px 14px;font-size:13px;">Select All</button>
                                <button type="button" class="btn btn-secondary" id="btn_clear_counties"
                                        style="padding:8px 14px;font-size:13px;">Clear</button>
                            </div>
                            <div id="counties_grid"
                                 style="border:2px solid #e0e0e0;border-radius:10px;padding:12px;
                                        max-height:240px;overflow:auto;display:grid;
                                        grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px;background:#fafbfd;">
                                <?php foreach ($all_counties as $c):
                                    $cid = (int)$c['county_id'];
                                    $checked = in_array($cid, $current_assigned, true) ? 'checked' : '';
                                ?>
                                <label class="county-pick"
                                       data-name="<?php echo strtolower(htmlspecialchars($c['county_name'])); ?>"
                                       style="display:flex;align-items:center;gap:6px;padding:6px 8px;
                                              border-radius:6px;background:<?php echo $checked?'#e8edf8':'#fff'; ?>;
                                              border:1px solid #e8eaf6;cursor:pointer;font-size:13px;">
                                    <input type="checkbox" name="assigned_counties[]"
                                           value="<?php echo $cid; ?>" <?php echo $checked; ?>>
                                    <span><?php echo htmlspecialchars($c['county_name']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <div id="ac_count" style="font-size:12px;color:#0d1a63;margin-top:6px;font-weight:600;">
                                <?php echo count($current_assigned); ?> selected
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Password Change Section -->
                        <div class="form-group full-width">
                            <h4 style="color: #0d1a63; margin: 20px 0 10px;"><i class="fas fa-lock"></i> Change Password (Optional)</h4>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                                <div class="form-grid" style="margin-top: 0;">
                                    <div class="form-group">
                                        <label>New Password</label>
                                        <input type="password" class="form-control" name="new_password" placeholder="Leave empty to keep current">
                                    </div>
                                    <div class="form-group">
                                        <label>Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Photo Section - BLOB Storage -->
                        <div class="photo-section">
                            <div class="current-photo">
                                <?php if (!empty($user['photo'])): ?>
                                    <img src="display_photo.php?user_id=<?php echo $user['user_id']; ?>" alt="Current Photo">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/120?text=No+Photo" alt="No Photo">
                                <?php endif; ?>
                                <p>Current Photo</p>
                            </div>

                            <div style="flex: 1;">
                                <!-- New Photo Preview -->
                                <div class="photo-preview">
                                    <img id="photo-preview-img" src="#" alt="New Photo Preview">
                                    <p>New Photo Preview</p>
                                </div>

                                <div class="form-group">
                                    <label>Upload New Photo</label>
                                    <input type="file" class="form-control" id="photo" name="photo" accept="image/jpeg,image/png,image/gif">
                                    <div class="photo-hint">
                                        <i class="fas fa-info-circle"></i> Max size: 5MB. Allowed: JPG, PNG, GIF
                                    </div>
                                </div>

                                <div class="webcam-container" style="margin-top: 10px;">
                                    <label>Or Take a New Photo</label>
                                    <video id="video" width="400" height="300" autoplay></video>
                                    <canvas id="canvas"></canvas>
                                    <img id="preview" src="#" alt="Preview">
                                    <div style="margin-top: 10px;">
                                        <button type="button" id="start-webcam" class="btn btn-info btn-sm">
                                            <i class="fas fa-camera"></i> Start Webcam
                                        </button>
                                        <button type="button" id="capture-btn" class="btn btn-success btn-sm" style="display: none;">
                                            <i class="fas fa-camera-retro"></i> Capture Photo
                                        </button>
                                        <button type="button" id="retake-btn" class="btn btn-warning btn-sm" style="display: none;">
                                            <i class="fas fa-redo"></i> Retake
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="btn-group">
                        <a href="userslist.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Users
                        </a>
                        <button type="submit" name="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // File input preview
    document.getElementById('photo').addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('photo-preview-img').src = e.target.result;
                document.getElementById('photo-preview-img').style.display = 'block';
                document.getElementById('preview').style.display = 'none';
                document.getElementById('video').style.display = 'none';
            }
            reader.readAsDataURL(this.files[0]);
        }
    });

    // Webcam capture functionality
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const preview = document.getElementById('preview');
    const startWebcamBtn = document.getElementById('start-webcam');
    const captureBtn = document.getElementById('capture-btn');
    const retakeBtn = document.getElementById('retake-btn');
    const webcamPhotoInput = document.getElementById('webcam_photo');
    const photoInput = document.getElementById('photo');
    const photoPreview = document.getElementById('photo-preview-img');

    let stream = null;

    startWebcamBtn.addEventListener('click', async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: true });
            video.srcObject = stream;
            video.style.display = 'block';
            captureBtn.style.display = 'inline-block';
            startWebcamBtn.style.display = 'none';
            retakeBtn.style.display = 'none';
            preview.style.display = 'none';
            photoPreview.style.display = 'none';
            photoInput.value = ''; // Clear file input
        } catch (err) {
            alert('Error accessing webcam: ' + err.message);
        }
    });

    captureBtn.addEventListener('click', () => {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        const dataUrl = canvas.toDataURL('image/jpeg');
        preview.src = dataUrl;
        preview.style.display = 'block';
        webcamPhotoInput.value = dataUrl;
        video.style.display = 'none';
        captureBtn.style.display = 'none';
        retakeBtn.style.display = 'inline-block';
        photoInput.value = ''; // Clear file input

        // Stop webcam stream
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }
    });

    retakeBtn.addEventListener('click', () => {
        startWebcamBtn.style.display = 'inline-block';
        retakeBtn.style.display = 'none';
        preview.style.display = 'none';
        webcamPhotoInput.value = '';
    });

    // Clear webcam photo if file input is used
    photoInput.addEventListener('change', () => {
        if (photoInput.files.length > 0) {
            webcamPhotoInput.value = '';
            preview.style.display = 'none';
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                video.style.display = 'none';
                captureBtn.style.display = 'none';
                startWebcamBtn.style.display = 'inline-block';
            }
        }
    });

    // ----- Assigned counties picker -----
    (function () {
        const grid     = document.getElementById('counties_grid');
        const filter   = document.getElementById('county_filter');
        const btnAll   = document.getElementById('btn_select_all_counties');
        const btnNone  = document.getElementById('btn_clear_counties');
        const counter  = document.getElementById('ac_count');
        const wrap     = document.getElementById('assigned_counties_wrap');
        const role     = document.getElementById('userrole_select');
        if (!grid) return;

        function pills() { return grid.querySelectorAll('label.county-pick'); }
        function checked() { return grid.querySelectorAll('input[type="checkbox"]:checked'); }
        function refreshCount() { counter.textContent = checked().length + ' selected'; }
        grid.addEventListener('change', function (e) {
            const pill = e.target.closest('label.county-pick');
            if (pill) pill.style.background = e.target.checked ? '#e8edf8' : '#fff';
            refreshCount();
        });
        filter.addEventListener('input', function () {
            const q = filter.value.trim().toLowerCase();
            pills().forEach(p => {
                p.style.display = (!q || p.dataset.name.indexOf(q) !== -1) ? '' : 'none';
            });
        });
        btnAll.addEventListener('click', function () {
            pills().forEach(p => {
                if (p.style.display === 'none') return;
                const cb = p.querySelector('input[type="checkbox"]');
                if (cb && !cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change', {bubbles:true})); }
            });
        });
        btnNone.addEventListener('click', function () {
            pills().forEach(p => {
                const cb = p.querySelector('input[type="checkbox"]');
                if (cb && cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', {bubbles:true})); }
            });
        });
        // For update_user we hide the picker only if the user picks Admin/Super Admin
        // in the dropdown; we leave it visible by default so admins can change a user's role.
        function syncRoleVisibility() {
            if (!role || !wrap) return;
            const v = role.value || '';
            const isAdmin = (v === 'Admin' || v === 'Super Admin');
            wrap.style.display = isAdmin ? 'none' : '';
        }
        if (role) role.addEventListener('change', syncRoleVisibility);
        // Don't call sync on load — we want the picker visible to allow editing.
        refreshCount();
    })();
    </script>
</body>
</html>