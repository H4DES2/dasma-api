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

// Catch any PHP fatal errors and return them as JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? $_POST;
$userMessage = trim($input['message'] ?? '');
$userId      = trim($input['user_id'] ?? '');
$userLat     = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : 14.3294;
$userLng     = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : 120.9368;

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'reply' => 'Please enter a message.']);
    exit();
}

// 1. Fetch User Incident History
$historyContext = "User has no recorded incident reports.";
if (!empty($userId)) {
    try {
        $stmt = $conn->prepare("
            SELECT id, incident_type, status, barangay, created_at, 
                   COALESCE(admin_remarks, 'No remarks logged.') as remarks
            FROM incidents 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT 3
        ");
        if ($stmt) {
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $hRes = $stmt->get_result();
            $rows = $hRes ? $hRes->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            if (!empty($rows)) {
                $historyContext = "User's Recent Emergency Reports:\n";
                foreach ($rows as $r) {
                    $historyContext .= "- Report #{$r['id']} ({$r['incident_type']}) at Barangay {$r['barangay']}: Status is '{$r['status']}'. Reported: {$r['created_at']}. Remarks: {$r['remarks']}\n";
                }
            }
        }
    } catch (Exception $e) {
        $historyContext = "Incident database query error.";
    }
}

// 2. Fetch Evacuation Shelters Safely
$evacContext = "No active evacuation centers found.";
try {
    $eRes = $conn->query("
        SELECT name, barangay, capacity, COALESCE(current_occupants, 0) as current_occupants, latitude, longitude
        FROM evacuation_centers
        ORDER BY id ASC
        LIMIT 5
    ");

    if ($eRes && $eRes->num_rows > 0) {
        $evacList = [];
        while ($row = $eRes->fetch_assoc()) {
            $eLat = (float)($row['latitude'] ?? 0);
            $eLng = (float)($row['longitude'] ?? 0);
            
            // Calculate distance in PHP to avoid SQL math failures
            $dist = 0;
            if ($eLat != 0 && $eLng != 0) {
                $theta = $userLng - $eLng;
                $dist = sin(deg2rad($userLat)) * sin(deg2rad($eLat)) + cos(deg2rad($userLat)) * cos(deg2rad($eLat)) * cos(deg2rad($theta));
                $dist = acos(max(-1.0, min(1.0, $dist)));
                $dist = rad2deg($dist) * 60 * 1.1515 * 1.609344; // km
            }

            $vacant = max(0, (int)$row['capacity'] - (int)$row['current_occupants']);
            $row['calc_dist'] = round($dist, 2);
            $row['vacant'] = $vacant;
            $evacList[] = $row;
        }

        // Sort closest first
        usort($evacList, fn($a, $b) => $a['calc_dist'] <=> $b['calc_dist']);

        $evacContext = "Open Evacuation Centers in Dasmariñas:\n";
        foreach (array_slice($evacList, 0, 3) as $e) {
            $evacContext .= "- {$e['name']} ({$e['barangay']}): ~{$e['calc_dist']} km away. Available Space: {$e['vacant']} / {$e['capacity']}.\n";
        }
    }
} catch (Exception $e) {
    $evacContext = "Evacuation database currently unreachable.";
}

// 3. Fallback Response if API Key Missing
$apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

if (empty($apiKey)) {
    echo json_encode([
        'success' => true,
        'reply' => "Here is the latest live information from our command center:\n\n" . $evacContext . "\n" . $historyContext
    ]);
    exit();
}

// 4. Query Gemini API
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;

$systemInstruction = "You are the CDRRMO Emergency Virtual Assistant for Dasmariñas City. "
    . "You have live access to the citizen's real-time incident reports and evacuation shelter database.\n\n"
    . "LIVE SYSTEM CONTEXT:\n"
    . $historyContext . "\n"
    . $evacContext . "\n\n"
    . "INSTRUCTIONS:\n"
    . "1. If the user asks about the status of their report/incident, answer using their actual report data above.\n"
    . "2. If the user asks for evacuation centers, name the closest ones from the list with distance and available space.\n"
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

// Fallback if AI call returns rate limit or error
echo json_encode([
    'success' => true,
    'reply' => $evacContext . "\n" . $historyContext
]);
?>