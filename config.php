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
        [14.3642, 120.9410],
        [14.3695, 120.9525],
        [14.3725, 120.9638],
        [14.3740, 120.9780],
        [14.3685, 120.9910],
        [14.3540, 121.0025],
        [14.3385, 121.0118],
        [14.3210, 121.0142],
        [14.3050, 121.0095],
        [14.2950, 120.9985],
        [14.2885, 120.9850],
        [14.2810, 120.9765],
        [14.2750, 120.9680],
        [14.2710, 120.9580],
        [14.2665, 120.9470],
        [14.2620, 120.9385],
        [14.2580, 120.9310],
        [14.2645, 120.9230],
        [14.2750, 120.9165],
        [14.2855, 120.9120],
        [14.2980, 120.9085],
        [14.3090, 120.9095],
        [14.3185, 120.9125],
        [14.3300, 120.9160],
        [14.3415, 120.9215],
        [14.3520, 120.9280],
        [14.3600, 120.9350]
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