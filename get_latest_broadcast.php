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

// Fetch the single most recent ACTIVE broadcast
$sql = "SELECT id, title, message, severity, created_at FROM broadcasts WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $alert = $result->fetch_assoc();
    echo json_encode(["success" => true, "alert" => $alert]);
} else {
    echo json_encode(["success" => false, "message" => "No active broadcasts found"]);
}

$conn->close();
?>