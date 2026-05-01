<?php
// digitization/asset_master_register.php
session_start();

$base_path   = dirname(__DIR__);
$config_path = $base_path . '/includes/config.php';
$sess_check  = $base_path . '/includes/session_check.php';

if (!file_exists($config_path)) die('Configuration file not found.');
include $config_path;
include $sess_check;

if (!isset($conn) || !$conn) die('Database connection failed.');
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

$created_by = $_SESSION['full_name'] ?? '';
$this_file  = basename(__FILE__);

// ── HELPERS ────────────────────────────────────────────────────────────
$e = fn($v) => mysqli_real_escape_string($conn, trim((string)($v ?? '')));
$f = fn($v) => is_numeric($v) ? (float)$v : 'NULL';
$i = fn($v) => is_numeric($v) ? (int)$v   : 'NULL';

// Auto-recalculate current_value for all active assets
mysqli_query($conn, "
    UPDATE asset_master_register
    SET    current_value = GREATEST(0,
             purchase_value * POW(
               1 - (depreciation_percentage / 100),
               TIMESTAMPDIFF(MONTH, date_of_acquisition, NOW()) / 12
             )
           ),
           updated_at = NOW()
    WHERE  is_active = 1
      AND  date_of_acquisition IS NOT NULL
      AND  depreciation_percentage > 0
");

// ── AJAX: get asset details by ID ───────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_asset') {
    $aid = $i($_GET['asset_id'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM asset_master_register WHERE asset_id = $aid LIMIT 1"));
    header('Content-Type: application/json');
    echo json_encode($row ?: []);
    exit();
}

// ── AJAX: search assets ─────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    $q    = $e($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT asset_id, asset_name, asset_category, model, serial_number,
                    purchase_value, depreciation_percentage, dig_funder_name, lpo_number
             FROM asset_master_register
             WHERE is_active = 1
               AND (asset_name LIKE '%$q%' OR serial_number LIKE '%$q%'
                 OR model LIKE '%$q%' OR asset_category LIKE '%$q%'
                 OR lpo_number LIKE '%$q%')
             ORDER BY asset_name LIMIT 30");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── AJAX: save (insert/update) ──────────────────────────────────────────
if (isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $asset_id       = $i($_POST['asset_id']            ?? 0);
    $asset_name     = $e($_POST['asset_name']          ?? '');
    $asset_category = $e($_POST['asset_category']      ?? '');
    $description    = $e($_POST['description']         ?? '');
    $model          = $e($_POST['model']               ?? '');
    $serial_number  = $e($_POST['serial_number']       ?? '');
    $date_acq       = $e($_POST['date_of_acquisition'] ?? '');
    $age_acq        = $f($_POST['age_at_acquisition']  ?? 'NULL');
    $pv             = $f($_POST['purchase_value']      ?? 0);
    $dep_pct        = $f($_POST['depreciation_percentage'] ?? 0);
    $lpo_number     = $e($_POST['lpo_number']          ?? '');
    $funder_name    = $e($_POST['dig_funder_name']     ?? '');
    $project_name   = $e($_POST['project_name']        ?? '');
    $acq_type       = $e($_POST['acquisition_type']    ?? '');
    $condition      = $e($_POST['current_condition']   ?? 'Good');
    $date_disposal  = $e($_POST['date_of_disposal']    ?? '');
    $comments       = $e($_POST['comments']            ?? '');

    if (!$asset_name || $pv === 'NULL') {
        echo json_encode(['success' => false, 'error' => 'Asset name and purchase value are required.']);
        exit();
    }

    // Calculate current value
    $cv = 0;
    if ($date_acq && $dep_pct !== 'NULL' && $pv !== 'NULL') {
        $diff = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT TIMESTAMPDIFF(MONTH, '$date_acq', NOW()) AS m"));
        $months = max(0, (int)($diff['m'] ?? 0));
        $cv = round($pv * pow(1 - ($dep_pct / 100), $months / 12), 2);
        if ($cv < 0) $cv = 0;
    }

    $age_val       = ($age_acq === 'NULL' || $age_acq === '') ? 'NULL' : $age_acq;
    $date_acq_val  = $date_acq  ? "'$date_acq'"  : 'NULL';
    $disp_val      = $date_disposal ? "'$date_disposal'" : 'NULL';
    $cb            = $e($created_by);

    if ($asset_id === 'NULL' || $asset_id == 0) {
        $sql = "INSERT INTO asset_master_register
                  (asset_name, asset_category, description, model, serial_number,
                   date_of_acquisition, age_at_acquisition, purchase_value,
                   depreciation_percentage, current_value, lpo_number,
                   dig_funder_name, project_name, acquisition_type,
                   current_condition, date_of_disposal, comments, is_active,
                   created_by, created_at, updated_at)
                VALUES
                  ('$asset_name','$asset_category','$description','$model','$serial_number',
                   $date_acq_val, $age_val, $pv,
                   $dep_pct, $cv, '$lpo_number',
                   '$funder_name','$project_name','$acq_type',
                   '$condition', $disp_val, '$comments', 1,
                   '$cb', NOW(), NOW())";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(['success' => true, 'asset_id' => mysqli_insert_id($conn), 'action' => 'insert']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    } else {
        $sql = "UPDATE asset_master_register SET
                  asset_name='$asset_name', asset_category='$asset_category',
                  description='$description', model='$model', serial_number='$serial_number',
                  date_of_acquisition=$date_acq_val, age_at_acquisition=$age_val,
                  purchase_value=$pv, depreciation_percentage=$dep_pct, current_value=$cv,
                  lpo_number='$lpo_number', dig_funder_name='$funder_name',
                  project_name='$project_name', acquisition_type='$acq_type',
                  current_condition='$condition', date_of_disposal=$disp_val,
                  comments='$comments', updated_at=NOW()
                WHERE asset_id=$asset_id";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(['success' => true, 'asset_id' => $asset_id, 'action' => 'update']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    }
    exit();
}

// ── AJAX: delete ────────────────────────────────────────────────────────
if (isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    $aid = $i($_POST['asset_id'] ?? 0);
    if ($aid > 0 && $aid !== 'NULL') {
        // Soft-delete: just mark inactive
        if (mysqli_query($conn, "UPDATE asset_master_register SET is_active=0, updated_at=NOW() WHERE asset_id=$aid")) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    exit();
}

// ── AJAX: Excel/CSV import ──────────────────────────────────────────────
if (isset($_POST['ajax_import'])) {
    // Raise limits BEFORE loading anything — critical for large Excel files
    @ini_set('memory_limit', '512M');
    @ini_set('max_execution_time', '300');
    @set_time_limit(300);

    header('Content-Type: application/json');

    if (empty($_FILES['import_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
        exit();
    }

    $filename  = $_FILES['import_file']['name'];
    $tmp_path  = $_FILES['import_file']['tmp_name'];
    $ext       = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $raw_headers = [];
    $data_rows   = [];
    $xl_loaded   = false;   // true when PhpSpreadsheet autoload is available

    // ── Excel import via PhpSpreadsheet ──────────────────────────────────
    if (in_array($ext, ['xlsx', 'xls'])) {
        $autoload = $base_path . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            echo json_encode(['success' => false,
                'error' => 'PhpSpreadsheet library not found. Run: composer require phpoffice/phpspreadsheet']);
            exit();
        }
        require_once $autoload;
        $xl_loaded = true;

        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp_path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tmp_path);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows_raw    = $sheet->toArray(null, true, true, false);
            // Free memory as early as possible
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } catch (\Throwable $ex) {
            echo json_encode(['success' => false,
                'error' => 'Cannot read Excel file: ' . $ex->getMessage()]);
            exit();
        }

        if (empty($rows_raw)) {
            echo json_encode(['success' => false, 'error' => 'Excel file is empty.']);
            exit();
        }

        // Normalise headers (strip \r \n, lowercase, trim)
        $raw_headers = array_map(
            fn($h) => strtolower(trim(str_replace(["\r", "\n", "\xEF\xBB\xBF"], '', (string)($h ?? '')))),
            $rows_raw[0]
        );
        $data_rows = array_slice($rows_raw, 1);
        unset($rows_raw);

    // ── CSV import ────────────────────────────────────────────────────────
    } elseif ($ext === 'csv') {
        $handle = fopen($tmp_path, 'r');
        if (!$handle) {
            echo json_encode(['success' => false, 'error' => 'Cannot open CSV file.']);
            exit();
        }
        // Strip UTF-8 BOM that Excel sometimes adds to the first cell
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle); // not a BOM — rewind

        $first_line = fgetcsv($handle);
        if (!$first_line) {
            fclose($handle);
            echo json_encode(['success' => false, 'error' => 'CSV file is empty.']);
            exit();
        }
        $raw_headers = array_map(
            fn($h) => strtolower(trim(str_replace(["\r", "\n", "\xEF\xBB\xBF"], '', (string)($h ?? '')))),
            $first_line
        );
        $data_rows = [];
        while (($row = fgetcsv($handle)) !== false) $data_rows[] = $row;
        fclose($handle);

    } else {
        echo json_encode(['success' => false,
            'error' => 'Unsupported file type ".' . $ext . '". Please upload .xlsx, .xls, or .csv']);
        exit();
    }

    // Remove empty header slots (trailing blank columns from Excel)
    $hdr_count   = count($raw_headers);
    $valid_hdrs  = array_filter($raw_headers, fn($h) => $h !== '' && $h !== null);

    // Check required columns
    $required = ['asset_name', 'purchase_value'];
    $missing  = array_diff($required, $raw_headers);
    if (!empty($missing)) {
        echo json_encode(['success' => false,
            'error' => 'Missing required column(s): ' . implode(', ', $missing) .
                       '. Columns detected: ' . implode(', ', array_values($valid_hdrs))]);
        exit();
    }

    // ── Date parser (works for both CSV strings and Excel serial numbers) ─
    $parse_date = function($v) use ($xl_loaded) {
        if ($v === null || $v === '') return null;
        // Excel serial date (numeric > 1000)
        if (is_numeric($v) && (float)$v > 1000) {
            if ($xl_loaded) {
                try {
                    return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v)
                            ->format('Y-m-d');
                } catch (\Throwable $e) { return null; }
            }
            // Fallback: Excel epoch 1900-01-01
            $unix = ((float)$v - 25569) * 86400;
            return date('Y-m-d', (int)$unix);
        }
        if ($v instanceof \DateTime) return $v->format('Y-m-d');
        if (is_string($v)) {
            $ts = strtotime(trim($v));
            return $ts ? date('Y-m-d', $ts) : null;
        }
        return null;
    };

    // ── Safe row → associative array ─────────────────────────────────────
    // FIX: array_combine() returns FALSE when counts differ (e.g. trailing
    //      commas in Excel-exported CSV add extra columns).  We ALWAYS
    //      truncate the row to header count FIRST, then pad if shorter.
    $make_row = function(array $row_raw) use ($raw_headers, $hdr_count): array|false {
        $truncated = array_slice($row_raw, 0, $hdr_count);          // remove extra cols
        $padded    = array_pad($truncated, $hdr_count, '');          // fill missing cols
        $combined  = @array_combine($raw_headers, $padded);          // safe combine
        return $combined === false ? [] : $combined;
    };

    $imported = 0; $skipped = 0; $errors = [];
    $cb = mysqli_real_escape_string($conn, $created_by);

    // Helper: safely read a key from the row array
    $col = fn(array $data, string $key, $default = '') => $data[$key] ?? $default;

    foreach ($data_rows as $ridx => $row_raw) {
        // Skip non-array or entirely blank rows
        if (!is_array($row_raw)) { $skipped++; continue; }
        $non_empty = array_filter($row_raw, fn($v) => $v !== null && $v !== '');
        if (empty($non_empty)) continue;

        // Safe combine (handles column count mismatches — the main CSV bug)
        $data = $make_row($row_raw);
        if (empty($data)) { $skipped++; continue; }

        // Skip repeated header rows (e.g. copy-pasted headers mid-file)
        $rnum = trim((string)($col($data, '#', '')));
        if (strtolower($rnum) === '#' || strtolower($col($data, 'asset_name')) === 'asset_name') {
            $skipped++; continue;
        }

        $r_asset_name = trim((string)$col($data, 'asset_name'));
        $r_pv_raw     = $col($data, 'purchase_value', '');

        // Validate required fields
        if ($r_asset_name === '') {
            $skipped++;
            if (count($errors) < 10) $errors[] = "Row " . ($ridx + 2) . ": asset_name is empty.";
            continue;
        }
        // Strip currency symbols / commas that Excel sometimes exports
        $r_pv_clean = preg_replace('/[^\d.]/', '', (string)$r_pv_raw);
        $r_pv = is_numeric($r_pv_clean) && (float)$r_pv_clean > 0 ? (float)$r_pv_clean : null;
        if ($r_pv === null) {
            $skipped++;
            if (count($errors) < 10)
                $errors[] = "Row " . ($ridx + 2) . ": purchase_value invalid (got: " . htmlspecialchars((string)$r_pv_raw) . ")";
            continue;
        }

        $r_asset_cat = $e($col($data, 'asset_category'));
        $r_desc      = $e($col($data, 'description'));
        $r_model     = $e($col($data, 'model'));
        $r_serial    = $e($col($data, 'serial_number'));
        $r_date_acq  = $parse_date($col($data, 'date_of_acquisition'));
        $r_age_raw   = $col($data, 'age_at_time_of_acquisition', $col($data, 'age_at_acquisition', ''));
        $r_age       = is_numeric($r_age_raw) ? (float)$r_age_raw : null;
        $r_dep_raw   = $col($data, 'depreciation_percentage', '');
        $r_dep       = is_numeric($r_dep_raw) ? (float)$r_dep_raw : 0;
        $r_lpo       = $e($col($data, 'lpo_number'));
        $r_funder    = $e($col($data, 'dig_funder_name'));
        $r_project   = $e($col($data, 'project_name'));
        // Accept both 'acquisition_name' (Excel column) and 'acquisition_type' (canonical)
        $r_acq_type  = $e($col($data, 'acquisition_type') ?: $col($data, 'acquisition_name'));
        $r_cond_raw  = $col($data, 'current_condition', 'Good');
        $cond_opts   = ['Good', 'Fair', 'Poor', 'Disposed'];
        $r_cond      = in_array($r_cond_raw, $cond_opts) ? $r_cond_raw : 'Good';
        $r_disposal  = $parse_date($col($data, 'date_of_disposal'));
        $r_comments  = $e($col($data, 'comments'));

        // Calculate current book value
        if ($r_date_acq && $r_dep > 0) {
            $diff_r = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT TIMESTAMPDIFF(MONTH, '$r_date_acq', NOW()) AS m"));
            $months_r = max(0, (int)($diff_r['m'] ?? 0));
            $r_cv = max(0, round($r_pv * pow(1 - $r_dep / 100, $months_r / 12), 2));
        } else {
            $r_cv = $r_pv;
        }

        $r_asset_s    = $e($r_asset_name);
        $date_acq_sql = $r_date_acq ? "'$r_date_acq'" : 'NULL';
        $date_dis_sql = $r_disposal  ? "'$r_disposal'" : 'NULL';
        $age_sql      = $r_age !== null ? $r_age : 'NULL';

        // Skip duplicate serial numbers (only if serial is non-empty)
        if ($r_serial !== '') {
            $dup = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT asset_id FROM asset_master_register WHERE serial_number='$r_serial' LIMIT 1"));
            if ($dup) {
                if (count($errors) < 20)
                    $errors[] = "Row " . ($ridx + 2) . ": Serial '$r_serial' already exists — skipped.";
                $skipped++; continue;
            }
        }

        $ins = "INSERT INTO asset_master_register
                  (asset_name, asset_category, description, model, serial_number,
                   date_of_acquisition, age_at_acquisition, purchase_value,
                   depreciation_percentage, current_value, lpo_number,
                   dig_funder_name, project_name, acquisition_type,
                   current_condition, date_of_disposal, comments, is_active,
                   created_by, created_at, updated_at)
                VALUES
                  ('$r_asset_s','$r_asset_cat','$r_desc','$r_model','$r_serial',
                   $date_acq_sql, $age_sql, $r_pv,
                   $r_dep, $r_cv, '$r_lpo',
                   '$r_funder','$r_project','$r_acq_type',
                   '$r_cond', $date_dis_sql, '$r_comments', 1,
                   '$cb', NOW(), NOW())";

        if (mysqli_query($conn, $ins)) {
            $imported++;
        } else {
            if (count($errors) < 20)
                $errors[] = "Row " . ($ridx + 2) . ": " . mysqli_error($conn);
            $skipped++;
        }
    }

    echo json_encode([
        'success'  => true,
        'imported' => $imported,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'headers_found' => array_values($valid_hdrs),  // helpful for debugging
    ]);
    exit();
}

// ── AJAX: export CSV ────────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    $res = mysqli_query($conn,
        "SELECT asset_id, asset_name, asset_category, description, model, serial_number,
                date_of_acquisition, age_at_acquisition, purchase_value,
                depreciation_percentage, current_value, lpo_number,
                dig_funder_name, project_name, acquisition_type,
                current_condition, date_of_disposal, comments, created_by, created_at
         FROM asset_master_register WHERE is_active=1 ORDER BY asset_name");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="asset_master_register_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['asset_id','asset_name','asset_category','description','model','serial_number',
                   'date_of_acquisition','age_at_acquisition','purchase_value',
                   'depreciation_percentage','current_value','lpo_number',
                   'dig_funder_name','project_name','acquisition_type',
                   'current_condition','date_of_disposal','comments','created_by','created_at']);
    while ($r = mysqli_fetch_assoc($res)) fputcsv($out, $r);
    fclose($out);
    exit();
}

