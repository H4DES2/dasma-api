<?php
while (ob_get_level() > 0) { ob_end_clean(); }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($_POST['email'] ?? $input['email'] ?? '');
$new_password = $_POST['new_password'] ?? $input['new_password'] ?? '';

if (empty($email) || empty($new_password)) {
    echo json_encode(["success" => false, "message" => "Email and new password are required."]);
    exit();
}

// Verify that they actually requested a reset and the code is still valid/exists in the flow
// (You can optionally require the code to be passed here again for strict validation)
$stmt = $conn->prepare("SELECT id FROM password_resets WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$valid_request = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$valid_request) {
    echo json_encode(["success" => false, "message" => "No active password reset request found."]);
    exit();
}

// Hash new password and update user
$hashed_pwd = password_hash($new_password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->bind_param("ss", $hashed_pwd, $email);

if ($stmt->execute()) {
    // Delete the reset code so it can't be used again
    $stmt_del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt_del->bind_param("s", $email);
    $stmt_del->execute();
    $stmt_del->close();

    echo json_encode(["success" => true, "message" => "Password updated successfully."]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update password."]);
}

$stmt->close();
$conn->close();
?>