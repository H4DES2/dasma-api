<?php
ini_set('display_errors', 0);
while (ob_get_level() > 0) { ob_end_clean(); }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function respond(bool $success, string $message): void
{
    echo json_encode(["success" => $success, "message" => $message]);
    exit();
}

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/reset_helpers.php';
    ensure_reset_table($conn);

    $input        = json_decode(file_get_contents('php://input'), true);
    $email        = trim($_POST['email'] ?? $input['email'] ?? '');
    $code         = trim($_POST['code'] ?? $input['code'] ?? '');
    $new_password = $_POST['new_password'] ?? $input['new_password'] ?? '';

    if ($email === '' || $code === '' || $new_password === '') {
        respond(false, "Email, verification code and new password are required.");
    }
    if (strlen($new_password) < 8) {
        respond(false, "Password must be at least 8 characters long.");
    }

    // The code must be sent again here and still be valid.
    // (Before, anyone who triggered a code email for an address could reset that
    // account's password without ever knowing the code.)
    [$ok, $message] = check_reset_code($conn, $email, $code);
    if (!$ok) {
        respond(false, $message);
    }

    $hashed_pwd = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->bind_param("ss", $hashed_pwd, $email);
    $updated = $stmt->execute();
    $stmt->close();

    if (!$updated) {
        respond(false, "Failed to update password.");
    }

    // Burn the code so it can't be used again
    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    respond(true, "Password updated successfully.");
} catch (Throwable $e) {
    error_log('reset_password: ' . $e->getMessage());
    respond(false, "Server error. Please try again.");
}