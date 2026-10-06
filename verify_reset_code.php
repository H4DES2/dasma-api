<?php
ini_set('display_errors', 0);
while (ob_get_level() > 0) { ob_end_clean(); }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/reset_helpers.php';
    ensure_reset_table($conn);

    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($_POST['email'] ?? $input['email'] ?? '');
    $code  = trim($_POST['code'] ?? $input['code'] ?? '');

    if ($email === '' || $code === '') {
        echo json_encode(["success" => false, "message" => "Email and code are required."]);
        exit();
    }

    [$ok, $message] = check_reset_code($conn, $email, $code);

    echo json_encode([
        "success" => $ok,
        "message" => $ok ? "Code verified successfully." : $message,
    ]);

    $conn->close();
} catch (Throwable $e) {
    error_log('verify_reset_code: ' . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Server error. Please try again."]);
}