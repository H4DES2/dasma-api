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

if (empty($userMessage)) {
    echo json_encode(['success' => false, 'reply' => 'Please enter a message.']);
    exit();
}

// 1. Evacuation Centers Intent
if (preg_match('/(evac|evacuation|shelter|safe place)/i', $userMessage)) {
    echo json_encode([
        'success' => true,
        'reply' => "To find and navigate to the nearest evacuation center:\n" .
                   "1. Tap the 'SOS / Evacuation' icon on the bottom navigation bar.\n" .
                   "2. The app will locate open city evacuation facilities and show their current capacity.\n" .
                   "3. Tap on any shelter to see the fastest driving/walking route and turnaround distance from your current location."
    ]);
    exit();
}

// 2. Incident Reporting Intent
if (preg_match('/(how to report|report|steps|procedure|process)/i', $userMessage)) {
    echo json_encode([
        'success' => true,
        'reply' => "To report an emergency:\n" .
                   "1. Tap 'REPORT EMERGENCY' on your Home Dashboard.\n" .
                   "2. Take photo evidence of the scene.\n" .
                   "3. Adjust your location pin within the 100m radar boundary if needed.\n" .
                   "4. Select the emergency category and specific type.\n" .
                   "5. Add landmark/street details and a brief description.\n" .
                   "6. Tap 'TRANSMIT SOS'.\n" .
                   "7. Track responder status (En Route, On Scene, Resolved) in the 'History' tab."
    ]);
    exit();
}

// 3. Fallback to Gemini API
$apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

if (empty($apiKey)) {
    echo json_encode([
        'success' => true,
        'reply' => "I can guide you on app features (Reporting, Evacuation Centers, Weather, History) or provide first-aid guidance. If you are experiencing an immediate life-threatening emergency, tap the red REPORT EMERGENCY button now."
    ]);
    exit();
}

// Valid API endpoint
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;

$systemInstruction = "You are the CDRRMO Emergency Virtual Assistant for Dasmariñas City. "
    . "Provide clear, concise, actionable advice for app usage or emergency first aid. "
    . "Always remind citizens to tap 'REPORT EMERGENCY' in the app if they need immediate responder deployment.";

$payload = [
    "contents" => [
        [
            "role" => "user",
            "parts" => [
                ["text" => $systemInstruction . "\n\nCitizen Question: " . $userMessage]
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

// Safe fallback if external AI fails or times out
echo json_encode([
    'success' => true,
    'reply' => "For immediate assistance:\n- Evacuation: Tap the Evacuation tab on the bottom bar.\n- Reporting: Tap the red REPORT EMERGENCY button.\n- Timeline: Check the History tab."
]);
?>