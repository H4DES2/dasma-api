<?php
// Prevent PHP warnings from ruining our clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

require_once 'config.php';

$user_id = $_POST['userId'] ?? $_POST['id'] ?? null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "Missing User ID"]);
    exit();
}

// Handle Manage Profile (Phone Number & Password Change)
if (isset($_POST['action']) && $_POST['action'] === 'update_personal_info') {
    $phone = $_POST['phone_number'] ?? '';
    $current_pwd = $_POST['current_password'] ?? '';
    $new_pwd = $_POST['new_password'] ?? '';

    // 1. Update phone number in user_profiles
    $stmt_phone = $conn->prepare("UPDATE user_profiles SET phone_number = ? WHERE user_id = ?");
    $stmt_phone->bind_param("si", $phone, $user_id);
    $stmt_phone->execute();
    $stmt_phone->close();

    // 2. Securely Handle Password Change if requested
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
            exit();
        } else {
            echo json_encode(["success" => false, "message" => "Incorrect current password!"]);
            exit();
        }
    }

    echo json_encode(["success" => true, "message" => "Profile updated successfully!"]);
    exit();
}

// Safely grab all variables
$department = $_POST['department'] ?? '';
$barangay = $_POST['barangay'] ?? '';
$theme = $_POST['theme'] ?? 'dark';
$font_size = $_POST['font_size'] ?? '16px';
// 🚀 FIX: Default to 0 instead of 1 if missing so status isn't forcibly set to online
$is_online = isset($_POST['is_online']) ? (int)$_POST['is_online'] : 0;

$conn->begin_transaction();

try {
    // 1. Update the main USERS table (Team, Brgy, and Duty Status)
    $stmt1 = $conn->prepare("UPDATE users SET is_online = ?, department = ?, barangay = ? WHERE id = ?");
    $stmt1->bind_param("issi", $is_online, $department, $barangay, $user_id);
    if (!$stmt1->execute()) {
        throw new Exception("Users update failed: " . $stmt1->error);
    }
    $stmt1->close();

    // 2. Safely check if this user already has a profile settings row
    $check = $conn->query("SELECT id FROM user_profiles WHERE user_id = $user_id");
    
    if ($check && $check->num_rows > 0) {
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
?>