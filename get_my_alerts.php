<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

$raw_id = $_REQUEST['user_id'] ?? $_REQUEST['id'] ?? null;

if ($raw_id === null) {
    echo json_encode(["success" => false, "data" => [], "message" => "User ID required."]);
    exit();
}

$user_id = (int)$raw_id;

$sql = "SELECT * FROM incidents WHERE reported_by = ? OR client_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["success" => false, "data" => [], "sql_error" => $conn->error]);
    exit();
}

$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$alerts = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['created_at'])) {
            $row['created_at'] = date("M d, Y - h:i A", strtotime($row['created_at']));
        }
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }
        $alerts[] = $row;
    }
}

echo json_encode(["success" => true, "data" => $alerts]);

$stmt->close();
$conn->close();
?>