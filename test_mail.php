<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    // Enable full debug output to the browser
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = getenv('SMTP_USER') ?: $_ENV['SMTP_USER'];
    $mail->Password   = getenv('SMTP_PASS') ?: $_ENV['SMTP_PASS'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Port 465 SSL
    $mail->Port       = 465;
    $mail->Timeout    = 10; // Don't let it hang forever

    // Bypass Docker SSL certificate verification hangs
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    $mail->setFrom($mail->Username, 'Dasma Alert Test');
    $mail->addAddress($mail->Username); // Send to yourself
    $mail->Subject = 'Render SMTP Test';
    $mail->Body    = 'If you see this, SMTP is finally working!';

    $mail->send();
    echo "<h1>SUCCESS: Email sent!</h1>";
} catch (Exception $e) {
    echo "<h1>FAILED</h1><p>Mailer Error: {$mail->ErrorInfo}</p>";
}