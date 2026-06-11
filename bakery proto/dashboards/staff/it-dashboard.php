<?php
// =====================================================
// FILE: dashboards/staff/it-dashboard.php
// PURPOSE: IT Department Dashboard - Session & Security Management
// VERSION: 1.1 - Improved notification system with scroll preservation
// =====================================================

$root_path = dirname(__DIR__, 2);

if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

session_start();

require_once $root_path . '/conn.php';
require_once $root_path . '/includes/User.php';
require_once $root_path . '/includes/Security.php';
require_once $root_path . '/config/config_loader.php';

$db = Database::getInstance();

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

// Check if user is in IT department
$in_it = false;
$user_roles = $userObj->getRoles();
$department_id = null;

foreach ($user_roles as $role) {
    $dept_check = $db->preparedFetchOne("
        SELECT d.dept_code, d.dept_name, d.id 
        FROM departments d 
        WHERE d.id = ?
    ", 'i', [$role['department_id'] ?? 0]);
    
    if ($dept_check && ($dept_check['dept_code'] === 'IT' || stripos($dept_check['dept_name'], 'information') !== false)) {
        $in_it = true;
        $department_id = $dept_check['id'];
        break;
    }
}

$privilege_level = $userObj->getPrivilegeLevel();
$is_admin = ($privilege_level >= 100);

if (!$in_it && !$is_admin) {
    header('Location: ' . $root_path . '/login_signup.php?error=not_it');
    exit;
}

$user_branch_id = $user['branch_id'] ?? 1;
$branch_info = $db->preparedFetchOne("SELECT branch_name, branch_code FROM branches WHERE id = ?", 'i', [$user_branch_id]);
$branch_name = $branch_info['branch_name'] ?? 'Headquarters';
$is_headquarters = ($user_branch_id == 1);

$role_name = $user_roles[0]['role_name'] ?? 'IT Staff';

// =====================================================
// CSRF PROTECTION
// =====================================================
if (!isset($_SESSION['it_csrf_token'])) {
    $_SESSION['it_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['it_csrf_token'];

// =====================================================
// CREATE/UPDATE NOTIFICATION TABLES
// =====================================================
$db->query("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_user_id INT NOT NULL,
        type VARCHAR(50) NOT NULL COMMENT 'security, info, warning, success, department',
        message TEXT NOT NULL,
        requires_acknowledgment TINYINT(1) DEFAULT 0,
        expires_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type),
        INDEX idx_from_user (from_user_id),
        INDEX idx_expires (expires_at),
        FOREIGN KEY (from_user_id) REFERENCES bakery_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$db->query("
    CREATE TABLE IF NOT EXISTS notification_to (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_id INT NOT NULL,
        to_user_id INT NULL,
        to_branch_id INT NULL,
        to_department_id INT NULL,
        to_role_id INT NULL,
        to_user_type ENUM('staff', 'customer', 'vendor') NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notification (notification_id),
        INDEX idx_user (to_user_id),
        INDEX idx_branch (to_branch_id),
        INDEX idx_department (to_department_id),
        INDEX idx_role (to_role_id),
        INDEX idx_user_type (to_user_type),
        FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
        FOREIGN KEY (to_user_id) REFERENCES bakery_users(id) ON DELETE CASCADE,
        FOREIGN KEY (to_branch_id) REFERENCES branches(id) ON DELETE CASCADE,
        FOREIGN KEY (to_department_id) REFERENCES departments(id) ON DELETE CASCADE,
        FOREIGN KEY (to_role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// =====================================================
// AJAX HANDLERS
// =====================================================
if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['it_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // ========== TERMINATE SESSION ==========
    if ($action === 'terminate_session') {
        $session_id = $_POST['session_id'] ?? '';
        
        if (empty($session_id)) {
            $response['message'] = 'Invalid session ID';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE user_sessions 
                SET is_active = 0, expires_at = NOW() 
                WHERE session_id = ?
            ", 's', [$session_id]);
            
            $db->preparedExecute("
                UPDATE bakery_users 
                SET last_session_id = NULL 
                WHERE last_session_id = ?
            ", 's', [$session_id]);
            
            logActivity($_SESSION['user_id'], "Terminated session: " . substr($session_id, 0, 8) . "...", 'session', 0);
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Session terminated successfully'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // ========== TOGGLE ACCOUNT LOCK ==========
    if ($action === 'toggle_account_lock') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $lock_duration = (int)($_POST['lock_duration'] ?? 0);
        
        if ($user_id <= 0) {
            $response['message'] = 'Invalid user ID';
            echo json_encode($response);
            exit;
        }
        
        if ($lock_duration > 0) {
            $locked_until = date('Y-m-d H:i:s', strtotime("+$lock_duration minutes"));
            $db->preparedExecute("
                UPDATE bakery_users 
                SET account_locked_until = ?, failed_login_attempts = 0 
                WHERE id = ?
            ", 'si', [$locked_until, $user_id]);
            
            logActivity($_SESSION['user_id'], "Locked account for user ID: $user_id until $locked_until", 'user', $user_id);
            $response = ['success' => true, 'message' => "Account locked for $lock_duration minutes"];
        } else {
            $db->preparedExecute("
                UPDATE bakery_users 
                SET account_locked_until = NULL, failed_login_attempts = 0 
                WHERE id = ?
            ", 'i', [$user_id]);
            
            logActivity($_SESSION['user_id'], "Unlocked account for user ID: $user_id", 'user', $user_id);
            $response = ['success' => true, 'message' => 'Account unlocked successfully'];
        }
        
        echo json_encode($response);
        exit;
    }
    
    // ========== GET SESSIONS ==========
    if ($action === 'get_sessions') {
        $filter = $_POST['filter'] ?? 'all';
        
        $sql = "
            SELECT 
                us.*,
                u.fullname,
                u.username,
                u.email,
                u.user_type,
                u.account_locked_until,
                b.branch_name
            FROM user_sessions us
            JOIN bakery_users u ON us.user_id = u.id
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE us.is_active = 1
        ";
        
        if ($filter === 'staff') {
            $sql .= " AND u.user_type = 'staff'";
        } elseif ($filter === 'customers') {
            $sql .= " AND u.user_type = 'customer'";
        } elseif ($filter === 'vendors') {
            $sql .= " AND u.user_type = 'vendor'";
        }
        
        $sql .= " ORDER BY us.last_activity DESC";
        
        $sessions = $db->preparedFetchAll($sql, '', []);
        $response = ['success' => true, 'sessions' => $sessions];
        echo json_encode($response);
        exit;
    }
    
    // ========== GET ACTIVITIES ==========
    if ($action === 'get_activities') {
        $filter = $_POST['filter'] ?? 'all';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $sql = "
            SELECT 
                al.*,
                u.fullname,
                u.user_type,
                b.branch_name
            FROM activity_logs al
            JOIN bakery_users u ON al.user_id = u.id
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE al.action NOT LIKE '%login%' 
              AND al.action NOT LIKE '%Login%'
              AND al.action NOT LIKE '%logout%'
              AND al.action NOT LIKE '%Logout%'
              AND al.action NOT LIKE '%session%'
        ";
        
        $params = [];
        $types = '';
        
        if ($filter === 'staff') {
            $sql .= " AND u.user_type = 'staff'";
        } elseif ($filter === 'customers') {
            $sql .= " AND u.user_type = 'customer'";
        } elseif ($filter === 'vendors') {
            $sql .= " AND u.user_type = 'vendor'";
        }
        
        if (!empty($date_from)) {
            $sql .= " AND DATE(al.created_at) >= ?";
            $params[] = $date_from;
            $types .= 's';
        }
        if (!empty($date_to)) {
            $sql .= " AND DATE(al.created_at) <= ?";
            $params[] = $date_to;
            $types .= 's';
        }
        
        $sql .= " ORDER BY al.created_at DESC LIMIT 100";
        
        $activities = $db->preparedFetchAll($sql, $types, $params);
        $response = ['success' => true, 'activities' => $activities];
        echo json_encode($response);
        exit;
    }
    
    // ========== GET CUSTOMER ACTIONS ==========
    if ($action === 'get_customer_actions') {
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $sql = "
            (SELECT 
                'order' as action_type,
                co.id as entity_id,
                co.order_number as description,
                co.total_amount as amount,
                co.status,
                co.created_at,
                u.fullname,
                u.id as user_id
            FROM customer_orders co
            JOIN bakery_users u ON co.user_id = u.id
            WHERE u.user_type = 'customer')
            
            UNION ALL
            
            (SELECT 
                'return' as action_type,
                oret.id as entity_id,
                oret.return_number as description,
                oret.total_refund_amount as amount,
                oret.status,
                oret.created_at,
                u.fullname,
                u.id as user_id
            FROM order_returns oret
            JOIN bakery_users u ON oret.created_by = u.id
            WHERE u.user_type = 'customer')
            
            ORDER BY created_at DESC
            LIMIT 100
        ";
        
        $customer_actions = $db->query($sql);
        $actions = [];
        if ($customer_actions) {
            while ($row = mysqli_fetch_assoc($customer_actions)) {
                $actions[] = $row;
            }
        }
        
        $response = ['success' => true, 'actions' => $actions];
        echo json_encode($response);
        exit;
    }
    
    // ========== CREATE NOTIFICATION ==========
    if ($action === 'create_notification') {
        $message = trim($_POST['message'] ?? '');
        $type = $_POST['type'] ?? 'security';
        $target_tab = $_POST['target_tab'] ?? 'staff';
        $target_data = json_decode($_POST['target_data'] ?? '{}', true);
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $requires_ack = isset($_POST['requires_acknowledgment']) ? 1 : 0;
        
        if (empty($message)) {
            $response['message'] = 'Message is required';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            // Insert notification
            $db->preparedExecute("
                INSERT INTO notifications (from_user_id, type, message, requires_acknowledgment, expires_at)
                VALUES (?, ?, ?, ?, ?)
            ", 'issis', [$_SESSION['user_id'], $type, $message, $requires_ack, $expires_at]);
            
            $notification_id = $db->lastInsertId();
            
            // Insert targets based on tab
            if ($target_tab === 'staff') {
                $selection_type = $target_data['selection_type'] ?? 'all';
                
                if ($selection_type === 'all') {
                    // All staff
                    $db->preparedExecute("
                        INSERT INTO notification_to (notification_id, to_user_type)
                        VALUES (?, 'staff')
                    ", 'i', [$notification_id]);
                } else {
                    // Specific selections
                    if (!empty($target_data['branches'])) {
                        foreach ($target_data['branches'] as $branch_id) {
                            $db->preparedExecute("
                                INSERT INTO notification_to (notification_id, to_branch_id)
                                VALUES (?, ?)
                            ", 'ii', [$notification_id, $branch_id]);
                        }
                    }
                    if (!empty($target_data['departments'])) {
                        foreach ($target_data['departments'] as $dept_id) {
                            $db->preparedExecute("
                                INSERT INTO notification_to (notification_id, to_department_id)
                                VALUES (?, ?)
                            ", 'ii', [$notification_id, $dept_id]);
                        }
                    }
                    if (!empty($target_data['roles'])) {
                        foreach ($target_data['roles'] as $role_id) {
                            $db->preparedExecute("
                                INSERT INTO notification_to (notification_id, to_role_id)
                                VALUES (?, ?)
                            ", 'ii', [$notification_id, $role_id]);
                        }
                    }
                    if (!empty($target_data['users'])) {
                        foreach ($target_data['users'] as $user_id) {
                            $db->preparedExecute("
                                INSERT INTO notification_to (notification_id, to_user_id)
                                VALUES (?, ?)
                            ", 'ii', [$notification_id, $user_id]);
                        }
                    }
                }
            } elseif ($target_tab === 'customers') {
                $selection_type = $target_data['selection_type'] ?? 'all';
                
                if ($selection_type === 'all') {
                    $db->preparedExecute("
                        INSERT INTO notification_to (notification_id, to_user_type)
                        VALUES (?, 'customer')
                    ", 'i', [$notification_id]);
                } else {
                    if (!empty($target_data['users'])) {
                        foreach ($target_data['users'] as $user_id) {
                            $db->preparedExecute("
                                INSERT INTO notification_to (notification_id, to_user_id)
                                VALUES (?, ?)
                            ", 'ii', [$notification_id, $user_id]);
                        }
                    }
                }
            } elseif ($target_tab === 'vendors') {
                $selection_type = $target_data['selection_type'] ?? 'all';
                
                if ($selection_type === 'all') {
                    $db->preparedExecute("
                        INSERT INTO notification_to (notification_id, to_user_type)
                        VALUES (?, 'vendor')
                    ", 'i', [$notification_id]);
                } else {
                    if (!empty($target_data['users'])) {
                        foreach ($target_data['users'] as $user_id) {
                            $db->preparedExecute("
                                INSERT INTO notification_to (notification_id, to_user_id)
                                VALUES (?, ?)
                            ", 'ii', [$notification_id, $user_id]);
                        }
                    }
                }
            }
            
            logActivity($_SESSION['user_id'], "Created notification ID: $notification_id", 'notification', $notification_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Notification created successfully'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // ========== GET NOTIFICATIONS ==========
    if ($action === 'get_notifications') {
        $notifications = $db->preparedFetchAll("
            SELECT n.*, 
                   GROUP_CONCAT(DISTINCT nt.to_user_type) as target_types,
                   COUNT(DISTINCT nt.id) as target_count
            FROM notifications n
            LEFT JOIN notification_to nt ON n.id = nt.notification_id
            WHERE n.expires_at IS NULL OR n.expires_at > NOW()
            GROUP BY n.id
            ORDER BY n.created_at DESC
        ", '', []);
        
        $response = ['success' => true, 'notifications' => $notifications];
        echo json_encode($response);
        exit;
    }
    
    // ========== SEARCH USERS ==========
    if ($action === 'search_users') {
        $term = trim($_POST['term'] ?? '');
        $user_type = $_POST['user_type'] ?? 'staff';
        
        $sql = "
            SELECT id, fullname, username, email, user_type
            FROM bakery_users
            WHERE user_type = ? AND is_active = 1
        ";
        $params = [$user_type];
        $types = 's';
        
        if (!empty($term)) {
            $sql .= " AND (fullname LIKE ? OR username LIKE ? OR email LIKE ?)";
            $searchTerm = "%$term%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= 'sss';
        }
        
        $sql .= " ORDER BY fullname LIMIT 50";
        
        $users = $db->preparedFetchAll($sql, $types, $params);
        $response = ['success' => true, 'users' => $users];
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// =====================================================
// PAGE DATA
// =====================================================
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

// Stats
$active_sessions_count = $db->preparedFetchOne("SELECT COUNT(*) as count FROM user_sessions WHERE is_active = 1", '', [])['count'] ?? 0;
$staff_sessions_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count 
    FROM user_sessions us 
    JOIN bakery_users u ON us.user_id = u.id 
    WHERE us.is_active = 1 AND u.user_type = 'staff'
", '', [])['count'] ?? 0;
$locked_accounts_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count 
    FROM bakery_users 
    WHERE account_locked_until IS NOT NULL AND account_locked_until > NOW()
", '', [])['count'] ?? 0;
$failed_logins_today = $db->preparedFetchOne("
    SELECT COUNT(*) as count 
    FROM login_attempts 
    WHERE success = 0 AND DATE(attempted_at) = CURDATE()
", '', [])['count'] ?? 0;

// Locked accounts
$locked_accounts = $db->preparedFetchAll("
    SELECT id, fullname, username, email, user_type, account_locked_until
    FROM bakery_users 
    WHERE account_locked_until IS NOT NULL AND account_locked_until > NOW()
    ORDER BY account_locked_until
", '', []);

// Branches for dropdown
$all_branches = $db->preparedFetchAll("SELECT id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name", '', []);
// Departments for dropdown
$all_departments = $db->preparedFetchAll("SELECT id, dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name", '', []);
// Roles for dropdown
$all_roles = $db->preparedFetchAll("SELECT id, role_name FROM roles ORDER BY role_name", '', []);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>IT Dashboard · Fingerchops Ventures</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/it-dashboard.css">
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
    <script>
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
        const IS_HEADQUARTERS = <?php echo $is_headquarters ? 'true' : 'false'; ?>;
        const USER_BRANCH_ID = <?php echo $user_branch_id; ?>;
        
        // Preloaded data for dropdowns
        const BRANCHES = <?php echo json_encode($all_branches); ?>;
        const DEPARTMENTS = <?php echo json_encode($all_departments); ?>;
        const ROLES = <?php echo json_encode($all_roles); ?>;
    </script>
</head>
<body>
    <div class="preloader" id="preloader">
        <img src="../../logo.jpeg" alt="Fingerchops" class="preloader-logo" onerror="this.style.display='none'">
    </div>
    
    <div class="refresh-indicator" id="refreshIndicator">
        <i class="fas fa-sync-alt"></i>
        <span id="refreshTimer">60</span>s
    </div>
    
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-left">
                <h1><i class="fas fa-shield-alt"></i> IT Security Dashboard</h1>
                <div class="header-meta">
                    <span class="header-date"><i class="far fa-calendar-alt"></i> <?php echo $current_date; ?></span>
                    <span class="header-time"><i class="far fa-clock"></i> <?php echo $current_time; ?></span>
                </div>
            </div>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($user['fullname']); ?></span>
                <span class="role-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($role_name); ?></span>
                <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <div class="stat-value"><?php echo number_format($active_sessions_count); ?></div>
                <div class="stat-label">Active Sessions</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-tie"></i>
                <div class="stat-value"><?php echo number_format($staff_sessions_count); ?></div>
                <div class="stat-label">Staff Online</div>
            </div>
            <div class="stat-card warning">
                <i class="fas fa-lock"></i>
                <div class="stat-value"><?php echo number_format($locked_accounts_count); ?></div>
                <div class="stat-label">Locked Accounts</div>
            </div>
            <div class="stat-card danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="stat-value"><?php echo number_format($failed_logins_today); ?></div>
                <div class="stat-label">Failed Logins Today</div>
            </div>
        </div>
        
        <div class="tabs">
            <button class="tab active" data-tab="sessions"><i class="fas fa-user-clock"></i> Sessions (Staff)</button>
            <button class="tab" data-tab="activity"><i class="fas fa-history"></i> Activity Log</button>
            <button class="tab" data-tab="customers"><i class="fas fa-users"></i> Customer Actions</button>
            <button class="tab" data-tab="alerts"><i class="fas fa-bell"></i> Security Alerts</button>
        </div>
        
        <!-- Sessions Tab -->
        <div id="sessions-tab" class="tab-content active">
            <div class="section-header">
                <h2><i class="fas fa-user-clock"></i> Active Staff Sessions</h2>
                <div class="filter-group">
                    <select id="sessionFilter" class="filter-select">
                        <option value="staff">Staff Only</option>
                        <option value="all">All Users</option>
                        <option value="customers">Customers</option>
                        <option value="vendors">Vendors</option>
                    </select>
                    <button id="refreshSessionsBtn" class="btn-secondary btn-sm"><i class="fas fa-sync-alt"></i> Refresh</button>
                </div>
            </div>
            
            <?php if (!empty($locked_accounts)): ?>
            <div class="locked-accounts-section">
                <h3><i class="fas fa-lock"></i> Currently Locked Accounts</h3>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Type</th>
                                <th>Locked Until</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($locked_accounts as $locked): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($locked['fullname']); ?></strong>
                                    <small><?php echo htmlspecialchars($locked['email']); ?></small>
                                </td>
                                <td><span class="badge badge-info"><?php echo ucfirst($locked['user_type']); ?></span></td>
                                <td><span class="badge badge-danger"><?php echo date('M j, H:i', strtotime($locked['account_locked_until'])); ?></span></td>
                                <td>
                                    <button class="btn-sm btn-success unlock-account-btn" data-user-id="<?php echo $locked['id']; ?>">
                                        <i class="fas fa-unlock"></i> Unlock
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div id="sessionsContainer">
                <div class="loading-pulse">Loading active sessions...</div>
            </div>
        </div>
        
        <!-- Activity Tab -->
        <div id="activity-tab" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Staff Activity Log</h2>
                <div class="filter-group">
                    <select id="activityFilter" class="filter-select">
                        <option value="all">All Users</option>
                        <option value="staff" selected>Staff Only</option>
                        <option value="customers">Customers</option>
                        <option value="vendors">Vendors</option>
                    </select>
                    <input type="date" id="activityDateFrom" class="filter-input" placeholder="From">
                    <input type="date" id="activityDateTo" class="filter-input" placeholder="To">
                    <button id="applyActivityFilterBtn" class="btn-primary btn-sm">Apply</button>
                </div>
            </div>
            
            <div id="activityContainer">
                <div class="loading-pulse">Loading activity log...</div>
            </div>
        </div>
        
        <!-- Customer Actions Tab -->
        <div id="customers-tab" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-users"></i> Customer Actions</h2>
                <div class="filter-group">
                    <input type="date" id="customerDateFrom" class="filter-input" placeholder="From">
                    <input type="date" id="customerDateTo" class="filter-input" placeholder="To">
                    <button id="applyCustomerFilterBtn" class="btn-primary btn-sm">Apply</button>
                </div>
            </div>
            
            <div id="customerActionsContainer">
                <div class="loading-pulse">Loading customer actions...</div>
            </div>
        </div>
        
        <!-- Alerts Tab -->
        <div id="alerts-tab" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-bell"></i> Security Notifications & Alerts</h2>
                <a href="tools/notifications.php" class="dashboard-link"><i class="fas fa-external-link-alt"></i> Manage Notifications</a>
            </div>
            
            <div class="tool-cards-grid">
                <div class="tool-card placeholder">
                    <i class="fas fa-chart-line"></i>
                    <h3>Security Report</h3>
                    <p>Generate security activity report (Coming Soon)</p>
                </div>
                
                <div class="tool-card placeholder">
                    <i class="fas fa-network-wired"></i>
                    <h3>IP Blacklist</h3>
                    <p>Manage blocked IP addresses (Coming Soon)</p>
                </div>
            </div>
        </div>
        
        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> All actions are logged · Auto-refresh every 60s · IT Security Dashboard
        </div>
    </div>
    
    <!-- Create Notification modal removed — use central notifications tool at tools/notifications.php -->
    
    <!-- Session Details Modal -->
    <div id="sessionDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Session Details</h3>
                <button class="modal-close" onclick="closeModal('sessionDetailsModal')">&times;</button>
            </div>
            <div class="modal-body" id="sessionDetailsBody"></div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('sessionDetailsModal')">Close</button>
                <button class="btn-danger" id="terminateSessionBtn">
                    <i class="fas fa-ban"></i> Terminate Session
                </button>
            </div>
        </div>
    </div>
    
    <!-- Lock Account Modal -->
    <div id="lockAccountModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Lock Account</h3>
                <button class="modal-close" onclick="closeModal('lockAccountModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="lockUserId">
                <div class="form-group">
                    <label>Lock Duration</label>
                    <select id="lockDuration">
                        <option value="15">15 minutes</option>
                        <option value="30">30 minutes</option>
                        <option value="60">1 hour</option>
                        <option value="240">4 hours</option>
                        <option value="1440">24 hours</option>
                        <option value="10080">7 days</option>
                        <option value="0">Unlock Account</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('lockAccountModal')">Cancel</button>
                <button class="btn-primary" id="confirmLockBtn">Confirm</button>
            </div>
        </div>
    </div>
    
    <div id="toastContainer" class="toast-container"></div>
    <div id="overlay" class="overlay"></div>
    
    <script>
        let refreshCountdown = 60;
        let refreshTimer = null;
        let currentSessionId = null;
        let currentTargetTab = 'staff';
        
        // Selected items for notifications
        let selectedStaff = new Map();
        let selectedCustomers = new Map();
        let selectedVendors = new Map();
        
        // ========== SCROLL POSITION ==========
        function saveScrollPosition() {
            localStorage.setItem('itScrollPos', window.scrollY);
            localStorage.setItem('itActiveTab', document.querySelector('.tab.active')?.dataset.tab || 'sessions');
        }
        
        function restoreScrollPosition() {
            const savedPos = localStorage.getItem('itScrollPos');
            const savedTab = localStorage.getItem('itActiveTab');
            
            if (savedTab) {
                const tab = document.querySelector(`.tab[data-tab="${savedTab}"]`);
                if (tab) tab.click();
            }
            
            if (savedPos) {
                window.scrollTo(0, parseInt(savedPos));
                localStorage.removeItem('itScrollPos');
                localStorage.removeItem('itActiveTab');
            }
        }
        
        window.addEventListener('beforeunload', saveScrollPosition);
        
        // ========== INITIALIZATION ==========
        window.addEventListener('load', function() {
            setTimeout(() => {
                const preloader = document.getElementById('preloader');
                if (preloader) {
                    preloader.classList.add('fade-out');
                    setTimeout(() => { if (preloader) preloader.style.display = 'none'; }, 500);
                }
            }, 500);
            
            restoreScrollPosition();
            loadSessions();
            startAutoRefresh();
        });
        
        function startAutoRefresh() {
            if (refreshTimer) clearInterval(refreshTimer);
            const timerElement = document.getElementById('refreshTimer');
            refreshCountdown = 60;
            
            refreshTimer = setInterval(() => {
                refreshCountdown--;
                if (timerElement) timerElement.textContent = refreshCountdown;
                if (refreshCountdown <= 0) {
                    saveScrollPosition();
                    window.location.reload();
                }
            }, 1000);
        }
        
        // ========== TOAST ==========
        function showToast(msg, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${escapeHtml(msg)}<span onclick="this.parentElement.remove()">×</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.getElementById('overlay').classList.remove('active');
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.getElementById('overlay').classList.add('active');
        }
        
        // ========== AJAX ==========
        async function ajaxPost(data) {
            data.append('csrf_token', CSRF_TOKEN);
            const response = await fetch(window.location.href, { method: 'POST', body: data });
            return await response.json();
        }
        
        // ========== TARGET TABS ==========
        document.querySelectorAll('.target-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.target-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.target-panel').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(`target-${this.dataset.target}`).classList.add('active');
                currentTargetTab = this.dataset.target;
            });
        });
        
        // Selection type toggles
        document.getElementById('staffSelectionType')?.addEventListener('change', function() {
            document.getElementById('staffSpecificOptions').style.display = this.value === 'specific' ? 'block' : 'none';
        });
        
        document.getElementById('customersSelectionType')?.addEventListener('change', function() {
            document.getElementById('customersSpecificOptions').style.display = this.value === 'specific' ? 'block' : 'none';
        });
        
        document.getElementById('vendorsSelectionType')?.addEventListener('change', function() {
            document.getElementById('vendorsSpecificOptions').style.display = this.value === 'specific' ? 'block' : 'none';
        });
        
        // ========== SEARCH USERS ==========
        let searchTimeout;
        
        function setupSearch(inputId, resultsId, userType, selectedMap, listId) {
            const input = document.getElementById(inputId);
            const resultsDiv = document.getElementById(resultsId);
            
            input?.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const term = this.value.trim();
                
                if (term.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }
                
                searchTimeout = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax', 1);
                    formData.append('action', 'search_users');
                    formData.append('term', term);
                    formData.append('user_type', userType);
                    
                    try {
                        const data = await ajaxPost(formData);
                        if (data.success && data.users) {
                            resultsDiv.innerHTML = data.users.map(u => `
                                <div class="search-result-item" onclick="addSelectedItem('${userType}', ${u.id}, '${escapeHtml(u.fullname)}', '${escapeHtml(u.email)}')">
                                    <strong>${escapeHtml(u.fullname)}</strong>
                                    <small>${escapeHtml(u.email)}</small>
                                </div>
                            `).join('');
                            resultsDiv.style.display = 'block';
                        } else {
                            resultsDiv.innerHTML = '<div class="search-result-item">No users found</div>';
                            resultsDiv.style.display = 'block';
                        }
                    } catch(e) {
                        console.error('Search error:', e);
                    }
                }, 300);
            });
            
            input?.addEventListener('blur', () => {
                setTimeout(() => resultsDiv.style.display = 'none', 200);
            });
        }
        
        setupSearch('staffSearch', 'staffSearchResults', 'staff', selectedStaff, 'selectedStaffList');
        setupSearch('customerSearch', 'customerSearchResults', 'customer', selectedCustomers, 'selectedCustomersList');
        setupSearch('vendorSearch', 'vendorSearchResults', 'vendor', selectedVendors, 'selectedVendorsList');
        
        window.addSelectedItem = function(userType, id, name, email) {
            let selectedMap, listId;
            
            if (userType === 'staff') {
                selectedMap = selectedStaff;
                listId = 'selectedStaffList';
            } else if (userType === 'customer') {
                selectedMap = selectedCustomers;
                listId = 'selectedCustomersList';
            } else {
                selectedMap = selectedVendors;
                listId = 'selectedVendorsList';
            }
            
            if (!selectedMap.has(id)) {
                selectedMap.set(id, { id, name, email });
                updateSelectedList(selectedMap, listId);
            }
        };
        
        function updateSelectedList(selectedMap, listId) {
            const container = document.getElementById(listId);
            let html = '';
            
            selectedMap.forEach((item) => {
                html += `
                    <div class="selected-item">
                        <span>${escapeHtml(item.name)} (${escapeHtml(item.email)})</span>
                        <button type="button" onclick="removeSelectedItem('${listId}', ${item.id})" class="remove-btn">&times;</button>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        window.removeSelectedItem = function(listId, id) {
            if (listId === 'selectedStaffList') {
                selectedStaff.delete(id);
                updateSelectedList(selectedStaff, 'selectedStaffList');
            } else if (listId === 'selectedCustomersList') {
                selectedCustomers.delete(id);
                updateSelectedList(selectedCustomers, 'selectedCustomersList');
            } else if (listId === 'selectedVendorsList') {
                selectedVendors.delete(id);
                updateSelectedList(selectedVendors, 'selectedVendorsList');
            }
        };
        
        // Create notification handlers removed — centralize notifications in tools/notifications.php

        
        // ========== SESSIONS ==========
        async function loadSessions() {
            const container = document.getElementById('sessionsContainer');
            container.innerHTML = '<div class="loading-pulse">Loading sessions...</div>';
            
            const filter = document.getElementById('sessionFilter')?.value || 'staff';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_sessions');
            formData.append('filter', filter);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.sessions && data.sessions.length > 0) {
                    let html = '<div class="table-wrapper"><table class="data-table"><thead><tr>';
                    html += '<th>User</th><th>Type</th><th>Branch</th><th>Session ID</th><th>IP Address</th><th>Last Activity</th><th>Actions</th>';
                    html += '</tr></thead><tbody>';
                    
                    for (const s of data.sessions) {
                        const shortSessionId = s.session_id.substring(0, 8) + '...';
                        const isLocked = s.account_locked_until && new Date(s.account_locked_until) > new Date();
                        
                        html += `<tr>
                            <td>
                                <strong>${escapeHtml(s.fullname)}</strong>
                                <small>${escapeHtml(s.email)}</small>
                                ${isLocked ? '<span class="badge badge-danger">Locked</span>' : ''}
                            </td>
                            <td><span class="badge badge-info">${s.user_type}</span></td>
                            <td>${escapeHtml(s.branch_name || '—')}</td>
                            <td><code>${shortSessionId}</code></td>
                            <td>${escapeHtml(s.ip_address)}</td>
                            <td>${new Date(s.last_activity).toLocaleString()}</td>
                            <td>
                                <button class="btn-sm btn-secondary" onclick='viewSessionDetails(${JSON.stringify(s)})'>
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn-sm btn-danger" onclick="terminateSession('${s.session_id}')">
                                    <i class="fas fa-ban"></i>
                                </button>
                                <button class="btn-sm btn-warning" onclick="openLockModal(${s.user_id})">
                                    <i class="fas fa-lock"></i>
                                </button>
                            </td>
                        </tr>`;
                    }
                    
                    html += '</tbody></table></div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="empty-state">No active sessions found.</div>';
                }
            } catch(e) {
                container.innerHTML = '<div class="error-state">Error loading sessions.</div>';
            }
        }
        
        window.viewSessionDetails = function(session) {
            const body = document.getElementById('sessionDetailsBody');
            currentSessionId = session.session_id;
            
            body.innerHTML = `
                <div class="detail-row"><strong>User:</strong> ${escapeHtml(session.fullname)} (${escapeHtml(session.username)})</div>
                <div class="detail-row"><strong>Email:</strong> ${escapeHtml(session.email)}</div>
                <div class="detail-row"><strong>User Type:</strong> ${escapeHtml(session.user_type)}</div>
                <div class="detail-row"><strong>Branch:</strong> ${escapeHtml(session.branch_name || '—')}</div>
                <div class="detail-row"><strong>Session ID:</strong> <code>${escapeHtml(session.session_id)}</code></div>
                <div class="detail-row"><strong>IP Address:</strong> ${escapeHtml(session.ip_address)}</div>
                <div class="detail-row"><strong>User Agent:</strong> ${escapeHtml(session.user_agent || '—')}</div>
                <div class="detail-row"><strong>Login Time:</strong> ${new Date(session.login_time).toLocaleString()}</div>
                <div class="detail-row"><strong>Last Activity:</strong> ${new Date(session.last_activity).toLocaleString()}</div>
            `;
            
            openModal('sessionDetailsModal');
        };
        
        window.terminateSession = async function(sessionId) {
            if (!confirm('Terminate this session? The user will be logged out immediately.')) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'terminate_session');
            formData.append('session_id', sessionId);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('sessionDetailsModal');
                    loadSessions();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error', 'error');
            }
        };
        
        document.getElementById('terminateSessionBtn')?.addEventListener('click', () => {
            if (currentSessionId) terminateSession(currentSessionId);
        });
        
        window.openLockModal = function(userId) {
            document.getElementById('lockUserId').value = userId;
            openModal('lockAccountModal');
        };
        
        document.getElementById('confirmLockBtn')?.addEventListener('click', async () => {
            const userId = document.getElementById('lockUserId').value;
            const duration = document.getElementById('lockDuration').value;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'toggle_account_lock');
            formData.append('user_id', userId);
            formData.append('lock_duration', duration);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('lockAccountModal');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error', 'error');
            }
        });
        
        document.querySelectorAll('.unlock-account-btn')?.forEach(btn => {
            btn.addEventListener('click', async function() {
                const userId = this.dataset.userId;
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'toggle_account_lock');
                formData.append('user_id', userId);
                formData.append('lock_duration', 0);
                
                try {
                    const data = await ajaxPost(formData);
                    if (data.success) {
                        showToast(data.message, 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch(e) {
                    showToast('Network error', 'error');
                }
            });
        });
        
        // ========== ACTIVITY LOG ==========
        async function loadActivities() {
            const container = document.getElementById('activityContainer');
            container.innerHTML = '<div class="loading-pulse">Loading activity...</div>';
            
            const filter = document.getElementById('activityFilter')?.value || 'staff';
            const dateFrom = document.getElementById('activityDateFrom')?.value || '';
            const dateTo = document.getElementById('activityDateTo')?.value || '';
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_activities');
            formData.append('filter', filter);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.activities && data.activities.length > 0) {
                    let html = '<div class="table-wrapper"><table class="data-table"><thead><tr>';
                    html += '<th>Time</th><th>User</th><th>Type</th><th>Action</th><th>Entity</th></tr></thead><tbody>';
                    
                    for (const a of data.activities) {
                        html += `<tr>
                            <td>${new Date(a.created_at).toLocaleString()}</td>
                            <td>${escapeHtml(a.fullname)}</td>
                            <td><span class="badge badge-info">${a.user_type}</span></td>
                            <td>${escapeHtml(a.action)}</td>
                            <td>${a.entity_type ? escapeHtml(a.entity_type) + ' #' + a.entity_id : '—'}</td>
                        </tr>`;
                    }
                    
                    html += '</tbody></table></div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="empty-state">No activity found.</div>';
                }
            } catch(e) {
                container.innerHTML = '<div class="error-state">Error loading activity.</div>';
            }
        }
        
        // ========== CUSTOMER ACTIONS ==========
        async function loadCustomerActions() {
            const container = document.getElementById('customerActionsContainer');
            container.innerHTML = '<div class="loading-pulse">Loading customer actions...</div>';
            
            const dateFrom = document.getElementById('customerDateFrom')?.value || '';
            const dateTo = document.getElementById('customerDateTo')?.value || '';
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_customer_actions');
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.actions && data.actions.length > 0) {
                    let html = '<div class="table-wrapper"><table class="data-table"><thead><tr>';
                    html += '<th>Time</th><th>Customer</th><th>Action Type</th><th>Details</th><th>Amount</th><th>Status</th></tr></thead><tbody>';
                    
                    for (const a of data.actions) {
                        html += `<tr>
                            <td>${new Date(a.created_at).toLocaleString()}</td>
                            <td>${escapeHtml(a.fullname)}</td>
                            <td><span class="badge badge-primary">${a.action_type}</span></td>
                            <td>${escapeHtml(a.description)}</td>
                            <td>${a.amount ? '₦' + parseFloat(a.amount).toFixed(2) : '—'}</td>
                            <td><span class="badge badge-${a.status === 'completed' ? 'success' : 'warning'}">${a.status}</span></td>
                        </tr>`;
                    }
                    
                    html += '</tbody></table></div>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="empty-state">No customer actions found.</div>';
                }
            } catch(e) {
                container.innerHTML = '<div class="error-state">Error loading customer actions.</div>';
            }
        }
        
        // ========== NOTIFICATIONS ==========
        async function loadNotifications() {
            const container = document.querySelector('#notificationsContainer');
            if (!container) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_notifications');
            
            try {
                const data = await ajaxPost(formData);
                let html = '<h3><i class="fas fa-list"></i> Active Notifications</h3>';
                
                if (data.success && data.notifications && data.notifications.length > 0) {
                    html += '<div class="notifications-list">';
                    
                    for (const n of data.notifications) {
                        const typeClass = `notification-${n.type}`;
                        html += `
                            <div class="notification-card ${typeClass}">
                                <div class="notification-header">
                                    <span class="notification-badge">${n.type}</span>
                                    <span class="notification-time">${new Date(n.created_at).toLocaleString()}</span>
                                </div>
                                <div class="notification-body">
                                    <p>${escapeHtml(n.message)}</p>
                                </div>
                                <div class="notification-footer">
                                    <span><i class="fas fa-bullseye"></i> Targets: ${n.target_types || 'All'}</span>
                                    ${n.expires_at ? `<span><i class="fas fa-calendar"></i> Expires: ${new Date(n.expires_at).toLocaleString()}</span>` : ''}
                                </div>
                            </div>
                        `;
                    }
                    
                    html += '</div>';
                } else {
                    html += '<div class="empty-state">No active notifications.</div>';
                }
                
                container.innerHTML = html;
            } catch(e) {
                container.innerHTML = '<h3><i class="fas fa-list"></i> Active Notifications</h3><div class="error-state">Error loading notifications.</div>';
            }
        }
        
        // ========== EVENT LISTENERS ==========
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.getElementById(this.dataset.tab + '-tab').classList.add('active');
                
                if (this.dataset.tab === 'activity') {
                    loadActivities();
                } else if (this.dataset.tab === 'customers') {
                    loadCustomerActions();
                } else if (this.dataset.tab === 'alerts') {
                    loadNotifications();
                }
            });
        });
        
        document.getElementById('refreshSessionsBtn')?.addEventListener('click', loadSessions);
        document.getElementById('sessionFilter')?.addEventListener('change', loadSessions);
        document.getElementById('applyActivityFilterBtn')?.addEventListener('click', loadActivities);
        document.getElementById('applyCustomerFilterBtn')?.addEventListener('click', loadCustomerActions);
        
        document.getElementById('overlay')?.addEventListener('click', function() {
            document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
            this.classList.remove('active');
        });
    </script>
</body>
</html>