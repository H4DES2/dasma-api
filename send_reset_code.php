<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

ini_set('display_errors', 0);
error_reporting(E_ALL);

while (ob_get_level() > 0) { 
    ob_end_clean(); 
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit(); 
}

// 1. Database Connection
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/php/config.php')) {
    require_once __DIR__ . '/php/config.php';
} else {
    echo json_encode(["success" => false, "message" => "Database config file missing."]);
    exit();
}

// 2. Ensure Table Exists
$conn->query("CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(10) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_code (email, code)
)");

// 3. Parse Request
$input = json_decode(file_get_contents('php://input'), true);
$email = trim($_POST['email'] ?? $input['email'] ?? '');

if (empty($email)) {
    echo json_encode(["success" => false, "message" => "Email address is required."]);
    exit();
}

// 4. Verify User Exists
$stmt = $conn->prepare("SELECT id, first_name FROM users WHERE email = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Database query failed: " . $conn->error]);
    exit();
}
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(["success" => false, "message" => "No account found with this email."]);
    exit();
}

// 5. Store Verification Code
$code = sprintf("%06d", mt_rand(100000, 999999));
$expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$stmt_del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
$stmt_del->bind_param("s", $email);
$stmt_del->execute();
$stmt_del->close();

$stmt_ins = $conn->prepare("INSERT INTO password_resets (email, code, expires_at) VALUES (?, ?, ?)");
$stmt_ins->bind_param("sss", $email, $code, $expires_at);
$stmt_ins->execute();
$stmt_ins->close();

// 6. Composer Autoload Check
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload_path)) {
    echo json_encode([
        "success" => false, 
        "message" => "vendor/autoload.php not found at: " . $autoload_path
    ]);
    exit();
}
require_once $autoload_path;

// 7. Dispatch Mail with PHPMailer
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    
    // Set your sender address & 16-character Google App Password (remove spaces)
    $mail->Username   = 'YOUR_GMAIL@gmail.com';
    $mail->Password   = 'YOUR_16_DIGIT_APP_PASSWORD';
    
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->Timeout    = 10; // Prevent indefinite hang

    // Bypass local OpenSSL verification failure on XAMPP/Windows
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom('YOUR_GMAIL@gmail.com', 'DasmaAlert CDRRMO');
    $mail->addAddress($email, $user['first_name']);

    $mail->isHTML(true);
    $mail->Subject = 'DasmaAlert - Password Reset Code';
    $mail->Body    = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2 style='color: #D32F2F;'>DasmaAlert Emergency Services</h2>
            <p>Hello <b>" . htmlspecialchars($user['first_name']) . "</b>,</p>
            <p>Your password reset verification code is:</p>
            <div style='font-size: 28px; font-weight: bold; letter-spacing: 4px; color: #D32F2F; margin: 20px 0;'>
                {$code}
            </div>
            <p>This code expires in <b>15 minutes</b>.</p>
        </div>";

    $mail->send();
    echo json_encode(["success" => true, "message" => "Verification code sent to your email."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Mailer Error: " . $mail->ErrorInfo]);
}

$conn->close();