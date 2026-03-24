<?php
// integration_assessment_list.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Handle DELETE action
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];

    // First delete child records
    mysqli_query($conn, "DELETE FROM integration_assessment_emr_systems WHERE assessment_id = $delete_id");

    // Then delete main record
    if (mysqli_query($conn, "DELETE FROM integration_assessments WHERE assessment_id = $delete_id")) {
        $_SESSION['success_msg'] = 'Assessment deleted successfully!';
    } else {
        $_SESSION['error_msg'] = 'Error deleting assessment: ' . mysqli_error($conn);
    }

    header('Location: integration_assessment_list.php');
    exit();
}

// -- FILTERS -------------------------------------------------------------------
$period      = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';
$county      = isset($_GET['county']) ? mysqli_real_escape_string($conn, $_GET['county']) : '';
$agency      = isset($_GET['agency']) ? mysqli_real_escape_string($conn, $_GET['agency']) : '';
$level_of_care = isset($_GET['level_of_care']) ? mysqli_real_escape_string($conn, $_GET['level_of_care']) : '';
$date_from   = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to     = isset($_GET['date_to']) ? mysqli_real_escape_string($conn, $_GET['date_to']) : '';
$art_site    = isset($_GET['art_site']) ? mysqli_real_escape_string($conn, $_GET['art_site']) : '';
$uses_emr    = isset($_GET['uses_emr']) ? mysqli_real_escape_string($conn, $_GET['uses_emr']) : '';

$leadership = isset($_GET['leadership']) ? mysqli_real_escape_string($conn, $_GET['leadership']) : '';
$data_integration = isset($_GET['data_integration']) ? mysqli_real_escape_string($conn, $_GET['data_integration']) : '';


// Build WHERE clause
$where = "WHERE 1=1";
if ($period) $where .= " AND assessment_period = '$period'";
if ($county) $where .= " AND county_name = '$county'";
if ($agency) $where .= " AND agency = '$agency'";
if ($level_of_care) $where .= " AND level_of_care_name = '$level_of_care'";
if ($date_from) $where .= " AND collection_date >= '$date_from'";
if ($date_to) $where .= " AND collection_date <= '$date_to'";
if ($art_site) $where .= " AND is_art_site = '$art_site'";
if ($uses_emr) $where .= " AND uses_emr = '$uses_emr'";
if ($leadership) $where .= " AND leadership_commitment = '$leadership'";
if ($data_integration) $where .= " AND data_integration_level = '$data_integration'";

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total records for pagination
$total_query = "SELECT COUNT(*) as total FROM integration_assessments $where";
$total_result = mysqli_query($conn, $total_query);
$total_records = mysqli_fetch_assoc($total_result)['total'];
$total_pages = ceil($total_records / $limit);

// Get assessments with pagination
$query = "
    SELECT
        assessment_id,
        assessment_period,
        facility_name,
        mflcode,
        county_name,
        level_of_care_name,
        is_art_site,
        uses_emr,
        supported_by_usdos_ip,
        tx_curr,
        plhiv_enrolled_sha,
        collected_by,
        collection_date,
        created_at
    FROM integration_assessments
    $where
    ORDER BY created_at DESC
    LIMIT $offset, $limit
";
$assessments = mysqli_query($conn, $query);

// Get distinct filter options
$periods = mysqli_query($conn, "SELECT DISTINCT assessment_period FROM integration_assessments WHERE assessment_period != '' ORDER BY assessment_period DESC");
$counties = mysqli_query($conn, "SELECT DISTINCT county_name FROM integration_assessments WHERE county_name != '' ORDER BY county_name");
$agencies = mysqli_query($conn, "SELECT DISTINCT agency FROM integration_assessments WHERE agency != '' ORDER BY agency");
$care_levels = mysqli_query($conn, "SELECT DISTINCT level_of_care_name FROM integration_assessments WHERE level_of_care_name != '' ORDER BY level_of_care_name");

