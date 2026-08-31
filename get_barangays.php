<?php
header('Content-Type: application/json');

$conn = new mysqli("localhost", "root", "", "alert");

if ($conn->connect_error) {
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit();
}

$barangays = [];
$sql = "SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);

if ($result) {
    while($row = $result->fetch_assoc()) {
        $barangays[] = $row;
    }
    echo json_encode(["success" => true, "data" => $barangays]);
} else {
    echo json_encode(["success" => false, "message" => "Query failed: " . $conn->error]);
}

$conn->close();
?>