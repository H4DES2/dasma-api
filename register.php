<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "", "alert"); // Database name 'alert'

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Connection failed"]);
    exit;
}

// Get the POST data
$fname    = $_POST['fname'];
$lname    = $_POST['lname'];
$username = $_POST['username'];
$email    = $_POST['email'];
$password = password_hash($_POST['password'], PASSWORD_DEFAULT);
$barangay = $_POST['barangay_id'];

// 1. Insert into 'users' table first
$userSql = "INSERT INTO users (username, email, password, role) VALUES ('$username', '$email', '$password', 'user')";

if ($conn->query($userSql) === TRUE) {
    $last_id = $conn->insert_id; // Get the ID of the user we just created

    // 2. Insert into 'client_profiles' using that User ID
    $profileSql = "INSERT INTO client_profiles (id, first_name, last_name, barangay) 
                   VALUES ('$last_id', '$fname', '$lname', '$barangay')";
    
    if ($conn->query($profileSql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Account created successfully!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Profile creation failed: " . $conn->error]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "User registration failed: " . $conn->error]);
}

$conn->close();
?>