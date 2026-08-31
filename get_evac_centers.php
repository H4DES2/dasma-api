<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Prevent PHP warnings from breaking the JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Look for config.php in multiple likely locations
$possible_paths = [
    __DIR__ . '/../php/config.php', 
    __DIR__ . '/config.php',        
    'C:/xampp/htdocs/php/config.php' 
];

$config_found = false;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $config_found = true;
        break;
    }
}

if (!$config_found) {
    echo json_encode(['success' => false, 'error' => 'Database config not found']);
    exit();
}

$query = "SELECT id, name, barangay, capacity, current_occupants, latitude, longitude, status FROM evacuation_centers";
$result = $conn->query($query);

if ($result) {
    $centers = [];
    while ($row = $result->fetch_assoc()) {
        $centers[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $centers]);
} else {
    // If table doesn't exist, tell the app
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>