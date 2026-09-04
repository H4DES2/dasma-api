<?php
error_reporting(E_ALL); 
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(["success" => false, "jobs" => [], "message" => "Fatal PHP Error: " . $error['message']]);
        exit;
    }
});

require_once 'config.php';

$responder_id = $_POST['id'] ?? $_GET['id'] ?? null;

if (!$responder_id) {
    echo json_encode(["success" => false, "jobs" => [], "message" => "No Responder ID provided"]);
    exit();
}

try {
    $user_sql = "SELECT department FROM users WHERE id = ?";
    $stmt1 = $conn->prepare($user_sql);
    if (!$stmt1) throw new Exception("Database prepare failed: " . $conn->error);
    
    $stmt1->bind_param("i", $responder_id);
    $stmt1->execute();
    $user_data = $stmt1->get_result()->fetch_assoc();
    $stmt1->close();
    
    if (!$user_data) {
        throw new Exception("Responder account not found.");
    }

    $my_team = trim($user_data['department'] ?? '');

    if (empty($my_team)) {
        echo json_encode(["success" => true, "team_name" => "Unassigned", "jobs" => [], "message" => "Account is not assigned to a team."]);
        exit();
    }

    $sql = "SELECT 
                i.*, i.backup_requested, 
                u.first_name as reporter_fname, 
                u.last_name as reporter_lname,
                (SELECT phone_number FROM user_profiles WHERE user_id = u.id LIMIT 1) as reporter_phone,
                (SELECT log_message FROM incident_logs WHERE incident_id = i.id ORDER BY created_at ASC LIMIT 1) as latest_log
            FROM incidents i 
            LEFT JOIN users u ON i.reported_by = u.id 
            WHERE i.assigned_to LIKE CONCAT('%', ?, '%') 
            AND i.status IN ('active', 'dispatched', 'on-scene')
            ORDER BY i.created_at DESC";

    $stmt2 = $conn->prepare($sql);
    if (!$stmt2) throw new Exception("Database prepare failed: " . $conn->error);
    
    $stmt2->bind_param("s", $my_team);
    $stmt2->execute();
    $result = $stmt2->get_result();

    $jobs = [];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    while($row = $result->fetch_assoc()) {
        $row['image_url'] = !empty($row['image_path']) ? $scheme . '://' . $host . '/' . ltrim($row['image_path'], '/') : null;
        $jobs[] = $row;
    }

    $stmt2->close();

    $json_output = json_encode([
        "success" => true,
        "team_name" => $my_team,
        "jobs" => $jobs
    ], JSON_INVALID_UTF8_SUBSTITUTE);

    if ($json_output === false) {
         throw new Exception("JSON Encoding Failed: " . json_last_error_msg());
    }
    
    echo $json_output;

} catch (Throwable $e) {
    echo json_encode(["success" => false, "jobs" => [], "message" => "Server Error: " . $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>