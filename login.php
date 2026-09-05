<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight OPTIONS request sent by the browser
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$inputData = $_POST;
if (empty($inputData)) {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $inputData = $json;
    }
}

$username = trim($inputData['username'] ?? '');
$password = $inputData['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Username and password required"
    ]);
    exit();
}

$stmt = $conn->prepare("SELECT id, username, password, first_name, last_name, role FROM users WHERE username = ? OR email = ? LIMIT 1");
$stmt->bind_param("ss", $username, $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();

    if (password_verify($password, $user['password'])) {
        $rawRole = strtolower(trim($user['role'] ?? ''));
        $cleanRole = 'citizen';
        if (strpos($rawRole, 'responder') !== false) {
            $cleanRole = 'responder';
        } elseif (strpos($rawRole, 'superadmin') !== false) {
            $cleanRole = 'superadmin';
        } elseif (strpos($rawRole, 'admin') !== false) {
            $cleanRole = 'admin';
        }

        echo json_encode([
            "status" => "success",
            "success" => true,
            "message" => "Login successful!",
            "role" => $cleanRole,
            "id" => (string)$user['id'],
            "username" => $user['username'],
            "fname" => $user['first_name'] ?? '',
            "lname" => $user['last_name'] ?? '',
            "user" => [
                "id" => (string)$user['id'],
                "username" => $user['username'],
                "fname" => $user['first_name'] ?? '',
                "lname" => $user['last_name'] ?? '',
                "role" => $cleanRole,
                "is_verified" => 1
            ]
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "success" => false,
            "message" => "Invalid password"
        ]);
    }
} else {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "User not found"
    ]);
}

$stmt->close();
$conn->close();