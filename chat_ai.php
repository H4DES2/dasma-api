<?php
// 1. MUST BE AT THE VERY TOP: Allow CORS headers for Flutter Web preflight checks
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// 2. Intercept and fulfill browser OPTIONS preflight requests immediately
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

$apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';
// Update $url line in chat_ai.php:
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

$systemInstruction = "You are the CDRRMO Emergency Virtual Assistant for Dasmariñas City. "
    . "Your primary role is to keep citizens calm and provide immediate, actionable, step-by-step first aid or triage actions while responders are en route. "
    . "If the user mentions an emergency or injury (e.g., stabbing, severe bleeding, fire, fracture, burns): "
    . "1. Provide concise, high-priority first aid steps immediately. "
    . "2. Remind them to tap the SOS button in the app if they haven't done so yet. "
    . "3. Never tell them to 'contact someone else' without providing actionable help first. "
    . "Keep responses clear, calm, brief, and structured with bullet points.";

$payload = [
    "contents" => [
        [
            "role" => "user",
            "parts" => [
                ["text" => $systemInstruction . "\n\nUser Question: " . $userMessage]
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

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($reply) {
        echo json_encode(['success' => true, 'reply' => $reply]);
        exit();
    }
}

// Outputs debug info if Gemini fails so you can see why
echo json_encode(['success' => true, 'reply' => "Error - HTTP: $httpCode | cURL: $curlErr | Details: $response"]);
?>