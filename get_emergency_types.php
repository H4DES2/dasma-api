<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'config.php';

$types = [];
$res = $conn->query("SELECT id, name, icon, incidents FROM emergency_types WHERE status = 'active' ORDER BY name ASC");

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $incList = [];
        if (!empty($row['incidents'])) {
            $incList = array_values(array_filter(array_map('trim', explode(',', $row['incidents']))));
        }
        $types[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'icon' => $row['icon'],
            'incidents' => $incList
        ];
    }
}

echo json_encode(['success' => true, 'data' => $types]);
$conn->close();