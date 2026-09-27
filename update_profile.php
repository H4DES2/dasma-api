<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$raw_id = $_POST['userId'] ?? $_POST['id'] ?? ($_SESSION['user_id'] ?? null);
$user_id = $raw_id !== null ? (int)$raw_id : null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "Missing User ID"]);
    exit();
}

// Helper: Ensure profile row exists
function getExistingProfile(mysqli $conn, int $user_id): array {
    $stmt = $conn->prepare("SELECT theme, font_size, phone_number, profile_photo FROM user_profiles WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res ?: [];
}

// 1. Handle Manage Profile (Phone Number & Password Change)
if (isset($_POST['action']) && $_POST['action'] === 'update_personal_info') {
    $phone       = $_POST['phone_number'] ?? null;
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd     = $_POST['new_password'] ?? '';

    if ($phone !== null) {
        $existing = getExistingProfile($conn, $user_id);
        if (!empty($existing)) {
            $stmt_phone = $conn->prepare("UPDATE user_profiles SET phone_number = ? WHERE user_id = ?");
            $stmt_phone->bind_param("si", $phone, $user_id);
        } else {
            $stmt_phone = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number, theme, font_size) VALUES (?, ?, 'light', '16px')");
            $stmt_phone->bind_param("is", $user_id, $phone);
        }
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

// 2. Handle Profile Photo Sync
if (isset($_POST['action']) && $_POST['action'] === 'update_photo') {
    $photo_url = trim($_POST['profile_photo'] ?? '');

    if (empty($photo_url)) {
        echo json_encode(["success" => false, "message" => "Photo URL is empty"]);
        $conn->close();
        exit();
    }

    $existing = getExistingProfile($conn, $user_id);
    if (!empty($existing)) {
        $stmt = $conn->prepare("UPDATE user_profiles SET profile_photo = ? WHERE user_id = ?");
        $stmt->bind_param("si", $photo_url, $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, profile_photo, theme, font_size) VALUES (?, ?, 'light', '16px')");
        $stmt->bind_param("is", $user_id, $photo_url);
    }

    if ($stmt->execute()) {
        try {
            $stmt_u = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            if ($stmt_u) {
                $stmt_u->bind_param("si", $photo_url, $user_id);
                $stmt_u->execute();
                $stmt_u->close();
            }
        } catch (Exception $e) {}

        echo json_encode(["success" => true, "message" => "Photo synced!"]);
    } else {
        echo json_encode(["success" => false, "message" => "Sync failed: " . $conn->error]);
    }

    $stmt->close();
    $conn->close();
    exit();
}

// 3. Dynamic Partial Settings Update (Theme, Font Size, Barangay, Online State)
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

        if (!empty($existing)) {
            $stmt_pref = $conn->prepare("UPDATE user_profiles SET theme = ?, font_size = ? WHERE user_id = ?");
            $stmt_pref->bind_param("ssi", $final_theme, $final_font, $user_id);
        } else {
            $stmt_pref = $conn->prepare("INSERT INTO user_profiles (user_id, theme, font_size) VALUES (?, ?, ?)");
            $stmt_pref->bind_param("iss", $user_id, $final_theme, $final_font);
        }

        if (!$stmt_pref->execute()) {
            throw new Exception("Profile preference update failed: " . $stmt_pref->error);
        }
        $stmt_pref->close();

        // 🚀 Sync PHP Session so page refresh doesn't revert to dark!
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