<?php
while (ob_get_level() > 0) { ob_end_clean(); }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($_POST['email'] ?? $input['email'] ?? '');
$code = trim($_POST['code'] ?? $input['code'] ?? '');

if (empty($email) || empty($code)) {
    echo json_encode(["success" => false, "message" => "Email and code are required."]);
    exit();
}

$stmt = $conn->prepare("SELECT id FROM password_resets WHERE email = ? AND code = ? AND expires_at > NOW() LIMIT 1");
$stmt->bind_param("ss", $email, $code);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if ($result->num_rows > 0) {
    echo json_encode(["success" => true, "message" => "Code verified successfully."]);
} else {
    echo json_encode(["success" => false, "message" => "Invalid or expired verification code."]);
}

$conn->close();
?>