<?php
/**
 * fetch_staff_by_id.php
 * AJAX endpoint for TNA staff lookup.
 * Place in: trainings/  (same folder as the questionnaire)
 */

// Must be absolutely first
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);
ob_start();

// Project uses session_start() before config.php
session_start();

// Include DB connection ($conn is mysqli procedural)
include '../includes/config.php';

// Discard anything config may have printed, then set JSON header
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// Session guard - return JSON not redirect
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

// Validate input
$id_number = isset($_POST['id_number']) ? trim($_POST['id_number']) : '';
if ($id_number === '') {
    echo json_encode(['success' => false, 'message' => 'ID number is required.']);
    exit;
}

// Check DB connection
if (!isset($conn) || !$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Check config.php']);
    exit;
}

// Helper: safe query - returns empty array on any failure
function tna_query($conn, $sql, $id_number) {
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 's', $id_number);
    if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return []; }
    $result = mysqli_stmt_get_result($stmt);
    if (!$result) { mysqli_stmt_close($stmt); return []; }
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

// 1. Core staff record (required)
$stmt = @mysqli_prepare($conn,
    "SELECT staff_id, id_number,
            first_name, last_name, other_name,
            sex, staff_phone, email,
            cadre_name, department_name,
            facility_name, county_name, subcounty_name,
            employment_status, staff_status,
            date_of_birth, date_of_joining
     FROM county_staff WHERE id_number = ? LIMIT 1"
);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Query failed: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $id_number);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    mysqli_stmt_close($stmt);
    echo json_encode(['success' => false,
        'message' => 'No staff found with ID: ' . htmlspecialchars($id_number)]);
    exit;
}

$staff = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

$staff['full_name'] = trim($staff['first_name'] . ' ' . $staff['last_name'] .
    (!empty($staff['other_name']) ? ' ' . $staff['other_name'] : ''));
$staff['date_of_birth']   = !empty($staff['date_of_birth'])
    ? date('d M Y', strtotime($staff['date_of_birth']))   : '';
$staff['date_of_joining'] = !empty($staff['date_of_joining'])
    ? date('d M Y', strtotime($staff['date_of_joining'])) : '';

// 2. Academics
$academics = tna_query($conn,
    "SELECT academic_id, qualification_type, qualification_name,
            institution_name, course_name, specialization,
            grade, award_year, start_date, end_date,
            completion_status, verification_status
     FROM employee_academics WHERE id_number = ?
     ORDER BY award_year DESC, end_date DESC", $id_number);
foreach ($academics as &$r) {
    $r['start_date'] = !empty($r['start_date']) ? date('d M Y', strtotime($r['start_date'])) : '';
    $r['end_date']   = !empty($r['end_date'])   ? date('d M Y', strtotime($r['end_date']))   : '';
} unset($r);

// 3. Professional registrations
$registrations = tna_query($conn,
    "SELECT registration_id, regulatory_body, registration_number,
            registration_date, expiry_date, license_number,
            license_grade, specialization, verification_status
     FROM employee_professional_registrations WHERE id_number = ?
     ORDER BY expiry_date DESC", $id_number);
foreach ($registrations as &$r) {
    $r['is_expired']        = (!empty($r['expiry_date']) && strtotime($r['expiry_date']) < time());
    $r['registration_date'] = !empty($r['registration_date']) ? date('d M Y', strtotime($r['registration_date'])) : '';
    $r['expiry_date']       = !empty($r['expiry_date'])       ? date('d M Y', strtotime($r['expiry_date']))       : '';
} unset($r);

// 4. Work experience
$experience = tna_query($conn,
    "SELECT experience_id, employer_name, employer_type,
            job_title, job_grade, department,
            start_date, end_date, is_current, verification_status
     FROM employee_work_experience WHERE id_number = ?
     ORDER BY CASE WHEN is_current = 'Yes' THEN 0 ELSE 1 END, end_date DESC", $id_number);
foreach ($experience as &$r) {
    $r['start_date'] = !empty($r['start_date']) ? date('M Y', strtotime($r['start_date'])) : '';
    $r['end_date']   = ($r['is_current'] === 'Yes') ? 'Present'
        : (!empty($r['end_date']) ? date('M Y', strtotime($r['end_date'])) : '');
} unset($r);

// 5. Trainings
$trainings = tna_query($conn,
    "SELECT training_id, training_name, training_provider, training_type,
            start_date, end_date, certificate_number,
            certificate_expiry_date, skills_acquired, funding_source
     FROM employee_trainings WHERE id_number = ?
     ORDER BY end_date DESC", $id_number);
foreach ($trainings as &$r) {
    $r['start_date']              = !empty($r['start_date'])              ? date('d M Y', strtotime($r['start_date']))              : '';
    $r['end_date']                = !empty($r['end_date'])                ? date('d M Y', strtotime($r['end_date']))                : '';
    $r['certificate_expiry_date'] = !empty($r['certificate_expiry_date']) ? date('d M Y', strtotime($r['certificate_expiry_date'])) : '';
} unset($r);

// 6. Statutory
$stat = tna_query($conn,
    "SELECT kra_pin, nhif_number, nssf_number, disability
     FROM employee_statutory WHERE id_number = ? LIMIT 1", $id_number);
$statutory = !empty($stat) ? $stat[0] : null;

// Output JSON
echo json_encode([
    'success'       => true,
    'staff'         => $staff,
    'academics'     => $academics,
    'registrations' => $registrations,
    'experience'    => $experience,
    'trainings'     => $trainings,
    'statutory'     => $statutory,
], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);