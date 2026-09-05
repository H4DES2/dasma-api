<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiKey = getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? '');
$senderEmail = 'jacobbataclanortega@gmail.com'; // Your registered Brevo account email

$payload = [
    'sender'      => ['name' => 'Dasma Alert', 'email' => $senderEmail],
    'to'          => [['email' => 'jbortega@kld.edu.ph']],
    'subject'     => 'Dasma Alert Verification Code',
    'htmlContent' => '<h3>Your OTP verification code is: <strong>482910</strong></h3>'
];

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'api-key: ' . trim($apiKey),
    'Content-Type: application/json',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: " . $httpCode . "<br>";
echo "Response: " . $response;