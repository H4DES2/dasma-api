<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$lat = isset($_GET['latitude']) ? (float)$_GET['latitude'] : 0.0;
$lng = isset($_GET['longitude']) ? (float)$_GET['longitude'] : 0.0;

if ($lat == 0.0 || $lng == 0.0) {
    echo json_encode(['success' => false, 'barangay' => 'Unknown Location']);
    exit();
}

$sql = "
    SELECT name 
    FROM barangays 
    WHERE boundary IS NOT NULL 
    ORDER BY ST_Distance(ST_SRID(boundary, 0), POINT(?, ?)) ASC 
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("dd", $lng, $lat);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

echo json_encode([
    'success' => true,
    'barangay' => $row['name'] ?? 'Zone IV'
]);