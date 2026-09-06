<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

// --- SPATIAL POINT-IN-POLYGON HELPER ---
function isPointInPolygon($point, $polygon) {
    $x = $point[0]; // lng
    $y = $point[1]; // lat
    $inside = false;
    $count = count($polygon);

    for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
        $xi = $polygon[$i][0]; $yi = $polygon[$i][1];
        $xj = $polygon[$j][0]; $yj = $polygon[$j][1];

        $intersect = (($yi > $y) != ($yj > $y))
            && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 0.00000001) + $xi);
        if ($intersect) $inside = !$inside;
    }
    return $inside;
}

function resolveGeoJsonBarangay($lat, $lng) {
    static $geoData = null;
    if ($geoData === null) {
        $filePath = __DIR__ . '/dasma_boundaries.json';
        if (file_exists($filePath)) {
            $geoData = json_decode(file_get_contents($filePath), true);
        }
    }

    if (!$geoData || !isset($geoData['features'])) {
        return null;
    }

    $point = [(float)$lng, (float)$lat];

    foreach ($geoData['features'] as $feature) {
        $brgyName = $feature['properties']['name'] ?? $feature['properties']['ADM4_EN'] ?? '';
        $geomType = $feature['geometry']['type'];
        $coordinates = $feature['geometry']['coordinates'];

        if ($geomType === 'Polygon') {
            if (isPointInPolygon($point, $coordinates[0])) {
                return $brgyName;
            }
        } elseif ($geomType === 'MultiPolygon') {
            foreach ($coordinates as $polyRing) {
                if (isPointInPolygon($point, $polyRing[0])) {
                    return $brgyName;
                }
            }
        }
    }
    return null;
}

// 1. Collect Data 
$raw_id        = $_POST['reported_by'] ?? $_POST['user_id'] ?? null;
$user_id_int   = (is_numeric($raw_id)) ? (int)$raw_id : null; 
$incident_type = $_POST['incident_type'] ?? 'General Emergency'; 

$description   = isset($_POST['description']) ? trim($_POST['description']) : ''; 
$latitude      = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$longitude     = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$raw_barangay  = $_POST['barangay'] ?? 'Unknown Location';

// Strict Dasmariñas boundary perimeter
$min_lat = 14.2750;
$max_lat = 14.3750;
$min_lng = 120.9100;
$max_lng = 121.0100;

// Geofence check
$outside_cities = ['general trias', 'gen. trias', 'gentri', 'imus', 'silang', 'tanza', 'bacoor', 'carmona', 'gma', 'trece martires'];
$contains_outside_city = false;

foreach ($outside_cities as $city) {
    if (stripos($raw_barangay, $city) !== false) {
        $contains_outside_city = true;
        break;
    }
}

if ($latitude < $min_lat || $latitude > $max_lat || $longitude < $min_lng || $longitude > $max_lng || $contains_outside_city) {
    echo json_encode([
        "success" => false,
        "message" => "Reporting is restricted to the City of Dasmariñas jurisdiction only."
    ]);
    exit();
}

// --- ACCURATE BARANGAY RESOLUTION ---
$resolved_barangay = resolveGeoJsonBarangay($latitude, $longitude);

