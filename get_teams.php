<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Standalone database connection to bypass folder path errors
$conn = new mysqli('localhost', 'root', '', 'alert');

if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

$conn->set_charset("utf8");

// Fetch all response teams
$query = "SELECT id, team_name, team_type, assigned_barangay, status FROM response_teams ORDER BY team_name ASC";
$result = $conn->query($query);

$teams = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $teams[] = $row;
    }
}

echo json_encode($teams);
?>