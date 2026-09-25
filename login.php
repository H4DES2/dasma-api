<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

// Auto-create rate limiting table if missing
$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempt_time),
    INDEX idx_user_time (username, attempt_time)
)");

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

// Identify client IP
$ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '0.0.0.0';
$ip_address = trim(explode(',', $ip_address)[0]);

// 1. Check Rate Limit: max 5 failed attempts in the last 15 minutes
$rate_stmt = $conn->prepare("
    SELECT COUNT(*) as failed_attempts 
    FROM login_attempts 
    WHERE (ip_address = ? OR username = ?) 
      AND attempt_time >= (NOW() - INTERVAL 15 MINUTE)
");
$rate_stmt->bind_param("ss", $ip_address, $username);
$rate_stmt->execute();
$rate_res = $rate_stmt->get_result()->fetch_assoc();
$rate_stmt->close();

if (($rate_res['failed_attempts'] ?? 0) >= 5) {
    http_response_code(429);
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Too many failed login attempts. Please wait 15 minutes before trying again."
    ]);
    exit();
}

function record_failed_attempt($conn, $ip, $user) {
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $ip, $user);
        $stmt->execute();
        $stmt->close();
    }
}

$stmt = $conn->prepare("SELECT id, username, password, first_name, last_name, role FROM users WHERE username = ? OR email = ? LIMIT 1");
$stmt->bind_param("ss", $username, $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();

    if (password_verify($password, $user['password'])) {
        // Clear failed attempts upon successful authentication
        $clear_stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR username = ?");
        if ($clear_stmt) {
            $clear_stmt->bind_param("ss", $ip_address, $username);
            $clear_stmt->execute();
            $clear_stmt->close();
        }

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
        record_failed_attempt($conn, $ip_address, $username);
        echo json_encode([
            "status" => "error",
            "success" => false,
            "message" => "Invalid password"
        ]);
    }
} else {
    record_failed_attempt($conn, $ip_address, $username);
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "User not found"
    ]);
}

$stmt->close();
$conn->close();