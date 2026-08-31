<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

// Get the data sent from your Superadmin Dashboard
$incident_id = $_POST['incident_id'] ?? null;
$team_name   = $_POST['team_name'] ?? null;

if (!$incident_id || !$team_name) {
    echo json_encode(["success" => false, "message" => "Missing Data"]);
    exit();
}

try {
    // 🚀 THE LOGIC: This updates the row so you don't have to type it manually
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
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>