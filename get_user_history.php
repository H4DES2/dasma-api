<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

// Grab the user ID from the app
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($user_id > 0) {
    $sql = "SELECT id, incident_type, barangay, severity, status, image_path, created_at, admin_remarks 
            FROM incidents 
            WHERE reported_by = ? 
            ORDER BY created_at DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $history = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['created_at'] = date('M d, Y - h:i A', strtotime($row['created_at']));
            $row['image_path'] = $row['image_path'] ?? '';
            $history[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'history' => $history]);
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
}

$conn->close();
exit();
?>