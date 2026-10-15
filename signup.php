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
    $barangay = trim($_POST['barangay'] ?? '');

    // Normalize alias
    if (strcasecmp($barangay, 'Burol Main') === 0) {
        $barangay = 'Burol';
    }

    if (empty($fname) || empty($username) || empty($email) || empty($mobile) || empty($password) || empty($barangay)) {
        echo json_encode(["status" => "error", "success" => false, "message" => "Missing required fields."]);
        exit();
    }

    // Strict 11-digit check starting with 09
    if (!preg_match('/^09[0-9]{9}$/', $mobile)) {
        echo json_encode([
            "status"  => "error", 
            "success" => false, 
            "message" => "Mobile number must be exactly 11 digits starting with 09."
        ]);
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $otp_code = (string)rand(100000, 999999);
    $fullName = trim("$fname $lname");

    // 1. Check if username or email already exists in users
    $chk = $conn->prepare("SELECT id, status FROM users WHERE username = ? OR email = ? LIMIT 1");
    if (!$chk) {
        echo json_encode(["status" => "error", "success" => false, "message" => "Query prepare failed: " . $conn->error]);
        exit();
    }

    $chk->bind_param("ss", $username, $email);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($existing) {
        // Account exists: check if it is still unverified (Pending)
        if (strcasecmp($existing['status'], 'Pending') === 0) {
            $user_id = (int)$existing['id'];

            // Update pending account with the latest details and a new OTP
            $upd = $conn->prepare("UPDATE users SET first_name=?, last_name=?, username=?, email=?, password=?, otp_code=?, barangay=? WHERE id=?");
            $upd->bind_param("sssssssi", $fname, $lname, $username, $email, $hashed_password, $otp_code, $barangay, $user_id);
            $upd->execute();
            $upd->close();

            // Update user profile phone number
            $p_stmt = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE phone_number = VALUES(phone_number)");
            if ($p_stmt) {
                $p_stmt->bind_param("is", $user_id, $mobile);
                $p_stmt->execute();
                $p_stmt->close();
            }

            // Dispatch fresh OTP email
            $emailSent = sendBrevoOtpEmail($email, $fullName, $otp_code);

            echo json_encode([
                "status"  => "success",
                "success" => true,
                "message" => $emailSent 
                    ? "Pending account updated! A new 6-digit code has been dispatched to your email." 
                    : "Account updated, but failed to deliver code. Please tap Resend Code.",
                "otp"     => $otp_code
            ]);
            exit();
        } else {
            // Already verified/active account
            echo json_encode([
                "status"  => "error", 
                "success" => false, 
                "message" => "Username or Email already taken."
            ]);
            exit();
        }
    }

    // 2. New registration
    $sql = "INSERT INTO users (first_name, last_name, username, email, password, role, status, otp_code, barangay) 
            VALUES (?, ?, ?, ?, ?, 'user', 'Pending', ?, ?)"; 
            
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssssss", $fname, $lname, $username, $email, $hashed_password, $otp_code, $barangay);
        
        try {
            $stmt->execute();
            $new_user_id = $conn->insert_id;
            
            $profile_stmt = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number) VALUES (?, ?)");
            if ($profile_stmt) {
                $profile_stmt->bind_param("is", $new_user_id, $mobile);
                $profile_stmt->execute();
                $profile_stmt->close();
            }
            
            // Dispatch OTP via Brevo HTTPS API
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
            echo json_encode(["status" => "error", "success" => false, "message" => "DB Error: " . $e->getMessage()]);
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