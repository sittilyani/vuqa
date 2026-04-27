<?php
session_start();
include '../includes/config.php';
include '../includes/session_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$training_id = (int)$_POST['training_id'];
$action = $_POST['action'] ?? '';

if (!$training_id) {
    echo json_encode(['success' => false, 'message' => 'Training ID required']);
    exit();
}

// Get training allowance settings
$training_query = mysqli_query($conn, "SELECT allowance_settings FROM planned_trainings WHERE training_id = $training_id");
$training_data = mysqli_fetch_assoc($training_query);
$allowances = json_decode($training_data['allowance_settings'] ?? '{}', true);

// Define amounts from POST or training settings
$fare_amount = isset($_POST['fare_amount']) ? (float)$_POST['fare_amount'] : ($allowances['fare'] ?? 0);
$airtime_amount = isset($_POST['airtime_amount']) ? (float)$_POST['airtime_amount'] : ($allowances['airtime'] ?? 0);
$perdiem_amount = isset($_POST['perdiem_amount']) ? (float)$_POST['perdiem_amount'] : ($allowances['perdiem'] ?? 0);
$lunch_amount = isset($_POST['lunch_amount']) ? (float)$_POST['lunch_amount'] : ($allowances['lunch'] ?? 0);
$dinner_amount = isset($_POST['dinner_amount']) ? (float)$_POST['dinner_amount'] : ($allowances['dinner'] ?? 0);
$others_amount = (float)($_POST['others_amount'] ?? 0);

$total_amount = $fare_amount + $airtime_amount + $perdiem_amount + $lunch_amount + $dinner_amount + $others_amount;

// Get all registrations for this training
$registrations_query = mysqli_query($conn, "SELECT registration_id FROM training_registrations WHERE training_id = $training_id");

if ($action === 'apply_to_all') {
    // Apply same amounts to all participants
    while ($reg = mysqli_fetch_assoc($registrations_query)) {
        $check_query = mysqli_query($conn, "SELECT payment_id FROM participant_payments WHERE registration_id = {$reg['registration_id']}");

        if (mysqli_num_rows($check_query) > 0) {
            // Update existing
            $update = mysqli_query($conn, "UPDATE participant_payments SET
                fare_amount = $fare_amount,
                airtime_amount = $airtime_amount,
                perdiem_amount = $perdiem_amount,
                lunch_amount = $lunch_amount,
                dinner_amount = $dinner_amount,
                others_amount = $others_amount,
                total_amount = $total_amount,
                updated_at = NOW()
                WHERE registration_id = {$reg['registration_id']}");
        } else {
            // Insert new
            $insert = mysqli_query($conn, "INSERT INTO participant_payments
                (registration_id, fare_amount, airtime_amount, perdiem_amount, lunch_amount, dinner_amount, others_amount, total_amount)
                VALUES
                ({$reg['registration_id']}, $fare_amount, $airtime_amount, $perdiem_amount, $lunch_amount, $dinner_amount, $others_amount, $total_amount)");
        }
    }

    echo json_encode(['success' => true, 'message' => 'Payments applied to all participants successfully!']);

} elseif ($action === 'process_selected' && isset($_POST['selected_ids'])) {
    // Apply to selected participants only
    $selected_ids = $_POST['selected_ids'];
    $success_count = 0;

    foreach ($selected_ids as $reg_id) {
        $reg_id = (int)$reg_id;
        $check_query = mysqli_query($conn, "SELECT payment_id FROM participant_payments WHERE registration_id = $reg_id");

        if (mysqli_num_rows($check_query) > 0) {
            $update = mysqli_query($conn, "UPDATE participant_payments SET
                fare_amount = $fare_amount,
                airtime_amount = $airtime_amount,
                perdiem_amount = $perdiem_amount,
                lunch_amount = $lunch_amount,
                dinner_amount = $dinner_amount,
                others_amount = $others_amount,
                total_amount = $total_amount,
                updated_at = NOW()
                WHERE registration_id = $reg_id");
            if ($update) $success_count++;
        } else {
            $insert = mysqli_query($conn, "INSERT INTO participant_payments
                (registration_id, fare_amount, airtime_amount, perdiem_amount, lunch_amount, dinner_amount, others_amount, total_amount)
                VALUES
                ($reg_id, $fare_amount, $airtime_amount, $perdiem_amount, $lunch_amount, $dinner_amount, $others_amount, $total_amount)");
            if ($insert) $success_count++;
        }
    }

    echo json_encode(['success' => true, 'message' => "Payments processed for $success_count participants"]);

} elseif ($action === 'complete_payment') {
    // Mark payments as completed/processed
    $payment_ids = $_POST['payment_ids'] ?? [];
    $processed_by = $_SESSION['full_name'];

    foreach ($payment_ids as $payment_id) {
        $payment_id = (int)$payment_id;
        mysqli_query($conn, "UPDATE participant_payments SET
            payment_status = 'completed',
            processed_by = '$processed_by',
            processed_at = NOW()
            WHERE payment_id = $payment_id");
    }

    echo json_encode(['success' => true, 'message' => 'Payments marked as completed']);

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>