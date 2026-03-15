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

// ── Handle form submission ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate'])) {
    $asset_id    = (int)$_POST['asset_id'];
    $staff_id    = (int)$_POST['staff_id'];
    $alloc_date  = mysqli_real_escape_string($conn, $_POST['allocation_date']  ?? date('Y-m-d'));
    $return_date = !empty($_POST['expected_return_date']) ? mysqli_real_escape_string($conn, $_POST['expected_return_date']) : 'NULL';
    $remarks     = mysqli_real_escape_string($conn, $_POST['remarks'] ?? '');
    $by          = mysqli_real_escape_string($conn, $_SESSION['full_name'] ?? 'Admin');

    // Validate
    if (!$asset_id || !$staff_id) {
        $_SESSION['error_msg'] = "Please select both an asset and a staff member.";
        header('Location: allocate_asset.php');
        exit();
    }

    // Check asset is available
    $asset_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT a.*, ac.category_name FROM assets a
         JOIN asset_categories ac ON a.category_id = ac.category_id
         WHERE a.asset_id = $asset_id AND a.current_status = 'Available'"));
    if (!$asset_row) {
        $_SESSION['error_msg'] = "Selected asset is not available for allocation.";
        header('Location: allocate_asset.php');
        exit();
    }

    // Fetch staff snapshot
    $staff_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM county_staff WHERE staff_id = $staff_id"));
    if (!$staff_row) {
        $_SESSION['error_msg'] = "Staff member not found.";
        header('Location: allocate_asset.php');
        exit();
    }

    $sname    = mysqli_real_escape_string($conn, trim($staff_row['first_name'].' '.($staff_row['other_name'] ?? '').' '.$staff_row['last_name']));
    $id_num   = mysqli_real_escape_string($conn, $staff_row['id_number']);
    $facility = mysqli_real_escape_string($conn, $staff_row['facility_name']  ?? '');
    $dept     = mysqli_real_escape_string($conn, $staff_row['department_name'] ?? '');
    $cadre    = mysqli_real_escape_string($conn, $staff_row['cadre_name']      ?? '');
    $county   = mysqli_real_escape_string($conn, $staff_row['county_name']     ?? '');
    $subcnty  = mysqli_real_escape_string($conn, $staff_row['subcounty_name']  ?? '');
    $phone    = mysqli_real_escape_string($conn, $staff_row['staff_phone']     ?? '');

    $ret_sql = ($return_date === 'NULL') ? 'NULL' : "'$return_date'";

    mysqli_begin_transaction($conn);
    try {
        // Insert allocation
        $ins = "INSERT INTO asset_allocations
            (asset_id, staff_id, id_number, staff_name, facility_name, department_name,
             cadre_name, county_name, subcounty_name, staff_phone,
             allocation_date, expected_return_date, allocated_by, remarks)
            VALUES
            ($asset_id, $staff_id, '$id_num', '$sname', '$facility', '$dept',
             '$cadre', '$county', '$subcnty', '$phone',
             '$alloc_date', $ret_sql, '$by', '$remarks')";
        if (!mysqli_query($conn, $ins)) throw new Exception(mysqli_error($conn));

        // Mark asset as allocated
        if (!mysqli_query($conn, "UPDATE assets SET current_status='Allocated' WHERE asset_id=$asset_id"))
            throw new Exception(mysqli_error($conn));

        mysqli_commit($conn);
        $_SESSION['success_msg'] = "Asset <strong>{$asset_row['asset_name']}</strong> successfully allocated to <strong>{$sname}</strong>.";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['error_msg'] = "Error: ".$e->getMessage();
    }
    header('Location: allocate_asset.php');
    exit();
}

