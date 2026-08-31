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

$fname    = $_POST['fname'] ?? '';
$lname    = $_POST['lname'] ?? '';
$username = $_POST['username'] ?? '';
$email    = $_POST['email'] ?? '';
$raw_pass = $_POST['password'] ?? '';
$barangay = $_POST['barangay_id'] ?? $_POST['barangay'] ?? '';

if (empty($username) || empty($email) || empty($raw_pass) || empty($fname) || empty($lname)) {
    echo json_encode(["status" => "error", "message" => "All required fields must be provided."]);
    exit();
}

$password = password_hash($raw_pass, PASSWORD_DEFAULT);

$conn->begin_transaction();

try {
    // 1. Insert into 'users' table
    $stmt_user = $conn->prepare("INSERT INTO users (username, email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, 'user')");
    $stmt_user->bind_param("sssss", $username, $email, $password, $fname, $lname);
    
    if (!$stmt_user->execute()) {
        throw new Exception("User registration failed: " . $stmt_user->error);
    }
    
    $last_id = $conn->insert_id;
    $stmt_user->close();

    // 2. Insert into 'client_profiles'
    $stmt_profile = $conn->prepare("INSERT INTO client_profiles (user_id, first_name, last_name, barangay) VALUES (?, ?, ?, ?)");
    $stmt_profile->bind_param("isss", $last_id, $fname, $lname, $barangay);
    
    if (!$stmt_profile->execute()) {
        throw new Exception("Profile creation failed: " . $stmt_profile->error);
    }
    $stmt_profile->close();

    $conn->commit();
    echo json_encode(["status" => "success", "message" => "Account created successfully!"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

$conn->close();
?>