<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');
include('../includes/county_access.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Handle filters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where = "WHERE 1=1";
if ($search) {
    $where .= " AND (c.county_name LIKE '%$search%' OR cca.assessment_period LIKE '%$search%')";
}
if ($status_filter === 'completed') {
    $where .= " AND cca.is_completed = 1";
} elseif ($status_filter === 'incomplete') {
    $where .= " AND cca.is_completed = 0";
}
// Restrict the list to assigned counties for non-admins
$where .= cf_county_filter_sql('cca.county_id');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$total_query = "SELECT COUNT(*) as total FROM county_integration_assessments cca JOIN counties c ON cca.county_id = c.county_id $where";
$total_result = $conn->query($total_query);
$total_records = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

$query = "
    SELECT cca.*, c.county_name, c.county_code,
           (SELECT COUNT(*) FROM county_integration_sections WHERE assessment_id = cca.assessment_id) as total_sections,
           (SELECT SUM(is_completed) FROM county_integration_sections WHERE assessment_id = cca.assessment_id) as completed_sections
    FROM county_integration_assessments cca
    JOIN counties c ON cca.county_id = c.county_id
    $where
    ORDER BY cca.created_at DESC
    LIMIT $offset, $limit
";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>County Integration Assessments - List</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
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
        }
        .page-header h1 { font-size: 1.6rem; display: flex; align-items: center; gap: 10px; }
        .page-header .hdr-links a {
            color: #fff;
            background: rgba(255,255,255,.15);
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            margin-left: 8px;
        }

        .filters {
            background: #fff;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 11px; font-weight: 700; color: #666; margin-bottom: 5px; text-transform: uppercase; }
        .filter-group input, .filter-group select { width: 100%; padding: 10px 12px; border: 2px solid #e0e4f0; border-radius: 8px; }
        .btn-filter { background: #0D1A63; color: #fff; border: none; padding: 10px 24px; border-radius: 8px; cursor: pointer; }
        .btn-reset { background: #f3f4f6; color: #666; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; text-decoration: none; }
        .btn-new { background: #28a745; color: #fff; border: none; padding: 10px 24px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }

        .table-card { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 14px rgba(0,0,0,.07); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 14px 12px; background: #f8fafc; color: #0D1A63; font-size: 11px; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #e8ecf5; }
        tr:hover td { background: #f8faff; }

        .badge-completed { background: #d4edda; color: #155724; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-incomplete { background: #f8d7da; color: #721c24; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .progress-bar { width: 100px; height: 6px; background: #e0e4f0; border-radius: 10px; overflow: hidden; }
        .progress-fill { height: 100%; background: #28a745; border-radius: 10px; }

        .btn-action { padding: 5px 10px; border-radius: 6px; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        .btn-view { background: #e8edf8; color: #0D1A63; }
        .btn-edit { background: #fff3cd; color: #856404; }

        .pagination { display: flex; justify-content: center; gap: 6px; margin: 24px 0; }
        .page-link { padding: 8px 14px; background: #fff; border: 1px solid #e0e4f0; border-radius: 8px; text-decoration: none; color: #0D1A63; }
        .page-link.active { background: #0D1A63; color: #fff; }

        .back-link { display: inline-flex; align-items: center; gap: 6px; background: #fff; padding: 8px 16px; border-radius: 8px; text-decoration: none; color: #0D1A63; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
    <a href="index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

    <div class="page-header">
        <h1><i class="fas fa-list"></i> County Integration Assessments</h1>
        <div class="hdr-links">
            <a href="county_integration_assessment.php" class="btn-new"><i class="fas fa-plus"></i> New Assessment</a>
        </div>
    </div>

    <form method="GET" class="filters">
        <div class="filter-group"><label>Search</label><input type="text" name="search" placeholder="County, Period" value="<?= htmlspecialchars($search) ?>"></div>
        <div class="filter-group"><label>Status</label><select name="status"><option value="">All</option><option value="completed" <?= $status_filter=='completed'?'selected':'' ?>>Completed</option><option value="incomplete" <?= $status_filter=='incomplete'?'selected':'' ?>>Incomplete</option></select></div>
        <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
        <a href="county_integration_assessment_list.php" class="btn-reset"><i class="fas fa-times"></i> Clear</a>
    </form>

    <div class="table-card">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>ID</th><th>County</th><th>Period</th><th>Progress</th><th>Status</th><th>Completed By</th><th>Completed At</th><th>Created</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()):
                        $progress = $row['total_sections'] > 0 ? round(($row['completed_sections'] / $row['total_sections']) * 100) : 0;
                    ?>
                    <tr>
                        <td><?= $row['assessment_id'] ?></td>
                        <td><strong><?= htmlspecialchars($row['county_name']) ?></strong> (<?= $row['county_code'] ?>)</td>
                        <td><?= htmlspecialchars($row['assessment_period']) ?></td>
                        <td>
                            <div class="progress-bar"><div class="progress-fill" style="width: <?= $progress ?>%"></div></div>
                            <span style="font-size: 11px;"><?= $row['completed_sections'] ?>/<?= $row['total_sections'] ?> sections</span>
                         </td>
                        <td>
                            <span class="<?= $row['is_completed'] ? 'badge-completed' : 'badge-incomplete' ?>">
                                <?= $row['is_completed'] ? '<i class="fas fa-check-circle"></i> Completed' : '<i class="fas fa-clock"></i> In Progress' ?>
                            </span>
                         </td>
                        <td><?= htmlspecialchars($row['completed_by'] ?? '�') ?></td>
                        <td><?= $row['completed_at'] ? date('d M Y', strtotime($row['completed_at'])) : '�' ?></td>
                        <td><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                        <td>
                            <a href="county_integration_assessment.php?county_id=<?= $row['county_id'] ?>&period=<?= urlencode($row['assessment_period']) ?>" class="btn-action btn-edit"><i class="fas fa-edit"></i> Edit</a>
                            <a href="view_county_integration_assessment.php?id=<?= $row['assessment_id'] ?>" class="btn-action btn-view"><i class="fas fa-eye"></i> View</a>
                         </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="9" style="text-align: center; padding: 40px;">No assessments found. <a href="county_integration_assessment.php">Start a new assessment</a></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if($total_pages > 1): ?>
    <div class="pagination">
        <?php for($i=1; $i<=$total_pages; $i++): ?>
        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>" class="page-link <?= $i==$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>