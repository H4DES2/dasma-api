<?php
require_once 'config.php';

class Auth {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    // Accepts registration parameters
    public function signup($username, $email, $password, $password_confirm, $first_name, $last_name, $mobile) {
        if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($mobile)) {
            return ['success' => false, 'message' => 'All fields are required.'];
        }
        
        if ($password !== $password_confirm) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'user', 'Active')");
        $stmt->bind_param("sss", $username, $email, $hash);
        
        if ($stmt->execute()) {
            $user_id = $this->conn->insert_id;
            $stmt->close();
            
            $stmt_profile = $this->conn->prepare("INSERT INTO client_profiles (user_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)");
            $stmt_profile->bind_param("isss", $user_id, $first_name, $last_name, $mobile);
            $stmt_profile->execute();
            $stmt_profile->close();
            
            return ['success' => true, 'message' => 'Account created!'];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'DB Error or Username taken'];
    }

    public function login($username, $password) {
        $stmt = $this->conn->prepare("SELECT id, username, password, first_name, last_name, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $stmt->close();
                return [
                    "success" => true,
                    "id" => $user['id'],
                    "username" => $user['username'],
                    "fname" => $user['first_name'],
                    "lname" => $user['last_name'],
                    "role" => $user['role']
                ];
            }
        }
        $stmt->close();
        return ["success" => false, "message" => "Invalid credentials"];
    }

    public function is_logged_in() { 
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        return isset($_SESSION['user_id']); 
    }
    
    public function isSuperAdmin() { return $this->is_logged_in() && ($_SESSION['role'] ?? '') === 'superadmin'; }
    public function isAdmin() { return $this->is_logged_in() && ($_SESSION['role'] ?? '') === 'admin'; }
    public function isResponder() { return $this->is_logged_in() && ($_SESSION['role'] ?? '') === 'responder'; }
    public function isUser() { return $this->is_logged_in() && ($_SESSION['role'] ?? '') === 'user'; }

    public function logout() { 
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        session_unset(); 
        session_destroy(); 
    }
}

$auth = new Auth($conn);
?>