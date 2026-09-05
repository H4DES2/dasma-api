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

// Import PHPMailer if present
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../alert/vendor/autoload.php')) {
    require_once __DIR__ . '/../alert/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname    = $_POST['fname'] ?? '';
    $lname    = $_POST['lname'] ?? '';
    $username = $_POST['username'] ?? '';
    $email    = $_POST['email'] ?? '';
    $mobile   = $_POST['mobile'] ?? ''; 
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
            
            // Send OTP email if PHPMailer exists
            if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->SMTPOptions = array(
                        'ssl' => array(
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        )
                    );

                    $mail->Host       = SMTP_HOST; 
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER; 
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
                    $mail->Port       = 465;

                    $mail->setFrom(FROM_EMAIL, FROM_NAME);
                    $mail->addAddress($email, $fname); 

                    $mail->isHTML(true);
                    $mail->Subject = 'DASMA ALERT - Your Verification Code';
                    $mail->Body    = "Hello <b>" . htmlspecialchars($fname) . "</b>,<br><br>Your verification code is: <h2 style='color:#D32F2F;'>$otp_code</h2><br><br>Do not share this code.";
                    $mail->AltBody = "Hello $fname, your code is: $otp_code";

                    $mail->send();

                    echo json_encode([
                        "status" => "success", 
                        "success" => true, 
                        "message" => "Account created! Check email for OTP.",
                        "otp" => $otp_code
                    ]);
                } catch (\Exception $e) {
                    echo json_encode([
                        "status" => "error", 
                        "success" => true, 
                        "message" => "Account created, but email failed: " . $mail->ErrorInfo,
                        "otp" => $otp_code 
                    ]);
                }
            } else {
                echo json_encode([
                    "status" => "success", 
                    "success" => true, 
                    "message" => "Account created! OTP generated.",
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
    } else {
        echo json_encode(["status" => "error", "success" => false, "message" => "Query prepare failed: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "error", "success" => false, "message" => "Invalid request method."]);
}

$conn->close();
?>