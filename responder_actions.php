<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once 'config.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $incident_id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if (!$action || !$user_id) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit();
    }

    $team_stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
    $team_stmt->bind_param("i", $user_id);
    $team_stmt->execute();
    $team_name = $team_stmt->get_result()->fetch_assoc()['department'] ?? '';
    $team_stmt->close();

    // 🚀 TAB 1: SUBMIT NEW INCIDENT / REQUEST BACKUP FROM FIELD
    if ($action === 'submit_new_report') {
        $type = $_POST['incident_type'];
        $sev = $_POST['severity'];
        $lat = (float)$_POST['lat'];
        $lng = (float)$_POST['lng'];
        $remarks = $_POST['remarks'];
        $backup = (int)$_POST['request_backup'];

        // 🚀 THE FIX: Insert new incident and INSTANTLY assign it to this Responder's Team as 'on-scene'
        $stmt = $conn->prepare("INSERT INTO incidents (reported_by, incident_type, severity, latitude, longitude, status, backup_requested, barangay, assigned_to) VALUES (?, ?, ?, ?, ?, 'on-scene', ?, 'Coordinates Logged', ?)");
        $stmt->bind_param("issddis", $user_id, $type, $sev, $lat, $lng, $backup, $team_name);
        
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;

            // 🚀 Link the Response Team to the new Incident immediately
            $stmt_team = $conn->prepare("UPDATE response_teams SET current_incident_id = ?, status = 'on-scene' WHERE team_name = ?");
            $stmt_team->bind_param("is", $new_id, $team_name);
            $stmt_team->execute();

            // Log the event
            $log = $backup === 1 ? "🚨 URGENT BACKUP REQUESTED by [$team_name]. Remarks: $remarks" : "Field Report by Unit [$team_name]: $remarks";
            $stmt2 = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
            $stmt2->bind_param("iis", $new_id, $user_id, $log);
            $stmt2->execute();

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit();
    }

    // 🚀 TAB 2: ACTION BUTTON UPDATES
    if ($action === 'submit_status_update') {
        $new_status = $_POST['status']; // 'On Scene', 'Resolved', 'Backup'
        $remarks = $_POST['remarks'];

        if ($new_status === 'On Scene') {
            $conn->query("UPDATE incidents SET status = 'on-scene' WHERE id = $incident_id");
            $stmt = $conn->prepare("UPDATE response_teams SET status = 'on-scene' WHERE current_incident_id = ? AND team_name = ?");
            $stmt->bind_param("is", $incident_id, $team_name);
            $stmt->execute();
            $log_msg = "Unit [$team_name] is ON SCENE. Remarks: $remarks";
        } elseif ($new_status === 'Resolved') {
            $conn->query("UPDATE incidents SET status = 'archived' WHERE id = $incident_id");
            $conn->query("UPDATE response_teams SET status = 'available', current_incident_id = NULL WHERE current_incident_id = $incident_id");
            $log_msg = "Unit [$team_name] RESOLVED the incident. Remarks: $remarks";
        } elseif ($new_status === 'Backup') {
            $conn->query("UPDATE incidents SET backup_requested = 1 WHERE id = $incident_id");
            $log_msg = "🚨 URGENT: Unit [$team_name] requested BACKUP. Remarks: $remarks";
        } else {
            $log_msg = "Unit [$team_name] Update: $remarks";
        }

        $stmt = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $incident_id, $user_id, $log_msg);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit();
    }

    // 🚀 NEW: Handle Request Backup from Responder Terminal
    if ($action === 'request_backup') {
        $backup_type = $conn->real_escape_string($_POST['backup_type'] ?? 'Unknown');
        $backup_count = $conn->real_escape_string($_POST['backup_count'] ?? '1 Unit');
        $situation = $conn->real_escape_string($_POST['situation'] ?? '');
        
        // 1. Flag the incident so the Admin Dashboard starts flashing
        $stmt1 = $conn->prepare("UPDATE incidents SET backup_requested = 1 WHERE id = ?");
        $stmt1->bind_param("i", $incident_id);
        $stmt1->execute();
        $stmt1->close();
        
        // 2. Compile their form into a readable log and save it
        $log_message = "🚨 URGENT BACKUP: Needs $backup_count of $backup_type. Situation: $situation";
        $stmt2 = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
        $stmt2->bind_param("iis", $incident_id, $user_id, $log_message);
        $stmt2->execute();
        $stmt2->close();
        
        echo json_encode(['success' => true]); 
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
?>