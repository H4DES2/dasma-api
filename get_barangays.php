<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$barangays = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'barangays'");

if ($tableCheck && $tableCheck->num_rows > 0) {
    $colCheck = $conn->query("SHOW COLUMNS FROM barangays LIKE 'status'");
    $hasStatus = ($colCheck && $colCheck->num_rows > 0);

    $sql = $hasStatus 
        ? "SELECT id, name FROM barangays WHERE status = 'active' ORDER BY name ASC" 
        : "SELECT id, name FROM barangays ORDER BY name ASC";

    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $barangays[] = $row;
        }
    }
}

if (empty($barangays)) {
    $fallbackList = [
        "Burol I", "Burol II", "Burol III", "Burol Main",
        "Datu Esmael", "Fatima I", "Fatima II", "Fatima III",
        "H-2", "Langkaan I", "Langkaan II", "Paliparan I",
        "Paliparan II", "Paliparan III", "Sabang", "Salawag",
        "Salitran I", "Salitran II", "Salitran III", "Salitran IV",
        "Sampaloc I", "Sampaloc II", "Sampaloc III", "Sampaloc IV", "Sampaloc V",
        "San Agustin I", "San Agustin II", "San Agustin III",
        "San Andres I", "San Andres II", "San Antonio",
        "San Emmanuel", "San Esteban", "San Francisco",
        "San Isidro Labrador I", "San Isidro Labrador II",
        "San Jose", "San Juan", "San Lorenzo Ruiz I", "San Lorenzo Ruiz II",
        "San Luis I", "San Luis II", "San Manuel I", "San Manuel II",
        "San Mateo", "San Miguel I", "San Miguel II", "San Nicolas I", "San Nicolas II",
        "San Pedro I", "San Pedro II", "San Roque", "San Simon",
        "Santa Cristina I", "Santa Cristina II", "Santa Cruz I", "Santa Cruz II",
        "Santa Fe", "Santa Lucia", "Santa Maria", "Santo Cristo",
        "Santo Niño I", "Santo Niño II", "Victoria Reyes", "Zone I", "Zone I-B", "Zone II", "Zone III", "Zone IV"
    ];

    foreach ($fallbackList as $index => $name) {
        $barangays[] = [
            "id" => $index + 1,
            "name" => $name
        ];
    }
}

$response = [
    "success" => true,
    "data" => $barangays,
    "barangays" => $barangays
];

echo json_encode($response);
$conn->close();
exit();
?>