<?php
// get_nearby_incidents.php
header('Content-Type: application/json');
require_once '../php/config.php';

// Get the user's current GPS location from the mobile app
$user_lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$user_lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$radius_meters = isset($_POST['radius']) ? (int)$_POST['radius'] : 5000; // Default 5km

if ($user_lat === 0.0 || $user_lng === 0.0) {
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
    exit();
}

// 🚀 SPATIAL QUERY: MariaDB instantly ignores everything outside the radius
$sql = "SELECT id, incident_type, severity, barangay, created_at, latitude, longitude,
               ST_Distance_Sphere(geo_point, ST_PointFromText(?)) as distance_meters 
        FROM incidents 
        WHERE status NOT IN ('archived', 'rejected') 
        AND ST_Distance_Sphere(geo_point, ST_PointFromText(?)) <= ?
        ORDER BY distance_meters ASC 
        LIMIT 50";

$stmt = $conn->prepare($sql);

// Format the point string for MariaDB: 'POINT(longitude latitude)'
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
echo json_encode(['success' => true, 'data' => $incidents]);
?>