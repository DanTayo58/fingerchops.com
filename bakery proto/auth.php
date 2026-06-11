<?php
// auth.php - Authentication endpoint (Consolidated)
// Version: 6.1 (Fixed: Removed direct access block for API endpoints)

declare(strict_types=1);

// Allow requests from login page - no direct access block for API endpoints
// Only block direct file viewing, not API calls

session_start();

// Error reporting (production-safe)
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

$logFile = __DIR__ . '/logs/auth_debug.log';

/**
 * Write debug log (only in development mode)
 */
function writeAuthLog(string $message): void {
    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        $logDir = dirname(__FILE__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents(
            __DIR__ . '/logs/auth_debug.log',
            date('Y-m-d H:i:s') . " - " . $message . "\n",
            FILE_APPEND
        );
    }
}

writeAuthLog("=== NEW REQUEST ===");

try {
    writeAuthLog("Session started: " . session_id());

    // Load required files
    require_once __DIR__ . '/conn.php';
    require_once __DIR__ . '/includes/Auth.php';
    require_once __DIR__ . '/includes/User.php';
    require_once __DIR__ . '/includes/Helpers.php';
    require_once __DIR__ . '/includes/DashboardRouter.php';
    require_once __DIR__ . '/includes/Security.php';
    require_once __DIR__ . '/includes/Validation.php';
    require_once __DIR__ . '/config/config_loader.php';

    header('Content-Type: application/json');

    // Get action from request
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    writeAuthLog("Action: {$action}");

    // Route to appropriate handler
    match ($action) {
        'get_csrf_token' => handleGetCsrfToken(),
        'refresh_csrf' => handleRefreshCsrf(),
        'login' => handleLogin(),
        'logout' => handleLogout(),
        'check_session' => handleCheckSession(),
        'get_dashboard_info' => handleGetDashboardInfo(),
        'check_email' => handleCheckEmail(),
        'check_phone' => handleCheckPhone(),
        'check_username' => handleCheckUsername(),
        'change_password' => handleChangePassword(),
        'register' => handleRegister(),
        default => handleInvalidAction($action),
    };

} catch (Throwable $e) {
    $error = "EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    writeAuthLog($error);
    error_log($error);

    if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage(),
            'code' => 'SERVER_ERROR'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected error occurred. Please try again later.',
            'code' => 'SERVER_ERROR'
        ]);
    }
}

exit;

// =====================================================
// HANDLER FUNCTIONS
// =====================================================

/**
 * Handle CSRF token generation
 */
function handleGetCsrfToken(): void {
    $action = $_GET['action_type'] ?? $_POST['action_type'] ?? 'general';
    $token = generateCSRFToken($action);
    echo json_encode([
        'success' => true,
        'token' => $token,
        'action' => $action,
        'expires' => time() + 3600
    ]);
}

/**
 * Handle CSRF token refresh
 */
function handleRefreshCsrf(): void {
    $action = $_GET['action_type'] ?? $_POST['action_type'] ?? 'general';
    $token = generateCSRFToken($action);
    echo json_encode([
        'success' => true,
        'token' => $token,
        'action' => $action,
        'expires' => time() + 3600,
        'message' => 'CSRF token refreshed successfully'
    ]);
}

/**
 * Handle login request
 */
