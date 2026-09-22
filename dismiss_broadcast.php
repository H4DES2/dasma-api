<?php
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$user_id      = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$broadcast_id = isset($_POST['broadcast_id']) ? (int)$_POST['broadcast_id'] : 0;

if ($user_id > 0 && $broadcast_id > 0) {
    // Ensure column exists
    $chk = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'dismissed_broadcast_id'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE user_profiles ADD COLUMN dismissed_broadcast_id INT DEFAULT 0");
    }

    // Update profile
    $stmt = $conn->prepare("UPDATE user_profiles SET dismissed_broadcast_id = ? WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $broadcast_id, $user_id);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
}
$conn->close();
?>