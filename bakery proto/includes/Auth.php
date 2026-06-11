<?php
// includes/Auth.php - Authentication class for PHP 8.3
// Version: 6.0 (PHP 8.3+ with prepared statements, fixed autoLogin bug, enhanced security)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

require_once dirname(__FILE__) . '/../conn.php';
require_once dirname(__FILE__) . '/Validation.php';
require_once dirname(__FILE__) . '/Security.php';
require_once dirname(__FILE__) . '/Helpers.php';
require_once dirname(__FILE__) . '/Mailer.php';
require_once dirname(__FILE__) . '/../config/config_loader.php';

/**
 * Authentication Class - User authentication and session management
 * 
 * @package Fingerchops
 * @version 6.0
 */
class Auth {
    private Database $db;
    private Validation $validator;
    private Mailer $mailer;
    private int $maxAttempts;
    private int $lockoutTime;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->validator = new Validation();
        $this->mailer = new Mailer();
        $this->maxAttempts = (int)setting('max_login_attempts', 5);
        $this->lockoutTime = (int)setting('lockout_duration', 15) * 60;
    }
    
    /**
     * Authenticate user login
     */
    public function login(string $username, string $password, string $ip, bool $rememberMe = false): array {
        // Check rate limiting
        if (!checkRateLimit('login', $ip)) {
            return [
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.',
                'throttled' => true
            ];
        }
        
        // Get user by username, email, or user_id
        $user = $this->getUserByLoginIdentifier($username);
        
        if ($user) {
            // Check if account is locked
            if ($this->isAccountLocked((int)$user['id'])) {
                $this->logLoginAttempt(null, $ip, false, $username);
                return [
                    'success' => false,
                    'message' => 'Account is temporarily locked. Please try again later.',
                    'locked' => true
                ];
            }
            
            // Verify password
            if (verifyPassword($password, $user['password_hash'])) {
                // Password correct - reset failed attempts
                $this->resetFailedAttempts((int)$user['id']);
                $this->logLoginAttempt((int)$user['id'], $ip, true);
                
                // Check if password needs to be changed
                $forceChange = (bool)($user['force_password_change'] ?? false);
                
                if (!$forceChange && (int)setting('force_password_change_days', 90) > 0) {
                    $lastChange = strtotime($user['last_password_change'] ?? 'now');
                    $daysSinceChange = (time() - $lastChange) / 86400;
                    if ($daysSinceChange > (int)setting('force_password_change_days', 90)) {
                        $forceChange = true;
                    }
                }
                
                // Check if password hash needs rehashing (upgrade from older algorithms)
                if (passwordNeedsRehash($user['password_hash'])) {
                    $this->upgradePasswordHash((int)$user['id'], $password);
                }
                
                // Create session
                $sessionId = $this->createSession((int)$user['id'], $ip, $rememberMe);
                
                // Update user's last login info
                $this->updateUserLoginInfo((int)$user['id'], $sessionId, $ip);
                
                // Set remember me cookie if requested
                if ($rememberMe) {
                    $this->setRememberMeToken((int)$user['id']);
                }
                
                // Log successful login
                logActivity((int)$user['id'], 'Logged in successfully', 'auth', (int)$user['id']);
                
                return [
                    'success' => true,
                    'user' => $user,
                    'force_password_change' => $forceChange,
                    'message' => 'Login successful'
                ];
            }
        }
        
        // Failed login
        $this->handleFailedLogin($username, $ip);
        
        return [
            'success' => false,
            'message' => 'Invalid username or password'
        ];
    }
    
    /**
     * Automatically log in a user after successful registration (FIXED)
     */
    public function autoLogin(int $userId): bool {
        error_log("=== autoLogin called for user {$userId} ===");
        $ip = getClientIP();
        error_log("autoLogin: IP = {$ip}");
        
        $sessionId = $this->createSession($userId, $ip, false);
        
        if ($sessionId !== false && $sessionId !== null) {
            error_log("autoLogin: session created with ID {$sessionId}");
            $this->updateUserLoginInfo($userId, $sessionId, $ip);
            
            // Set session variables for immediate use
            $_SESSION['user_id'] = $userId;
            $_SESSION['session_id'] = $sessionId;
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            $user = $this->getUserById($userId);
            if ($user) {
                $_SESSION['user_type'] = $user['user_type'];
            }
            
            error_log("autoLogin: SUCCESS - user {$userId} logged in");
            return true;
        }
        
        error_log("autoLogin: FAILED - session creation failed for user {$userId}");
        return false;
    }
    
    /**
     * Upgrade password hash to current algorithm
     */
    private function upgradePasswordHash(int $userId, string $plainPassword): void {
        $newHash = hashPassword($plainPassword);
        
        $this->db->preparedExecute(
            "UPDATE bakery_users SET password_hash = ? WHERE id = ?",
            'si',
            [$newHash, $userId]
        );
        
        logActivity($userId, 'Password hash upgraded', 'security', $userId);
    }
    
    /**
     * Get user by username, email, or user_id using prepared statement
     */
    private function getUserByLoginIdentifier(string $username): ?array {
        return $this->db->preparedFetchOne(
            "SELECT * FROM bakery_users 
             WHERE (username = ? OR email = ? OR user_id = ?) 
             AND is_active = 1",
            'sss',
            [$username, $username, $username]
        );
    }
    
    /**
     * Get the currently logged in user
     */
    public function getCurrentUser(): ?array {
        if (!$this->validateSession()) {
            return null;
        }
        return $this->getUserById((int)$_SESSION['user_id']);
    }
    
    /**
     * Get current user ID
     */
    public function getCurrentUserId(): ?int {
        if (!$this->validateSession()) {
            return null;
        }
        return (int)$_SESSION['user_id'];
    }
    
    /**
     * Set remember me token (hashed for security)
     */
    private function setRememberMeToken(int $userId): void {
        $token = generateSecureToken(64);
        $hashedToken = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + ((int)setting('remember_me_days', 30) * 86400));
        
        // Delete existing tokens for this user
        $this->db->preparedExecute(
            "DELETE FROM remember_me_tokens WHERE user_id = ?",
            'i',
            [$userId]
        );
        
        // Insert new token with hashed value
        $success = $this->db->preparedExecute(
            "INSERT INTO remember_me_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
            'iss',
            [$userId, $hashedToken, $expires]
        );
        
        if ($success) {
            $cookieValue = $userId . ':' . $token;
            $cookieExpiry = time() + ((int)setting('remember_me_days', 30) * 86400);
            $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            setcookie('remember_me', $cookieValue, $cookieExpiry, '/', '', $secure, true);
        }
    }
    
    /**
     * Attempt login via remember me cookie
     */
    public function loginViaRememberMe(): ?array {
        if (!isset($_COOKIE['remember_me'])) {
            return null;
        }
        
        $cookie = $_COOKIE['remember_me'];
        $parts = explode(':', $cookie);
        
        if (count($parts) !== 2) {
            return null;
        }
        
        $userId = (int)$parts[0];
        $token = $parts[1];
        $hashedToken = hash('sha256', $token);
        
        // Clean expired tokens
        $this->db->preparedExecute(
            "DELETE FROM remember_me_tokens WHERE expires_at < NOW()",
            '',
            []
        );
        
        // Check token
        $row = $this->db->preparedFetchOne(
            "SELECT * FROM remember_me_tokens 
             WHERE user_id = ? AND token = ? AND expires_at > NOW()",
            'is',
            [$userId, $hashedToken]
        );
        
        if ($row) {
            $user = $this->getUserById($userId);
            if ($user) {
                $ip = getClientIP();
                $sessionId = $this->createSession($userId, $ip, true);
                if ($sessionId) {
                    $this->updateUserLoginInfo($userId, $sessionId, $ip);
                    logActivity($userId, 'Logged in via remember me', 'auth', $userId);
                    // Refresh token
                    $this->setRememberMeToken($userId);
                    return $user;
                }
            }
        }
        
        // Invalid token - clear cookie
        setcookie('remember_me', '', time() - 3600, '/');
        return null;
    }
    
    /**
     * Handle failed login attempt
     */
    private function handleFailedLogin(string $username, string $ip): void {
        $this->logLoginAttempt(null, $ip, false, $username);
        
        $user = $this->getUserByLoginIdentifier($username);
        if ($user) {
            $userId = (int)$user['id'];
            
            // Increment failed attempts
            $this->db->preparedExecute(
                "UPDATE bakery_users 
                 SET failed_login_attempts = failed_login_attempts + 1,
                     last_failed_login = NOW()
                 WHERE id = ?",
                'i',
                [$userId]
            );
            
            // Get updated count
            $row = $this->db->preparedFetchOne(
                "SELECT failed_login_attempts FROM bakery_users WHERE id = ?",
                'i',
                [$userId]
            );
            
            if ($row && (int)$row['failed_login_attempts'] >= $this->maxAttempts) {
                $lockUntil = date('Y-m-d H:i:s', time() + $this->lockoutTime);
                $this->db->preparedExecute(
                    "UPDATE bakery_users SET account_locked_until = ? WHERE id = ?",
                    'si',
                    [$lockUntil, $userId]
                );
                
                logActivity($userId, 'Account locked due to failed attempts', 'security', $userId);
                
                if ((bool)setting('notify_on_account_lock', true)) {
                    $this->mailer->sendAccountLocked(
                        $user['email'],
                        $user['fullname'],
                        $lockUntil
                    );
                }
            }
        }
    }
    
    /**
     * Check if account is locked
     */
    private function isAccountLocked(int $userId): bool {
        $row = $this->db->preparedFetchOne(
            "SELECT account_locked_until FROM bakery_users 
             WHERE id = ? AND account_locked_until > NOW()",
            'i',
            [$userId]
        );
        return !empty($row);
    }
    
    /**
     * Reset failed login attempts
     */
    private function resetFailedAttempts(int $userId): void {
        $this->db->preparedExecute(
            "UPDATE bakery_users 
             SET failed_login_attempts = 0,
                 account_locked_until = NULL
             WHERE id = ?",
            'i',
            [$userId]
        );
    }
    
    /**
     * Log login attempt
     */
    private function logLoginAttempt(?int $userId, string $ip, bool $success, ?string $username = null): void {
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        
        $this->db->preparedExecute(
            "INSERT INTO login_attempts (ip_address, username, user_id, success, user_agent)
             VALUES (?, ?, ?, ?, ?)",
            'ssiis',
            [$ip, $username, $userId, (int)$success, $userAgent]
        );
        
        if ($userId !== null) {
            $actionType = $success ? 'LOGIN' : 'FAILED_LOGIN';
            $this->db->preparedExecute(
                "INSERT INTO audit_trail (user_id, action_type, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                'isss',
                [$userId, $actionType, $ip, $userAgent]
            );
        }
    }
    
    /**
     * Create user session with session regeneration
     */
    private function createSession(int $userId, string $ip, bool $rememberMe = false): string|false {
        $sessionId = generateSecureToken(64);
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $loginTime = date('Y-m-d H:i:s');
        
        if ($rememberMe) {
            $expires = date('Y-m-d H:i:s', time() + ((int)setting('remember_me_days', 30) * 86400));
        } else {
            $expires = date('Y-m-d H:i:s', time() + (int)setting('session_lifetime', 28800));
        }
        
        // Regenerate PHP session ID to prevent session fixation
        session_regenerate_id(true);
        
        // Deactivate old sessions
        $this->db->preparedExecute(
            "UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1",
            'i',
            [$userId]
        );
        
        // Insert new session
        $success = $this->db->preparedExecute(
            "INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, 
                  login_time, last_activity, expires_at, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
            'issssss',
            [$userId, $sessionId, $ip, $userAgent, $loginTime, $loginTime, $expires]
        );
        
        if (!$success) {
            error_log("Failed to create session: " . ($this->db->getConnection()->error ?? 'Unknown error'));
            return false;
        }
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_id'] = $sessionId;
        $_SESSION['login_time'] = time();
        $_SESSION['remember_me'] = $rememberMe;
        $_SESSION['last_activity'] = time();
        
        $user = $this->getUserById($userId);
        if ($user) {
            $_SESSION['user_type'] = $user['user_type'];
        }
        
        return $sessionId;
    }
    
    /**
     * Update user login information
     */
    private function updateUserLoginInfo(int $userId, string $sessionId, string $ip): void {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $this->db->preparedExecute(
            "UPDATE bakery_users 
             SET last_login = NOW(),
                 last_session_id = ?,
                 last_ip_address = ?,
                 last_user_agent = ?,
                 failed_login_attempts = 0
             WHERE id = ?",
            'sssi',
            [$sessionId, $ip, $userAgent, $userId]
        );
    }
    
    /**
     * Validate current session
     */
    public function validateSession(): bool {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            return false;
        }
        
        $userId = (int)$_SESSION['user_id'];
        $sessionId = $_SESSION['session_id'];
        $ip = getClientIP();
        
        // Clean expired sessions
        $this->db->preparedExecute(
            "UPDATE user_sessions SET is_active = 0 WHERE expires_at < NOW()",
            '',
            []
        );
        
        // Fetch active session
        $session = $this->db->preparedFetchOne(
            "SELECT * FROM user_sessions 
             WHERE session_id = ? AND user_id = ? 
             AND is_active = 1 AND expires_at > NOW()",
            'si',
            [$sessionId, $userId]
        );
        
        if (!$session) {
            $this->logout();
            return false;
        }
        
        // Check IP if strict mode is enabled
        if ((bool)setting('session_strict_ip', false) && $session['ip_address'] !== $ip) {
            $this->logout();
            return false;
        }
        
        // Update last activity
        $this->db->preparedExecute(
            "UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?",
            's',
            [$sessionId]
        );
        
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Check session status and return JSON for AJAX calls
     */
    public function checkSessionStatus(): array {
        $timeout = (int)setting('session_inactivity_timeout', 1800);
        
        $response = [
            'valid' => false,
            'authenticated' => false,
            'timestamp' => time(),
            'timeout' => $timeout
        ];
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            $user = $this->loginViaRememberMe();
            if ($user) {
                $response['valid'] = true;
                $response['authenticated'] = true;
                $response['user_id'] = $user['id'];
                $response['user_type'] = $user['user_type'];
                $response['via_remember'] = true;
                $response['message'] = 'Logged in via remember me';
                return $response;
            }
            $response['message'] = 'No active session';
            return $response;
        }
        
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
            $response['valid'] = true;
            $response['authenticated'] = true;
            $response['user_id'] = $_SESSION['user_id'];
            $response['user_type'] = $_SESSION['user_type'] ?? null;
            $response['message'] = 'Session initialized';
            return $response;
        }
        
        $elapsed = time() - (int)$_SESSION['last_activity'];
        
        if ($elapsed < $timeout) {
            $response['valid'] = true;
            $response['authenticated'] = true;
            $response['user_id'] = $_SESSION['user_id'];
            $response['user_type'] = $_SESSION['user_type'] ?? null;
            $response['time_remaining'] = $timeout - $elapsed;
            
            $_SESSION['last_activity'] = time();
            
            if (isset($_SESSION['session_id'])) {
                $this->db->preparedExecute(
                    "UPDATE user_sessions SET last_activity = NOW() WHERE session_id = ?",
                    's',
                    [$_SESSION['session_id']]
                );
            }
        } else {
            $userId = (int)$_SESSION['user_id'];
            $ip = getClientIP();
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            
            $this->db->preparedExecute(
                "INSERT INTO audit_trail (user_id, action_type, ip_address, user_agent, created_at)
                 VALUES (?, 'SESSION_TIMEOUT', ?, ?, NOW())",
                'iss',
                [$userId, $ip, $userAgent]
            );
            
            logActivity($userId, 'Session expired due to inactivity', 'auth', $userId);
            $this->logout();
            $response['message'] = 'Session expired due to inactivity';
            $response['valid'] = false;
        }
        
        return $response;
    }
    
    /**
     * Get session info
     */
    public function getSessionInfo(?string $sessionId = null): ?array {
        if ($sessionId === null && isset($_SESSION['session_id'])) {
            $sessionId = $_SESSION['session_id'];
        }
        if (!$sessionId) {
            return null;
        }
        return $this->db->preparedFetchOne(
            "SELECT * FROM user_sessions WHERE session_id = ?",
            's',
            [$sessionId]
        );
    }
    
    /**
     * Logout user
     */
    public function logout(): void {
        if (isset($_SESSION['user_id']) && isset($_SESSION['session_id'])) {
            $userId = (int)$_SESSION['user_id'];
            $sessionId = $_SESSION['session_id'];
            
            $this->db->preparedExecute(
                "UPDATE user_sessions SET is_active = 0 WHERE session_id = ?",
                's',
                [$sessionId]
            );
            
            logActivity($userId, 'Logged out', 'auth', $userId);
            
            $ip = getClientIP();
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $this->db->preparedExecute(
                "INSERT INTO audit_trail (user_id, action_type, ip_address, user_agent)
                 VALUES (?, 'LOGOUT', ?, ?)",
                'iss',
                [$userId, $ip, $userAgent]
            );
        }
        
        // Clear remember me cookie
        if (isset($_COOKIE['remember_me'])) {
            setcookie('remember_me', '', time() - 3600, '/');
        }
        
        // Clear session
        $_SESSION = [];
        
        if (session_id()) {
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, '/');
            }
            session_destroy();
        }
    }
    
    /**
     * Change user password
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        if (!verifyPassword($currentPassword, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        if (!$this->validator->validatePassword($newPassword)) {
            return ['success' => false, 'errors' => $this->validator->getErrors()];
        }
        
        // Check password history
        $history = $this->db->preparedFetchAll(
            "SELECT password_hash FROM password_history 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            'ii',
            [$userId, (int)setting('password_history_count', 5)]
        );
        
        foreach ($history as $row) {
            if (verifyPassword($newPassword, $row['password_hash'])) {
                return ['success' => false, 'message' => 'You cannot reuse a recent password'];
            }
        }
        
        $newHash = hashPassword($newPassword);
        
        $this->db->beginTransaction();
        
        try {
            // Update user
            $success = $this->db->preparedExecute(
                "UPDATE bakery_users 
                 SET password_hash = ?, last_password_change = NOW(), force_password_change = 0
                 WHERE id = ?",
                'si',
                [$newHash, $userId]
            );
            
            if (!$success) {
                throw new Exception('Failed to update password');
            }
            
            // Add to history
            $success = $this->db->preparedExecute(
                "INSERT INTO password_history (user_id, password_hash, password_salt, created_at)
                 VALUES (?, ?, ?, NOW())",
                'iss',
                [$userId, $newHash, '']
            );
            
            if (!$success) {
                throw new Exception('Failed to update password history');
            }
            
            // Terminate all other sessions
            if (isset($_SESSION['session_id'])) {
                $this->db->preparedExecute(
                    "UPDATE user_sessions SET is_active = 0 
                     WHERE user_id = ? AND session_id != ?",
                    'is',
                    [$userId, $_SESSION['session_id']]
                );
            }
            
            logActivity($userId, 'Password changed', 'security', $userId);
            
            $this->mailer->send(
                ['email' => $user['email'], 'name' => $user['fullname']],
                'Your password has been changed',
                '<p>Your Fingerchops Ventures account password was successfully changed.</p>
                 <p>If you did not make this change, please contact support immediately.</p>'
            );
            
            $this->db->commit();
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Password change error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to change password. Please try again.'];
        }
    }
    
    /**
     * Initiate password reset
     */
    public function initiatePasswordReset(string $email): array {
        $cleanEmail = trim($email);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Please enter a valid email address'
            ];
        }
        
        $ip = getClientIP();
        if (!checkRateLimit('request_password_reset', $ip)) {
            return [
                'success' => false,
                'message' => 'Too many reset requests. Please try again later.',
                'throttled' => true
            ];
        }
        
        $user = $this->getUserByEmail($cleanEmail);
        if (!$user) {
            logActivity(null, 'Password reset requested for non-existent email: ' . $email, 'security');
            return [
                'success' => true,
                'message' => 'If the email exists in our system, a reset link has been sent.'
            ];
        }
        
        $token = generateSecureToken(32);
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Clear old tokens
        $this->db->preparedExecute(
            "UPDATE bakery_users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?",
            'i',
            [$user['id']]
        );
        
        // Set new token
        $success = $this->db->preparedExecute(
            "UPDATE bakery_users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?",
            'ssi',
            [$token, $expires, $user['id']]
        );
        
        if ($success) {
            logActivity($user['id'], 'Password reset initiated', 'security', $user['id']);
            $emailSent = $this->mailer->sendPasswordReset($user['email'], $user['fullname'], $token);
            
            if (!$emailSent) {
                error_log("Failed to send password reset email to: " . $user['email']);
            }
            
            return [
                'success' => true,
                'message' => 'Password reset link has been sent to your email.',
                'debug_token' => defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE ? $token : null
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to initiate password reset. Please try again.'];
    }
    
    /**
     * Reset password with token
     */
    public function resetPassword(string $token, string $newPassword): array {
        // Clean expired tokens
        $this->db->preparedExecute(
            "UPDATE bakery_users SET password_reset_token = NULL, password_reset_expires = NULL
             WHERE password_reset_expires < NOW()",
            '',
            []
        );
        
        // Find user with valid token
        $user = $this->db->preparedFetchOne(
            "SELECT id, email, fullname FROM bakery_users 
             WHERE password_reset_token = ? AND password_reset_expires > NOW()
             AND is_active = 1 LIMIT 1",
            's',
            [$token]
        );
        
        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid or expired reset token. Please request a new password reset link.'
            ];
        }
        
        if (!$this->validator->validatePassword($newPassword)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }
        
        // Check password history
        $history = $this->db->preparedFetchAll(
            "SELECT password_hash FROM password_history 
             WHERE user_id = ? 
             ORDER BY created_at DESC 
             LIMIT ?",
            'ii',
            [$user['id'], (int)setting('password_history_count', 5)]
        );
        
        foreach ($history as $row) {
            if (verifyPassword($newPassword, $row['password_hash'])) {
                return [
                    'success' => false,
                    'message' => 'You cannot reuse any of your last ' . setting('password_history_count', 5) . ' passwords.'
                ];
            }
        }
        
        $newHash = hashPassword($newPassword);
        
        $this->db->beginTransaction();
        
        try {
            // Update user
            $success = $this->db->preparedExecute(
                "UPDATE bakery_users 
                 SET password_hash = ?,
                     password_reset_token = NULL, password_reset_expires = NULL,
                     last_password_change = NOW(), force_password_change = 0
                 WHERE id = ?",
                'si',
                [$newHash, $user['id']]
            );
            
            if (!$success) {
                throw new Exception('Failed to update password');
            }
            
            // Add to history
            $success = $this->db->preparedExecute(
                "INSERT INTO password_history (user_id, password_hash, password_salt, created_at)
                 VALUES (?, ?, ?, NOW())",
                'iss',
                [$user['id'], $newHash, '']
            );
            
            if (!$success) {
                throw new Exception('Failed to update password history');
            }
            
            // Terminate all sessions
            $this->db->preparedExecute(
                "UPDATE user_sessions SET is_active = 0 WHERE user_id = ?",
                'i',
                [$user['id']]
            );
            
            logActivity($user['id'], 'Password reset completed', 'security', $user['id']);
            
            $this->mailer->send(
                ['email' => $user['email'], 'name' => $user['fullname']],
                'Your password has been reset',
                '<p>Your Fingerchops Ventures account password was successfully reset.</p>
                 <p>If you did not perform this action, please contact support immediately.</p>'
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Password reset successful. You can now log in with your new password.'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Password reset error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while resetting your password. Please try again.'
            ];
        }
    }
    
    /**
     * Validate reset token
     */
    public function validateResetToken(string $token): array {
        $user = $this->db->preparedFetchOne(
            "SELECT id, email FROM bakery_users 
             WHERE password_reset_token = ? AND password_reset_expires > NOW()
             AND is_active = 1 LIMIT 1",
            's',
            [$token]
        );
        
        if ($user) {
            return ['valid' => true, 'user_id' => $user['id'], 'email' => $user['email']];
        }
        return ['valid' => false];
    }
    
    /**
     * Get user by ID
     */
    public function getUserById(int $userId): ?array {
        return $this->db->preparedFetchOne(
            "SELECT * FROM bakery_users WHERE id = ?",
            'i',
            [$userId]
        );
    }
    
    /**
     * Get user by email
     */
    public function getUserByEmail(string $email): ?array {
        return $this->db->preparedFetchOne(
            "SELECT * FROM bakery_users WHERE email = ? AND is_active = 1",
            's',
            [$email]
        );
    }
    
    /**
     * Check if user has permission
     */
    public function hasPermission(?int $userId, string $permission): bool {
        if (!$userId) {
            return false;
        }
        
        $row = $this->db->preparedFetchOne(
            "SELECT r.{$permission}
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.is_active = 1
             LIMIT 1",
            'i',
            [$userId]
        );
        
        if ($row) {
            return isset($row[$permission]) && (bool)$row[$permission];
        }
        return false;
    }
    
    /**
     * Require user to be logged in
     */
    public function requireLogin(): void {
        if (!$this->validateSession()) {
            redirect('/login_signup.php', 'Please log in to continue', 'warning');
        }
    }
    
    /**
     * Require specific permission
     */
    public function requirePermission(string $permission): void {
        $this->requireLogin();
        if (!$this->hasPermission((int)$_SESSION['user_id'], $permission)) {
            redirect('/dashboards/customer-dashboard.php', 'You do not have permission to access that page', 'error');
        }
    }
    
    /**
     * Get all active sessions for a user
     */
    public function getUserSessions(int $userId): array {
        return $this->db->preparedFetchAll(
            "SELECT * FROM user_sessions 
             WHERE user_id = ? AND is_active = 1 
             ORDER BY last_activity DESC",
            'i',
            [$userId]
        );
    }
    
    /**
     * Terminate a specific session
     */
    public function terminateSession(string $sessionId, ?int $userId = null): bool {
        $sql = "UPDATE user_sessions SET is_active = 0 WHERE session_id = ?";
        $params = [$sessionId];
        $types = 's';
        
        if ($userId !== null) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
            $types .= 'i';
        }
        
        return $this->db->preparedExecute($sql, $types, $params);
    }
    
    /**
     * Terminate all other sessions for current user
     */
    public function terminateOtherSessions(): bool {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            return false;
        }
        
        $userId = (int)$_SESSION['user_id'];
        $currentSession = $_SESSION['session_id'];
        
        return $this->db->preparedExecute(
            "UPDATE user_sessions SET is_active = 0 
             WHERE user_id = ? AND session_id != ?",
            'is',
            [$userId, $currentSession]
        );
    }
}