if (empty($resolved_barangay)) {
    // Exact corridor resolution for Aguinaldo Highway / Congressional Junction / Volet's / NCST
    if ($latitude >= 14.3180 && $latitude <= 14.3275 && $longitude >= 120.9380 && $longitude <= 120.9490) {
        if ($latitude <= 14.3248) {
            $resolved_barangay = 'Zone IV';
        } elseif ($longitude <= 120.9415) {
            $resolved_barangay = 'Zone I-A (Poblacion)';
        } else {
            $resolved_barangay = 'Zone I (Poblacion)';
        }
    } else {
        // Strip string anomalies
        $clean_input = trim(str_ireplace([', Dasmariñas', ', Cavite', 'Philippines', 'City of Dasmariñas', 'City'], '', $raw_barangay));
        
        $aliases = [
            'manuelaville'   => 'San Agustin II',
            'the courtyards' => 'Salawag',
            'orchard'        => 'Salawag',
            'summerwind'     => 'Burol Main',
            'waltermart'     => 'San Agustin II',
            'sm dasma'       => 'Sampaloc I',
            'robinsons'      => 'Sampaloc I',
            'dlsud'          => 'Zone IV',
            'dlshsi'         => 'Zone IV',
            'volets'         => 'Zone IV',
            'ncst'           => 'Zone IV',
            'poblacion'      => ($latitude <= 14.3248) ? 'Zone IV' : 'Zone I (Poblacion)'
        ];

        foreach ($aliases as $alias => $official_name) {
            if (stripos($clean_input, $alias) !== false) {
                $resolved_barangay = $official_name;
                break;
            }
        }

        // Validate against official database records
        if (empty($resolved_barangay)) {
            $b_stmt = $conn->prepare("SELECT name FROM barangays WHERE status = 'active' AND (LOWER(name) = LOWER(?) OR LOWER(name) LIKE LOWER(?)) LIMIT 1");
            $like_param = "%" . $clean_input . "%";
            $b_stmt->bind_param("ss", $clean_input, $like_param);
            $b_stmt->execute();
            $b_res = $b_stmt->get_result();
            if ($row = $b_res->fetch_assoc()) {
                $resolved_barangay = $row['name'];
            }
            $b_stmt->close();
        }

        if (empty($resolved_barangay)) {
            $resolved_barangay = !empty($clean_input) ? $clean_input : 'Zone IV';
        }
    }
}

$barangay = $resolved_barangay;

$status        = 'active';
$admin_remarks = null;
$is_verified   = 0;    
$image_path    = null;

$severity_payload   = $_POST['severity'] ?? 'Minor';
$allowed_severities = ['Critical', 'Major', 'Minor'];
$severity = in_array($severity_payload, $allowed_severities) ? $severity_payload : 'Minor';

if (!$user_id_int) {
    echo json_encode(["success" => false, "message" => "Critical Error: No User ID provided."]);
    exit();
}

// 2. Handle Image Upload
if (isset($_FILES['evidence_photo']) && $_FILES['evidence_photo']['error'] === UPLOAD_ERR_OK) {
    $target_dir = __DIR__ . "/uploads/evidence/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES['evidence_photo']['name'], PATHINFO_EXTENSION));
    if ($file_extension === 'jfif') {
        $file_extension = 'jpg';
    }

    $new_filename = 'inc_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
    $target_file  = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES['evidence_photo']['tmp_name'], $target_file)) {
        $image_path = 'uploads/evidence/' . $new_filename;
    }
}

// 3. Database Execution
/** @var mysqli $conn */
$conn->begin_transaction(); 

try {
    $sql_inc = "INSERT INTO incidents (client_id, barangay, incident_type, severity, latitude, longitude, status, reported_by, is_verified, image_path, admin_remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_inc = $conn->prepare($sql_inc);
    $stmt_inc->bind_param("isssddsiiss", $user_id_int, $barangay, $incident_type, $severity, $latitude, $longitude, $status, $user_id_int, $is_verified, $image_path, $admin_remarks);
    $stmt_inc->execute();
    
    $new_incident_id = $conn->insert_id;
    $stmt_inc->close();

    if (!empty($description)) {
        $sql_log = "INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)";
        $stmt_log = $conn->prepare($sql_log);
        $formatted_log = "REPORTER LOG: " . $description;
        $stmt_log->bind_param("iis", $new_incident_id, $user_id_int, $formatted_log);
        $stmt_log->execute();
        $stmt_log->close();
    }

    $conn->commit(); 
    echo json_encode(["success" => true, "message" => "SOS Transmitted successfully!", "resolved_barangay" => $barangay]);

} catch (Exception $e) {
    $conn->rollback(); 
    echo json_encode(["success" => false, "message" => "System Error: " . $e->getMessage()]);
}

$conn->close();
?>