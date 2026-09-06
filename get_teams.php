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

$teams = [];
$checkTable = $conn->query("SHOW TABLES LIKE 'response_teams'");

if ($checkTable && $checkTable->num_rows > 0) {
    // Left join users table to compute responder counts and member names per unit
    $query = "
        SELECT 
            rt.id, 
            rt.team_name, 
            rt.team_type, 
            COALESCE(rt.assigned_barangay, 'City-Wide') AS assigned_barangay, 
            rt.status,
            COUNT(u.id) AS member_count,
            COALESCE(GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name) SEPARATOR ', '), '') AS members
        FROM response_teams rt
        LEFT JOIN users u ON (
            LOWER(TRIM(u.role)) = 'responder'
            AND (
                LOWER(TRIM(u.department)) = LOWER(TRIM(rt.team_name))
                OR (rt.assigned_barangay IS NOT NULL AND LOWER(TRIM(u.barangay)) = LOWER(TRIM(rt.assigned_barangay)))
            )
        )
        GROUP BY rt.id, rt.team_name, rt.team_type, rt.assigned_barangay, rt.status
        ORDER BY rt.team_name ASC
    ";
    $result = $conn->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['member_count'] = (int)$row['member_count'];
            $teams[] = $row;
        }
    }
}


echo json_encode($teams);
$conn->close();
exit();
?>