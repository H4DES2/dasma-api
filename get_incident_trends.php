<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';

// Optional filter by target barangay or target month
$target_brgy = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';
$target_month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$whereClauses = ["barangay IS NOT NULL", "barangay != ''", "incident_type IS NOT NULL"];
$params = [];
$types = "";

if (!empty($target_brgy)) {
    $whereClauses[] = "LOWER(TRIM(barangay)) = LOWER(?)";
    $params[] = $target_brgy;
    $types .= "s";
}

if ($target_month > 0 && $target_month <= 12) {
    $whereClauses[] = "MONTH(created_at) = ?";
    $params[] = $target_month;
    $types .= "i";
}

$whereSql = implode(" AND ", $whereClauses);

$sql = "
    SELECT 
        TRIM(barangay) AS barangay,
        TRIM(incident_type) AS incident_type,
        MONTH(created_at) AS incident_month,
        COUNT(*) AS total_incidents,
        SUM(CASE WHEN LOWER(severity) = 'critical' THEN 1 ELSE 0 END) AS critical_count,
        ROUND(AVG(latitude), 6) AS avg_lat,
        ROUND(AVG(longitude), 6) AS avg_lng
    FROM incidents
    WHERE {$whereSql}
    GROUP BY TRIM(barangay), TRIM(incident_type), MONTH(created_at)
    ORDER BY incident_month ASC, total_incidents DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$trends = [];
while ($row = $result->fetch_assoc()) {
    $trends[] = [
        'barangay'        => $row['barangay'],
        'incident_type'   => $row['incident_type'],
        'month'           => (int)$row['incident_month'],
        'total_incidents' => (int)$row['total_incidents'],
        'critical_count'  => (int)$row['critical_count'],
        'coordinates'     => [
            'lat' => (float)$row['avg_lat'],
            'lng' => (float)$row['avg_lng']
        ]
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'total_clusters' => count($trends),
    'data' => $trends
], JSON_PRETTY_PRINT);