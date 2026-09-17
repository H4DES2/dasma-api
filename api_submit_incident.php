<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

// Database Spatial Resolver
function resolveBarangaySector(mysqli $conn, float $lat, float $lng, string $fallback = 'Zone IV'): string {
    if (strcasecmp(trim($fallback), 'Burol Main') === 0) {
        $fallback = 'Burol';
    }

    if ($lat == 0.0 || $lng == 0.0) return $fallback;

    $sql = "
        SELECT name 
        FROM barangays 
        WHERE boundary IS NOT NULL 
        ORDER BY ST_Distance(ST_SRID(boundary, 0), POINT(?, ?)) ASC 
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("dd", $lng, $lat);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            $sector = trim($row['name']);
            return (strcasecmp($sector, 'Burol Main') === 0) ? 'Burol' : $sector;
        }
        $stmt->close();
    }
    return $fallback;
}

// 1. Collect and Validate Payload
$raw_id        = $_POST['reported_by'] ?? $_POST['user_id'] ?? null;
$user_id_int   = (is_numeric($raw_id)) ? (int)$raw_id : null; 
$incident_type = $_POST['incident_type'] ?? 'General Emergency'; 
$description   = isset($_POST['description']) ? trim($_POST['description']) : ''; 
$latitude      = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$longitude     = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$raw_barangay  = $_POST['barangay'] ?? 'Unknown Location';

if (!$user_id_int) {
    echo json_encode(["success" => false, "message" => "Critical Error: No User ID provided."]);
    exit();
}

// Strict Dasmariñas boundary perimeter
$min_lat = 14.2750;
$max_lat = 14.3750;
$min_lng = 120.9100;
$max_lng = 121.0100;

$outside_cities = ['general trias', 'gen. trias', 'gentri', 'imus', 'silang', 'tanza', 'bacoor', 'carmona', 'gma', 'trece martires'];
foreach ($outside_cities as $city) {
    if (stripos($raw_barangay, $city) !== false) {
        echo json_encode([
            "success" => false,
            "message" => "Reporting is restricted to the City of Dasmariñas jurisdiction only."
        ]);
        exit();
    }
}

if ($latitude < $min_lat || $latitude > $max_lat || $longitude < $min_lng || $longitude > $max_lng) {
    echo json_encode([
        "success" => false,
        "message" => "Reporting is restricted to the City of Dasmariñas jurisdiction only."
    ]);
    exit();
}

// 2. Server-side Deduplication Guard (Prevents multi-click spam within 45s)
$stmt_dup = $conn->prepare("
    SELECT id FROM incidents 
    WHERE reported_by = ? 
      AND incident_type = ? 
      AND created_at >= (NOW() - INTERVAL 45 SECOND)
    LIMIT 1
");
$stmt_dup->bind_param("is", $user_id_int, $incident_type);
$stmt_dup->execute();
$dup_res = $stmt_dup->get_result();

if ($dup_row = $dup_res->fetch_assoc()) {
    $existing_id = $dup_row['id'];
    $stmt_dup->close();
    echo json_encode([
        "success" => true,
        "message" => "SOS already received. Units are on alert.",
        "incident_id" => $existing_id,
        "resolved_barangay" => $raw_barangay
    ]);
    $conn->close();
    exit();
}
$stmt_dup->close();

// Resolve Sector
$barangay = resolveBarangaySector($conn, $latitude, $longitude, $raw_barangay);

$severity_payload   = $_POST['severity'] ?? 'Minor';
$allowed_severities = ['Critical', 'Major', 'Minor'];
$severity = in_array($severity_payload, $allowed_severities) ? $severity_payload : 'Minor';

$status        = 'active';
$admin_remarks = null;
$is_verified   = 0;    
$image_path    = null;

// 3. Handle Cloudinary Upload
if (isset($_FILES['evidence_photo']) && $_FILES['evidence_photo']['error'] === UPLOAD_ERR_OK) {
    $cloud_name = getenv('CLOUDINARY_CLOUD_NAME') ?: ($_ENV['CLOUDINARY_CLOUD_NAME'] ?? 'wyxsiraw');
    $api_key    = getenv('CLOUDINARY_API_KEY') ?: ($_ENV['CLOUDINARY_API_KEY'] ?? null);
    $api_secret = getenv('CLOUDINARY_API_SECRET') ?: ($_ENV['CLOUDINARY_API_SECRET'] ?? null);

    if ($cloud_name === 'dasma-api') {
        $cloud_name = 'wyxsiraw';
    }

    if ($cloud_name && $api_key && $api_secret) {
        $file_tmp   = $_FILES['evidence_photo']['tmp_name'];
        $file_mime  = mime_content_type($file_tmp);
        $file_name  = $_FILES['evidence_photo']['name'];
        $timestamp  = time();

        $params_to_sign = [
            'folder'    => 'dasma_evidence',
            'timestamp' => $timestamp
        ];
        ksort($params_to_sign);

        $sig_parts = [];
        foreach ($params_to_sign as $k => $v) {
            $sig_parts[] = "{$k}={$v}";
        }
        $sig_string = implode('&', $sig_parts) . $api_secret;
        $signature  = sha1($sig_string);

        $cfile = new CURLFile($file_tmp, $file_mime, $file_name);
        $post_fields = [
            'file'      => $cfile,
            'api_key'   => $api_key,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => 'dasma_evidence'
        ];

        $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloud_name}/image/upload");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json_res = json_decode($response, true);
        if ($http_code === 200 && !empty($json_res['secure_url'])) {
            $image_path = $json_res['secure_url'];
        }
    }
}

// 4. Database Transaction
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
    echo json_encode([
        "success" => true,
        "message" => "SOS Transmitted successfully!",
        "incident_id" => $new_incident_id,
        "resolved_barangay" => $barangay
    ]);

} catch (Exception $e) {
    $conn->rollback(); 
    echo json_encode(["success" => false, "message" => "System Error: " . $e->getMessage()]);
}

$conn->close();
?>