function handleLogin(): void {
    writeAuthLog("=== LOGIN ATTEMPT ===");

    requireCSRFToken('login', true);
    
    $ip = getClientIP();
    if (!checkRateLimit('login', $ip)) {
        echo json_encode([
            'success' => false,
            'message' => 'Too many login attempts. Please try again later.',
            'throttled' => true
        ]);
        return;
    }

    $username = trim($_POST['login_username'] ?? '');
    $password = $_POST['login_password'] ?? '';
    $remember = isset($_POST['remember_me']) ? (bool)$_POST['remember_me'] : false;

    if (empty($username) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'Username and password required'
        ]);
        return;
    }

    $auth = new Auth();
    $result = $auth->login($username, $password, $ip, $remember);

    if ($result['success']) {
        $userId = (int)($result['user']['id'] ?? 0);
        
        if ($userId === 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Login succeeded but user ID missing'
            ]);
            return;
        }

        $router = new DashboardRouter();
        $redirect = $router->getDashboard([
            'id' => $userId,
            'user_type' => $result['user']['user_type']
        ]);

        $displayInfo = null;
        if (method_exists($router, 'getUserDisplayInfo')) {
            $displayInfo = $router->getUserDisplayInfo($userId);
        }

        $response = [
            'success' => true,
            'redirect' => $redirect,
            'force_password_change' => $result['force_password_change'] ?? false,
            'message' => $result['message'] ?? 'Login successful',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'user_id' => $result['user']['user_id'] ?? null,
                'fullname' => $result['user']['fullname'] ?? '',
                'username' => $result['user']['username'] ?? '',
                'user_type' => $result['user']['user_type'] ?? '',
                'email' => $result['user']['email'] ?? '',
                'is_verified' => (bool)($result['user']['is_verified'] ?? false)
            ]
        ];
        
        if ($displayInfo) {
            $response['display_info'] = $displayInfo;
        }
        
        echo json_encode($response);
    } else {
        $response = [
            'success' => false,
            'message' => $result['message'] ?? 'Login failed'
        ];
        
        if (isset($result['locked'])) {
            $response['locked'] = $result['locked'];
        }
        if (isset($result['throttled'])) {
            $response['throttled'] = $result['throttled'];
        }
        if (isset($result['attempts_left'])) {
            $response['attempts_left'] = $result['attempts_left'];
        }
        
        echo json_encode($response);
    }
}

/**
 * Handle logout request
 */
function handleLogout(): void {
    writeAuthLog("=== LOGOUT ===");
    requireCSRFToken('logout', true);
    
    if (isset($_SESSION['user_id'])) {
        $auth = new Auth();
        $auth->logout();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully',
        'redirect' => '/login_signup.php'
    ]);
}

/**
 * Handle session check request
 */
function handleCheckSession(): void {
    writeAuthLog("=== CHECK SESSION ===");
    $auth = new Auth();
    $result = $auth->checkSessionStatus();
    echo json_encode($result);
}

/**
 * Handle dashboard info request
 */
function handleGetDashboardInfo(): void {
    writeAuthLog("=== GET DASHBOARD INFO ===");
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Not logged in',
            'code' => 'NOT_AUTHENTICATED'
        ]);
        return;
    }
    
    $router = new DashboardRouter();
    $displayInfo = $router->getUserDisplayInfo((int)$_SESSION['user_id']);
    
    echo json_encode([
        'success' => true,
        'display_info' => $displayInfo
    ]);
}

/**
 * Handle email uniqueness check
 */
function handleCheckEmail(): void {
    $email = trim($_GET['email'] ?? '');
    
    if (empty($email)) {
        echo json_encode(['exists' => false]);
        return;
    }
    
    $db = Database::getInstance();
    $row = $db->preparedFetchOne(
        "SELECT id FROM bakery_users WHERE email = ?",
        's',
        [$email]
    );
    
    echo json_encode(['exists' => !empty($row)]);
}

/**
 * Handle phone uniqueness check
 */
function handleCheckPhone(): void {
    $phone = trim($_GET['phone'] ?? '');
    
    if (empty($phone)) {
        echo json_encode(['exists' => false]);
        return;
    }
    
    $db = Database::getInstance();
    $fullPhone = '+234' . ltrim($phone, '+');
    
    $row = $db->preparedFetchOne(
        "SELECT id FROM bakery_users WHERE phone = ? OR phone = ?",
        'ss',
        [$phone, $fullPhone]
    );
    
    echo json_encode(['exists' => !empty($row)]);
}

/**
 * Handle username uniqueness check with suggestions
 */
