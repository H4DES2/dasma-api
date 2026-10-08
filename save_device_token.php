<?php
while (ob_get_level() > 0) { ob_end_clean(); }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config.php';

$input    = json_decode(file_get_contents('php://input'), true);
$user_id  = (int)($_POST['user_id'] ?? $input['user_id'] ?? 0);
$token    = trim($_POST['token'] ?? $input['token'] ?? '');
$platform = trim($_POST['platform'] ?? $input['platform'] ?? 'android');

if (!in_array($platform, ['android', 'ios', 'web'], true)) {
    $platform = 'android';
}

if ($user_id <= 0 || $token === '' || strlen($token) > 500) {
    echo json_encode(["success" => false, "message" => "Invalid user_id or device token."]);
    exit();
}

$stmt = $conn->prepare("
    INSERT INTO user_device_tokens (user_id, device_token, platform)
    VALUES (?, ?, ?) AS new
    ON DUPLICATE KEY UPDATE user_id = new.user_id, platform = new.platform, updated_at = NOW()
");
$stmt->bind_param("iss", $user_id, $token, $platform);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Device token registered."]);
} else {
    error_log('save_device_token: ' . $conn->error);
    echo json_encode(["success" => false, "message" => "Failed to save token."]);
}

$stmt->close();
$conn->close();