<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $image  = isset($_FILES['image']) ? $_FILES['image'] : null;

    if (!$image || $userId <= 0) {
        echo json_encode(["success" => false, "message" => "Missing user ID or image data"]);
        exit;
    }

    $targetDir = __DIR__ . "/uploads/profiles/";
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $fileExtension = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
    
    if (!in_array($fileExtension, $allowedTypes)) {
    echo json_encode(["success" => false, "message" => "Invalid file format. Only JPG/PNG/WEBP/JFIF allowed."]);
    exit;
}

   $fileName = "profile_" . $userId . "_" . time() . "." . $fileExtension;
    $targetFilePath = $targetDir . $fileName;

    if (move_uploaded_file($image['tmp_name'], $targetFilePath)) {
        $stmt_check = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
        $stmt_check->bind_param("i", $userId);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        $exists = ($check_result && $check_result->num_rows > 0);
        $stmt_check->close();
        
        if ($exists) {
            $stmt = $conn->prepare("UPDATE user_profiles SET profile_photo = ? WHERE user_id = ?");
            $stmt->bind_param("si", $fileName, $userId);
        } else {
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
        echo json_encode(["success" => false, "message" => "File upload failed."]);
    }
}

$conn->close();