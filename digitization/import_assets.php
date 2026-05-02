<?php
// digitization/import_assets.php
session_start();
include '../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, "r");

    // Skip header row
    fgetcsv($handle);

    $imported = 0;
    $errors = [];
    $row_count = 1;

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $row_count++;
        // Mapping: 0:facility_id, 1:asset_id, 2:tag_name, 3:issue_date, 4:user, 5:dept_id
        $fac_id  = (int)$data[0];
        $ast_id  = (int)$data[1];
        $tag     = mysqli_real_escape_string($conn, $data[2]);
        $date    = mysqli_real_escape_string($conn, $data[3]);
        $user    = mysqli_real_escape_string($conn, $data[4]);
        $dept_id = (int)$data[5];

        // 1. Fetch Facility Details
        $f_res = mysqli_query($conn, "SELECT facility_name, mflcode, county_name FROM facilities WHERE facility_id = $fac_id");
        $f_data = mysqli_fetch_assoc($f_res);

        // 2. Fetch Asset Details
        $a_res = mysqli_query($conn, "SELECT description, purchase_value, depreciation_percentage FROM asset_master_register WHERE asset_id = $ast_id");
        $a_data = mysqli_fetch_assoc($a_res);

        if (!$f_data || !$a_data) {
            $errors[] = "Row $row_count: Invalid Facility or Asset ID.";
            continue;
        }

        // 3. Insert into assets_issuance_register (Ensuring no dig_id is referenced)
        $sql = "INSERT INTO assets_issuance_register
                (asset_id, facility_id, facility_name, mflcode, county_name, asset_name, tag_name,
                 purchase_value, depreciation_percentage, issue_date, name_of_user, department_id,
                 invest_status, created_at)
                VALUES
                ($ast_id, $fac_id, '{$f_data['facility_name']}', '{$f_data['mflcode']}', '{$f_data['county_name']}',
                 '{$a_data['description']}', '$tag', '{$a_data['purchase_value']}',
                 '{$a_data['depreciation_percentage']}', '$date', '$user', $dept_id, 'Active', NOW())";

        if (mysqli_query($conn, $sql)) {
            $imported++;
        } else {
            $errors[] = "Row $row_count: " . mysqli_error($conn);
        }
    }

    fclose($handle);
    echo json_encode(['success' => true, 'imported' => $imported, 'errors' => $errors]);
    exit;
}