<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Handle filters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$county_filter = isset($_GET['county']) ? mysqli_real_escape_string($conn, $_GET['county']) : '';
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? mysqli_real_escape_string($conn, $_GET['date_to']) : '';

$where = "WHERE 1=1";
if ($search) {
    $where .= " AND (participant_name LIKE '%$search%' OR cme_title LIKE '%$search%' OR email LIKE '%$search%')";
}
if ($county_filter) {
    $where .= " AND county = '$county_filter'";
}
if ($date_from) {
    $where .= " AND date >= '$date_from'";
}
if ($date_to) {
    $where .= " AND date <= '$date_to'";
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 30;
$offset = ($page - 1) * $limit;

$total_query = "SELECT COUNT(*) as total FROM virtual_trainings $where";
$total_result = $conn->query($total_query);
$total_records = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

$query = "SELECT * FROM virtual_trainings $where ORDER BY created_at DESC LIMIT $offset, $limit";
$result = $conn->query($query);

// Get counties for filter
$counties = [];
$county_result = $conn->query("SELECT DISTINCT county FROM virtual_trainings WHERE county IS NOT NULL AND county != '' ORDER BY county");
if ($county_result) {
    while ($row = $county_result->fetch_assoc()) {
        $counties[] = $row['county'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Virtual Training Records - Transition Tracker</title>
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

        .table-card { background: #fff; border-radius: 14px; padding: 0; overflow: hidden; box-shadow: 0 2px 14px rgba(0,0,0,.07); }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; padding: 14px 12px; background: #f8fafc; color: #0D1A63; font-size: 11px; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #e8ecf5; }
        tr:hover td { background: #f8faff; }

        .pagination { display: flex; justify-content: center; gap: 6px; margin: 24px 0; }
        .page-link { padding: 8px 14px; background: #fff; border: 1px solid #e0e4f0; border-radius: 8px; text-decoration: none; color: #0D1A63; }
        .page-link.active { background: #0D1A63; color: #fff; }

        .badge { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .badge-male { background: #d1ecf1; color: #0c5460; }
        .badge-female { background: #f8d7da; color: #721c24; }

        .summary-bar { background: #f8fafc; padding: 12px 22px; border-bottom: 1px solid #e0e4f0; display: flex; justify-content: space-between; }

        .back-link { display: inline-flex; align-items: center; gap: 6px; background: #fff; padding: 8px 16px; border-radius: 8px; text-decoration: none; color: #0D1A63; font-size: 13px; font-weight: 600; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
    <a href="add_virtual_training.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Training Form</a>

    <div class="page-header">
        <h1><i class="fas fa-list"></i> Virtual Training Records</h1>
        <a href="add_virtual_training.php" style="color:#fff; background:rgba(255,255,255,.2); padding:8px 16px; border-radius:8px; text-decoration:none;">
            <i class="fas fa-plus"></i> Add New
        </a>
    </div>

    <form method="GET" class="filters">
        <div class="filter-group"><label>Search</label><input type="text" name="search" placeholder="Name, CME, Email" value="<?= htmlspecialchars($search) ?>"></div>
        <div class="filter-group"><label>County</label><select name="county"><option value="">All</option><?php foreach($counties as $c): ?><option <?= $county_filter==$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
        <div class="filter-group"><label>From Date</label><input type="date" name="date_from" value="<?= $date_from ?>"></div>
        <div class="filter-group"><label>To Date</label><input type="date" name="date_to" value="<?= $date_to ?>"></div>
        <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
        <a href="virtual_trainings_list.php" class="btn-reset"><i class="fas fa-times"></i> Clear</a>
    </form>

    <div class="table-card">
        <div class="summary-bar"><span>Total Records: <?= number_format($total_records) ?></span><span><i class="fas fa-download"></i> <a href="export_virtual_trainings.php" style="color:#0D1A63;">Export to Excel</a></span></div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>ID</th><th>Participant</th><th>Sex</th><th>County</th><th>Cadre</th><th>CME Title</th><th>Training Date</th><th>Work Station</th><th>Department</th><th>Administered By</th></tr></thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['training_id'] ?></td>
                        <td><strong><?= htmlspecialchars($row['participant_name']) ?></strong></td>
                        <td><span class="badge <?= $row['sex']=='Male'?'badge-male':'badge-female' ?>"><?= $row['sex'] ?? '—' ?></span></td>
                        <td><?= htmlspecialchars($row['county'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['cadre_name'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['cme_title']) ?></td>
                        <td><?= date('d M Y', strtotime($row['date'])) ?></td>
                        <td><?= htmlspecialchars($row['work_station'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['department'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($row['administered_by'] ?? '—') ?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="10" style="text-align:center; padding:40px;">No records found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if($total_pages > 1): ?>
    <div class="pagination">
        <?php for($i=1; $i<=$total_pages; $i++): ?>
        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&county=<?= urlencode($county_filter) ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>" class="page-link <?= $i==$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>