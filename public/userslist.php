<?php
session_start();

// Check if the user is not logged in, redirect to login page
if (!isset($_SESSION['user_id'])) {
    header("location: ../public/login.php");
    exit;
}
include '../includes/config.php';

// Handle success/error messages
$success_msg = '';
$error_msg = '';

if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
}

// Get search term
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query with search
$sql = "SELECT * FROM tblusers";
if (!empty($search)) {
    $search = mysqli_real_escape_string($conn, $search);
    $sql .= " WHERE user_id LIKE '%$search%'
              OR username LIKE '%$search%'
              OR first_name LIKE '%$search%'
              OR last_name LIKE '%$search%'
              OR full_name LIKE '%$search%'
              OR userrole LIKE '%$search%'
              OR status LIKE '%$search%'";
}
$sql .= " ORDER BY user_id DESC";

$result = $conn->query($sql);
$users = [];
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}

// Get counts
$total_users = count($users);
$active_users = 0;
$inactive_users = 0;

foreach ($users as $user) {
    if (isset($user['status']) && $user['status'] == 'Active') {
        $active_users++;
    } else {
        $inactive_users++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users List</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background: #f4f7fc;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: #0D1A63;
            color: white;
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
        }

        .header h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .header h2 i {
            margin-right: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .stat-card .number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }

        .stat-card .label {
            color: #666;
            font-size: 14px;
        }

        .action-bar {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-form {
            display: flex;
            gap: 10px;
            flex: 1;
            max-width: 500px;
        }

        .search-form input {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-form input:focus {
            outline: none;
            border-color: #667eea;
        }

        .search-form button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #0D1A63;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .alert {
            padding: 15px 20px;
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

        .table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }

        tbody tr:hover {
            background: #f5f5f5;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #28a745;
            color: white;
        }

        .status-inactive {
            background: #dc3545;
            color: white;
        }

        .action-cell {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 10px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-edit { background: #ffc107; color: #212529; }
        .btn-disable { background: #dc3545; }
        .btn-enable { background: #28a745; }
        .btn-view { background: #17a2b8; }
        .btn-reset { background: #6c757d; }

        @media (max-width: 768px) {
            .action-bar {
                flex-direction: column;
            }

            .search-form {
                max-width: 100%;
            }

            .action-buttons {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-users"></i> User Management</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?php echo $total_users; ?></div>
                    <div class="label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo $active_users; ?></div>
                    <div class="label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?php echo $inactive_users; ?></div>
                    <div class="label">Inactive Users</div>
                </div>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <div class="action-bar">
            <form class="search-form" method="GET" action="">
                <input type="text" name="search" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit"><i class="fas fa-search"></i> Search</button>
                <?php if (!empty($search)): ?>
                    <a href="userslist.php" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>

            <div class="action-buttons">
                <a href="user_registration.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Add User
                </a>
                <button class="btn btn-success" onclick="exportToExcel()">
                    <i class="fas fa-file-excel"></i> Export
                </button>
                <button class="btn btn-info" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                <i class="fas fa-users" style="font-size: 48px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                                No users found
                                <?php if (!empty($search)): ?>
                                    <br><a href="userslist.php" class="btn btn-primary btn-sm">Clear Search</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo htmlspecialchars($user['full_name'] ?? $user['first_name'] . ' ' . $user['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['userrole']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo (isset($user['status']) && $user['status'] == 'Active') ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo (isset($user['status']) && $user['status'] == 'Active') ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($user['date_created'])); ?></td>
                                <td class="action-cell">
                                    <a href="view_user.php?user_id=<?php echo $user['user_id']; ?>" class="action-btn btn-view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="update_user.php?id=<?php echo $user['user_id']; ?>" class="action-btn btn-edit" title="Edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php if ($user['status'] == 'Active'): ?>
                                        <a href="disable_user.php?user_id=<?php echo $user['user_id']; ?>" class="action-btn btn-disable" title="Disable"
                                           onclick="return confirm('Are you sure you want to disable this user?')">
                                            <i class="fas fa-ban"></i> Disable
                                        </a>
                                    <?php else: ?>
                                        <a href="enable_user.php?user_id=<?php echo $user['user_id']; ?>" class="action-btn btn-enable" title="Enable"
                                           onclick="return confirm('Are you sure you want to enable this user?')">
                                            <i class="fas fa-check-circle"></i> Enable
                                        </a>
                                    <?php endif; ?>
                                    <a href="reset_user_password.php?user_id=<?php echo $user['user_id']; ?>" class="action-btn btn-reset" title="Reset Password"
                                       onclick="return confirm('Are you sure you want to reset the password to default (123456)?')">
                                        <i class="fas fa-sync-alt"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function exportToExcel() {
        var table = document.querySelector('table');
        var html = table.outerHTML;
        var uri = 'data:application/vnd.ms-excel,' + encodeURIComponent(html);
        var link = document.createElement('a');
        link.href = uri;
        link.download = 'users_list.xls';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    </script>
</body>
</html>