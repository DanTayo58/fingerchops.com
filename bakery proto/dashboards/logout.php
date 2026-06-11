<?php
// logout.php - Handles user logout with proper logging
session_start();

// Include database connection and security functions
require_once '../conn.php';
require_once '../includes/Security.php';

// Get database instance
$db = Database::getInstance();

// Log the logout if user was logged in
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $ip = $_SERVER['REMOTE_ADDR'];
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
    
    // Log to activity_logs using the existing logActivity function
    if (function_exists('logActivity')) {
        logActivity($userId, 'Logged out', 'auth', $userId);
    } else {
        // Fallback if logActivity doesn't exist
        $query = "INSERT INTO activity_logs (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at)
                  VALUES ($userId, 'Logged out', 'auth', $userId, '$ip', '$userAgent', NOW())";
        $db->query($query);
    }
    
    // Log to audit_trail
    $query = "INSERT INTO audit_trail (user_id, action_type, ip_address, user_agent, created_at)
              VALUES ($userId, 'LOGOUT', '$ip', '$userAgent', NOW())";
    $db->query($query);
    
    // Deactivate the user's session in the database
    if (isset($_SESSION['session_id'])) {
        $sessionId = $db->escape($_SESSION['session_id']);
        $db->query("UPDATE user_sessions SET is_active = 0 WHERE session_id = '$sessionId'");
    }
}

// Clear all session data
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear remember-me cookie if present so the user is not auto-logged in again
if (isset($_COOKIE['remember_me'])) {
    setcookie('remember_me', '', time() - 42000, '/');
}

// Destroy the session
session_destroy();

// Redirect to login
header('Location: ../login_signup.php?logout=success');
exit;
?>