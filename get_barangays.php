<?php
header('Content-Type: application/json');
require_once 'config.php';

$barangays = [];
$sql = "SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $barangays[] = $row;
    }
    echo json_encode(["success" => true, "data" => $barangays]);
} else {
    echo json_encode(["success" => false, "message" => "Query failed: " . $conn->error]);
}

$conn->close();
?>