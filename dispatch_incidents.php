<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once 'config.php';

// 1. Get data from the Admin Dashboard request
$incident_id = $_POST['incident_id'] ?? null;
$team_name   = $_POST['team_name'] ?? null; // e.g., "Fire Squad 1"
$remarks     = $_POST['admin_remarks'] ?? '';

if (!$incident_id || !$team_name) {
    echo json_encode(["success" => false, "message" => "Missing incident ID or Team Name"]);
    exit();
}

try {
    // 2. Update the incident: Set the team and change status to 'dispatched'
    $sql = "UPDATE incidents 
            SET assigned_to = ?, 
                status = 'dispatched', 
                admin_remarks = ? 
            WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $team_name, $remarks, $incident_id);

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true, 
            "message" => "Incident #$incident_id successfully assigned to $team_name"
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "Database update failed"]);
    }

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>