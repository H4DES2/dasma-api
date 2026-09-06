<?php
if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

// 🚀 FIXED: Port 465 connects over SSL, bypassing Render 587 port blocks
define('SMTP_HOST',  $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
define('SMTP_PORT',  (int)($_ENV['SMTP_PORT'] ?? 465));
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

// Fail fast after 4 seconds instead of freezing Flutter past its 15s timeout
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 4);

if (DB_PORT !== 3306) {
    // Cloud database with SSL (Aiven, Render, TiDB)
    $conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
    $ssl_flag = defined('MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT') 
        ? MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT 
        : MYSQLI_CLIENT_SSL;
    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, NULL, $ssl_flag);
} else {
    // Local fallback (Standard MySQL / XAMPP)
    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}

if (!$connected) {
    $err = mysqli_connect_error();
    error_log("Database connection failed: " . $err);
    header("Content-Type: application/json; charset=UTF-8");
    die(json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Database connection unavailable: " . $err
    ]));
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
        [14.3648, 120.9332],
        [14.3638, 120.9455],
        [14.3642, 120.9578],
        [14.3635, 120.9662],
        [14.3540, 120.9678],
        [14.3548, 120.9740],
        [14.3562, 120.9840],
        [14.3490, 121.0020],
        [14.3410, 121.0145],
        [14.3315, 121.0118],
        [14.3160, 121.0045],
        [14.3015, 120.9990],
        [14.2885, 120.9892],
        [14.2750, 120.9830],
        [14.2660, 120.9775],
        [14.2540, 120.9660],
        [14.2610, 120.9585],
        [14.2615, 120.9410],
        [14.2540, 120.9380],
        [14.2505, 120.9355],
        [14.2520, 120.9250],
        [14.2570, 120.9268],
        [14.2640, 120.9230],
        [14.2730, 120.9180],
        [14.2855, 120.9145],
        [14.3010, 120.9130],
        [14.3180, 120.9185],
        [14.3360, 120.9215],
        [14.3465, 120.9205],
        [14.3560, 120.9220],
        [14.3585, 120.9260]
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