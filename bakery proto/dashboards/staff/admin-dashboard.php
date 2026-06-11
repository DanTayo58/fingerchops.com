<?php
// =====================================================
// FILE: dashboards/staff/admin-dashboard.php
// PURPOSE: Supreme Administrator Dashboard - Complete
// VERSION: 7.1 - Fixed empty budget requests handling
// =====================================================

// Get root path - from dashboards/staff/ to root is 3 levels up
$root_path = dirname(__DIR__, 2);

// Production-safe error reporting
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

session_start();

// Load required files
require_once $root_path . '/conn.php';
require_once $root_path . '/includes/User.php';
require_once $root_path . '/includes/Security.php';
require_once $root_path . '/config/config_loader.php';

$db = Database::getInstance();

// Security check
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $root_path . '/login_signup.php');
    exit;
}

$userObj = new User($_SESSION['user_id']);
$user = $userObj->getData();

if (!$user) {
    header('Location: ' . $root_path . '/login_signup.php');
    exit;
}

// Check privilege level
$minAdminLevel = setting('admin_privilege_level', 100);
$privilege_level = $userObj->getPrivilegeLevel();
if ($privilege_level < $minAdminLevel) {
    header('Location: ' . $root_path . '/login_signup.php');
    exit;
}

// Get user details
$roleDetails = $userObj->getRoles();
$userRole = !empty($roleDetails) ? $roleDetails[0]['role_name'] : 'Administrator';
$userRoleCode = !empty($roleDetails) ? $roleDetails[0]['role_code'] : 'ADMIN';

$branch = $db->preparedFetchOne("SELECT branch_name, branch_code FROM branches WHERE id = ?", 'i', [$user['branch_id'] ?? 1]);
$branchName = $branch['branch_name'] ?? 'Headquarters';
$branchCode = $branch['branch_code'] ?? 'HQ';

$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role'] = $userRole;
$_SESSION['branch'] = $branchName;

// Check if user has accounting role
$is_accounting = false;
$user_roles = $userObj->getRoles();
foreach ($user_roles as $role) {
    if (stripos($role['role_name'], 'accountant') !== false || $role['role_code'] === 'ACCOUNTANT') {
        $is_accounting = true;
        break;
    }
}

