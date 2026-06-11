<?php
// includes/Security.php - Enhanced security functions for PHP 8.3
// Version: 4.0 (PHP 8.3+ with improved security, CSRF protection, and rate limiting)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

// Define BCRYPT_COST if not already defined
if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 12); // Increased from 10 for better security
}

// =====================================================
// UUID GENERATION (Primary implementation)
// =====================================================

if (!function_exists('generateUUID')) {
    function generateUUID(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

// =====================================================
// RANDOM STRING GENERATION (Cryptographically Secure)
// =====================================================

if (!function_exists('generateRandomString')) {
    function generateRandomString(int $length = 32, string $type = 'alnum'): string {
        $characters = match ($type) {
            'alnum' => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'alpha' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'numeric' => '0123456789',
            'hex' => '0123456789abcdef',
            default => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
        };
        
        $charLength = strlen($characters);
        $randomString = '';
        
        try {
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[random_int(0, $charLength - 1)];
            }
        } catch (Exception $e) {
            // Fallback to less secure method if random_int fails
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[mt_rand(0, $charLength - 1)];
            }
        }
        
        return $randomString;
    }
}

// =====================================================
// SECURE TOKEN GENERATION
// =====================================================

if (!function_exists('generateSecureToken')) {
    function generateSecureToken(int $length = 32): string {
        try {
            return bin2hex(random_bytes($length));
        } catch (Exception $e) {
            return hash('sha256', uniqid((string)mt_rand(), true) . microtime() . session_id());
        }
    }
}

// =====================================================
// CSRF TOKEN MANAGEMENT (CONSOLIDATED - SINGLE SOURCE)
// =====================================================

if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(string $action = 'default'): string {
        $db = Database::getInstance();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = getClientIP();
        
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = hash('sha256', uniqid((string)mt_rand(), true) . microtime() . session_id());
        }
        
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        
        // Clean up expired tokens
        $db->preparedExecute(
            "DELETE FROM csrf_tokens WHERE expires_at < NOW()",
            '',
            []
        );
        
        // Delete existing tokens for this user/action
        if ($userId !== null) {
            $db->preparedExecute(
                "DELETE FROM csrf_tokens WHERE user_id = ? AND action = ?",
                'is',
                [(int)$userId, $action]
            );
            
            $db->preparedExecute(
                "INSERT INTO csrf_tokens (user_id, token, action, expires_at) VALUES (?, ?, ?, ?)",
                'isss',
                [(int)$userId, $token, $action, $expiresAt]
            );
        } else {
            $db->preparedExecute(
                "DELETE FROM csrf_tokens WHERE ip_address = ? AND action = ? AND user_id IS NULL",
                'ss',
                [$ip, $action]
            );
            
            $db->preparedExecute(
                "INSERT INTO csrf_tokens (user_id, token, action, expires_at, ip_address) 
                 VALUES (NULL, ?, ?, ?, ?)",
                'ssss',
                [$token, $action, $expiresAt, $ip]
            );
        }
        
        // Store in session for quick access
        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        $_SESSION['csrf_tokens'][$action] = [
            'token' => $token,
            'expires' => time() + 3600
        ];
        
        return $token;
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken(?string $token, string $action, bool $markUsed = true): bool {
        if (empty($token) || empty($action)) {
            return false;
        }
        
        $db = Database::getInstance();
        
        // Clean expired tokens
        $db->preparedExecute("DELETE FROM csrf_tokens WHERE expires_at < NOW()", '', []);
        
        // Build query based on login status
        if (isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
            $row = $db->preparedFetchOne(
                "SELECT id FROM csrf_tokens 
                 WHERE token = ? AND action = ? AND user_id = ? 
                 AND expires_at > NOW() AND is_used = 0",
                'ssi',
                [$token, $action, $userId]
            );
        } else {
            $ip = getClientIP();
            $row = $db->preparedFetchOne(
                "SELECT id FROM csrf_tokens 
                 WHERE token = ? AND action = ? AND ip_address = ?
                 AND user_id IS NULL AND expires_at > NOW() AND is_used = 0",
                'sss',
                [$token, $action, $ip]
            );
        }
        
        if ($row) {
            if ($markUsed) {
                $db->preparedExecute(
                    "UPDATE csrf_tokens SET is_used = 1 WHERE id = ?",
                    'i',
                    [(int)$row['id']]
                );
            }
            
            // Clear from session cache
            if (isset($_SESSION['csrf_tokens'][$action]) && 
                $_SESSION['csrf_tokens'][$action]['token'] === $token) {
                unset($_SESSION['csrf_tokens'][$action]);
            }
            
            return true;
        }
        
        // Session fallback (for backward compatibility)
        if (isset($_SESSION['csrf_tokens'][$action]) && 
            $_SESSION['csrf_tokens'][$action]['token'] === $token &&
            $_SESSION['csrf_tokens'][$action]['expires'] > time()) {
            if ($markUsed) {
                unset($_SESSION['csrf_tokens'][$action]);
            }
            return true;
        }
        
        return false;
    }
}

