<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function respond(bool $success, string $message): void
{
    echo json_encode(["success" => $success, "message" => $message]);
    exit();
}

/**
 * Sends an email through Brevo's HTTPS API (port 443).
 * Render's free tier blocks SMTP ports (25/465/587), so PHPMailer/SMTP can't work there.
 * Returns [true, ''] or [false, 'reason'].
 */
function send_mail_brevo(string $toEmail, string $toName, string $subject, string $html): array
{
    $apiKey = getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? '');
    if ($apiKey === '') {
        error_log('send_reset_code: BREVO_API_KEY is not set');
        return [false, 'Email service is not configured on the server.'];
    }

    $fromEmail = getenv('FROM_EMAIL') ?: ($_ENV['FROM_EMAIL'] ?? '');
    $fromName  = getenv('FROM_NAME')  ?: ($_ENV['FROM_NAME']  ?? 'Dasma Alert');
    if ($fromEmail === '') {
        error_log('send_reset_code: FROM_EMAIL is not set');
        return [false, 'Email sender is not configured on the server.'];
    }

    $payload = json_encode([
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('send_reset_code: Brevo request failed: ' . $curlErr);
        return [false, 'Could not reach the email service. Please try again.'];
    }
    if ($status < 200 || $status >= 300) {
        // Full reason (e.g. "sender not verified", "invalid key") goes to the Render logs only
        error_log("send_reset_code: Brevo returned HTTP $status: $response");
        return [false, 'The email service rejected the request. Please try again later.'];
    }
    return [true, ''];
}

try {
    // 1. Database connection
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } elseif (file_exists(__DIR__ . '/php/config.php')) {
        require_once __DIR__ . '/php/config.php';
    } else {
        respond(false, "Database config file missing.");
    }
    require_once __DIR__ . '/reset_helpers.php';

    // 2. Ensure table exists
    ensure_reset_table($conn);

    // 3. Parse request
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($_POST['email'] ?? $input['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, "A valid email address is required.");
    }

    // 4. Verify user exists
    $stmt = $conn->prepare("SELECT id, first_name FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        respond(false, "No account found with this email.");
    }

    // 5. Cooldown: stops people from spamming someone's inbox
    $stmt = $conn->prepare(
        "SELECT id FROM password_resets
         WHERE email = ? AND created_at > (NOW() - INTERVAL 45 SECOND) LIMIT 1"
    );
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $tooSoon = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($tooSoon) {
        respond(false, "Please wait a moment before requesting another code.");
    }

    // 6. Create + store the code (expiry is computed by MySQL so timezones can't disagree)
    $code = (string)random_int(100000, 999999);

    $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO password_resets (email, code, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))"
    );
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $stmt->close();

    // 7. Send the email
    $name = htmlspecialchars($user['first_name'], ENT_QUOTES, 'UTF-8');
    $html = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2 style='color: #D32F2F;'>DasmaAlert Emergency Services</h2>
            <p>Hello <b>{$name}</b>,</p>
            <p>Your password reset verification code is:</p>
            <div style='font-size: 28px; font-weight: bold; letter-spacing: 4px; color: #D32F2F; margin: 20px 0;'>
                {$code}
            </div>
            <p>This code expires in <b>15 minutes</b>.</p>
            <p style='color: #888; font-size: 12px;'>If you did not request this, please disregard this email.</p>
        </div>";

    [$sent, $error] = send_mail_brevo($email, $user['first_name'], 'DasmaAlert - Password Reset Code', $html);

    if (!$sent) {
        // Remove the unused code so the cooldown doesn't block an immediate retry
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->close();
        respond(false, $error);
    }

    respond(true, "Verification code sent to your email!");
} catch (Throwable $e) {
    error_log('send_reset_code: ' . $e->getMessage());
    respond(false, "Server error. Please try again.");
}