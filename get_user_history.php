<?php
// 🚨 CORS HEADERS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once 'config.php';

// Grab the user ID from the app
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($user_id > 0) {
    // Fetch all reports made by this exact user, newest first
   $query = "SELECT id, incident_type, barangay, severity, status, image_path, created_at, admin_remarks 
              FROM incidents 
              WHERE reported_by = $user_id 
              ORDER BY created_at DESC";
              
    $result = $conn->query($query);
    $history = [];
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Format date for the app
            $row['created_at'] = date('M d, Y - h:i A', strtotime($row['created_at']));
            $row['image_path'] = $row['image_path'] ?? '';
            $history[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'history' => $history]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
}
exit();
?>