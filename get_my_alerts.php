<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=UTF-8');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host = "localhost";
$username = "root";
$password = "";
$database = "alert";

try {
    $conn = new mysqli($host, $username, $password, $database);
} catch (\mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode(["data" => [], "error" => "Connection exception: " . $e->getMessage()]);
    exit();
}
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["data" => [], "error" => "Connection failed: " . $conn->connect_error]);
    exit();
}

$raw_id = $_REQUEST['user_id'] ?? $_REQUEST['id'] ?? 2;
$user_id = (int)$raw_id;

$sql = "SELECT * FROM incidents WHERE reported_by = ? OR client_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["data" => [], "sql_error" => $conn->error]);
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
        // Force-clean every string field to valid UTF-8
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
        }
        $alerts[] = $row;
    }
}

$json = json_encode(["data" => $alerts]);
if ($json === false) {
    echo json_encode(["data" => [], "json_error" => json_last_error_msg()]);
} else {
    echo $json;
}

$stmt->close();
$conn->close();