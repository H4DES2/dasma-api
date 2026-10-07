<?php
// Function to send FCM alert to specific users or all responders
function sendPushNotification($server_jwt_access_token, $project_id, $device_token, $title, $body, $data = []) {
    $url = "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send";

    $payload = [
        "message" => [
            "token" => $device_token,
            "notification" => [
                "title" => $title,
                "body"  => $body
            ],
            "data" => array_map('strval', $data),
            "android" => [
                "priority" => "HIGH",
                "notification" => [
                    "channel_id" => "emergency_alerts",
                    "sound" => "default"
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $server_jwt_access_token,
        "Content-Type: application/json; UTF-8"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}