<?php
require_once 'config.php';

class Auth {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    // 🚀 Accepts all 7 parameters from the new signup form
    public function signup($username, $email, $password, $password_confirm, $first_name, $last_name, $mobile) {
        // 1. Check if ANY field is empty
        if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($mobile)) {
            return ['success' => false, 'message' => 'All fields are required.'];
        }
        
        if ($password !== $password_confirm) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        // 2. Insert into main users table (Role: user)
        $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'user', 'Active')");
        $stmt->bind_param("sss", $username, $email, $hash);
        
        if ($stmt->execute()) {
            $user_id = $this->conn->insert_id;
            
            // 🚀 3. Insert personal details using the exact 'phone' column name
            $stmt_profile = $this->conn->prepare("INSERT INTO client_profiles (user_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)");
            $stmt_profile->bind_param("isss", $user_id, $first_name, $last_name, $mobile);
            $stmt_profile->execute();
            
            return ['success' => true, 'message' => 'Account created!'];
        }
        return ['success' => false, 'message' => 'DB Error or Username taken'];
    }

    // Inside auth.php
public function login($username, $password) {
    $stmt = $this->conn->prepare("SELECT id, username, password, first_name, last_name, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            return [
                "success" => true,
                "id" => $user['id'], // 🚨 MUST include this
                "username" => $user['username'],
                "fname" => $user['first_name'],
                "lname" => $user['last_name'],
                "role" => $user['role']
            ];
        }
    }
    return ["success" => false, "message" => "Invalid credentials"];
}

    public function is_logged_in() { return isset($_SESSION['user_id']); }
    
    // Level 1: CDRRMO Command Center (HQ)
    public function isSuperAdmin() { return $this->is_logged_in() && $_SESSION['role'] === 'superadmin'; }
    
    // Level 2: Barangay Officials
    public function isAdmin() { return $this->is_logged_in() && $_SESSION['role'] === 'admin'; }

    // Level 3: First Responders (Team Apps)
    public function isResponder() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'responder';
    }
    
    // Level 4: Citizen App Users
    public function isUser() { return $this->is_logged_in() && $_SESSION['role'] === 'user'; }

    public function logout() { 
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        session_unset(); 
        session_destroy(); 
    }
}

$auth = new Auth($conn);
?>