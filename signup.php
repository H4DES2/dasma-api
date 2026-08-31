<?php
// 1. Force strict JSON and CORS headers
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");


// 2. Keep errors on for now so we can see if PHPMailer complains
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- IMPORT PHPMAILER ---
// 🚨 Ensure this path is correct based on your folder structure
require '../alert/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
$conn = new mysqli("localhost", "root", "", "alert");

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "success" => false, "message" => "Connection failed"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Capture data from Flutter
    $fname    = $_POST['fname'] ?? '';
    $lname    = $_POST['lname'] ?? '';
    $username = $_POST['username'] ?? '';
    $email    = $_POST['email'] ?? '';
    $mobile   = $_POST['mobile'] ?? ''; 
    $password = $_POST['password'] ?? '';

    if (empty($fname) || empty($username) || empty($email) || empty($mobile) || empty($password)) {
        echo json_encode(["status" => "error", "success" => false, "message" => "Missing required fields."]);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $otp_code = rand(100000, 999999);

    // 🚀 FIX: Removed 'phone_number' from the 'users' table insert
    $sql = "INSERT INTO users (first_name, last_name, username, email, password, role, status, otp_code) 
        VALUES (?, ?, ?, ?, ?, 'user', 'Pending', ?)"; 
            
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ssssss", $fname, $lname, $username, $email, $hashed_password, $otp_code);
        
        try {
            $stmt->execute();
            
            // 🚀 FIX: Get the new user ID and immediately insert the phone number into 'user_profiles'
            $new_user_id = $stmt->insert_id;
            $profile_stmt = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number) VALUES (?, ?)");
            if ($profile_stmt) {
                $profile_stmt->bind_param("is", $new_user_id, $mobile);
                $profile_stmt->execute();
                $profile_stmt->close();
            }
            
            // 🚀 --- PHPMAILER INTEGRATION ---
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                
                // 🛡️ LOCALHOST SSL BYPASS
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->Host       = 'smtp.gmail.com'; 
                $mail->SMTPAuth   = true;
                
                // 🚨 NEW CREDENTIALS APPLIED
                $mail->Username   = 'jacobbataclanortega@gmail.com'; 
                $mail->Password   = 'exaprftqupdaxwhc'; // No spaces!
                
                // 🚀 Recommended Port for XAMPP
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
                $mail->Port       = 587; 

                // Recipient Details
                $mail->setFrom('jacobbataclanortega@gmail.com', 'Dasma Alert');
                $mail->addAddress($email, $fname); 

                $mail->isHTML(true);
                $mail->Subject = 'DASMA ALERT - Your Verification Code';
                $mail->Body    = "Hello <b>$fname</b>,<br><br>Your verification code is: <h2 style='color:#D32F2F;'>$otp_code</h2><br><br>Do not share this code.";
                $mail->AltBody = "Hello $fname, your code is: $otp_code";

                $mail->send();

                echo json_encode([
                    "status" => "success", 
                    "success" => true, 
                    "message" => "Account created! Check email for OTP.",
                    "otp" => $otp_code
                ]);

            } catch (Exception $e) {
                echo json_encode([
                    "status" => "error", 
                    "success" => true, 
                    "message" => "Account created, but email failed: {$mail->ErrorInfo}",
                    "otp" => $otp_code 
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
    }
}
$conn->close();
?>