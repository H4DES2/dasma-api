<?php
require_once __DIR__ . '/config.php';
while (ob_get_level() > 0) { ob_end_clean(); }
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['userId']) ? intval($_POST['userId']) : 0;
    $image  = isset($_FILES['image']) ? $_FILES['image'] : null;

    if (!$image || $userId <= 0 || $image['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["success" => false, "message" => "Missing user ID or image data."]);
        exit;
    }

    $tmp_path = $image['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmp_path);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/webp', 'image/gif'];
    $ext = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif'];

    if (!in_array($mime, $allowed_mimes, true) || !in_array($ext, $allowed_exts, true) || !@getimagesize($tmp_path)) {
        echo json_encode(["success" => false, "message" => "Invalid image format."]);
        exit;
    }

    // Normalize JFIF to JPG
    $clean_ext  = ($ext === 'jfif') ? 'jpg' : $ext;
    $clean_mime = ($ext === 'jfif') ? 'image/jpeg' : $mime;
    $file_id    = 'profile_' . $userId . '_' . time();

    $cloud_name    = 'wyxsiraw';
    $upload_preset = 'dasma_preset';

    $cfile = new CURLFile($tmp_path, $clean_mime, $file_id . '.' . $clean_ext);
    $post_fields = [
        'file'          => $cfile,
        'upload_preset' => $upload_preset,
        'folder'        => 'dasma_profiles',
        'asset_folder'  => 'dasma_profiles',
        'public_id'     => 'dasma_profiles/' . $file_id
    ];

    $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloud_name}/image/upload");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json_res = json_decode($response, true);
    if ($http_code === 200 && !empty($json_res['secure_url'])) {
        $cloud_url = $json_res['secure_url'];

        $stmt_check = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
        $stmt_check->bind_param("i", $userId);
        $stmt_check->execute();
        $exists = ($stmt_check->get_result()->num_rows > 0);
        $stmt_check->close();

        if ($exists) {
            $stmt = $conn->prepare("UPDATE user_profiles SET profile_photo = ? WHERE user_id = ?");
            $stmt->bind_param("si", $cloud_url, $userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, profile_photo) VALUES (?, ?)");
            $stmt->bind_param("is", $userId, $cloud_url);
        }

        if ($stmt && $stmt->execute()) {
            echo json_encode([
                "success" => true,
                "profile_photo" => $cloud_url
            ]);
        } else {
            echo json_encode(["success" => false, "message" => "Database update failed."]);
        }
        if ($stmt) $stmt->close();
    } else {
        echo json_encode(["success" => false, "message" => "Cloudinary upload failed: " . ($response ?: "HTTP $http_code")]);
    }
}
$conn->close();