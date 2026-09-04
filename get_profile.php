<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$input = json_decode(file_get_contents('php://input'), true);
$raw_id = $_POST['id'] ?? $_GET['id'] ?? $input['id'] ?? null;

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
            "barangay" => $user['barangay'],           
            "department" => $user['department'],
            "is_online" => (int)($user['is_online'] ?? 0),
            "profile_photo" => $user['profile_photo'] ?? '', 
            "phone_number" => $user['phone_number'] ?? '',
            "theme" => $user['theme'] ?? 'dark',                
            "font_size" => $user['font_size'] ?? '16px'         
        ]);
    } else {
        echo json_encode(["success" => false, "message" => "User not found."]);
    }
    
    $stmt->close();
} else {
    echo json_encode(["success" => false, "message" => "Invalid request. Missing ID or username."]);
}

$conn->close();