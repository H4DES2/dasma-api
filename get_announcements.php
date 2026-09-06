<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

date_default_timezone_set('Asia/Manila');

// Fetch the latest 15 announcements
$query = "SELECT id, title, message, image_path, created_at FROM announcements ORDER BY created_at DESC LIMIT 15";
$result = $conn->query($query);

$announcements = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['created_at'] = date('M d, Y - h:i A', strtotime($row['created_at']));
        $row['image_path'] = $row['image_path'] ?? '';
        $announcements[] = $row;
    }
}

echo json_encode([
    'success' => true, 
    'data' => $announcements 
]);

$conn->close();
exit();