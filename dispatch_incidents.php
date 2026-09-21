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

// 1. Get and sanitize data from the Admin Dashboard request
$incident_id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : null; //[cite: 25]
$team_name   = isset($_POST['team_name']) ? trim($_POST['team_name']) : null;     //[cite: 25]
$remarks     = isset($_POST['admin_remarks']) ? trim($_POST['admin_remarks']) : ''; //[cite: 25]
$admin_id    = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 1; // Fallback to Superadmin ID

if (!$incident_id || empty($team_name)) {
    echo json_encode(["success" => false, "message" => "Missing incident ID or Team Name"]); //[cite: 25]
    exit();
}

$conn->begin_transaction();

try {
    // 2. Update the incident record: Set team, change status to 'dispatched', verify flag
    $sql = "UPDATE incidents 
            SET assigned_to = ?, 
                status = 'dispatched', 
                admin_remarks = ?,
                is_verified = 1
            WHERE id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $team_name, $remarks, $incident_id); //[cite: 25]
    $stmt->execute();
    $stmt->close();

    // 3. Update the response team's operational readiness in response_teams
    $sql_team = "UPDATE response_teams 
                 SET status = 'deployed', 
                     current_incident_id = ? 
                 WHERE team_name = ?";
    $stmt_team = $conn->prepare($sql_team);
    if ($stmt_team) {
        $stmt_team->bind_param("is", $incident_id, $team_name);
        $stmt_team->execute();
        $stmt_team->close();
    }

    // 4. Log the dispatch event to incident_logs for audit compliance
    $log_message = "DISPATCH ORDER: Dispatched [{$team_name}] to the incident.";
    if (!empty($remarks)) {
        $log_message .= " Remarks: " . $remarks;
    }

    $sql_log = "INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)";
    $stmt_log = $conn->prepare($sql_log);
    if ($stmt_log) {
        $stmt_log->bind_param("iis", $incident_id, $admin_id, $log_message);
        $stmt_log->execute();
        $stmt_log->close();
    }

    $conn->commit();

    echo json_encode([
        "success" => true, 
        "message" => "Incident #$incident_id successfully assigned to $team_name and unit status marked as deployed."
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Dispatch failed: " . $e->getMessage()]);
}

$conn->close();
?>