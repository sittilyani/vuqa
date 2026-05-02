<?php
// digitization/view_asset_issues.php  — Assets Issuance Register (CRUD List)

// Buffer ALL AJAX calls immediately to prevent PHP warnings corrupting JSON
$_IS_AJAX = (isset($_POST['ajax_delete']) || isset($_POST['ajax_action']) || isset($_POST['ajax_perm_delete']));
if ($_IS_AJAX) { ob_start(); ini_set('display_errors', 0); error_reporting(0); }

session_start();

$base_path   = dirname(__DIR__);
$config_path = $base_path . '/includes/config.php';
$sess_check  = $base_path . '/includes/session_check.php';

if (!file_exists($config_path)) die('Configuration file not found.');
include $config_path;
include $sess_check;

if (!isset($conn) || !$conn)          die('Database connection failed.');
if (!isset($_SESSION['user_id']))     { header('Location: ../login.php'); exit(); }

mysqli_report(MYSQLI_REPORT_OFF);

$e   = fn($v) => mysqli_real_escape_string($conn, trim((string)($v ?? '')));
$i   = fn($v) => is_numeric($v) ? (int)$v : 0;
$now = date('Y-m-d H:i:s');

// Logged-in user info
$current_user = $_SESSION['username'] ?? $_SESSION['name'] ?? ($_SESSION['user_id'] ?? 'unknown');
$current_role = $_SESSION['role'] ?? '';
$is_admin     = in_array($current_role, ['Admin', 'Super Admin']);

// Detect which table exists — supports pre- and post-migration state
$_air_table = 'assets_issuance_register';
$_tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'assets_issuance_register'");
if (!$_tbl_check || mysqli_num_rows($_tbl_check) === 0) {
    $_air_table = 'digital_innovation_investments';
}

