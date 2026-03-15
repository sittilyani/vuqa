<?php
session_start();
include('../includes/config.php');

// Check if user is logged in
if(!isset($_SESSION['user_id'])){
    header('Location: ../login.php');
    exit();
}

// Handle enable/disable actions
if(isset($_GET['action']) && isset($_GET['staff_id'])) {
    $staff_id = (int)$_GET['staff_id'];
    $action = $_GET['action'];

    // Verify staff exists
    $check = mysqli_query($conn, "SELECT staff_id, status FROM county_staff WHERE staff_id = $staff_id");
    if(mysqli_num_rows($check) > 0) {
        $staff = mysqli_fetch_assoc($check);

        if($action == 'enable' && $staff['status'] == 'disabled') {
            $update = mysqli_query($conn, "UPDATE county_staff SET status = 'active' WHERE staff_id = $staff_id");
            if($update) {
                $_SESSION['success_msg'] = "Staff member enabled successfully.";
            } else {
                $_SESSION['error_msg'] = "Error enabling staff: " . mysqli_error($conn);
            }
        } elseif($action == 'disable' && $staff['status'] == 'active') {
            $update = mysqli_query($conn, "UPDATE county_staff SET status = 'disabled' WHERE staff_id = $staff_id");
            if($update) {
                $_SESSION['success_msg'] = "Staff member disabled successfully.";
            } else {
                $_SESSION['error_msg'] = "Error disabling staff: " . mysqli_error($conn);
            }
        } else {
            $_SESSION['error_msg'] = "Invalid action or staff status.";
        }
    } else {
        $_SESSION['error_msg'] = "Staff member not found.";
    }

    // Preserve all query parameters
    $query_params = [];
    if(isset($_GET['page'])) $query_params[] = 'page=' . $_GET['page'];
    if(isset($_GET['search'])) $query_params[] = 'search=' . urlencode($_GET['search']);
    if(isset($_GET['status'])) $query_params[] = 'status=' . urlencode($_GET['status']);

    $redirect_url = 'staffslist.php';
    if(!empty($query_params)) {
        $redirect_url .= '?' . implode('&', $query_params);
    }

    header('Location: ' . $redirect_url);
    exit();
}

// Pagination
$limit = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Messages
$msg = isset($_SESSION['success_msg']) ? $_SESSION['success_msg'] : '';
$error = isset($_SESSION['error_msg']) ? $_SESSION['error_msg'] : '';
unset($_SESSION['success_msg']);
unset($_SESSION['error_msg']);

// Search filter
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

$where_clause = "WHERE 1=1"; // Start with true condition
if($search){
    $where_clause .= " AND (first_name LIKE '%$search%'
                        OR last_name LIKE '%$search%'
                        OR other_name LIKE '%$search%'
                        OR staff_phone LIKE '%$search%'
                        OR email LIKE '%$search%'
                        OR facility_name LIKE '%$search%'
                        OR department_name LIKE '%$search%'
                        OR cadre_name LIKE '%$search%'
                        OR id_number LIKE '%$search%'
                        OR status LIKE '%$search%'
                        OR employment_status LIKE '%$search%')";
}

if($status_filter) {
    // Convert filter value to match database values
    if($status_filter == 'active') {
        $where_clause .= " AND status = 'active'";
    } elseif($status_filter == 'disabled') {
        $where_clause .= " AND status = 'disabled'";
    }
}

// Get total records
$count_query = "SELECT COUNT(*) as total FROM county_staff $where_clause";
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_records / $limit);

// Fetch staff records
$query = "SELECT * FROM county_staff $where_clause ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$result = mysqli_query($conn, $query);

// Get counts for stats
$active_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE status = 'active'"))['count'];
$disabled_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE status = 'disabled'"))['count'];

