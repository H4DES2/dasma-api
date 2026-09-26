<?php
// Enable CORS for Vercel Web & Mobile clients
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle CORS Preflight check from browsers
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

// Auto-create API rate limiting table if missing
$conn->query("CREATE TABLE IF NOT EXISTS api_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(64) NOT NULL,
    identifier_type ENUM('ip', 'user') NOT NULL,
    endpoint VARCHAR(50) NOT NULL,
    request_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate_lookup (identifier, identifier_type, endpoint, request_time)
)");

// 1. Identify Client IP & Extract User ID
$ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '0.0.0.0';
$ip_address = trim(explode(',', $ip_address)[0]);

$raw_id      = $_POST['reported_by'] ?? $_POST['user_id'] ?? null;
$user_id_int = (is_numeric($raw_id)) ? (int)$raw_id : null;

if (!$user_id_int) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Critical Error: No User ID provided."]);
    exit();
}

// 2. Two-Tier Rate Limiting:
// Tier A: User-Level Limit (Max 2 incident reports per 60 seconds per account)
$endpoint_tag = 'submit_incident';
$user_str_id  = (string)$user_id_int;

$user_rate_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt 
    FROM api_rate_limits 
    WHERE identifier = ? 
      AND identifier_type = 'user' 
      AND endpoint = ? 
      AND request_time >= (NOW() - INTERVAL 1 MINUTE)
");
$user_rate_stmt->bind_param("ss", $user_str_id, $endpoint_tag);
$user_rate_stmt->execute();
$user_rate = $user_rate_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$user_rate_stmt->close();

if ($user_rate >= 2) {
    http_response_code(429);
    echo json_encode([
        "success" => false,
        "message" => "Report submission limit reached. Please wait 1 minute before submitting another report."
    ]);
    exit();
}

// Tier B: IP-Level Limit (Max 20 requests per 60 seconds to accommodate CGNAT mobile networks)
$ip_rate_stmt = $conn->prepare("
    SELECT COUNT(*) as cnt 
    FROM api_rate_limits 
    WHERE identifier = ? 
      AND identifier_type = 'ip' 
      AND endpoint = ? 
      AND request_time >= (NOW() - INTERVAL 1 MINUTE)
");
$ip_rate_stmt->bind_param("ss", $ip_address, $endpoint_tag);
$ip_rate_stmt->execute();
$ip_rate = $ip_rate_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$ip_rate_stmt->close();

if ($ip_rate >= 20) {
    http_response_code(429);
    echo json_encode([
        "success" => false,
        "message" => "Network traffic limit reached. Please wait a moment before trying again."
    ]);
    exit();
}

// 3. Collect Payload & Validate Sanity
$incident_type = $_POST['incident_type'] ?? 'General Emergency';
$description   = isset($_POST['description']) ? trim($_POST['description']) : '';
$latitude      = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$longitude     = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$raw_barangay  = $_POST['barangay'] ?? 'City of Dasmariñas';

$accuracy_raw     = $_POST['accuracy_meters'] ?? null;
$accuracy_meters  = is_numeric($accuracy_raw) ? round((float)$accuracy_raw, 2) : null;

$block         = isset($_POST['block']) && trim($_POST['block']) !== '' ? trim($_POST['block']) : null;
$lot           = isset($_POST['lot']) && trim($_POST['lot']) !== '' ? trim($_POST['lot']) : null;
$phase         = isset($_POST['phase']) && trim($_POST['phase']) !== '' ? trim($_POST['phase']) : null;
$subdivision   = isset($_POST['subdivision']) && trim($_POST['subdivision']) !== '' ? trim($_POST['subdivision']) : null;

// Coordinate sanity boundary for Dasmariñas City Jurisdiction
$min_lat = 14.2600;
$max_lat = 14.3750;
$min_lng = 120.9100;
$max_lng = 121.0100;

if ($latitude < $min_lat || $latitude > $max_lat || $longitude < $min_lng || $longitude > $max_lng) {
    echo json_encode([
        "success" => false,
        "message" => "Reporting is restricted to Dasmariñas City jurisdiction only."
    ]);
    exit();
}

// 4. Server-side Deduplication Guard
$stmt_dup = $conn->prepare("
    SELECT id FROM incidents 
    WHERE reported_by = ? 
      AND incident_type = ? 
      AND created_at >= (NOW() - INTERVAL 30 SECOND)
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

$barangay = trim($raw_barangay);
if ($barangay === '' || str_contains($barangay, 'Unknown') || str_contains($barangay, 'Outside')) {
    $barangay = 'City of Dasmariñas';
}
if (strcasecmp($barangay, 'Burol Main') === 0) {
    $barangay = 'Burol';
}

$severity_payload   = $_POST['severity'] ?? 'Minor';
$allowed_severities = ['Critical', 'Major', 'Minor'];
$severity = in_array($severity_payload, $allowed_severities) ? $severity_payload : 'Minor';

$status        = 'active';
$admin_remarks = null;
$is_verified   = 0;
$image_path    = null;

// 5. Cloudinary Evidence Upload
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

// 6. Database Insertion
$conn->begin_transaction();

try {
    $sql_inc = "INSERT INTO incidents 
                (reported_by, barangay, block, lot, phase, subdivision, incident_type, severity, latitude, longitude, accuracy_meters, status, is_verified, image_path, admin_remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_inc = $conn->prepare($sql_inc);
    $stmt_inc->bind_param(
        "isssssssddssiss",
        $user_id_int,
        $barangay,
        $block,
        $lot,
        $phase,
        $subdivision,
        $incident_type,
        $severity,
        $latitude,
        $longitude,
        $accuracy_meters,
        $status,
        $is_verified,
        $image_path,
        $admin_remarks
    );
    $stmt_inc->execute();
    
    $new_incident_id = $conn->insert_id;
    $stmt_inc->close();

    // Compile log entry
    $address_details = [];
    if ($subdivision) $address_details[] = "Subd: $subdivision";
    if ($phase)       $address_details[] = "Phase: $phase";
    if ($block)       $address_details[] = "Blk: $block";
    if ($lot)         $address_details[] = "Lot: $lot";

    $full_log = [];
    if (!empty($address_details)) {
        $full_log[] = "[" . implode(", ", $address_details) . "]";
    }
    if (!empty($description)) {
        $full_log[] = $description;
    }

    if (!empty($full_log)) {
        $sql_log = "INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)";
        $stmt_log = $conn->prepare($sql_log);
        $formatted_log = "REPORTER LOG: " . implode(" - ", $full_log);
        $stmt_log->bind_param("iis", $new_incident_id, $user_id_int, $formatted_log);
        $stmt_log->execute();
        $stmt_log->close();
    }

    // Record valid submission for both User and IP tiers
    $stmt_track = $conn->prepare("INSERT INTO api_rate_limits (identifier, identifier_type, endpoint) VALUES (?, 'user', ?), (?, 'ip', ?)");
    if ($stmt_track) {
        $stmt_track->bind_param("ssss", $user_str_id, $endpoint_tag, $ip_address, $endpoint_tag);
        $stmt_track->execute();
        $stmt_track->close();
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