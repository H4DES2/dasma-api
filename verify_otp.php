<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$conn = new mysqli("localhost", "root", "", "alert");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $otp   = $_POST['otp'] ?? '';

    // Check if the email and OTP match a 'Pending' user
    $sql = "UPDATE users SET status = 'Active' WHERE email = ? AND otp_code = ? AND status = 'Pending'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $email, $otp);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(["status" => "success", "message" => "Account activated!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid code or account already active."]);
    }
}
?>