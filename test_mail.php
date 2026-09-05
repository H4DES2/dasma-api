<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiKey = getenv('RESEND_API_KEY') ?: ($_ENV['RESEND_API_KEY'] ?? '');

// Note: On Resend's free tier with default sandbox, send to the email you signed up with on Resend
$payload = [
    'from'    => 'onboarding@resend.dev',
    'to'      => ['jbortega@kld.edu.ph'],
    'subject' => 'Dasma Alert API Test',
    'html'    => '<p>OTP delivery via HTTPS API is <strong>operational!</strong></p>'
];

$ch = curl_init('https://api.resend.com/emails');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . trim($apiKey),
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: " . $httpCode . "<br>";
echo "Response: " . $response;