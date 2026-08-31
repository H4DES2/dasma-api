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

$barangays = [];
$sql = "SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $barangays[] = $row;
    }
    echo json_encode(["success" => true, "data" => $barangays]);
} else {
    echo json_encode(["success" => false, "message" => "Query failed: " . $conn->error]);
}

$conn->close();
?>