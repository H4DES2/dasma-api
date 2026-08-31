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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';
    $incident_id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
    $user_id     = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

    if (!$action || !$user_id) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        exit();
    }

    $team_stmt = $conn->prepare("SELECT department FROM users WHERE id = ?");
    $team_stmt->bind_param("i", $user_id);
    $team_stmt->execute();
    $team_data = $team_stmt->get_result()->fetch_assoc();
    $team_name = $team_data['department'] ?? '';
    $team_stmt->close();

    // TAB 1: SUBMIT NEW INCIDENT / REQUEST BACKUP FROM FIELD
    if ($action === 'submit_new_report') {
        $type    = $_POST['incident_type'] ?? 'General Emergency';
        $sev     = $_POST['severity'] ?? 'Minor';
        $lat     = isset($_POST['lat']) ? (float)$_POST['lat'] : 0.0;
        $lng     = isset($_POST['lng']) ? (float)$_POST['lng'] : 0.0;
        $remarks = $_POST['remarks'] ?? '';
        $backup  = isset($_POST['request_backup']) ? (int)$_POST['request_backup'] : 0;

        $stmt = $conn->prepare("INSERT INTO incidents (reported_by, incident_type, severity, latitude, longitude, status, backup_requested, barangay, assigned_to) VALUES (?, ?, ?, ?, ?, 'on-scene', ?, 'Coordinates Logged', ?)");
        $stmt->bind_param("issddis", $user_id, $type, $sev, $lat, $lng, $backup, $team_name);
        
        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $stmt->close();

            $stmt_team = $conn->prepare("UPDATE response_teams SET current_incident_id = ?, status = 'on-scene' WHERE team_name = ?");
            $stmt_team->bind_param("is", $new_id, $team_name);
            $stmt_team->execute();
            $stmt_team->close();

            $log = $backup === 1 ? "🚨 URGENT BACKUP REQUESTED by [$team_name]. Remarks: $remarks" : "Field Report by Unit [$team_name]: $remarks";
            $stmt2 = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
            $stmt2->bind_param("iis", $new_id, $user_id, $log);
            $stmt2->execute();
            $stmt2->close();

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        $conn->close();
        exit();
    }

    // TAB 2: ACTION BUTTON UPDATES
    if ($action === 'submit_status_update') {
        $new_status = $_POST['status'] ?? '';
        $remarks    = $_POST['remarks'] ?? '';

        if ($new_status === 'On Scene') {
            $stmt_inc = $conn->prepare("UPDATE incidents SET status = 'on-scene' WHERE id = ?");
            $stmt_inc->bind_param("i", $incident_id);
            $stmt_inc->execute();
            $stmt_inc->close();

            $stmt_t = $conn->prepare("UPDATE response_teams SET status = 'on-scene' WHERE current_incident_id = ? AND team_name = ?");
            $stmt_t->bind_param("is", $incident_id, $team_name);
            $stmt_t->execute();
            $stmt_t->close();

            $log_msg = "Unit [$team_name] is ON SCENE. Remarks: $remarks";
        } elseif ($new_status === 'Resolved') {
            $stmt_inc = $conn->prepare("UPDATE incidents SET status = 'archived' WHERE id = ?");
            $stmt_inc->bind_param("i", $incident_id);
            $stmt_inc->execute();
            $stmt_inc->close();

            $stmt_t = $conn->prepare("UPDATE response_teams SET status = 'available', current_incident_id = NULL WHERE current_incident_id = ?");
            $stmt_t->bind_param("i", $incident_id);
            $stmt_t->execute();
            $stmt_t->close();

            $log_msg = "Unit [$team_name] RESOLVED the incident. Remarks: $remarks";
        } elseif ($new_status === 'Backup') {
            $stmt_inc = $conn->prepare("UPDATE incidents SET backup_requested = 1 WHERE id = ?");
            $stmt_inc->bind_param("i", $incident_id);
            $stmt_inc->execute();
            $stmt_inc->close();

            $log_msg = "🚨 URGENT: Unit [$team_name] requested BACKUP. Remarks: $remarks";
        } else {
            $log_msg = "Unit [$team_name] Update: $remarks";
        }

        $stmt_log = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
        $stmt_log->bind_param("iis", $incident_id, $user_id, $log_msg);
        
        if ($stmt_log->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        $stmt_log->close();
        $conn->close();
        exit();
    }

    // TAB 3: REQUEST BACKUP FORM
    if ($action === 'request_backup') {
        $backup_type  = $_POST['backup_type'] ?? 'Unknown';
        $backup_count = $_POST['backup_count'] ?? '1 Unit';
        $situation    = $_POST['situation'] ?? '';
        
        $stmt1 = $conn->prepare("UPDATE incidents SET backup_requested = 1 WHERE id = ?");
        $stmt1->bind_param("i", $incident_id);
        $stmt1->execute();
        $stmt1->close();
        
        $log_message = "🚨 URGENT BACKUP: Needs $backup_count of $backup_type. Situation: $situation";
        $stmt2 = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
        $stmt2->bind_param("iis", $incident_id, $user_id, $log_message);
        $stmt2->execute();
        $stmt2->close();
        
        echo json_encode(['success' => true]); 
        $conn->close();
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}

$conn->close();
?>