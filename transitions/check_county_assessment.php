<?php
session_start();
include('../includes/config.php');

header('Content-Type: application/json');

$county_id = isset($_GET['county_id']) ? (int)$_GET['county_id'] : 0;
$period = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';

if (!$county_id || !$period) {
    echo json_encode(['exists' => false, 'error' => 'Missing parameters']);
    exit();
}

$query = $conn->prepare("
    SELECT c.county_name, cca.assessment_id, cca.is_completed, cca.completed_by, cca.completed_at
    FROM county_integration_assessments cca
    JOIN counties c ON cca.county_id = c.county_id
    WHERE cca.county_id = ? AND cca.assessment_period = ?
");
$query->bind_param("is", $county_id, $period);
$query->execute();
$result = $query->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'exists' => true,
        'county_name' => $row['county_name'],
        'is_completed' => $row['is_completed'],
        'completed_by' => $row['completed_by'],
        'completed_at' => $row['completed_at']
    ]);
} else {
    echo json_encode(['exists' => false]);
}
?>