function handleCheckUsername(): void {
    $username = trim($_GET['username'] ?? '');
    
    if (empty($username)) {
        echo json_encode(['exists' => false]);
        return;
    }
    
    $db = Database::getInstance();
    
    $row = $db->preparedFetchOne(
        "SELECT id FROM bakery_users WHERE username = ?",
        's',
        [$username]
    );
    
    $exists = !empty($row);
    $suggestions = [];
    
    if ($exists) {
        $base = preg_replace('/[0-9]+$/', '', $username);
        
        for ($i = 1; $i <= 3; $i++) {
            $suggestion = $base . $i;
            $checkRow = $db->preparedFetchOne(
                "SELECT id FROM bakery_users WHERE username = ?",
                's',
                [$suggestion]
            );
            if (!$checkRow) {
                $suggestions[] = $suggestion;
            }
        }
    }
    
    echo json_encode([
        'exists' => $exists,
        'suggestions' => $suggestions
    ]);
}

/**
 * Handle password change request
 */
function handleChangePassword(): void {
    writeAuthLog("=== PASSWORD CHANGE REQUEST ===");
    
    requireCSRFToken('force_change_password', true);
    
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    
    if ($userId === 0 || empty($currentPassword) || empty($newPassword)) {
        writeAuthLog("Missing fields: userId=$userId, currentPassword=" . (empty($currentPassword) ? 'empty' : 'provided') . ", newPassword=" . (empty($newPassword) ? 'empty' : 'provided'));
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields'
        ]);
        return;
    }
    
    writeAuthLog("Attempting password change for user ID: $userId");
    
    try {
        $auth = new Auth();
        $result = $auth->changePassword($userId, $currentPassword, $newPassword);
        
        if ($result['success']) {
            writeAuthLog("Password change successful for user ID: $userId");
        } else {
            writeAuthLog("Password change failed for user ID: $userId - " . ($result['message'] ?? 'Unknown error'));
        }
        
        echo json_encode($result);
    } catch (Throwable $e) {
        writeAuthLog("Exception in password change: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to change password. Please try again.',
            'debug' => ($e->getMessage() ?? 'Unknown error')
        ]);
    }
}

/**
 * Handle user registration
 */
function handleRegister(): void {
    writeAuthLog("=== REGISTRATION ATTEMPT ===");
    
    requireCSRFToken('register', true);
    
    $ip = getClientIP();
    if (!checkRateLimit('register', $ip)) {
        echo json_encode([
            'success' => false,
            'message' => 'Too many registration attempts. Please try again later.',
            'throttled' => true
        ]);
        return;
    }
    
    $userData = [
        'fullname' => trim($_POST['fullname'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'user_role' => $_POST['user_role'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'user_type' => $_POST['user_role'] ?? '',
        'is_verified' => 0
    ];
    
    // Validate user type
    $allowedTypes = ['customer', 'vendor'];
    if (!in_array($userData['user_type'], $allowedTypes, true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid account type selected'
        ]);
        return;
    }
    
    // Handle vendor-specific fields
    if ($userData['user_type'] === 'vendor') {
        $userData['wholesale_discount'] = (float)setting('default_wholesale_discount', 15);
        $userData['vendor_status'] = 'pending';
    }
    
    $user = new User();
    $result = $user->create($userData);
    
    if ($result['success']) {
        $newUser = $user->getData();
        $newUserId = (int)$result['user_id'];
        
        // Auto-login the new user
        $auth = new Auth();
        $autoLoginSuccess = $auth->autoLogin($newUserId);
        writeAuthLog("Register: autoLogin returned " . ($autoLoginSuccess ? 'true' : 'false'));
        
        $router = new DashboardRouter();
        $redirect = $router->getDashboard([
            'id' => $newUserId,
            'user_type' => $newUser['user_type']
        ]);
        writeAuthLog("Register: redirect = {$redirect}");
        
        // Get display info for success modal
        $displayInfo = $router->getUserDisplayInfo($newUserId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully!',
            'user_id' => $newUser['user_id'],
            'redirect' => $redirect,
            'display_info' => $displayInfo,
            'user' => [
                'id' => $newUserId,
                'fullname' => $newUser['fullname'],
                'user_type' => $newUser['user_type'],
                'email' => $newUser['email'],
                'is_verified' => (bool)($newUser['is_verified'] ?? false)
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'errors' => $result['errors']
        ]);
    }
}

/**
 * Handle invalid action
 */
function handleInvalidAction(string $action): void {
    writeAuthLog("Invalid action: {$action}");
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action',
        'code' => 'INVALID_ACTION'
    ]);
}