<?php
session_start();
include '../includes/config.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error_message = "Username and password are required.";
    } else {
        $sql = "SELECT user_id, password, userrole, first_name, last_name, full_name, sex, status,
                       photo, login_attempts, account_locked_until, id_number
                FROM tblusers WHERE status = 'Active' AND username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['account_locked_until'] && strtotime($user['account_locked_until']) > time()) {
                $error_message = "Account temporarily locked. Please try again later.";
            }
            elseif ($user['status'] == 'Inactive') {
                $error_message = "Account is deactivated. Please contact administrator.";
            }
            elseif (password_verify($password, $user['password'])) {
                $update_sql = "UPDATE tblusers SET
                              last_login = NOW(),
                              login_attempts = 0,
                              account_locked_until = NULL
                              WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("i", $user['user_id']);
                $update_stmt->execute();
                $update_stmt->close();

                session_regenerate_id(true);

                // Get staff_id from county_staff using id_number
                $staff_id = null;
                if (!empty($user['id_number'])) {
                    $staff_stmt = $conn->prepare("SELECT staff_id FROM county_staff WHERE id_number = ?");
                    $staff_stmt->bind_param('s', $user['id_number']);
                    $staff_stmt->execute();
                    $staff_result = $staff_stmt->get_result();
                    if ($staff_row = $staff_result->fetch_assoc()) {
                        $staff_id = $staff_row['staff_id'];
                    }
                    $staff_stmt->close();
                }

                $_SESSION['user_id']       = $user['user_id'];
                $_SESSION['username']      = $username;
                $_SESSION['userrole']      = $user['userrole'];
                $_SESSION['first_name']    = $user['first_name'];
                $_SESSION['last_name']     = $user['last_name'];
                $_SESSION['full_name']     = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['sex']            = $user['sex'];
                $_SESSION['status']         = $user['status'];
                $_SESSION['photo']          = $user['photo'];
                $_SESSION['role']           = $user['userrole']; // Add this for layout.php compatibility
                $_SESSION['id_number']      = $user['id_number']; // Store id_number in session
                $_SESSION['staff_id']       = $staff_id; // Store staff_id in session
                $_SESSION['last_activity']  = time();

                // FIXED: Redirect to layout with correct path to welcome.php
                header("Location: ../includes/layout.php?page=../public/welcome.php");
                exit();
            } else {
                $new_attempts = $user['login_attempts'] + 1;
                $lock_until = null;

                if ($new_attempts >= 5) {
                    $lock_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                    $error_message = "Too many failed login attempts. Account locked for 30 minutes.";
                } else {
                    $error_message = "Invalid username or password. Attempts: {$new_attempts}/5";
                }

                $update_sql = "UPDATE tblusers SET
                              login_attempts = ?,
                              account_locked_until = ?
                              WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("isi", $new_attempts, $lock_until, $user['user_id']);
                $update_stmt->execute();
                $update_stmt->close();
            }
        } else {
            $error_message = "Invalid username or password.";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vuqa Login</title>
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/favicon_io/favicon-16x16.png">
    <link rel="manifest" href="../assets/favicon_io/site.webmanifest">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ---------- RESET & GLOBAL ---------- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 2rem 1rem 90px 1rem;  /* bottom padding prevents footer overlap */
        }

        /* ---------- MAIN CARD (FULLY RESPONSIVE) ---------- */
        .login-container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(0px);
            padding: 2rem 2rem 2.2rem;
            border-radius: 32px;
            box-shadow: 0 25px 45px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(255,255,255,0.1);
            width: 100%;
            max-width: 30%;
            margin: 1rem auto;
            text-align: center;
            transition: all 0.3s ease;
        }
        /* logo image - fluid & touch-friendly */
        .login-container img {
            width: clamp(85px, 28vw, 130px);
            height: auto;
            margin-bottom: 1rem;
            object-fit: contain;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.05));
        }

        .login-container h2 {
            font-size: clamp(1.6rem, 6vw, 2.2rem);
            font-weight: 700;
            color: #2D008A;
            margin-bottom: 0.25rem;
            letter-spacing: -0.3px;
        }

        .login-container p {
            color: #2D008A;
            font-size: clamp(0.85rem, 3.5vw, 1rem);
            margin-bottom: 1.5rem;
        }

        /* error message styling */
        .error-message {
            background-color: #ffe9e9;
            color: #c7362b;
            padding: 0.75rem 1rem;
            border-radius: 60px;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #e74c3c;
            text-align: left;
            font-weight: 500;
            word-break: break-word;
        }

        /* form groups */
        .form-group {
            margin-bottom: 1.5rem;
            text-align: left;
        }

        .form-group label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #2D008A;
            display: block;
            margin-bottom: 0.4rem;
            letter-spacing: 0.3px;
        }

        .input-icon {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon i {
            position: absolute;
            left: 14px;
            color: #8e9aaf;
            font-size: 1rem;
            pointer-events: none;
        }

        input {
            width: 100%;
            padding: 0.85rem 1rem 0.85rem 2.5rem;
            border: 1.5px solid #e2e8f0;
            font-size: 1rem;
            transition: all 0.2s;
            background: #fff;
            outline: none;
            font-family: inherit;
        }

        input:focus {
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        /* button */
        .btn-submit {
            width: 100%;
            padding: 0.9rem;
            background: #2D008A;
            color: white;
            border: none;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 8px 14px rgba(67, 97, 238, 0.25);
            margin-top: 0.3rem;
        }

        .btn-submit:hover {
            background: #2c3fcf;
            transform: translateY(-2px);
            box-shadow: 0 12px 20px rgba(67, 97, 238, 0.35);
        }

        .btn-submit:active {
            transform: translateY(1px);
        }

        /* register info */
        .register {
            margin-top: 1.8rem;
            padding-top: 0.8rem;
            border-top: 1px solid #edf2f7;
        }

        .register p {
            font-size: 0.85rem;
            color: #2D008A;
            margin-bottom: 0;
        }

        /* ---------- STICKY FOOTER - FULLY RESPONSIVE, TOUCH OPTIMIZED ---------- */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: #2D008A;
            border-top: 1px solid rgba(0, 0, 0, 0.08);
            padding: 0.9rem 1.5rem;
            z-index: 1000;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.03);
            backdrop-filter: blur(0px);
        }

        .footer-content {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem 1rem;
        }

        .footer-text {
            font-size: clamp(0.7rem, 3vw, 0.85rem);
            color: #FFF;
            line-height: 1.4;
            flex: 1;
            text-align: left;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .social-links a {
            color: #4361ee;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f0f4fe;
            text-decoration: none;
        }

        .social-links a:hover {
            transform: translateY(-3px);
            background: #e0e8ff;
            color: #1e2fcf;
        }
        /* responsive inner spacing */
        @media (max-width: 550px) {
            .login-container {
                padding: 1.5rem 1.2rem 1.8rem;
                border-radius: 28px;
                max-width: 94%;
            }
        }

        @media (max-width: 420px) {
            .login-container {
                padding: 1.2rem 1rem 1.5rem;
            }
        }

        /* adjust footer for very small devices */
        @media (max-width: 640px) {
            .footer {
                padding: 0.7rem 1rem;
            }
            .footer-content {
                flex-direction: column;
                text-align: center;
                gap: 0.5rem;
            }
            .footer-text {
                text-align: center;
                order: 2;
            }
            .social-links {
                order: 1;
                justify-content: center;
            }
            .social-links a {
                width: 36px;
                height: 36px;
                font-size: 1.1rem;
            }
        }

        /* prevent body content hidden under footer */
        @media (max-width: 480px) {
            body {
                padding-bottom: 100px;
            }
            .btn-submit {
                padding: 0.8rem;
            }
        }

        /* additional smoothness for larger screens but compact */
        @media (min-width: 1400px) {
            .login-container {
                max-width: 520px;
            }
            .footer-content {
                max-width: 1400px;
            }
        }

        /* accessibility & touch targets */
        button, a, input {
            touch-action: manipulation;
        }

        /* animation */
        .login-container {
            animation: fadeSlideUp 0.5s ease-out;
        }
        @keyframes fadeSlideUp {
            0% {
                opacity: 0;
                transform: translateY(20px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>

    <div class="login-container">
        <img src="../assets/images/vuga_logo3_nbg.png" width="378" height="162" alt="">
        <h4>Welcome Back</h4>
        <p style="color: #666; margin-bottom: 25px;">Please login to your account</p>

        <?php if (!empty($error_message)): ?>
            <div style="color: red; margin-bottom: 15px; font-size: 14px;"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-submit">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>

            <div class="register" style="margin-top: 20px;">
                <p>Not registered Yet? Please contact the administrator</p>
            </div>
        </form>
    </div>

    <footer class="footer">
        <div class="footer-content">
            <div class="footer-text">
                Transition & Integration Monitoring - developed by LVCTHealth
                <?php echo date('Y');?> - &copy; lvcthealth
            </div>

            <div class="social-links">
                <a href="https://web.facebook.com/LVCTHealth/" target="_blank"><i class="fab fa-facebook"></i></a>
                <a href="https://www.youtube.com/user/TheLVCT" target="_blank"><i class="fab fa-youtube"></i></a>
                <a href="https://x.com/LVCTKe" target="_blank"><i class="fab fa-twitter" style="color: blue;"></i></a>
                <a href="https://www.instagram.com/lvct_health/" target="_blank"><i class="fab fa-instagram"></i></a>
                <a href="https://www.linkedin.com/company/lvcthealth/" target="_blank"><i class="fab fa-linkedin"></i></a>
            </div>
        </div>
    </footer>
</body>
</html>