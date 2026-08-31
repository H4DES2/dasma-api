<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

// Get the user's current GPS location from the mobile app
$user_lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$user_lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$radius_meters = isset($_POST['radius']) ? (int)$_POST['radius'] : 5000; // Default 5km

if ($user_lat === 0.0 || $user_lng === 0.0) {
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
    exit();
}

// SPATIAL QUERY: MariaDB/MySQL distance check
$sql = "SELECT id, incident_type, severity, barangay, created_at, latitude, longitude,
               ST_Distance_Sphere(geo_point, ST_PointFromText(?)) as distance_meters 
        FROM incidents 
        WHERE status NOT IN ('archived', 'rejected') 
        AND ST_Distance_Sphere(geo_point, ST_PointFromText(?)) <= ?
        ORDER BY distance_meters ASC 
        LIMIT 50";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database query preparation failed: ' . $conn->error]);
    exit();
}

// Format the point string: 'POINT(longitude latitude)'
$point_str = "POINT($user_lng $user_lat)"; 

$stmt->bind_param("ssi", $point_str, $point_str, $radius_meters);
$stmt->execute();
$result = $stmt->get_result();

$incidents = [];
while ($row = $result->fetch_assoc()) {
    $row['distance_km'] = round($row['distance_meters'] / 1000, 2);
    $incidents[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'data' => $incidents]);
?>