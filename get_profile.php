<?php
// 1. CORS Headers & Preflight Handling for Web & Mobile
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';

// Auto-create API rate limiting table if missing using the unified schema
$conn->query("CREATE TABLE IF NOT EXISTS api_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(64) NOT NULL,
    identifier_type ENUM('ip', 'user') NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    request_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_lookup (identifier, identifier_type, endpoint, request_time)
)");

// Extract Client IP
$ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '0.0.0.0';
$ip_address = trim(explode(',', $ip_address)[0]);

// 2. Rate Limit Guard: Max 60 requests per minute per IP for this endpoint
$endpoint_tag = 'get_profile';
$rate_stmt = $conn->prepare("
    SELECT COUNT(*) as req_count 
    FROM api_rate_limits 
    WHERE identifier = ? 
      AND identifier_type = 'ip'
      AND endpoint = ? 
      AND request_time >= (NOW() - INTERVAL 1 MINUTE)
");

if ($rate_stmt) {
    $rate_stmt->bind_param("ss", $ip_address, $endpoint_tag);
    $rate_stmt->execute();
    $rate_res = $rate_stmt->get_result()->fetch_assoc();
    $rate_stmt->close();

    if (($rate_res['req_count'] ?? 0) >= 60) {
        http_response_code(429);
        echo json_encode([
            "success" => false,
            "message" => "Rate limit exceeded. Please wait a minute before requesting profile data again."
        ]);
        exit();
    }
}

// Log this valid request
$log_stmt = $conn->prepare("INSERT INTO api_rate_limits (identifier, identifier_type, endpoint) VALUES (?, 'ip', ?)");
if ($log_stmt) {
    $log_stmt->bind_param("ss", $ip_address, $endpoint_tag);
    $log_stmt->execute();
    $log_stmt->close();
}

// 3. Fetch Profile Data
$input = json_decode(file_get_contents('php://input'), true);
$raw_id = $_POST['id'] ?? $_GET['id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? $input['id'] ?? $input['user_id'] ?? null;

if ($raw_id !== null && $raw_id !== '') {
    if (is_numeric($raw_id)) {
        $sql = "SELECT 
                    u.id, u.first_name, u.last_name, u.username, u.barangay, u.department, u.is_online,
                    p.profile_photo, p.phone_number, p.theme, p.font_size 
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE u.id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $raw_id);
    } else {
        $sql = "SELECT 
                    u.id, u.first_name, u.last_name, u.username, u.barangay, u.department, u.is_online,
                    p.profile_photo, p.phone_number, p.theme, p.font_size 
                FROM users u
                LEFT JOIN user_profiles p ON u.id = p.user_id
                WHERE u.username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $raw_id);
    }
    
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        exit();
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        echo json_encode([
            "success" => true,
            "id" => $user['id'],
            "username" => $user['username'],
            "first_name" => $user['first_name'],
            "last_name" => $user['last_name'],
            "barangay" => $user['barangay'] ?? 'City of Dasmariñas',           
            "department" => $user['department'],
            "is_online" => (int)($user['is_online'] ?? 0),
            "profile_photo" => $user['profile_photo'] ?? '', 
            "phone_number" => $user['phone_number'] ?? '',
            "theme" => $user['theme'] ?? 'dark',                
            "font_size" => $user['font_size'] ?? '16px',
            "profile" => $user
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "User not found."]);
    }
    
    $stmt->close();
} else {
    echo json_encode(["success" => false, "message" => "Invalid request. Missing ID or username."]);
}

$conn->close();