if (!function_exists('getCSRFTokenField')) {
    function getCSRFTokenField(string $action): string {
        $token = generateCSRFToken($action);
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">' . "\n" .
               '<input type="hidden" name="csrf_action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('requireCSRFToken')) {
    function requireCSRFToken(string $action, bool $isAjax = false): void {
        $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!verifyCSRFToken($token, $action)) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid or expired security token. Please refresh the page and try again.',
                    'code' => 'INVALID_CSRF_TOKEN'
                ]);
                exit;
            } else {
                http_response_code(403);
                die('Invalid security token. Please refresh the page and try again.');
            }
        }
    }
}

// =====================================================
// PASSWORD HASHING (Centralized with bcrypt)
// =====================================================

if (!function_exists('generateSalt')) {
    function generateSalt(): string {
        return password_hash(generateRandomString(22), PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }
}

if (!function_exists('hashPassword')) {
    function hashPassword(string $password, ?string $salt = null): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword(string $password, string $hash): bool {
        if (empty($password) || empty($hash)) {
            return false;
        }
        
        // Handle legacy crypt() hashes (for backward compatibility)
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$')) {
            return password_verify($password, $hash);
        }
        
        // Legacy MD5/SHA1 fallback (should be removed after migration)
        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            return md5($password) === $hash;
        }
        if (strlen($hash) === 40 && ctype_xdigit($hash)) {
            return sha1($password) === $hash;
        }
        
        return false;
    }
}

if (!function_exists('passwordNeedsRehash')) {
    function passwordNeedsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }
}

// =====================================================
// IP ADDRESS HELPERS (with proxy support)
// =====================================================

