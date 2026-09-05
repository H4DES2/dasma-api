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

function sendBrevoOtpEmail($recipientEmail, $recipientName, $otpCode) {
    $apiKey = getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? '');
    $senderEmail = 'jacobbataclanortega@gmail.com';

    $payload = [
        'sender'      => ['name' => 'Dasma Alert', 'email' => $senderEmail],
        'to'          => [['email' => $recipientEmail, 'name' => $recipientName]],
        'subject'     => 'Dasma Alert - Your Verification Code',
        'htmlContent' => '
            <div style="font-family: Arial, sans-serif; padding: 20px; color: #333;">
                <h2 style="color: #d32f2f;">Dasma Alert Verification</h2>
                <p>Hello ' . htmlspecialchars($recipientName) . ',</p>
                <p>Your one-time verification code is:</p>
                <div style="font-size: 28px; font-weight: bold; letter-spacing: 4px; color: #d32f2f; margin: 16px 0;">' . $otpCode . '</div>
                <p>This code will expire shortly. Do not share it with anyone.</p>
            </div>'
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . trim($apiKey),
        'Content-Type: application/json',
        'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 201 || $httpCode === 200);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname    = trim($_POST['fname'] ?? '');
    $lname    = trim($_POST['lname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $mobile   = trim($_POST['mobile'] ?? ''); 
    $password = $_POST['password'] ?? '';

    if (empty($fname) || empty($username) || empty($email) || empty($mobile) || empty($password)) {
        echo json_encode(["status" => "error", "success" => false, "message" => "Missing required fields."]);
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $otp_code = (string)rand(100000, 999999);

    $sql = "INSERT INTO users (first_name, last_name, username, email, password, role, status, otp_code) 
            VALUES (?, ?, ?, ?, ?, 'user', 'Pending', ?)"; 
            
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ssssss", $fname, $lname, $username, $email, $hashed_password, $otp_code);
        
        try {
            $stmt->execute();
            $new_user_id = $conn->insert_id;
            
            $profile_stmt = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number) VALUES (?, ?)");
            if ($profile_stmt) {
                $profile_stmt->bind_param("is", $new_user_id, $mobile);
                $profile_stmt->execute();
                $profile_stmt->close();
            }
            
            // Dispatch OTP via Brevo HTTPS API (Port 443)
            $fullName = trim("$fname $lname");
            $emailSent = sendBrevoOtpEmail($email, $fullName, $otp_code);

            if ($emailSent) {
                echo json_encode([
                    "status"  => "success", 
                    "success" => true, 
                    "message" => "Account created! Check email for OTP.",
                    "otp"     => $otp_code
                ]);
            } else {
                echo json_encode([
                    "status"  => "error", 
                    "success" => false, 
                    "message" => "Account created, but failed to deliver verification email. Please tap Resend Code."
                ]);
            }

        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) { 
                echo json_encode(["status" => "error", "success" => false, "message" => "Username or Email already taken."]);
            } else {
                echo json_encode(["status" => "error", "success" => false, "message" => "DB Error: " . $e->getMessage()]);
            }
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "success" => false, "message" => "Query prepare failed: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "error", "success" => false, "message" => "Invalid request method."]);
}

$conn->close();
?>