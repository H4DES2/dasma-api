<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../alert/vendor/autoload.php')) {
    require_once __DIR__ . '/../alert/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required."]);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, first_name, status FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "Account not found."]);
        exit();
    }

    if ($user['status'] === 'Active') {
        echo json_encode(["status" => "error", "message" => "Account is already verified. Please sign in."]);
        exit();
    }

    $new_otp = (string)rand(100000, 999999);
    $up_stmt = $conn->prepare("UPDATE users SET otp_code = ? WHERE id = ?");
    $up_stmt->bind_param("si", $new_otp, $user['id']);
    $up_stmt->execute();
    $up_stmt->close();

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = defined('SMTP_USER') ? SMTP_USER : '';
            $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
            // Use SMTPS on 465 to bypass Render port 587 blocks
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            $mail->setFrom(defined('FROM_EMAIL') ? FROM_EMAIL : SMTP_USER, defined('FROM_NAME') ? FROM_NAME : 'DasmaAlert');
            $mail->addAddress($email, $user['first_name']);

            $mail->isHTML(true);
            $mail->Subject = 'DASMA ALERT - Resent Verification Code';
            $mail->Body    = "Hello <b>" . htmlspecialchars($user['first_name']) . "</b>,<br><br>Your new verification code is: <h2 style='color:#D32F2F;'>$new_otp</h2><br><br>This code expires shortly. Do not share this code.";
            $mail->AltBody = "Your new code is: $new_otp";

            $mail->send();
            echo json_encode(["status" => "success", "message" => "A new verification code has been sent to your email."]);
        } catch (\Exception $e) {
            echo json_encode(["status" => "error", "message" => "Email sending failed: " . $mail->ErrorInfo]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Mailer library unavailable."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}

$conn->close();