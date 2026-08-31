<?php
// 🚀 1. ALLOW CORS (Cross-Origin Resource Sharing)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// 🚀 2. HANDLE "PREFLIGHT" BROWSER CHECKS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 🚀 3. CONNECT TO DATABASE (Crucial! Without this, $conn doesn't exist)
require_once 'config.php'; 

// 1. Get the data from the request
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Username and password required"]);
    exit;
}

// 2. Look for the user in the 'users' table
// We fetch first_name and last_name because your app needs them
$sql = "SELECT * FROM users WHERE username = '$username' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();

    // 3. Check if the password is correct
    if (password_verify($password, $user['password'])) {
        echo json_encode([
            "success" => true,
            "id" => $user['id'], // 🚨 FIXED! Changed to exactly "id"
            "username" => $user['username'],
            "fname" => $user['first_name'],
            "lname" => $user['last_name'],
            "role" => $user['role']
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Incorrect password"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "User does not exist"]);
}

$conn->close();
?>