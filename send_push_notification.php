<?php

function getFirebaseAccessToken(): string {
    $serviceAccountPath = __DIR__ . '/service-account.json';
    if (!file_exists($serviceAccountPath)) {
        return '';
    }

    $sa = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
        return '';
    }

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
    openssl_sign($unsignedJwt, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
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