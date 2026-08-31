<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

// Get the first name from the app to find their specific alerts
$fname = $_POST['fname'] ?? $_GET['fname'] ?? '';

if (empty($fname)) {
    echo json_encode(["status" => "error", "message" => "First name is required."]);
    exit();
}

$sql = "SELECT incidents.type, incidents.status, incidents.created_at 
        FROM incidents 
        JOIN users ON incidents.user_id = users.id 
        WHERE users.first_name = ? 
        ORDER BY incidents.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $fname);
$stmt->execute();
$result = $stmt->get_result();

$alerts = [];
while ($row = $result->fetch_assoc()) {
    $alerts[] = $row;
}

echo json_encode(["status" => "success", "data" => $alerts]);

$stmt->close();
$conn->close();
?>