// =====================================================
// AJAX HANDLER
// =====================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    header('Content-Type: application/json');
    
    $token = $_POST['csrf_token'] ?? '';
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($token, 'admin_action', false)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    // Approve Vendor
    if (isset($_POST['approve_vendor'])) {
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        if ($vendor_id <= 0) {
            $response['message'] = 'Invalid vendor ID';
            echo json_encode($response);
            exit;
        }
        $db->preparedExecute("UPDATE bakery_users SET vendor_status = 'active' WHERE id = ? AND user_type = 'vendor'", 'i', [$vendor_id]);
        if ($db->affectedRows() > 0) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Approved vendor ID $vendor_id", 'vendor', $vendor_id);
            }
            $response = ['success' => true, 'message' => 'Vendor approved successfully'];
        } else {
            $response['message'] = 'Vendor not found or already approved';
        }
        echo json_encode($response);
        exit;
    }
    
    // Reject Vendor
    if (isset($_POST['reject_vendor'])) {
        $vendor_id = (int)($_POST['vendor_id'] ?? 0);
        if ($vendor_id <= 0) {
            $response['message'] = 'Invalid vendor ID';
            echo json_encode($response);
            exit;
        }
        $db->preparedExecute("UPDATE bakery_users SET vendor_status = 'inactive' WHERE id = ? AND user_type = 'vendor'", 'i', [$vendor_id]);
        if ($db->affectedRows() > 0) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Rejected vendor ID $vendor_id", 'vendor', $vendor_id);
            }
            $response = ['success' => true, 'message' => 'Vendor rejected'];
        } else {
            $response['message'] = 'Vendor not found';
        }
        echo json_encode($response);
        exit;
    }
    
    // Approve Budget (Admin)
    if (isset($_POST['approve_budget_admin'])) {
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($budget_id <= 0) {
            $response['message'] = 'Invalid budget ID';
            echo json_encode($response);
            exit;
        }
        
        $budget = $db->preparedFetchOne("SELECT id, purchase_id, admin_status, accounting_status FROM budget_requests WHERE id = ?", 'i', [$budget_id]);
        if (!$budget) {
            $response['message'] = 'Budget request not found';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE budget_requests 
                SET admin_status = 'approved', 
                    admin_approved_by = ?, 
                    admin_approved_at = NOW(),
                    admin_notes = CONCAT(COALESCE(admin_notes, ''), '\n', ?),
                    overall_status = CASE 
                        WHEN accounting_status = 'approved' THEN 'approved' 
                        ELSE 'partially_approved' 
                    END
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Approved budget request #$budget_id as Admin", 'budget', $budget_id);
            }
            
            if ($budget['accounting_status'] === 'approved') {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'fully_approved',
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ", 'ii', [$_SESSION['user_id'], $budget['purchase_id']]);
            } else {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'admin_approved'
                    WHERE id = ?
                ", 'i', [$budget['purchase_id']]);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget approved by Admin'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Reject Budget (Admin)
    if (isset($_POST['reject_budget_admin'])) {
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($budget_id <= 0) {
            $response['message'] = 'Invalid budget ID';
            echo json_encode($response);
            exit;
        }
        
        $budget = $db->preparedFetchOne("SELECT id, purchase_id, admin_status, accounting_status FROM budget_requests WHERE id = ?", 'i', [$budget_id]);
        if (!$budget) {
            $response['message'] = 'Budget request not found';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE budget_requests 
                SET admin_status = 'rejected',
                    admin_approved_by = ?, 
                    admin_approved_at = NOW(),
                    admin_notes = CONCAT(COALESCE(admin_notes, ''), '\n', ?),
                    overall_status = 'rejected'
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            $db->preparedExecute("
                UPDATE purchases 
                SET approval_status = 'rejected',
                    purchase_status = 'idle',
                    can_modify = 1
                WHERE id = ?
            ", 'i', [$budget['purchase_id']]);
            
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Rejected budget request #$budget_id as Admin", 'budget', $budget_id);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget rejected by Admin'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Approve Budget (Accounting)
    if (isset($_POST['approve_budget_accounting'])) {
        if (!$is_accounting) {
            $response['message'] = 'Accounting permission required';
            echo json_encode($response);
            exit;
        }
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($budget_id <= 0) {
            $response['message'] = 'Invalid budget ID';
            echo json_encode($response);
            exit;
        }
        
        $budget = $db->preparedFetchOne("SELECT id, purchase_id, admin_status, accounting_status FROM budget_requests WHERE id = ?", 'i', [$budget_id]);
        if (!$budget) {
            $response['message'] = 'Budget request not found';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE budget_requests 
                SET accounting_status = 'approved', 
                    accounting_approved_by = ?, 
                    accounting_approved_at = NOW(),
                    accounting_notes = CONCAT(COALESCE(accounting_notes, ''), '\n', ?),
                    overall_status = CASE 
                        WHEN admin_status = 'approved' THEN 'approved' 
                        ELSE 'partially_approved' 
                    END
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Approved budget request #$budget_id as Accounting", 'budget', $budget_id);
            }
            
            if ($budget['admin_status'] === 'approved') {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'fully_approved',
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ", 'ii', [$_SESSION['user_id'], $budget['purchase_id']]);
            } else {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'accounting_approved'
                    WHERE id = ?
                ", 'i', [$budget['purchase_id']]);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget approved by Accounting'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Reject Budget (Accounting)
    if (isset($_POST['reject_budget_accounting'])) {
        if (!$is_accounting) {
            $response['message'] = 'Accounting permission required';
            echo json_encode($response);
            exit;
        }
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($budget_id <= 0) {
            $response['message'] = 'Invalid budget ID';
            echo json_encode($response);
            exit;
        }
        
        $budget = $db->preparedFetchOne("SELECT id, purchase_id, admin_status, accounting_status FROM budget_requests WHERE id = ?", 'i', [$budget_id]);
        if (!$budget) {
            $response['message'] = 'Budget request not found';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE budget_requests 
                SET accounting_status = 'rejected',
                    accounting_approved_by = ?, 
                    accounting_approved_at = NOW(),
                    accounting_notes = CONCAT(COALESCE(accounting_notes, ''), '\n', ?),
                    overall_status = 'rejected'
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            $db->preparedExecute("
                UPDATE purchases 
                SET approval_status = 'rejected',
                    purchase_status = 'idle',
                    can_modify = 1
                WHERE id = ?
            ", 'i', [$budget['purchase_id']]);
            
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Rejected budget request #$budget_id as Accounting", 'budget', $budget_id);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget rejected by Accounting'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Approve Permission
    if (isset($_POST['approve_permission'])) {
        $permission_id = (int)($_POST['permission_id'] ?? 0);
        if ($permission_id <= 0) {
            $response['message'] = 'Invalid permission ID';
            echo json_encode($response);
            exit;
        }
        $db->preparedExecute("UPDATE permission_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", 'ii', [$_SESSION['user_id'], $permission_id]);
        if ($db->affectedRows() > 0) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Approved permission request #$permission_id", 'permission', $permission_id);
            }
            $response = ['success' => true, 'message' => 'Permission granted'];
        } else {
            $response['message'] = 'Permission request not found';
        }
        echo json_encode($response);
        exit;
    }
    
    // Reject Permission
    if (isset($_POST['reject_permission'])) {
        $permission_id = (int)($_POST['permission_id'] ?? 0);
        if ($permission_id <= 0) {
            $response['message'] = 'Invalid permission ID';
            echo json_encode($response);
            exit;
        }
        $db->preparedExecute("UPDATE permission_requests SET status = 'rejected', approved_by = ?, approved_at = NOW() WHERE id = ?", 'ii', [$_SESSION['user_id'], $permission_id]);
        if ($db->affectedRows() > 0) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], "Rejected permission request #$permission_id", 'permission', $permission_id);
            }
            $response = ['success' => true, 'message' => 'Permission denied'];
        } else {
            $response['message'] = 'Permission request not found';
        }
        echo json_encode($response);
        exit;
    }
    
    // Dismiss Notification
    if (isset($_POST['dismiss_notification'])) {
        $notification_id = (int)($_POST['notification_id'] ?? 0);
        if ($notification_id > 0) {
            $db->preparedExecute("UPDATE notifications SET is_read = 1 WHERE id = ?", 'i', [$notification_id]);
            $response = ['success' => true, 'message' => 'Notification dismissed'];
        } else {
            $response['message'] = 'Invalid notification ID';
        }
        echo json_encode($response);
        exit;
    }
    
    // Mark All Notifications Read
    if (isset($_POST['mark_all_read'])) {
        $db->preparedExecute("UPDATE notifications SET is_read = 1 WHERE to_user_id = ? OR to_user_id IS NULL", 'i', [$_SESSION['user_id']]);
        $response = ['success' => true, 'message' => 'All notifications marked as read'];
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// =====================================================
// FETCH DASHBOARD DATA
// =====================================================

// Helper function for time ago
function time_ago($datetime) {
    if (empty($datetime)) return 'Unknown';
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return $diff . ' seconds ago';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $timestamp);
}

// 1. USER STATS (for modal)
$new_customers_today = $db->preparedFetchOne(
    "SELECT COUNT(*) as count FROM bakery_users 
     WHERE user_type = 'customer' AND DATE(created_at) = CURDATE()",
    '', []
)['count'] ?? 0;

$new_customers_this_week = $db->preparedFetchOne(
    "SELECT COUNT(*) as count FROM bakery_users 
     WHERE user_type = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
    '', []
)['count'] ?? 0;

$new_customers_this_month = $db->preparedFetchOne(
    "SELECT COUNT(*) as count FROM bakery_users 
     WHERE user_type = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    '', []
)['count'] ?? 0;

$new_customers_this_year = $db->preparedFetchOne(
    "SELECT COUNT(*) as count FROM bakery_users 
     WHERE user_type = 'customer' AND YEAR(created_at) = YEAR(NOW())",
    '', []
)['count'] ?? 0;

// 2. TOTAL STATS
$total_users = $db->preparedFetchOne("SELECT COUNT(*) as count FROM bakery_users", '', [])['count'] ?? 0;
$total_customers = $db->preparedFetchOne("SELECT COUNT(*) as count FROM bakery_users WHERE user_type = 'customer'", '', [])['count'] ?? 0;
$total_staff = $db->preparedFetchOne("SELECT COUNT(*) as count FROM bakery_users WHERE user_type = 'staff'", '', [])['count'] ?? 0;
$total_vendors = $db->preparedFetchOne("SELECT COUNT(*) as count FROM bakery_users WHERE user_type = 'vendor' AND vendor_status = 'active'", '', [])['count'] ?? 0;
$total_vendors_pending = $db->preparedFetchOne("SELECT COUNT(*) as count FROM bakery_users WHERE user_type = 'vendor' AND vendor_status = 'pending'", '', [])['count'] ?? 0;
$new_users_today = $db->preparedFetchOne("SELECT COUNT(*) as count FROM bakery_users WHERE DATE(created_at) = CURDATE()", '', [])['count'] ?? 0;

// 3. RECENT REGISTRATIONS
$recent_registrations = $db->preparedFetchAll(
    "SELECT id, fullname, username, user_type, created_at 
     FROM bakery_users 
     ORDER BY created_at DESC 
     LIMIT 5",
    '',
    []
);

// 4. PENDING REQUESTS
$pending_vendors = $db->preparedFetchAll(
    "SELECT id, fullname, email, phone, created_at 
     FROM bakery_users 
     WHERE user_type = 'vendor' AND vendor_status = 'pending'
     ORDER BY created_at DESC 
     LIMIT 5",
    '',
    []
);

// Budget requests - handle empty gracefully (using preparedFetchAll which returns empty array if none)
$pending_budgets = $db->preparedFetchAll("
    SELECT br.*, d.dept_name, u.fullname as requester_name
    FROM budget_requests br
    LEFT JOIN departments d ON br.department_id = d.id
    JOIN bakery_users u ON br.requester_id = u.id
    WHERE br.status = 'pending'
    ORDER BY br.created_at DESC
    LIMIT 5
", '', []);

$pending_budget_total = $db->preparedFetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM budget_requests WHERE status = 'pending'", '', [])['total'] ?? 0;

$pending_permissions = $db->preparedFetchAll(
    "SELECT pr.*, u.fullname as requester_name, u.user_type
     FROM permission_requests pr
     JOIN bakery_users u ON pr.requester_id = u.id
     WHERE pr.status = 'pending'
     ORDER BY pr.created_at DESC
     LIMIT 5",
    '',
    []
);
$pending_permissions_count = $db->preparedFetchOne("SELECT COUNT(*) as count FROM permission_requests WHERE status = 'pending'", '', [])['count'] ?? 0;

// 5. STAFF ACTIVITY
$staff_activity = $db->preparedFetchAll(
    "SELECT al.*, u.fullname, u.user_type
     FROM activity_logs al
     JOIN bakery_users u ON al.user_id = u.id
     WHERE al.action NOT LIKE '%login%' 
       AND al.action NOT LIKE '%Login%'
       AND al.action NOT LIKE '%logout%'
       AND al.action NOT LIKE '%Logout%'
       AND al.action NOT LIKE '%session%'
       AND al.action NOT LIKE '%Session%'
       AND al.action NOT LIKE '%LOGIN%'
       AND al.action NOT LIKE '%LOGOUT%'
     ORDER BY al.created_at DESC
     LIMIT 10",
    '',
    []
);

// 6. SYSTEM HEALTH
$online_users = $db->preparedFetchOne(
    "SELECT COUNT(DISTINCT user_id) as count FROM user_sessions 
     WHERE is_active = 1 AND last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
    '',
    []
)['count'] ?? 0;

$failed_logins_24h = $db->preparedFetchOne(
    "SELECT COUNT(*) as count FROM login_attempts 
     WHERE success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    '',
    []
)['count'] ?? 0;

$active_sessions = $db->preparedFetchOne(
    "SELECT COUNT(*) as count FROM user_sessions WHERE is_active = 1",
    '',
    []
)['count'] ?? 0;

// 7. LOGIN ATTEMPTS
$login_attempts = $db->preparedFetchAll(
    "SELECT la.*, u.fullname 
     FROM login_attempts la
     LEFT JOIN bakery_users u ON la.user_id = u.id
     ORDER BY la.attempted_at DESC
     LIMIT 5",
    '',
    []
);

// 8. SESSION ACTIVITY
$session_activity = $db->preparedFetchAll(
    "SELECT at.*, u.fullname 
     FROM audit_trail at
     JOIN bakery_users u ON at.user_id = u.id
     WHERE at.action_type IN ('LOGIN', 'LOGOUT', 'SESSION_TIMEOUT')
     ORDER BY at.created_at DESC
     LIMIT 5",
    '',
    []
);

// 9. SYSTEM CONFIG
$maintenance_mode = setting('maintenance_mode', false);
$app_version = setting('app_version', '4.0.0');
$active_products = $db->preparedFetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 1", '', [])['count'] ?? 0;

// Generate CSRF token
$csrf_token = '';
if (function_exists('generateCSRFToken')) {
    $csrf_token = generateCSRFToken('admin_action');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard · <?php echo htmlspecialchars(setting('app_name', 'Fingerchops Bakery')); ?></title>
    <link rel="icon" type="image/jpeg" href="<?php echo $root_path; ?>/logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/admin-dashboard.css">
</head>
<body>
    <div class="preloader" id="preloader">
        <div class="preloader-logo"></div>
    </div>

    <!-- Auto-refresh Indicator -->
    <div class="refresh-indicator" id="refreshIndicator">
        <span class="refresh-spinner"></span>
        <span id="refreshMessage">Auto-refresh in 40s</span>
    </div>

    <!-- Scroll to Top Button -->
    <div class="scroll-top" id="scrollTop" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
        <i class="fas fa-arrow-up"></i>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Budget Approval Modal -->
    <div id="budgetApprovalModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Budget</h3>
                <button class="modal-close" onclick="closeBudgetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approveBudgetId">
                <input type="hidden" id="approveRole">
                <div class="form-group">
                    <label>Approval Notes (optional)</label>
                    <textarea id="approveNotes" rows="3" placeholder="Add any notes about this approval..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeBudgetModal()">Cancel</button>
                <button class="btn-primary" id="confirmApproveBtn">Confirm Approval</button>
            </div>
        </div>
    </div>

    <!-- Budget Reject Modal -->
    <div id="budgetRejectModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Budget</h3>
                <button class="modal-close" onclick="closeRejectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rejectBudgetId">
                <input type="hidden" id="rejectRole">
                <div class="form-group">
                    <label>Rejection Reason <span class="required">*</span></label>
                    <textarea id="rejectNotes" rows="3" placeholder="Please provide a reason for rejection..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeRejectModal()">Cancel</button>
                <button class="btn-primary" id="confirmRejectBtn">Confirm Rejection</button>
            </div>
        </div>
    </div>

    <!-- User Stats Modal -->
    <div id="userStatsModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-chart-line"></i> New Customers Analytics</h3>
                <button class="modal-close" onclick="closeUserStatsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="stats-grid-modal">
                    <div class="stat-card-modal">
                        <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Today</div>
                            <div class="stat-value"><?php echo number_format($new_customers_today); ?></div>
                        </div>
                    </div>
                    <div class="stat-card-modal">
                        <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">This Week</div>
                            <div class="stat-value"><?php echo number_format($new_customers_this_week); ?></div>
                        </div>
                    </div>
                    <div class="stat-card-modal">
                        <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">This Month</div>
                            <div class="stat-value"><?php echo number_format($new_customers_this_month); ?></div>
                        </div>
                    </div>
                    <div class="stat-card-modal">
                        <div class="stat-icon"><i class="fas fa-calendar-year"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">This Year</div>
                            <div class="stat-value"><?php echo number_format($new_customers_this_year); ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-note">
                    <i class="fas fa-chart-simple"></i> Total customers: <strong><?php echo number_format($total_customers); ?></strong>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-primary" onclick="closeUserStatsModal()">Close</button>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        
        <!-- HEADER with FULL USER INFO -->
        <div class="admin-header">
            <div class="header-top">
                <div class="header-title">
                    <h1>
                        <i class="fas fa-crown"></i> 
                        Admin Dashboard
                    </h1>
                    <p>
                        <i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                        <i class="fas fa-clock" style="margin-left: 12px;"></i> <?php echo date('h:i A'); ?>
                    </p>
                </div>
                <div class="header-actions">
                    <div class="header-badge">
                        <i class="fas fa-user-shield"></i>
                        <span><?php echo htmlspecialchars($userRole); ?></span>
                    </div>
                    <div class="header-badge">
                        <i class="fas fa-store"></i>
                        <span><?php echo htmlspecialchars($branchName); ?> (<?php echo htmlspecialchars($branchCode); ?>)</span>
                    </div>
                    <a href="../logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
            <div class="header-footer">
                <div class="user-greeting">
                    <i class="fas fa-user-circle"></i> 
                    Welcome back, <strong><?php echo htmlspecialchars($user['fullname']); ?></strong>
                    <span class="user-details">
                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone']); ?>
                    </span>
                </div>
                <div class="system-badge">
                    <i class="fas fa-code-branch"></i> v<?php echo $app_version; ?>
                    <?php if ($maintenance_mode): ?>
                        <span class="badge badge-warning"><i class="fas fa-tools"></i> Maintenance Mode</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- QUICK STATS BAR -->
        <div class="quick-stats">
            <div class="quick-stat-item clickable" id="totalUsersStat" onclick="openUserStatsModal()">
                <div class="quick-stat-number"><?php echo number_format($total_users); ?></div>
                <div class="quick-stat-label">Total Users</div>
                <small class="stat-change">+<?php echo $new_users_today; ?> today</small>
                <i class="fas fa-chart-line stat-hover-icon"></i>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-number"><?php echo number_format($total_customers); ?></div>
                <div class="quick-stat-label">Customers</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-number"><?php echo number_format($total_staff); ?></div>
                <div class="quick-stat-label">Staff</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-number"><?php echo number_format($total_vendors); ?></div>
                <div class="quick-stat-label">Active Vendors</div>
                <?php if ($total_vendors_pending > 0): ?>
                    <span class="request-badge"><?php echo $total_vendors_pending; ?> pending</span>
                <?php endif; ?>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-number"><?php echo $online_users; ?></div>
                <div class="quick-stat-label">Online Now</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-number"><?php echo $active_sessions; ?></div>
                <div class="quick-stat-label">Active Sessions</div>
            </div>
        </div>

        <!-- QUICK ACTION CARDS -->
        <div class="quick-actions">
            <a href="admin_power/new-staff.php" class="action-card">
                <i class="fas fa-user-plus action-icon"></i>
                <span>New Staff</span>
                <small>Add team member</small>
            </a>
            <a href="admin_power/roles.php" class="action-card">
                <i class="fas fa-lock action-icon"></i>
                <span>Roles</span>
                <small>Manage permissions</small>
            </a>
            <a href="admin_power/users.php" class="action-card">
                <i class="fas fa-users action-icon"></i>
                <span>Users</span>
                <small>Manage all users</small>
                <?php if ($total_vendors_pending + $pending_permissions_count > 0): ?>
                    <span class="request-badge"><?php echo $total_vendors_pending + $pending_permissions_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="admin_power/products.php" class="action-card">
                <i class="fas fa-box action-icon"></i>
                <span>Products</span>
                <small><?php echo $active_products; ?> active</small>
            </a>
            <a href="admin_power/budgets.php" class="action-card">
                <i class="fas fa-chart-line action-icon"></i>
                <span>Budgets</span>
                <small><?php echo count($pending_budgets); ?> pending</small>
            </a>
            <a href="admin_power/system.php" class="action-card">
                <i class="fas fa-cog action-icon"></i>
                <span>System</span>
                <small>Configuration</small>
            </a>
            <a href="admin_power/audit.php" class="action-card">
                <i class="fas fa-search action-icon"></i>
                <span>Audit Log</span>
                <small>View history</small>
            </a>
            <a href="admin_power/department.php" class="action-card">
                <i class="fas fa-building action-icon"></i>
                <span>Departments</span>
                <small>Manage structure</small>
            </a>
            <a href="tools/notifications.php" class="action-card">
                <i class="fas fa-bell action-icon"></i>
                <span>Notifications</span>
                <small>Create & review alerts</small>
            </a>
        </div>

        <!-- FIRST ROW: Pending Approvals -->
        <div class="dashboard-row">
            <!-- Vendor Requests -->
            <div class="insight-card">
                <h3>
                    <i class="fas fa-store"></i> 
                    Pending Vendor Approvals
                    <?php if ($total_vendors_pending > 0): ?>
                        <span class="badge badge-warning" style="margin-left: auto;"><?php echo $total_vendors_pending; ?> total</span>
                    <?php endif; ?>
                </h3>
                <?php if (!empty($pending_vendors)): ?>
                    <ul class="item-list">
                        <?php foreach ($pending_vendors as $vendor): ?>
                        <li>
                            <div class="item-info">
                                <div class="item-title"><?php echo htmlspecialchars($vendor['fullname']); ?></div>
                                <div class="item-meta"><?php echo htmlspecialchars($vendor['email']); ?> · <?php echo htmlspecialchars($vendor['phone']); ?></div>
                            </div>
                            <div class="item-action">
                                <button class="btn-approve" onclick="approveVendor(<?php echo $vendor['id']; ?>)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-reject" onclick="rejectVendor(<?php echo $vendor['id']; ?>)">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($total_vendors_pending > 5): ?>
                        <div style="text-align: center; margin-top: 15px;">
                            <a href="admin_power/vendors.php?status=pending" class="view-all-link">View all <?php echo $total_vendors_pending; ?> →</a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="empty-message"><i class="fas fa-check-circle"></i> No pending vendor requests</p>
                <?php endif; ?>
            </div>

            <!-- Budget Requests -->
            <div class="insight-card">
                <h3>
                    <i class="fas fa-chart-line"></i> 
                    Pending Budget Requests
                    <?php if (count($pending_budgets) > 0): ?>
                        <span class="badge badge-warning" style="margin-left: auto;">₦<?php echo number_format($pending_budget_total); ?></span>
                    <?php endif; ?>
                </h3>
                <?php if (!empty($pending_budgets)): ?>
                    <ul class="item-list">
                        <?php foreach ($pending_budgets as $budget): ?>
                        <li>
                            <div class="item-info">
                                <div class="item-title"><?php echo htmlspecialchars($budget['dept_name']); ?> - ₦<?php echo number_format($budget['amount']); ?></div>
                                <div class="item-meta"><?php echo htmlspecialchars($budget['requester_name']); ?> · <?php echo htmlspecialchars($budget['title']); ?></div>
                            </div>
                            <div class="item-action">
                                <button class="btn-approve" onclick="openBudgetApprovalModal(<?php echo $budget['id']; ?>, 'admin')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn-reject" onclick="openBudgetRejectModal(<?php echo $budget['id']; ?>, 'admin')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($pending_budgets) > 5): ?>
                        <div style="text-align: center; margin-top: 15px;">
                            <a href="admin_power/budgets.php?status=pending" class="view-all-link">View all →</a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="empty-message"><i class="fas fa-check-circle"></i> No pending budget requests</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECOND ROW: Permission Requests & Recent Registrations -->
        <div class="dashboard-row">
            <!-- Permission Requests -->
            <div class="insight-card">
                <h3>
                    <i class="fas fa-lock"></i> 
                    Pending Permission Requests
                    <?php if ($pending_permissions_count > 0): ?>
                        <span class="badge badge-warning" style="margin-left: auto;"><?php echo $pending_permissions_count; ?> total</span>
                    <?php endif; ?>
                </h3>
                <?php if (!empty($pending_permissions)): ?>
                    <ul class="item-list">
                        <?php foreach ($pending_permissions as $perm): ?>
                        <li>
                            <div class="item-info">
                                <div class="item-title"><?php echo htmlspecialchars($perm['requester_name']); ?></div>
                                <div class="item-meta"><?php echo htmlspecialchars($perm['requested_permission']); ?> · <?php echo htmlspecialchars($perm['reason']); ?></div>
                            </div>
                            <div class="item-action">
                                <button class="btn-approve" onclick="approvePermission(<?php echo $perm['id']; ?>)">
                                    <i class="fas fa-check"></i> Grant
                                </button>
                                <button class="btn-reject" onclick="rejectPermission(<?php echo $perm['id']; ?>)">
                                    <i class="fas fa-times"></i> Deny
                                </button>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="empty-message"><i class="fas fa-check-circle"></i> No pending permission requests</p>
                <?php endif; ?>
            </div>

            <!-- Recent Registrations -->
            <div class="insight-card">
                <h3>
                    <i class="fas fa-user-plus"></i> 
                    Recent Registrations
                    <span class="badge badge-info" style="margin-left: auto;">+<?php echo $new_users_today; ?> today</span>
                </h3>
                <?php if (!empty($recent_registrations)): ?>
                    <ul class="item-list">
                        <?php foreach ($recent_registrations as $reg): ?>
                        <li>
                            <div class="item-info">
                                <div class="item-title"><?php echo htmlspecialchars($reg['fullname']); ?></div>
                                <div class="item-meta">
                                    <i class="fas fa-user-tag"></i> <?php echo ucfirst($reg['user_type']); ?>
                                    <i class="fas fa-calendar"></i> <?php echo date('M j, H:i', strtotime($reg['created_at'])); ?>
                                </div>
                            </div>
                            <div class="item-action">
                                <a href="admin_power/user-details.php?id=<?php echo $reg['id']; ?>" class="btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div style="text-align: center; margin-top: 15px;">
                        <a href="admin_power/users.php" class="view-all-link">View all users →</a>
                    </div>
                <?php else: ?>
                    <p class="empty-message"><i class="fas fa-user-plus"></i> No recent registrations</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- THIRD ROW: Activity Logs -->
        <div class="full-width-section">
            <div class="insight-card">
                <h3><i class="fas fa-history"></i> Activity & Security Logs</h3>
                
                <div class="logs-three-column">
                    <div class="log-column">
                        <h4><i class="fas fa-clipboard-list"></i> Staff Activity</h4>
                        <?php if (!empty($staff_activity)): ?>
                            <ul class="item-list">
                                <?php foreach ($staff_activity as $act): ?>
                                <li>
                                    <div class="item-info">
                                        <div class="item-title">
                                            <i class="fas fa-user-circle"></i> 
                                            <?php echo htmlspecialchars($act['fullname']); ?>
                                        </div>
                                        <div class="item-meta">
                                            <span class="activity-action"><?php echo htmlspecialchars($act['action']); ?></span>
                                            <?php if (!empty($act['entity_type'])): ?>
                                                <span class="badge badge-info"><?php echo htmlspecialchars($act['entity_type']); ?></span>
                                            <?php endif; ?>
                                            <span class="activity-time"><?php echo time_ago($act['created_at']); ?></span>
                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="empty-message"><i class="fas fa-inbox"></i> No staff activity recorded</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="log-column">
                        <h4><i class="fas fa-sign-in-alt"></i> Login Attempts</h4>
                        <?php if (!empty($login_attempts)): ?>
                            <ul class="item-list">
                                <?php foreach ($login_attempts as $login): ?>
                                <li class="<?php echo $login['success'] ? 'login-success' : 'login-failed'; ?>">
                                    <div class="item-info">
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($login['fullname'] ?? $login['username'] ?? 'Unknown'); ?>
                                        </div>
                                        <div class="item-meta">
                                            <?php echo htmlspecialchars($login['ip_address']); ?> · <?php echo date('H:i', strtotime($login['attempted_at'])); ?>
                                            <?php if (!$login['success']): ?>
                                                <span class="badge badge-danger">Failed</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if ($failed_logins_24h > 0): ?>
                                <div class="alert-warning" style="margin-top: 10px; padding: 8px; background: #fee2e2; border-radius: 8px; font-size: 12px;">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $failed_logins_24h; ?> failed attempts in last 24h
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="empty-message">No login attempts recorded</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="log-column">
                        <h4><i class="fas fa-user-clock"></i> Session Activity</h4>
                        <?php if (!empty($session_activity)): ?>
                            <ul class="item-list">
                                <?php foreach ($session_activity as $session): ?>
                                <li class="session-<?php echo strtolower($session['action_type']); ?>">
                                    <div class="item-info">
                                        <div class="item-title">
                                            <?php echo htmlspecialchars($session['fullname']); ?>
                                        </div>
                                        <div class="item-meta">
                                            <?php echo htmlspecialchars($session['action_type']); ?> · <?php echo date('M j, H:i', strtotime($session['created_at'])); ?>
                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="empty-message">No session activity</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="text-align: right; margin-top: 20px; padding-top: 10px; border-top: 1px solid var(--border-light);">
                    <a href="admin_power/audit.php" class="view-all-link">
                        <i class="fas fa-external-link-alt"></i> View Full Audit Log
                    </a>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> All actions are logged · Auto-refresh every 40s · System v<?php echo $app_version; ?>
            <br><small>Last login: <?php echo date('M j, H:i', strtotime($user['last_login'] ?? 'now')); ?> from IP: <?php echo htmlspecialchars($user['last_ip_address'] ?? 'Unknown'); ?></small>
        </div>
    </div>

    <!-- Hidden CSRF token for AJAX requests -->
    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

    <script>
        // =====================================================
        // CONFIGURATION
        // =====================================================
        const rootPath = '<?php echo $root_path; ?>';
        let refreshCountdown = 40;
        let isRefreshing = false;
        let sessionCheckInterval = null;
        let refreshTimer = null;

        // =====================================================
        // SCROLL POSITION SAVE/RESTORE
        // =====================================================
        window.addEventListener('beforeunload', function() {
            localStorage.setItem('adminScrollPos', window.scrollY);
        });

        window.addEventListener('load', function() {
            const savedPos = localStorage.getItem('adminScrollPos');
            if (savedPos) {
                window.scrollTo(0, parseInt(savedPos));
                localStorage.removeItem('adminScrollPos');
            }
            setTimeout(function() {
                const preloader = document.getElementById('preloader');
                if (preloader) preloader.classList.add('fade-out');
                setTimeout(function() {
                    if (preloader && preloader.parentNode) {
                        preloader.parentNode.removeChild(preloader);
                    }
                }, 500);
            }, 500);
        });

        // =====================================================
        // AUTO-REFRESH
        // =====================================================
        const refreshIndicator = document.getElementById('refreshIndicator');
        const refreshMessage = document.getElementById('refreshMessage');

        function updateRefreshIndicator() {
            if (refreshMessage) refreshMessage.textContent = `Auto-refresh in ${refreshCountdown}s`;
        }

        function startRefresh() {
            if (isRefreshing) return;
            isRefreshing = true;
            localStorage.setItem('adminScrollPos', window.scrollY);
            if (refreshMessage) refreshMessage.textContent = 'Refreshing...';
            window.location.reload();
        }

        if (refreshTimer) clearInterval(refreshTimer);
        
        refreshTimer = setInterval(function() {
            if (!isRefreshing) {
                refreshCountdown--;
                updateRefreshIndicator();
                if (refreshCountdown <= 0) {
                    startRefresh();
                }
            }
        }, 1000);

        // =====================================================
        // SESSION HEARTBEAT
        // =====================================================
        function checkSessionStatus() {
            fetch(rootPath + '/auth.php?action=check_session')
                .then(r => r.json())
                .then(data => {
                    if (!data.valid) {
                        showToast('Session expired. Redirecting to login...', 'warning');
                        setTimeout(() => { window.location.href = rootPath + '/login_signup.php'; }, 2000);
                    } else if (data.time_remaining && data.time_remaining < 60) {
                        showToast(`Session expires in ${data.time_remaining} seconds`, 'warning', 8000);
                    }
                })
                .catch(e => console.error('Session check:', e));
        }

        function startSessionCheck() {
            if (sessionCheckInterval) clearInterval(sessionCheckInterval);
            sessionCheckInterval = setInterval(checkSessionStatus, 30000);
        }
        startSessionCheck();

        // =====================================================
        // TOAST NOTIFICATIONS
        // =====================================================
        const toastContainer = document.getElementById('toastContainer');

        function showToast(message, type = 'info', duration = 3000) {
            if (!toastContainer) return;
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span>${escapeHtml(message)}</span>
                <button onclick="this.parentElement.remove()">&times;</button>
            `;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.remove(), duration);
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // =====================================================
        // APPROVAL FUNCTIONS
        // =====================================================
        function sendApproval(action, idField, id) {
            const csrfToken = document.getElementById('csrf_token')?.value;
            if (!csrfToken) {
                showToast('CSRF token missing', 'error');
                return;
            }

            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append(action, '1');
            fd.append(idField, id);
            fd.append('csrf_token', csrfToken);

            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        showToast(d.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast(d.message, 'error');
                    }
                })
                .catch(() => showToast('Network error', 'error'));
        }

        function approveVendor(id) { if (confirm('Approve this vendor?')) sendApproval('approve_vendor', 'vendor_id', id); }
        function rejectVendor(id) { if (confirm('Reject this vendor?')) sendApproval('reject_vendor', 'vendor_id', id); }
        function approvePermission(id) { if (confirm('Grant this permission?')) sendApproval('approve_permission', 'permission_id', id); }
        function rejectPermission(id) { if (confirm('Deny this permission?')) sendApproval('reject_permission', 'permission_id', id); }

        // =====================================================
        // BUDGET MODAL FUNCTIONS
        // =====================================================
        const budgetModal = document.getElementById('budgetApprovalModal');
        const rejectModal = document.getElementById('budgetRejectModal');

        function openBudgetApprovalModal(budgetId, role) {
            document.getElementById('approveBudgetId').value = budgetId;
            document.getElementById('approveRole').value = role;
            document.getElementById('approveNotes').value = '';
            budgetModal.classList.add('show');
            document.getElementById('overlay').classList.add('active');
        }

        function openBudgetRejectModal(budgetId, role) {
            document.getElementById('rejectBudgetId').value = budgetId;
            document.getElementById('rejectRole').value = role;
            document.getElementById('rejectNotes').value = '';
            rejectModal.classList.add('show');
            document.getElementById('overlay').classList.add('active');
        }

        function closeBudgetModal() {
            budgetModal.classList.remove('show');
            document.getElementById('overlay').classList.remove('active');
        }

        function closeRejectModal() {
            rejectModal.classList.remove('show');
            document.getElementById('overlay').classList.remove('active');
        }

        document.getElementById('confirmApproveBtn')?.addEventListener('click', async () => {
            const budgetId = document.getElementById('approveBudgetId').value;
            const role = document.getElementById('approveRole').value;
            const notes = document.getElementById('approveNotes').value;
            
            const action = role === 'admin' ? 'approve_budget_admin' : 'approve_budget_accounting';
            const csrfToken = document.getElementById('csrf_token')?.value;
            
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append(action, '1');
            fd.append('budget_id', budgetId);
            fd.append('notes', notes);
            fd.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: fd });
                const data = await response.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeBudgetModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            }
        });

        document.getElementById('confirmRejectBtn')?.addEventListener('click', async () => {
            const budgetId = document.getElementById('rejectBudgetId').value;
            const role = document.getElementById('rejectRole').value;
            const notes = document.getElementById('rejectNotes').value;
            
            if (!notes.trim()) {
                showToast('Please provide a reason for rejection', 'error');
                return;
            }
            
            const action = role === 'admin' ? 'reject_budget_admin' : 'reject_budget_accounting';
            const csrfToken = document.getElementById('csrf_token')?.value;
            
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append(action, '1');
            fd.append('budget_id', budgetId);
            fd.append('notes', notes);
            fd.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: fd });
                const data = await response.json();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeRejectModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            }
        });

        // =====================================================
        // USER STATS MODAL
        // =====================================================
        const userStatsModal = document.getElementById('userStatsModal');

        function openUserStatsModal() {
            if (userStatsModal) userStatsModal.classList.add('show');
        }

        function closeUserStatsModal() {
            if (userStatsModal) userStatsModal.classList.remove('show');
        }

        // Close modals on outside click
        window.addEventListener('click', function(e) {
            if (e.target === userStatsModal) closeUserStatsModal();
            if (e.target === budgetModal) closeBudgetModal();
            if (e.target === rejectModal) closeRejectModal();
        });

        // =====================================================
        // SCROLL TO TOP
        // =====================================================
        const scrollTop = document.getElementById('scrollTop');
        if (scrollTop) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    scrollTop.classList.add('visible');
                } else {
                    scrollTop.classList.remove('visible');
                }
            });
        }
        
        // =====================================================
        // CLEANUP
        // =====================================================
        window.addEventListener('beforeunload', function() {
            if (refreshTimer) clearInterval(refreshTimer);
            if (sessionCheckInterval) clearInterval(sessionCheckInterval);
        });

        // Create overlay element if not exists
        let overlay = document.getElementById('overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'overlay';
            overlay.className = 'overlay';
            document.body.appendChild(overlay);
        }
    </script>
</body>
</html>