if (!function_exists('getClientIP')) {
    function getClientIP(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // Trusted proxy detection
        $trustedProxies = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        $isTrustedProxy = false;
        
        foreach ($trustedProxies as $proxy) {
            if (str_contains($proxy, '/')) {
                // CIDR matching - simplified
                if (str_starts_with($ip, rtrim($proxy, '/*'))) {
                    $isTrustedProxy = true;
                    break;
                }
            } elseif ($ip === $proxy) {
                $isTrustedProxy = true;
                break;
            }
        }
        
        if ($isTrustedProxy) {
            $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
            if ($forwardedFor) {
                $ips = array_map('trim', explode(',', $forwardedFor));
                $ip = $ips[0];
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
            }
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ?: $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// =====================================================
// RATE LIMITING (with sliding window)
// =====================================================

if (!function_exists('checkRateLimit')) {
    function checkRateLimit(string $action, string $ip, ?int $userId = null): bool {
        $db = Database::getInstance();
        
        $limits = [
            'login' => 10,
            'register' => 5,
            'request_password_reset' => 3,
            'reset_password' => 5,
            'api' => 60,
            'contact_form' => 3,
            'checkout' => 10,
        ];
        
        $maxRequests = $limits[$action] ?? 10;
        $timeWindow = 300; // 5 minutes
        
        // Clean old entries
        $db->preparedExecute(
            "DELETE FROM rate_limits WHERE last_request < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            'i',
            [$timeWindow]
        );
        
        // Check current count
        $row = $db->preparedFetchOne(
            "SELECT request_count, first_request FROM rate_limits 
             WHERE ip_address = ? AND endpoint = ?",
            'ss',
            [$ip, $action]
        );
        
        if ($row) {
            // Check if still within window
            $firstRequestTime = strtotime($row['first_request']);
            $timeElapsed = time() - $firstRequestTime;
            
            if ($timeElapsed > $timeWindow) {
                // Reset window
                $db->preparedExecute(
                    "UPDATE rate_limits SET request_count = 1, first_request = NOW(), last_request = NOW()
                     WHERE ip_address = ? AND endpoint = ?",
                    'ss',
                    [$ip, $action]
                );
                return true;
            }
            
            if ($row['request_count'] >= $maxRequests) {
                return false;
            }
            
            // Increment
            $db->preparedExecute(
                "UPDATE rate_limits SET request_count = request_count + 1, last_request = NOW()
                 WHERE ip_address = ? AND endpoint = ?",
                'ss',
                [$ip, $action]
            );
        } else {
            // Insert new
            $db->preparedExecute(
                "INSERT INTO rate_limits (ip_address, endpoint, request_count, first_request, last_request)
                 VALUES (?, ?, 1, NOW(), NOW())",
                'ss',
                [$ip, $action]
            );
        }
        
        return true;
    }
}

// =====================================================
// ACTIVITY LOGGING
// =====================================================

if (!function_exists('logActivity')) {
    function logActivity(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        mixed $oldData = null,
        mixed $newData = null
    ): bool {
        $db = Database::getInstance();
        
        $oldDataJson = $oldData !== null ? (is_string($oldData) ? $oldData : json_encode($oldData)) : null;
        $newDataJson = $newData !== null ? (is_string($newData) ? $newData : json_encode($newData)) : null;
        $ip = getClientIP();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        
        return $db->preparedExecute(
            "INSERT INTO activity_logs 
             (user_id, action, entity_type, entity_id, old_data, new_data, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            'issiisss',
            [$userId, $action, $entityType, $entityId, $oldDataJson, $newDataJson, $ip, $userAgent]
        );
    }
}

// =====================================================
// SANITIZATION (XSS Prevention)
// =====================================================

if (!function_exists('sanitizeOutput')) {
    function sanitizeOutput(string $string, int $flags = ENT_QUOTES | ENT_HTML5): string {
        return htmlspecialchars($string, $flags, 'UTF-8');
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput(string $string): string {
        return trim(strip_tags($string));
    }
}

// =====================================================
// ENCRYPTION HELPERS (AES-256-CBC)
// =====================================================

if (!function_exists('encryptData')) {
    function encryptData(string $data, ?string $key = null): string {
        $key ??= defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'default-key-change-this-in-production';
        
        $method = 'AES-256-CBC';
        $ivLength = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($data, $method, $key, 0, $iv);
        
        return base64_encode($iv . $encrypted);
    }
}

if (!function_exists('decryptData')) {
    function decryptData(string $data, ?string $key = null): string|false {
        $key ??= defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 'default-key-change-this-in-production';
        
        $data = base64_decode($data);
        $method = 'AES-256-CBC';
        $ivLength = openssl_cipher_iv_length($method);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        
        return openssl_decrypt($encrypted, $method, $key, 0, $iv);
    }
}

// =====================================================
// REQUEST HELPERS
// =====================================================

if (!function_exists('isAjax')) {
    function isAjax(): bool {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('getJsonInput')) {
    function getJsonInput(): array {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
}

// =====================================================
// CSRF TOKEN REFRESH ENDPOINT HANDLER
// =====================================================

if (!function_exists('handleRefreshCsrf')) {
    function handleRefreshCsrf(): void {
        header('Content-Type: application/json');
        
        $action = $_GET['action'] ?? $_POST['action'] ?? 'general';
        $token = generateCSRFToken($action);
        
        echo json_encode([
            'success' => true,
            'token' => $token,
            'action' => $action,
            'expires' => time() + 3600
        ]);
        exit;
    }
}

// =====================================================
// REDIRECT HELPER
// =====================================================

if (!function_exists('redirect')) {
    /**
     * Redirect to a URL with an optional flash message.
     *
     * @param string $url     Destination URL (absolute path or full URL)
     * @param string $message Optional message to flash to the user
     * @param string $type    Message type: 'info' | 'success' | 'warning' | 'error'
     */
    function redirect(string $url, string $message = '', string $type = 'info'): never {
        // Store flash message in session if provided
        if ($message !== '' && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['flash'] = [
                'message' => $message,
                'type'    => $type,
                'time'    => time(),
            ];
        }

        // Prevent header injection — strip newlines from URL
        $url = preg_replace('/[\r\n]/', '', $url);

        // If headers already sent (e.g. during development with display_errors on),
        // fall back to a meta-refresh so the redirect still works.
        if (headers_sent()) {
            echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
            echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        } else {
            header('Location: ' . $url, true, 302);
        }

        exit;
    }
}