<?php
// Hide HTML warnings so JSON stays clean
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $user_id = $_POST['id']; 

    // 🚀 ADDED: u.is_online to SELECT query
    $sql = "SELECT 
                u.id, u.first_name, u.last_name, u.username, u.barangay, u.department, u.is_online,
                p.profile_photo, p.theme, p.font_size 
            FROM users u
            LEFT JOIN user_profiles p ON u.id = p.user_id
            WHERE u.id = ?";
            
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        echo json_encode(["success" => false, "message" => "Database error: " . $conn->error]);
        exit();
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        echo json_encode([
            "success" => true,
            "id" => $user['id'],
            "username" => $user['username'],
            "first_name" => $user['first_name'],
            "last_name" => $user['last_name'],
            "barangay" => $user['barangay'],           
            "department" => $user['department'],
            "is_online" => $user['is_online'] ?? 0, // 🚀 ADDED: Returned to Flutter
            "profile_photo" => $user['profile_photo'] ?? '', 
            "theme" => $user['theme'] ?? 'dark',                
            "font_size" => $user['font_size'] ?? '16px'         
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "User not found."]);
    }
} else {
    echo json_encode(["success" => false, "message" => "Invalid request. Missing ID."]);
}

$conn->close();
?>