<?php
session_start();
header('Content-Type: application/json');
include '../includes/config.php';

$staff_id = $_GET['staff_id'] ?? $_SESSION['temp_staff_id'] ?? '';

if($staff_id) {
    $result = mysqli_query($conn, "SELECT completion_percentage FROM staff_profile WHERE staff_id='$staff_id'");
    if($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'percentage' => $row['completion_percentage']]);
    } else {
        echo json_encode(['success' => true, 'percentage' => 0]);
    }
} else {
    echo json_encode(['success' => false, 'percentage' => 0]);
}
?>