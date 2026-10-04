<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Content-Type: application/json; charset=UTF-8");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Any fatal error -> JSON instead of a blank 500
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log("chat_ai.php fatal: {$e['message']} in {$e['file']}:{$e['line']}");
        if (!headers_sent()) http_response_code(200);
        echo json_encode(['success' => false, 'reply' => 'The assistant hit a server error. Please try again.']);
    }
});

require_once 'config.php';

$rawInput    = file_get_contents('php://input');
$input       = json_decode($rawInput, true) ?? $_POST;
$userMessage = trim($input['message'] ?? '');
$userId      = trim((string)($input['user_id'] ?? ''));
$userLat     = isset($input['latitude'])  && is_numeric($input['latitude'])  ? (float)$input['latitude']  : 14.3294;
$userLng     = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : 120.9368;

if ($userMessage === '') {
    echo json_encode(['success' => false, 'reply' => 'Please enter a message.']);
    exit();
}

// ---------- 1. User's incident history ----------
$historyContext = "The user has no recorded incident reports.";
if ($userId !== '') {
    try {
        $stmt = $conn->prepare("
            SELECT id, incident_type, status, barangay, created_at,
                   COALESCE(admin_remarks, 'No remarks logged.') AS remarks
            FROM incidents
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        if ($stmt) {
            $stmt->bind_param("s", $userId);
            $stmt->execute();
            $res  = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();

            if (!empty($rows)) {
                $historyContext = "User's recent emergency reports (newest first):\n";
                foreach ($rows as $r) {
                    $historyContext .= "- Report #{$r['id']} ({$r['incident_type']}) in Barangay {$r['barangay']}: "
                        . "status '{$r['status']}', reported {$r['created_at']}. Admin remarks: {$r['remarks']}\n";
                }
            }
        }
    } catch (Throwable $e) {
        error_log("chat_ai history error: " . $e->getMessage());
        $historyContext = "Incident history is temporarily unavailable.";
    }
}

// ---------- 2. Evacuation centers (all, then sort by distance) ----------
$evacContext = "No evacuation centers found.";
$topEvac = [];
try {
    $eRes = $conn->query("
        SELECT name, barangay, capacity, COALESCE(current_occupants, 0) AS current_occupants, latitude, longitude
        FROM evacuation_centers
    ");
    if ($eRes && $eRes->num_rows > 0) {
        $list = [];
        while ($row = $eRes->fetch_assoc()) {
            $eLat = (float)($row['latitude'] ?? 0);
            $eLng = (float)($row['longitude'] ?? 0);
            $dist = null;
            if ($eLat != 0 && $eLng != 0) {
                $theta = $userLng - $eLng;
                $d = sin(deg2rad($userLat)) * sin(deg2rad($eLat))
                   + cos(deg2rad($userLat)) * cos(deg2rad($eLat)) * cos(deg2rad($theta));
                $d = acos(max(-1.0, min(1.0, $d)));
                $dist = rad2deg($d) * 60 * 1.1515 * 1.609344; // km
            }
            $row['dist']   = $dist;
            $row['vacant'] = max(0, (int)$row['capacity'] - (int)$row['current_occupants']);
            $list[] = $row;
        }
        // Centers with unknown coordinates go last
        usort($list, function ($a, $b) {
            if ($a['dist'] === null) return 1;
            if ($b['dist'] === null) return -1;
            return $a['dist'] <=> $b['dist'];
        });
        $topEvac = array_slice($list, 0, 3);

        $evacContext = "Nearest evacuation centers to the user (closest first):\n";
        foreach ($topEvac as $e) {
            $km = $e['dist'] === null ? 'distance unknown' : '~' . round($e['dist'], 2) . ' km away';
            $evacContext .= "- {$e['name']} ({$e['barangay']}): {$km}. Space available: {$e['vacant']} of {$e['capacity']}.\n";
        }
    }
} catch (Throwable $e) {
    error_log("chat_ai evac error: " . $e->getMessage());
    $evacContext = "Evacuation center data is temporarily unavailable.";
}

// Plain-text answer used if Gemini is unavailable
$fallback = $evacContext . "\n" . $historyContext;

// ---------- 3. Gemini ----------
$apiKey = $_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '';
if ($apiKey === '') {
    error_log("chat_ai: GEMINI_API_KEY is not set");
    echo json_encode(['success' => true, 'reply' => $fallback]);
    exit();
}

$model = getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash';
$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

$systemInstruction =
    "You are the CDRRMO Emergency Virtual Assistant for Dasmariñas City. You have two main duties: providing direct emergency/first-aid guidance, and checking live app data.\n\n"
  . "LIVE DATA:\n{$historyContext}\n{$evacContext}\n\n"
  . "RULES:\n"
  . "1. FIRST AID & EMERGENCIES: Always provide immediate, actionable, step-by-step first aid or survival instructions. Do not refuse to answer. After providing the steps, briefly remind them to tap the SOS button or contact medical professionals.\n"
  . "2. STATUS QUESTIONS: Use ONLY the live data above for report statuses. Never invent reports.\n"
  . "3. EVACUATION: Name the closest center(s) using the live data, including distance and available space.\n"
  . "4. HOW TO REPORT: Dashboard -> Report Emergency -> Snap photo -> Adjust pin -> Select type -> Add details -> Transmit SOS.\n"
  . "5. FORMAT: Use bullet points and keep it concise (under 120 words).\n"
  . "6. LANGUAGE: You MUST match the user's language. If the question is in Tagalog/Filipino, answer in Tagalog/Filipino. If in English, answer in English.";

$payload = [
    "system_instruction" => ["parts" => [["text" => $systemInstruction]]],
    "contents" => [["role" => "user", "parts" => [["text" => $userMessage]]]],
    "generationConfig" => ["temperature" => 0.3, "maxOutputTokens" => 400],
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 12,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data  = json_decode($response, true);
    $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($reply) {
        echo json_encode(['success' => true, 'reply' => trim($reply)]);
        exit();
    }
}

// Log WHY Gemini failed (check Render logs) instead of failing silently
error_log("chat_ai Gemini failed: http={$httpCode} curl='{$curlErr}' body=" . substr((string)$response, 0, 300));
echo json_encode(['success' => true, 'reply' => $fallback]);