// Helper: send JSON and exit
function aj(array $payload): void {
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

// ── AJAX: Status action (return / lost / disposed etc.) ──────────────────────
if (isset($_POST['ajax_action'])) {
    global $conn, $_air_table, $e, $i, $now, $current_user;
    $iid    = $i($_POST['invest_id'] ?? 0);
    $action = $e($_POST['action_type'] ?? '');
    $notes  = $e($_POST['action_notes'] ?? '');

    $allowed = ['Returned-Good','Returned-Faulty','Lost','Obsolete','Damaged','Disposed'];
    if ($iid <= 0 || !in_array($action, $allowed)) {
        aj(['success'=>false,'error'=>'Invalid action or ID']);
    }

    // Extra columns for disposed
    $extra_sql = '';
    if ($action === 'Disposed') {
        $disp_date = date('Y-m-d');
        $extra_sql = ", date_of_disposal = '$disp_date'";
    }

    // Update issuance record
    $ok = mysqli_query($conn,
        "UPDATE `$_air_table`
         SET invest_status = '$action',
             action_notes  = '$notes',
             action_date   = '$now',
             updated_at    = '$now'
             $extra_sql
         WHERE invest_id = $iid");

    if (!$ok) aj(['success'=>false,'error'=>mysqli_error($conn)]);

    // Sync asset_master_register.asset_status
    // Find asset_id for this issuance
    $ar = mysqli_query($conn, "SELECT asset_id FROM `$_air_table` WHERE invest_id=$iid LIMIT 1");
    if ($ar && ($arow = mysqli_fetch_assoc($ar)) && $arow['asset_id']) {
        $aid = (int)$arow['asset_id'];
        // Returns → back to In Stock; everything else → Disposed / keep as-is
        if (in_array($action, ['Returned-Good','Returned-Faulty'])) {
            $new_asset_status = 'In Stock';
        } elseif (in_array($action, ['Disposed','Lost','Damaged','Obsolete'])) {
            $new_asset_status = 'Disposed';
        } else {
            $new_asset_status = 'Issued';
        }
        mysqli_query($conn,
            "UPDATE asset_master_register
             SET asset_status = '$new_asset_status', updated_at = '$now'
             WHERE asset_id = $aid");
    }

    aj(['success'=>true,'new_status'=>$action]);
}

// ── AJAX: Soft-delete (move to deleted_issued_items) ─────────────────────────
if (isset($_POST['ajax_delete'])) {
    global $conn, $_air_table, $i, $e, $now, $current_user, $is_admin;

    $iid = $i($_POST['invest_id'] ?? 0);
    if ($iid <= 0) aj(['success'=>false,'error'=>'Invalid ID']);

    // Fetch full record
    $rec_res = mysqli_query($conn, "SELECT * FROM `$_air_table` WHERE invest_id=$iid LIMIT 1");
    if (!$rec_res || mysqli_num_rows($rec_res) === 0) {
        aj(['success'=>false,'error'=>'Record not found']);
    }
    $rec = mysqli_fetch_assoc($rec_res);

    // Ensure deleted_issued_items table exists
    $del_tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'deleted_issued_items'");
    if (!$del_tbl_check || mysqli_num_rows($del_tbl_check) === 0) {
        // Create it on-the-fly if migration_v4 hasn't run yet
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `deleted_issued_items` (
            `del_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `invest_id` INT UNSIGNED NOT NULL,
            `asset_id` INT UNSIGNED NULL,
            `facility_name` VARCHAR(255) NULL,
            `mflcode` VARCHAR(30) NULL,
            `county_name` VARCHAR(100) NULL,
            `tag_name` VARCHAR(100) NULL,
            `name_of_user` VARCHAR(255) NULL,
            `department_name` VARCHAR(150) NULL,
            `issue_date` DATE NULL,
            `invest_status` VARCHAR(50) NOT NULL DEFAULT 'Active',
            `action_notes` TEXT NULL,
            `action_date` DATETIME NULL,
            `purchase_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `current_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `created_by` VARCHAR(150) NULL,
            `created_at` DATETIME NULL,
            `deleted_by` VARCHAR(150) NOT NULL,
            `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`del_id`),
            KEY `idx_invest_id` (`invest_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Insert into archive
    $e_fn   = fn($v) => mysqli_real_escape_string($conn, (string)($v ?? ''));
    $to_d   = fn($v) => $v ? "'" . $e_fn($v) . "'" : 'NULL';
    $to_n   = fn($v) => is_numeric($v) ? (float)$v : 0;
    $del_by = $e_fn($current_user);

    $ins = mysqli_query($conn,
        "INSERT INTO deleted_issued_items
         (invest_id, asset_id, facility_name, mflcode, county_name,
          tag_name, name_of_user, department_name, issue_date,
          invest_status, action_notes, action_date,
          purchase_value, current_value, created_by, created_at,
          deleted_by, deleted_at)
         VALUES (
            $iid,
            " . ($rec['asset_id'] ? (int)$rec['asset_id'] : 'NULL') . ",
            " . $to_d($rec['facility_name']) . ",
            " . $to_d($rec['mflcode']) . ",
            " . $to_d($rec['county_name']) . ",
            " . $to_d($rec['tag_name']) . ",
            " . $to_d($rec['name_of_user']) . ",
            " . $to_d($rec['department_name']) . ",
            " . $to_d($rec['issue_date']) . ",
            '" . $e_fn($rec['invest_status'] ?? 'Active') . "',
            " . $to_d($rec['action_notes'] ?? null) . ",
            " . $to_d($rec['action_date'] ?? null) . ",
            " . $to_n($rec['purchase_value']) . ",
            " . $to_n($rec['current_value']) . ",
            " . $to_d($rec['created_by']) . ",
            " . $to_d($rec['created_at']) . ",
            '$del_by',
            '$now'
         )");

    if (!$ins) aj(['success'=>false,'error'=>'Archive failed: ' . mysqli_error($conn)]);

    // Remove from live table
    $del = mysqli_query($conn, "DELETE FROM `$_air_table` WHERE invest_id=$iid");
    if (!$del) aj(['success'=>false,'error'=>'Delete failed: ' . mysqli_error($conn)]);

    aj(['success'=>true]);
}

// ── AJAX: Permanent delete from deleted_issued_items (Admin/Super Admin only) ─
if (isset($_POST['ajax_perm_delete'])) {
    global $is_admin, $conn, $i;
    if (!$is_admin) aj(['success'=>false,'error'=>'Insufficient permissions']);
    $did = $i($_POST['del_id'] ?? 0);
    if ($did <= 0) aj(['success'=>false,'error'=>'Invalid ID']);
    $ok = mysqli_query($conn, "DELETE FROM deleted_issued_items WHERE del_id=$did");
    aj($ok ? ['success'=>true] : ['success'=>false,'error'=>mysqli_error($conn)]);
}

// ── AJAX: export CSV ─────────────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $res = mysqli_query($conn,
        "SELECT air.invest_id, air.facility_name, air.mflcode, air.county_name,
                amr.asset_category, amr.description AS asset_description,
                air.tag_name, air.name_of_user, air.department_name,
                air.purchase_value, air.current_value, air.depreciation_percentage,
                air.issue_date, air.end_date, air.invest_status,
                air.action_notes, air.action_date,
                air.service_level, air.lot_number,
                et.emr_type_name, df.dig_funder_name,
                air.created_by, air.created_at
         FROM `$_air_table` air
         LEFT JOIN asset_master_register amr ON air.asset_id = amr.asset_id
         LEFT JOIN emr_types et ON air.emr_type_id = et.emr_type_id
         LEFT JOIN digital_funders df ON air.dig_funder_id = df.dig_funder_id
         ORDER BY air.created_at DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assets_issuance_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['invest_id','facility_name','mflcode','county_name','asset_category',
                   'asset_description','tag_name','name_of_user','department_name',
                   'purchase_value','current_value','depreciation_percentage',
                   'issue_date','end_date','invest_status','action_notes','action_date',
                   'service_level','lot_number','emr_type_name','dig_funder_name','created_by','created_at']);
    if ($res) while ($row = mysqli_fetch_assoc($res)) fputcsv($out, $row);
    fclose($out);
    exit();
}

// ── FILTERS ──────────────────────────────────────────────────────────────────
$f_status   = $e($_GET['status']  ?? '');
$f_county   = $e($_GET['county']  ?? '');
$f_search   = $e($_GET['search']  ?? '');
$f_dept     = $e($_GET['dept']    ?? '');

$where = "WHERE 1=1";
if ($f_status)  $where .= " AND air.invest_status = '$f_status'";
if ($f_county)  $where .= " AND air.county_name = '$f_county'";
if ($f_dept)    $where .= " AND air.department_name LIKE '%$f_dept%'";
if ($f_search)  $where .= " AND (air.facility_name LIKE '%$f_search%'
                                  OR air.tag_name LIKE '%$f_search%'
                                  OR amr.description LIKE '%$f_search%'
                                  OR air.name_of_user LIKE '%$f_search%'
                                  OR air.mflcode LIKE '%$f_search%')";

// Total count
$cnt_res  = mysqli_query($conn, "SELECT COUNT(*) AS n FROM `$_air_table` air
            LEFT JOIN asset_master_register amr ON air.asset_id=amr.asset_id $where");
$cnt_row  = $cnt_res ? mysqli_fetch_assoc($cnt_res) : ['n'=>0];
$total    = (int)$cnt_row['n'];

$per_page = 30;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$pages    = max(1, ceil($total / $per_page));

// Main query
$list_res = mysqli_query($conn,
    "SELECT air.invest_id, air.asset_id, air.facility_name, air.mflcode,
            air.county_name, air.tag_name, air.name_of_user, air.department_name,
            air.purchase_value, air.current_value, air.depreciation_percentage,
            air.issue_date, air.end_date, air.no_end_date, air.invest_status,
            air.action_notes, air.action_date,
            air.service_level, air.lot_number, air.created_at,
            amr.asset_category, amr.description AS asset_description,
            amr.model, amr.serial_number,
            et.emr_type_name, df.dig_funder_name
     FROM `$_air_table` air
     LEFT JOIN asset_master_register amr ON air.asset_id = amr.asset_id
     LEFT JOIN emr_types et ON air.emr_type_id = et.emr_type_id
     LEFT JOIN digital_funders df ON air.dig_funder_id = df.dig_funder_id
     $where
     ORDER BY air.created_at DESC
     LIMIT $per_page OFFSET $offset");

$records = [];
if ($list_res) while ($row = mysqli_fetch_assoc($list_res)) $records[] = $row;

// Summary cards
$sum_res = mysqli_query($conn, "SELECT
    COUNT(*) AS total_records,
    SUM(purchase_value) AS total_pv,
    SUM(current_value)  AS total_cv,
    SUM(CASE WHEN invest_status='Active' THEN 1 ELSE 0 END) AS active_cnt
    FROM `$_air_table`");
$sum = $sum_res ? mysqli_fetch_assoc($sum_res) : [];

// County dropdown
$cnty_res = mysqli_query($conn, "SELECT DISTINCT county_name FROM `$_air_table` WHERE county_name IS NOT NULL ORDER BY county_name");
$counties = [];
if ($cnty_res) while ($c = mysqli_fetch_assoc($cnty_res)) $counties[] = $c['county_name'];

// Status badge map
$status_styles = [
    'Active'          => 'background:#d4f7e6;color:#04B04B',
    'Expired'         => 'background:#fde8eb;color:#E41E39',
    'Returned-Good'   => 'background:#e8f4ff;color:#0066cc',
    'Returned-Faulty' => 'background:#fff3cd;color:#856404',
    'Lost'            => 'background:#fde8eb;color:#E41E39',
    'Obsolete'        => 'background:#f5f3fb;color:#6c757d',
    'Damaged'         => 'background:#fff3cd;color:#a65f00',
    'Disposed'        => 'background:#ffe8f0;color:#9b1030',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assets Issuance Register – LVCT Health</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--primary:#2D008A;--primary-dark:#1e005e;--primary-light:#AC80EE;
    --success:#04B04B;--warning:#FFC12E;--danger:#E41E39;
    --bg:#FDFCF9;--card:#fff;--border:#e0d9f0;--text:#1a1a2e;--muted:#6c757d;
    --radius:10px;--shadow:0 2px 12px rgba(45,0,138,.09)}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:var(--primary);color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.18)}
.topbar h1{font-size:1.15rem;font-weight:700}
.topbar .acts a{color:#fff;text-decoration:none;margin-left:14px;font-size:.87rem;opacity:.88}
.topbar .acts a:hover{opacity:1}
.container{max-width:1400px;margin:24px auto;padding:0 18px}
/* SUMMARY CARDS */
.summary-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
@media(max-width:900px){.summary-row{grid-template-columns:1fr 1fr}}
.scard{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);padding:16px 20px;display:flex;align-items:center;gap:14px}
.scard-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0}
.scard-icon.purple{background:#f0ebff;color:var(--primary)}
.scard-icon.green{background:#d4f7e6;color:var(--success)}
.scard-icon.yellow{background:#fff8e1;color:#d4960a}
.scard-icon.red{background:#fde8eb;color:var(--danger)}
.scard-body p{font-size:.75rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.scard-body h3{font-size:1.25rem;font-weight:700;color:var(--text);margin-top:2px}
/* FILTER BAR */
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:18px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.filter-bar .form-group{display:flex;flex-direction:column;gap:4px;min-width:160px}
.filter-bar label{font-size:.75rem;font-weight:600;color:var(--primary)}
.filter-bar input,.filter-bar select{border:1.5px solid var(--border);border-radius:6px;padding:7px 10px;font-size:.85rem;color:var(--text)}
.filter-bar input:focus,.filter-bar select:focus{outline:none;border-color:var(--primary)}
/* TABLE */
.table-wrap{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.table-head{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border)}
.table-head h2{font-size:1rem;font-weight:700;color:var(--primary)}
table{width:100%;border-collapse:collapse;font-size:.84rem}
thead th{background:#f5f3fb;color:var(--primary);font-weight:700;font-size:.76rem;text-transform:uppercase;letter-spacing:.5px;padding:10px 12px;text-align:left;white-space:nowrap;border-bottom:2px solid var(--border)}
tbody tr{border-bottom:1px solid #f5f3fb;transition:background .15s}
tbody tr:hover{background:#f9f7ff}
td{padding:10px 12px;vertical-align:middle}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700}
.kes{font-variant-numeric:tabular-nums;font-weight:600}
.muted{color:var(--muted);font-size:.78rem}
.actions-col{display:flex;gap:5px;white-space:nowrap;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:6px;border:none;font-size:.78rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-dark);color:#fff}
.btn-success{background:var(--success);color:#fff}
.btn-warning{background:var(--warning);color:#1a1a2e}
.btn-danger{background:var(--danger);color:#fff}
.btn-outline{background:transparent;border:1.5px solid var(--primary);color:var(--primary)}
.btn-outline:hover{background:var(--primary);color:#fff}
.btn-sm{padding:5px 9px;font-size:.74rem}
/* PAGINATION */
.pagination{padding:14px 20px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--border)}
.pagination .info{font-size:.82rem;color:var(--muted)}
.pag-links{display:flex;gap:4px;flex-wrap:wrap}
.pag-links a,.pag-links span{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:7px;border:1.5px solid var(--border);font-size:.82rem;font-weight:600;text-decoration:none;color:var(--text);transition:all .2s}
.pag-links a:hover{background:var(--primary);color:#fff;border-color:var(--primary)}
.pag-links span.current{background:var(--primary);color:#fff;border-color:var(--primary)}
/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:1000;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal{background:#fff;border-radius:12px;padding:28px;max-width:460px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,.22);position:relative}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted)}
.modal-close:hover{color:var(--danger)}
.modal h3{font-size:1.05rem;font-weight:700;color:var(--primary);margin-bottom:6px}
.modal p.sub{font-size:.84rem;color:var(--muted);margin-bottom:18px}
.form-row{margin-bottom:14px}
.form-row label{display:block;font-size:.78rem;font-weight:600;color:var(--primary);margin-bottom:5px}
.form-row select,.form-row textarea{width:100%;border:1.5px solid var(--border);border-radius:7px;padding:9px 12px;font-size:.88rem;color:var(--text);font-family:inherit;resize:vertical}
.form-row select:focus,.form-row textarea:focus{outline:none;border-color:var(--primary)}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px}
/* ALERT */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:.88rem;display:none}
.alert.show{display:flex;align-items:center;gap:8px}
.alert-success{background:#d4f7e6;border:1px solid var(--success);color:#056629}
.alert-danger{background:#fde8eb;border:1px solid var(--danger);color:#9b0d20}
/* Notes tooltip */
.has-notes{cursor:help;border-bottom:1px dashed var(--muted)}
</style>
</head>
<body>

<div class="topbar">
    <h1><i class="fa fa-list-check"></i> Assets Issuance Register – LVCT Health</h1>
    <div class="acts">
        <a href="assets_issuance.php"><i class="fa fa-plus"></i> New Issuance</a>
        <a href="asset_master_register.php"><i class="fa fa-database"></i> Asset Master</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export_csv'=>1])) ?>"><i class="fa fa-download"></i> Export CSV</a>
        <a href="../index.php"><i class="fa fa-home"></i> Home</a>
    </div>
</div>

<div class="container">
    <div id="alertBox" class="alert"></div>

    <!-- SUMMARY CARDS -->
    <div class="summary-row">
        <div class="scard">
            <div class="scard-icon purple"><i class="fa fa-tags"></i></div>
            <div class="scard-body"><p>Total Records</p><h3><?= number_format((int)($sum['total_records'] ?? 0)) ?></h3></div>
        </div>
        <div class="scard">
            <div class="scard-icon green"><i class="fa fa-circle-check"></i></div>
            <div class="scard-body"><p>Active</p><h3><?= number_format((int)($sum['active_cnt'] ?? 0)) ?></h3></div>
        </div>
        <div class="scard">
            <div class="scard-icon yellow"><i class="fa fa-coins"></i></div>
            <div class="scard-body"><p>Total Purchase Value</p><h3>KES <?= number_format((float)($sum['total_pv'] ?? 0), 0) ?></h3></div>
        </div>
        <div class="scard">
            <div class="scard-icon red"><i class="fa fa-chart-line"></i></div>
            <div class="scard-body"><p>Total Current Value</p><h3>KES <?= number_format((float)($sum['total_cv'] ?? 0), 0) ?></h3></div>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" class="filter-bar">
        <div class="form-group" style="flex:2;min-width:220px">
            <label>Search</label>
            <input type="text" name="search" placeholder="Facility, tag, user, MFL…"
                   value="<?= htmlspecialchars($f_search) ?>">
        </div>
        <div class="form-group">
            <label>Status</label>
            <select name="status">
                <option value="">All</option>
                <option value="Active"          <?= $f_status==='Active'          ?'selected':'' ?>>Active</option>
                <option value="Expired"         <?= $f_status==='Expired'         ?'selected':'' ?>>Expired</option>
                <option value="Returned-Good"   <?= $f_status==='Returned-Good'   ?'selected':'' ?>>Returned – Good</option>
                <option value="Returned-Faulty" <?= $f_status==='Returned-Faulty' ?'selected':'' ?>>Returned – Faulty</option>
                <option value="Lost"            <?= $f_status==='Lost'            ?'selected':'' ?>>Lost</option>
                <option value="Obsolete"        <?= $f_status==='Obsolete'        ?'selected':'' ?>>Obsolete</option>
                <option value="Damaged"         <?= $f_status==='Damaged'         ?'selected':'' ?>>Damaged</option>
                <option value="Disposed"        <?= $f_status==='Disposed'        ?'selected':'' ?>>Disposed</option>
            </select>
        </div>
        <div class="form-group">
            <label>County</label>
            <select name="county">
                <option value="">All Counties</option>
                <?php foreach ($counties as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $f_county===$c?'selected':'' ?>>
                    <?= htmlspecialchars($c) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Department</label>
            <input type="text" name="dept" placeholder="Filter by department"
                   value="<?= htmlspecialchars($f_dept) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Filter</button>
        <a href="view_asset_issues.php" class="btn btn-outline"><i class="fa fa-rotate-left"></i> Reset</a>
    </form>

    <!-- TABLE -->
    <div class="table-wrap">
        <div class="table-head">
            <h2><i class="fa fa-table fa-sm"></i> Issuance Records
                <span style="font-size:.82rem;font-weight:400;color:var(--muted);margin-left:8px">
                    (<?= number_format($total) ?> total — showing <?= $per_page ?> per page)
                </span>
            </h2>
        </div>
        <?php if (empty($records)): ?>
        <div style="padding:40px;text-align:center;color:var(--muted)">
            <i class="fa fa-inbox fa-2x" style="margin-bottom:10px;display:block"></i>
            No records found. <a href="assets_issuance.php" style="color:var(--primary)">Add the first issuance →</a>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Facility</th>
                    <th>Asset / Category</th>
                    <th>Tag / Label</th>
                    <th>Assigned To</th>
                    <th>Department</th>
                    <th>Issue Date</th>
                    <th>Purchase KES</th>
                    <th>Current KES</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($records as $rec): ?>
            <?php
                $sid    = $rec['invest_id'];
                $sstyle = $status_styles[$rec['invest_status']] ?? 'background:#f5f3fb;color:#6c757d';
                $has_notes = !empty($rec['action_notes']);
            ?>
            <tr id="row-<?= $sid ?>">
                <td class="muted"><?= $sid ?></td>
                <td>
                    <strong><?= htmlspecialchars($rec['facility_name']) ?></strong>
                    <div class="muted"><?= htmlspecialchars($rec['mflcode'] ?? '') ?> · <?= htmlspecialchars($rec['county_name'] ?? '') ?></div>
                </td>
                <td>
                    <strong><?= htmlspecialchars($rec['asset_description'] ?? '—') ?></strong>
                    <div class="muted"><?= htmlspecialchars($rec['asset_category'] ?? '') ?></div>
                </td>
                <td><?= htmlspecialchars($rec['tag_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($rec['name_of_user'] ?? '—') ?></td>
                <td class="muted"><?= htmlspecialchars($rec['department_name'] ?? '—') ?></td>
                <td><?= $rec['issue_date'] ? date('d/m/Y', strtotime($rec['issue_date'])) : '—' ?></td>
                <td class="kes"><?= number_format((float)$rec['purchase_value'], 0) ?></td>
                <td class="kes"><?= number_format((float)$rec['current_value'], 0) ?></td>
                <td>
                    <span class="badge" style="<?= $sstyle ?>">
                        <?= htmlspecialchars($rec['invest_status']) ?>
                    </span>
                    <?php if ($has_notes): ?>
                    <span class="has-notes muted" title="<?= htmlspecialchars($rec['action_notes'] ?? '') ?>">
                        <i class="fa fa-note-sticky fa-xs"></i>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="actions-col">
                        <a href="print_issuance.php?invest_id=<?= $sid ?>"
                           target="_blank" class="btn btn-success btn-sm" title="Print Certificate">
                            <i class="fa fa-print"></i>
                        </a>
                        <button class="btn btn-warning btn-sm" title="Update Status / Action"
                                onclick="openAction(<?= $sid ?>, '<?= htmlspecialchars(addslashes($rec['facility_name'])) ?>', '<?= htmlspecialchars(addslashes($rec['asset_description'] ?? '')) ?>')">
                            <i class="fa fa-pen-to-square"></i> Action
                        </button>
                        <button class="btn btn-danger btn-sm" title="Remove Record"
                                onclick="confirmDelete(<?= $sid ?>, '<?= htmlspecialchars(addslashes($rec['facility_name'])) ?>')">
                            <i class="fa fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- PAGINATION -->
        <div class="pagination">
            <div class="info">
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total)) ?> of <?= number_format($total) ?> records
            </div>
            <div class="pag-links">
                <?php
                $q_params = $_GET;
                // Show max 10 page links for brevity
                $p_start = max(1, $page - 5);
                $p_end   = min($pages, $p_start + 9);
                if ($p_start > 1) { $q_params['page'] = 1; echo '<a href="?'.http_build_query($q_params).'">1</a>'; if ($p_start > 2) echo '<span>…</span>'; }
                for ($p = $p_start; $p <= $p_end; $p++):
                    $q_params['page'] = $p;
                    $link = '?' . http_build_query($q_params);
                    if ($p == $page): ?>
                    <span class="current"><?= $p ?></span>
                    <?php else: ?>
                    <a href="<?= $link ?>"><?= $p ?></a>
                    <?php endif;
                endfor;
                if ($p_end < $pages) { if ($p_end < $pages - 1) echo '<span>…</span>'; $q_params['page'] = $pages; echo '<a href="?'.http_build_query($q_params).'">'.$pages.'</a>'; }
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════ ACTION MODAL ═══════════ -->
<div class="modal-overlay" id="actionModal">
    <div class="modal">
        <button class="modal-close" onclick="closeAction()"><i class="fa fa-times"></i></button>
        <h3><i class="fa fa-pen-to-square"></i> Update Issuance Status</h3>
        <p class="sub" id="actionSubtitle">Select action and add notes.</p>

        <div class="form-row">
            <label>Action <span style="color:var(--danger)">*</span></label>
            <select id="actionType">
                <option value="">— Select action —</option>
                <option value="Returned-Good">✅ Returned – Good condition</option>
                <option value="Returned-Faulty">⚠️ Returned – Faulty</option>
                <option value="Lost">❌ Lost</option>
                <option value="Obsolete">🔴 Obsolete</option>
                <option value="Damaged">🟡 Damaged</option>
                <option value="Disposed">🗑️ Disposed</option>
            </select>
        </div>
        <div class="form-row">
            <label>Notes / Remarks <span style="color:var(--danger)">*</span></label>
            <textarea id="actionNotes" rows="4" placeholder="Describe the reason or details of this action…"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeAction()">Cancel</button>
            <button class="btn btn-primary" id="actionSubmitBtn" onclick="submitAction()">
                <i class="fa fa-check"></i> Save Action
            </button>
        </div>
    </div>
</div>

<!-- ═══════════ DELETE MODAL ═══════════ -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal">
        <button class="modal-close" onclick="closeDelete()"><i class="fa fa-times"></i></button>
        <h3 style="color:var(--danger)"><i class="fa fa-triangle-exclamation"></i> Remove Issuance Record</h3>
        <p id="deleteMsg" class="sub" style="margin-bottom:18px">This record will be moved to the archive.</p>

        <div style="background:#fff8e1;border:1px solid #FFC12E;border-radius:8px;padding:12px;font-size:.84rem;margin-bottom:16px">
            <i class="fa fa-circle-info" style="color:#d4960a"></i>
            The record will be <strong>archived</strong> (not permanently deleted).
            <?php if ($is_admin): ?>
            Permanent deletion is available from the archive panel (Admin only).
            <?php else: ?>
            Only Admins can permanently delete archived records.
            <?php endif; ?>
        </div>

        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeDelete()">Cancel</button>
            <button class="btn btn-danger" id="confirmDeleteBtn">
                <i class="fa fa-archive"></i> Archive Record
            </button>
        </div>
    </div>
</div>

<script>
function showAlert(msg, type='success') {
    const b = document.getElementById('alertBox');
    b.className = 'alert alert-' + type + ' show';
    b.innerHTML = '<i class="fa fa-' + (type==='success'?'check-circle':'exclamation-triangle') + '"></i> ' + msg;
    window.scrollTo({top:0,behavior:'smooth'});
    setTimeout(() => { b.className='alert'; }, 6000);
}

// ── ACTION MODAL ────────────────────────────────────────────────────
let pendingActionId = null;

function openAction(id, facility, asset) {
    pendingActionId = id;
    document.getElementById('actionSubtitle').textContent =
        '#' + id + ' — ' + facility + ' · ' + asset;
    document.getElementById('actionType').value  = '';
    document.getElementById('actionNotes').value = '';
    document.getElementById('actionModal').classList.add('show');
}

function closeAction() {
    document.getElementById('actionModal').classList.remove('show');
    pendingActionId = null;
}

async function submitAction() {
    const action = document.getElementById('actionType').value;
    const notes  = document.getElementById('actionNotes').value.trim();
    if (!action) { alert('Please select an action.'); return; }
    if (!notes)  { alert('Notes are required.'); return; }

    const btn = document.getElementById('actionSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    fd.append('ajax_action',  '1');
    fd.append('invest_id',    pendingActionId);
    fd.append('action_type',  action);
    fd.append('action_notes', notes);

    try {
        const r  = await fetch(window.location.pathname, {method:'POST', body:fd});
        const js = await r.json();
        closeAction();
        if (js.success) {
            showAlert('Status updated to "' + js.new_status + '" successfully.');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            showAlert('Error: ' + (js.error || 'Update failed.'), 'danger');
        }
    } catch(ex) {
        showAlert('Network error: ' + ex.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Save Action';
    }
}

// Close action modal on overlay click
document.getElementById('actionModal').addEventListener('click', function(e) {
    if (e.target === this) closeAction();
});

// ── DELETE MODAL ────────────────────────────────────────────────────
let pendingDeleteId = null;

function confirmDelete(id, name) {
    pendingDeleteId = id;
    document.getElementById('deleteMsg').textContent =
        'Archive issuance record #' + id + ' for "' + name + '"?';
    document.getElementById('deleteModal').classList.add('show');
}

function closeDelete() {
    document.getElementById('deleteModal').classList.remove('show');
    pendingDeleteId = null;
}

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
    if (!pendingDeleteId) return;
    const id  = pendingDeleteId;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Archiving…';

    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('invest_id', id);

    try {
        const r  = await fetch(window.location.pathname, {method:'POST', body:fd});
        const js = await r.json();
        closeDelete();
        if (js.success) {
            const row = document.getElementById('row-' + id);
            if (row) row.remove();
            showAlert('Record #' + id + ' archived successfully.');
        } else {
            showAlert('Error: ' + (js.error || 'Archive failed.'), 'danger');
        }
    } catch(ex) {
        showAlert('Network error: ' + ex.message, 'danger');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-archive"></i> Archive Record';
    }
});

document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDelete();
});
</script>
</body>
</html>
