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

// Get the data sent from your Superadmin Dashboard
$incident_id = $_POST['incident_id'] ?? null;
$team_name   = $_POST['team_name'] ?? null;

if (!$incident_id || !$team_name) {
    echo json_encode(["success" => false, "message" => "Missing Data"]);
    exit();
}

try {
    $sql = "UPDATE incidents 
            SET assigned_to = ?, 
                status = 'dispatched' 
            WHERE id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $team_name, $incident_id);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Successfully assigned to $team_name"]);
    } else {
        echo json_encode(["success" => false, "message" => "Database update failed"]);
    }
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();
?>