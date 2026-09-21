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

// Accept both POST and GET parameters
$user_lat      = isset($_REQUEST['latitude']) ? (float)$_REQUEST['latitude'] : 0.0;
$user_lng      = isset($_REQUEST['longitude']) ? (float)$_REQUEST['longitude'] : 0.0;
$radius_meters = isset($_REQUEST['radius']) ? (int)$_REQUEST['radius'] : 5000; // Default 5km

if ($user_lat === 0.0 || $user_lng === 0.0) {
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates']);
    exit();
}

// Robust query calculating distance dynamically from numeric latitude & longitude
$sql = "SELECT id, incident_type, severity, barangay, block, lot, phase, subdivision,
               created_at, latitude, longitude, image_path, status,
               ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) AS distance_meters 
        FROM incidents 
        WHERE status NOT IN ('archived', 'rejected') 
          AND latitude != 0 AND longitude != 0
          AND ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= ?
        ORDER BY distance_meters ASC 
        LIMIT 50";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Database query preparation failed: ' . $conn->error]);
    exit();
}

// Bind coordinates: POINT(lng, lat)
$stmt->bind_param("ddddi", $user_lng, $user_lat, $user_lng, $user_lat, $radius_meters);
$stmt->execute();
$result = $stmt->get_result();

$incidents = [];
while ($row = $result->fetch_assoc()) {
    $row['distance_km'] = round($row['distance_meters'] / 1000, 2);

    // Format consolidated address
    $addr_parts = array_filter([
        !empty($row['subdivision']) ? 'Subd: ' . $row['subdivision'] : '',
        !empty($row['phase'])       ? 'Ph ' . $row['phase'] : '',
        !empty($row['block'])       ? 'Blk ' . $row['block'] : '',
        !empty($row['lot'])         ? 'Lot ' . $row['lot'] : ''
    ]);
    
    $prefix = !empty($addr_parts) ? implode(', ', $addr_parts) . ', ' : '';
    $row['formatted_address'] = $prefix . 'Brgy. ' . $row['barangay'];

    $incidents[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'data' => $incidents]);
?>