// ── Load dropdowns and data ─────────────────────────────────────────────
// Funders (id + name for proper select)
$funders_res  = mysqli_query($conn, "SELECT dig_funder_id, dig_funder_name FROM digital_funders ORDER BY dig_funder_name");
$funders_arr  = [];
while ($r = mysqli_fetch_assoc($funders_res)) $funders_arr[] = $r;

// asset_categories (with depreciation_percentage)
$cats_res  = mysqli_query($conn, "SELECT category_id, category_name, depreciation_percentage FROM asset_categories ORDER BY category_name");
$cats_arr  = [];
while ($r = mysqli_fetch_assoc($cats_res)) $cats_arr[] = $r;
// Build JS lookup: categoryName => depreciation_percentage
$cats_dep_js = json_encode(array_column($cats_arr, 'depreciation_percentage', 'category_name'));

// Organizations (project/programme names)
$orgs_res  = mysqli_query($conn, "SELECT org_id, org_name FROM organizations ORDER BY org_name");
$orgs_arr  = [];
while ($r = mysqli_fetch_assoc($orgs_res)) $orgs_arr[] = $r;

// Acquisition types
$acqs_res  = mysqli_query($conn, "SELECT acq_id, acq_name FROM acquisitions ORDER BY acq_name");
$acqs_arr  = [];
while ($r = mysqli_fetch_assoc($acqs_res)) $acqs_arr[] = $r;

