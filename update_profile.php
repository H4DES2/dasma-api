<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$raw_id = $_POST['userId'] ?? $_POST['id'] ?? null;
$user_id = $raw_id !== null ? (int)$raw_id : null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "Missing User ID"]);
    exit();
}

// 1. Handle Manage Profile (Phone Number & Password Change)
if (isset($_POST['action']) && $_POST['action'] === 'update_personal_info') {
    $phone       = $_POST['phone_number'] ?? null;
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd     = $_POST['new_password'] ?? '';

    // Upsert phone number into user_profiles
    if ($phone !== null) {
        $stmt_phone = $conn->prepare("
            INSERT INTO user_profiles (user_id, phone_number, theme, font_size) 
            VALUES (?, ?, 'dark', '16px')
            ON DUPLICATE KEY UPDATE phone_number = VALUES(phone_number)
        ");
        $stmt_phone->bind_param("is", $user_id, $phone);
        $stmt_phone->execute();
        $stmt_phone->close();
    }

    // Password validation
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

    $stmt = $conn->prepare("
        INSERT INTO user_profiles (user_id, profile_photo, theme, font_size) 
        VALUES (?, ?, 'dark', '16px')
        ON DUPLICATE KEY UPDATE profile_photo = VALUES(profile_photo)
    ");
    $stmt->bind_param("is", $user_id, $photo_url);

    if ($stmt->execute()) {
        try {
            $stmt_u = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            if ($stmt_u) {
                $stmt_u->bind_param("si", $photo_url, $user_id);
                $stmt_u->execute();
                $stmt_u->close();
            }
        } catch (Exception $e) {
            // Optional users column fallback
        }

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
    // A. Update users table only for provided values
    $user_updates = [];
    $user_types = "";
    $user_params = [];

    if (isset($_POST['is_online'])) {
        $user_updates[] = "is_online = ?";
        $user_types .= "i";
        $user_params[] = (int)$_POST['is_online'];
    }
    if (isset($_POST['department'])) {
        $user_updates[] = "department = ?";
        $user_types .= "s";
        $user_params[] = trim($_POST['department']);
    }
    if (isset($_POST['barangay'])) {
        $user_updates[] = "barangay = ?";
        $user_types .= "s";
        $user_params[] = trim($_POST['barangay']);
    }

    if (!empty($user_updates)) {
        $sql1 = "UPDATE users SET " . implode(", ", $user_updates) . " WHERE id = ?";
        $user_types .= "i";
        $user_params[] = $user_id;

        $stmt1 = $conn->prepare($sql1);
        $stmt1->bind_param($user_types, ...$user_params);
        if (!$stmt1->execute()) {
            throw new Exception("Users update failed: " . $stmt1->error);
        }
        $stmt1->close();
    }

    // B. Upsert preferences into user_profiles only for provided values
    $theme     = $_POST['theme'] ?? null;
    $font_size = $_POST['font_size'] ?? null;

    if ($theme !== null || $font_size !== null) {
        $stmt_check = $conn->prepare("SELECT theme, font_size FROM user_profiles WHERE user_id = ?");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        $prof_res = $stmt_check->get_result();
        $existing = $prof_res->fetch_assoc();
        $stmt_check->close();

        $final_theme = $theme ?? ($existing['theme'] ?? 'dark');
        $final_font  = $font_size ?? ($existing['font_size'] ?? '16px');

        $stmt2 = $conn->prepare("
            INSERT INTO user_profiles (user_id, theme, font_size) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE theme = VALUES(theme), font_size = VALUES(font_size)
        ");
        $stmt2->bind_param("iss", $user_id, $final_theme, $final_font);
        if (!$stmt2->execute()) {
            throw new Exception("Profile preference update failed: " . $stmt2->error);
        }
        $stmt2->close();
    }

    $conn->commit();
    echo json_encode(["success" => true, "message" => "Settings Saved Successfully!"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();