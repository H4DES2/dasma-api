<?php
// ==========================================
// --- .ENV LOADER FUNCTION ---
// ==========================================
function loadEnv($path) {
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim(trim($value), "\"'");
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
    return true;
}

$possible_paths = [
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    __DIR__ . '/../../.env'
];

foreach ($possible_paths as $path) {
    if (loadEnv($path)) break;
}

// ==========================================
// --- CONFIGURATION CONSTANTS ---
// ==========================================
define('DB_HOST',    $_ENV['DB_HOST']   ?? 'localhost');
define('DB_PORT',    (int)($_ENV['DB_PORT'] ?? 3306));
define('DB_USER',    $_ENV['DB_USER']   ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']   ?? '');
define('DB_NAME',    $_ENV['DB_NAME']   ?? 'alert');

define('SMTP_HOST',  $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_PORT',  (int)($_ENV['SMTP_PORT'] ?? 587));
define('SMTP_USER',  $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASS',  $_ENV['SMTP_PASS'] ?? '');
define('FROM_EMAIL', $_ENV['FROM_EMAIL'] ?? SMTP_USER);
define('FROM_NAME',  $_ENV['FROM_NAME']  ?? 'Dasma Alert');

define('BASE_URL',   $_ENV['BASE_URL']  ?? 'http://localhost/dasma_api');
define('TOKEN_EXPIRY', 3600);
define('REMEMBER_ME_DURATION', 2592000);

define('ADMIN_ROLE',  'admin');
define('CLIENT_ROLE', 'client');

// ==========================================
// --- DATABASE CONNECTION (SSL & CLOUD READY) ---
// ==========================================
$conn = mysqli_init();

if (DB_PORT !== 3306) {
    // Cloud database with SSL (Aiven, Render, TiDB)
    $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, NULL, MYSQLI_CLIENT_SSL);
} else {
    // Local fallback (Standard MySQL / XAMPP)
    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}

if (!$connected) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die(json_encode(["success" => false, "message" => "Database connection unavailable."]));
}

$conn->set_charset("utf8mb4");

// ==========================================
// --- HELPERS ---
// ==========================================
function get_client_ip() {
    return filter_var($_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', FILTER_VALIDATE_IP) ?: '127.0.0.1';
}

function isInsideDasma($lat, $lng) {
    $poly = [
        [14.3643, 120.9324], [14.3452, 120.9856], [14.3125, 121.0021], 
        [14.2856, 121.0125], [14.2482, 120.9754], [14.2654, 120.9254], 
        [14.2854, 120.9021], [14.3212, 120.9125]
    ];

    $inside = false;
    $n = count($poly);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        if ((($poly[$i][0] > $lat) != ($poly[$j][0] > $lat)) &&
            ($lng < ($poly[$j][1] - $poly[$i][1]) * ($lat - $poly[$i][0]) / ($poly[$j][0] - $poly[$i][0]) + $poly[$i][1])) {
            $inside = !$inside;
        }
    }
    return $inside;
}
?>