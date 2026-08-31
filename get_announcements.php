<?php
// 🚨 CORS HEADERS - Crucial for mobile apps to connect
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Connect to the database
require_once 'config.php';

// Fetch the latest 15 announcements
$query = "SELECT id, title, message, image_path, created_at FROM announcements ORDER BY created_at DESC LIMIT 15";
$result = $conn->query($query);

$announcements = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Format the date nicely so Flutter doesn't have to do the math
        $row['created_at'] = date('M d, Y - h:i A', strtotime($row['created_at']));
        
        // Ensure image path isn't strictly null in JSON
        $row['image_path'] = $row['image_path'] ?? '';
        
        $announcements[] = $row;
    }
}

// Send the JSON package across the bridge!
echo json_encode([
    'success' => true, 
    // 🚀 THE FIX: Renamed 'announcements' to 'data' to match your Flutter app perfectly!
    'data' => $announcements 
]);

exit();
?>