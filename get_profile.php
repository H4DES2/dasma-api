<?php
// Clear any accidental output/BOM so response is clean JSON
while (ob_get_level() > 0) { 
    ob_end_clean(); 
}

// 1. CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. Safe Config Include (Handles both root and subfolder structures)
if (file_exists(__DIR__ . '/php/config.php')) {
    require_once __DIR__ . '/php/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/../php/config.php')) {
    require_once __DIR__ . '/../php/config.php';
} else {
    echo json_encode(["success" => false, "message" => "Database config file not found."]);
    exit();
}

// Extract ID from POST, GET, or raw JSON body
$raw_input = json_decode(file_get_contents('php://input'), true);
$raw_id = $_POST['id'] ?? $_POST['userId'] ?? $_POST['user_id'] ?? $_GET['id'] ?? $raw_input['id'] ?? $raw_input['userId'] ?? null;

$user_id = $raw_id !== null ? (int)$raw_id : 0;

if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "Missing or invalid User ID"]);
    exit();
}

// 3. Query User with Coalesce Fallback across users and user_profiles
$sql = "SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.username, 
            COALESCE(u.barangay, 'City of Dasmariñas') AS barangay, 
            COALESCE(u.department, '') AS department, 
            COALESCE(u.is_online, 0) AS is_online,
            COALESCE(NULLIF(p.profile_photo, ''), NULLIF(u.profile_photo, ''), '') AS profile_photo,
            COALESCE(p.phone_number, '') AS phone_number,
            COALESCE(p.theme, 'light') AS theme,
            COALESCE(p.font_size, '16px') AS font_size
        FROM users u
        LEFT JOIN user_profiles p ON u.id = p.user_id
        WHERE u.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["success" => false, "message" => "SQL query error: " . $conn->error]);
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    echo json_encode([
        "success" => true,
        "id" => (int)$user['id'],
        "username" => $user['username'],
        "first_name" => $user['first_name'],
        "last_name" => $user['last_name'],
        "barangay" => $user['barangay'],           
        "department" => $user['department'],
        "is_online" => (int)$user['is_online'],
        "profile_photo" => $user['profile_photo'], 
        "phone_number" => $user['phone_number'],
        "theme" => $user['theme'],                
        "font_size" => $user['font_size'],
        "profile" => $user
    ]);
} else {
    echo json_encode(["success" => false, "message" => "User record not found."]);
}

$stmt->close();
$conn->close();