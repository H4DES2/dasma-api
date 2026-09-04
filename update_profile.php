<?php
require_once __DIR__ . '/config.php';
header("Content-Type: application/json; charset=UTF-8");

$raw_id = $_POST['userId'] ?? $_POST['id'] ?? null;
$user_id = $raw_id !== null ? (int)$raw_id : null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "Missing User ID"]);
    exit();
}

// Handle Manage Profile (Phone Number & Password Change)
if (isset($_POST['action']) && $_POST['action'] === 'update_personal_info') {
    $phone       = $_POST['phone_number'] ?? '';
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd     = $_POST['new_password'] ?? '';

    $stmt_phone = $conn->prepare("UPDATE user_profiles SET phone_number = ? WHERE user_id = ?");
    $stmt_phone->bind_param("si", $phone, $user_id);
    $stmt_phone->execute();
    $stmt_phone->close();

    if (!empty($current_pwd) && !empty($new_pwd)) {
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

$department = $_POST['department'] ?? '';
$barangay   = $_POST['barangay'] ?? '';
$theme      = $_POST['theme'] ?? 'dark';
$font_size  = $_POST['font_size'] ?? '16px';
$is_online  = isset($_POST['is_online']) ? (int)$_POST['is_online'] : 0;

$conn->begin_transaction();

try {
    $stmt1 = $conn->prepare("UPDATE users SET is_online = ?, department = ?, barangay = ? WHERE id = ?");
    $stmt1->bind_param("issi", $is_online, $department, $barangay, $user_id);
    if (!$stmt1->execute()) {
        throw new Exception("Users update failed: " . $stmt1->error);
    }
    $stmt1->close();

    $stmt_check = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $check_result = $stmt_check->get_result();
    $has_profile = $check_result && $check_result->num_rows > 0;
    $stmt_check->close();
    
    if ($has_profile) {
        $stmt2 = $conn->prepare("UPDATE user_profiles SET theme = ?, font_size = ? WHERE user_id = ?");
        $stmt2->bind_param("ssi", $theme, $font_size, $user_id);
        if (!$stmt2->execute()) throw new Exception("Profile update failed: " . $stmt2->error);
        $stmt2->close();
    } else {
        $stmt3 = $conn->prepare("INSERT INTO user_profiles (user_id, theme, font_size, profile_photo, phone_number, radio_callsign, position) VALUES (?, ?, ?, '', '', '', '')");
        $stmt3->bind_param("iss", $user_id, $theme, $font_size);
        if (!$stmt3->execute()) throw new Exception("Profile insert failed: " . $stmt3->error);
        $stmt3->close();
    }

    $conn->commit();
    echo json_encode(["success" => true, "message" => "Settings Saved Successfully!"]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}

$conn->close();