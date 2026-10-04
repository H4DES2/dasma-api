<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'config.php';

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userMessage = trim($input['message'] ?? '');
$userId      = trim($input['user_id'] ?? '');
$userLat     = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : 14.3294;
$userLng     = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : 120.9368;

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'reply' => 'Please enter a message.']);
    exit();
}

$historyContext = "User has not submitted any reports yet.";
if (!empty($userId)) {
    $stmt = $conn->prepare("
        SELECT id, incident_type, status, barangay, created_at, 
               COALESCE(admin_remarks, 'No admin remarks') as remarks
        FROM incidents 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $hRes = $stmt->get_result();
    $rows = $hRes->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!empty($rows)) {
        $historyContext = "User's Recent Emergency Incident Reports:\n";
        foreach ($rows as $r) {
            $historyContext .= "- Report #{$r['id']} ({$r['incident_type']}) at {$r['barangay']}: Status is '{$r['status']}'. Reported: {$r['created_at']}. Remarks: {$r['remarks']}\n";
        }
    }
}

$evacContext = "No evacuation centers on record.";
$evacSql = "
    SELECT name, barangay, capacity, current_occupants, latitude, longitude,
           (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance_km
    FROM evacuation_centers
    WHERE (capacity - current_occupants) > 0
    ORDER BY distance_km ASC
    LIMIT 3
";
$stmtEvac = $conn->prepare($evacSql);
$stmtEvac->bind_param("ddd", $userLat, $userLng, $userLat);
$stmtEvac->execute();
$eRes = $stmtEvac->get_result();
$evacRows = $eRes->fetch_all(MYSQLI_ASSOC);
$stmtEvac->close();

if (!empty($evacRows)) {
    $evacContext = "Nearest Open Evacuation Centers to Citizen's current location:\n";
    foreach ($evacRows as $e) {
        $dist = round($e['distance_km'], 2);
        $vacant = $e['capacity'] - $e['current_occupants'];
        $evacContext .= "- {$e['name']} ({$e['barangay']}): {$dist} km away. Available capacity: {$vacant} / {$e['capacity']}.\n";
    }
}

$apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

if (empty($apiKey)) {
    echo json_encode([
        'success' => true,
        'reply' => "Here is your system information:\n\n" . $historyContext . "\n" . $evacContext
    ]);
    exit();
}

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;

$systemInstruction = "You are the CDRRMO Emergency Virtual Assistant for Dasmariñas City. "
    . "You have live access to the citizen's real-time incident reports and evacuation shelter database.\n\n"
    . "LIVE SYSTEM CONTEXT:\n"
    . $historyContext . "\n"
    . $evacContext . "\n\n"
    . "INSTRUCTIONS:\n"
    . "1. If the user asks about the status of their report/incident, answer using their actual report data above.\n"
    . "2. If the user asks for evacuation centers, name the closest ones from the list with distance and available capacity.\n"
    . "3. If they ask how to report, guide them: Dashboard -> Report Emergency -> Snap photo -> Adjust pin -> Select type -> Add details -> Transmit SOS.\n"
    . "4. Keep responses direct, helpful, and concise.";

$payload = [
    "contents" => [
        [
            "role" => "user",
            "parts" => [
                ["text" => $systemInstruction . "\n\nCitizen's Question: " . $userMessage]
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 12);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($reply) {
        echo json_encode(['success' => true, 'reply' => trim($reply)]);
        exit();
    }
}

echo json_encode([
    'success' => true,
    'reply' => $historyContext . "\n" . $evacContext
]);
?>