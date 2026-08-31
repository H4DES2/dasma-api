<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once 'config.php';

// 1. Collect Data 
$raw_id        = $_POST['reported_by'] ?? $_POST['user_id'] ?? null;
$user_id_int   = (is_numeric($raw_id)) ? (int)$raw_id : null; 
$incident_type = $_POST['incident_type'] ?? 'General Emergency'; 

$description   = isset($_POST['description']) ? trim($_POST['description']) : ''; 
$latitude      = isset($_POST['latitude']) ? (float)$_POST['latitude'] : 0.0;
$longitude     = isset($_POST['longitude']) ? (float)$_POST['longitude'] : 0.0;
$barangay      = $_POST['barangay'] ?? 'Unknown Location';

// 🚀 PRECISE DASMARIÑAS BOUNDARIES (Excludes GenTri, Imus, Silang, Bacoor)
$min_lat = 14.2700;
$max_lat = 14.3680;
$min_lng = 120.9150; // Navarro, GenTri is at 120.8942 -> fully blocked!
$max_lng = 121.0150; // Paliparan / Salawag East -> fully included!

// 🚀 HYBRID CHECK: Coordinate Geofence + City Name Blacklist
$outside_cities = ['general trias', 'gen. trias', 'gentri', 'imus', 'silang', 'tanza', 'bacoor', 'carmona', 'gma', 'trece martires'];
$contains_outside_city = false;

foreach ($outside_cities as $city) {
    if (stripos($barangay, $city) !== false) {
        $contains_outside_city = true;
        break;
    }
}

$is_out_of_bounds = ($latitude < $min_lat || $latitude > $max_lat || $longitude < $min_lng || $longitude > $max_lng || $contains_outside_city);

// Status routing
$status        = $is_out_of_bounds ? 'rejected' : 'active';
$admin_remarks = $is_out_of_bounds ? 'Out of Jurisdiction (Auto-Rejected)' : null;
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
    $target_dir = $_SERVER['DOCUMENT_ROOT'] . "/dasma_api/uploads/evidence/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $file_extension = pathinfo($_FILES['evidence_photo']['name'], PATHINFO_EXTENSION);
    $new_filename = 'inc_' . time() . '_' . rand(1000, 9999) . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($_FILES['evidence_photo']['tmp_name'], $target_file)) {
        $image_path = 'uploads/evidence/' . $new_filename;
    }
}

// 3. Database Execution
/** @var mysqli $conn */
$conn->begin_transaction(); 

try {
    // A. Insert into incidents table (sets status to 'rejected' if outside Dasmariñas)
    $sql_inc = "INSERT INTO incidents (client_id, barangay, incident_type, severity, latitude, longitude, status, reported_by, is_verified, image_path, admin_remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_inc = $conn->prepare($sql_inc);
    $stmt_inc->bind_param("isssddsiiss", $user_id_int, $barangay, $incident_type, $severity, $latitude, $longitude, $status, $user_id_int, $is_verified, $image_path, $admin_remarks);
    $stmt_inc->execute();
    
    $new_incident_id = $conn->insert_id;
    $stmt_inc->close();

    // B. Insert into incident_logs if details were typed
    if (!empty($description)) {
        $sql_log = "INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)";
        $stmt_log = $conn->prepare($sql_log);
        $formatted_log = "REPORTER LOG: " . $description;
        $stmt_log->bind_param("iis", $new_incident_id, $user_id_int, $formatted_log);
        $stmt_log->execute();
        $stmt_log->close();
    }

    // C. Automatically route into spam_reports (Report Bin) if out of jurisdiction
    if ($is_out_of_bounds) {
        $spam_reason = "Out of Jurisdiction: Report originated from $barangay ($latitude, $longitude).";
        $sql_spam = "INSERT INTO spam_reports (incident_id, reason) VALUES (?, ?)";
        $stmt_spam = $conn->prepare($sql_spam);
        $stmt_spam->bind_param("is", $new_incident_id, $spam_reason);
        $stmt_spam->execute();
        $stmt_spam->close();
    }

    $conn->commit(); 
    echo json_encode(["success" => true, "message" => "SOS Transmitted successfully!"]);

} catch (Exception $e) {
    $conn->rollback(); 
    echo json_encode(["success" => false, "message" => "System Error: " . $e->getMessage()]);
}

$conn->close();
?>