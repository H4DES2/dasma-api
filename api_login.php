<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'auth.php';

$auth = new Auth($conn);

// Read JSON payload from Flutter
$data = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? ($_POST['username'] ?? '');
$password = $data['password'] ?? ($_POST['password'] ?? '');

$result = $auth->login($username, $password);
echo json_encode($result);
exit();
?>