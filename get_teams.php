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

// Fetch all response teams
$query = "SELECT id, team_name, team_type, assigned_barangay, status FROM response_teams ORDER BY team_name ASC";
$result = $conn->query($query);

$teams = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $teams[] = $row;
    }
    echo json_encode(["success" => true, "data" => $teams]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to retrieve teams: " . $conn->error]);
}

$conn->close();
?>