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

echo json_encode([
    "status" => "ok",
    "service" => "Dasma Alert API",
    "db_connected" => isset($conn) && !$conn->connect_error,
    "timestamp" => date("Y-m-d H:i:s")
]);

$conn->close();
?>