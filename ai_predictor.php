<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';

// Target month to predict (defaults to current month if not specified)
$target_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$target_brgy  = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';

// 1. Fetch top aggregated incident trends from the database
$whereClauses = [
    "barangay IS NOT NULL",
    "barangay != ''",
    "incident_type IS NOT NULL"
];
$params = [];
$types  = "";

if ($target_month > 0 && $target_month <= 12) {
    $whereClauses[] = "MONTH(created_at) = ?";
    $params[] = $target_month;
    $types   .= "i";
}

if (!empty($target_brgy)) {
    $whereClauses[] = "LOWER(TRIM(barangay)) = LOWER(?)";
    $params[] = $target_brgy;
    $types   .= "s";
}

$whereSql = implode(" AND ", $whereClauses);

$query = "
    SELECT 
        TRIM(barangay) AS barangay,
        TRIM(incident_type) AS incident_type,
        MONTH(created_at) AS month,
        COUNT(*) AS occurrences,
        SUM(CASE WHEN LOWER(severity) = 'critical' THEN 1 ELSE 0 END) AS critical_count,
        ROUND(AVG(latitude), 6) AS lat,
        ROUND(AVG(longitude), 6) AS lng
    FROM incidents
    WHERE {$whereSql}
    GROUP BY TRIM(barangay), TRIM(incident_type), MONTH(created_at)
    ORDER BY occurrences DESC, critical_count DESC
    LIMIT 60
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$dbResult = $stmt->get_result();

$dataset = [];
while ($row = $dbResult->fetch_assoc()) {
    $dataset[] = [
        'barangay'       => $row['barangay'],
        'incident'       => $row['incident_type'],
        'month'          => (int)$row['month'],
        'occurrences'    => (int)$row['occurrences'],
        'critical_cases' => (int)$row['critical_count'],
        'lat'            => (float)$row['lat'],
        'lng'            => (float)$row['lng']
    ];
}
$stmt->close();

if (empty($dataset)) {
    echo json_encode([
        'success' => false,
        'message' => 'No historical records found for the selected month/filter.'
    ]);
    exit();
}

// 2. Prepare Gemini Prompt
$geminiApiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');

if (empty($geminiApiKey)) {
    echo json_encode([
        'success' => false,
        'message' => 'GEMINI_API_KEY environment variable is not configured on the server.'
    ]);
    exit();
}

$monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$monthLabel = $monthNames[$target_month] ?? "Month {$target_month}";

$systemInstruction = "You are DasmaAlert's Senior Disaster Risk Reduction & Predictive AI for Dasmariñas City, Cavite. "
    . "Analyze the aggregated historical incident dataset provided. "
    . "Identify spatial clusters, recurrent incident patterns (e.g., flooding in rainy months, heat stroke, brush fires, power line hazards, road accidents), "
    . "and generate actionable hazard forecasts for administrators. "
    . "Output ONLY strict JSON conforming to this schema:\n"
    . "{\n"
    . '  "summary": "Brief executive assessment for ' . $monthLabel . ' in Dasmariñas",' . "\n"
    . '  "forecasts": [' . "\n"
    . '    {' . "\n"
    . '      "barangay": "Barangay Name",' . "\n"
    . '      "hazard_type": "Primary anticipated incident",' . "\n"
    . '      "risk_level": "CRITICAL" | "HIGH" | "MODERATE",' . "\n"
    . '      "pattern_confidence": "e.g., 85% - Recurrent Pattern",' . "\n"
    . '      "historical_basis": "Specific reason citing historical counts/patterns from the data",' . "\n"
    . '      "recommended_action": "Clear action for CDRRMO/Barangay responders (equipment pre-staging, advisories, patrols)",' . "\n"
    . '      "lat": float,' . "\n"
    . '      "lng": float' . "\n"
    . '    }' . "\n"
    . '  ]' . "\n"
    . "}";

$promptPayload = [
    "systemInstruction" => [
        "parts" => [["text" => $systemInstruction]]
    ],
    "contents" => [
        [
            "parts" => [
                [
                    "text" => "Target Prediction Month: {$monthLabel} (Month {$target_month})\n\nHistorical Incident Clusters:\n" . json_encode($dataset)
                ]
            ]
        ]
    ],
    "generationConfig" => [
        "response_mime_type" => "application/json",
        "temperature"        => 0.2
    ]
];

// Call Google Gemini API
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $geminiApiKey;

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($promptPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$apiResponse = curl_exec($ch);
$httpStatus  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpStatus !== 200) {
    echo json_encode([
        'success'   => false,
        'http_code' => $httpStatus,
        'error'     => 'Gemini API call failed',
        'raw'       => $apiResponse
    ]);
    exit();
}

$decodedResult = json_decode($apiResponse, true);
$rawTextJson = $decodedResult['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$parsedPrediction = json_decode($rawTextJson, true);

echo json_encode([
    'success'    => true,
    'month'      => $monthLabel,
    'prediction' => $parsedPrediction
], JSON_PRETTY_PRINT);