// ── AJAX: staff search ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_staff') {
    $q = mysqli_real_escape_string($conn, $_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT staff_id, first_name, other_name, last_name, id_number,
                    staff_phone, facility_name, department_name, cadre_name,
                    county_name, subcounty_name, employment_status
             FROM county_staff
             WHERE status='active'
               AND (first_name LIKE '%$q%' OR last_name LIKE '%$q%'
                    OR other_name LIKE '%$q%' OR id_number LIKE '%$q%'
                    OR staff_phone LIKE '%$q%')
             ORDER BY first_name, last_name LIMIT 15");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── AJAX: asset search ────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_asset') {
    $q = mysqli_real_escape_string($conn, $_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 1) {
        $res = mysqli_query($conn,
            "SELECT a.asset_id, a.asset_code, a.asset_name, a.make, a.model,
                    a.serial_number, a.condition_state, ac.category_name, ac.category_icon
             FROM assets a
             JOIN asset_categories ac ON a.category_id = ac.category_id
             WHERE a.current_status='Available'
               AND (a.asset_name LIKE '%$q%' OR a.asset_code LIKE '%$q%'
                    OR a.serial_number LIKE '%$q%' OR ac.category_name LIKE '%$q%'
                    OR a.make LIKE '%$q%' OR a.model LIKE '%$q%')
             ORDER BY ac.category_name, a.asset_name LIMIT 20");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── Available asset count ─────────────────────────────────────────────────────
$avail_count = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM assets WHERE current_status='Available'"))['c'];
$alloc_count = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM asset_allocations WHERE allocation_status='Active'"))['c'];
$total_assets = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) c FROM assets"))['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Allocate Asset</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:#f0f3fb; padding:20px; }

.page-header {
    background: linear-gradient(135deg,#0D1A63 0%,#1a3a8f 100%);
    color:#fff; padding:22px 30px; border-radius:14px;
    margin-bottom:24px; display:flex; justify-content:space-between; align-items:center;
    box-shadow:0 8px 24px rgba(13,26,99,.25);
}
.page-header h1 { font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
.page-header .hdr-links a {
    color:#fff; text-decoration:none; background:rgba(255,255,255,.15);
    padding:7px 14px; border-radius:8px; font-size:13px; margin-left:8px;
    transition:background .2s;
}
.page-header .hdr-links a:hover { background:rgba(255,255,255,.28); }

.stats-row { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
.stat-card {
    background:#fff; border-radius:12px; padding:18px 22px;
    box-shadow:0 2px 12px rgba(0,0,0,.06); border-left:5px solid #0D1A63;
    display:flex; align-items:center; gap:16px;
}
.stat-card .icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center;
    justify-content:center; font-size:20px; }
.stat-card h3 { font-size:26px; color:#0D1A63; font-weight:800; }
.stat-card p  { color:#888; font-size:12px; margin-top:2px; }

.alert { padding:13px 18px; border-radius:9px; margin-bottom:18px; font-size:14px;
    display:flex; align-items:center; gap:10px; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

.card { background:#fff; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.06); margin-bottom:24px; }
.card-head {
    background:linear-gradient(90deg,#0D1A63,#1a3a8f);
    color:#fff; padding:14px 22px; border-radius:14px 14px 0 0;
    font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px;
}
.card-body { padding:22px; }

/* ── Search + picker ── */
.search-wrap { position:relative; }
.search-wrap input {
    width:100%; padding:12px 42px 12px 16px;
    border:2px solid #e0e0e0; border-radius:9px; font-size:14px;
    transition:border-color .25s;
}
.search-wrap input:focus { outline:none; border-color:#0D1A63; box-shadow:0 0 0 3px rgba(13,26,99,.1); }
.search-wrap .search-icon {
    position:absolute; right:14px; top:50%; transform:translateY(-50%);
    color:#aaa; font-size:15px;
}
.search-wrap .spinner {
    position:absolute; right:14px; top:50%; transform:translateY(-50%);
    color:#0D1A63; font-size:14px; display:none;
}

.results-list {
    position:absolute; z-index:999; width:100%; background:#fff;
    border:1.5px solid #dce3f5; border-radius:10px; margin-top:4px;
    box-shadow:0 8px 28px rgba(13,26,99,.15); max-height:300px; overflow-y:auto; display:none;
}
.results-list .result-item {
    padding:11px 15px; cursor:pointer; border-bottom:1px solid #f0f0f0;
    transition:background .15s;
}
.results-list .result-item:last-child { border-bottom:none; }
.results-list .result-item:hover { background:#f0f3fb; }
.results-list .result-item .ri-name  { font-weight:700; color:#0D1A63; font-size:13.5px; }
.results-list .result-item .ri-meta  { font-size:11.5px; color:#777; margin-top:2px; }
.results-list .result-item .ri-badge {
    display:inline-block; font-size:10px; background:#e8edf8; color:#0D1A63;
    border-radius:4px; padding:1px 6px; margin-left:6px; font-weight:600;
}
.results-list .no-results { padding:14px 15px; color:#999; font-size:13px; text-align:center; }

/* ── Selected card ── */
.selected-card {
    border:2px solid #0D1A63; border-radius:11px; padding:14px 18px;
    background:linear-gradient(135deg,#f0f3fb,#fff); margin-top:10px; display:none;
}
.selected-card .sc-header { display:flex; justify-content:space-between; align-items:flex-start; }
.selected-card .sc-title { font-weight:700; color:#0D1A63; font-size:15px; }
.selected-card .sc-clear { color:#dc3545; cursor:pointer; font-size:13px; }
.selected-card .sc-grid {
    display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:10px;
}
.selected-card .sc-field label { font-size:10px; text-transform:uppercase; letter-spacing:.4px;
    color:#999; font-weight:600; display:block; }
.selected-card .sc-field span { font-size:13px; color:#333; font-weight:500; }

/* ── Form layout ── */
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.form-group { margin-bottom:0; }
.form-group.full { grid-column:1/-1; }
.form-group label { display:block; font-size:13px; color:#555; font-weight:600;
    margin-bottom:6px; }
.form-group label i.req { color:#dc3545; font-style:normal; }
.form-group input, .form-group select, .form-group textarea {
    width:100%; padding:11px 14px; border:2px solid #e0e0e0; border-radius:8px;
    font-size:13.5px; transition:border-color .25s; font-family:inherit;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline:none; border-color:#0D1A63; box-shadow:0 0 0 3px rgba(13,26,99,.1);
}

.btn { padding:11px 24px; border:none; border-radius:9px; font-size:14px; font-weight:600;
    cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .25s;
    text-decoration:none; }
.btn-primary { background:#0D1A63; color:#fff; }
.btn-primary:hover { background:#1a2a7a; transform:translateY(-1px);
    box-shadow:0 5px 16px rgba(13,26,99,.3); }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-secondary:hover { background:#5a6268; }

.divider { border:none; border-top:1px dashed #dce3f5; margin:20px 0; }
</style>
</head>
<body>

<div class="page-header">
    <h1><i class="fas fa-boxes"></i> Allocate Asset to Staff</h1>
    <div class="hdr-links">
        <a href="assets_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="add_asset.php"><i class="fas fa-plus"></i> Add Asset</a>
        
    </div>
</div>

<?php if ($msg):  ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $msg ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= $error ?></div><?php endif; ?>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card">
        <div class="icon" style="background:#e8f4fd;color:#0D1A63;"><i class="fas fa-boxes"></i></div>
        <div><h3><?= $total_assets ?></h3><p>Total Assets Registered</p></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:#d4edda;color:#28a745;"><i class="fas fa-check-circle"></i></div>
        <div><h3><?= $avail_count ?></h3><p>Available for Allocation</p></div>
    </div>
    <div class="stat-card">
        <div class="icon" style="background:#fff3cd;color:#856404;"><i class="fas fa-user-tag"></i></div>
        <div><h3><?= $alloc_count ?></h3><p>Currently Allocated</p></div>
    </div>
</div>

<form method="POST" id="allocForm">
<input type="hidden" name="asset_id"  id="h_asset_id">
<input type="hidden" name="staff_id"  id="h_staff_id">
<input type="hidden" name="allocate"  value="1">

<!-- STEP 1: Search Staff -->
<div class="card">
    <div class="card-head"><i class="fas fa-user-search"></i> Step 1 — Find Staff Member</div>
    <div class="card-body">
        <div class="search-wrap" id="staffSearchWrap">
            <input type="text" id="staffSearch"
                   placeholder="Type name, ID number or phone number to search..."
                   autocomplete="off">
            <i class="fas fa-search search-icon" id="staffSearchIcon"></i>
            <i class="fas fa-spinner fa-spin spinner" id="staffSpinner"></i>
            <div class="results-list" id="staffResults"></div>
        </div>

        <div class="selected-card" id="staffCard">
            <div class="sc-header">
                <div class="sc-title" id="sc_name"></div>
                <span class="sc-clear" onclick="clearStaff()"><i class="fas fa-times-circle"></i> Change</span>
            </div>
            <div class="sc-grid">
                <div class="sc-field"><label>ID Number</label><span id="sc_id"></span></div>
                <div class="sc-field"><label>Phone</label><span id="sc_phone"></span></div>
                <div class="sc-field"><label>Employment</label><span id="sc_emp"></span></div>
                <div class="sc-field"><label>Facility</label><span id="sc_facility"></span></div>
                <div class="sc-field"><label>Department</label><span id="sc_dept"></span></div>
                <div class="sc-field"><label>Cadre</label><span id="sc_cadre"></span></div>
                <div class="sc-field"><label>County</label><span id="sc_county"></span></div>
                <div class="sc-field"><label>Sub-County</label><span id="sc_sub"></span></div>
            </div>
        </div>
    </div>
</div>

<!-- STEP 2: Search Asset -->
<div class="card">
    <div class="card-head"><i class="fas fa-barcode"></i> Step 2 — Select Asset</div>
    <div class="card-body">
        <div class="search-wrap" id="assetSearchWrap">
            <input type="text" id="assetSearch"
                   placeholder="Search by asset name, code, serial number or category..."
                   autocomplete="off">
            <i class="fas fa-search search-icon" id="assetSearchIcon"></i>
            <i class="fas fa-spinner fa-spin spinner" id="assetSpinner"></i>
            <div class="results-list" id="assetResults"></div>
        </div>

        <div class="selected-card" id="assetCard">
            <div class="sc-header">
                <div class="sc-title" id="ac_name"></div>
                <span class="sc-clear" onclick="clearAsset()"><i class="fas fa-times-circle"></i> Change</span>
            </div>
            <div class="sc-grid">
                <div class="sc-field"><label>Asset Code</label><span id="ac_code"></span></div>
                <div class="sc-field"><label>Category</label><span id="ac_cat"></span></div>
                <div class="sc-field"><label>Condition</label><span id="ac_cond"></span></div>
                <div class="sc-field"><label>Make / Model</label><span id="ac_make"></span></div>
                <div class="sc-field"><label>Serial No</label><span id="ac_serial"></span></div>
            </div>
        </div>
    </div>
</div>

<!-- STEP 3: Allocation Details -->
<div class="card">
    <div class="card-head"><i class="fas fa-clipboard-list"></i> Step 3 — Allocation Details</div>
    <div class="card-body">
        <div class="form-grid">
            <div class="form-group">
                <label>Allocation Date <i class="req">*</i></label>
                <input type="date" name="allocation_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label>Expected Return Date <small style="color:#aaa;">(leave blank if permanent)</small></label>
                <input type="date" name="expected_return_date">
            </div>
            <div class="form-group full">
                <label>Remarks / Notes</label>
                <textarea name="remarks" rows="3"
                    placeholder="Any notes about this allocation (e.g. accessories included, specific purpose)..."></textarea>
            </div>
        </div>

        <hr class="divider">

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <a href="assets_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary" id="submitBtn" disabled>
                <i class="fas fa-check-circle"></i> Confirm Allocation
            </button>
        </div>
    </div>
</div>

</form>

<script>
// ── Debounce helper ──────────────────────────────────────────────────────────
function debounce(fn, delay) {
    let t;
    return function(...args) { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

// ── State ────────────────────────────────────────────────────────────────────
let selectedStaff = null;
let selectedAsset = null;

function checkSubmit() {
    document.getElementById('submitBtn').disabled = !(selectedStaff && selectedAsset);
}

// ── Staff search ─────────────────────────────────────────────────────────────
const staffInput   = document.getElementById('staffSearch');
const staffResults = document.getElementById('staffResults');
const staffSpinner = document.getElementById('staffSpinner');
const staffIcon    = document.getElementById('staffSearchIcon');

staffInput.addEventListener('input', debounce(async function() {
    const q = staffInput.value.trim();
    if (q.length < 2) { staffResults.style.display='none'; return; }
    staffSpinner.style.display='block'; staffIcon.style.display='none';
    const res = await fetch(`allocate_asset.php?ajax=search_staff&q=${encodeURIComponent(q)}`);
    const data = await res.json();
    staffSpinner.style.display='none'; staffIcon.style.display='block';
    renderStaffResults(data);
}, 350));

function renderStaffResults(rows) {
    if (!rows.length) {
        staffResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No active staff found</div>';
    } else {
        staffResults.innerHTML = rows.map(r => {
            const name = [r.first_name, r.other_name, r.last_name].filter(Boolean).join(' ');
            return `<div class="result-item" onclick='selectStaff(${JSON.stringify(r)})'>
                <div class="ri-name">${name} <span class="ri-badge">${r.id_number}</span></div>
                <div class="ri-meta">
                    <i class="fas fa-hospital" style="color:#0D1A63"></i> ${r.facility_name}
                    &nbsp;|&nbsp; <i class="fas fa-phone" style="color:#28a745"></i> ${r.staff_phone || '—'}
                    &nbsp;|&nbsp; ${r.county_name}
                </div>
            </div>`;
        }).join('');
    }
    staffResults.style.display='block';
}

function selectStaff(r) {
    selectedStaff = r;
    document.getElementById('h_staff_id').value = r.staff_id;
    const name = [r.first_name, r.other_name, r.last_name].filter(Boolean).join(' ');
    document.getElementById('sc_name').textContent    = name;
    document.getElementById('sc_id').textContent      = r.id_number;
    document.getElementById('sc_phone').textContent   = r.staff_phone   || '—';
    document.getElementById('sc_emp').textContent     = r.employment_status || '—';
    document.getElementById('sc_facility').textContent= r.facility_name || '—';
    document.getElementById('sc_dept').textContent    = r.department_name|| '—';
    document.getElementById('sc_cadre').textContent   = r.cadre_name    || '—';
    document.getElementById('sc_county').textContent  = r.county_name   || '—';
    document.getElementById('sc_sub').textContent     = r.subcounty_name|| '—';
    document.getElementById('staffCard').style.display='block';
    staffResults.style.display='none';
    staffInput.value = name;
    checkSubmit();
}

function clearStaff() {
    selectedStaff = null;
    document.getElementById('h_staff_id').value = '';
    document.getElementById('staffCard').style.display='none';
    staffInput.value = '';
    checkSubmit();
}

// ── Asset search ─────────────────────────────────────────────────────────────
const assetInput   = document.getElementById('assetSearch');
const assetResults = document.getElementById('assetResults');
const assetSpinner = document.getElementById('assetSpinner');
const assetIcon    = document.getElementById('assetSearchIcon');

assetInput.addEventListener('input', debounce(async function() {
    const q = assetInput.value.trim();
    if (q.length < 1) { assetResults.style.display='none'; return; }
    assetSpinner.style.display='block'; assetIcon.style.display='none';
    const res = await fetch(`allocate_asset.php?ajax=search_asset&q=${encodeURIComponent(q)}`);
    const data = await res.json();
    assetSpinner.style.display='none'; assetIcon.style.display='block';
    renderAssetResults(data);
}, 350));

function renderAssetResults(rows) {
    if (!rows.length) {
        assetResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No available assets found</div>';
    } else {
        assetResults.innerHTML = rows.map(r => `
            <div class="result-item" onclick='selectAsset(${JSON.stringify(r)})'>
                <div class="ri-name">
                    <i class="fas ${r.category_icon}" style="color:#0D1A63;margin-right:5px"></i>
                    ${r.asset_name}
                    <span class="ri-badge">${r.asset_code}</span>
                </div>
                <div class="ri-meta">
                    ${r.category_name}
                    ${r.make ? '&nbsp;|&nbsp; ' + r.make : ''}
                    ${r.model ? r.model : ''}
                    ${r.serial_number ? '&nbsp;|&nbsp; S/N: ' + r.serial_number : ''}
                    &nbsp;|&nbsp; Condition: <strong>${r.condition_state}</strong>
                </div>
            </div>`).join('');
    }
    assetResults.style.display='block';
}

function selectAsset(r) {
    selectedAsset = r;
    document.getElementById('h_asset_id').value = r.asset_id;
    document.getElementById('ac_name').innerHTML =
        `<i class="fas ${r.category_icon}" style="margin-right:6px"></i>${r.asset_name}`;
    document.getElementById('ac_code').textContent   = r.asset_code;
    document.getElementById('ac_cat').textContent    = r.category_name;
    document.getElementById('ac_cond').textContent   = r.condition_state;
    document.getElementById('ac_make').textContent   = [r.make, r.model].filter(Boolean).join(' / ') || '—';
    document.getElementById('ac_serial').textContent = r.serial_number || '—';
    document.getElementById('assetCard').style.display='block';
    assetResults.style.display='none';
    assetInput.value = r.asset_name + ' (' + r.asset_code + ')';
    checkSubmit();
}

function clearAsset() {
    selectedAsset = null;
    document.getElementById('h_asset_id').value = '';
    document.getElementById('assetCard').style.display='none';
    assetInput.value = '';
    checkSubmit();
}

// ── Close dropdowns on outside click ─────────────────────────────────────────
document.addEventListener('click', function(e) {
    if (!e.target.closest('#staffSearchWrap'))  staffResults.style.display='none';
    if (!e.target.closest('#assetSearchWrap'))  assetResults.style.display='none';
});

// ── Confirm submission ────────────────────────────────────────────────────────
document.getElementById('allocForm').addEventListener('submit', function(e) {
    if (!selectedStaff || !selectedAsset) {
        e.preventDefault();
        alert('Please select both a staff member and an asset before submitting.');
        return;
    }
    const name  = [selectedStaff.first_name, selectedStaff.other_name, selectedStaff.last_name].filter(Boolean).join(' ');
    if (!confirm(`Allocate "${selectedAsset.asset_name}" to ${name}?\n\nThis will mark the asset as Allocated.`)) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
