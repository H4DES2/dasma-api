<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

$reported_by   = isset($_POST['reported_by']) ? (int)$_POST['reported_by'] : 0;
$incident_type = $_POST['incident_type'] ?? 'General';
$latitude      = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$longitude     = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$barangay      = $_POST['barangay'] ?? 'Unknown';

$sql = "INSERT INTO incidents (reported_by, incident_type, latitude, longitude, status, barangay) 
        VALUES (?, ?, ?, ?, 'active', ?)";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("isdds", $reported_by, $incident_type, $latitude, $longitude, $barangay);
    
    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success", 
            "success" => true, 
            "message" => "SOS Sent! Help is coming."
        ]);
    } else {
        echo json_encode([
            "status" => "error", 
            "success" => false, 
            "message" => "Database Error: " . $stmt->error
        ]);
    }
    $stmt->close();
} else {
    echo json_encode([
        "status" => "error", 
        "success" => false, 
        "message" => "Query prepare failed: " . $conn->error
    ]);
}

$conn->close();
?>