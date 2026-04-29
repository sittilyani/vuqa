<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/config.php';

// Set timezone to Africa/Nairobi
date_default_timezone_set('Africa/Nairobi');

// Check if user is logged in
if (!isset($_SESSION['full_name'])) {
    header("Location: ../login/login.php");
    exit();
}

// Get user role and info
$userName = $_SESSION['full_name'];
$userRole = $_SESSION['role'] ?? $_SESSION['userrole'] ?? 'User';
$userId = $_SESSION['user_id'] ?? 0;

// Define which roles can access dashboard
$canAccessDashboard = in_array($userRole, ['Admin', 'Supervisor', 'Manager']);

// Get the requested page or set default
$defaultPage = "../public/welcome.php";
$requestedPage = isset($_GET['page']) ? $_GET['page'] : $defaultPage;

// Security: Prevent directory traversal attacks
$requestedPage = str_replace(['\\'], '/', $requestedPage);
$requestedPage = preg_replace('/\.\.\//', '', $requestedPage);
$requestedPage = '../' . $requestedPage;

// Ensure the page exists and is within allowed directories
$allowedPrefixes = ['../public/', '../trainings/', '../meetings/', '../dashboard/', '../staff/', '../reports/', '../login/', '../backup/'];
$isAllowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (strpos($requestedPage, $prefix) === 0) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    $requestedPage = $defaultPage;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vuqa</title>
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../assets/favicon_io/favicon-16x16.png">
    <link rel="manifest" href="../assets/favicon_io/site.webmanifest">
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:#f4f7fc;overflow:hidden}
        .app-wrapper{display:flex;height:100vh;width:100%;overflow:hidden}

        /* Sidebar Styles */
        .sidebar{max-width:280px;background:#2D008A;color:#fff;height:100vh;position:fixed;left:0;top:0;overflow-y:auto;transition:transform 0.3s ease;z-index:1000;box-shadow:4px 0 10px rgba(0,0,0,.1)}
        .sidebar::-webkit-scrollbar{width:6px}
        .sidebar::-webkit-scrollbar-track{background:#AC80EE}
        .sidebar::-webkit-scrollbar-thumb{background:#Ac80EE;border-radius:3px}

        /* Sidebar states */
        .sidebar.show{transform:translateX(0)}

        /* Main content adjustment */
        .main-content{flex:1;margin-left:280px;height:100vh;display:flex;flex-direction:column;background:#f4f7fc;transition:margin-left 0.3s ease}

        .sidebar-header{padding:25px 20px;border-bottom:1px solid #334155;margin-bottom:20px}
        .logo-area{display:flex;align-items:center;gap:12px}
        .logo-icon{width:45px;height:45px;background:#4361ee;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff}
        .logo-text h3{font-size:18px;font-weight:600;margin:0;color:#fff}
        .logo-text p{font-size:12px;color:#94a3b8;margin:3px 0 0}
        .user-info{padding:15px 20px;background:#334155;margin:0 15px 20px;border-radius:12px;display:flex;align-items:center;gap:12px}
        .user-avatar{width:45px;height:45px;background:#4361ee;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff}
        .user-details h4{font-size:14px;font-weight:600;margin:0;color:#fff}
        .user-details span{font-size:11px;color:#94a3b8}
        .nav-menu{padding:0 15px}
        .nav-section{margin-bottom:25px}
        .nav-section-title{font-size:18px; font-weight: 600;text-transform:uppercase;letter-spacing:.5px;color: #04B04B;padding:0 10px;margin-bottom:10px}
        .nav-item{list-style:none;margin-bottom:5px}
        .nav-link{display:flex;align-items:center;gap:12px;padding:12px 15px;color:#cbd5e1;text-decoration:none;border-radius:10px;transition:.3s;font-size:14px;cursor:pointer}
        .nav-link:hover{background:#334155;color:#fff}
        .nav-link.active{background:#4361ee;color:#fff}

        /* Top Navbar */
        .top-navbar{height:70px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;padding:0 25px;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.03)}
        .menu-toggle{display:none;background:none;border:none;font-size:24px;color:#475569;cursor:pointer;transition:color 0.3s;padding:8px;border-radius:8px}
        .menu-toggle:hover{background:#f1f5f9;color:#011F88}
        .menu-toggle i{font-size:24px}
        .page-title{font-size:20px;font-weight:700;color:#011F88;margin:0}
        .top-nav-actions{display:flex;align-items:center;gap:20px}

        /* DateTime Display Styles */
        .datetime-display{display:flex;align-items:center;gap:10px;background:#f1f5f9;padding:8px 15px;border-radius:10px;color:#1e293b}
        .datetime-display i,.datetime-display .time{color:#E41E39}
        .datetime-display .time{font-weight:600;font-size:1.1em;font-family:monospace}
        .datetime-display i{font-size:1.1em}
        .datetime-display .date{font-size:.9em;color:#64748b}

        .notification-btn{background:#f1f5f9;border:none;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#475569;position:relative;cursor:pointer}
        .notification-badge{position:absolute;top:-5px;right:-5px;background:#E41E39;color:#fff;font-size:10px;padding:3px 6px;border-radius:30px;min-width:18px;text-align:center}
        .user-dropdown{display:flex;align-items:center;gap:10px;background:#f1f5f9;padding:8px 15px;border-radius:10px;cursor:pointer}
        .user-dropdown .avatar{width:35px;height:35px;background:#AC80EE;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600}

        /* Iframe Container */
        .iframe-container{flex:1;overflow-y:auto;padding:20px 25px;background:#f4f7fc}
        .iframe-container iframe{width:100%;height:100%;border:none;background:#fff;border-radius:15px;box-shadow:0 4px 12px rgba(0,0,0,.03)}
        .img-vuqa{height: 32px; width: 76px;}

        /* Dropdown Menu */
        .dropdown-menu-custom{position:absolute;right:0;top:100%;margin-top:10px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.1);min-width:200px;display:none;z-index:1000}
        .dropdown-menu-custom.show{display:block}
        .dropdown-item{padding:12px 20px;display:flex;align-items:center;gap:10px;color:#1e293b;text-decoration:none;transition:background 0.3s}
        .dropdown-item:hover{background:#f1f5f9}
        .dropdown-divider{height:1px;background:#e2e8f0;margin:5px 0}

        /* Loading Spinner */
        .iframe-loading{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;display:none}
        .spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:#AC80EE;border-radius:50%;animation:spin 1s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sidebar-overlay.show {
            display: block;
            opacity: 1;
        }

        /* Responsive Design */
        @media(max-width:992px){
            .sidebar {
                transform: translateX(-100%);
                box-shadow: none;
            }
            .sidebar.show {
                transform: translateX(0);
                box-shadow: 4px 0 15px rgba(0,0,0,0.2);
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
            .sidebar-overlay.show {
                display: block;
            }
        }

        @media(max-width:768px){
            .top-navbar{padding:0 15px}
            .page-title{font-size:18px}
            .user-dropdown .info{display:none}
            .iframe-container{padding:15px}
            .datetime-display .date {display: none;}
            .datetime-display {padding: 6px 12px;}
        }

        @media(max-width:576px){
            .top-nav-actions{gap:10px}
            .notification-btn{width:35px;height:35px}
            .user-dropdown{padding:5px 10px}
            .datetime-display {padding: 5px 10px;}
            .datetime-display .time {font-size: 0.9em;}
            .iframe-container {padding: 10px;}
        }
    </style>
</head>
<body>
    <div class="app-wrapper">
        <!-- Sidebar Overlay (for mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="logo-area">
                    <div class="logo-text">
                        <h3>Program Monitoring</h3>
                        <p>Staff monitoring, trainings, meetings, mentorship and participants registration</p>
                    </div>
                </div>
            </div>

            <div class="user-info">

                <div class="user-avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
                <div class="user-details">
                    <h4><?php echo htmlspecialchars($userName); ?></h4>
                    <span><?php echo htmlspecialchars($userRole); ?></span>
                </div>
            </div>

            <div class="nav-menu">
                <!-- Main Navigation -->
                <div class="nav-section">
                    <div class="nav-section-title">Human Resource</div>
                    <?php if ($canAccessDashboard): ?>

                    <div class="nav-item">
                        <a href="../staff/staff_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/chart-bar.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Employee Dashboard
                        </a>
                    </div>

                    <div class="nav-item">
                        <a href="../staff/staffslist.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Staff settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../public/userslist.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/user.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            User Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../settings/index.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/cog.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Admin Settings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../backup/view_backups.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <i class="fa fa-database"></i> View Backup
                        </a>
                    </div>
                    <?php endif; ?>

                </div>

                <!-- integration Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Integration</div>
                    <div class="nav-item">
                        <a href="../integrations/facility_integration_assessment.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            New Facility Integration Assessment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/facility_integration_assessment_list.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Facility Integration Assessment List
                        </a>
                    </div>

                    <div class="nav-item">
                        <a href="../integrations/facility_integration_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Facility Integration Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/facility_integration_comparison_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Facility Integration Comparison Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/facility_integration_workplan.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Facility Integration Workplan
                        </a>
                    </div>

                    <!--start of county integration assessment-->
                    <div class="nav-item">
                        <a href="../integrations/county_integration_assessment.php" target="contentFrame" class="nav-link" onclick="handleNavClick()" style="color: yellow;">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            New County Integration Assessment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/county_integration_assessment_list.php" target="contentFrame" class="nav-link" onclick="handleNavClick()" style="color: yellow;">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            County Integration Assessment List
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/county_integration_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()" style="color: yellow;">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            County Integration Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/county_integration_comparison_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()" style="color: yellow;">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            County Integration Comparison Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../integrations/county_integration_workplan.php" target="contentFrame" class="nav-link" onclick="handleNavClick()" style="color: yellow;">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            County Integration Workplan
                        </a>
                    </div>

                </div>
                <!-- integration Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Transition</div>
                    <div class="nav-item">
                        <a href="../transitions/transition_assessment.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/unlock.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Transition Assessment
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../transitions/transition_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/exchange-alt.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Transition Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../transitions/view_transition_assessment.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/exchange-alt.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Transition List
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../transitions/transition_comparison_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Transition Comparison Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../transitions/transition_workplan.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Transition Workplan
                        </a>
                    </div>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Training</div>
                    <div class="nav-item">
                        <a href="../new-trainings/create_training.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Create training
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../new-trainings/planned_trainings.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Planned trainings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/training_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/tasks.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            TNA Dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/view_training.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Trainings dashboard
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/view_staff_trainings.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <i class="fas fa-chalkboard-teacher"></i> View Completed Trainings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/training_list.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <i class="fas fa-chalkboard-teacher"></i> Course Trainings Lists
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/participant_registration.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Participants Registration
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/training_list.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            View trainings
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../trainings/training_needs_assessment_questionaire.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Training Needs Assessment
                        </a>
                    </div>

                    <div class="nav-item">
                        <a href="../trainings/staff_training_form.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Self staff trainings update
                        </a>
                    </div>
                </div>
                <div class="nav-section">
                    <div class="nav-section-title">Virtual Trainings</div>
                    <div class="nav-item">
                        <a href="../virtual_trainings/virtual_trainings_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Virtual trainings dashboards
                        </a>
                        <a href="../virtual_trainings/virtual_trainings_list.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Virtual trainings Lists
                        </a>
                        <a href="../virtual_trainings/add_virtual_form.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/users.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Add Virtual trainings
                        </a>
                    </div>
                </div>

                <!-- Assets Section -->
                <div class="nav-section">
                    <div class="nav-section-title">Digitization</div>
                    <div class="nav-item">
                        <a href="../digitization/asset_master_register.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/box.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Asset Master Register
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../digitization/digital_innovation_investments.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/box.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Digital Registration
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="../assets-items/assets_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/box.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Assets
                        </a>
                    </div>
                </div>
                <!-- PrEP Section -->
                <div class="nav-section">
                    <div class="nav-section-title">PrEP</div>
                    <div class="nav-item">
                        <a href="../prep/prep_dashboard.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/syringe.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            PrEP dashboard
                        </a>
                        <a href="../prep/stockslist.php" target="contentFrame" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/table-list.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            PrEP stocks
                        </a>

                    </div>
                </div>

                <!-- System Section -->
                <div class="nav-section">
                    <div class="nav-section-title">System</div>
                    <div class="nav-item">
                        <a href="../index.php" class="nav-link" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/sign-out.svg" alt="" width="16" height="16" style="filter:invert(1)">
                            Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="main-content">
            <!-- Top Navbar -->
            <div class="top-navbar">
                <div class="d-flex align-items-center gap-3">
                    <button class="menu-toggle" onclick="toggleSidebar()" id="menuToggleBtn">
                        <i class="bi bi-list"></i>
                    </button>
                    <a href="../includes/layout.php?page=../public/welcome.php">
                    <img src="../assets/images/vuga_logo3_nbg.png" width="378" height="162" class="img-vuqa"alt="">
                    </a>
                    <h1 class="page-title" id="pageTitle">Welcome</h1>
                </div>

                <div class="top-nav-actions">
                    <!-- DateTime Display - Africa/Nairobi -->
                    <div class="datetime-display" id="datetimeDisplay">
                        <i class="bi bi-clock"></i>
                        <span class="time" id="timeDisplay"><?php echo date('H:i:s'); ?></span>
                        <span class="date" id="dateDisplay"><?php echo date('D, M d, Y'); ?></span>
                    </div>

                    <div class="user-dropdown" onclick="toggleUserMenu()">
                        <div class="avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
                        <div class="info">
                            <div class="name"><?php echo htmlspecialchars($userName); ?></div>
                            <div class="role"><?php echo htmlspecialchars($userRole); ?></div>
                        </div>
                        <i class="bi bi-chevron-down"></i>
                    </div>

                    <!-- User Dropdown Menu -->
                    <div class="dropdown-menu-custom" id="userMenu">
                        <a href="../staff/employee_profile.php" target="contentFrame" class="dropdown-item" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/user.svg" alt="" width="16" height="16">
                            Profile
                        </a>
                        <a href="../public/reset_password.php" target="contentFrame" class="dropdown-item" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/shield.svg" alt="" width="16" height="16">
                            Change password
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="../index.php" class="dropdown-item" onclick="handleNavClick()">
                            <img src="../assets/fontawesome/svgs-full/solid/sign-out.svg" alt="" width="16" height="16">
                            Logout
                        </a>
                    </div>
                </div>
            </div>

            <!-- Iframe Content Area -->
            <div class="iframe-container" id="iframeContainer">
                <div class="iframe-loading" id="iframeLoading">
                    <div class="spinner"></div>
                    <p style="margin-top: 10px; color: #64748b;">Loading...</p>
                </div>
                <!-- FIXED: Use the requested page with proper path -->
                <iframe name="contentFrame" src="<?php echo htmlspecialchars($requestedPage); ?>" id="contentFrame" frameborder="0" onload="hideLoading()"></iframe>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle function
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');

            // Change menu icon based on sidebar state
            const menuIcon = document.querySelector('#menuToggleBtn i');
            if (sidebar.classList.contains('show')) {
                menuIcon.classList.remove('bi-list');
                menuIcon.classList.add('bi-x-lg');
            } else {
                menuIcon.classList.remove('bi-x-lg');
                menuIcon.classList.add('bi-list');
            }
        }

        // Close sidebar function
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.remove('show');
            overlay.classList.remove('show');

            // Reset menu icon
            const menuIcon = document.querySelector('#menuToggleBtn i');
            menuIcon.classList.remove('bi-x-lg');
            menuIcon.classList.add('bi-list');
        }

        // Handle navigation click - closes sidebar on mobile after clicking a link
        function handleNavClick() {
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        }

        // Toggle user menu
        function toggleUserMenu() {
            document.getElementById('userMenu').classList.toggle('show');
        }

        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const userDropdown = document.querySelector('.user-dropdown');
            const userMenu = document.getElementById('userMenu');
            if (!userDropdown.contains(event.target) && !userMenu.contains(event.target)) {
                userMenu.classList.remove('show');
            }
        });

        // Hide loading spinner
        function hideLoading() {
            document.getElementById('iframeLoading').style.display = 'none';
        }

        // Show loading when iframe starts loading
        document.getElementById('contentFrame').addEventListener('load', function() {
            hideLoading();
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                closeSidebar();
            }
        });

        // Update page title from iframe
        document.getElementById('contentFrame').addEventListener('load', function() {
            try {
                const iframeTitle = this.contentDocument.title;
                if (iframeTitle) {
                    document.getElementById('pageTitle').textContent = iframeTitle;
                }
            } catch(e) {
                // Cross-origin restrictions may prevent accessing title
            }
        });

        // Africa/Nairobi DateTime Display
        function updateDateTime() {
            const now = new Date();

            // Format time (HH:MM:SS)
            const timeStr = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
                timeZone: 'Africa/Nairobi'
            });

            // Format date (Day, Month DD, YYYY)
            const dateStr = now.toLocaleDateString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                timeZone: 'Africa/Nairobi'
            });

            const timeElement = document.getElementById('timeDisplay');
            const dateElement = document.getElementById('dateDisplay');

            if (timeElement) timeElement.textContent = timeStr;
            if (dateElement) dateElement.textContent = dateStr;
        }

        // Initialize datetime
        document.addEventListener('DOMContentLoaded', function() {
            updateDateTime();
            setInterval(updateDateTime, 1000);
        });

        // Add click event to all nav links for mobile
        document.querySelectorAll('.nav-link[target="contentFrame"]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (window.innerWidth <= 992) {
                    // Small delay to ensure the iframe loads before closing sidebar
                    setTimeout(closeSidebar, 100);
                }
            });
        });

        // Session messaging for iframe
        window.addEventListener('message', function(event) {
            if (event.data === "getSession") {
                const iframe = document.getElementById('contentFrame');
                if (iframe && iframe.contentWindow) {
                    iframe.contentWindow.postMessage({
                        type: 'sessionData',
                        userId: '<?php echo $_SESSION['user_id'] ?? ''; ?>',
                        fullName: '<?php echo $_SESSION['full_name'] ?? ''; ?>',
                        role: '<?php echo $_SESSION['role'] ?? ''; ?>'
                    }, '*');
                }
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>