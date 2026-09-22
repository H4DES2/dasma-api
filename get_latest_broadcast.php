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

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0);

// Check if user has already dismissed an alert
$user_dismissed_id = 0;
if ($user_id > 0) {
    // Ensure column exists to prevent fatal errors
    $chk = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'dismissed_broadcast_id'");
    if ($chk && $chk->num_rows > 0) {
        $stmt_u = $conn->prepare("SELECT dismissed_broadcast_id FROM user_profiles WHERE user_id = ?");
        if ($stmt_u) {
            $stmt_u->bind_param("i", $user_id);
            $stmt_u->execute();
            $res_u = $stmt_u->get_result()->fetch_assoc();
            $user_dismissed_id = (int)($res_u['dismissed_broadcast_id'] ?? 0);
            $stmt_u->close();
        }
    }
}

// Fetch the single most recent ACTIVE broadcast
$sql = "SELECT id, title, message, severity, created_at FROM broadcasts WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $alert = $result->fetch_assoc();
    
    // If the active broadcast matches the dismissed ID recorded for this user, do not return it
    if ($user_dismissed_id > 0 && (int)$alert['id'] === $user_dismissed_id) {
        echo json_encode(["success" => false, "message" => "Broadcast already dismissed by user"]);
    } else {
        echo json_encode(["success" => true, "alert" => $alert]);
    }
} else {
    echo json_encode(["success" => false, "message" => "No active broadcasts found"]);
}

$conn->close();
?>