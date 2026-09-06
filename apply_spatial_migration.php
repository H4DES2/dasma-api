<?php
require_once 'config.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "=== Starting Spatial Migration ===\n";

// 1. Add boundary column if not present
$checkCol = $conn->query("SHOW COLUMNS FROM barangays LIKE 'boundary'");
if ($checkCol && $checkCol->num_rows === 0) {
    $res = $conn->query("ALTER TABLE barangays ADD COLUMN boundary MULTIPOLYGON NULL SRID 4326");
    if ($res) {
        echo "[SUCCESS] Added 'boundary' MULTIPOLYGON column.\n";
    } else {
        echo "[ERROR] Failed to add boundary column: " . $conn->error . "\n";
    }

    $idxRes = $conn->query("CREATE SPATIAL INDEX idx_barangay_boundary ON barangays(boundary)");
    if ($idxRes) {
        echo "[SUCCESS] Created spatial index on boundary.\n";
    } else {
        echo "[ERROR] Failed to create spatial index: " . $conn->error . "\n";
    }
} else {
    echo "[INFO] Column 'boundary' already exists in 'barangays'.\n";
}

echo "=== Migration Finished ===\n";
$conn->close();