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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $otp   = $_POST['otp'] ?? '';

    if (empty($email) || empty($otp)) {
        echo json_encode(["status" => "error", "message" => "Email and OTP are required."]);
        exit();
    }

    $sql = "UPDATE users SET status = 'Active' WHERE email = ? AND otp_code = ? AND status = 'Pending'";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ss", $email, $otp);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Account activated!"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Invalid code or account already active."]);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Query failed: " . $conn->error]);
    }
}

$conn->close();
?>