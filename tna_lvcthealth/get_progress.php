<?php
session_start();
header('Content-Type: application/json');

// FIX: path was 'includes/config.php' — must match index.php which uses '../includes/config.php'
include '../includes/config.php';

$staff_id = $_GET['staff_id'] ?? ($_SESSION['temp_staff_id'] ?? '');

if ($staff_id) {
    $safe_id = mysqli_real_escape_string($conn, $staff_id);
    $result  = mysqli_query($conn, "SELECT completion_percentage FROM staff_profile WHERE staff_id='$safe_id'");

    if ($result && $row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'percentage' => (int)$row['completion_percentage']]);
    } else {
        // Staff not yet saved — return 0% (not an error)
        echo json_encode(['success' => true, 'percentage' => 0]);
    }
} else {
    echo json_encode(['success' => false, 'percentage' => 0, 'message' => 'No staff ID in session.']);
}
?>