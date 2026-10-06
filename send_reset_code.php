<?php
while (ob_get_level() > 0) { ob_end_clean(); }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/config.php'; // Adjust path if needed

// Auto-create the password_resets table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_code (email, code)
)");

$input = json_decode(file_get_contents('php://input'), true);
$email = trim($_POST['email'] ?? $input['email'] ?? '');

if (empty($email)) {
    echo json_encode(["success" => false, "message" => "Email address is required."]);
    exit();
}

// Check if user exists (assuming your users table has an email column)
$stmt = $conn->prepare("SELECT id, first_name FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Return success anyway to prevent email enumeration attacks
    echo json_encode(["success" => true, "message" => "If an account exists, a code was sent."]);
    exit();
}

// Generate 6-digit code
$code = sprintf("%06d", mt_rand(100000, 999999));
$expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

// Invalidate old codes for this email
$stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->close();

// Insert new code
$stmt = $conn->prepare("INSERT INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $email, $code, $expires_at);
$stmt->execute();
$stmt->close();

// Send Email (Replace with PHPMailer if your server blocks standard mail())
$subject = "DasmaAlert - Password Reset Code";
$message = "Hello {$user['first_name']},\n\nYour password reset code is: {$code}\n\nThis code will expire in 15 minutes.\n\nIf you did not request this, please ignore this email.";
$headers = "From: noreply@dasmaalert.com";

@mail($email, $subject, $message, $headers);

echo json_encode(["success" => true, "message" => "Verification code sent to your email."]);
$conn->close();
?>