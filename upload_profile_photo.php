<?php
// Hide HTML warnings so Flutter's JSON parser doesn't crash
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $image = isset($_FILES['image']) ? $_FILES['image'] : null;

    if (!$image || $userId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing user ID or image data"]);
        exit;
    }

    // Tell it to save inside a dedicated 'profiles' folder
    $targetDir = __DIR__ . "/uploads/profiles/";
    
    // Create the folder if it doesn't exist yet
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $fileExtension = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (!in_array($fileExtension, $allowedTypes)) {
        echo json_encode(["success" => false, "message" => "Invalid file format. Only JPG/PNG allowed."]);
        exit;
    }

    $fileName = "profile_" . $userId . "_" . time() . "." . $fileExtension;
    $targetFilePath = $targetDir . $fileName;

    if (move_uploaded_file($image['tmp_name'], $targetFilePath)) {
        
        // 🚀 THE FIX: Manually check if the user profile row exists first to bypass index limitations
        $check = $conn->query("SELECT id FROM user_profiles WHERE user_id = $userId");
        
        if ($check && $check->num_rows > 0) {
            // Row exists! Do a clean UPDATE.
            $stmt = $conn->prepare("UPDATE user_profiles SET profile_photo = ? WHERE user_id = ?");
            $stmt->bind_param("si", $fileName, $userId);
        } else {
            // Row doesn't exist yet! Do a clean INSERT.
            $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, profile_photo) VALUES (?, ?)");
            $stmt->bind_param("is", $userId, $fileName);
        }
        
        if ($stmt && $stmt->execute()) {
            echo json_encode(["success" => true, "profile_photo" => $fileName]);
        } else {
            echo json_encode(["success" => false, "message" => "Database update failed: " . $conn->error]);
        }
        
        if ($stmt) {
            $stmt->close();
        }
        
    } else {
        echo json_encode(["success" => false, "message" => "File move failed. Check folder permissions."]);
    }
}
?>