// Success/Error messages
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integration Assessments List</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        .page-header {
            background: linear-gradient(135deg, #0D1A63 0%, #1a3a9e 100%);
            color: #fff;
            padding: 22px 30px;
            border-radius: 14px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 6px 24px rgba(13,26,99,.25);
        }
        .page-header h1 {
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header .hdr-links a {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-left: 8px;
            transition: background .2s;
        }
        .page-header .hdr-links a:hover {
            background: rgba(255,255,255,.28);
        }
        .page-header .hdr-links a.active {
            background: #fff;
            color: #0D1A63;
            font-weight: 600;
        }

        .alert {
            padding: 13px 18px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Filters */
        .filters {
            background: #fff;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 24px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #666;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid #e0e4f0;
            border-radius: 8px;
            font-size: 13px;
            transition: all .2s;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #0D1A63;
            box-shadow: 0 0 0 3px rgba(13,26,99,.1);
        }
        .filter-actions {
            display: flex;
            gap: 8px;
        }
        .btn-filter {
            background: #0D1A63;
            color: #fff;
            border: none;
            padding: 9px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-filter:hover { background: #1a2a7a; }
        .btn-reset {
            background: #f3f4f6;
            color: #666;
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-reset:hover { background: #e5e7eb; }

        /* Table */
        .table-card {
            background: #fff;
            border-radius: 12px;
            padding: 0;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            overflow: hidden;
        }
        .table-responsive {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            text-align: left;
            padding: 14px 12px;
            background: #f8fafc;
            color: #0D1A63;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e0e4f0;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e8ecf5;
            vertical-align: middle;
        }
        tr:hover td {
            background: #f8faff;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
        }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }

        .actions {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-view {
            background: #e8edf8;
            color: #0D1A63;
        }
        .btn-view:hover { background: #d6dff0; }
        .btn-edit {
            background: #fff3cd;
            color: #856404;
        }
        .btn-edit:hover { background: #ffe69c; }
        .btn-delete {
            background: #f8d7da;
            color: #721c24;
            border: none;
            cursor: pointer;
        }
        .btn-delete:hover { background: #f5c6cb; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin: 24px 0;
        }
        .page-item {
            list-style: none;
        }
        .page-link {
            display: block;
            padding: 8px 14px;
            background: #fff;
            border: 1px solid #e0e4f0;
            border-radius: 8px;
            color: #0D1A63;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all .2s;
        }
        .page-link:hover {
            background: #e8edf8;
            border-color: #0D1A63;
        }
        .page-link.active {
            background: #0D1A63;
            color: #fff;
            border-color: #0D1A63;
        }

        .summary-bar {
            background: #f8fafc;
            padding: 12px 22px;
            border-bottom: 1px solid #e0e4f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .summary-count {
            font-weight: 700;
            color: #0D1A63;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1><i class="fas fa-clipboard-list"></i> Integration Assessments</h1>
        <div class="hdr-links">
            <a href="integration_assessment.php"><i class="fas fa-plus"></i> New Assessment</a>
            <a href="integration_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
            <a href="integration_assessment_list.php" class="active"><i class="fas fa-list"></i> List View</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- Filters -->
    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Period</label>
            <select name="period">
                <option value="">All Periods</option>
                <?php while ($p = mysqli_fetch_assoc($periods)): ?>
                <option value="<?= htmlspecialchars($p['assessment_period']) ?>" <?= $period == $p['assessment_period'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['assessment_period']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>County</label>
            <select name="county">
                <option value="">All Counties</option>
                <?php mysqli_data_seek($counties, 0); while ($c = mysqli_fetch_assoc($counties)): ?>
                <option value="<?= htmlspecialchars($c['county_name']) ?>" <?= $county == $c['county_name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['county_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Agency</label>
            <select name="agency">
                <option value="">All Agencies</option>
                <?php mysqli_data_seek($agencies, 0); while ($a = mysqli_fetch_assoc($agencies)): ?>
                <option value="<?= htmlspecialchars($a['agency']) ?>" <?= $agency == $a['agency'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['agency']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Level of Care</label>
            <select name="level_of_care">
                <option value="">All Levels</option>
                <?php mysqli_data_seek($care_levels, 0); while ($l = mysqli_fetch_assoc($care_levels)): ?>
                <option value="<?= htmlspecialchars($l['level_of_care_name']) ?>" <?= $level_of_care == $l['level_of_care_name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($l['level_of_care_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Leadership Commitment</label>
            <select name="leadership">
                <option value="">All</option>
                <option value="High" <?= $leadership == 'High' ? 'selected' : '' ?>>High</option>
                <option value="Moderate" <?= $leadership == 'Moderate' ? 'selected' : '' ?>>Moderate</option>
                <option value="Low" <?= $leadership == 'Low' ? 'selected' : '' ?>>Low</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Data Integration</label>
            <select name="data_integration">
                <option value="">All</option>
                <option value="Fully Integrated" <?= $data_integration == 'Fully Integrated' ? 'selected' : '' ?>>Fully Integrated</option>
                <option value="Partial" <?= $data_integration == 'Partial' ? 'selected' : '' ?>>Partial</option>
                <option value="Fragmented" <?= $data_integration == 'Fragmented' ? 'selected' : '' ?>>Fragmented</option>
            </select>
        </div>
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="filter-group">
            <label>ART Site</label>
            <select name="art_site">
                <option value="">All</option>
                <option value="Yes" <?= $art_site == 'Yes' ? 'selected' : '' ?>>Yes</option>
                <option value="No" <?= $art_site == 'No' ? 'selected' : '' ?>>No</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Uses EMR</label>
            <select name="uses_emr">
                <option value="">All</option>
                <option value="Yes" <?= $uses_emr == 'Yes' ? 'selected' : '' ?>>Yes</option>
                <option value="No" <?= $uses_emr == 'No' ? 'selected' : '' ?>>No</option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
            <a href="integration_assessment_list.php" class="btn-reset"><i class="fas fa-undo-alt"></i> Reset</a>
        </div>
    </form>

    <!-- Table -->
    <div class="table-card">
        <div class="summary-bar">
            <span>Showing <span class="summary-count"><?= min($limit, $total_records) ?></span> of <span class="summary-count"><?= number_format($total_records) ?></span> assessments</span>
            <span><i class="fas fa-download"></i> <a href="#" style="color: #0D1A63;">Export to Excel</a></span>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Period</th>
                        <th>Facility</th>
                        <th>MFL Code</th>
                        <th>County</th>
                        <th>Level of Care</th>
                        <th>ART Site</th>
                        <th>Uses EMR</th>
                        <th>TX_CURR</th>
                        <th>SHA Enrolled</th>
                        <th>Collected By</th>
                        <th>Collection Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($assessments) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($assessments)): ?>
                        <tr>
                            <td><strong>#<?= $row['assessment_id'] ?></strong></td>
                            <td><?= htmlspecialchars($row['assessment_period'] ?? '�') ?></td>
                            <td><?= htmlspecialchars($row['facility_name'] ?? '�') ?></td>
                            <td><?= htmlspecialchars($row['mflcode'] ?? '�') ?></td>
                            <td><?= htmlspecialchars($row['county_name'] ?? '�') ?></td>
                            <td><?= htmlspecialchars($row['level_of_care_name'] ?? '�') ?></td>
                            <td>
                                <span class="badge <?= $row['is_art_site'] == 'Yes' ? 'badge-success' : 'badge-danger' ?>">
                                    <?= $row['is_art_site'] ?? '�' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $row['uses_emr'] == 'Yes' ? 'badge-success' : ($row['uses_emr'] == 'No' ? 'badge-warning' : 'badge-info') ?>">
                                    <?= $row['uses_emr'] ?? '�' ?>
                                </span>
                            </td>
                            <td><?= number_format($row['tx_curr'] ?? 0) ?></td>
                            <td><?= number_format($row['plhiv_enrolled_sha'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($row['collected_by'] ?? '�') ?></td>
                            <td><?= $row['collection_date'] ? date('d M Y', strtotime($row['collection_date'])) : '�' ?></td>
                            <td class="actions">
                                <a href="view_integration_assessment.php?id=<?= $row['assessment_id'] ?>" class="btn-icon btn-view" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit_integration_assessment.php?id=<?= $row['assessment_id'] ?>" class="btn-icon btn-edit" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?delete=<?= $row['assessment_id'] ?>" class="btn-icon btn-delete" title="Delete" onclick="return confirm('Are you sure you want to delete this assessment? This action cannot be undone.')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13" style="text-align: center; padding: 40px;">
                                <i class="fas fa-folder-open" style="font-size: 40px; color: #ccc; margin-bottom: 10px; display: block;"></i>
                                No assessments found matching your criteria
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>

        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>