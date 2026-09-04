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

$teams = [];
$checkTable = $conn->query("SHOW TABLES LIKE 'response_teams'");

if ($checkTable && $checkTable->num_rows > 0) {
    $query = "SELECT id, team_name, team_type, assigned_barangay, status FROM response_teams ORDER BY team_name ASC";
    $result = $conn->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
    }
}

// Fallback seed if table has no entries
if (empty($teams)) {
    $fallbackTeams = [
        ["team_name" => "Central Fire Truck 1", "team_type" => "Fire"],
        ["team_name" => "Central Fire Truck 2", "team_type" => "Fire"],
        ["team_name" => "Alpha Rescue Unit", "team_type" => "Rescue"],
        ["team_name" => "Bravo Rescue Unit", "team_type" => "Rescue"],
        ["team_name" => "Medic Ambulance 1", "team_type" => "Medic"],
        ["team_name" => "Medic Ambulance 2", "team_type" => "Medic"],
        ["team_name" => "Police Mobile Patrol 1", "team_type" => "Police"],
        ["team_name" => "Police Mobile Patrol 2", "team_type" => "Police"],
    ];

    foreach ($fallbackTeams as $t) {
        $stmt = $conn->prepare("INSERT INTO response_teams (team_name, team_type, status) VALUES (?, ?, 'available')");
        if ($stmt) {
            $stmt->bind_param("ss", $t['team_name'], $t['team_type']);
            $stmt->execute();
            $stmt->close();
        }
    }

    $res = $conn->query("SELECT id, team_name, team_type, assigned_barangay, status FROM response_teams ORDER BY team_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $teams[] = $row;
        }
    }
}

// Return raw array directly to satisfy List<dynamic> decode in Flutter
echo json_encode($teams);

$conn->close();
exit();
?>