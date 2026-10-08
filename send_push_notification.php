<?php

function getFirebaseAccessToken(): string {
    $sa = null;

    // 1. Check environment variable first (Render / Production)
    $envCredentials = getenv('FIREBASE_CREDENTIALS') ?: ($_ENV['FIREBASE_CREDENTIALS'] ?? null);
    if (!empty($envCredentials)) {
        $sa = json_decode($envCredentials, true);
    }

    // 2. Fall back to local file (Local Development)
    if (!$sa) {
        $serviceAccountPath = __DIR__ . '/service-account.json';
        if (file_exists($serviceAccountPath)) {
            $sa = json_decode(file_get_contents($serviceAccountPath), true);
        }
    }

    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
        error_log('[FCM OAuth] Missing service account credentials');
        return '';
    }

    // Fix escaped newlines in environment variables
    $privateKey = str_replace('\n', "\n", $sa['private_key']);

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600
    ];

    $b64 = function($data) {
        return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    };

    $unsignedJwt = $b64($header) . '.' . $b64($payload);
    $signature = '';
    
    if (!openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        error_log('[FCM OAuth] openssl_sign failed: ' . openssl_error_string());
        return '';
    }

    $signedJwt = $unsignedJwt . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $signedJwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['access_token'] ?? '';
}

function sendPushNotification(string $accessToken, string $projectId, string $deviceToken, string $title, string $body, array $extraData = []): bool {
    if (empty($accessToken) || empty($deviceToken)) {
        error_log('[FCM Dispatch] Missing access token or target device token');
        return false;
    }

    // Ensure all extra data values are strings for FCM specs
    $stringData = [];
    foreach ($extraData as $k => $v) {
        $stringData[(string)$k] = is_string($v) ? $v : json_encode($v);
    }

    $payload = [
    'message' => [
        'token' => $deviceToken,
        'notification' => [
            'title' => $title,
            'body'  => $body,
        ],
        'android' => [
            'priority' => 'HIGH',
            'notification' => [
                'channel_id'            => 'emergency_alerts',
                'sound'                 => 'default',
                'notification_priority' => 'PRIORITY_MAX',
            ],
        ],
        'data' => $stringData,
    ],
];

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json; UTF-8',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[FCM Error $httpCode] " . $response);
        return false;
    }

    return true;
}