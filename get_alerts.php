<?php
header('Content-Type: application/json');
$conn = new mysqli("localhost", "root", "", "alert");

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Connection failed"]);
    exit;
}

// Get the first name from the app to find their specific alerts
$fname = $_POST['fname'] ?? '';

$sql = "SELECT incidents.type, incidents.status, incidents.created_at 
        FROM incidents 
        JOIN users ON incidents.user_id = users.id 
        WHERE users.first_name = '$fname' 
        ORDER BY incidents.created_at DESC";

$result = $conn->query($sql);
$alerts = [];

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $alerts[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $alerts]);
$conn->close();
?>