// Asset type catalog for depreciation hint
$asset_types_res = mysqli_query($conn,
    "SELECT dit_asset_name, depreciation_percentage FROM digital_investments_assets ORDER BY dit_asset_name");
$asset_types_arr = [];
while ($r = mysqli_fetch_assoc($asset_types_res)) $asset_types_arr[$r['dit_asset_name']] = $r['depreciation_percentage'];

// Load list
$list_res = mysqli_query($conn,
    "SELECT * FROM asset_master_register WHERE is_active=1 ORDER BY created_at DESC");
$assets_list = [];
while ($r = mysqli_fetch_assoc($list_res)) $assets_list[] = $r;

// Edit pre-fill
$edit_row = null;
if (isset($_GET['edit'])) {
    $eid      = $i($_GET['edit']);
    $edit_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM asset_master_register WHERE asset_id=$eid LIMIT 1"));
}

// Summary counts
$total_assets = count($assets_list);
$total_value  = array_sum(array_column($assets_list, 'purchase_value'));
$curr_value   = array_sum(array_column($assets_list, 'current_value'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Asset Master Register — LVCT Health</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root{
  --primary:#2D008A; --primary2:#4B00C8; --lilac:#AC80EE;
  --pink:#FFDCF9; --green:#04B04B; --amber:#FFC12E;
  --red:#E41E39; --bg:#f4f2fb; --card:#fff;
  --border:#e2d9f3; --muted:#6B7280;
  --shadow:0 2px 18px rgba(45,0,138,.10); --radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:#1a1e2e;line-height:1.6;}
.wrap{max-width:90%;margin:0 auto;padding:20px 16px;}

/* header */
.page-header{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;
  padding:20px 28px;border-radius:var(--radius);margin-bottom:22px;
  display:flex;justify-content:space-between;align-items:center;
  box-shadow:0 6px 28px rgba(45,0,138,.28);}
.page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);
  padding:7px 15px;border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.hdr-links a:hover{background:rgba(255,255,255,.3);}

/* kpi cards */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:22px;}
.kpi-card{background:var(--card);border-radius:var(--radius);padding:18px 22px;
  box-shadow:var(--shadow);border-left:4px solid var(--primary);
  display:flex;align-items:center;gap:16px;}
.kpi-icon{width:48px;height:48px;border-radius:12px;
  background:linear-gradient(135deg,var(--primary),var(--primary2));
  color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
.kpi-label{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;}
.kpi-value{font-size:1.4rem;font-weight:800;color:var(--primary);}

/* tabs */
.tabs{display:flex;gap:0;margin-bottom:22px;background:var(--card);
  border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
.tab-btn{flex:1;padding:13px 10px;border:none;background:transparent;cursor:pointer;
  font-size:13.5px;font-weight:600;color:var(--muted);transition:.2s;
  border-bottom:3px solid transparent;display:flex;align-items:center;justify-content:center;gap:8px;}
.tab-btn:hover{background:#f4f2fb;color:var(--primary);}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary);background:var(--pink);}

/* card */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:22px;}
.card-head{background:linear-gradient(90deg,var(--primary),var(--primary2));
  color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center;}
.card-head h3{font-size:14px;font-weight:700;display:flex;align-items:center;gap:9px;}
.card-body{padding:24px;}

/* form */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;}
.form-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
.form-group{margin-bottom:0;}
.form-group.full{grid-column:1/-1;}
.form-group label{display:block;margin-bottom:5px;font-weight:600;color:#374151;font-size:13px;}
.form-group label .req{color:var(--red);margin-left:2px;}
.form-control,.form-select{width:100%;padding:10px 14px;border:1.5px solid var(--border);
  border-radius:9px;font-size:13.5px;font-family:inherit;background:#fff;transition:.2s;color:#1a1e2e;}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(45,0,138,.10);}
.form-control[readonly]{background:#f8f7fe;color:#555;}
textarea.form-control{resize:vertical;min-height:80px;}

/* value display */
.value-display{background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;
  border-radius:9px;padding:10px 16px;font-size:15px;font-weight:700;
  display:flex;align-items:center;gap:8px;}
.vd-label{font-size:11px;font-weight:400;opacity:.8;display:block;margin-bottom:2px;}

/* dep badge */
.dep-badge{display:inline-flex;align-items:center;gap:6px;background:var(--pink);
  color:var(--primary);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;
  margin-top:6px;border:1px solid var(--lilac);}

/* buttons */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;
  border:none;border-radius:9px;font-size:13px;font-weight:700;
  cursor:pointer;transition:.2s;text-decoration:none;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:var(--primary2);}
.btn-green{background:var(--green);color:#fff;}
.btn-green:hover{filter:brightness(.9);}
.btn-amber{background:var(--amber);color:#1a1e2e;}
.btn-amber:hover{filter:brightness(.9);}
.btn-red{background:var(--red);color:#fff;}
.btn-red:hover{filter:brightness(.9);}
.btn-outline{background:transparent;border:2px solid var(--primary);color:var(--primary);}
.btn-outline:hover{background:var(--pink);}
.btn-group{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;}

/* divider */
.divider-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;
  color:var(--primary);display:flex;align-items:center;gap:8px;margin:20px 0 12px;}
.divider-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* table */
.tbl-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
thead tr{background:linear-gradient(90deg,var(--primary),var(--primary2));color:#fff;}
thead th{padding:10px 12px;text-align:left;font-weight:700;white-space:nowrap;}
tbody tr{border-bottom:1px solid #f0eeff;transition:.15s;}
tbody tr:hover{background:var(--pink);}
tbody td{padding:9px 12px;vertical-align:middle;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;
  border-radius:20px;font-size:11px;font-weight:700;}
.badge-good{background:#d4f8e5;color:var(--green);}
.badge-fair{background:#fff3cc;color:#7a5800;}
.badge-poor{background:#fde8eb;color:var(--red);}
.badge-disposed{background:#e5e7eb;color:#6b7280;}

/* filter bar */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center;}
.filter-bar input,.filter-bar select{padding:8px 12px;border:1.5px solid var(--border);
  border-radius:8px;font-size:13px;font-family:inherit;flex:1;min-width:150px;max-width:240px;}
.filter-bar input:focus,.filter-bar select:focus{outline:none;border-color:var(--primary);}

/* import drop zone */
.import-drop{border:2px dashed var(--lilac);border-radius:12px;padding:32px 20px;
  text-align:center;cursor:pointer;transition:.2s;background:#faf8ff;}
.import-drop:hover,.import-drop.drag-over{background:var(--pink);border-color:var(--primary);}
.import-drop i{font-size:2.5rem;color:var(--lilac);margin-bottom:10px;}
.import-drop p{font-size:13.5px;color:var(--muted);}
.import-drop strong{color:var(--primary);}

/* alert */
.alert{padding:12px 18px;border-radius:9px;margin-bottom:18px;font-size:13.5px;
  display:flex;align-items:flex-start;gap:10px;}
.alert-success{background:#d4f8e5;color:#0a5c2e;border:1px solid #a8e6c1;}
.alert-error{background:#fde8eb;color:#7a0011;border:1px solid #f5b8c0;}
.alert-info{background:var(--pink);color:var(--primary);border:1px solid var(--lilac);}

/* toast */
.toast{position:fixed;bottom:24px;right:24px;background:#1a1e2e;color:#fff;
  padding:12px 22px;border-radius:10px;font-size:13.5px;font-weight:600;
  display:flex;align-items:center;gap:9px;z-index:9999;
  transform:translateY(80px);opacity:0;transition:.35s;pointer-events:none;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success .toast-icon{color:var(--green);}
.toast.error   .toast-icon{color:var(--red);}

/* modal */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:8000;
  display:none;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:var(--radius);width:min(540px,95vw);
  overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.modal-head{background:linear-gradient(90deg,var(--primary),var(--primary2));
  color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center;}
.modal-head h4{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.modal-body{padding:22px;}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);
  display:flex;gap:10px;justify-content:flex-end;}

@media(max-width:680px){
  .page-header{flex-direction:column;gap:12px;align-items:flex-start;}
  .tabs{flex-direction:column;}
}
</style>
</head>
<body>
<div class="wrap">

<!-- PAGE HEADER -->
<div class="page-header">
  <h1><i class="fas fa-clipboard-list"></i> Asset Master Register</h1>
  <div class="hdr-links">
    <a href="javascript:void(0)" onclick="showTab('form')"><i class="fas fa-plus"></i> Add Asset</a>
    <a href="javascript:void(0)" onclick="showTab('list')"><i class="fas fa-list"></i> All Assets</a>
    <a href="javascript:void(0)" onclick="showTab('import')"><i class="fas fa-file-import"></i> Import</a>
    <a href="digital_innovation_investments.php"><i class="fas fa-laptop-medical"></i> Investments</a>
    <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
  </div>
</div>

<!-- KPI ROW -->
<div class="kpi-row">
  <div class="kpi-card">
    <div class="kpi-icon"><i class="fas fa-boxes"></i></div>
    <div>
      <div class="kpi-label">Total Assets</div>
      <div class="kpi-value"><?= number_format($total_assets) ?></div>
    </div>
  </div>
  <div class="kpi-card" style="border-left-color:var(--amber)">
    <div class="kpi-icon" style="background:linear-gradient(135deg,var(--amber),#e6a800)">
      <i class="fas fa-tag"></i></div>
    <div>
      <div class="kpi-label">Purchase Value (KES)</div>
      <div class="kpi-value" style="color:var(--amber)"><?= number_format($total_value, 2) ?></div>
    </div>
  </div>
  <div class="kpi-card" style="border-left-color:var(--green)">
    <div class="kpi-icon" style="background:linear-gradient(135deg,var(--green),#027d34)">
      <i class="fas fa-chart-line"></i></div>
    <div>
      <div class="kpi-label">Current Book Value (KES)</div>
      <div class="kpi-value" style="color:var(--green)"><?= number_format($curr_value, 2) ?></div>
    </div>
  </div>
  <div class="kpi-card" style="border-left-color:var(--lilac)">
    <div class="kpi-icon" style="background:linear-gradient(135deg,var(--lilac),#7c4fc7)">
      <i class="fas fa-percentage"></i></div>
    <div>
      <div class="kpi-label">Depreciation (KES)</div>
      <div class="kpi-value" style="color:var(--lilac)"><?= number_format($total_value - $curr_value, 2) ?></div>
    </div>
  </div>
</div>

<!-- TABS -->
<div class="tabs" id="mainTabs">
  <button class="tab-btn active" id="tab_form" onclick="showTab('form')">
    <i class="fas fa-plus-circle"></i> Add / Edit Asset
  </button>
  <button class="tab-btn" id="tab_list" onclick="showTab('list')">
    <i class="fas fa-table"></i> Asset Register
  </button>
  <button class="tab-btn" id="tab_import" onclick="showTab('import')">
    <i class="fas fa-file-import"></i> Import Excel / CSV
  </button>
</div>

<div id="globalAlert"></div>

<!-- ══════════ TAB 1: FORM ══════════ -->
<div id="pane_form">
<div class="card">
  <div class="card-head">
    <h3><i class="fas fa-clipboard-plus"></i> <span id="formTitle">Register New Asset</span></h3>
  </div>
  <div class="card-body">

    <div class="divider-label"><i class="fas fa-info-circle"></i> Asset Identification</div>
    <div class="form-grid">

      <div class="form-group">
        <label>Asset Name <span class="req">*</span>
          <small style="font-weight:400;color:var(--muted)">(generic type, e.g. Laptop, Router)</small>
        </label>
        <input type="text" id="f_asset_name" class="form-control"
               placeholder="e.g. Laptop"
               list="assetNameList"
               value="<?= $edit_row ? htmlspecialchars($edit_row['asset_name']) : '' ?>">
        <datalist id="assetNameList">
          <?php foreach (array_keys($asset_types_arr) as $at): ?>
          <option value="<?= htmlspecialchars($at) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>

      <div class="form-group">
        <label>Asset Category <span class="req">*</span></label>
        <select id="f_asset_category" class="form-select" onchange="onCategoryChange(this.value)">
          <option value="">-- Select Category --</option>
          <?php foreach ($cats_arr as $cat):
          $sel = ($edit_row && $edit_row['asset_category'] == $cat['category_name']) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($cat['category_name']) ?>" <?= $sel ?>>
            <?= htmlspecialchars($cat['category_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Description / Full Name</label>
        <input type="text" id="f_description" class="form-control"
               placeholder="e.g. HP ProBook 430 G4"
               value="<?= $edit_row ? htmlspecialchars($edit_row['description'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label>Model</label>
        <input type="text" id="f_model" class="form-control"
               placeholder="e.g. 430 G4"
               value="<?= $edit_row ? htmlspecialchars($edit_row['model'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label>Serial Number</label>
        <input type="text" id="f_serial_number" class="form-control"
               placeholder="e.g. 5CD71608QM"
               value="<?= $edit_row ? htmlspecialchars($edit_row['serial_number'] ?? '') : '' ?>">
      </div>

    </div>

    <div class="divider-label"><i class="fas fa-shopping-cart"></i> Procurement Details</div>
    <div class="form-grid">

      <div class="form-group">
        <label>Date of Acquisition</label>
        <input type="date" id="f_date_of_acquisition" class="form-control"
               max="<?= date('Y-m-d') ?>"
               oninput="calcCurrentValue(); validateDisposalDate()"
               value="<?= $edit_row ? htmlspecialchars($edit_row['date_of_acquisition'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label>Age at Acquisition (years)</label>
        <input type="number" id="f_age_at_acquisition" class="form-control"
               min="0" step="0.1" placeholder="e.g. 4"
               value="<?= $edit_row ? htmlspecialchars($edit_row['age_at_acquisition'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label>Purchase Value (KES) <span class="req">*</span></label>
        <input type="number" id="f_purchase_value" class="form-control"
               min="0" step="0.01" placeholder="0.00"
               oninput="calcCurrentValue()"
               value="<?= $edit_row ? htmlspecialchars($edit_row['purchase_value'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label>Depreciation Rate (% per annum)</label>
        <input type="number" id="f_depreciation_percentage" class="form-control"
               min="0" max="100" step="0.01" placeholder="Auto-filled from category"
               readonly style="background:#f4f2fb;cursor:not-allowed"
               oninput="calcCurrentValue()"
               value="<?= $edit_row ? htmlspecialchars($edit_row['depreciation_percentage'] ?? '') : '' ?>">
        <div id="depHint" class="dep-badge" style="display:none">
          <i class="fas fa-lightbulb"></i>
          <span id="depHintText"></span>
        </div>
        <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block">
          Hint: Laptop/Desktop ~33.33% | Router/Printer ~25% | Server ~20% | Solar ~10%
        </small>
      </div>

      <div class="form-group">
        <label>LPO / Reference Number</label>
        <input type="text" id="f_lpo_number" class="form-control"
               placeholder="e.g. PO-12345"
               value="<?= $edit_row ? htmlspecialchars($edit_row['lpo_number'] ?? '') : '' ?>">
      </div>

      <div class="form-group">
        <label>Funder / Donor Organisation</label>
        <select id="f_dig_funder_name" class="form-select">
          <option value="">-- Select Funder --</option>
          <?php foreach ($funders_arr as $fn):
          $sel = ($edit_row && $edit_row['dig_funder_name'] == $fn['dig_funder_name']) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($fn['dig_funder_name']) ?>" <?= $sel ?>>
            <?= htmlspecialchars($fn['dig_funder_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Project / Programme Name</label>
        <select id="f_project_name" class="form-select">
          <option value="">-- Select Project / Organisation --</option>
          <?php foreach ($orgs_arr as $org):
          $sel = ($edit_row && $edit_row['project_name'] == $org['org_name']) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($org['org_name']) ?>" <?= $sel ?>>
            <?= htmlspecialchars($org['org_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Acquisition Type</label>
        <select id="f_acquisition_type" class="form-select">
          <option value="">-- Select Type --</option>
          <?php foreach ($acqs_arr as $acq):
          $sel = ($edit_row && $edit_row['acquisition_type'] == $acq['acq_name']) ? 'selected' : '';
          ?>
          <option value="<?= htmlspecialchars($acq['acq_name']) ?>" <?= $sel ?>>
            <?= htmlspecialchars($acq['acq_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

    </div>

    <div class="divider-label"><i class="fas fa-shield-alt"></i> Condition &amp; Disposal</div>
    <div class="form-grid">

      <div class="form-group">
        <label>Current Condition</label>
        <select id="f_current_condition" class="form-select">
          <?php foreach (['Good','Fair','Poor','Disposed'] as $c):
          $sel = ($edit_row && $edit_row['current_condition'] == $c) ? 'selected' : ''; ?>
          <option value="<?= $c ?>" <?= $sel ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Date of Disposal (if applicable)</label>
        <input type="date" id="f_date_of_disposal" class="form-control"
               oninput="validateDisposalDate()"
               value="<?= $edit_row ? htmlspecialchars($edit_row['date_of_disposal'] ?? '') : '' ?>">
        <small id="disposalDateErr" style="color:var(--red);font-size:11px;display:none">
          <i class="fas fa-exclamation-circle"></i> Disposal date cannot be before acquisition date.
        </small>
      </div>

      <div class="form-group">
        <label>Current Value (Auto-calculated)</label>
        <div class="value-display" id="cvDisplay">
          <div>
            <span class="vd-label">Estimated Book Value</span>
            <span id="cvAmount">KES 0.00</span>
          </div>
          <i class="fas fa-calculator" style="margin-left:auto;opacity:.6;font-size:1.3rem"></i>
        </div>
        <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block">
          <i class="fas fa-info-circle"></i> PV × (1 − dep%)^(months/12) — reducing balance
        </small>
      </div>

      <div class="form-group full">
        <label>Comments / Notes</label>
        <textarea id="f_comments" class="form-control"
                  placeholder="Any additional notes…"><?= $edit_row ? htmlspecialchars($edit_row['comments'] ?? '') : '' ?></textarea>
      </div>

    </div>

    <input type="hidden" id="f_asset_id" value="<?= $edit_row ? htmlspecialchars($edit_row['asset_id']) : '' ?>">

    <div class="btn-group">
      <button class="btn btn-primary" onclick="saveAsset()">
        <i class="fas fa-save"></i> Save Asset
      </button>
      <button class="btn btn-outline" onclick="resetForm()">
        <i class="fas fa-undo"></i> Reset Form
      </button>
    </div>

  </div>
</div>
</div><!-- /pane_form -->

<!-- ══════════ TAB 2: LIST ══════════ -->
<div id="pane_list" style="display:none">
<div class="card">
  <div class="card-head">
    <h3><i class="fas fa-table"></i> Asset Master Register</h3>
    <div style="display:flex;gap:8px">
      <a href="?export_csv=1" class="btn btn-green" style="padding:7px 14px;font-size:12px">
        <i class="fas fa-file-csv"></i> Export CSV
      </a>
      <button class="btn btn-amber" style="padding:7px 14px;font-size:12px" onclick="showTab('form')">
        <i class="fas fa-plus"></i> Add New
      </button>
    </div>
  </div>
  <div class="card-body">
    <div class="filter-bar">
      <input type="text" id="searchInput" placeholder="🔍 Search name, model, serial, funder…"
             oninput="filterTable()">
      <select id="filterCategory" onchange="filterTable()">
        <option value="">All asset_categories</option>
        <?php foreach ($cats_arr as $cat): ?>
        <option value="<?= htmlspecialchars($cat['category_name']) ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filterFunder" onchange="filterTable()">
        <option value="">All Funders</option>
        <?php foreach ($funders_arr as $fn): ?>
        <option value="<?= htmlspecialchars($fn['dig_funder_name']) ?>"><?= htmlspecialchars($fn['dig_funder_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filterCondition" onchange="filterTable()">
        <option value="">All Conditions</option>
        <option value="Good">Good</option>
        <option value="Fair">Fair</option>
        <option value="Poor">Poor</option>
        <option value="Disposed">Disposed</option>
      </select>
    </div>

    <div style="font-size:12px;color:var(--muted);margin-bottom:8px">Showing <span id="assetCount"><?= count($assets_list) ?></span> record(s)</div>
    <div class="tbl-wrap">
    <table id="assetTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Asset Name</th>
          <th>Category</th>
          <th>Model</th>
          <th>Serial No.</th>
          <th>Date Acquired</th>
          <th>Purchase Value (KES)</th>
          <th>Dep %</th>
          <th>Current Value (KES)</th>
          <th>LPO No.</th>
          <th>Funder</th>
          <th>Project</th>
          <th>Acq. Type</th>
          <th>Condition</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="assetTbody">
      <?php foreach ($assets_list as $idx => $a): ?>
      <tr data-category="<?= htmlspecialchars($a['asset_category'] ?? '') ?>"
          data-condition="<?= htmlspecialchars($a['current_condition']) ?>"
          data-funder="<?= htmlspecialchars($a['dig_funder_name'] ?? '') ?>">
        <td><?= $idx + 1 ?></td>
        <td><strong><?= htmlspecialchars($a['asset_name']) ?></strong>
          <?php if ($a['description']): ?>
          <br><small style="color:var(--muted)"><?= htmlspecialchars($a['description']) ?></small>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($a['asset_category'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['model'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['serial_number'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['date_of_acquisition'] ?? '—') ?></td>
        <td><?= number_format((float)$a['purchase_value'], 2) ?></td>
        <td><?= htmlspecialchars($a['depreciation_percentage']) ?>%</td>
        <td><strong style="color:var(--primary)"><?= number_format((float)$a['current_value'], 2) ?></strong></td>
        <td><?= htmlspecialchars($a['lpo_number'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['dig_funder_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['project_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['acquisition_type'] ?? '—') ?></td>
        <td>
          <?php
          $bc = ['Good'=>'badge-good','Fair'=>'badge-fair','Poor'=>'badge-poor','Disposed'=>'badge-disposed'];
          $cond = $a['current_condition'];
          ?>
          <span class="badge <?= $bc[$cond] ?? 'badge-good' ?>"><?= htmlspecialchars($cond) ?></span>
        </td>
        <td style="white-space:nowrap">
          <a href="?edit=<?= $a['asset_id'] ?>" class="btn btn-amber"
             style="padding:5px 10px;font-size:11px">
            <i class="fas fa-edit"></i> Edit
          </a>
          <a href="digital_innovation_investments.php?issue=<?= $a['asset_id'] ?>"
             class="btn btn-green" style="padding:5px 10px;font-size:11px"
             title="Issue this asset to a facility">
            <i class="fas fa-paper-plane"></i> Issue
          </a>
          <button class="btn btn-red" style="padding:5px 10px;font-size:11px"
                  onclick="deleteAsset(<?= $a['asset_id'] ?>, '<?= htmlspecialchars($a['asset_name'], ENT_QUOTES) ?>')">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($assets_list)): ?>
      <tr><td colspan="15" style="text-align:center;color:var(--muted);padding:30px">
        <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px"></i>
        No assets yet. Click <strong>Add Asset</strong> or <strong>Import</strong> to get started.
      </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</div><!-- /pane_list -->

<!-- ══════════ TAB 3: IMPORT ══════════ -->
<div id="pane_import" style="display:none">
<div class="card">
  <div class="card-head">
    <h3><i class="fas fa-file-import"></i> Import Asset Register (Excel / CSV)</h3>
  </div>
  <div class="card-body">

    <div class="alert alert-info">
      <i class="fas fa-info-circle" style="font-size:1.3rem;flex-shrink:0"></i>
      <div>
        <strong>Supported Formats:</strong> .xlsx, .xls, .csv<br>
        <strong>Required columns:</strong>
        <code style="background:#e8deff;padding:2px 6px;border-radius:4px;font-size:12px">
          asset_name, purchase_value
        </code><br>
        <strong>Optional columns:</strong>
        <code style="background:#f0f8ff;padding:2px 6px;border-radius:4px;font-size:12px">
          asset_category, description, model, serial_number, date_of_acquisition,
          age_at_time_of_acquisition, depreciation_percentage, lpo_number,
          dig_funder_name, project_name, acquisition_name, current_condition,
          date_of_disposal, comments
        </code><br>
        <strong>Date format:</strong> YYYY-MM-DD &nbsp;|&nbsp;
        <strong>Duplicate serials</strong> are automatically skipped.<br>
        The existing <em>assets_master_register.xlsx</em> in this folder matches this format exactly.
      </div>
    </div>

    <div class="import-drop" id="importDrop" onclick="document.getElementById('importFile').click()">
      <i class="fas fa-cloud-upload-alt"></i>
      <p><strong>Click to browse</strong> or drag &amp; drop your Excel or CSV file</p>
      <p id="importFileName" style="margin-top:8px;color:var(--primary);font-weight:600"></p>
    </div>
    <input type="file" id="importFile" accept=".xlsx,.xls,.csv"
           style="display:none" onchange="onImportFileChange(this)">

    <div class="btn-group">
      <button class="btn btn-primary" id="btnImport" disabled onclick="importAssets()">
        <i class="fas fa-file-import"></i> Import Assets
      </button>
      <button class="btn btn-outline" onclick="downloadTemplate()">
        <i class="fas fa-download"></i> Download CSV Template
      </button>
    </div>

    <div id="importResult" style="margin-top:18px"></div>
  </div>
</div>
</div><!-- /pane_import -->

</div><!-- /wrap -->

<!-- DELETE MODAL -->
<div class="modal-overlay" id="delModal">
  <div class="modal-box">
    <div class="modal-head">
      <h4><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h4>
      <button onclick="closeDelModal()"
        style="background:none;border:none;color:#fff;cursor:pointer;font-size:1.2rem">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to remove <strong id="delAssetName"></strong> from the register?
        <br>This action cannot be undone.</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-outline" onclick="closeDelModal()"><i class="fas fa-times"></i> Cancel</button>
      <button class="btn btn-red" onclick="confirmDelete()"><i class="fas fa-trash"></i> Yes, Remove</button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div class="toast" id="toast">
  <i class="fas fa-check-circle toast-icon"></i>
  <span id="toastMsg">Saved</span>
</div>

<script>
const THIS_FILE = '<?= $this_file ?>';
const ASSET_TYPES = <?= json_encode($asset_types_arr) ?>;
let deleteTargetId = 0;
let importFile = null;

// ── Toast ──────────────────────────────────────────────────────────
function showToast(msg, type='success'){
  const t=document.getElementById('toast');
  document.getElementById('toastMsg').textContent=msg;
  t.className='toast '+type+' show';
  const ic=t.querySelector('.toast-icon');
  ic.className='fas '+(type==='success'?'fa-check-circle':'fa-exclamation-triangle')+' toast-icon';
  setTimeout(()=>t.classList.remove('show'),3500);
}
function showAlert(msg,type='info'){
  const el=document.getElementById('globalAlert');
  el.innerHTML=`<div class="alert alert-${type}">
    <i class="fas fa-${type==='success'?'check-circle':type==='error'?'times-circle':'info-circle'}"></i>
    <span>${msg}</span></div>`;
  setTimeout(()=>el.innerHTML='',5000);
}

// ── Tabs ────────────────────────────────────────────────────────────
function showTab(name){
  ['form','list','import'].forEach(t=>{
    document.getElementById('pane_'+t).style.display=t===name?'block':'none';
    document.getElementById('tab_'+t).classList.toggle('active',t===name);
  });
}

// ── Current value calc ──────────────────────────────────────────────
function calcCurrentValue(){
  const pv=parseFloat(document.getElementById('f_purchase_value').value)||0;
  const dep=parseFloat(document.getElementById('f_depreciation_percentage').value)||0;
  const dateStr=document.getElementById('f_date_of_acquisition').value;

  // Hint from asset types catalog
  const name=document.getElementById('f_asset_name').value.trim();
  if(ASSET_TYPES[name]){
    const hint=document.getElementById('depHint');
    document.getElementById('depHintText').textContent=
      `Suggested rate for "${name}": ${ASSET_TYPES[name]}%`;
    hint.style.display='inline-flex';
  }

  if(!pv){
    document.getElementById('cvAmount').textContent='0.00';
    return;
  }
  if(!dep||!dateStr){
    document.getElementById('cvAmount').textContent=pv.toLocaleString('en-KE',{minimumFractionDigits:2});
    return;
  }
  const d=new Date(dateStr), now=new Date();
  const months=Math.max(0,
    (now.getFullYear()-d.getFullYear())*12+(now.getMonth()-d.getMonth()));
  let cv=pv*Math.pow(1-dep/100,months/12);
  if(cv<0)cv=0;
  cv=Math.round(cv*100)/100;
  document.getElementById('cvAmount').textContent=
    cv.toLocaleString('en-KE',{minimumFractionDigits:2});
}

// ── Save asset ──────────────────────────────────────────────────────
async function saveAsset(){
  // Validate dates before saving
  const acqVal = document.getElementById('f_date_of_acquisition').value;
  if (acqVal && acqVal > '<?= date("Y-m-d") ?>') {
    showToast('Acquisition date cannot be in the future.', 'error'); return;
  }
  if (!validateDisposalDate()) {
    showToast('Disposal date cannot be before acquisition date.', 'error'); return;
  }
  const name=document.getElementById('f_asset_name').value.trim();
  const pv=document.getElementById('f_purchase_value').value;
  if(!name){showToast('Asset name is required.','error');return;}
  if(!pv||parseFloat(pv)<=0){showToast('Purchase value must be greater than 0.','error');return;}

  const fd=new FormData();
  fd.append('ajax_save','1');
  fd.append('asset_id',document.getElementById('f_asset_id').value||'');
  fd.append('asset_name',name);
  fd.append('asset_category',document.getElementById('f_asset_category').value);
  fd.append('description',document.getElementById('f_description').value);
  fd.append('model',document.getElementById('f_model').value);
  fd.append('serial_number',document.getElementById('f_serial_number').value);
  fd.append('date_of_acquisition',document.getElementById('f_date_of_acquisition').value);
  fd.append('age_at_acquisition',document.getElementById('f_age_at_acquisition').value);
  fd.append('purchase_value',pv);
  fd.append('depreciation_percentage',document.getElementById('f_depreciation_percentage').value||0);
  fd.append('lpo_number',document.getElementById('f_lpo_number').value);
  fd.append('dig_funder_name',document.getElementById('f_dig_funder_name').value);
  fd.append('project_name',document.getElementById('f_project_name').value);
  fd.append('acquisition_type',document.getElementById('f_acquisition_type').value);
  fd.append('current_condition',document.getElementById('f_current_condition').value);
  fd.append('date_of_disposal',document.getElementById('f_date_of_disposal').value);
  fd.append('comments',document.getElementById('f_comments').value);

  try{
    const data=await fetch(THIS_FILE,{method:'POST',body:fd}).then(r=>r.json());
    if(data.success){
      showToast(data.action==='insert'?'Asset registered successfully!':'Asset updated!','success');
      document.getElementById('f_asset_id').value=data.asset_id;
      document.getElementById('formTitle').textContent='Edit Asset';
      setTimeout(()=>{showTab('list');window.location.reload();},1400);
    } else {
      showToast(data.error||'Save failed.','error');
    }
  } catch(e){
    showToast('Network error.','error');
  }
}

// ── Reset form ──────────────────────────────────────────────────────
function resetForm(){
  ['f_asset_name','f_description','f_model','f_serial_number','f_date_of_acquisition',
   'f_age_at_acquisition','f_purchase_value','f_lpo_number',
   'f_date_of_disposal','f_comments'].forEach(id=>{
    document.getElementById(id).value='';
  });
  document.getElementById('f_depreciation_percentage').value='';
  document.getElementById('f_asset_category').value='';
  document.getElementById('f_dig_funder_name').value='';
  document.getElementById('f_project_name').value='';
  document.getElementById('f_acquisition_type').value='';
  document.getElementById('disposalDateErr').style.display='none';
  document.getElementById('f_current_condition').value='Good';
  document.getElementById('f_asset_id').value='';
  document.getElementById('cvAmount').textContent='0.00';
  document.getElementById('depHint').style.display='none';
  document.getElementById('formTitle').textContent='Register New Asset';
  showToast('Form cleared.','success');
  window.scrollTo({top:0,behavior:'smooth'});
}

// ── Delete ──────────────────────────────────────────────────────────
function deleteAsset(id,name){
  deleteTargetId=id;
  document.getElementById('delAssetName').textContent=name;
  document.getElementById('delModal').classList.add('show');
}
function closeDelModal(){
  document.getElementById('delModal').classList.remove('show');
  deleteTargetId=0;
}
async function confirmDelete(){
  if(!deleteTargetId)return;
  const fd=new FormData();
  fd.append('ajax_delete','1');
  fd.append('asset_id',deleteTargetId);
  try{
    const data=await fetch(THIS_FILE,{method:'POST',body:fd}).then(r=>r.json());
    if(data.success){
      showToast('Asset removed.','success');
      closeDelModal();
      setTimeout(()=>window.location.reload(),1000);
    } else {
      showToast(data.error||'Delete failed.','error');
    }
  } catch(e){showToast('Network error.','error');}
}

// ── Category change → auto-fill depreciation (readonly) ────────────────
const CAT_DEP = <?= $cats_dep_js ?>;

function onCategoryChange(catName) {
    const dep = CAT_DEP[catName];
    const field = document.getElementById('f_depreciation_percentage');
    if (dep !== undefined && dep !== null) {
        field.value = parseFloat(dep).toFixed(2);
        document.getElementById('depHint').style.display = 'flex';
        document.getElementById('depHintText').textContent =
            catName + ': ' + parseFloat(dep).toFixed(2) + '% per annum';
    } else {
        field.value = '';
        document.getElementById('depHint').style.display = 'none';
    }
    calcCurrentValue();
}

// Pre-fill depreciation on page load if editing and category is set
(function() {
    const catSel = document.getElementById('f_asset_category');
    if (catSel && catSel.value) {
        const dep = CAT_DEP[catSel.value];
        const field = document.getElementById('f_depreciation_percentage');
        // Only set if field is empty (don't override existing edit value)
        if (dep !== undefined && dep !== null && !field.value) {
            field.value = parseFloat(dep).toFixed(2);
        }
    }
})();

// ── Date validation: disposal ≥ acquisition ─────────────────────────────
function validateDisposalDate() {
    const acq = document.getElementById('f_date_of_acquisition').value;
    const dis = document.getElementById('f_date_of_disposal').value;
    const err = document.getElementById('disposalDateErr');
    if (acq && dis && dis < acq) {
        err.style.display = 'block';
        document.getElementById('f_date_of_disposal').style.borderColor = 'var(--red)';
        return false;
    }
    if (err) err.style.display = 'none';
    document.getElementById('f_date_of_disposal').style.borderColor = '';
    return true;
}

// ── Table filter (name/model/serial + category + funder + condition) ──────
function filterTable() {
    const q    = document.getElementById('searchInput').value.toLowerCase();
    const cat  = document.getElementById('filterCategory').value.toLowerCase();
    const fund = document.getElementById('filterFunder').value.toLowerCase();
    const cond = document.getElementById('filterCondition').value.toLowerCase();
    const rows = document.querySelectorAll('#assetTbody tr');
    let visible = 0;
    rows.forEach(row => {
        const txt   = row.textContent.toLowerCase();
        const rCat  = (row.dataset.category  || '').toLowerCase();
        const rCond = (row.dataset.condition || '').toLowerCase();
        const rFund = (row.dataset.funder    || '').toLowerCase();
        const matchQ = !q    || txt.includes(q);
        const matchC = !cat  || rCat  === cat;
        const matchF = !fund || rFund === fund;
        const matchD = !cond || rCond === cond;
        const show   = matchQ && matchC && matchF && matchD;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const counter = document.getElementById('assetCount');
    if (counter) counter.textContent = visible + ' record(s)';
}

// ── Override saveAsset to validate dates first ──────────────────────────
const _origSave = typeof saveAsset === 'function' ? saveAsset : null;

</script>
</body>
</html>
