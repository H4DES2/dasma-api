<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

$query = "SELECT id, name, barangay, capacity, current_occupants, latitude, longitude, status FROM evacuation_centers";
$result = $conn->query($query);

if ($result) {
    $centers = [];
    while ($row = $result->fetch_assoc()) {
        $centers[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $centers]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$conn->close();
?>