<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
require 'config.php'; // Using your existing config for connection

// 🚀 1. Capture data directly from Flutter
// We use the names sent in reportData from emergency_page.dart
$reported_by = $_POST['reported_by'] ?? 0;
$incident_type = $_POST['incident_type'] ?? 'General';
$latitude    = $_POST['latitude'] ?? 0;
$longitude   = $_POST['longitude'] ?? 0;
$barangay    = $_POST['barangay'] ?? 'Unknown';

// 🚀 2. Corrected INSERT statement 
// I updated the column names to match your database screenshot exactly!
$sql = "INSERT INTO incidents (reported_by, incident_type, latitude, longitude, status, barangay) 
        VALUES ('$reported_by', '$incident_type', '$latitude', '$longitude', 'active', '$barangay')";

if ($conn->query($sql) === TRUE) {
    echo json_encode([
        "status" => "success", 
        "success" => true, 
        "message" => "SOS Sent! Help is coming."
    ]);
} else {
    echo json_encode([
        "status" => "error", 
        "success" => false, 
        "message" => "Database Error: " . $conn->error
    ]);
}

$conn->close();
?>