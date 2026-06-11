<?php
// =====================================================
// FILE: admin_power/departments.php
// PURPOSE: Manage departments, staff, and track changes
// ACCESS: Admin only (Level 100)
// VERSION: 12.0 - ALL ACTIONS WORKING (Branch, Role, Department)
// =====================================================

if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

session_start();

require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
require_once '../../../config/config_loader.php';

$db = Database::getInstance();

// Security check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login_signup.php');
    exit;
}

$userObj = new User($_SESSION['user_id']);
$user = $userObj->getData();

if (!$user || $userObj->getPrivilegeLevel() < 100) {
    header('Location: ../../../login_signup.php');
    exit;
}

// CSRF token
if (!isset($_SESSION['dept_csrf_token'])) {
    $_SESSION['dept_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['dept_csrf_token'];

// Branch filter
$admin_branch_id = $user['branch_id'];
$selected_branch = isset($_GET['branch']) ? (int)$_GET['branch'] : ($admin_branch_id ?? 0);
$all_branches = [];
if ($admin_branch_id === null) {
    $branch_query = $db->query("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name");
    if ($branch_query) {
        while ($row = mysqli_fetch_assoc($branch_query)) {
            $all_branches[] = $row;
        }
    }
}
$selected_branch_name = '';
if ($selected_branch > 0) {
    $branch_info = $db->query("SELECT branch_name FROM branches WHERE id = $selected_branch");
    if ($branch_info && mysqli_num_rows($branch_info) > 0) {
        $selected_branch_name = mysqli_fetch_assoc($branch_info)['branch_name'];
    }
}

// ==================== AJAX HANDLERS ====================
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['dept_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $dept_id = (int)($_POST['dept_id'] ?? 0);
    $branch_id = (int)($_POST['branch_id'] ?? $selected_branch);

    // ========== GET BRANCHES ==========
    if ($action === 'get_branches') {
        $branches = $db->preparedFetchAll("SELECT id, branch_code, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name", '', []);
        $response['success'] = true;
        $response['branches'] = $branches;
        echo json_encode($response);
        exit;
    }

    // ========== UPDATE USER BRANCH (UPDATES bakery_users.branch_id) ==========
    // ========== UPDATE USER BRANCH (UPDATES bakery_users.branch_id) ==========
    if ($action === 'update_user_branch') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_branch_id = (int)($_POST['branch_id'] ?? 0);
        
        if ($user_id <= 0) {
            $response['message'] = 'Invalid user';
            echo json_encode($response);
            exit;
        }
        
        // Get current user data
        $current_data = $db->preparedFetchOne("SELECT fullname, branch_id FROM bakery_users WHERE id = ?", 'i', [$user_id]);
        
        if (!$current_data) {
            $response['message'] = 'User not found';
            echo json_encode($response);
            exit;
        }
        
        $old_branch_id = $current_data['branch_id'] ?? null;
        
        // Get branch names
        $old_branch_name = 'Unassigned';
        if ($old_branch_id) {
            $old_branch = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$old_branch_id]);
            $old_branch_name = $old_branch['branch_name'] ?? 'Unassigned';
        }
        
        $new_branch_name = 'Headquarters';
        if ($new_branch_id > 0) {
            $new_branch = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$new_branch_id]);
            $new_branch_name = $new_branch['branch_name'] ?? 'Unknown Branch';
        } elseif ($new_branch_id == 0) {
            $new_branch_name = 'Headquarters (No Branch)';
        }
        
        // ACTUALLY UPDATE THE bakery_users TABLE
        $result = $db->preparedExecute(
            "UPDATE bakery_users SET branch_id = ? WHERE id = ?", 
            'ii', 
            [$new_branch_id ?: null, $user_id]
        );
        
        if ($result) {
            // Verify the update worked
            $verify = $db->preparedFetchOne("SELECT branch_id FROM bakery_users WHERE id = ?", 'i', [$user_id]);
            
            if ($verify && $verify['branch_id'] == ($new_branch_id ?: null)) {
                // Record the change in established_changes
                $db->preparedExecute(
                    "INSERT INTO established_changes (user_id, change_type, title, description, old_value, new_value, created_by) 
                    VALUES (?, 'branch_transfer', ?, ?, ?, ?, ?)",
                    'issssi',
                    [$user_id, "Branch Transfer: {$current_data['fullname']}", "Moved from {$old_branch_name} to {$new_branch_name}", $old_branch_name, $new_branch_name, $_SESSION['user_id']]
                );
                
                $response['success'] = true;
                $response['message'] = "Branch updated: {$old_branch_name} → {$new_branch_name}";
            } else {
                $response['success'] = false;
                $response['message'] = 'Branch update verification failed';
            }
        } else {
            $response['message'] = 'Failed to update branch in database';
        }
        
        echo json_encode($response);
        exit;
    }

    // ========== UPDATE STAFF (UPDATES bakery_users AND user_roles.role_id) ==========
    if ($action === 'update_staff') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $new_role_id = (int)($_POST['role_id'] ?? 0);
        $current_dept_id = (int)($_POST['current_dept_id'] ?? 0);

        if ($user_id <= 0 || empty($fullname) || empty($email) || empty($phone)) {
            $response['message'] = 'Missing required fields';
            echo json_encode($response);
            exit;
        }

        $validator = new Validation();
        if (!$validator->validateEmail($email)) {
            $response['message'] = 'Invalid email';
            echo json_encode($response);
            exit;
        }
        if (!$validator->validatePhone($phone, 'Phone', 'NG')) {
            $response['message'] = 'Invalid phone number';
            echo json_encode($response);
            exit;
        }

        $current_user = new User($user_id);
        $current_data = $current_user->getData();
        
        // Check uniqueness
        if ($current_data['email'] !== $email && !$validator->isEmailUnique($email, $user_id)) {
            $response['message'] = 'Email already used by another user';
            echo json_encode($response);
            exit;
        }
        if ($current_data['phone'] !== $phone && !$validator->isPhoneUnique($phone, $user_id)) {
            $response['message'] = 'Phone number already used by another user';
            echo json_encode($response);
            exit;
        }

        $db->beginTransaction();
        
        try {
            // 1. UPDATE bakery_users table
            $update_result = $db->preparedExecute(
                "UPDATE bakery_users SET fullname = ?, email = ?, phone = ? WHERE id = ?",
                'sssi',
                [$fullname, $email, $phone, $user_id]
            );
            
            if (!$update_result) {
                throw new Exception('Failed to update user details');
            }

            // 2. UPDATE user_roles table (role in current department)
            if ($new_role_id > 0) {
                // Get current role
                $current_role = $db->preparedFetchOne(
                    "SELECT r.role_name 
                    FROM user_roles ur 
                    JOIN roles r ON ur.role_id = r.id 
                    WHERE ur.user_id = ? AND ur.department_id = ? AND ur.is_active = 1",
                    'ii', [$user_id, $current_dept_id]
                );
                
                $old_role_name = $current_role['role_name'] ?? 'No Role';
                $new_role = $db->preparedFetchOne("SELECT role_name FROM roles WHERE id = ?", 'i', [$new_role_id]);
                $new_role_name = $new_role['role_name'] ?? 'Unknown';
                
                if ($old_role_name !== $new_role_name) {
                    // Deactivate current role
                    $db->preparedExecute(
                        "UPDATE user_roles SET is_active = 0 WHERE user_id = ? AND department_id = ? AND is_active = 1",
                        'ii', [$user_id, $current_dept_id]
                    );
                    
                    // Insert new role
                    $insert = $db->preparedExecute(
                        "INSERT INTO user_roles (user_id, role_id, department_id, assigned_by, assigned_date, is_active)
                        VALUES (?, ?, ?, ?, NOW(), 1)",
                        'iiii', [$user_id, $new_role_id, $current_dept_id, $_SESSION['user_id']]
                    );
                    
                    if (!$insert) {
                        throw new Exception('Failed to assign new role');
                    }
                    
                    // Record role change
                    $db->preparedExecute(
                        "INSERT INTO established_changes (user_id, change_type, title, description, old_value, new_value, created_by) 
                        VALUES (?, 'role_change', ?, ?, ?, ?, ?)",
                        'issssi',
                        [$user_id, "Role Change: {$current_data['fullname']}", "Role changed from {$old_role_name} to {$new_role_name}", $old_role_name, $new_role_name, $_SESSION['user_id']]
                    );
                }
            }
            
            $db->commit();
            $response['success'] = true;
            $response['message'] = 'Staff updated successfully';
            
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }

    // ========== TRANSFER STAFF (UPDATES user_roles.department_id AND role_id) ==========
    if ($action === 'transfer_staff') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_role_id = (int)($_POST['role_id'] ?? 0);
        $new_dept_id = (int)($_POST['department_id'] ?? 0);

        if ($user_id <= 0 || $new_role_id <= 0 || $new_dept_id <= 0) {
            $response['message'] = 'Invalid data';
            echo json_encode($response);
            exit;
        }

        $current_user = new User($user_id);
        $current_data = $current_user->getData();
        
        // Get current department and role
        $current_role_data = $db->preparedFetchOne(
            "SELECT d.dept_name, r.role_name 
            FROM user_roles ur 
            JOIN departments d ON ur.department_id = d.id 
            JOIN roles r ON ur.role_id = r.id 
            WHERE ur.user_id = ? AND ur.is_active = 1",
            'i', [$user_id]
        );
        
        $old_dept_name = $current_role_data['dept_name'] ?? 'None';
        $old_role_name = $current_role_data['role_name'] ?? 'None';
        
        $new_dept = $db->preparedFetchOne("SELECT dept_name FROM departments WHERE id = ?", 'i', [$new_dept_id]);
        $new_role = $db->preparedFetchOne("SELECT role_name FROM roles WHERE id = ?", 'i', [$new_role_id]);
        $new_dept_name = $new_dept['dept_name'] ?? 'Unknown';
        $new_role_name = $new_role['role_name'] ?? 'Unknown';
        
        // Check if user already has a role in the new department
        $existing = $db->preparedFetchOne(
            "SELECT id FROM user_roles WHERE user_id = ? AND department_id = ? AND is_active = 1", 
            'ii', [$user_id, $new_dept_id]
        );
        
        if ($existing) {
            $response['message'] = 'Staff already has a role in this department';
            echo json_encode($response);
            exit;
        }

        $db->beginTransaction();
        
        try {
            // Deactivate ALL current active roles
            $db->preparedExecute(
                "UPDATE user_roles SET is_active = 0 WHERE user_id = ? AND is_active = 1",
                'i', [$user_id]
            );

            // Insert new role in new department
            $insert = $db->preparedExecute(
                "INSERT INTO user_roles (user_id, role_id, department_id, assigned_by, assigned_date, is_active)
                VALUES (?, ?, ?, ?, NOW(), 1)",
                'iiii', [$user_id, $new_role_id, $new_dept_id, $_SESSION['user_id']]
            );
            
            if (!$insert) {
                throw new Exception('Failed to assign new role');
            }
            
            // Record transfer
            $change_desc = "Transferred from {$old_dept_name} ({$old_role_name}) to {$new_dept_name} ({$new_role_name})";
            $db->preparedExecute(
                "INSERT INTO established_changes (user_id, change_type, title, description, old_value, new_value, created_by) 
                VALUES (?, 'department_transfer', ?, ?, ?, ?, ?)",
                'issssi',
                [$user_id, "Department Transfer: {$current_data['fullname']}", $change_desc, "{$old_dept_name} - {$old_role_name}", "{$new_dept_name} - {$new_role_name}", $_SESSION['user_id']]
            );
            
            $db->commit();
            $response['success'] = true;
            $response['message'] = "Staff transferred: {$old_dept_name} → {$new_dept_name}";
            
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = 'Transfer failed: ' . $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }

    // ========== GET ESTABLISHED CHANGES ==========
    if ($action === 'get_established_changes') {
        $change_type = $_POST['change_type'] ?? '';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        $user_id_filter = (int)($_POST['user_id_filter'] ?? 0);
        
        $sql = "SELECT ec.*, u.fullname as user_name, u.username,
                       creator.fullname as creator_name
                FROM established_changes ec
                JOIN bakery_users u ON ec.user_id = u.id
                LEFT JOIN bakery_users creator ON ec.created_by = creator.id
                WHERE 1=1";
        $params = [];
        $types = '';
        
        if (!empty($change_type) && $change_type !== 'all') {
            $types_arr = explode(',', $change_type);
            $placeholders = implode(',', array_fill(0, count($types_arr), '?'));
            $sql .= " AND ec.change_type IN ($placeholders)";
            foreach ($types_arr as $t) {
                $params[] = trim($t);
                $types .= 's';
            }
        }
        
        if (!empty($date_from)) {
            $sql .= " AND DATE(ec.created_at) >= ?";
            $params[] = $date_from;
            $types .= 's';
        }
        
        if (!empty($date_to)) {
            $sql .= " AND DATE(ec.created_at) <= ?";
            $params[] = $date_to;
            $types .= 's';
        }
        
        if ($user_id_filter > 0) {
            $sql .= " AND ec.user_id = ?";
            $params[] = $user_id_filter;
            $types .= 'i';
        }
        
        $sql .= " ORDER BY ec.created_at DESC LIMIT 100";
        
        $changes = $db->preparedFetchAll($sql, $types, $params);
        
        $response['success'] = true;
        $response['changes'] = $changes ?: [];
        echo json_encode($response);
        exit;
    }

    // ========== DELETE CHANGE ==========
    if ($action === 'delete_change') {
        $change_id = (int)($_POST['change_id'] ?? 0);
        if ($change_id <= 0) {
            $response['message'] = 'Invalid change ID';
            echo json_encode($response);
            exit;
        }
        
        $result = $db->preparedExecute("DELETE FROM established_changes WHERE id = ?", 'i', [$change_id]);
        if ($result) {
            $response['success'] = true;
            $response['message'] = 'Change deleted successfully';
        } else {
            $response['message'] = 'Failed to delete change';
        }
        echo json_encode($response);
        exit;
    }

    // ========== GET ROLES FOR DEPARTMENT ==========
    if ($action === 'get_roles') {
        $dept_id = (int)($_POST['dept_id'] ?? 0);
        $query = "SELECT id, role_name, role_code, 
                         (SELECT COUNT(*) FROM user_roles WHERE role_id = r.id AND department_id = $dept_id AND is_active = 1) as current_occupants
                  FROM roles r
                  WHERE (department_id IS NULL OR department_id = $dept_id)
                  ORDER BY privilege_level DESC, role_name";
        $result = $db->query($query);
        $roles = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $roles[] = $row;
            }
        }
        $response['success'] = true;
        $response['roles'] = $roles;
        echo json_encode($response);
        exit;
    }

    // ========== GET DEPARTMENTS ==========
    if ($action === 'get_departments') {
        $depts = $db->preparedFetchAll("SELECT id, dept_name, dept_code FROM departments WHERE is_active = 1 ORDER BY dept_name", '', []);
        $response['success'] = true;
        $response['departments'] = $depts;
        echo json_encode($response);
        exit;
    }

    // ========== GET DEPARTMENT DATA ==========
    if ($action === 'get_department_data') {
        if ($dept_id === 0) {
            $depts = $db->preparedFetchAll("
                SELECT d.id, d.dept_name, d.dept_code, COUNT(DISTINCT u.id) as staff_count
                FROM departments d
                LEFT JOIN user_roles ur ON d.id = ur.department_id AND ur.is_active = 1
                LEFT JOIN bakery_users u ON ur.user_id = u.id
                WHERE d.is_active = 1
                GROUP BY d.id ORDER BY d.dept_name", '', []);
            
            $logs = $db->preparedFetchAll("
                SELECT al.*, u.fullname FROM activity_logs al
                JOIN bakery_users u ON al.user_id = u.id
                ORDER BY al.created_at DESC LIMIT 20", '', []);
            
            $response['success'] = true;
            $response['type'] = 'overview';
            $response['departments'] = $depts;
            $response['logs'] = $logs;
        } else {
            $dept = $db->preparedFetchOne("SELECT id, dept_name, dept_code FROM departments WHERE id = ?", 'i', [$dept_id]);
            if (!$dept) {
                $response['message'] = 'Department not found';
                echo json_encode($response);
                exit;
            }
            
            $staff = $db->preparedFetchAll("
                SELECT u.id, u.fullname, u.username, u.email, u.phone, u.branch_id,
                       b.branch_name, r.role_name, r.role_code, r.id as role_id
                FROM bakery_users u
                JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
                JOIN roles r ON ur.role_id = r.id
                LEFT JOIN branches b ON u.branch_id = b.id
                WHERE ur.department_id = ?
                ORDER BY r.privilege_level DESC, u.fullname", 'i', [$dept_id]);
            
            $logs = $db->preparedFetchAll("
                SELECT al.*, u.fullname FROM activity_logs al
                JOIN bakery_users u ON al.user_id = u.id
                WHERE u.id IN (SELECT DISTINCT user_id FROM user_roles WHERE department_id = ?)
                ORDER BY al.created_at DESC LIMIT 20", 'i', [$dept_id]);
            
            $response['success'] = true;
            $response['type'] = 'department';
            $response['department'] = $dept;
            $response['staff'] = $staff;
            $response['logs'] = $logs;
        }
        echo json_encode($response);
        exit;
    }

    $response['message'] = 'Invalid action';
    echo json_encode($response);
    exit;
}

// ==================== FETCH DATA FOR PAGE ====================
$departments = $db->preparedFetchAll("SELECT id, dept_name, dept_code FROM departments WHERE is_active = 1 ORDER BY dept_name", '', []);
// ONLY STAFF USERS
$all_staff = $db->preparedFetchAll(
    "SELECT id, fullname, username FROM bakery_users WHERE user_type = 'staff' AND is_active = 1 ORDER BY fullname", 
    '', 
    []
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Departments & Changes · Fingerchops Bakery</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/department.css">
    <style>
        .required { color: #dc3545; }
        .filter-group select[multiple] { min-width: 180px; height: auto; min-height: 90px; }
        .filter-group select[multiple] option { padding: 6px 8px; }
        .action-buttons { display: flex; gap: 6px; }
        .btn-icon { background: none; border: none; cursor: pointer; font-size: 16px; padding: 4px 8px; border-radius: 4px; transition: all 0.2s; }
        .btn-icon:hover { background: #f0f0f0; }
        .change-badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-transfer { background: #fef3c7; color: #d97706; }
        .badge-role { background: #dbeafe; color: #2563eb; }
        .badge-branch { background: #dcfce7; color: #166534; }
        .btn-danger { background: #dc3545; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        .btn-danger:hover { background: #c82333; }
        @media print {
            .department-tabs, .branch-filter, .filter-bar, .page-header .header-actions, 
            .modal, .toast-container, .preloader, .action-buttons, .btn-icon {
                display: none !important;
            }
            .change-log-table th:last-child, .change-log-table td:last-child {
                display: none !important;
            }
            .change-log-table { border-collapse: collapse; width: 100%; }
            .change-log-table th, .change-log-table td { border: 1px solid #ddd; padding: 8px; }
            .tab-pane { padding: 0; }
        }
        .info-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
            margin-left: 8px;
        }
        .info-badge.dept { background: #e0e7ff; color: #3730a3; }
        .info-badge.role { background: #fef3c7; color: #b45309; }
        .info-badge.branch { background: #dcfce7; color: #166534; }
    </style>
</head>
<body class="departments-page">
    <div class="preloader" id="preloader">
        <div class="preloader-spinner"></div>
    </div>
    
    <div class="dashboard-container departments-dashboard">
        <div class="page-header departments-header">
            <h1 class="page-title"><i class="fas fa-building"></i> Departments & Staff Management</h1>
            <div class="header-actions">
                <a href="../admin-dashboard.php" class="btn btn-secondary dashboard-link"><i class="fas fa-arrow-left"></i> Dashboard</a>
            </div>
        </div>

        <!-- Branch Filter -->
        <div class="branch-filter branch-filter-section">
            <label class="branch-filter-label"><i class="fas fa-store"></i> Branch:</label>
            <?php if ($admin_branch_id === null): ?>
                <select id="branchFilterSelect" class="branch-filter-select">
                    <option value="0" <?php echo $selected_branch == 0 ? 'selected' : ''; ?>>All Branches</option>
                    <?php foreach ($all_branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch == $branch['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($branch['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button id="applyBranchFilter" class="btn-apply-filter">Apply</button>
            <?php else: ?>
                <input type="hidden" id="branchFilterSelect" value="<?php echo $admin_branch_id; ?>">
                <span class="branch-name-display"><?php echo htmlspecialchars($selected_branch_name ?: 'My Branch'); ?></span>
            <?php endif; ?>
        </div>

        <!-- Tabs -->
        <div class="department-tabs tabs-container">
            <button class="tab-btn tab-all-departments active" data-dept-id="0">All Departments</button>
            <?php foreach ($departments as $dept): ?>
                <button class="tab-btn tab-department" data-dept-id="<?php echo $dept['id']; ?>">
                    <?php echo htmlspecialchars($dept['dept_name']); ?>
                </button>
            <?php endforeach; ?>
            <button class="tab-btn changes-tab" data-tab="changes">
                <i class="fas fa-history"></i> Established Changes
            </button>
        </div>

        <!-- Tab Content -->
        <div id="tabContent" class="tab-pane tab-content-area active">
            <div class="loading-spinner-container"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div id="editModal" class="modal edit-staff-modal">
        <div class="modal-content edit-modal-content" style="max-width: 550px;">
            <div class="modal-header edit-modal-header">
                <h3 class="modal-title"><i class="fas fa-user-edit"></i> Edit Staff Member</h3>
                <button class="modal-close modal-close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="mode-toggle modal-mode-toggle">
                <button type="button" class="mode-btn mode-edit-btn active" onclick="setMode('edit')"><i class="fas fa-info-circle"></i> Edit Info & Role</button>
                <button type="button" class="mode-btn mode-transfer-btn" onclick="setMode('transfer')"><i class="fas fa-exchange-alt"></i> Transfer Department</button>
                <button type="button" class="mode-btn mode-branch-btn" onclick="setMode('change_branch')"><i class="fas fa-store"></i> Change Branch</button>
            </div>

            <!-- Edit Info & Role Form -->
            <form id="editStaffForm" class="edit-staff-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" value="update_staff">
                <input type="hidden" name="user_id" id="edit_user_id">
                <input type="hidden" name="current_dept_id" id="edit_current_dept_id">
                <div class="form-group edit-form-group">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="fullname" id="edit_fullname" class="form-input" required>
                </div>
                <div class="form-group edit-form-group">
                    <label class="form-label">Email <span class="required">*</span></label>
                    <input type="email" name="email" id="edit_email" class="form-input" required>
                </div>
                <div class="form-group edit-form-group">
                    <label class="form-label">Phone <span class="required">*</span></label>
                    <input type="text" name="phone" id="edit_phone" class="form-input" required>
                </div>
                <div class="form-group edit-form-group">
                    <label class="form-label">Role in Department</label>
                    <select name="role_id" id="edit_role_id" class="form-select role-select">
                        <option value="">Loading...</option>
                    </select>
                    <small class="form-hint">Changing role updates staff permissions in the user_roles table</small>
                </div>
                <div class="modal-footer edit-modal-footer">
                    <button type="button" class="btn btn-secondary cancel-btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary save-btn"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>

            <!-- Transfer Department Form -->
            <form id="transferForm" class="transfer-form" style="display:none">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" value="transfer_staff">
                <input type="hidden" name="user_id" id="transfer_user_id">
                <div class="form-group transfer-form-group">
                    <label class="form-label">New Department <span class="required">*</span></label>
                    <select id="transfer_department" class="form-select department-select" required>
                        <option value="">Loading...</option>
                    </select>
                </div>
                <div class="form-group transfer-form-group">
                    <label class="form-label">New Role <span class="required">*</span></label>
                    <select id="transfer_role" class="form-select role-select" required disabled>
                        <option value="">Select department first</option>
                    </select>
                </div>
                <div class="modal-footer transfer-modal-footer">
                    <button type="button" class="btn btn-secondary cancel-btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary transfer-submit-btn"><i class="fas fa-exchange-alt"></i> Transfer Staff</button>
                </div>
            </form>

            <!-- Change Branch Form -->
            <form id="changeBranchForm" class="change-branch-form" style="display:none">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="ajax" value="1">
                <input type="hidden" name="action" value="update_user_branch">
                <input type="hidden" name="user_id" id="branch_user_id">
                <div class="form-group branch-form-group">
                    <label class="form-label">Select Branch <span class="required">*</span></label>
                    <select id="branch_select" class="form-select branch-select" required>
                        <option value="">Loading...</option>
                        <option value="0">No Branch (Headquarters)</option>
                    </select>
                </div>
                <div class="modal-footer branch-modal-footer">
                    <button type="button" class="btn btn-secondary cancel-btn" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary update-branch-btn"><i class="fas fa-check-circle"></i> Update Branch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal delete-confirm-modal">
        <div class="modal-content delete-modal-content" style="max-width: 400px;">
            <div class="modal-header delete-modal-header">
                <h3 class="modal-title"><i class="fas fa-trash-alt"></i> Delete Change Record</h3>
                <button class="modal-close delete-modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body delete-modal-body">
                <p class="delete-confirm-message">Are you sure you want to delete this change record? This action cannot be undone.</p>
            </div>
            <div class="modal-footer delete-modal-footer">
                <button class="btn btn-secondary cancel-delete-btn" onclick="closeDeleteModal()">Cancel</button>
                <button id="confirmDeleteBtn" class="btn btn-danger confirm-delete-btn"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="toast-container notification-container"></div>

    <script>
        const csrfToken = '<?php echo $csrf_token; ?>';
        let currentDeptId = 0;
        let currentStaffData = null;
        let branchesList = [];
        let pendingDeleteId = 0;

        function showToast(msg, type) { 
            let t = document.createElement('div'); 
            t.className = `toast toast-${type} notification-toast`; 
            t.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${msg}`; 
            document.getElementById('toastContainer').appendChild(t); 
            setTimeout(() => t.remove(), 3000); 
        }
        
        function escapeHtml(s) { 
            if(!s) return ''; 
            return s.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;'); 
        }

        function loadBranches() {
            const fd = new FormData(); 
            fd.append('ajax', '1'); 
            fd.append('action', 'get_branches'); 
            fd.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { 
                    if(d.success) { 
                        branchesList = d.branches; 
                        let sel = document.getElementById('branch_select'); 
                        if(sel) { 
                            sel.innerHTML = '<option value="">Select branch</option><option value="0">No Branch (Headquarters)</option>'; 
                            d.branches.forEach(b => sel.innerHTML += `<option value="${b.id}">${escapeHtml(b.branch_name)} (${escapeHtml(b.branch_code)})</option>`); 
                        } 
                    } 
                });
        }

        // ========== ESTABLISHED CHANGES TAB ==========
        function loadChanges() {
            let changeTypeSelect = document.getElementById('filterChangeType');
            let changeType = 'all';
            if(changeTypeSelect) {
                let selected = Array.from(changeTypeSelect.selectedOptions).map(opt => opt.value);
                if(selected.length === 0 || selected.includes('all')) changeType = 'all';
                else changeType = selected.join(',');
            }
            const dateFrom = document.getElementById('filterDateFrom')?.value || '';
            const dateTo = document.getElementById('filterDateTo')?.value || '';
            const userId = document.getElementById('filterUserId')?.value || '0';
            
            const fd = new FormData();
            fd.append('ajax', '1'); 
            fd.append('action', 'get_established_changes'); 
            fd.append('change_type', changeType);
            fd.append('date_from', dateFrom); 
            fd.append('date_to', dateTo); 
            fd.append('user_id_filter', userId);
            fd.append('csrf_token', csrfToken);
            
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    let html = `
                        <div class="change-history changes-container">
                            <h2 class="changes-title"><i class="fas fa-history"></i> Established Changes Log</h2>
                            <div class="filter-bar changes-filter-bar">
                                <div class="filter-group change-type-filter">
                                    <label class="filter-label">Change Type <small>(Ctrl+Click for multiple)</small></label>
                                    <select id="filterChangeType" class="filter-select change-type-select" multiple size="4">
                                        <option value="all" ${changeType === 'all' || changeType.includes('all') ? 'selected' : ''}>All Types</option>
                                        <option value="department_transfer" ${changeType.includes('department_transfer') ? 'selected' : ''}>Department Transfer</option>
                                        <option value="role_change" ${changeType.includes('role_change') ? 'selected' : ''}>Role Change</option>
                                        <option value="branch_transfer" ${changeType.includes('branch_transfer') ? 'selected' : ''}>Branch Transfer</option>
                                    </select>
                                </div>
                                <div class="filter-group date-from-filter">
                                    <label class="filter-label">Date From</label>
                                    <input type="date" id="filterDateFrom" class="filter-input date-input" value="${dateFrom}">
                                </div>
                                <div class="filter-group date-to-filter">
                                    <label class="filter-label">Date To</label>
                                    <input type="date" id="filterDateTo" class="filter-input date-input" value="${dateTo}">
                                </div>
                                <div class="filter-group staff-filter">
                                    <label class="filter-label">Staff Member</label>
                                    <select id="filterUserId" class="filter-select staff-select">
                                        <option value="0">All Staff</option>
                                    </select>
                                </div>
                                <div class="filter-group filter-actions">
                                    <button id="applyFiltersBtn" class="btn btn-primary apply-filters-btn">Apply Filters</button>
                                </div>
                                <div class="filter-group print-action">
                                    <button id="printChangesBtn" class="print-btn print-changes-btn"><i class="fas fa-print"></i> Print</button>
                                </div>
                            </div>
                            <div class="table-container changes-table-container">
                                <table class="change-log-table changes-table">
                                    <thead class="table-header">
                                        <tr class="table-header-row">
                                            <th class="col-date">Date</th>
                                            <th class="col-staff">Staff</th>
                                            <th class="col-type">Type</th>
                                            <th class="col-change">Change</th>
                                            <th class="col-from">From</th>
                                            <th class="col-to">To</th>
                                            <th class="col-actions">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="table-body">`;
                    
                    if(!data.changes || data.changes.length === 0) {
                        html += '<tr class="empty-row"><td colspan="7" class="empty-message" style="text-align:center">No changes recorded yet</td></tr>';
                    } else {
                        data.changes.forEach(c => {
                            let typeClass = c.change_type === 'department_transfer' ? 'badge-transfer' : (c.change_type === 'role_change' ? 'badge-role' : 'badge-branch');
                            html += `<tr class="change-row">
                                <td class="change-date">${new Date(c.created_at).toLocaleDateString()}</td>
                                <td class="change-staff"><strong>${escapeHtml(c.user_name)}</strong><br><small class="staff-username">@${escapeHtml(c.username)}</small></td>
                                <td class="change-type-cell"><span class="change-badge ${typeClass} change-type-badge">${c.change_type.replace('_', ' ')}</span></td>
                                <td class="change-title">${escapeHtml(c.title)}</td>
                                <td class="change-old-value">${escapeHtml(c.old_value || '-')}</td>
                                <td class="change-new-value">${escapeHtml(c.new_value || '-')}</td>
                                <td class="change-actions"><div class="action-buttons"><button class="btn-icon delete-change-btn" onclick="deleteChange(${c.id})" title="Delete" style="color:#dc3545"><i class="fas fa-trash"></i></button></div></td>
                            </tr>`;
                        });
                    }
                    html += `</tbody>
                                </table>
                            </div>
                        </div>`;
                    document.getElementById('tabContent').innerHTML = html;
                    
                    let staffSelect = document.getElementById('filterUserId');
                    if(staffSelect) { 
                        staffSelect.innerHTML = '<option value="0">All Staff</option>';
                        <?php foreach($all_staff as $staff): ?>
                            staffSelect.innerHTML += `<option value="<?php echo $staff['id']; ?>"><?php echo addslashes($staff['fullname']); ?> (@<?php echo addslashes($staff['username']); ?>)</option>`;
                        <?php endforeach; ?>
                        staffSelect.value = userId;
                    }
                    document.getElementById('applyFiltersBtn')?.addEventListener('click', loadChanges);
                    document.getElementById('printChangesBtn')?.addEventListener('click', () => window.print());
                })
                .catch(e => { console.error(e); document.getElementById('tabContent').innerHTML = '<div class="error-message">Failed to load changes</div>'; });
        }

        function deleteChange(id) { pendingDeleteId = id; document.getElementById('deleteModal').classList.add('show'); }
        function confirmDelete() {
            let fd = new FormData(); 
            fd.append('ajax','1'); 
            fd.append('action','delete_change'); 
            fd.append('change_id', pendingDeleteId); 
            fd.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(d=>{ if(d.success) { showToast(d.message,'success'); loadChanges(); } else showToast(d.message,'error'); closeDeleteModal(); });
        }
        function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('show'); pendingDeleteId = 0; }
        document.getElementById('confirmDeleteBtn')?.addEventListener('click', confirmDelete);

        // ========== DEPARTMENT FUNCTIONS ==========
        function loadDepartment(deptId) {
            currentDeptId = deptId;
            const fd = new FormData();
            fd.append('ajax','1'); 
            fd.append('action','get_department_data'); 
            fd.append('dept_id', deptId);
            fd.append('branch_id', document.getElementById('branchFilterSelect')?.value || 0);
            fd.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(data => {
                    if(!data.success) { document.getElementById('tabContent').innerHTML = `<div class="error-message">${data.message}</div>`; return; }
                    if(data.type === 'overview') {
                        let html = '<div class="department-overview overview-container"><h3 class="section-title"><i class="fas fa-chart-simple"></i> All Departments</h3><div class="dept-cards departments-grid">';
                        data.departments.forEach(d => { 
                            html += `<div class="dept-card department-card"><div class="dept-card-header card-header"><i class="fas fa-building"></i><h4 class="dept-name">${escapeHtml(d.dept_name)}</h4></div><div class="dept-card-body card-body"><div class="dept-code">${escapeHtml(d.dept_code)}</div><div class="staff-count"><i class="fas fa-users"></i> ${d.staff_count} staff</div></div></div>`; 
                        });
                        html += '</div><h3 class="section-title"><i class="fas fa-history"></i> Recent Activity</h3><ul class="logs-list activity-list">';
                        data.logs.forEach(l => { html += `<li class="log-item"><div class="log-action">${escapeHtml(l.action)}</div><div class="log-meta">${escapeHtml(l.fullname)} · ${new Date(l.created_at).toLocaleString()}</div></li>`; });
                        html += '</ul></div>';
                        document.getElementById('tabContent').innerHTML = html;
                    } else if(data.type === 'department') {
                        let staffHtml = '<h3 class="section-title"><i class="fas fa-users"></i> Staff Members</h3><table class="staff-table staff-members-table"><thead class="table-header"><tr class="table-header-row"><th class="col-name">Name</th><th class="col-email">Email</th><th class="col-phone">Phone</th><th class="col-branch">Branch</th><th class="col-role">Role</th><th class="col-actions">Actions</th></tr></thead><tbody class="table-body">';
                        data.staff.forEach(s => {
                            staffHtml += `<tr class="staff-row">
                                <td class="staff-name"><strong>${escapeHtml(s.fullname)}</strong><br><small class="staff-username">${escapeHtml(s.username)}</small></td>
                                <td class="staff-email">${escapeHtml(s.email)}</td>
                                <td class="staff-phone">${escapeHtml(s.phone)}</td>
                                <td class="staff-branch">${s.branch_name ? escapeHtml(s.branch_name) : 'Unassigned'}</td>
                                <td class="staff-role">${escapeHtml(s.role_name)} (${s.role_code})</td>
                                <td class="staff-actions"><button class="edit-btn edit-staff-btn" onclick='openEditModal(${JSON.stringify(s).replace(/'/g, "\\'")}, ${data.department.id})'><i class="fas fa-edit"></i> Edit</button></td>
                            </tr>`;
                        });
                        staffHtml += '</tbody></table><h3 class="section-title"><i class="fas fa-history"></i> Recent Activity</h3><ul class="logs-list activity-list">';
                        data.logs.forEach(l => { staffHtml += `<li class="log-item"><div class="log-action">${escapeHtml(l.action)}</div><div class="log-meta">${escapeHtml(l.fullname)} · ${new Date(l.created_at).toLocaleString()}</div></li>`; });
                        staffHtml += '</ul>';
                        document.getElementById('tabContent').innerHTML = `<div class="department-info department-detail"><h2 class="department-title"><i class="fas fa-building"></i> ${escapeHtml(data.department.dept_name)} (${escapeHtml(data.department.dept_code)})</h2></div>${staffHtml}`;
                    }
                })
                .catch(e => { console.error(e); document.getElementById('tabContent').innerHTML = '<div class="error-message">Failed to load</div>'; });
        }

        // ========== EDIT MODAL FUNCTIONS ==========
        let currentMode = 'edit';
        const editForm = document.getElementById('editStaffForm'), transferForm = document.getElementById('transferForm'), changeBranchForm = document.getElementById('changeBranchForm');
        
        function setMode(mode) {
            currentMode = mode;
            editForm.style.display = mode === 'edit' ? 'block' : 'none';
            transferForm.style.display = mode === 'transfer' ? 'block' : 'none';
            changeBranchForm.style.display = mode === 'change_branch' ? 'block' : 'none';
            document.querySelectorAll('.mode-btn').forEach(btn => btn.classList.remove('active'));
            if(mode === 'edit') document.querySelector('.mode-edit-btn').classList.add('active');
            else if(mode === 'transfer') document.querySelector('.mode-transfer-btn').classList.add('active');
            else document.querySelector('.mode-branch-btn').classList.add('active');
        }

        function loadRoles(deptId, selectId, selectedId) {
            let sel = document.getElementById(selectId); 
            sel.innerHTML = '<option value="">Loading...</option>';
            let fd = new FormData(); 
            fd.append('ajax','1'); 
            fd.append('action','get_roles'); 
            fd.append('dept_id', deptId); 
            fd.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(d => {
                    if(d.success && d.roles.length) { 
                        sel.innerHTML = '<option value="">Select role</option>'; 
                        d.roles.forEach(r => { 
                            let warn = (r.max_occupants > 0 && r.current_occupants >= r.max_occupants) ? ' (FULL)' : ''; 
                            sel.innerHTML += `<option value="${r.id}" ${r.id == selectedId ? 'selected' : ''}>${escapeHtml(r.role_name)} (${r.role_code})${warn}</option>`; 
                        }); 
                        sel.disabled = false; 
                    } else { 
                        sel.innerHTML = '<option value="">No roles available</option>'; 
                        sel.disabled = true; 
                    }
                });
        }

        function loadDepartmentsForTransfer() {
            let sel = document.getElementById('transfer_department'); 
            sel.innerHTML = '<option value="">Loading...</option>';
            let fd = new FormData(); 
            fd.append('ajax','1'); 
            fd.append('action','get_departments'); 
            fd.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(d => { 
                    if(d.success) { 
                        sel.innerHTML = '<option value="">Select department</option>'; 
                        d.departments.forEach(dept => sel.innerHTML += `<option value="${dept.id}">${escapeHtml(dept.dept_name)} (${escapeHtml(dept.dept_code)})</option>`); 
                    } 
                });
        }

        function openEditModal(staff, deptId) {
            currentStaffData = staff;
            document.getElementById('edit_user_id').value = staff.id;
            document.getElementById('edit_fullname').value = staff.fullname;
            document.getElementById('edit_email').value = staff.email;
            document.getElementById('edit_phone').value = staff.phone;
            document.getElementById('edit_current_dept_id').value = deptId;
            document.getElementById('transfer_user_id').value = staff.id;
            document.getElementById('branch_user_id').value = staff.id;
            loadRoles(deptId, 'edit_role_id', staff.role_id);
            loadDepartmentsForTransfer();
            let branchSel = document.getElementById('branch_select');
            if(branchSel && branchesList.length) branchSel.value = staff.branch_id || 0;
            setMode('edit');
            document.getElementById('editModal').classList.add('show');
        }

        function closeEditModal() { document.getElementById('editModal').classList.remove('show'); }

        // Form submissions
        document.getElementById('editStaffForm').addEventListener('submit', function(e) {
            e.preventDefault();
            let fd = new FormData(this);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(d => { 
                    if(d.success) { 
                        showToast(d.message,'success'); 
                        closeEditModal(); 
                        loadDepartment(currentDeptId); 
                    } else showToast(d.message,'error'); 
                });
        });
        
        document.getElementById('transferForm').addEventListener('submit', function(e) {
            e.preventDefault();
            let role = document.getElementById('transfer_role').value, dept = document.getElementById('transfer_department').value;
            if(!role || !dept) { showToast('Select both department and role','error'); return; }
            let fd = new FormData(this); 
            fd.append('role_id', role); 
            fd.append('department_id', dept);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(d => { 
                    if(d.success) { 
                        showToast(d.message,'success'); 
                        closeEditModal(); 
                        loadDepartment(currentDeptId); 
                    } else showToast(d.message,'error'); 
                });
        });
        
        document.getElementById('changeBranchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const branchId = document.getElementById('branch_select').value;
            const userId = document.getElementById('branch_user_id').value;
            
            if (!userId) {
                showToast('Invalid user', 'error');
                return;
            }
            
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'update_user_branch');
            fd.append('user_id', userId);
            fd.append('branch_id', branchId);
            fd.append('csrf_token', csrfToken);
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Updating...';
            submitBtn.disabled = true;
            
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { 
                    if(d.success) { 
                        showToast(d.message, 'success'); 
                        closeEditModal(); 
                        loadDepartment(currentDeptId); 
                    } else { 
                        showToast(d.message || 'Update failed', 'error'); 
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Network error', 'error');
                })
                .finally(() => {
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                });
        });
        
        document.getElementById('transfer_department').addEventListener('change', function() {
            let deptId = this.value, roleSel = document.getElementById('transfer_role');
            if(!deptId) { roleSel.innerHTML = '<option value="">Select department first</option>'; roleSel.disabled = true; return; }
            roleSel.innerHTML = '<option value="">Loading...</option>'; roleSel.disabled = true;
            let fd = new FormData(); 
            fd.append('ajax','1'); 
            fd.append('action','get_roles'); 
            fd.append('dept_id', deptId); 
            fd.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(d => {
                    if(d.success && d.roles.length) { 
                        roleSel.innerHTML = '<option value="">Select role</option>'; 
                        d.roles.forEach(r => { 
                            let warn = (r.max_occupants > 0 && r.current_occupants >= r.max_occupants) ? ' (FULL)' : ''; 
                            roleSel.innerHTML += `<option value="${r.id}">${escapeHtml(r.role_name)} (${r.role_code})${warn}</option>`; 
                        }); 
                        roleSel.disabled = false; 
                    } else { 
                        roleSel.innerHTML = '<option value="">No roles available</option>'; 
                        roleSel.disabled = true; 
                    }
                });
        });

        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                let deptId = this.getAttribute('data-dept-id'), tabType = this.getAttribute('data-tab');
                if(tabType === 'changes') loadChanges();
                else if(deptId !== null) loadDepartment(parseInt(deptId));
            });
        });

        document.getElementById('applyBranchFilter')?.addEventListener('click', () => loadDepartment(currentDeptId));
        loadDepartment(0);
        loadBranches();

        window.onclick = function(e) { 
            if(e.target.classList.contains('modal')) e.target.classList.remove('show'); 
        };
        
        window.addEventListener('load', () => { 
            setTimeout(() => { 
                let p = document.getElementById('preloader'); 
                if(p) { p.classList.add('fade-out'); setTimeout(() => p.style.display = 'none', 500); } 
            }, 500); 
        });
    </script>
</body>
</html>