//Employment status count
$pnp_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE employment_status = 'Permanent and Pensionable'"))['count'];
$internship_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE employment_status = 'Internship'"))['count'];
$uhc_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE employment_status = 'UHC'"))['count'];
$contract_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE employment_status = 'Contract'"))['count'];
$attachment_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM county_staff WHERE employment_status = 'Attachment'"))['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff List</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7fc; padding: 20px; }
        .container { width: 98%; margin: 0 auto; background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.05); }
        h2 { color: #0D1A63; margin-bottom: 25px; font-size: 28px; font-weight: 600; }
        h2 i { margin-right: 10px; }

        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .search-box { flex: 2; min-width: 300px; }
        .search-box input { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; transition: all 0.3s ease; }
        .search-box input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }

        .filter-box { flex: 1; min-width: 200px; }
        .filter-box select { width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px; background: white; cursor: pointer; }
        .filter-box select:focus { outline: none; border-color: #667eea; }

        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 500; transition: all 0.3s ease; }
        .btn-primary { background: #0D1A63; color: #fff; }
        .btn-primary:hover { background: #1a2a7a; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(13,26,99,0.3); }
        .btn-success { background: #28a745; color: #fff; }
        .btn-success:hover { background: #218838; transform: translateY(-2px); }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger { background: #dc3545; color: #fff; }
        .btn-danger:hover { background: #c82333; }
        .btn-info { background: #17a2b8; color: #fff; }
        .btn-info:hover { background: #138496; }
        .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #e0e0e0; }
        th { background: #f8f9fa; color: #0D1A63; font-weight: 600; position: sticky; top: 0; z-index: 10; }
        tr:hover { background: #f5f5f5; }

        .pagination { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 15px; border: 1px solid #e0e0e0; border-radius: 6px; text-decoration: none; color: #333; transition: all 0.3s ease; }
        .pagination a:hover { background: #0D1A63; color: #fff; border-color: #0D1A63; }
        .pagination .active { background: #0D1A63; color: #fff; border-color: #0D1A63; }

        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .photo-thumb { width: 45px; height: 45px; object-fit: cover; border-radius: 50%; cursor: pointer; border: 2px solid #f0f0f0; transition: transform 0.3s ease; }
        .photo-thumb:hover { transform: scale(1.1); }
        .no-photo { width: 45px; height: 45px; background: #e0e0e0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #666; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid #0D1A63; }
        .stat-card h3 { font-size: 28px; color: #0D1A63; margin-bottom: 5px; }
        .stat-card p { color: #666; font-size: 14px; }

        .alert { padding: 15px 20px; margin-bottom: 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .status-active { background: #28a745; color: white; }
        .status-disabled { background: #dc3545; color: white; }

        .btn-icon { padding: 5px; border-radius: 4px; }

        @media (max-width: 768px) {
            .toolbar { flex-direction: column; align-items: stretch; }
            table { font-size: 12px; }
            th, td { padding: 10px; }
            .action-buttons { flex-direction: column; }
            .btn-sm { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fas fa-users"></i> County Staff Management</h2>

        <?php if($msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo $total_records; ?></h3>
                <p><i class="fas fa-users"></i> Total Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $active_count; ?></h3>
                <p><i class="fas fa-check-circle" style="color: #28a745;"></i> Active Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $disabled_count; ?></h3>
                <p><i class="fas fa-ban" style="color: #dc3545;"></i> Disabled Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $pnp_count; ?></h3>
                <p><i class="fas fa-lock" style="color: #dc3545;"></i> PnP Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $internship_count; ?></h3>
                <p><i class="fas fa-prescription" style="color: #dc3545;"></i> Internship Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $uhc_count; ?></h3>
                <p><i class="fas fa-universal-access" style="color: #dc3545;"></i> UHC Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $contract_count; ?></h3>
                <p><i class="fas fa-lock-open" style="color: #dc3545;"></i> Contract Staff</p>
            </div>
            <div class="stat-card">
                <h3><?php echo $attachment_count; ?></h3>
                <p><i class="fas fa-laptop-medical" style="color: #dc3545;"></i> Students Staff</p>
            </div>
        </div>

        <div class="toolbar">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search by name, ID, phone, email, facility, department..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-box">
                <select id="statusFilter">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="disabled" <?php echo $status_filter == 'disabled' ? 'selected' : ''; ?>>Disabled Only</option>
                </select>
            </div>

            <div style="display: flex; gap: 10px;">
                <a href="add_staff.php" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Add New Staff
                </a>
                <a href="export_staff.php?search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export
                </a>
                <a href="staffslist.php" class="btn btn-info">
                    <i class="fas fa-sync-alt"></i> Reset
                </a>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>ID Number</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Facility</th>
                        <th>Department</th>
                        <th>Cadre</th>
                        <th>County</th>
                        <th>Account Status</th>
                        <th>Employee Status</th>
                        <th>Contract Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $counter = $offset + 1;
                    if(mysqli_num_rows($result) > 0):
                        while($row = mysqli_fetch_assoc($result)):
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['first_name'] . ' ' . (!empty($row['other_name']) ? $row['other_name'] . ' ' : '') . $row['last_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['id_number']); ?></td>
                        <td><?php echo htmlspecialchars($row['staff_phone'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['email'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['facility_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['cadre_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['county_name']); ?></td>
                        <td>
                            <?php if($row['status'] == 'active'): ?>
                                <span class="status-badge status-active">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-disabled">
                                    <i class="fas fa-ban"></i> Disabled
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['staff_status'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['employment_status'] ?? ''); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="view_staff.php?staff_id=<?php echo $row['staff_id']; ?>" class="btn btn-info btn-sm" title="Quick View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="update_staff.php?staff_id=<?php echo $row['staff_id']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="employee_profile.php?staff_id=<?php echo $row['staff_id']; ?>" class="btn btn-primary btn-sm" title="Full Profile">
                                    <i class="fas fa-user-circle"></i>
                                </a>
                                <?php if($row['status'] == 'active'): ?>
                                    <a href="?action=disable&staff_id=<?php echo $row['staff_id']; ?>&page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
                                       class="btn btn-danger btn-sm"
                                       title="Disable"
                                       onclick="return confirm('Are you sure you want to disable this staff member? They will no longer be able to access the system.')">
                                        <i class="fas fa-ban"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="?action=enable&staff_id=<?php echo $row['staff_id']; ?>&page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"
                                       class="btn btn-success btn-sm"
                                       title="Enable"
                                       onclick="return confirm('Are you sure you want to enable this staff member? They will regain access to the system.')">
                                        <i class="fas fa-check-circle"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php
                        endwhile;
                    else:
                    ?>
                    <tr>
                        <td colspan="14" style="text-align: center; padding: 40px;">
                            <i class="fas fa-users" style="font-size: 48px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                            No staff members found
                            <?php if($search || $status_filter): ?>
                                <br><a href="staffslist.php" class="btn btn-primary btn-sm" style="margin-top: 10px;">Clear Filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
            <?php endif; ?>

            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <?php if($i == $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-search as you type
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function(){
            clearTimeout(searchTimeout);
            const searchValue = this.value;
            const statusValue = document.getElementById('statusFilter').value;
            searchTimeout = setTimeout(function(){
                let url = '?search=' + encodeURIComponent(searchValue);
                if(statusValue) {
                    url += '&status=' + encodeURIComponent(statusValue);
                }
                window.location.href = url;
            }, 500);
        });

        // Status filter change
        document.getElementById('statusFilter').addEventListener('change', function(){
            const statusValue = this.value;
            const searchValue = document.getElementById('searchInput').value;
            let url = '?';
            if(searchValue) {
                url += 'search=' + encodeURIComponent(searchValue);
            }
            if(statusValue) {
                url += (searchValue ? '&' : '') + 'status=' + encodeURIComponent(statusValue);
            }
            window.location.href = url || 'staffslist.php';
        });

        // Add keyboard shortcut for search (Ctrl+F focuses search)
        document.addEventListener('keydown', function(e) {
            if(e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
        });
    </script>
</body>
</html>