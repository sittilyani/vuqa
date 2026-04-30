<?php
// digital/digital_innovation_investments.php
session_start();

$base_path = dirname(__DIR__);
$config_path   = $base_path . '/includes/config.php';
$session_check = $base_path . '/includes/session_check.php';

if (!file_exists($config_path)) {
    die('Configuration file not found. Expected: ' . $config_path);
}
include($config_path);
include($session_check);

if (!isset($conn) || !$conn) {
    die('Database connection failed. Please check config.php.');
}
if (!mysqli_ping($conn)) {
    die('Database connection lost. Please check your database server.');
}
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$created_by = $_SESSION['full_name'] ?? '';
$uid        = (int)$_SESSION['user_id'];
$this_file  = basename(__FILE__);

// ────────────────────────────────────────────────────────────────────
//  HELPERS
// ────────────────────────────────────────────────────────────────────
$e  = fn($v) => mysqli_real_escape_string($conn, trim((string)($v ?? '')));
$i  = fn($v) => is_numeric($v) ? (int)$v   : 'NULL';
$f  = fn($v) => is_numeric($v) ? (float)$v : 'NULL';

// ────────────────────────────────────────────────────────────────────
//  AUTO-RECALCULATE current_value for all active records (monthly-safe)
//  Uses reducing-balance:  CV = PV × (1 − dep_rate)^(months/12)
// ────────────────────────────────────────────────────────────────────
mysqli_query($conn, "
    UPDATE digital_innovation_investments
    SET  current_value = GREATEST(0,
            purchase_value * POW(
                1 - (depreciation_percentage / 100),
                TIMESTAMPDIFF(MONTH, issue_date, NOW()) / 12
            )
         ),
         updated_at = NOW()
    WHERE invest_status = 'Active'
      AND (no_end_date = 1 OR end_date IS NULL OR end_date >= CURDATE())
");

// ────────────────────────────────────────────────────────────────────
//  AJAX — facility search
// ────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_facility') {
    $q    = $e($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT facility_id, facility_name, mflcode, county_name, subcounty_name,
                    owner, sdp, agency, emr, emrstatus, infrastructuretype,
                    latitude, longitude, level_of_care_name
             FROM facilities
             WHERE (facility_name LIKE '%$q%' OR mflcode LIKE '%$q%' OR county_name LIKE '%$q%')
             ORDER BY facility_name LIMIT 20");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  AJAX — get asset details (depreciation_percentage)
// ────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_asset') {
    $dig_id = $i($_GET['dig_id'] ?? 0);
    $row    = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT dig_id, dit_asset_name, depreciation_percentage
         FROM digital_investments_assets WHERE dig_id = $dig_id LIMIT 1"));
    header('Content-Type: application/json');
    echo json_encode($row ?: []);
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  AJAX — search asset master register
// ────────────────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_register') {
    $q    = $e($_GET['q'] ?? '');
    $rows = [];
    if (strlen($q) >= 2) {
        $res = mysqli_query($conn,
            "SELECT asset_id, asset_name, asset_category, model, serial_number,
                    purchase_value, depreciation_percentage, dig_funder_name,
                    lpo_number, project_name, acquisition_type, current_condition
             FROM asset_master_register
             WHERE is_active = 1
               AND (asset_name LIKE '%$q%' OR serial_number LIKE '%$q%'
                 OR model LIKE '%$q%' OR lpo_number LIKE '%$q%')
             ORDER BY asset_name LIMIT 30");
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit();
}

// ── AJAX: get single asset from register by ID ────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_register_asset') {
    $aid = $i($_GET['asset_id'] ?? 0);
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM asset_master_register WHERE asset_id = $aid LIMIT 1"));
    header('Content-Type: application/json');
    echo json_encode($row ?: []);
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  AJAX — save / update investment record
// ────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $invest_id           = $i($_POST['invest_id']             ?? 0);
    $facility_id         = $i($_POST['facility_id']           ?? 0);
    $facility_name       = $e($_POST['facility_name']         ?? '');
    $mflcode             = $e($_POST['mflcode']               ?? '');
    $county_name         = $e($_POST['county_name']           ?? '');
    $subcounty_name      = $e($_POST['subcounty_name']        ?? '');
    $latitude            = $f($_POST['latitude']              ?? 'NULL');
    $longitude           = $f($_POST['longitude']             ?? 'NULL');
    $dit_asset_name      = $e($_POST['dit_asset_name']        ?? '');
    $asset_name          = $e($_POST['asset_name']            ?? '');
    $asset_id_reg        = $i($_POST['asset_id_reg']          ?? 0);
    $dep_pct             = $f($_POST['depreciation_percentage'] ?? 0);
    $purchase_value      = $f($_POST['purchase_value']        ?? 0);
    $tag_name            = $e($_POST['tag_name']              ?? '');
    $quantity            = max(1, (int)($_POST['quantity']    ?? 1));
    $total_cost          = $f($_POST['total_cost']            ?? 0);
    $issue_date          = $e($_POST['issue_date']            ?? '');
    $no_end_date         = (int)!empty($_POST['no_end_date']);
    $end_date_raw        = $e($_POST['end_date']              ?? '');
    $end_date_val        = ($no_end_date || $end_date_raw === '') ? 'NULL' : "'$end_date_raw'";
    $dig_funder_name     = $e($_POST['dig_funder_name']       ?? '');
    $sdp_name            = $e($_POST['sdp_name']              ?? '');
    $emr_type_name       = $e($_POST['emr_type_name']         ?? '');
    $service_level       = $e($_POST['service_level']         ?? '');
    $lot_number          = $e($_POST['lot_number']            ?? '');
    $name_of_user        = $e($_POST['name_of_user']          ?? '');
    $department_name     = $e($_POST['department_name']       ?? '');
    $date_of_verification = $e($_POST['date_of_verification'] ?? '');
    $date_of_disposal    = $e($_POST['date_of_disposal']      ?? '');

    // Derived SQL values
    $lat_val   = ($latitude  === 'NULL' || $latitude  === '') ? 'NULL' : $latitude;
    $lng_val   = ($longitude === 'NULL' || $longitude === '') ? 'NULL' : $longitude;
    $dov_val   = $date_of_verification ? "'$date_of_verification'" : 'NULL';
    $dod_val   = $date_of_disposal     ? "'$date_of_disposal'"     : 'NULL';
    $aid_val   = ($asset_id_reg === 'NULL' || $asset_id_reg == 0) ? 'NULL' : $asset_id_reg;
    // total_cost: use posted value or recalculate
    $tc        = ($total_cost !== 'NULL' && $total_cost > 0) ? $total_cost : ($purchase_value !== 'NULL' ? $purchase_value * $quantity : 0);

    if (!$facility_id || !$dit_asset_name || !$purchase_value || !$issue_date || !$service_level) {
        echo json_encode(['success' => false, 'error' => 'Please fill all required fields.']);
        exit();
    }

    // Calc initial current_value
    $months_elapsed = 0;
    if ($issue_date) {
        $diff = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT TIMESTAMPDIFF(MONTH, '$issue_date', NOW()) AS m"));
        $months_elapsed = (int)($diff['m'] ?? 0);
    }
    $current_value = 0;
    if ($dep_pct !== 'NULL' && $purchase_value !== 'NULL' && $months_elapsed >= 0) {
        $current_value = round($purchase_value * pow(1 - ($dep_pct / 100), $months_elapsed / 12), 2);
        if ($current_value < 0) $current_value = 0;
    }

    $invest_status = 'Active';
    if (!$no_end_date && $end_date_raw && strtotime($end_date_raw) < time()) {
        $invest_status = 'Expired';
    }
    $invest_status_s = $e($invest_status);

    if ($invest_id === 'NULL' || $invest_id == 0) {
        // INSERT
        $sql = "INSERT INTO digital_innovation_investments
                    (asset_id, facility_id, facility_name, mflcode, county_name, subcounty_name,
                     latitude, longitude,
                     dit_asset_name, asset_name, tag_name, quantity, total_cost,
                     depreciation_percentage, purchase_value,
                     issue_date, end_date, no_end_date, current_value,
                     dig_funder_name, sdp_name, emr_type_name, service_level, lot_number,
                     name_of_user, department_name, date_of_verification, date_of_disposal,
                     invest_status, created_by, created_at, updated_at)
                VALUES
                    ($aid_val, $facility_id,'$facility_name','$mflcode','$county_name','$subcounty_name',
                     $lat_val, $lng_val,
                     '$dit_asset_name','$asset_name','$tag_name',$quantity,$tc,
                     $dep_pct,$purchase_value,
                     '$issue_date',$end_date_val,$no_end_date,$current_value,
                     '$dig_funder_name','$sdp_name','$emr_type_name','$service_level','$lot_number',
                     '$name_of_user','$department_name',$dov_val,$dod_val,
                     '$invest_status_s','".mysqli_real_escape_string($conn,$created_by)."', NOW(), NOW())";
        if (mysqli_query($conn, $sql)) {
            $new_id = mysqli_insert_id($conn);
            echo json_encode(['success' => true, 'invest_id' => $new_id, 'current_value' => $current_value, 'action' => 'insert']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    } else {
        // UPDATE
        $sql = "UPDATE digital_innovation_investments SET
                    asset_id=$aid_val,
                    facility_id=$facility_id, facility_name='$facility_name',
                    mflcode='$mflcode', county_name='$county_name', subcounty_name='$subcounty_name',
                    latitude=$lat_val, longitude=$lng_val,
                    dit_asset_name='$dit_asset_name', asset_name='$asset_name',
                    tag_name='$tag_name', quantity=$quantity, total_cost=$tc,
                    depreciation_percentage=$dep_pct, purchase_value=$purchase_value,
                    issue_date='$issue_date', end_date=$end_date_val, no_end_date=$no_end_date,
                    current_value=$current_value, dig_funder_name='$dig_funder_name',
                    sdp_name='$sdp_name', emr_type_name='$emr_type_name',
                    service_level='$service_level', lot_number='$lot_number',
                    name_of_user='$name_of_user', department_name='$department_name',
                    date_of_verification=$dov_val, date_of_disposal=$dod_val,
                    invest_status='$invest_status_s', updated_at=NOW()
                WHERE invest_id=$invest_id";
        if (mysqli_query($conn, $sql)) {
            echo json_encode(['success' => true, 'invest_id' => $invest_id, 'current_value' => $current_value, 'action' => 'update']);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    }
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  AJAX — delete investment
// ────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_delete'])) {
    header('Content-Type: application/json');
    $invest_id = $i($_POST['invest_id'] ?? 0);
    if ($invest_id !== 'NULL' && $invest_id > 0) {
        if (mysqli_query($conn, "DELETE FROM digital_innovation_investments WHERE invest_id=$invest_id")) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  AJAX — CSV import
// ────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_csv_import'])) {
    header('Content-Type: application/json');

    if (empty($_FILES['csv_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
        exit();
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$handle) {
        echo json_encode(['success' => false, 'error' => 'Cannot read uploaded file.']);
        exit();
    }

    // Strip UTF-8 BOM that Excel adds to CSV exports
    $bom_c = fread($handle, 3);
    if ($bom_c !== "\xEF\xBB\xBF") rewind($handle);

    // Headers — strip BOM, newlines, lowercase, trim
    $headers   = array_map(
        fn($h) => strtolower(trim(str_replace(["\r","\n","\xEF\xBB\xBF"], '', (string)($h ?? '')))),
        fgetcsv($handle)
    );
    $hdr_cnt   = count($headers);
    $required  = ['facility_name','dit_asset_name','purchase_value','issue_date','service_level'];
    $missing   = array_diff($required, $headers);
    if (!empty($missing)) {
        fclose($handle);
        echo json_encode(['success' => false,
            'error' => 'Missing required columns: ' . implode(', ', $missing) .
                       '. Detected: ' . implode(', ', array_filter($headers))]);
        exit();
    }

    $imported = 0; $skipped = 0; $errors = [];
    $cb = mysqli_real_escape_string($conn, $created_by);
    $rownum = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $rownum++;
        // Skip blank rows silently
        if (!array_filter($row, fn($v) => $v !== null && trim((string)$v) !== '')) continue;

        // FIX: truncate excess columns then pad short rows before array_combine
        $row_safe = array_pad(array_slice($row, 0, $hdr_cnt), $hdr_cnt, '');
        $data = @array_combine($headers, $row_safe);
        if ($data === false) { $skipped++; continue; }

        // Skip repeated header rows
        if (strtolower(trim($data['facility_name'] ?? '')) === 'facility_name') { $skipped++; continue; }

        $fname          = $e($data['facility_name']  ?? '');
        $asset_name_csv = $e($data['dit_asset_name'] ?? '');
        $pv_raw         = preg_replace('/[^\d.]/', '', (string)($data['purchase_value'] ?? ''));
        $pv             = is_numeric($pv_raw) ? (float)$pv_raw : null;
        $idate          = $e($data['issue_date']     ?? '');
        $slevel         = $e($data['service_level']  ?? '');

        if (!$fname || !$asset_name_csv || $pv === null || !$idate || !$slevel) {
            $skipped++;
            if (count($errors) < 10)
                $errors[] = "Row $rownum: missing required field(s) — facility='$fname' asset='$asset_name_csv' pv='$pv_raw' date='$idate'";
            continue;
        }

        // Lookup facility by MFL code first, then name
        $mfl_csv = $e($data['mflcode'] ?? '');
        $fac_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT facility_id, facility_name, mflcode, county_name, subcounty_name, latitude, longitude
             FROM facilities
             WHERE " . ($mfl_csv ? "mflcode='$mfl_csv'" : "facility_name='$fname'") . " LIMIT 1"));
        if (!$fac_row) {
            $skipped++;
            if (count($errors) < 20) $errors[] = "Row $rownum: facility '$fname' not found";
            continue;
        }

        $fid     = (int)$fac_row['facility_id'];
        $fname   = $e($fac_row['facility_name']);
        $mfl     = $e($fac_row['mflcode']        ?? '');
        $county  = $e($fac_row['county_name']    ?? ($data['county_name']    ?? ''));
        $subcnty = $e($fac_row['subcounty_name'] ?? ($data['subcounty_name'] ?? ''));
        $lat_c   = is_numeric($fac_row['latitude'])  ? (float)$fac_row['latitude']  : 'NULL';
        $lng_c   = is_numeric($fac_row['longitude']) ? (float)$fac_row['longitude'] : 'NULL';

        // Lookup asset type
        $asset_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT dit_asset_name, depreciation_percentage
             FROM digital_investments_assets WHERE dit_asset_name='$asset_name_csv' LIMIT 1"));
        if (!$asset_row) {
            $skipped++;
            if (count($errors) < 20) $errors[] = "Row $rownum: asset type '$asset_name_csv' not found in catalog";
            continue;
        }

        $asset_name_s = $e($asset_row['dit_asset_name']);
        $dep_pct      = (float)$asset_row['depreciation_percentage'];

        $no_end  = (int)(!empty($data['no_end_date']) && strtolower($data['no_end_date']) !== '0');
        $edate_r = $e($data['end_date'] ?? '');
        $edate_v = ($no_end || $edate_r === '') ? 'NULL' : "'$edate_r'";

        $tag_c    = $e($data['tag_name']           ?? '');
        $qty_c    = max(1, (int)($data['quantity'] ?? 1));
        $tc_c     = round($pv * $qty_c, 2);
        $funder_c = $e($data['dig_funder_name']    ?? '');
        $sdp_c    = $e($data['sdp_name']           ?? '');
        $emr_c    = $e($data['emr_type_name']      ?? '');
        $lot_c    = $e($data['lot_number']         ?? '');
        $user_c   = $e($data['name_of_user']       ?? '');
        $dept_c   = $e($data['department_name']    ?? '');
        $dov_r    = $e($data['date_of_verification'] ?? '');
        $dov_c    = $dov_r ? "'$dov_r'" : 'NULL';

        // Current value calc
        $diff_row       = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT TIMESTAMPDIFF(MONTH,'$idate',NOW()) AS m"));
        $months_elapsed = max(0, (int)($diff_row['m'] ?? 0));
        $cv             = max(0, round($pv * pow(1 - ($dep_pct / 100), $months_elapsed / 12), 2));

        $invest_status = 'Active';
        if (!$no_end && $edate_r && strtotime($edate_r) < time()) $invest_status = 'Expired';
        $is_s = $e($invest_status);

        $ins = "INSERT INTO digital_innovation_investments
                    (facility_id, facility_name, mflcode, county_name, subcounty_name,
                     latitude, longitude,
                     dit_asset_name, asset_name, tag_name, quantity, total_cost,
                     depreciation_percentage, purchase_value,
                     issue_date, end_date, no_end_date, current_value,
                     dig_funder_name, sdp_name, emr_type_name, service_level, lot_number,
                     name_of_user, department_name, date_of_verification,
                     invest_status, created_by, created_at, updated_at)
                VALUES ($fid,'$fname','$mfl','$county','$subcnty',
                        $lat_c, $lng_c,
                        '$asset_name_s','$asset_name_s','$tag_c',$qty_c,$tc_c,
                        $dep_pct,$pv,
                        '$idate',$edate_v,$no_end,$cv,
                        '$funder_c','$sdp_c','$emr_c','$slevel','$lot_c',
                        '$user_c','$dept_c',$dov_c,
                        '$is_s','$cb',NOW(),NOW())";
        if (mysqli_query($conn, $ins)) {
            $imported++;
        } else {
            if (count($errors) < 20) $errors[] = "Row $rownum: " . mysqli_error($conn);
            $skipped++;
        }
    }
    fclose($handle);
    echo json_encode(['success' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  AJAX — Excel/XLSX import for investments
// ────────────────────────────────────────────────────────────────────
if (isset($_POST['ajax_excel_import'])) {
    header('Content-Type: application/json');
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    if (empty($_FILES['xls_file']['tmp_name'])) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
        exit();
    }

    $fname_up = $_FILES['xls_file']['name'];
    $tmp_up   = $_FILES['xls_file']['tmp_name'];
    $ext_up   = strtolower(pathinfo($fname_up, PATHINFO_EXTENSION));

    $autoload = $base_path . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        echo json_encode(['success' => false, 'error' => 'PhpSpreadsheet not installed.']);
        exit();
    }
    require_once $autoload;

    try {
        if ($ext_up === 'csv') {
            $handle_up = fopen($tmp_up, 'r');
            // Strip UTF-8 BOM if present
            $bom_up = fread($handle_up, 3);
            if ($bom_up !== "\xEF\xBB\xBF") rewind($handle_up);
            $raw_hdr = array_map(
                fn($h) => strtolower(trim(str_replace(["\r","\n","\xEF\xBB\xBF"], '', (string)($h ?? '')))),
                fgetcsv($handle_up)
            );
            $data_rows_up = [];
            while (($rr = fgetcsv($handle_up)) !== false) $data_rows_up[] = $rr;
            fclose($handle_up);
        } else {
            $reader_up   = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tmp_up);
            $reader_up->setReadDataOnly(true);
            $spreadsheet_up = $reader_up->load($tmp_up);
            $sheet_up = $spreadsheet_up->getActiveSheet();
            $all_rows_up = $sheet_up->toArray(null, true, true, false);
            $raw_hdr  = array_map(
                fn($h) => strtolower(trim(str_replace(["\r","\n","\xEF\xBB\xBF"], '', (string)($h ?? '')))),
                $all_rows_up[0]
            );
            $data_rows_up = array_slice($all_rows_up, 1);
            // Free memory ASAP
            $spreadsheet_up->disconnectWorksheets();
            unset($spreadsheet_up);
        }
    } catch (\Throwable $ex) {
        echo json_encode(['success' => false, 'error' => 'Cannot read file: ' . $ex->getMessage()]);
        exit();
    }

    $required_up = ['facility_name', 'dit_asset_name', 'purchase_value', 'issue_date', 'service_level'];
    $missing_up  = array_diff($required_up, $raw_hdr);
    if (!empty($missing_up)) {
        echo json_encode(['success' => false,
            'error' => 'Missing columns: ' . implode(', ', $missing_up)]);
        exit();
    }

    $parse_date_up = function($v) {
        if (!$v) return null;
        if (is_numeric($v) && (float)$v > 1000) {
            try { return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d'); }
            catch (Exception $e) { return null; }
        }
        if ($v instanceof \DateTime) return $v->format('Y-m-d');
        if (is_string($v)) { $ts = strtotime($v); return $ts ? date('Y-m-d', $ts) : null; }
        return null;
    };

    $imported_up = 0; $skipped_up = 0; $errors_up = [];
    $cb_up = mysqli_real_escape_string($conn, $created_by);

    foreach ($data_rows_up as $ridx_up => $row_raw_up) {
        if (!is_array($row_raw_up)) { $skipped_up++; continue; }
        $non_empty_up = array_filter($row_raw_up, fn($v) => $v !== null && $v !== '');
        if (empty($non_empty_up)) continue;

        $hdr_cnt_up  = count($raw_hdr);
        $row_safe_up = array_pad(array_slice($row_raw_up, 0, $hdr_cnt_up), $hdr_cnt_up, '');
        $data_up     = @array_combine($raw_hdr, $row_safe_up);
        if ($data_up === false) { $skipped_up++; continue; }

        $r_fname_up    = $e($data_up['facility_name']   ?? '');
        $r_asset_up    = $e($data_up['dit_asset_name']  ?? '');
        $r_pv_up_raw   = $data_up['purchase_value']     ?? 0;
        $r_idate_up    = $parse_date_up($data_up['issue_date'] ?? '');
        $r_slevel_up   = $e($data_up['service_level']   ?? '');

        $r_pv_up_clean = preg_replace('/[^\d.]/', '', (string)$r_pv_up_raw);
        if (!$r_fname_up || !$r_asset_up || !is_numeric($r_pv_up_clean) || !$r_idate_up || !$r_slevel_up) {
            $skipped_up++; continue;
        }

        $r_pv_up = (float)$r_pv_up_clean;
        $r_mfl_up  = $e($data_up['mflcode'] ?? '');

        // Lookup facility
        $fac_up = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT facility_id, facility_name, mflcode, county_name, subcounty_name, latitude, longitude
             FROM facilities
             WHERE " . ($r_mfl_up ? "mflcode='$r_mfl_up'" : "facility_name='$r_fname_up'") . " LIMIT 1"));
        if (!$fac_up) { $skipped_up++; $errors_up[] = "Row ".($ridx_up+2).": facility '$r_fname_up' not found"; continue; }

        $fid_up    = (int)$fac_up['facility_id'];
        $fn_up     = $e($fac_up['facility_name']);
        $mfl_up    = $e($fac_up['mflcode'] ?? '');
        $cty_up    = $e($fac_up['county_name'] ?? '');
        $sub_up    = $e($fac_up['subcounty_name'] ?? '');
        $lat_up    = is_numeric($fac_up['latitude'])  ? (float)$fac_up['latitude']  : 'NULL';
        $lng_up    = is_numeric($fac_up['longitude']) ? (float)$fac_up['longitude'] : 'NULL';

        // Lookup asset type
        $arow_up = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT dit_asset_name, depreciation_percentage FROM digital_investments_assets WHERE dit_asset_name='$r_asset_up' LIMIT 1"));
        if (!$arow_up) { $skipped_up++; $errors_up[] = "Row ".($ridx_up+2).": asset type '$r_asset_up' not found"; continue; }

        $dep_up  = (float)$arow_up['depreciation_percentage'];
        $diff_up = mysqli_fetch_assoc(mysqli_query($conn, "SELECT TIMESTAMPDIFF(MONTH,'$r_idate_up',NOW()) AS m"));
        $mo_up   = max(0, (int)($diff_up['m'] ?? 0));
        $cv_up   = max(0, round($r_pv_up * pow(1 - $dep_up / 100, $mo_up / 12), 2));

        $no_end_up  = (int)(!empty($data_up['no_end_date']) && strtolower($data_up['no_end_date']) !== '0');
        $edate_r_up = $parse_date_up($data_up['end_date'] ?? '');
        $edate_up   = ($no_end_up || !$edate_r_up) ? 'NULL' : "'$edate_r_up'";

        $tag_up     = $e($data_up['tag_name']             ?? '');
        $qty_up     = max(1, (int)($data_up['quantity']   ?? 1));
        $tc_up      = round($r_pv_up * $qty_up, 2);
        $funder_up  = $e($data_up['dig_funder_name']      ?? '');
        $sdp_up     = $e($data_up['sdp_name']             ?? '');
        $emr_up     = $e($data_up['emr_type_name']        ?? '');
        $lot_up     = $e($data_up['lot_number']           ?? '');
        $user_up    = $e($data_up['name_of_user']         ?? '');
        $dept_up    = $e($data_up['department_name']      ?? '');
        $dov_up_r   = $parse_date_up($data_up['date_of_verification'] ?? '');
        $dov_up     = $dov_up_r ? "'$dov_up_r'" : 'NULL';

        $is_s_up = ($no_end_up || !$edate_r_up || strtotime($edate_r_up) >= time()) ? 'Active' : 'Expired';

        $ins_up = "INSERT INTO digital_innovation_investments
                    (facility_id, facility_name, mflcode, county_name, subcounty_name,
                     latitude, longitude,
                     dit_asset_name, asset_name, tag_name, quantity, total_cost,
                     depreciation_percentage, purchase_value,
                     issue_date, end_date, no_end_date, current_value,
                     dig_funder_name, sdp_name, emr_type_name, service_level, lot_number,
                     name_of_user, department_name, date_of_verification,
                     invest_status, created_by, created_at, updated_at)
                   VALUES
                    ($fid_up,'$fn_up','$mfl_up','$cty_up','$sub_up',
                     $lat_up, $lng_up,
                     '$r_asset_up','$r_asset_up','$tag_up',$qty_up,$tc_up,
                     $dep_up,$r_pv_up,
                     '$r_idate_up',$edate_up,$no_end_up,$cv_up,
                     '$funder_up','$sdp_up','$emr_up','$r_slevel_up','$lot_up',
                     '$user_up','$dept_up',$dov_up,
                     '$is_s_up','$cb_up',NOW(),NOW())";

        if (mysqli_query($conn, $ins_up)) { $imported_up++; }
        else { $errors_up[] = "Row ".($ridx_up+2).": ".mysqli_error($conn); $skipped_up++; }
    }

    echo json_encode(['success' => true, 'imported' => $imported_up, 'skipped' => $skipped_up, 'errors' => $errors_up]);
    exit();
}

// ────────────────────────────────────────────────────────────────────
//  LOAD DROPDOWNS
// ────────────────────────────────────────────────────────────────────
$assets_res   = mysqli_query($conn, "SELECT dig_id, dit_asset_name, depreciation_percentage FROM digital_investments_assets ORDER BY dit_asset_name");
$funders_res  = mysqli_query($conn, "SELECT dig_funder_id, dig_funder_name FROM digital_funders ORDER BY dig_funder_name");
$sdps_res     = mysqli_query($conn, "SELECT sdp_id, sdp_name FROM service_delivery_points ORDER BY sdp_name");
$emr_res      = mysqli_query($conn, "SELECT emr_type_id, emr_type_name FROM emr_types ORDER BY emr_type_name");

$assets  = []; while ($r = mysqli_fetch_assoc($assets_res))  $assets[]  = $r;
$funders = []; while ($r = mysqli_fetch_assoc($funders_res)) $funders[] = $r;
$sdps    = []; while ($r = mysqli_fetch_assoc($sdps_res))    $sdps[]    = $r;
$emr_types_arr = []; while ($r = mysqli_fetch_assoc($emr_res)) $emr_types_arr[] = $r;

// ────────────────────────────────────────────────────────────────────
//  LOAD EXISTING RECORDS (for the table/list view)
// ────────────────────────────────────────────────────────────────────
// Aggregate stats (ALL records — no LIMIT)
$stats_row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*)                                                   AS total_records,
        COUNT(DISTINCT facility_id)                                AS total_facilities,
        SUM(CASE WHEN invest_status='Active' THEN 1 ELSE 0 END)  AS active_count,
        COALESCE(SUM(purchase_value), 0)                           AS total_pv,
        COALESCE(SUM(current_value),  0)                           AS total_cv,
        COALESCE(SUM(total_cost),     0)                           AS total_tc
    FROM digital_innovation_investments
"));

// Full record list (no LIMIT — all records displayed)
$list_res = mysqli_query($conn, "
    SELECT *
    FROM   digital_innovation_investments
    ORDER BY created_at DESC
");
$investments = [];
while ($r = mysqli_fetch_assoc($list_res)) $investments[] = $r;

// Edit pre-fill
$edit_row = null;
if (isset($_GET['edit'])) {
    $eid     = $i($_GET['edit']);
    $edit_row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT * FROM digital_innovation_investments WHERE invest_id=$eid LIMIT 1"));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Digital Innovations Investments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* ── Root tokens ─────────────────────────────────────────────────────── */
:root{
  --primary:#2D008A;
  --primary2:#4B00C8;
  --lilac:#AC80EE;
  --pink:#FFDCF9;
  --green:#04B04B;
  --amber:#FFC12E;
  --red:#E41E39;
  --bg:#f4f2fb;
  --card:#fff;
  --border:#e2d9f3;
  --muted:#6B7280;
  --shadow:0 2px 18px rgba(45,0,138,.10);
  --radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#1a1e2e;line-height:1.6;}
.wrap{max-width:85%;margin:0 auto;padding:20px 16px;}

/* ── Header ─────────────────────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--primary),var(--primary2));
  color:#fff;padding:20px 28px;border-radius:var(--radius);
  margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;
  box-shadow:0 6px 28px rgba(45,0,138,.28);}
.page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.hdr-links a{
  color:#fff;text-decoration:none;background:rgba(255,255,255,.15);
  padding:7px 15px;border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.hdr-links a:hover{background:rgba(255,255,255,.3);}

/* ── Tabs ────────────────────────────────────────────────────────────── */
.tabs{display:flex;gap:0;margin-bottom:22px;background:var(--card);
  border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);}
.tab-btn{flex:1;padding:13px 10px;border:none;background:transparent;cursor:pointer;
  font-size:13.5px;font-weight:600;color:var(--muted);transition:.2s;
  border-bottom:3px solid transparent;display:flex;align-items:center;justify-content:center;gap:8px;}
.tab-btn:hover{background:#f4f2fb;color:var(--primary);}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary);background:var(--pink);}

/* ── Card ────────────────────────────────────────────────────────────── */
.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);
  overflow:hidden;margin-bottom:22px;}
.card-head{
  background:linear-gradient(90deg,var(--primary),var(--primary2));
  color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center;}
.card-head h3{font-size:14px;font-weight:700;display:flex;align-items:center;gap:9px;}
.card-body{padding:24px;}

/* ── Form grid ───────────────────────────────────────────────────────── */
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;}
.form-grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
.form-group{margin-bottom:0;}
.form-group.full{grid-column:1/-1;}
.form-group label{display:block;margin-bottom:5px;font-weight:600;color:#374151;font-size:13px;}
.form-group label span.req{color:var(--red);margin-left:2px;}

.form-control,
.form-select{
  width:100%;padding:10px 14px;border:1.5px solid var(--border);
  border-radius:9px;font-size:13.5px;font-family:inherit;
  background:#fff;transition:.2s;color:#1a1e2e;}
.form-control:focus,.form-select:focus{
  outline:none;border-color:var(--primary);
  box-shadow:0 0 0 3px rgba(45,0,138,.10);}
.form-control[readonly]{background:#f8f7fe;color:#555;}

/* current-value display */
.current-value-display{
  background:linear-gradient(135deg,var(--primary),var(--primary2));
  color:#fff;border-radius:9px;padding:10px 16px;
  font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;
  letter-spacing:.3px;}
.cv-label{font-size:11px;font-weight:400;opacity:.8;display:block;margin-bottom:2px;}

/* ── Stats bar ───────────────────────────────────────────────────────── */
.stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);
  padding:14px 18px;display:flex;align-items:center;gap:14px;}
.stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;
  font-size:18px;flex-shrink:0;}
.stat-value{font-size:1.15rem;font-weight:700;color:#1a1e2e;line-height:1.2;}
.stat-label{font-size:11px;color:var(--muted);margin-top:2px;}

/* ── Dep badge ───────────────────────────────────────────────────────── */
.dep-badge{display:inline-flex;align-items:center;gap:6px;
  background:var(--pink);color:var(--primary);
  border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;
  margin-top:6px;border:1px solid var(--lilac);}

/* ── Checkbox row ────────────────────────────────────────────────────── */
.cb-row{display:flex;align-items:center;gap:9px;margin-top:8px;}
.cb-row input[type=checkbox]{width:17px;height:17px;accent-color:var(--primary);}
.cb-row label{font-weight:500;font-size:13px;color:#374151;cursor:pointer;}

/* ── Service level radio group ───────────────────────────────────────── */
.sl-group{display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;}
.sl-opt{display:flex;align-items:center;gap:8px;padding:9px 18px;
  border:2px solid var(--border);border-radius:9px;cursor:pointer;
  font-size:13px;font-weight:600;transition:.2s;background:#fff;}
.sl-opt:hover{border-color:var(--lilac);background:var(--pink);}
.sl-opt input[type=radio]{accent-color:var(--primary);width:16px;height:16px;}
.sl-opt.selected{border-color:var(--primary);background:var(--pink);color:var(--primary);}

/* ── Buttons ─────────────────────────────────────────────────────────── */
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

/* ── Facility search ─────────────────────────────────────────────────── */
.search-wrap{position:relative;}
.search-wrap input{
  width:100%;padding:11px 44px 11px 14px;border:1.5px solid var(--border);
  border-radius:9px;font-size:13.5px;background:#fff;transition:.2s;font-family:inherit;}
.search-wrap input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(45,0,138,.10);}
.s-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#aaa;pointer-events:none;}
.s-spinner{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--primary);display:none;}
.results-dropdown{
  position:absolute;z-index:999;width:100%;background:#fff;
  border:1.5px solid var(--border);border-radius:10px;margin-top:4px;
  box-shadow:0 8px 28px rgba(45,0,138,.15);max-height:260px;overflow-y:auto;display:none;}
.result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:.15s;}
.result-item:last-child{border-bottom:none;}
.result-item:hover{background:var(--pink);}
.ri-name{font-weight:700;color:var(--primary);font-size:13px;}
.ri-meta{font-size:11px;color:#777;margin-top:2px;}
.ri-badge{font-size:10px;background:var(--pink);color:var(--primary);
  border-radius:4px;padding:1px 6px;margin-left:4px;font-weight:600;}
.no-results{padding:14px;color:#999;font-size:13px;text-align:center;}

/* ── Facility card ───────────────────────────────────────────────────── */
.facility-card{
  border:2px solid var(--primary);border-radius:10px;padding:14px 18px;
  background:linear-gradient(135deg,var(--pink),#fff);margin-top:8px;display:none;}
.fac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;margin-top:8px;}
.fg label{font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;
  color:#999;font-weight:700;display:block;margin-bottom:1px;}
.fg span{font-size:12.5px;color:#222;font-weight:500;}

/* ── Table ───────────────────────────────────────────────────────────── */
.tbl-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
thead tr{background:linear-gradient(90deg,var(--primary),var(--primary2));color:#fff;}
thead th{padding:10px 12px;text-align:left;font-weight:700;white-space:nowrap;}
tbody tr{border-bottom:1px solid #f0eeff;transition:.15s;}
tbody tr:hover{background:var(--pink);}
tbody td{padding:9px 12px;vertical-align:middle;}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;
  border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:#d4f8e5;color:var(--green);}
.badge-expired{background:#fde8eb;color:var(--red);}
.badge-fw{background:var(--pink);color:var(--primary);}
.badge-sdp{background:#fff3cc;color:#7a5800;}

/* ── Toast ───────────────────────────────────────────────────────────── */
.toast{position:fixed;bottom:24px;right:24px;background:#1a1e2e;color:#fff;
  padding:12px 22px;border-radius:10px;font-size:13.5px;font-weight:600;
  display:flex;align-items:center;gap:9px;z-index:9999;
  transform:translateY(80px);opacity:0;transition:.35s;pointer-events:none;}
.toast.show{transform:translateY(0);opacity:1;}
.toast.success .toast-icon{color:var(--green);}
.toast.error   .toast-icon{color:var(--red);}

/* ── CSV import area ─────────────────────────────────────────────────── */
.csv-drop{
  border:2px dashed var(--lilac);border-radius:12px;padding:32px 20px;
  text-align:center;cursor:pointer;transition:.2s;background:#faf8ff;}
.csv-drop:hover,.csv-drop.drag-over{background:var(--pink);border-color:var(--primary);}
.csv-drop i{font-size:2.5rem;color:var(--lilac);margin-bottom:10px;}
.csv-drop p{font-size:13.5px;color:var(--muted);}
.csv-drop strong{color:var(--primary);}

/* ── Alert ───────────────────────────────────────────────────────────── */
.alert{padding:12px 18px;border-radius:9px;margin-bottom:18px;
  font-size:13.5px;display:flex;align-items:flex-start;gap:10px;}
.alert-success{background:#d4f8e5;color:#0a5c2e;border:1px solid #a8e6c1;}
.alert-error{background:#fde8eb;color:#7a0011;border:1px solid #f5b8c0;}
.alert-info{background:var(--pink);color:var(--primary);border:1px solid var(--lilac);}

/* ── Search / filter bar ─────────────────────────────────────────────── */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center;}
.filter-bar input,.filter-bar select{
  padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;
  font-size:13px;font-family:inherit;flex:1;min-width:160px;max-width:240px;}
.filter-bar input:focus,.filter-bar select:focus{
  outline:none;border-color:var(--primary);}

/* ── Section divider label ───────────────────────────────────────────── */
.divider-label{
  font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;
  color:var(--primary);display:flex;align-items:center;gap:8px;margin:20px 0 12px;}
.divider-label::after{content:'';flex:1;height:1px;background:var(--border);}

/* ── Responsive ──────────────────────────────────────────────────────── */
@media(max-width:680px){
  .page-header{flex-direction:column;gap:12px;align-items:flex-start;}
  .tabs{flex-direction:column;}
  .tab-btn{border-bottom:none;border-right:3px solid transparent;}
  .tab-btn.active{border-right-color:var(--primary);}
}

/* ── Modal ───────────────────────────────────────────────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);
  z-index:8000;display:none;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:var(--radius);width:min(540px,95vw);
  overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25);}
.modal-head{background:linear-gradient(90deg,var(--primary),var(--primary2));
  color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center;}
.modal-head h4{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;}
.modal-body{padding:22px;}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);
  display:flex;gap:10px;justify-content:flex-end;}
</style>
</head>
<body>
<div class="wrap">

<!-- ── PAGE HEADER ──────────────────────────────────────────────────── -->
<div class="page-header">
    <h1><i class="fas fa-laptop-medical"></i> Digital Innovations Investments</h1>
    <div class="hdr-links">
        <a href="javascript:void(0)" onclick="showTab('form')"><i class="fas fa-plus"></i> New Record</a>
        <a href="javascript:void(0)" onclick="showTab('list')"><i class="fas fa-list"></i> All Records</a>
        <a href="javascript:void(0)" onclick="showTab('csv')"><i class="fas fa-file-csv"></i> Import CSV</a>
        <a href="javascript:void(0)" onclick="showTab('excel')"><i class="fas fa-file-excel"></i> Import Excel</a>
        <a href="asset_master_register.php"><i class="fas fa-clipboard-list"></i> Asset Register</a>
        <a href="../dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
    </div>
</div>

<!-- ── TABS ─────────────────────────────────────────────────────────── -->
<div class="tabs" id="mainTabs">
    <button class="tab-btn active" id="tab_form" onclick="showTab('form')">
        <i class="fas fa-plus-circle"></i> Add / Edit Record
    </button>
    <button class="tab-btn" id="tab_list" onclick="showTab('list')">
        <i class="fas fa-table"></i> All Investments
    </button>
    <button class="tab-btn" id="tab_csv" onclick="showTab('csv')">
        <i class="fas fa-file-csv"></i> Import CSV
    </button>
    <button class="tab-btn" id="tab_excel" onclick="showTab('excel')">
        <i class="fas fa-file-excel"></i> Import Excel
    </button>
</div>

<div id="globalAlert"></div>

<!-- ── SUMMARY STATS BAR ─────────────────────────────────────────────── -->
<?php
$s_total     = (int)($stats_row['total_records']   ?? 0);
$s_fac       = (int)($stats_row['total_facilities'] ?? 0);
$s_active    = (int)($stats_row['active_count']    ?? 0);
$s_pv        = (float)($stats_row['total_pv']      ?? 0);
$s_cv        = (float)($stats_row['total_cv']      ?? 0);
$s_tc        = (float)($stats_row['total_tc']      ?? 0);
$s_dep       = max(0, $s_pv - $s_cv);
?>
<div class="stats-bar">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8deff;color:var(--primary)"><i class="fas fa-laptop-medical"></i></div>
        <div>
            <div class="stat-value"><?= number_format($s_total) ?></div>
            <div class="stat-label">Total Records</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#d4f8e5;color:var(--green)"><i class="fas fa-check-circle"></i></div>
        <div>
            <div class="stat-value" style="color:var(--green)"><?= number_format($s_active) ?></div>
            <div class="stat-label">Active</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff3cc;color:#7a5800"><i class="fas fa-hospital"></i></div>
        <div>
            <div class="stat-value" style="color:#7a5800"><?= number_format($s_fac) ?></div>
            <div class="stat-label">Facilities</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8deff;color:var(--primary2)"><i class="fas fa-coins"></i></div>
        <div>
            <div class="stat-value">KES <?= number_format($s_pv, 0) ?></div>
            <div class="stat-label">Total Purchase Value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#d4f8e5;color:var(--green)"><i class="fas fa-chart-line"></i></div>
        <div>
            <div class="stat-value" style="color:var(--green)">KES <?= number_format($s_cv, 0) ?></div>
            <div class="stat-label">Current Book Value</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fde8eb;color:var(--red)"><i class="fas fa-arrow-down"></i></div>
        <div>
            <div class="stat-value" style="color:var(--red)">KES <?= number_format($s_dep, 0) ?></div>
            <div class="stat-label">Total Depreciation</div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     TAB 1 — FORM
═══════════════════════════════════════════════════════════════════ -->
<div id="pane_form">

<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-hospital-alt"></i> Facility Selection</h3>
    </div>
    <div class="card-body">
        <div class="form-group">
            <label>Facility <span class="req">*</span></label>
            <small style="color:var(--muted);display:block;margin-bottom:6px">
                Type the facility name or MFL code — selecting fills all location fields.
                <strong style="color:var(--red)">MFL Code is most precise.</strong>
            </small>
            <div class="search-wrap" id="facSearchWrap">
                <input type="text" id="facilitySearch" placeholder="Type facility name or MFL code…" autocomplete="off">
                <i class="fas fa-hospital s-icon" id="facIcon"></i>
                <i class="fas fa-spinner fa-spin s-spinner" id="facSpinner"></i>
                <div class="results-dropdown" id="facResults"></div>
            </div>
        </div>

        <div class="facility-card" id="facilityCard">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <strong style="color:var(--primary);font-size:14px" id="fc_name"></strong>
                <button type="button" onclick="clearFacility()"
                    style="background:none;border:none;color:var(--red);cursor:pointer;font-size:13px">
                    <i class="fas fa-times-circle"></i> Change
                </button>
            </div>
            <div class="fac-grid">
                <div class="fg"><label>MFL Code</label><span id="fc_mfl">—</span></div>
                <div class="fg"><label>County</label><span id="fc_county">—</span></div>
                <div class="fg"><label>Sub-County</label><span id="fc_subcounty">—</span></div>
                <div class="fg"><label>Level of Care</label><span id="fc_level">—</span></div>
                <div class="fg"><label>Owner</label><span id="fc_owner">—</span></div>
                <div class="fg"><label>SDP</label><span id="fc_sdp_fac">—</span></div>
                <div class="fg"><label>Agency</label><span id="fc_agency">—</span></div>
                <div class="fg"><label>EMR</label><span id="fc_emr">—</span></div>
                <div class="fg"><label>Latitude</label><span id="fc_lat">—</span></div>
                <div class="fg"><label>Longitude</label><span id="fc_lng">—</span></div>
            </div>
        </div>

        <!-- Hidden facility fields -->
        <input type="hidden" id="h_facility_id"   value="<?= $edit_row ? htmlspecialchars($edit_row['facility_id']) : '' ?>">
        <input type="hidden" id="h_facility_name" value="<?= $edit_row ? htmlspecialchars($edit_row['facility_name']) : '' ?>">
        <input type="hidden" id="h_mflcode"       value="<?= $edit_row ? htmlspecialchars($edit_row['mflcode']) : '' ?>">
        <input type="hidden" id="h_county"        value="<?= $edit_row ? htmlspecialchars($edit_row['county_name']) : '' ?>">
        <input type="hidden" id="h_subcounty"     value="<?= $edit_row ? htmlspecialchars($edit_row['subcounty_name']) : '' ?>">
        <input type="hidden" id="h_invest_id"     value="<?= $edit_row ? htmlspecialchars($edit_row['invest_id']) : '' ?>">
        <input type="hidden" id="h_latitude"      value="<?= $edit_row ? htmlspecialchars($edit_row['latitude'] ?? '') : '' ?>">
        <input type="hidden" id="h_longitude"     value="<?= $edit_row ? htmlspecialchars($edit_row['longitude'] ?? '') : '' ?>">
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-laptop"></i> Digital Asset Details</h3>
    </div>
    <div class="card-body">

        <div class="divider-label"><i class="fas fa-microchip"></i> Asset Information</div>
        <div class="form-grid">

            <!-- Asset from Master Register -->
            <div class="form-group full" style="margin-bottom:0">
                <label><i class="fas fa-clipboard-list" style="color:var(--primary)"></i>
                  Link to Asset Master Register
                  <small style="font-weight:400;color:var(--muted)">(search by name, serial or model — auto-fills details)</small>
                </label>
                <div style="position:relative">
                  <input type="text" id="registerSearch" class="form-control"
                         placeholder="Type asset name, serial number or model…" autocomplete="off">
                  <div id="registerDropdown" style="
                    position:absolute;z-index:999;width:100%;background:#fff;
                    border:1.5px solid var(--border);border-radius:10px;margin-top:4px;
                    box-shadow:0 8px 28px rgba(45,0,138,.15);max-height:220px;overflow-y:auto;display:none;">
                  </div>
                </div>
                <div id="registerCard" style="display:none;margin-top:8px;
                  border:2px solid var(--primary);border-radius:10px;padding:10px 16px;
                  background:linear-gradient(135deg,var(--pink),#fff)">
                  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                    <strong style="color:var(--primary);font-size:13px" id="rc_name"></strong>
                    <button type="button" onclick="clearRegisterAsset()"
                      style="background:none;border:none;color:var(--red);cursor:pointer;font-size:12px">
                      <i class="fas fa-times-circle"></i> Clear
                    </button>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;font-size:12px">
                    <div><span style="color:#999;font-size:10px;display:block">Model</span><span id="rc_model">—</span></div>
                    <div><span style="color:#999;font-size:10px;display:block">Serial</span><span id="rc_serial">—</span></div>
                    <div><span style="color:#999;font-size:10px;display:block">Category</span><span id="rc_cat">—</span></div>
                    <div><span style="color:#999;font-size:10px;display:block">Funder</span><span id="rc_funder">—</span></div>
                    <div><span style="color:#999;font-size:10px;display:block">LPO No.</span><span id="rc_lpo">—</span></div>
                    <div><span style="color:#999;font-size:10px;display:block">Condition</span><span id="rc_condition">—</span></div>
                  </div>
                </div>
                <input type="hidden" id="h_asset_id_reg" value="<?= $edit_row ? htmlspecialchars($edit_row['asset_id'] ?? '') : '' ?>">
            </div>

            <!-- Digital Asset Type -->
            <div class="form-group">
                <label>Asset Type <span class="req">*</span></label>
                <select id="dig_id" class="form-select" onchange="onAssetChange(this.value)">
                    <option value="">-- Select Asset Type --</option>
                    <?php foreach ($assets as $a): ?>
                    <option value="<?= $a['dig_id'] ?>"
                            data-dep="<?= $a['depreciation_percentage'] ?>"
                            data-name="<?= htmlspecialchars($a['dit_asset_name']) ?>"
                        <?= ($edit_row && $edit_row['dit_asset_name'] == $a['dit_asset_name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['dit_asset_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="depBadge" style="display:none" class="dep-badge">
                    <i class="fas fa-chart-line"></i>
                    Depreciation: <strong id="depPct">0</strong>% per annum
                </div>
            </div>

            <!-- Tag Name -->
            <div class="form-group">
                <label>Asset Tag / Label <span class="req">*</span>
                  <small style="font-weight:400;color:var(--muted)">(barcode / physical tag)</small>
                </label>
                <input type="text" id="tag_name" class="form-control"
                       placeholder="e.g. L12611"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['tag_name'] ?? '') : '' ?>">
            </div>

            <!-- Quantity + Total Cost -->
            <div class="form-group">
                <label>Quantity <span class="req">*</span></label>
                <input type="number" id="quantity" class="form-control"
                       min="1" step="1" value="<?= $edit_row ? (int)($edit_row['quantity'] ?? 1) : 1 ?>"
                       oninput="calcTotalCost()">
            </div>

            <div class="form-group">
                <label>Total Cost (Auto-calculated)</label>
                <div class="current-value-display" id="totalCostDisplay">
                  <div>
                    <span class="cv-label">Total Cost (Qty × Purchase Value)</span>
                    <span id="totalCostAmount">KES 0.00</span>
                  </div>
                  <i class="fas fa-equals" style="margin-left:auto;opacity:.6;font-size:1.3rem"></i>
                </div>
                <input type="hidden" id="total_cost_hidden"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['total_cost'] ?? '0') : '0' ?>">
            </div>

            <!-- Funder -->
            <div class="form-group">
                <label>Funder <span class="req">*</span></label>
                <select id="dig_funder_id" class="form-select">
                    <option value="">-- Select Funder --</option>
                    <?php foreach ($funders as $fn): ?>
                    <option value="<?= htmlspecialchars($fn['dig_funder_name']) ?>"
                        <?= ($edit_row && $edit_row['dig_funder_name'] == $fn['dig_funder_name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fn['dig_funder_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- EMR Type -->
            <div class="form-group">
                <label>Type of EMR <span class="req">*</span></label>
                <select id="emr_type_id" class="form-select">
                    <option value="">-- Select EMR Type --</option>
                    <?php foreach ($emr_types_arr as $em): ?>
                    <option value="<?= htmlspecialchars($em['emr_type_name']) ?>"
                        <?= ($edit_row && $edit_row['emr_type_name'] == $em['emr_type_name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($em['emr_type_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Service Level -->
            <div class="form-group">
                <label>Service Level <span class="req">*</span></label>
                <div class="sl-group" id="serviceLevelGroup">
                    <label class="sl-opt <?= (!$edit_row || $edit_row['service_level']==='Facility-wide') ? 'selected' : '' ?>">
                        <input type="radio" name="service_level" value="Facility-wide"
                               <?= (!$edit_row || $edit_row['service_level']==='Facility-wide') ? 'checked' : '' ?>
                               onchange="onServiceLevelChange(this.value)">
                        <i class="fas fa-hospital"></i> Facility-wide
                    </label>
                    <label class="sl-opt <?= ($edit_row && $edit_row['service_level']==='Service Delivery Point') ? 'selected' : '' ?>">
                        <input type="radio" name="service_level" value="Service Delivery Point"
                               <?= ($edit_row && $edit_row['service_level']==='Service Delivery Point') ? 'checked' : '' ?>
                               onchange="onServiceLevelChange(this.value)">
                        <i class="fas fa-map-pin"></i> Service Delivery Point
                    </label>
                </div>
            </div>

            <!-- SDP (conditional on service level) -->
            <div class="form-group" id="sdpGroup"
                 style="display:<?= ($edit_row && $edit_row['service_level']==='Service Delivery Point') ? 'block' : 'none' ?>">
                <label>Service Delivery Point <span class="req">*</span></label>
                <select id="sdp_id" class="form-select">
                    <option value="">-- Select SDP --</option>
                    <?php foreach ($sdps as $sp): ?>
                    <option value="<?= htmlspecialchars($sp['sdp_name']) ?>"
                        <?= ($edit_row && $edit_row['sdp_name'] == $sp['sdp_name']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sp['sdp_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Lot Number -->
            <div class="form-group">
                <label>Lot Number <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
                <input type="text" id="lot_number" class="form-control"
                       placeholder="e.g. LOT-2024-001"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['lot_number'] ?? '') : '' ?>">
            </div>

            <!-- Name of User -->
            <div class="form-group">
                <label>Name of User / Assigned Staff</label>
                <input type="text" id="name_of_user" class="form-control"
                       placeholder="e.g. TITUS TSUMA"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['name_of_user'] ?? '') : '' ?>">
            </div>

            <!-- Department -->
            <div class="form-group">
                <label>Department / Service</label>
                <input type="text" id="department_name" class="form-control"
                       placeholder="e.g. CCC, MCH, OPD"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['department_name'] ?? '') : '' ?>">
            </div>

            <!-- Date of Verification -->
            <div class="form-group">
                <label>Date of Verification</label>
                <input type="date" id="date_of_verification" class="form-control"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['date_of_verification'] ?? '') : '' ?>">
            </div>

            <!-- Date of Disposal -->
            <div class="form-group">
                <label>Date of Disposal</label>
                <input type="date" id="date_of_disposal_inv" class="form-control"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['date_of_disposal'] ?? '') : '' ?>">
            </div>

        </div>

        <div class="divider-label"><i class="fas fa-calendar-alt"></i> Dates &amp; Valuation</div>
        <div class="form-grid">

            <!-- Purchase / Initial Value -->
            <div class="form-group">
                <label>Purchase Value (KES) <span class="req">*</span></label>
                <input type="number" id="purchase_value" class="form-control" min="0" step="0.01"
                       placeholder="0.00" oninput="calcCurrentValue()"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['purchase_value'] ?? '') : '' ?>">
            </div>

            <!-- Issue Date -->
            <div class="form-group">
                <label>Issue Date <span class="req">*</span>
                  <small style="font-weight:400;color:var(--muted)">(defaults to today — editable)</small>
                </label>
                <input type="date" id="issue_date" class="form-control"
                       oninput="calcCurrentValue()"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['issue_date'] ?? '') : date('Y-m-d') ?>">
            </div>

            <!-- End Date -->
            <div class="form-group">
                <label>End Date</label>
                <input type="date" id="end_date" class="form-control"
                       value="<?= $edit_row ? htmlspecialchars($edit_row['end_date'] ?? '') : '' ?>"
                       <?= ($edit_row && $edit_row['no_end_date']) ? 'disabled' : '' ?>>
                <div class="cb-row">
                    <input type="checkbox" id="no_end_date"
                           <?= ($edit_row && $edit_row['no_end_date']) ? 'checked' : '' ?>
                           onchange="toggleEndDate(this)">
                    <label for="no_end_date">This investment does not have an end date</label>
                </div>
            </div>

            <!-- Current Value (calculated, read-only) -->
            <div class="form-group">
                <label>Current Value (Auto-calculated)</label>
                <div class="current-value-display" id="cvDisplay">
                    <div>
                        <span class="cv-label">Estimated Current Value</span>
                        <span id="cvAmount">KES 0.00</span>
                    </div>
                    <i class="fas fa-calculator" style="margin-left:auto;opacity:.6;font-size:1.3rem"></i>
                </div>
                <input type="hidden" id="current_value_hidden" value="0">
                <small style="color:var(--muted);font-size:11px;margin-top:4px;display:block">
                    <i class="fas fa-info-circle"></i>
                    Reducing balance formula: PV × (1 − dep%)^(months/12). Updated automatically every month.
                </small>
            </div>

        </div>

        <div class="btn-group">
            <button class="btn btn-primary" onclick="saveRecord()">
                <i class="fas fa-save"></i> Save Investment
            </button>
            <button class="btn btn-outline" onclick="resetForm()">
                <i class="fas fa-undo"></i> Reset Form
            </button>
        </div>
    </div>
</div>

</div><!-- /pane_form -->

<!-- ══════════════════════════════════════════════════════════════════
     TAB 2 — ALL RECORDS
═══════════════════════════════════════════════════════════════════ -->
<div id="pane_list" style="display:none">

<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-table"></i> All Digital Innovation Investments</h3>
        <button class="btn btn-green" style="padding:7px 14px;font-size:12px" onclick="showTab('form')">
            <i class="fas fa-plus"></i> Add New
        </button>
    </div>
    <div class="card-body">
        <div class="filter-bar">
            <input type="text" id="searchInput" placeholder="🔍  Search facility, asset…"
                   oninput="filterTable()">
            <select id="filterStatus" onchange="filterTable()">
                <option value="">All Statuses</option>
                <option value="Active">Active</option>
                <option value="Expired">Expired</option>
            </select>
            <select id="filterLevel" onchange="filterTable()">
                <option value="">All Service Levels</option>
                <option value="Facility-wide">Facility-wide</option>
                <option value="Service Delivery Point">Service Delivery Point</option>
            </select>
        </div>

        <div class="tbl-wrap">
        <table id="investTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Facility</th>
                    <th>MFL</th>
                    <th>Asset</th>
                    <th>Tag Name</th>
                    <th>Qty</th>
                    <th>Total Cost</th>
                    <th>Funder</th>
                    <th>Purchase Value</th>
                    <th>Current Value</th>
                    <th>Dep %</th>
                    <th>Issue Date</th>
                    <th>End Date</th>
                    <th>EMR Type</th>
                    <th>Service Level</th>
                    <th>SDP</th>
                    <th>Dept.</th>
                    <th>User</th>
                    <th>Lat</th>
                    <th>Lng</th>
                    <th>Lot #</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="investTbody">
            <?php foreach ($investments as $idx => $inv): ?>
            <tr data-status="<?= htmlspecialchars($inv['invest_status']) ?>"
                data-level="<?= htmlspecialchars($inv['service_level']) ?>">
                <td><?= $idx + 1 ?></td>
                <td><strong><?= htmlspecialchars($inv['facility_name']) ?></strong>
                    <br><small style="color:var(--muted)"><?= htmlspecialchars($inv['county_name'] ?? '') ?></small></td>
                <td><?= htmlspecialchars($inv['mflcode'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inv['asset_name']) ?></td>
                <td><?= htmlspecialchars($inv['tag_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inv['quantity'] ?? 1) ?></td>
                <td><strong>KES <?= number_format((float)($inv['total_cost'] ?? 0), 2) ?></strong></td>
                <td><?= htmlspecialchars($inv['dig_funder_name'] ?? '—') ?></td>
                <td>KES <?= number_format((float)$inv['purchase_value'], 2) ?></td>
                <td><strong style="color:var(--primary)">KES <?= number_format((float)$inv['current_value'], 2) ?></strong></td>
                <td><?= htmlspecialchars($inv['depreciation_percentage']) ?>%</td>
                <td><?= htmlspecialchars($inv['issue_date'] ?? '—') ?></td>
                <td><?= $inv['no_end_date'] ? '<em style="color:var(--green)">No End</em>' : htmlspecialchars($inv['end_date'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inv['emr_type_name'] ?? '—') ?></td>
                <td>
                    <span class="badge <?= $inv['service_level'] === 'Facility-wide' ? 'badge-fw' : 'badge-sdp' ?>">
                        <?= htmlspecialchars($inv['service_level']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($inv['sdp_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inv['department_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($inv['name_of_user'] ?? '—') ?></td>
                <td><small><?= $inv['latitude'] ? number_format((float)$inv['latitude'],5) : '—' ?></small></td>
                <td><small><?= $inv['longitude'] ? number_format((float)$inv['longitude'],5) : '—' ?></small></td>
                <td><?= htmlspecialchars($inv['lot_number'] ?? '—') ?></td>
                <td>
                    <span class="badge <?= $inv['invest_status'] === 'Active' ? 'badge-active' : 'badge-expired' ?>">
                        <i class="fas <?= $inv['invest_status'] === 'Active' ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                        <?= htmlspecialchars($inv['invest_status']) ?>
                    </span>
                </td>
                <td style="white-space:nowrap">
                    <a href="?edit=<?= $inv['invest_id'] ?>" class="btn btn-amber"
                       style="padding:5px 10px;font-size:11px">
                       <i class="fas fa-edit"></i> Edit
                    </a>
                    <button class="btn btn-red" style="padding:5px 10px;font-size:11px"
                            onclick="deleteRecord(<?= $inv['invest_id'] ?>, '<?= htmlspecialchars($inv['asset_name'], ENT_QUOTES) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($investments)): ?>
            <tr><td colspan="16" style="text-align:center;color:var(--muted);padding:30px">
                <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px"></i>
                No records found. Click <strong>Add New</strong> to start.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

</div><!-- /pane_list -->

<!-- ══════════════════════════════════════════════════════════════════
     TAB 3 — CSV IMPORT
═══════════════════════════════════════════════════════════════════ -->
<div id="pane_csv" style="display:none">

<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-file-csv"></i> Import Records via CSV</h3>
    </div>
    <div class="card-body">

        <div class="alert alert-info">
            <i class="fas fa-info-circle" style="font-size:1.2rem;flex-shrink:0"></i>
            <div>
                <strong>CSV Format Requirements</strong><br>
                Your CSV file must include these column headers (first row):<br>
                <code style="background:#e8deff;padding:2px 6px;border-radius:4px;font-size:12px">
                facility_name, dit_asset_name, purchase_value, issue_date, service_level
                </code>
                &nbsp;— required<br>
                <code style="background:#f0f8ff;padding:2px 6px;border-radius:4px;font-size:12px">
                mflcode, county_name, subcounty_name, end_date, no_end_date, dig_funder_name, sdp_name, emr_type_name, lot_number
                </code>
                &nbsp;— optional<br>
                <strong>Dates:</strong> YYYY-MM-DD &nbsp;|&nbsp;
                <strong>no_end_date:</strong> 1 = no end, 0 = has end &nbsp;|&nbsp;
                <strong>service_level:</strong> <em>Facility-wide</em> or <em>Service Delivery Point</em>
            </div>
        </div>

        <div class="csv-drop" id="csvDrop" onclick="document.getElementById('csvFile').click()">
            <i class="fas fa-cloud-upload-alt"></i>
            <p><strong>Click to browse</strong> or drag &amp; drop your CSV file here</p>
            <p id="csvFileName" style="margin-top:8px;color:var(--primary);font-weight:600"></p>
        </div>
        <input type="file" id="csvFile" accept=".csv,text/csv" style="display:none" onchange="onCsvFileChange(this)">

        <div class="btn-group">
            <button class="btn btn-primary" id="btnImport" disabled onclick="importCsv()">
                <i class="fas fa-file-import"></i> Import Records
            </button>
            <a href="#" id="csvTemplateLink" class="btn btn-outline" onclick="downloadTemplate(event)">
                <i class="fas fa-download"></i> Download Template
            </a>
        </div>

        <div id="importResult" style="margin-top:18px"></div>
    </div>
</div>

</div><!-- /pane_csv -->

<!-- ══════════════════════════════════════════════════════════════════
     TAB 4 — EXCEL IMPORT
═══════════════════════════════════════════════════════════════════ -->
<div id="pane_excel" style="display:none">
<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-file-excel"></i> Import Records via Excel (.xlsx / .xls)</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle" style="font-size:1.2rem;flex-shrink:0"></i>
            <div>
                <strong>Excel / CSV Import — Issuance Records</strong><br>
                Upload your <em>assets_master_register.xlsx</em> or any CSV/Excel with these columns:<br>
                <code style="background:#e8deff;padding:2px 6px;border-radius:4px;font-size:12px">
                facility_name, dit_asset_name, purchase_value, issue_date, service_level
                </code> — required<br>
                <code style="background:#f0f8ff;padding:2px 6px;border-radius:4px;font-size:12px">
                mflcode, tag_name, quantity, dig_funder_name, county_name, subcounty_name,
                name_of_user, department_name, lpo_number, end_date, no_end_date,
                sdp_name, emr_type_name, lot_number, date_of_verification
                </code> — optional<br>
                <strong>Dates:</strong> YYYY-MM-DD &nbsp;|&nbsp;
                <strong>Tip:</strong> The existing <em>assets_master_register.xlsx</em> in the digitization folder is compatible.
            </div>
        </div>
        <div class="csv-drop" id="xlsDrop" onclick="document.getElementById('xlsFile').click()">
            <i class="fas fa-file-excel" style="color:#107c41"></i>
            <p><strong>Click to browse</strong> or drag &amp; drop your Excel or CSV file</p>
            <p id="xlsFileName" style="margin-top:8px;color:var(--primary);font-weight:600"></p>
        </div>
        <input type="file" id="xlsFile" accept=".xlsx,.xls,.csv" style="display:none" onchange="onXlsFileChange(this)">
        <div class="btn-group">
            <button class="btn btn-primary" id="btnXlsImport" disabled onclick="importXls()">
                <i class="fas fa-file-import"></i> Import Records
            </button>
            <a href="asset_master_register.php?tab=import" class="btn btn-outline">
                <i class="fas fa-clipboard-list"></i> Import to Asset Register Instead
            </a>
        </div>
        <div id="xlsImportResult" style="margin-top:18px"></div>
    </div>
</div>
</div><!-- /pane_excel -->

</div><!-- /wrap -->

<!-- ── DELETE CONFIRM MODAL ─────────────────────────────────────────── -->
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
            <p>Are you sure you want to delete the investment record for
                <strong id="delAssetName"></strong>?<br>
                This action cannot be undone.</p>
        </div>
        <div class="modal-foot">
            <button class="btn btn-outline" onclick="closeDelModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button class="btn btn-red" id="btnConfirmDel" onclick="confirmDelete()">
                <i class="fas fa-trash"></i> Yes, Delete
            </button>
        </div>
    </div>
</div>

<!-- ── TOAST ─────────────────────────────────────────────────────────── -->
<div class="toast" id="toast">
    <i class="fas fa-check-circle toast-icon"></i>
    <span id="toastMsg">Saved successfully</span>
</div>

<!-- ════════════════════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════════════════ -->
<script>
// ── State ─────────────────────────────────────────────────────────────
const THIS_FILE    = '<?= $this_file ?>';
let   facilityData = {};
let   currentDepRate  = 0;
let   deleteTargetId  = 0;

// Pre-fill edit data if editing
<?php if ($edit_row): ?>
window.addEventListener('DOMContentLoaded', () => {
    // Pre-fill facility card
    document.getElementById('facilitySearch').value = <?= json_encode($edit_row['facility_name']) ?>;
    document.getElementById('h_facility_id').value  = <?= json_encode($edit_row['facility_id'])   ?>;
    document.getElementById('h_facility_name').value = <?= json_encode($edit_row['facility_name']) ?>;
    document.getElementById('h_mflcode').value       = <?= json_encode($edit_row['mflcode'] ?? '') ?>;
    document.getElementById('h_county').value        = <?= json_encode($edit_row['county_name'] ?? '') ?>;
    document.getElementById('h_subcounty').value     = <?= json_encode($edit_row['subcounty_name'] ?? '') ?>;

    document.getElementById('fc_name').textContent     = <?= json_encode($edit_row['facility_name']) ?>;
    document.getElementById('fc_mfl').textContent      = <?= json_encode($edit_row['mflcode'] ?? '—') ?>;
    document.getElementById('fc_county').textContent   = <?= json_encode($edit_row['county_name'] ?? '—') ?>;
    document.getElementById('fc_subcounty').textContent = <?= json_encode($edit_row['subcounty_name'] ?? '—') ?>;
    document.getElementById('facilityCard').style.display = 'block';

    // Depreciation badge
    const sel = document.getElementById('dig_id');
    if (sel.value) {
        const opt = sel.options[sel.selectedIndex];
        currentDepRate = parseFloat(opt.dataset.dep) || 0;
        document.getElementById('depPct').textContent = currentDepRate;
        document.getElementById('depBadge').style.display = 'inline-flex';
    }
    calcCurrentValue();
});
<?php endif; ?>

// ── Toast ──────────────────────────────────────────────────────────────
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'toast ' + type + ' show';
    const icon = t.querySelector('.toast-icon');
    icon.className = 'fas ' + (type==='success' ? 'fa-check-circle' : 'fa-exclamation-triangle') + ' toast-icon';
    setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Global alert ────────────────────────────────────────────────────────
function showAlert(msg, type='info') {
    const el = document.getElementById('globalAlert');
    el.innerHTML = `<div class="alert alert-${type}">
        <i class="fas fa-${type==='success'?'check-circle':type==='error'?'times-circle':'info-circle'}"></i>
        <span>${msg}</span></div>`;
    setTimeout(() => el.innerHTML = '', 5000);
}

// ── Tab switching ───────────────────────────────────────────────────────
function showTab(name) {
    ['form','list','csv','excel'].forEach(t => {
        document.getElementById('pane_'+t).style.display = t===name ? 'block' : 'none';
        document.getElementById('tab_'+t).classList.toggle('active', t===name);
    });
}

// ── Facility search ─────────────────────────────────────────────────────
function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

const facInput   = document.getElementById('facilitySearch');
const facResults = document.getElementById('facResults');
const facSpinner = document.getElementById('facSpinner');
const facIcon    = document.getElementById('facIcon');

if (facInput) {
    facInput.addEventListener('input', debounce(async function() {
        const q = facInput.value.trim();
        if (q.length < 2) { facResults.style.display='none'; return; }
        facSpinner.style.display='block'; facIcon.style.display='none';
        try {
            const rows = await fetch(`${THIS_FILE}?ajax=search_facility&q=${encodeURIComponent(q)}`).then(r=>r.json());
            facSpinner.style.display='none'; facIcon.style.display='block';
            if (!rows.length) {
                facResults.innerHTML = '<div class="no-results"><i class="fas fa-search"></i> No facilities found</div>';
            } else {
                facResults.innerHTML = rows.map(r =>
                    `<div class="result-item" onclick='pickFacility(${JSON.stringify(r).replace(/'/g,"&#39;")})'>
                        <div class="ri-name">${r.facility_name}
                            <span class="ri-badge">${r.mflcode||''}</span></div>
                        <div class="ri-meta">
                            <i class="fas fa-map-marker-alt" style="color:var(--primary)"></i>
                            ${r.county_name||''} | ${r.subcounty_name||''} | ${r.level_of_care_name||''}
                        </div>
                    </div>`
                ).join('');
            }
            facResults.style.display = 'block';
        } catch(e) { facSpinner.style.display='none'; facIcon.style.display='block'; }
    }, 350));

    document.addEventListener('click', e => {
        if (!document.getElementById('facSearchWrap').contains(e.target))
            facResults.style.display = 'none';
    });
}

function pickFacility(r) {
    facResults.style.display = 'none';
    facInput.value = r.facility_name;
    facilityData   = r;

    document.getElementById('h_facility_id').value   = r.facility_id;
    document.getElementById('h_facility_name').value = r.facility_name;
    document.getElementById('h_mflcode').value        = r.mflcode||'';
    document.getElementById('h_county').value         = r.county_name||'';
    document.getElementById('h_subcounty').value      = r.subcounty_name||'';
    document.getElementById('h_latitude').value        = r.latitude||'';
    document.getElementById('h_longitude').value       = r.longitude||'';

    document.getElementById('fc_name').textContent      = r.facility_name;
    document.getElementById('fc_mfl').textContent       = r.mflcode||'—';
    document.getElementById('fc_county').textContent    = r.county_name||'—';
    document.getElementById('fc_subcounty').textContent = r.subcounty_name||'—';
    document.getElementById('fc_level').textContent     = r.level_of_care_name||'—';
    document.getElementById('fc_owner').textContent     = r.owner||'—';
    document.getElementById('fc_sdp_fac').textContent   = r.sdp||'—';
    document.getElementById('fc_agency').textContent    = r.agency||'—';
    document.getElementById('fc_emr').textContent       = r.emr||'—';
    document.getElementById('fc_lat').textContent       = r.latitude||'—';
    document.getElementById('fc_lng').textContent       = r.longitude||'—';
    document.getElementById('facilityCard').style.display = 'block';
}

function clearFacility() {
    facilityData = {};
    facInput.value = '';
    document.getElementById('h_facility_id').value   = '';
    document.getElementById('h_facility_name').value = '';
    document.getElementById('h_mflcode').value        = '';
    document.getElementById('h_county').value         = '';
    document.getElementById('h_subcounty').value      = '';
    document.getElementById('h_latitude').value        = '';
    document.getElementById('h_longitude').value       = '';
    document.getElementById('facilityCard').style.display = 'none';
}

// ── Total cost calculation ─────────────────────────────────────────────
function calcTotalCost() {
    const pv  = parseFloat(document.getElementById('purchase_value').value) || 0;
    const qty = parseInt(document.getElementById('quantity').value)         || 1;
    const tc  = Math.round(pv * qty * 100) / 100;
    document.getElementById('totalCostAmount').textContent =
        'KES ' + tc.toLocaleString('en-KE', {minimumFractionDigits:2});
    document.getElementById('total_cost_hidden').value = tc;
}

// ── Asset register search ───────────────────────────────────────────────
function debounceReg(fn, ms) { let t; return (...a) => { clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

const regInput    = document.getElementById('registerSearch');
const regDropdown = document.getElementById('registerDropdown');

if (regInput) {
    regInput.addEventListener('input', debounceReg(async function() {
        const q = regInput.value.trim();
        if (q.length < 2) { regDropdown.style.display='none'; return; }
        try {
            const rows = await fetch(`${THIS_FILE}?ajax=search_register&q=${encodeURIComponent(q)}`).then(r=>r.json());
            if (!rows.length) {
                regDropdown.innerHTML = '<div style="padding:12px;color:#999;font-size:13px;text-align:center"><i class="fas fa-search"></i> No assets found in register</div>';
            } else {
                regDropdown.innerHTML = rows.map(r =>
                    `<div style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:.15s"
                          onmouseover="this.style.background='#faf2ff'" onmouseout="this.style.background=''"
                          onclick='pickRegisterAsset(${JSON.stringify(r).replace(/'/g,"&#39;")})'>
                        <div style="font-weight:700;color:var(--primary);font-size:13px">${r.asset_name}
                          <span style="font-size:10px;background:var(--pink);color:var(--primary);border-radius:4px;padding:1px 6px;margin-left:4px;font-weight:600">${r.asset_category||''}</span>
                        </div>
                        <div style="font-size:11px;color:#777;margin-top:2px">
                          Model: ${r.model||'—'} &nbsp;|&nbsp; Serial: ${r.serial_number||'—'} &nbsp;|&nbsp;
                          KES ${parseFloat(r.purchase_value).toLocaleString('en-KE',{minimumFractionDigits:2})}
                        </div>
                    </div>`
                ).join('');
            }
            regDropdown.style.display = 'block';
        } catch(e) { regDropdown.style.display='none'; }
    }, 350));

    document.addEventListener('click', e => {
        if (!regInput.closest('div').contains(e.target)) regDropdown.style.display = 'none';
    });
}

function pickRegisterAsset(r) {
    regDropdown.style.display = 'none';
    regInput.value = r.asset_name + (r.serial_number ? ' — ' + r.serial_number : '');
    document.getElementById('h_asset_id_reg').value = r.asset_id;

    // Fill asset type select
    const sel = document.getElementById('dig_id');
    for (let opt of sel.options) {
        if (opt.dataset.name === r.asset_name) { sel.value = opt.value; break; }
    }
    onAssetChange(sel.value);

    // Fill purchase value if empty
    if (!document.getElementById('purchase_value').value) {
        document.getElementById('purchase_value').value = r.purchase_value;
    }
    // Fill funder if empty
    if (!document.getElementById('dig_funder_id').value && r.dig_funder_name) {
        document.getElementById('dig_funder_id').value = r.dig_funder_name;
    }

    // Show register card
    document.getElementById('rc_name').textContent      = r.asset_name;
    document.getElementById('rc_model').textContent     = r.model || '—';
    document.getElementById('rc_serial').textContent    = r.serial_number || '—';
    document.getElementById('rc_cat').textContent       = r.asset_category || '—';
    document.getElementById('rc_funder').textContent    = r.dig_funder_name || '—';
    document.getElementById('rc_lpo').textContent       = r.lpo_number || '—';
    document.getElementById('rc_condition').textContent = r.current_condition || '—';
    document.getElementById('registerCard').style.display = 'block';

    calcTotalCost();
    calcCurrentValue();
}

function clearRegisterAsset() {
    regInput.value = '';
    document.getElementById('h_asset_id_reg').value = '';
    document.getElementById('registerCard').style.display = 'none';
}

// ── Asset change — show depreciation ───────────────────────────────────
function onAssetChange(dig_id) {
    const sel = document.getElementById('dig_id');
    const opt = sel.options[sel.selectedIndex];
    if (dig_id && opt) {
        currentDepRate = parseFloat(opt.dataset.dep) || 0;
        document.getElementById('depPct').textContent = currentDepRate;
        document.getElementById('depBadge').style.display = 'inline-flex';
    } else {
        currentDepRate = 0;
        document.getElementById('depBadge').style.display = 'none';
    }
    calcCurrentValue();
}

// ── Toggle end-date field ───────────────────────────────────────────────
function toggleEndDate(cb) {
    const edField = document.getElementById('end_date');
    edField.disabled = cb.checked;
    if (cb.checked) edField.value = '';
}

// ── Service level toggle SDP ────────────────────────────────────────────
function onServiceLevelChange(val) {
    document.getElementById('sdpGroup').style.display = val==='Service Delivery Point' ? 'block' : 'none';
    // Update selected class on labels
    document.querySelectorAll('.sl-opt').forEach(lbl => {
        lbl.classList.toggle('selected', lbl.querySelector('input').value === val);
    });
}

// ── Current value calculation ───────────────────────────────────────────
function calcCurrentValue() {
    const pv        = parseFloat(document.getElementById('purchase_value').value) || 0;
    const issueDateStr = document.getElementById('issue_date').value;
    if (!pv || !issueDateStr || !currentDepRate) {
        document.getElementById('cvAmount').textContent = 'KES ' + (pv ? pv.toFixed(2) : '0.00');
        document.getElementById('current_value_hidden').value = pv || 0;
        return;
    }
    const issueDate = new Date(issueDateStr);
    const now       = new Date();
    const monthsElapsed = Math.max(0,
        (now.getFullYear() - issueDate.getFullYear()) * 12 +
        (now.getMonth() - issueDate.getMonth())
    );
    // Reducing balance: CV = PV × (1 − dep/100)^(months/12)
    let cv = pv * Math.pow(1 - currentDepRate / 100, monthsElapsed / 12);
    if (cv < 0) cv = 0;
    cv = Math.round(cv * 100) / 100;

    document.getElementById('cvAmount').textContent = 'KES ' + cv.toLocaleString('en-KE', {minimumFractionDigits:2});
    document.getElementById('current_value_hidden').value = cv;
    calcTotalCost();
}

// ── Save record ─────────────────────────────────────────────────────────
async function saveRecord() {
    const fid   = document.getElementById('h_facility_id').value;
    const digId = document.getElementById('dig_id').value;
    const pv    = document.getElementById('purchase_value').value;
    const idate = document.getElementById('issue_date').value;
    const slevel = document.querySelector('input[name="service_level"]:checked')?.value || '';

    if (!fid)    { showToast('Please select a facility first.', 'error'); return; }
    if (!digId)  { showToast('Please select a digital asset.', 'error'); return; }
    if (!pv || parseFloat(pv) <= 0) { showToast('Please enter a valid purchase value.', 'error'); return; }
    if (!idate)  { showToast('Please enter the issue date.', 'error'); return; }
    if (!slevel) { showToast('Please select a service level.', 'error'); return; }

    const sel    = document.getElementById('dig_id');
    const opt    = sel.options[sel.selectedIndex];
    const assetName = opt ? opt.dataset.name : '';
    const depPct = currentDepRate;

    const noEnd   = document.getElementById('no_end_date').checked ? 1 : 0;
    const endDate = noEnd ? '' : document.getElementById('end_date').value;
    const investId = document.getElementById('h_invest_id').value || '';

    const fd = new FormData();
    fd.append('ajax_save',              '1');
    fd.append('invest_id',              investId);
    fd.append('facility_id',            fid);
    fd.append('facility_name',          document.getElementById('h_facility_name').value);
    fd.append('mflcode',                document.getElementById('h_mflcode').value);
    fd.append('county_name',            document.getElementById('h_county').value);
    fd.append('subcounty_name',         document.getElementById('h_subcounty').value);
    fd.append('dit_asset_name',         assetName);
    fd.append('asset_name',             assetName);
    fd.append('depreciation_percentage', depPct);
    fd.append('purchase_value',         pv);
    fd.append('issue_date',             idate);
    fd.append('no_end_date',            noEnd);
    fd.append('end_date',               endDate);
    fd.append('current_value',          document.getElementById('current_value_hidden').value);
    fd.append('dig_funder_name',        document.getElementById('dig_funder_id').value);
    fd.append('sdp_name',               document.getElementById('sdp_id').value || '');
    fd.append('emr_type_name',          document.getElementById('emr_type_id').value);
    fd.append('service_level',          slevel);
    fd.append('lot_number',             document.getElementById('lot_number').value);
    fd.append('tag_name',               document.getElementById('tag_name').value || '');
    fd.append('quantity',               document.getElementById('quantity').value || 1);
    fd.append('total_cost',             document.getElementById('total_cost_hidden').value || 0);
    fd.append('latitude',               document.getElementById('h_latitude').value || '');
    fd.append('longitude',              document.getElementById('h_longitude').value || '');
    fd.append('asset_id_reg',           document.getElementById('h_asset_id_reg').value || '');
    fd.append('name_of_user',           document.getElementById('name_of_user').value || '');
    fd.append('department_name',        document.getElementById('department_name').value || '');
    fd.append('date_of_verification',   document.getElementById('date_of_verification').value || '');
    fd.append('date_of_disposal',       document.getElementById('date_of_disposal_inv').value || '');

    try {
        const data = await fetch(THIS_FILE, {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            showToast(data.action==='insert' ? 'Investment saved successfully!' : 'Investment updated!', 'success');
            document.getElementById('h_invest_id').value = data.invest_id;
            // Update the CV display in case server recalculated
            document.getElementById('cvAmount').textContent =
                'KES ' + parseFloat(data.current_value).toLocaleString('en-KE', {minimumFractionDigits:2});
            setTimeout(() => { showTab('list'); window.location.reload(); }, 1400);
        } else {
            showToast(data.error || 'Save failed — please try again.', 'error');
        }
    } catch(err) {
        console.error(err);
        showToast('Network error — please check your connection.', 'error');
    }
}

// ── Reset form ──────────────────────────────────────────────────────────
function resetForm() {
    clearFacility();
    document.getElementById('dig_id').value          = '';
    document.getElementById('dig_funder_id').value   = '';
    document.getElementById('emr_type_id').value     = '';
    document.getElementById('sdp_id').value          = '';
    document.getElementById('purchase_value').value  = '';
    document.getElementById('issue_date').value      = '';
    document.getElementById('end_date').value        = '';
    document.getElementById('lot_number').value      = '';
    document.getElementById('tag_name').value         = '';
    document.getElementById('quantity').value         = 1;
    document.getElementById('name_of_user').value     = '';
    document.getElementById('department_name').value  = '';
    document.getElementById('date_of_verification').value = '';
    document.getElementById('date_of_disposal_inv').value = '';
    clearRegisterAsset();
    document.getElementById('totalCostAmount').textContent = 'KES 0.00';
    document.getElementById('total_cost_hidden').value = 0;
    document.getElementById('h_latitude').value  = '';
    document.getElementById('h_longitude').value = '';
    document.getElementById('no_end_date').checked   = false;
    document.getElementById('end_date').disabled     = false;
    document.getElementById('h_invest_id').value     = '';
    document.getElementById('depBadge').style.display = 'none';
    document.getElementById('sdpGroup').style.display = 'none';
    const radios = document.querySelectorAll('input[name="service_level"]');
    radios.forEach(r => r.checked = r.value === 'Facility-wide');
    document.querySelectorAll('.sl-opt').forEach(l =>
        l.classList.toggle('selected', l.querySelector('input').value === 'Facility-wide'));
    currentDepRate = 0;
    calcCurrentValue();
    showToast('Form cleared.', 'success');
    window.scrollTo({top:0, behavior:'smooth'});
}

// ── Delete record ───────────────────────────────────────────────────────
function deleteRecord(id, name) {
    deleteTargetId = id;
    document.getElementById('delAssetName').textContent = name;
    document.getElementById('delModal').classList.add('show');
}
function closeDelModal() {
    document.getElementById('delModal').classList.remove('show');
    deleteTargetId = 0;
}
async function confirmDelete() {
    if (!deleteTargetId) return;
    const fd = new FormData();
    fd.append('ajax_delete', '1');
    fd.append('invest_id',   deleteTargetId);
    try {
        const data = await fetch(THIS_FILE, {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            showToast('Record deleted.', 'success');
            closeDelModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast(data.error || 'Delete failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

// ── Table filter ────────────────────────────────────────────────────────
function filterTable() {
    const q      = document.getElementById('searchInput').value.toLowerCase();
    const status = document.getElementById('filterStatus').value.toLowerCase();
    const level  = document.getElementById('filterLevel').value.toLowerCase();
    const rows   = document.querySelectorAll('#investTbody tr');
    rows.forEach(row => {
        const txt    = row.textContent.toLowerCase();
        const rStat  = (row.dataset.status||'').toLowerCase();
        const rLevel = (row.dataset.level||'').toLowerCase();
        const matchQ = !q || txt.includes(q);
        const matchS = !status || rStat === status;
        const matchL = !level  || rLevel === level;
        row.style.display = (matchQ && matchS && matchL) ? '' : 'none';
    });
}

// ── Excel / xlsx import ────────────────────────────────────────────────
let xlsFile = null;

const xlsDrop = document.getElementById('xlsDrop');
if (xlsDrop) {
    xlsDrop.addEventListener('dragover', e => { e.preventDefault(); xlsDrop.classList.add('drag-over'); });
    xlsDrop.addEventListener('dragleave', () => xlsDrop.classList.remove('drag-over'));
    xlsDrop.addEventListener('drop', e => {
        e.preventDefault();
        xlsDrop.classList.remove('drag-over');
        const f = e.dataTransfer.files[0];
        if (f) {
            xlsFile = f;
            document.getElementById('xlsFileName').textContent = '📎 ' + f.name;
            document.getElementById('btnXlsImport').disabled = false;
        }
    });
}
function onXlsFileChange(input) {
    if (input.files.length) {
        xlsFile = input.files[0];
        document.getElementById('xlsFileName').textContent = '📎 ' + xlsFile.name;
        document.getElementById('btnXlsImport').disabled = false;
    }
}
async function importXls() {
    if (!xlsFile) { showToast('Please select a file.', 'error'); return; }
    const btn = document.getElementById('btnXlsImport');
    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing…';
    btn.disabled = true;
    const fd = new FormData();
    fd.append('ajax_excel_import', '1');
    fd.append('xls_file', xlsFile);
    try {
        const data = await fetch(THIS_FILE, {method:'POST', body:fd}).then(r=>r.json());
        const res = document.getElementById('xlsImportResult');
        if (data.success) {
            let html = `<div class="alert alert-success">
                <i class="fas fa-check-circle" style="font-size:1.3rem;flex-shrink:0"></i>
                <div><strong>Import Complete!</strong><br>
                ✅ ${data.imported} records imported &nbsp;|&nbsp;
                ⚠️ ${data.skipped} rows skipped</div></div>`;
            if (data.errors && data.errors.length) {
                html += `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i>
                    <div><strong>Notes:</strong><br>${data.errors.map(e=>'• '+e).join('<br>')}</div></div>`;
            }
            res.innerHTML = html;
            if (data.imported > 0) setTimeout(() => window.location.reload(), 2000);
        } else {
            res.innerHTML = `<div class="alert alert-error">
                <i class="fas fa-times-circle"></i> ${data.error}</div>`;
        }
    } catch(e) {
        document.getElementById('xlsImportResult').innerHTML =
            `<div class="alert alert-error"><i class="fas fa-times-circle"></i> Network error.</div>`;
    }
    btn.innerHTML = orig; btn.disabled = false;
}

// ── CSV import ──────────────────────────────────────────────────────────
let csvFile = null;

const csvDrop = document.getElementById('csvDrop');
if (csvDrop) {
    csvDrop.addEventListener('dragover', e => { e.preventDefault(); csvDrop.classList.add('drag-over'); });
    csvDrop.addEventListener('dragleave', () => csvDrop.classList.remove('drag-over'));
    csvDrop.addEventListener('drop', e => {
        e.preventDefault();
        csvDrop.classList.remove('drag-over');
        const file = e.dataTransfer.files[0];
        if (file && (file.type === 'text/csv' || file.name.endsWith('.csv'))) {
            csvFile = file;
            document.getElementById('csvFileName').textContent = '📎 ' + file.name;
            document.getElementById('btnImport').disabled = false;
        } else {
            showToast('Please upload a valid CSV file.', 'error');
        }
    });
}

function onCsvFileChange(input) {
    if (input.files.length) {
        csvFile = input.files[0];
        document.getElementById('csvFileName').textContent = '📎 ' + csvFile.name;
        document.getElementById('btnImport').disabled = false;
    }
}

async function importCsv() {
    if (!csvFile) { showToast('Please select a CSV file first.', 'error'); return; }
    const btn = document.getElementById('btnImport');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('ajax_csv_import', '1');
    fd.append('csv_file', csvFile);

    try {
        const data = await fetch(THIS_FILE, {method:'POST', body:fd}).then(r=>r.json());
        const res  = document.getElementById('importResult');
        if (data.success) {
            let html = `<div class="alert alert-success">
                <i class="fas fa-check-circle" style="font-size:1.3rem;flex-shrink:0"></i>
                <div><strong>Import Complete!</strong><br>
                ✅ ${data.imported} records imported &nbsp;|&nbsp;
                ⚠️ ${data.skipped} rows skipped</div></div>`;
            if (data.errors.length) {
                html += `<div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i>
                    <div><strong>Errors:</strong><br>${data.errors.map(e=>`• ${e}`).join('<br>')}</div></div>`;
            }
            res.innerHTML = html;
            if (data.imported > 0) setTimeout(() => window.location.reload(), 2000);
        } else {
            res.innerHTML = `<div class="alert alert-error">
                <i class="fas fa-times-circle"></i> ${data.error}</div>`;
        }
    } catch(e) {
        document.getElementById('importResult').innerHTML =
            `<div class="alert alert-error"><i class="fas fa-times-circle"></i> Network error — please try again.</div>`;
    }

    btn.innerHTML = origHtml;
    btn.disabled  = false;
}

// ── Download CSV template ─────────────────