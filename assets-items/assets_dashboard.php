<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$msg   = $_SESSION['success_msg'] ?? '';
$error = $_SESSION['error_msg']   ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// ── Handle return action ──────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'return' && isset($_GET['alloc_id'])) {
    $aid   = (int)$_GET['alloc_id'];
    $cond  = mysqli_real_escape_string($conn, $_GET['cond'] ?? 'Good');
    $by    = mysqli_real_escape_string($conn, $_SESSION['full_name'] ?? 'Admin');

    $alloc = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM asset_allocations WHERE allocation_id=$aid AND allocation_status='Active'"));
    if ($alloc) {
        mysqli_begin_transaction($conn);
        try {
            mysqli_query($conn, "UPDATE asset_allocations
                SET allocation_status='Returned', actual_return_date=CURDATE(),
                    return_condition='$cond'
                WHERE allocation_id=$aid");
            mysqli_query($conn, "UPDATE assets SET current_status='Available'
                WHERE asset_id={$alloc['asset_id']}");
            mysqli_commit($conn);
            $_SESSION['success_msg'] = "Asset returned successfully.";
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error_msg'] = "Error: ".$e->getMessage();
        }
    }
    header('Location: assets_dashboard.php');
    exit();
}

// ── AJAX: category breakdown for chart ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'chart_data') {
    $rows = [];
    $res = mysqli_query($conn,
        "SELECT ac.category_name, ac.category_icon,
                COUNT(a.asset_id) as total,
                SUM(a.current_status='Available') as available,
                SUM(a.current_status='Allocated')  as allocated
         FROM asset_categories ac
         LEFT JOIN assets a ON a.category_id = ac.category_id
         GROUP BY ac.category_id ORDER BY total DESC");
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── Filters ───────────────────────────────────────────────────────────────────
$f_county   = mysqli_real_escape_string($conn, $_GET['county']   ?? '');
$f_sub      = mysqli_real_escape_string($conn, $_GET['subcounty'] ?? '');
$f_facility = mysqli_real_escape_string($conn, $_GET['facility']  ?? '');
$f_category = (int)($_GET['category'] ?? 0);
$f_status   = mysqli_real_escape_string($conn, $_GET['alloc_status'] ?? '');
$f_search   = mysqli_real_escape_string($conn, $_GET['search']    ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 25;
$offset     = ($page-1)*$limit;

// Build WHERE
$where = "WHERE 1=1";
if ($f_county)   $where .= " AND aa.county_name   = '$f_county'";
if ($f_sub)      $where .= " AND aa.subcounty_name= '$f_sub'";
if ($f_facility) $where .= " AND aa.facility_name LIKE '%$f_facility%'";
if ($f_category) $where .= " AND a.category_id    = $f_category";
if ($f_status)   $where .= " AND aa.allocation_status = '$f_status'";
if ($f_search)   $where .= " AND (aa.staff_name LIKE '%$f_search%'
                              OR aa.id_number LIKE '%$f_search%'
                              OR a.asset_name LIKE '%$f_search%'
                              OR a.asset_code LIKE '%$f_search%'
                              OR a.serial_number LIKE '%$f_search%')";

$base_query = "FROM asset_allocations aa
               JOIN assets a ON a.asset_id = aa.asset_id
               JOIN asset_categories ac ON ac.category_id = a.category_id
               $where";

// Totals
$total_rows = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c $base_query"))['c'];
$total_pages = max(1, ceil($total_rows/$limit));

// Fetch records
$records = mysqli_query($conn,
    "SELECT aa.*, a.asset_name, a.asset_code, a.make, a.model,
            a.serial_number, a.condition_state, a.current_status,
            ac.category_name, ac.category_icon
     $base_query
     ORDER BY aa.allocation_date DESC, aa.created_at DESC
     LIMIT $limit OFFSET $offset");

// ── Summary stats ─────────────────────────────────────────────────────────────
$st = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT
        COUNT(*) as total_assets,
        SUM(current_status='Available')  as available,
        SUM(current_status='Allocated')  as allocated,
        SUM(current_status='Under Repair') as repair,
        SUM(current_status='Condemned')  as condemned
     FROM assets"));

$active_allocs = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM asset_allocations WHERE allocation_status='Active'"))['c'];

// Dropdown data
$counties    = mysqli_query($conn, "SELECT DISTINCT county_name FROM asset_allocations ORDER BY county_name");
$categories  = mysqli_query($conn, "SELECT * FROM asset_categories ORDER BY category_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assets Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:#f0f3fb; padding:20px; }

/* ── Header ── */
.page-header {
    background:linear-gradient(135deg,#0D1A63 0%,#1a3a8f 100%);
    color:#fff; padding:22px 30px; border-radius:14px; margin-bottom:22px;
    display:flex; justify-content:space-between; align-items:center;
    box-shadow:0 8px 24px rgba(13,26,99,.25);
}
.page-header h1 { font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
.hdr-links a {
    color:#fff; text-decoration:none; background:rgba(255,255,255,.15);
    padding:7px 14px; border-radius:8px; font-size:13px; margin-left:8px;
    transition:background .2s;
}
.hdr-links a:hover { background:rgba(255,255,255,.28); }

/* ── Alerts ── */
.alert { padding:13px 18px; border-radius:9px; margin-bottom:18px; font-size:14px;
    display:flex; align-items:center; gap:10px; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* ── Stats grid ── */
.stats-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:22px; }
.stat-card {
    background:#fff; border-radius:12px; padding:16px 18px;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    border-top:4px solid #0D1A63;
    text-align:center; transition:transform .2s;
}
.stat-card:hover { transform:translateY(-3px); }
.stat-card .sc-icon { font-size:22px; margin-bottom:6px; }
.stat-card h3 { font-size:28px; font-weight:800; color:#0D1A63; }
.stat-card p  { color:#888; font-size:11.5px; margin-top:3px; }

/* ── Category chips bar ── */
.cat-bar { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:22px; }
.cat-chip {
    background:#fff; border:2px solid #e0e8f8; border-radius:10px;
    padding:8px 14px; font-size:12.5px; color:#0D1A63; cursor:pointer;
    transition:all .2s; display:flex; align-items:center; gap:6px;
    text-decoration:none;
}
.cat-chip:hover, .cat-chip.active { background:#0D1A63; color:#fff; border-color:#0D1A63; }
.cat-chip .cnt {
    background:rgba(13,26,99,.12); color:#0D1A63; border-radius:6px;
    padding:1px 6px; font-size:11px; font-weight:700;
}
.cat-chip.active .cnt { background:rgba(255,255,255,.25); color:#fff; }

/* ── Filters card ── */
.filter-card {
    background:#fff; border-radius:12px; padding:16px 20px;
    box-shadow:0 2px 12px rgba(0,0,0,.06); margin-bottom:20px;
}
.filter-row { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
.filter-row .fg { flex:1; min-width:160px; }
.filter-row .fg label { font-size:12px; color:#777; font-weight:600;
    text-transform:uppercase; letter-spacing:.4px; display:block; margin-bottom:5px; }
.filter-row .fg input,
.filter-row .fg select {
    width:100%; padding:9px 12px; border:2px solid #e0e0e0; border-radius:8px;
    font-size:13px;
}
.filter-row .fg input:focus, .filter-row .fg select:focus {
    outline:none; border-color:#0D1A63;
}
.btn { padding:9px 18px; border:none; border-radius:8px; font-size:13px; font-weight:600;
    cursor:pointer; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
.btn-primary   { background:#0D1A63; color:#fff; }
.btn-primary:hover { background:#1a2a7a; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-secondary:hover { background:#5a6268; }
.btn-success   { background:#28a745; color:#fff; }
.btn-success:hover { background:#218838; }
.btn-warning   { background:#ffc107; color:#212529; }
.btn-danger    { background:#dc3545; color:#fff; }
.btn-sm        { padding:5px 10px; font-size:12px; border-radius:6px; }

/* ── Table card ── */
.table-card {
    background:#fff; border-radius:12px;
    box-shadow:0 2px 12px rgba(0,0,0,.06); overflow:hidden;
}
.table-head {
    background:linear-gradient(90deg,#0D1A63,#1a3a8f);
    color:#fff; padding:14px 20px;
    display:flex; justify-content:space-between; align-items:center;
}
.table-head h3 { font-size:15px; font-weight:700; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
th { background:#f8f9fa; color:#0D1A63; padding:12px 14px; text-align:left;
    font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
    border-bottom:2px solid #e0e0e0; white-space:nowrap; }
td { padding:11px 14px; border-bottom:1px solid #f0f0f0; font-size:13px;
    vertical-align:middle; }
tr:hover { background:#f8f9fa; }

/* ── Badges ── */
.badge {
    display:inline-block; padding:4px 10px; border-radius:14px;
    font-size:11px; font-weight:700; letter-spacing:.3px;
}
.badge-active   { background:#d4edda; color:#155724; }
.badge-returned { background:#cce5ff; color:#004085; }
.badge-lost     { background:#f8d7da; color:#721c24; }
.badge-transfer { background:#fff3cd; color:#856404; }
.badge-avail    { background:#d4edda; color:#155724; }
.badge-allocd   { background:#fff3cd; color:#856404; }
.badge-repair   { background:#cce5ff; color:#004085; }
.badge-condemned{ background:#f8d7da; color:#721c24; }

/* ── Category icon chip ── */
.cat-tag {
    display:inline-flex; align-items:center; gap:5px;
    background:#f0f3fb; border-radius:6px; padding:3px 8px;
    font-size:11.5px; color:#0D1A63; font-weight:600;
}

/* ── Pagination ── */
.pagination { display:flex; justify-content:space-between; align-items:center;
    padding:14px 20px; border-top:1px solid #f0f0f0; flex-wrap:wrap; gap:10px; }
.pagination .info { color:#888; font-size:13px; }
.pg-links { display:flex; gap:6px; }
.pg-links a, .pg-links span {
    padding:6px 12px; border:1.5px solid #e0e0e0; border-radius:6px;
    text-decoration:none; color:#333; font-size:13px; transition:all .2s;
}
.pg-links a:hover { background:#0D1A63; color:#fff; border-color:#0D1A63; }
.pg-links .cur { background:#0D1A63; color:#fff; border-color:#0D1A63; }

/* ── Return modal ── */
.modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
    z-index:9999; align-items:center; justify-content:center;
}
.modal-overlay.show { display:flex; }
.modal-box {
    background:#fff; border-radius:14px; padding:28px; width:440px;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
}
.modal-box h3 { font-size:17px; color:#0D1A63; margin-bottom:16px; }
.modal-box label { font-size:13px; color:#555; font-weight:600; display:block; margin-bottom:6px; }
.modal-box select {
    width:100%; padding:10px 12px; border:2px solid #e0e0e0; border-radius:8px;
    font-size:13.5px; margin-bottom:18px;
}
.modal-actions { display:flex; gap:10px; justify-content:flex-end; }

@media(max-width:900px) {
    .stats-grid { grid-template-columns:repeat(3,1fr); }
    .filter-row .fg { min-width:140px; }
}
@media(max-width:600px) {
    .stats-grid { grid-template-columns:repeat(2,1fr); }
    table { font-size:11.5px; }
    th, td { padding:8px 10px; }
}
</style>
</head>
<body>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Assets Dashboard</h1>
    <div class="hdr-links">
        <a href="allocate_asset.php"><i class="fas fa-user-tag"></i> Allocate Asset</a>
        <a href="add_asset.php"><i class="fas fa-plus"></i> Add Asset</a>

    </div>
</div>

<?php if ($msg):  ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div><?php endif; ?>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card" style="border-top-color:#0D1A63;">
        <div class="sc-icon" style="color:#0D1A63;"><i class="fas fa-boxes"></i></div>
        <h3><?= $st['total_assets'] ?></h3>
        <p>Total Assets</p>
    </div>
    <div class="stat-card" style="border-top-color:#28a745;">
        <div class="sc-icon" style="color:#28a745;"><i class="fas fa-check-circle"></i></div>
        <h3><?= $st['available'] ?></h3>
        <p>Available</p>
    </div>
    <div class="stat-card" style="border-top-color:#ffc107;">
        <div class="sc-icon" style="color:#856404;"><i class="fas fa-user-tag"></i></div>
        <h3><?= $st['allocated'] ?></h3>
        <p>Allocated</p>
    </div>
    <div class="stat-card" style="border-top-color:#17a2b8;">
        <div class="sc-icon" style="color:#17a2b8;"><i class="fas fa-tools"></i></div>
        <h3><?= $st['repair'] ?></h3>
        <p>Under Repair</p>
    </div>
    <div class="stat-card" style="border-top-color:#dc3545;">
        <div class="sc-icon" style="color:#dc3545;"><i class="fas fa-ban"></i></div>
        <h3><?= $st['condemned'] ?></h3>
        <p>Condemned</p>
    </div>
</div>

<!-- Category Filter Chips -->
<div class="cat-bar">
    <?php
    $active_cat_class = (!$f_category) ? 'active' : '';
    $total_allocs_display = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM asset_allocations"))['c'];
    $qs = http_build_query(array_merge($_GET, ['category'=>'', 'page'=>1]));
    ?>
    <a class="cat-chip <?= $active_cat_class ?>" href="?<?= $qs ?>">
        <i class="fas fa-th"></i> All Types
        <span class="cnt"><?= $st['total_assets'] ?></span>
    </a>
    <?php
    mysqli_data_seek($categories, 0);
    while ($cat = mysqli_fetch_assoc($categories)):
        $cnt = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM assets WHERE category_id={$cat['category_id']}"))['c'];
        if (!$cnt) continue;
        $active_cls = ($f_category == $cat['category_id']) ? 'active' : '';
        $qs2 = http_build_query(array_merge($_GET, ['category'=>$cat['category_id'],'page'=>1]));
    ?>
    <a class="cat-chip <?= $active_cls ?>" href="?<?= $qs2 ?>">
        <i class="fas <?= htmlspecialchars($cat['category_icon']) ?>"></i>
        <?= htmlspecialchars($cat['category_name']) ?>
        <span class="cnt"><?= $cnt ?></span>
    </a>
    <?php endwhile; ?>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" id="filterForm">
        <?php if ($f_category): ?>
        <input type="hidden" name="category" value="<?= $f_category ?>">
        <?php endif; ?>
        <div class="filter-row">
            <div class="fg">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($f_search) ?>"
                       placeholder="Name, ID, asset code, serial...">
            </div>
            <div class="fg">
                <label><i class="fas fa-map-marker-alt"></i> County</label>
                <select name="county" onchange="loadSubcounties(this.value)">
                    <option value="">All Counties</option>
                    <?php mysqli_data_seek($counties,0);
                    while ($c = mysqli_fetch_assoc($counties)): ?>
                    <option value="<?= htmlspecialchars($c['county_name']) ?>"
                        <?= $f_county==$c['county_name']?'selected':'' ?>>
                        <?= htmlspecialchars($c['county_name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-map"></i> Sub-County</label>
                <select name="subcounty" id="subcountyFilter">
                    <option value="">All Sub-Counties</option>
                    <?php if ($f_county):
                        $subs = mysqli_query($conn,
                            "SELECT DISTINCT subcounty_name FROM asset_allocations
                             WHERE county_name='$f_county' AND subcounty_name != ''
                             ORDER BY subcounty_name");
                        while ($s = mysqli_fetch_assoc($subs)):
                    ?>
                    <option value="<?= htmlspecialchars($s['subcounty_name']) ?>"
                        <?= $f_sub==$s['subcounty_name']?'selected':'' ?>>
                        <?= htmlspecialchars($s['subcounty_name']) ?>
                    </option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fas fa-hospital"></i> Facility</label>
                <input type="text" name="facility" value="<?= htmlspecialchars($f_facility) ?>"
                       placeholder="Facility name...">
            </div>
            <div class="fg">
                <label><i class="fas fa-tag"></i> Status</label>
                <select name="alloc_status">
                    <option value="">All Statuses</option>
                    <?php foreach (['Active','Returned','Transferred','Lost'] as $s):
                        $sel = $f_status===$s?'selected':''; ?>
                    <option value="<?=$s?>" <?=$sel?>><?=$s?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg" style="display:flex;gap:8px;align-items:flex-end;min-width:auto;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="assets_dashboard.php<?= $f_category ? '?category='.$f_category : '' ?>"
                   class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
            </div>
        </div>
    </form>
</div>

<!-- Allocations Table -->
<div class="table-card">
    <div class="table-head">
        <h3><i class="fas fa-list"></i> Asset Allocations
            <span style="font-weight:400;font-size:13px;margin-left:8px;">
                (<?= $total_rows ?> record<?= $total_rows!=1?'s':'' ?>)
            </span>
        </h3>
        <a href="allocate_asset.php" class="btn btn-success btn-sm">
            <i class="fas fa-plus"></i> New Allocation
        </a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Asset</th>
                    <th>Category</th>
                    <th>Staff Member</th>
                    <th>ID Number</th>
                    <th>Facility</th>
                    <th>County / Sub-County</th>
                    <th>Allocated</th>
                    <th>Expected Return</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $counter = $offset + 1;
            if (mysqli_num_rows($records) > 0):
                while ($row = mysqli_fetch_assoc($records)):
                    $st_badges = [
                        'Active'      => 'badge-active',
                        'Returned'    => 'badge-returned',
                        'Lost'        => 'badge-lost',
                        'Transferred' => 'badge-transfer',
                    ];
                    $badge_cls = $st_badges[$row['allocation_status']] ?? 'badge-active';
            ?>
            <tr>
                <td><?= $counter++ ?></td>
                <td>
                    <strong><?= htmlspecialchars($row['asset_name']) ?></strong>
                    <br><small style="color:#aaa;"><?= htmlspecialchars($row['asset_code']) ?></small>
                    <?php if ($row['serial_number']): ?>
                    <br><small style="color:#bbb;font-size:10px;">S/N: <?= htmlspecialchars($row['serial_number']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="cat-tag">
                        <i class="fas <?= htmlspecialchars($row['category_icon']) ?>"></i>
                        <?= htmlspecialchars($row['category_name']) ?>
                    </span>
                </td>
                <td>
                    <strong><?= htmlspecialchars($row['staff_name']) ?></strong>
                    <br><small style="color:#888;"><?= htmlspecialchars($row['cadre_name'] ?? '') ?></small>
                </td>
                <td><?= htmlspecialchars($row['id_number']) ?></td>
                <td>
                    <?= htmlspecialchars($row['facility_name']) ?>
                    <br><small style="color:#aaa;"><?= htmlspecialchars($row['department_name'] ?? '') ?></small>
                </td>
                <td>
                    <?= htmlspecialchars($row['county_name']) ?>
                    <?php if ($row['subcounty_name']): ?>
                    <br><small style="color:#aaa;"><?= htmlspecialchars($row['subcounty_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= date('d/m/Y', strtotime($row['allocation_date'])) ?></td>
                <td>
                    <?= $row['expected_return_date']
                        ? date('d/m/Y', strtotime($row['expected_return_date']))
                        : '<span style="color:#aaa;">Permanent</span>' ?>
                    <?php if ($row['actual_return_date']): ?>
                    <br><small style="color:#28a745;">Returned: <?= date('d/m/Y', strtotime($row['actual_return_date'])) ?></small>
                    <?php endif; ?>
                </td>
                <td><span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($row['allocation_status']) ?></span></td>
                <td>
                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        <?php if ($row['allocation_status'] === 'Active'): ?>
                        <button class="btn btn-warning btn-sm"
                                onclick="showReturnModal(<?= $row['allocation_id'] ?>, '<?= htmlspecialchars(addslashes($row['asset_name'])) ?>')">
                            <i class="fas fa-undo"></i> Return
                        </button>
                        <?php endif; ?>
                        <a href="view_allocation.php?id=<?= $row['allocation_id'] ?>"
                           class="btn btn-sm" style="background:#e8edf8;color:#0D1A63;">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endwhile;
            else: ?>
            <tr>
                <td colspan="11" style="text-align:center;padding:40px;color:#aaa;">
                    <i class="fas fa-box-open" style="font-size:40px;display:block;margin-bottom:10px;"></i>
                    No allocation records found
                    <?php if ($f_search || $f_county || $f_category || $f_status): ?>
                    <br><a href="assets_dashboard.php" class="btn btn-primary btn-sm"
                           style="margin-top:10px;">Clear Filters</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="pagination">
        <div class="info">
            Showing <?= $total_rows ? $offset+1 : 0 ?>–<?= min($offset+$limit, $total_rows) ?>
            of <?= $total_rows ?> records
        </div>
        <?php if ($total_pages > 1):
            $pg_params = $_GET;
        ?>
        <div class="pg-links">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($pg_params,['page'=>$page-1])) ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>

            <?php
            $start = max(1, $page-2);
            $end   = min($total_pages, $page+2);
            if ($start > 1) { echo '<a href="?'.http_build_query(array_merge($pg_params,['page'=>1])).'">1</a>'; if ($start>2) echo '<span>…</span>'; }
            for ($i=$start; $i<=$end; $i++):
            ?>
            <?php if ($i==$page): ?>
                <span class="cur"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(array_merge($pg_params,['page'=>$i])) ?>"><?= $i ?></a>
            <?php endif; ?>
            <?php endfor; ?>
            <?php if ($end < $total_pages): if ($end<$total_pages-1) echo '<span>…</span>';
                echo '<a href="?'.http_build_query(array_merge($pg_params,['page'=>$total_pages])).'">'.$total_pages.'</a>'; endif; ?>

            <?php if ($page < $total_pages): ?>
            <a href="?<?= http_build_query(array_merge($pg_params,['page'=>$page+1])) ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Return Modal -->
<div class="modal-overlay" id="returnModal">
    <div class="modal-box">
        <h3><i class="fas fa-undo" style="color:#0D1A63;margin-right:8px;"></i> Return Asset</h3>
        <p style="color:#666;font-size:13px;margin-bottom:16px;" id="returnAssetName"></p>
        <label>Return Condition</label>
        <select id="returnCond">
            <option value="Good">Good</option>
            <option value="Fair">Fair</option>
            <option value="Poor">Poor</option>
            <option value="Damaged">Damaged</option>
            <option value="Lost">Lost</option>
        </select>
        <div class="modal-actions">
            <button class="btn btn-secondary" onclick="closeReturnModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <a href="#" class="btn btn-primary" id="confirmReturnBtn">
                <i class="fas fa-check"></i> Confirm Return
            </a>
        </div>
    </div>
</div>

<script>
// ── Return modal ──────────────────────────────────────────────────────────────
function showReturnModal(allocId, assetName) {
    document.getElementById('returnAssetName').textContent = 'Returning: ' + assetName;
    document.getElementById('returnModal').classList.add('show');
    document.getElementById('confirmReturnBtn').onclick = function() {
        const cond = document.getElementById('returnCond').value;
        window.location.href = `assets_dashboard.php?action=return&alloc_id=${allocId}&cond=${encodeURIComponent(cond)}`;
    };
}
function closeReturnModal() {
    document.getElementById('returnModal').classList.remove('show');
}
document.getElementById('returnModal').addEventListener('click', function(e) {
    if (e.target === this) closeReturnModal();
});

// ── Dynamic subcounty load when county changes ────────────────────────────────
function loadSubcounties(county) {
    const sel = document.getElementById('subcountyFilter');
    sel.innerHTML = '<option value="">Loading...</option>';
    if (!county) { sel.innerHTML = '<option value="">All Sub-Counties</option>'; return; }
    fetch(`assets_dashboard.php?ajax=subcounties&county=${encodeURIComponent(county)}`)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">All Sub-Counties</option>';
            data.forEach(s => {
                sel.innerHTML += `<option value="${s}">${s}</option>`;
            });
        });
}

// ── AJAX: subcounties endpoint ────────────────────────────────────────────────
<?php
if (isset($_GET['ajax']) && $_GET['ajax'] === 'subcounties') {
    $c = mysqli_real_escape_string($conn, $_GET['county'] ?? '');
    $rows = [];
    $r = mysqli_query($conn,
        "SELECT DISTINCT subcounty_name FROM asset_allocations
         WHERE county_name='$c' AND subcounty_name != '' ORDER BY subcounty_name");
    while ($row = mysqli_fetch_assoc($r)) $rows[] = $row['subcounty_name'];
    echo 'const _preloadedSubs = '.json_encode($rows).';';
}
?>

// ── Auto-submit search on enter ───────────────────────────────────────────────
document.querySelector('input[name="search"]').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') document.getElementById('filterForm').submit();
});
</script>
</body>
</html>
