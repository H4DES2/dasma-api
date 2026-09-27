<?php
while (ob_get_level() > 0) { 
    ob_end_clean(); 
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=UTF-8");

if (file_exists(__DIR__ . '/php/config.php')) {
    require_once __DIR__ . '/php/config.php';
} elseif (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/../php/config.php')) {
    require_once __DIR__ . '/../php/config.php';
} else {
    echo json_encode(["success" => false, "message" => "Database config file not found."]);
    exit();
}

$raw_id = $_POST['userId'] ?? $_POST['id'] ?? ($_SESSION['user_id'] ?? null);
$user_id = $raw_id !== null ? (int)$raw_id : null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "Missing User ID"]);
    exit();
}

function getExistingProfile(mysqli $conn, int $user_id): array {
    $stmt = $conn->prepare("
        SELECT up.theme, up.font_size, up.phone_number, up.profile_photo
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ? 
        LIMIT 1
    ");
    if (!$stmt) return [];
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ?: [];
}

// 1. Manage Personal Info (Phone & Password)
if (isset($_POST['action']) && $_POST['action'] === 'update_personal_info') {
    $phone       = $_POST['phone_number'] ?? null;
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd     = $_POST['new_password'] ?? '';

    if ($phone !== null) {
        $existing = getExistingProfile($conn, $user_id);
        $stmt_phone = $conn->prepare("
            INSERT INTO user_profiles (user_id, phone_number, theme, font_size, profile_photo) 
            VALUES (?, ?, 'light', '16px', ?)
            ON DUPLICATE KEY UPDATE phone_number = VALUES(phone_number)
        ");
        $existing_photo = $existing['profile_photo'] ?? null;
        $stmt_phone->bind_param("iss", $user_id, $phone, $existing_photo);
        $stmt_phone->execute();
        $stmt_phone->close();
    }

    if (!empty($new_pwd)) {
        if (empty($current_pwd)) {
            echo json_encode(["success" => false, "message" => "Current password is required to set a new password."]);
            $conn->close();
            exit();
        }

        $stmt_pwd = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt_pwd->bind_param("i", $user_id);
        $stmt_pwd->execute();
        $user = $stmt_pwd->get_result()->fetch_assoc();
        $stmt_pwd->close();

        if ($user && password_verify($current_pwd, $user['password'])) {
            $hashed_pwd = password_hash($new_pwd, PASSWORD_DEFAULT);
            $stmt_upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt_upd->bind_param("si", $hashed_pwd, $user_id);
            $stmt_upd->execute();
            $stmt_upd->close();
            
            echo json_encode(["success" => true, "message" => "Profile & password updated successfully!"]);
            $conn->close();
            exit();
        } else {
            echo json_encode(["success" => false, "message" => "Incorrect current password!"]);
            $conn->close();
            exit();
        }
    }

    echo json_encode(["success" => true, "message" => "Profile updated successfully!"]);
    $conn->close();
    exit();
}

// 2. Profile Photo Sync
if (isset($_POST['action']) && $_POST['action'] === 'update_photo') {
    $photo_url = trim($_POST['profile_photo'] ?? '');

    if (empty($photo_url)) {
        echo json_encode(["success" => false, "message" => "Photo URL is empty"]);
        $conn->close();
        exit();
    }

    $stmt1 = $conn->prepare("
        INSERT INTO user_profiles (user_id, profile_photo, theme, font_size) 
        VALUES (?, ?, 'light', '16px')
        ON DUPLICATE KEY UPDATE profile_photo = VALUES(profile_photo)
    ");
    $stmt1->bind_param("is", $user_id, $photo_url);
    $ok1 = $stmt1->execute();
    $stmt1->close();

    if ($ok1) {
        echo json_encode(["success" => true, "message" => "Photo synced!"]);
    } else {
        echo json_encode(["success" => false, "message" => "Sync failed: " . $conn->error]);
    }

    $conn->close();
    exit();
}

// 3. Dynamic Partial Settings Update
$conn->begin_transaction();
try {
    if (isset($_POST['barangay'])) {
        $brgy = trim($_POST['barangay']);
        $stmt_b = $conn->prepare("UPDATE users SET barangay = ? WHERE id = ?");
        $stmt_b->bind_param("si", $brgy, $user_id);
        $stmt_b->execute();
        $stmt_b->close();
        $_SESSION['barangay'] = $brgy;
    }

    $theme     = $_POST['theme'] ?? null;
    $font_size = $_POST['font_size'] ?? null;

    if ($theme !== null || $font_size !== null) {
        $existing = getExistingProfile($conn, $user_id);

        $final_theme = $theme !== null ? strtolower(trim($theme)) : ($existing['theme'] ?? 'light');
        $final_font  = $font_size !== null ? trim($font_size) : ($existing['font_size'] ?? '16px');
        $photo_keep  = $existing['profile_photo'] ?? null;

        $stmt_pref = $conn->prepare("
            INSERT INTO user_profiles (user_id, theme, font_size, profile_photo) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE theme = VALUES(theme), font_size = VALUES(font_size)
        ");
        $stmt_pref->bind_param("isss", $user_id, $final_theme, $final_font, $photo_keep);

        if (!$stmt_pref->execute()) {
            throw new Exception("Profile preference update failed: " . $stmt_pref->error);
        }
        $stmt_pref->close();

        $_SESSION['theme'] = $final_theme;
        $_SESSION['font_size'] = $final_font;
    }

    $conn->commit();
    echo json_encode(["success" => true, "message" => "Settings Saved Successfully!"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();