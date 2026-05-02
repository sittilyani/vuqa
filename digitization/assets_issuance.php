<?php
// digitization/assets_issuance.php — Assets Issuance Register

// ── AJAX: buffer ALL output immediately so PHP warnings never corrupt JSON ──
$_IS_AJAX = (isset($_GET['ajax']) || isset($_POST['ajax_save']) || isset($_POST['ajax_delete']));
if ($_IS_AJAX) {
    ob_start();
    ini_set('display_errors', 0);   // suppress HTML error output for AJAX
    error_reporting(0);
}

session_start();

$base_path   = dirname(__DIR__);
$config_path = $base_path . '/includes/config.php';
$sess_check  = $base_path . '/includes/session_check.php';

if (!file_exists($config_path)) {
    if ($_IS_AJAX) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Config missing']); exit(); }
    die('Configuration file not found.');
}
include $config_path;
include $sess_check;

if (!isset($conn) || !$conn) {
    if ($_IS_AJAX) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'DB connection failed']); exit(); }
    die('Database connection failed.');
}
if (!isset($_SESSION['user_id'])) {
    if ($_IS_AJAX) { ob_end_clean(); header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Not authenticated']); exit(); }
    header('Location: ../login.php'); exit();
}

$created_by = $_SESSION['full_name'] ?? '';
$this_file  = basename(__FILE__);

// ── HELPERS ─────────────────────────────────────────────────────────────────
$e = fn($v) => mysqli_real_escape_string($conn, trim((string)($v ?? '')));
$f = fn($v) => is_numeric($v) ? (float)$v : 'NULL';
$i = fn($v) => is_numeric($v) ? (int)$v   : 'NULL';

// Helper: flush AJAX buffer and output clean JSON
function ajax_json(array $payload): void {
    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

// ── Auto-recalc current_value ───────────────────────────────────────────────
$_air_table = 'assets_issuance_register';
$_tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'assets_issuance_register'");
if (!$_tbl_check || mysqli_num_rows($_tbl_check) === 0) {
    $_air_table = 'digital_innovation_investments';
}

mysqli_query($conn, "
    UPDATE `$_air_table`
    SET    current_value = GREATEST(0,
               purchase_value * POW(1 - (depreciation_percentage / 100),
               TIMESTAMPDIFF(MONTH, issue_date, NOW()) / 12)),
           updated_at = NOW()
    WHERE  invest_status = 'Active'
      AND  (no_end_date = 1 OR end_date IS NULL OR end_date >= CURDATE())
      AND  issue_date IS NOT NULL
      AND  depreciation_percentage > 0
");

// ── AJAX: facility search ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_facility') {
    $q = $e($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT facility_id, facility_name, mflcode, county_name, subcounty_name,
                    latitude, longitude
             FROM facilities
             WHERE (facility_name LIKE '%$q%' OR mflcode LIKE '%$q%' OR county_name LIKE '%$q%')
             ORDER BY facility_name LIMIT 25");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    ajax_json($rows);
}

// ── AJAX: search asset master register ──────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_asset') {
    $q = $e($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT asset_id, asset_category, description, model, serial_number,
                    purchase_value, depreciation_percentage,
                    dig_funder_name, project_name, lpo_number, current_condition
             FROM asset_master_register
             WHERE is_active = 1
               AND (description LIKE '%$q%' OR serial_number LIKE '%$q%'
                 OR model LIKE '%$q%' OR asset_category LIKE '%$q%'
                 OR lpo_number LIKE '%$q%')
             ORDER BY description LIMIT 30");
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    ajax_json($rows);
}

// ── AJAX: get single asset by ID ─────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_asset') {
    $aid = $i($_GET['asset_id'] ?? 0);
    $res = mysqli_query($conn, "SELECT * FROM asset_master_register WHERE asset_id = $aid LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    ajax_json($row ?: []);
}

// ── AJAX: save issuance record ───────────────────────────────────────────────
if (isset($_POST['ajax_save'])) {
    try {
        $invest_id_edit = (isset($_POST['invest_id']) && is_numeric($_POST['invest_id'])) ? (int)$_POST['invest_id'] : 0;
        $asset_id       = $i($_POST['asset_id'] ?? 0);
        $facility_id    = $i($_POST['facility_id'] ?? 0);
        $facility_name  = $e($_POST['facility_name'] ?? '');
        $mflcode        = $e($_POST['mflcode'] ?? '');
        $county_name    = $e($_POST['county_name'] ?? '');
        $subcounty_name = $e($_POST['subcounty_name'] ?? '');
        $latitude       = $f($_POST['latitude'] ?? 'NULL');
        $longitude      = $f($_POST['longitude'] ?? 'NULL');
        $tag_name       = $e($_POST['tag_name'] ?? '');
        $emr_type_id    = (empty($_POST['emr_type_id']) || $_POST['emr_type_id'] == '0') ? 'NULL' : (int)$_POST['emr_type_id'];
        $service_level  = $e($_POST['service_level'] ?? 'Facility-wide');
        $lot_number     = $e($_POST['lot_number'] ?? '');
        $name_of_user   = $e($_POST['name_of_user'] ?? '');
        $department_id  = (empty($_POST['department_id']) || $_POST['department_id'] == '0') ? 'NULL' : (int)$_POST['department_id'];
        $department_name = $e($_POST['department_name'] ?? '');
        $issue_date     = $e($_POST['issue_date'] ?? '');
        $no_end_date    = (int)!empty($_POST['no_end_date']);
        $end_date_raw   = $e($_POST['end_date'] ?? '');
        $dig_funder_id  = (empty($_POST['dig_funder_id']) || $_POST['dig_funder_id'] == '0') ? 'NULL' : (int)$_POST['dig_funder_id'];
        $date_of_verification = $e($_POST['date_of_verification'] ?? '');
        $date_of_disposal     = $e($_POST['date_of_disposal']     ?? '');

        if (!$facility_id || !$asset_id || !$tag_name || !$issue_date) {
            ajax_json(['success' => false, 'error' => 'Required fields missing.']);
        }

        $amr_res = mysqli_query($conn, "SELECT description, depreciation_percentage, purchase_value FROM asset_master_register WHERE asset_id = $asset_id LIMIT 1");
        $amr = mysqli_fetch_assoc($amr_res);
        if (!$amr) { ajax_json(['success' => false, 'error' => 'Asset not found.']); }

        $asset_desc     = $e($amr['description']);
        $dep_pct        = (float)$amr['depreciation_percentage'];
        $purchase_value = (float)$amr['purchase_value'];

        $mq  = mysqli_query($conn, "SELECT TIMESTAMPDIFF(MONTH, '$issue_date', NOW()) AS m");
        $mr  = mysqli_fetch_assoc($mq);
        $months = max(0, (int)($mr['m'] ?? 0));
        $current_value = max(0, round($purchase_value * pow(1 - ($dep_pct / 100), $months / 12), 2));

        $ed_val  = ($no_end_date || !$end_date_raw) ? "NULL" : "'$end_date_raw'";
        $dov_val = $date_of_verification ? "'$date_of_verification'" : "NULL";
        $dod_val = $date_of_disposal ? "'$date_of_disposal'" : "NULL";
        $is_e    = (!$no_end_date && $end_date_raw && strtotime($end_date_raw) < time()) ? 'Expired' : 'Active';

        if ($invest_id_edit <= 0) {
            $sql = "INSERT INTO `$_air_table`
                    (asset_id, facility_id, facility_name, mflcode, county_name, subcounty_name, latitude, longitude, asset_name, tag_name, quantity, total_cost, depreciation_percentage, purchase_value, current_value, issue_date, end_date, no_end_date, dig_funder_id, emr_type_id, service_level, lot_number, invest_status, name_of_user, department_id, department_name, date_of_verification, date_of_disposal, created_by, created_at, updated_at)
                    VALUES ($asset_id, $facility_id, '$facility_name', '$mflcode', '$county_name', '$subcounty_name', $latitude, $longitude, '$asset_desc', '$tag_name', 1, $purchase_value, $dep_pct, $purchase_value, $current_value, '$issue_date', $ed_val, $no_end_date, $dig_funder_id, $emr_type_id, '$service_level', '$lot_number', '$is_e', '$name_of_user', $department_id, '$department_name', $dov_val, $dod_val, '$created_by', NOW(), NOW())";
        } else {
            $sql = "UPDATE `$_air_table` SET
                    asset_id=$asset_id, facility_id=$facility_id, facility_name='$facility_name', mflcode='$mflcode', county_name='$county_name', subcounty_name='$subcounty_name', latitude=$latitude, longitude=$longitude, asset_name='$asset_desc', tag_name='$tag_name', depreciation_percentage=$dep_pct, purchase_value=$purchase_value, current_value=$current_value, issue_date='$issue_date', end_date=$ed_val, no_end_date=$no_end_date, dig_funder_id=$dig_funder_id, emr_type_id=$emr_type_id, service_level='$service_level', lot_number='$lot_number', invest_status='$is_e', name_of_user='$name_of_user', department_id=$department_id, department_name='$department_name', date_of_verification=$dov_val, date_of_disposal=$dod_val, updated_at=NOW()
                    WHERE invest_id=$invest_id_edit";
        }

        if (mysqli_query($conn, $sql)) {
            $final_id = ($invest_id_edit > 0) ? $invest_id_edit : mysqli_insert_id($conn);
            ajax_json(['success' => true, 'invest_id' => $final_id, 'action' => ($invest_id_edit > 0 ? 'update' : 'insert')]);
        } else {
            ajax_json(['success' => false, 'error' => 'DB Error: ' . mysqli_error($conn)]);
        }
    } catch (Exception $ex) {
        ajax_json(['success' => false, 'error' => $ex->getMessage()]);
    }
}

// ── AJAX: delete (soft) ──────────────────────────────────────────────────────
if (isset($_POST['ajax_delete'])) {
    $iid = $i($_POST['invest_id'] ?? 0);
    if ($iid > 0) {
        $ok = mysqli_query($conn, "UPDATE `$_air_table` SET invest_status='Expired', updated_at=NOW() WHERE invest_id=$iid");
        ajax_json($ok ? ['success' => true] : ['success' => false, 'error' => mysqli_error($conn)]);
    }
    ajax_json(['success' => false, 'error' => 'Invalid ID']);
}

// ── Load dropdowns ────────────────────────────────────────────────────────────
$depts_arr = [];
$dr = mysqli_query($conn, "SELECT department_id, department_name FROM departments WHERE is_active=1 ORDER BY department_name");
if ($dr) while ($r = mysqli_fetch_assoc($dr)) $depts_arr[] = $r;

$emr_arr = [];
$emr_r = mysqli_query($conn, "SELECT emr_type_id, emr_type_name FROM emr_types ORDER BY emr_type_name");
if ($emr_r) while ($r = mysqli_fetch_assoc($emr_r)) $emr_arr[] = $r;

$funders_arr = [];
$fr = mysqli_query($conn, "SELECT dig_funder_id, dig_funder_name FROM digital_funders ORDER BY dig_funder_name");
if ($fr) while ($r = mysqli_fetch_assoc($fr)) $funders_arr[] = $r;

$edit_row = null;
$edit_asset = null;
if (isset($_GET['edit'])) {
    $eid = $i($_GET['edit']);
    $er  = mysqli_query($conn, "SELECT * FROM `$_air_table` WHERE invest_id=$eid LIMIT 1");
    $edit_row = $er ? mysqli_fetch_assoc($er) : null;
    if ($edit_row && $edit_row['asset_id']) {
        $ar = mysqli_query($conn, "SELECT * FROM asset_master_register WHERE asset_id={$edit_row['asset_id']} LIMIT 1");
        $edit_asset = $ar ? mysqli_fetch_assoc($ar) : null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assets Issuance – LVCT Health</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --primary:#2D008A; --primary-dark:#1e005e; --primary-light:#AC80EE;
    --success:#04B04B; --warning:#FFC12E; --danger:#E41E39;
    --bg:#FDFCF9; --card:#fff; --border:#e0d9f0;
    --text:#1a1a2e; --muted:#6c757d;
    --radius:10px; --shadow:0 2px 12px rgba(45,0,138,.09);
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
.topbar{background:var(--primary);color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.18)}
.topbar h1{font-size:1.15rem;font-weight:700;letter-spacing:.3px}
.topbar .actions a{color:#fff;text-decoration:none;margin-left:14px;font-size:.87rem;opacity:.88;transition:opacity .2s}
.topbar .actions a:hover{opacity:1}
.container{max-width:1200px;margin:28px auto;padding:0 18px}
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border);overflow:hidden}
.card-header{background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;padding:16px 22px;display:flex;align-items:center;gap:10px}
.card-header h2{font-size:1.05rem;font-weight:600}
.card-body{padding:24px}
.section-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--primary);border-bottom:2px solid var(--border);padding-bottom:6px;margin:18px 0 14px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
@media(max-width:768px){.grid-2,.grid-3{grid-template-columns:1fr}}
.form-group{display:flex;flex-direction:column;gap:5px}
label{font-size:.82rem;font-weight:600;color:var(--primary)}
label .req{color:var(--danger);margin-left:2px}
input,select,textarea{border:1.5px solid var(--border);border-radius:7px;padding:9px 12px;font-size:.9rem;color:var(--text);background:#fff;transition:border .2s,box-shadow .2s;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(45,0,138,.1)}
input[readonly]{background:#f5f3fb;color:var(--muted);cursor:not-allowed}
.search-wrap{position:relative}
.dropdown-list{position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid var(--primary);border-radius:0 0 8px 8px;max-height:240px;overflow-y:auto;z-index:999;display:none;box-shadow:0 4px 16px rgba(45,0,138,.12)}
.dropdown-list.show{display:block}
.dl-item{padding:9px 14px;cursor:pointer;font-size:.87rem;border-bottom:1px solid #f0ebff}
.dl-item:hover{background:#f5f3fb}
.dl-item small{color:var(--muted);display:block;font-size:.78rem}
.asset-preview{background:#f5f3fb;border:1.5px solid var(--border);border-radius:8px;padding:14px 16px;margin-top:8px;display:none}
.asset-preview.show{display:block}
.ap-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px}
.ap-item{font-size:.8rem;color:var(--muted)}
.ap-item strong{color:var(--text);display:block;font-size:.88rem}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:8px;border:none;font-size:.9rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none}
.btn-primary{background:var(--primary);color:#fff}
.btn-outline{background:transparent;border:1.5px solid var(--primary);color:var(--primary)}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.9rem;display:none}
.alert.show{display:flex;align-items:center;gap:10px}
.alert-success{background:#d4f7e6;border:1px solid #04B04B;color:#056629}
.alert-danger{background:#fde8eb;border:1px solid var(--danger);color:#9b0d20}
.spinner{display:none;width:18px;height:18px;border:3px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
.spinner.show{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
</style>
</head>
<body>

<div class="topbar">
    <h1><i class="fa fa-tags"></i> Assets Issuance Register</h1>
    <div class="actions">
        <a href="view_asset_issues.php"><i class="fa fa-list"></i> View All</a>
        <a href="asset_master_register.php"><i class="fa fa-database"></i> Master</a>
        <a href="../index.php"><i class="fa fa-home"></i> Home</a>
    </div>
</div>

<div class="container">
    <div id="alertBox" class="alert"></div>

    <div class="card">
        <div class="card-header">
            <i class="fa fa-file-signature fa-lg"></i>
            <h2><?= $edit_row ? 'Edit Issuance Record' : 'New Asset Issuance' ?></h2>
        </div>
        <div class="card-body">
        <form id="issuanceForm">
            <input type="hidden" name="ajax_save" value="1">
            <input type="hidden" name="invest_id" id="invest_id" value="<?= $edit_row ? (int)$edit_row['invest_id'] : 0 ?>">
            <input type="hidden" name="facility_id" id="facility_id" value="<?= $edit_row['facility_id'] ?? '' ?>">
            <input type="hidden" name="asset_id" id="asset_id" value="<?= $edit_row['asset_id'] ?? '' ?>">
            <input type="hidden" name="latitude" id="latitude" value="<?= $edit_row['latitude'] ?? '' ?>">
            <input type="hidden" name="longitude" id="longitude" value="<?= $edit_row['longitude'] ?? '' ?>">

            <div class="section-title">Facility Details</div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Facility Name <span class="req">*</span></label>
                    <div class="search-wrap">
                        <input type="text" id="facilitySearch" name="facility_name" placeholder="Search facility..." value="<?= htmlspecialchars($edit_row['facility_name'] ?? '') ?>" autocomplete="off">
                        <div class="dropdown-list" id="facilityList"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>MFL Code</label>
                    <input type="text" id="mflcode" name="mflcode" readonly value="<?= htmlspecialchars($edit_row['mflcode'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>County</label>
                    <input type="text" id="county_name" name="county_name" readonly value="<?= htmlspecialchars($edit_row['county_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Sub-County</label>
                    <input type="text" id="subcounty_name" name="subcounty_name" readonly value="<?= htmlspecialchars($edit_row['subcounty_name'] ?? '') ?>">
                </div>
            </div>

            <div class="section-title">Asset Selection</div>
            <div class="form-group">
                <label>Search Asset <span class="req">*</span></label>
                <div class="search-wrap">
                    <input type="text" id="assetSearch" placeholder="Search by name, SN, or model..." value="<?= $edit_asset['description'] ?? '' ?>" autocomplete="off">
                    <div class="dropdown-list" id="assetList"></div>
                </div>
            </div>

            <div class="asset-preview <?= $edit_asset ? 'show' : '' ?>" id="assetPreview">
                <div class="ap-grid">
                    <div class="ap-item"><span>Category</span><strong id="prev_cat"><?= $edit_asset['asset_category'] ?? '—' ?></strong></div>
                    <div class="ap-item"><span>Serial #</span><strong id="prev_serial"><?= $edit_asset['serial_number'] ?? '—' ?></strong></div>
                    <div class="ap-item"><span>Purchase Value</span><strong id="prev_pv"><?= isset($edit_asset['purchase_value']) ? number_format($edit_asset['purchase_value'], 2) : '—' ?></strong></div>
                </div>
            </div>

            <div class="section-title">Issuance Specifics</div>
            <div class="grid-3">
                <div class="form-group">
                    <label>Tag / Label <span class="req">*</span></label>
                    <input type="text" name="tag_name" id="tag_name" required value="<?= htmlspecialchars($edit_row['tag_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Issue Date <span class="req">*</span></label>
                    <input type="date" name="issue_date" id="issue_date" required value="<?= htmlspecialchars($edit_row['issue_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="form-group">
                    <label>EMR Type</label>
                    <select name="emr_type_id">
                        <option value="0">— Select —</option>
                        <?php foreach ($emr_arr as $em): ?>
                        <option value="<?= $em['emr_type_id'] ?>" <?= ($edit_row['emr_type_id'] == $em['emr_type_id']) ? 'selected' : '' ?>><?= $em['emr_type_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid-2" style="margin-top:16px">
                <div class="form-group">
                    <label>Assigned User <span class="req">*</span></label>
                    <input type="text" name="name_of_user" required value="<?= htmlspecialchars($edit_row['name_of_user'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Department <span class="req">*</span></label>
                    <select name="department_id" id="department_id" required>
                        <option value="0">— Select —</option>
                        <?php foreach ($depts_arr as $dep): ?>
                        <option value="<?= $dep['department_id'] ?>" data-name="<?= $dep['department_name'] ?>" <?= ($edit_row['department_id'] == $dep['department_id']) ? 'selected' : '' ?>><?= $dep['department_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="department_name" id="department_name" value="<?= $edit_row['department_name'] ?? '' ?>">
                </div>
            </div>

            <div style="margin-top:24px">
                <button type="submit" class="btn btn-primary" id="saveBtn">
                    <i class="fa fa-save"></i> Save Issuance <span class="spinner" id="saveSpinner"></span>
                </button>
                <a href="view_asset_issues.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
        </div>
    </div>
</div>

<script>
function showAlert(msg, type='success') {
    const b = document.getElementById('alertBox');
    b.className = 'alert alert-' + type + ' show';
    b.innerHTML = msg;
    window.scrollTo({top:0, behavior:'smooth'});
    setTimeout(() => { b.className='alert'; }, 5000);
}

// Search Logic (Facility)
let fTimer;
const fSearch = document.getElementById('facilitySearch');
const fList = document.getElementById('facilityList');
fSearch.addEventListener('input', () => {
    clearTimeout(fTimer);
    const q = fSearch.value.trim();
    if (q.length < 2) { fList.classList.remove('show'); return; }
    fTimer = setTimeout(async () => {
        const r = await fetch('?ajax=search_facility&q=' + encodeURIComponent(q));
        const data = await r.json();
        fList.innerHTML = '';
        data.forEach(f => {
            const d = document.createElement('div');
            d.className = 'dl-item';
            d.innerHTML = `<b>${f.facility_name}</b> <small>${f.mflcode}</small>`;
            d.onclick = () => {
                fSearch.value = f.facility_name;
                document.getElementById('facility_id').value = f.facility_id;
                document.getElementById('mflcode').value = f.mflcode;
                document.getElementById('county_name').value = f.county_name;
                document.getElementById('subcounty_name').value = f.subcounty_name;
                fList.classList.remove('show');
            };
            fList.appendChild(d);
        });
        fList.classList.add('show');
    }, 300);
});

// Search Logic (Asset)
let aTimer;
const aSearch = document.getElementById('assetSearch');
const aList = document.getElementById('assetList');
aSearch.addEventListener('input', () => {
    clearTimeout(aTimer);
    const q = aSearch.value.trim();
    if (q.length < 2) { aList.classList.remove('show'); return; }
    aTimer = setTimeout(async () => {
        const r = await fetch('?ajax=search_asset&q=' + encodeURIComponent(q));
        const data = await r.json();
        aList.innerHTML = '';
        data.forEach(a => {
            const d = document.createElement('div');
            d.className = 'dl-item';
            d.innerHTML = `<b>${a.description}</b> <small>SN: ${a.serial_number}</small>`;
            d.onclick = () => {
                aSearch.value = a.description;
                document.getElementById('asset_id').value = a.asset_id;
                document.getElementById('prev_cat').textContent = a.asset_category;
                document.getElementById('prev_serial').textContent = a.serial_number;
                document.getElementById('prev_pv').textContent = parseFloat(a.purchase_value).toLocaleString();
                document.getElementById('assetPreview').classList.add('show');
                aList.classList.remove('show');
            };
            aList.appendChild(d);
        });
        aList.classList.add('show');
    }, 300);
});

document.getElementById('department_id').onchange = function() {
    document.getElementById('department_name').value = this.options[this.selectedIndex].text;
};

// Form Submit
document.getElementById('issuanceForm').onsubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveBtn');
    const spin = document.getElementById('saveSpinner');
    btn.disabled = true; spin.classList.add('show');

    try {
        const fd = new FormData(this);
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const text = await r.text();
        let js;
        try { js = JSON.parse(text); } catch(e) { throw new Error("Invalid Server Response"); }

        if (js.success) {
            showAlert('Saved successfully! Redirecting...', 'success');
            setTimeout(() => location.href = 'view_asset_issues.php', 1500);
        } else {
            showAlert(js.error || 'Unknown error', 'danger');
        }
    } catch(err) {
        showAlert(err.message, 'danger');
    } finally {
        btn.disabled = false; spin.classList.remove('show');
    }
};
</script>
</body>
</html>