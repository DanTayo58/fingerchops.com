<?php
// =====================================================
// FILE: tools/manage-staff.php
// PURPOSE: Manage staff by branch with HR request system
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// Load required files
require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Position.php';
require_once '../../../includes/Security.php';

// Get database instance
$db = Database::getInstance();

// Security check - must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login_signup.html');
    exit;
}

// Load current user
$userObj = new User($_SESSION['user_id']);
$current_user = $userObj->getData();

if (!$current_user) {
    header('Location: ../../login_signup.html');
    exit;
}

// Get user's positions to determine management level
$positions = $userObj->getPositions();
$is_manager = false;
$user_branch_id = null;
$user_branch_name = '';
$user_department_id = null;
$user_department_name = '';
$is_hq_manager = false;
$can_manage_all = false;
$user_privilege_level = $userObj->getPrivilegeLevel();

// Check user's branch and department
if (!empty($positions)) {
    foreach ($positions as $pos) {
        if (isset($pos['is_manager_position']) && $pos['is_manager_position'] == 1) {
            $is_manager = true;
        }
        
        if (isset($pos['branch_id']) && $pos['branch_id']) {
            $user_branch_id = $pos['branch_id'];
            $user_branch_name = isset($pos['branch_name']) ? $pos['branch_name'] : '';
        }
        
        if (isset($pos['department_id'])) {
            $user_department_id = $pos['department_id'];
            $user_department_name = isset($pos['dept_name']) ? $pos['dept_name'] : '';
        }
        
        if (isset($pos['branch_code']) && $pos['branch_code'] === 'HQ') {
            $is_hq_manager = true;
        }
    }
}

// Also check privilege level for overall access
if ($user_privilege_level >= 80) {
    $can_manage_all = true;
}

// If not a manager, redirect
if (!$is_manager && $user_privilege_level < 50) {
    error_log("Non-manager attempted to access staff management: " . $current_user['fullname']);
    header('Location: ../sales-dashboard.php?error=not_manager');
    exit;
}

// =====================================================
// HANDLE STAFF ACTIONS (AJAX endpoints)
// =====================================================

// Get staff list for branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'get_staff') {
    header('Content-Type: application/json');
    
    $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : ($can_manage_all ? 0 : $user_branch_id);
    $search = isset($_POST['search']) ? $db->escape($_POST['search']) : '';
    
    $where = "po.is_active = 1";
    
    if ($branch_id > 0) {
        $where .= " AND p.branch_id = $branch_id";
    } elseif (!$can_manage_all && $user_branch_id) {
        $where .= " AND p.branch_id = $user_branch_id";
    }
    
    $where .= " AND d.dept_code = 'SL'";
    
    if (!empty($search)) {
        $where .= " AND (u.fullname LIKE '%$search%' OR u.username LIKE '%$search%' OR u.email LIKE '%$search%')";
    }
    
    $query = "
        SELECT DISTINCT 
            u.id,
            u.user_id,
            u.fullname,
            u.username,
            u.email,
            u.phone,
            u.user_type,
            u.is_active,
            u.hire_date,
            u.last_login,
            p.position_title,
            p.is_manager_position,
            r.role_name,
            r.role_code,
            r.privilege_level,
            po.assigned_date,
            d.dept_name as department_name,
            b.id as branch_id,
            b.branch_name,
            b.branch_code
        FROM bakery_users u
        JOIN position_occupants po ON u.id = po.user_id AND po.is_active = 1
        JOIN positions p ON po.position_id = p.id
        JOIN departments d ON p.department_id = d.id
        LEFT JOIN branches b ON p.branch_id = b.id
        LEFT JOIN roles r ON p.role_id = r.id
        WHERE $where
        ORDER BY b.branch_name, u.fullname
    ";
    
    $result = $db->query($query);
    $staff = [];
    
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'staff' => $staff]);
    exit;
}

// Get single staff member details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'get_staff_detail') {
    header('Content-Type: application/json');
    
    $staff_id = (int)$_POST['staff_id'];
    
    $query = "
        SELECT 
            u.*,
            p.id as position_id,
            p.position_title,
            p.is_manager_position,
            r.id as role_id,
            r.role_name,
            r.role_code,
            r.privilege_level,
            r.can_manage_users,
            r.can_approve_requests,
            r.can_manage_budget,
            r.can_view_reports,
            r.can_manage_system,
            d.id as department_id,
            d.dept_name,
            d.dept_code,
            b.id as branch_id,
            b.branch_name,
            b.branch_code,
            po.assigned_date
        FROM bakery_users u
        JOIN position_occupants po ON u.id = po.user_id AND po.is_active = 1
        JOIN positions p ON po.position_id = p.id
        JOIN departments d ON p.department_id = d.id
        LEFT JOIN branches b ON p.branch_id = b.id
        LEFT JOIN roles r ON p.role_id = r.id
        WHERE u.id = $staff_id
        LIMIT 1
    ";
    
    $result = $db->query($query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $staff = mysqli_fetch_assoc($result);
        echo json_encode(['success' => true, 'staff' => $staff]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Staff member not found']);
    }
    exit;
}

// Update staff member (with username)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_staff') {
    header('Content-Type: application/json');
    
    $staff_id = (int)$_POST['staff_id'];
    $fullname = $db->escape($_POST['fullname']);
    $username = $db->escape($_POST['username']);
    $email = $db->escape($_POST['email']);
    $phone = $db->escape($_POST['phone']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $position_id = (int)$_POST['position_id'];
    $role_id = (int)$_POST['role_id'];
    $is_manager = isset($_POST['is_manager']) ? 1 : 0;
    $branch_id = (int)$_POST['branch_id'];
    
    // Check permission
    if (!$can_manage_all && $user_branch_id) {
        $check = $db->query("
            SELECT p.branch_id 
            FROM position_occupants po
            JOIN positions p ON po.position_id = p.id
            WHERE po.user_id = $staff_id AND po.is_active = 1
        ");
        if ($check && $row = mysqli_fetch_assoc($check)) {
            if ($row['branch_id'] != $user_branch_id) {
                echo json_encode(['success' => false, 'message' => 'You do not have permission to edit staff from other branches']);
                exit;
            }
        }
    }
    
    // Validate username
    if (strlen($username) < 3 || strlen($username) > 50) {
        echo json_encode(['success' => false, 'message' => 'Username must be between 3 and 50 characters']);
        exit;
    }
    
    // Check if username is unique
    $check_username = $db->query("SELECT id FROM bakery_users WHERE username = '$username' AND id != $staff_id");
    if ($check_username && mysqli_num_rows($check_username) > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }
    
    $db->beginTransaction();
    
    try {
        // Update user basic info (including username)
        $update_user = "UPDATE bakery_users 
                        SET fullname = '$fullname', 
                            username = '$username',
                            email = '$email', 
                            phone = '$phone',
                            is_active = $is_active
                        WHERE id = $staff_id";
        
        if (!$db->query($update_user)) {
            throw new Exception('Failed to update user');
        }
        
        // Update position
        $update_position = "UPDATE positions 
                            SET position_title = (SELECT role_name FROM roles WHERE id = $role_id),
                                role_id = $role_id,
                                is_manager_position = $is_manager,
                                branch_id = $branch_id
                            WHERE id = $position_id";
        
        if (!$db->query($update_position)) {
            throw new Exception('Failed to update position');
        }
        
        logActivity($_SESSION['user_id'], "Updated staff member: $fullname (ID: $staff_id)", 'user', $staff_id);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Staff member updated successfully']);
        
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Reset password to default
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'reset_password') {
    header('Content-Type: application/json');
    
    $staff_id = (int)$_POST['staff_id'];
    $default_password = 'Staff@123';
    $password_hash = hashPassword($default_password);
    
    $query = "UPDATE bakery_users 
              SET password_hash = '$password_hash',
                  force_password_change = 1,
                  last_password_change = NOW()
              WHERE id = $staff_id";
    
    if ($db->query($query)) {
        logActivity($_SESSION['user_id'], "Reset password for user ID: $staff_id", 'user', $staff_id);
        
        $user = $db->fetchOne("SELECT fullname, email FROM bakery_users WHERE id = $staff_id");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Password reset to default: Staff@123',
            'default_password' => $default_password,
            'user_email' => $user['email'],
            'user_name' => $user['fullname']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reset password']);
    }
    exit;
}

// Send HR request for deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'send_hr_request') {
    header('Content-Type: application/json');
    
    $staff_id = (int)$_POST['staff_id'];
    $reason = $db->escape($_POST['reason']);
    $additional_notes = $db->escape($_POST['additional_notes']);
    $urgency = $db->escape($_POST['urgency']);
    
    // Get staff details for the request
    $staff = $db->fetchOne("SELECT fullname, username, position_title FROM bakery_users u 
                            JOIN position_occupants po ON u.id = po.user_id 
                            JOIN positions p ON po.position_id = p.id 
                            WHERE u.id = $staff_id AND po.is_active = 1");
    
    if (!$staff) {
        echo json_encode(['success' => false, 'message' => 'Staff member not found']);
        exit;
    }
    
    $query = "INSERT INTO hr_department_requests (
                request_type, requester_id, staff_id, current_status, 
                proposed_status, reason, additional_notes, urgency, status
              ) VALUES (
                'deactivation', {$_SESSION['user_id']}, $staff_id, 
                'Active', 'Deactivated', '$reason', '$additional_notes', '$urgency', 'pending'
              )";
    
    if ($db->query($query)) {
        $request_id = $db->lastInsertId();
        
        logActivity($_SESSION['user_id'], "Sent HR request for deactivation of: {$staff['fullname']} (ID: $staff_id)", 'hr_request', $request_id);
        
        echo json_encode([
            'success' => true, 
            'message' => 'HR request sent successfully. HR department will review the request.',
            'request_id' => $request_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send HR request']);
    }
    exit;
}

// Get all branches for dropdown
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'get_branches') {
    header('Content-Type: application/json');
    
    $branches = $db->query("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name");
    $branch_list = [];
    
    if ($branches) {
        while ($branch = mysqli_fetch_assoc($branches)) {
            $branch_list[] = $branch;
        }
    }
    
    echo json_encode(['success' => true, 'branches' => $branch_list]);
    exit;
}

// Get roles for dropdown
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'get_roles') {
    header('Content-Type: application/json');
    
    $roles = $db->query("SELECT id, role_name, role_code, privilege_level FROM roles ORDER BY privilege_level DESC");
    $role_list = [];
    
    if ($roles) {
        while ($role = mysqli_fetch_assoc($roles)) {
            $role_list[] = $role;
        }
    }
    
    echo json_encode(['success' => true, 'roles' => $role_list]);
    exit;
}

// Get positions for a branch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'get_positions') {
    header('Content-Type: application/json');
    
    $branch_id = (int)$_POST['branch_id'];
    $department_id = (int)$_POST['department_id'];
    
    $positions = $db->query("
        SELECT p.*, r.role_name 
        FROM positions p
        JOIN roles r ON p.role_id = r.id
        WHERE p.branch_id = $branch_id AND p.department_id = $department_id
        ORDER BY p.position_title
    ");
    
    $position_list = [];
    if ($positions) {
        while ($pos = mysqli_fetch_assoc($positions)) {
            $position_list[] = $pos;
        }
    }
    
    echo json_encode(['success' => true, 'positions' => $position_list]);
    exit;
}

// =====================================================
// FETCH DATA FOR PAGE
// =====================================================

// Get all branches for dropdown (if HQ manager)
$branches = [];
if ($can_manage_all) {
    $branches_result = $db->query("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name");
    if ($branches_result) {
        while ($branch = mysqli_fetch_assoc($branches_result)) {
            $branches[] = $branch;
        }
    }
}

// Get roles for dropdown
$roles = $db->query("SELECT id, role_name, role_code, privilege_level FROM roles ORDER BY privilege_level DESC");
$role_list = [];
if ($roles) {
    while ($role = mysqli_fetch_assoc($roles)) {
        $role_list[] = $role;
    }
}

// Get staff count for user's branch (or overall if HQ)
$staff_count = 0;
if ($can_manage_all) {
    $count_query = $db->query("
        SELECT COUNT(DISTINCT po.user_id) as count
        FROM position_occupants po
        JOIN positions p ON po.position_id = p.id
        JOIN departments d ON p.department_id = d.id
        WHERE d.dept_code = 'SL' AND po.is_active = 1
    ");
    $staff_count = $count_query ? mysqli_fetch_assoc($count_query)['count'] : 0;
} else {
    $count_query = $db->query("
        SELECT COUNT(DISTINCT po.user_id) as count
        FROM position_occupants po
        JOIN positions p ON po.position_id = p.id
        WHERE p.branch_id = $user_branch_id AND po.is_active = 1
    ");
    $staff_count = $count_query ? mysqli_fetch_assoc($count_query)['count'] : 0;
}

$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management · Fingerchops Ventures</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/manage-staff.css">
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-message">Loading...</div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>
    
    <!-- Password Reset Modal -->
    <div class="modal" id="passwordResetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-key"></i> Password Reset</h2>
                <button class="modal-close" onclick="closeModal('passwordResetModal')">&times;</button>
            </div>
            <div class="modal-body" id="passwordResetBody">
                <!-- Content will be filled dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('passwordResetModal')">Close</button>
                <button type="button" class="btn btn-primary" id="copyPasswordBtn" onclick="copyPassword()">Copy Password</button>
            </div>
        </div>
    </div>

    <!-- HR Request Modal -->
    <div class="modal" id="hrRequestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-building-user"></i> Request HR Deactivation</h2>
                <button class="modal-close" onclick="closeModal('hrRequestModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="hr-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p><strong>Important:</strong> This request will be sent to the HR department for review. 
                    The staff member will be deactivated only after HR approval.</p>
                </div>
                
                <form id="hrRequestForm">
                    <input type="hidden" id="hr_staff_id" name="staff_id">
                    
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Staff Member</label>
                        <div class="staff-preview" id="hrStaffPreview">
                            <i class="fas fa-user-circle"></i> <span id="hrStaffName">Loading...</span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-circle"></i> Reason for Deactivation *</label>
                        <textarea id="hrReason" rows="3" required placeholder="Please provide detailed reason for deactivation..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-sticky-note"></i> Additional Notes (Optional)</label>
                        <textarea id="hrNotes" rows="2" placeholder="Any additional information for HR..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-gauge-high"></i> Urgency Level</label>
                        <select id="hrUrgency">
                            <option value="low">Low - Standard processing</option>
                            <option value="medium">Medium - Priority attention</option>
                            <option value="high">High - Urgent</option>
                            <option value="urgent">Urgent - Immediate action required</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('hrRequestModal')">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="submitHRRequest()">Send to HR</button>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        
        <!-- HEADER with Dashboard Button -->
        <div class="page-header">
            <div class="header-top">
                <div class="header-title">
                    <h1><i class="fas fa-users-cog"></i> Staff Management</h1>
                    <p>Manage sales staff by branch · <?php echo htmlspecialchars($current_user['fullname']); ?></p>
                </div>
                <div class="header-actions">
                    <a href="../sales-dashboard.php" class="dashboard-btn">
                        <i class="fas fa-chalkboard-user"></i> Back to Dashboard
                    </a>
                    <div class="header-badge">
                        <i class="fas fa-store"></i> 
                        <?php if ($can_manage_all): ?>
                            All Branches (HQ Manager)
                        <?php else: ?>
                            <?php echo htmlspecialchars($user_branch_name ?: 'Branch Manager'); ?>
                        <?php endif; ?>
                        <span class="staff-count"><?php echo $staff_count; ?> staff</span>
                    </div>
                </div>
            </div>
            <div class="header-footer">
                <i class="fas fa-info-circle"></i> 
                <?php if ($can_manage_all): ?>
                    You have access to manage staff across all branches.
                <?php else: ?>
                    You can only manage staff in your assigned branch: <strong><?php echo htmlspecialchars($user_branch_name); ?></strong>
                <?php endif; ?>
                <br><small>Default password for new/reset: <strong>Staff@123</strong></small>
            </div>
        </div>
        
        <!-- SEARCH AND FILTER -->
        <div class="search-section">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by name, username, or email...">
                <button id="searchBtn" onclick="loadStaff()">Search</button>
            </div>
            <?php if ($can_manage_all): ?>
            <div class="filter-section">
                <label><i class="fas fa-map-marker-alt"></i> Branch:</label>
                <select id="branchFilter" onchange="loadStaff()">
                    <option value="0">All Branches</option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-secondary" onclick="resetFilters()">Reset</button>
            </div>
            <?php else: ?>
            <div class="filter-section">
                <span class="branch-info">
                    <i class="fas fa-map-marker-alt"></i> 
                    <?php echo htmlspecialchars($user_branch_name); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- STAFF TABLE -->
        <div class="staff-section">
            <div class="table-responsive">
                <table class="staff-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Staff</th>
                            <th>Contact</th>
                            <th>Branch</th>
                            <th>Position</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </thead>
                    <tbody id="staffTableBody">
                        <tr>
                            <td colspan="8" class="loading-placeholder">
                                <i class="fas fa-spinner fa-spin"></i> Loading staff...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- FOOTER -->
        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> All staff changes are logged · <?php echo $can_manage_all ? 'HQ Manager' : 'Branch Manager'; ?> Access
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div class="modal" id="editStaffModal">
        <div class="modal-content edit-modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-edit"></i> Edit Staff Member</h2>
                <button class="modal-close" onclick="closeModal('editStaffModal')">&times;</button>
            </div>
            <div class="modal-body edit-modal-body">
                <form id="editStaffForm">
                    <input type="hidden" id="edit_staff_id" name="staff_id">
                    <input type="hidden" name="ajax" value="update_staff">
                    
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name</label>
                        <input type="text" id="edit_fullname" name="fullname" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-at"></i> Username</label>
                        <input type="text" id="edit_username" name="username" required>
                        <div class="hint-text">Unique username for login (3-50 characters, letters/numbers/underscores)</div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="edit_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone</label>
                        <input type="text" id="edit_phone" name="phone" required>
                    </div>
                    
                    <?php if ($can_manage_all): ?>
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Branch</label>
                        <select id="edit_branch_id" name="branch_id" required>
                            <option value="">Select Branch</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" id="edit_branch_id" name="branch_id" value="<?php echo $user_branch_id; ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label><i class="fas fa-briefcase"></i> Position</label>
                        <select id="edit_position_id" name="position_id" required>
                            <option value="">Select Position</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Role</label>
                        <select id="edit_role_id" name="role_id" required>
                            <option value="">Select Role</option>
                            <?php foreach ($role_list as $role): ?>
                                <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?> (Level <?php echo $role['privilege_level']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="edit_is_manager" name="is_manager" value="1">
                        <label for="edit_is_manager">Manager Position</label>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="edit_is_active" name="is_active" value="1">
                        <label for="edit_is_active">Active Status</label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editStaffModal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="updateStaff()">Save Changes</button>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        const toastContainer = document.getElementById('toastContainer');
        const loadingOverlay = document.getElementById('loadingOverlay');
        let currentStaffData = null;
        let canManageAll = <?php echo $can_manage_all ? 'true' : 'false'; ?>;
        let userBranchId = <?php echo $user_branch_id ?: 'null'; ?>;
        let currentHRStaffId = null;
        let currentHRStaffName = '';

        // =====================================================
        // TOAST FUNCTIONS
        // =====================================================
        function showToast(message, type = 'info', duration = 3000) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            let icon = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle');
            toast.innerHTML = `
                <div class="toast-icon"><i class="fas fa-${icon}"></i></div>
                <div class="toast-content">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
            `;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.remove(), duration);
        }

        function showLoading() { loadingOverlay.classList.add('show'); }
        function hideLoading() { loadingOverlay.classList.remove('show'); }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        // =====================================================
        // LOAD STAFF LIST
        // =====================================================
        function loadStaff() {
            const branchId = canManageAll ? document.getElementById('branchFilter').value : userBranchId;
            const search = document.getElementById('searchInput').value;
            
            showLoading();
            
            const formData = new FormData();
            formData.append('ajax', 'get_staff');
            formData.append('branch_id', branchId);
            formData.append('search', search);
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    renderStaffTable(data.staff);
                } else {
                    showToast('Failed to load staff', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error', 'error');
            });
        }

        function renderStaffTable(staff) {
            const tbody = document.getElementById('staffTableBody');
            
            if (!staff || staff.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No staff members found in this branch</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = staff.map(s => `
                <tr class="staff-row" data-id="${s.id}">
                    <td>#${s.id}</td>
                    <td>
                        <div class="staff-info">
                            <strong>${escapeHtml(s.fullname)}</strong>
                            <span class="staff-username">@${escapeHtml(s.username)}</span>
                            <span class="staff-user-id">${escapeHtml(s.user_id)}</span>
                        </div>
                    </td>
                    <td>
                        <div class="staff-contact">
                            <i class="fas fa-envelope"></i> ${escapeHtml(s.email)}<br>
                            <i class="fas fa-phone"></i> ${escapeHtml(s.phone)}
                        </div>
                    </td>
                    <td>
                        <span class="branch-badge">${escapeHtml(s.branch_name || 'HQ')}</span>
                    </td>
                    <td>
                        <span class="position-badge">${escapeHtml(s.position_title || 'N/A')}</span>
                        ${s.is_manager_position ? '<span class="manager-badge"><i class="fas fa-crown"></i> Manager</span>' : ''}
                    </td>
                    <td>
                        <span class="role-badge" style="background: ${getRoleColor(s.privilege_level)}">
                            ${escapeHtml(s.role_name || 'N/A')}
                        </span>
                        <span class="level-badge">Lvl ${s.privilege_level}</span>
                    </td>
                    <td>
                        <span class="status-badge ${s.is_active ? 'status-active' : 'status-inactive'}">
                            ${s.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </td>
                    <td class="action-cell">
                        <button class="action-btn edit" onclick="editStaff(${s.id})" title="Edit Staff">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn reset" onclick="resetPassword(${s.id})" title="Reset Password to Staff@123">
                            <i class="fas fa-key"></i>
                        </button>
                        ${s.is_active ? `
                            <button class="action-btn remove" onclick="confirmDeactivation(${s.id}, '${escapeHtml(s.fullname)}')" title="Request HR Deactivation">
                                <i class="fas fa-user-slash"></i>
                            </button>
                        ` : `
                            <span class="deactivated-badge">Deactivated</span>
                        `}
                    </td>
                </tr>
            `).join('');
        }

        // =====================================================
        // EDIT STAFF FUNCTIONS
        // =====================================================
        function editStaff(staffId) {
            showLoading();
            
            const formData = new FormData();
            formData.append('ajax', 'get_staff_detail');
            formData.append('staff_id', staffId);
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    currentStaffData = data.staff;
                    populateEditForm(data.staff);
                    document.getElementById('editStaffModal').classList.add('show');
                } else {
                    showToast('Failed to load staff details', 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error', 'error');
            });
        }

        function populateEditForm(staff) {
            document.getElementById('edit_staff_id').value = staff.id;
            document.getElementById('edit_fullname').value = staff.fullname;
            document.getElementById('edit_username').value = staff.username;
            document.getElementById('edit_email').value = staff.email;
            document.getElementById('edit_phone').value = staff.phone;
            
            if (canManageAll) {
                document.getElementById('edit_branch_id').value = staff.branch_id;
            }
            
            document.getElementById('edit_role_id').value = staff.role_id;
            document.getElementById('edit_is_manager').checked = staff.is_manager_position == 1;
            document.getElementById('edit_is_active').checked = staff.is_active == 1;
            
            const branchId = canManageAll ? staff.branch_id : userBranchId;
            const deptId = staff.department_id;
            loadPositionsForBranch(branchId, deptId, staff.position_id);
        }

        function loadPositionsForBranch(branchId, deptId, currentPositionId) {
            const formData = new FormData();
            formData.append('ajax', 'get_positions');
            formData.append('branch_id', branchId);
            formData.append('department_id', deptId);
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const select = document.getElementById('edit_position_id');
                    select.innerHTML = '<option value="">Select Position</option>';
                    data.positions.forEach(pos => {
                        const selected = currentPositionId == pos.id ? 'selected' : '';
                        select.innerHTML += `<option value="${pos.id}" ${selected}>${escapeHtml(pos.position_title)}</option>`;
                    });
                }
            });
        }

        function updateStaff() {
            const formData = new FormData(document.getElementById('editStaffForm'));
            
            showLoading();
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('editStaffModal');
                    loadStaff();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error', 'error');
            });
        }

        // =====================================================
        // RESET PASSWORD
        // =====================================================
        function resetPassword(staffId) {
            if (!confirm('Reset password for this staff member to default "Staff@123"? They will be required to change it on next login.')) {
                return;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('ajax', 'reset_password');
            formData.append('staff_id', staffId);
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showPasswordResetModal(data.default_password, data.user_name, data.user_email);
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error', 'error');
            });
        }

        function showPasswordResetModal(newPassword, userName, userEmail) {
            const modalBody = document.getElementById('passwordResetBody');
            modalBody.innerHTML = `
                <div class="reset-success">
                    <i class="fas fa-check-circle"></i>
                    <h3>Password Reset Successful</h3>
                    <p>Password reset to default for <strong>${escapeHtml(userName)}</strong>:</p>
                    <div class="new-password-box">
                        <code>${escapeHtml(newPassword)}</code>
                        <button class="copy-btn" onclick="copyToClipboard('${escapeHtml(newPassword)}')">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                    <div class="reset-email-info">
                        <i class="fas fa-envelope"></i>
                        <p>Staff can log in with this password. They will be prompted to change it.</p>
                    </div>
                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Please securely share this password with the staff member.</p>
                    </div>
                </div>
            `;
            document.getElementById('passwordResetModal').classList.add('show');
        }

        function copyPassword() {
            const passwordBox = document.querySelector('.new-password-box code');
            if (passwordBox) {
                copyToClipboard(passwordBox.textContent);
            }
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Password copied to clipboard', 'success');
            }).catch(() => {
                showToast('Failed to copy', 'error');
            });
        }

        // =====================================================
        // HR REQUEST FUNCTIONS
        // =====================================================
        function confirmDeactivation(staffId, staffName) {
            if (!confirm(`⚠️ IMPORTANT: Deactivating "${staffName}" will be sent to HR for approval.\n\nThis will affect payroll and system access.\n\nDo you want to proceed with the HR request?`)) {
                return;
            }
            
            currentHRStaffId = staffId;
            currentHRStaffName = staffName;
            
            document.getElementById('hr_staff_id').value = staffId;
            document.getElementById('hrStaffName').innerHTML = staffName;
            document.getElementById('hrReason').value = '';
            document.getElementById('hrNotes').value = '';
            document.getElementById('hrUrgency').value = 'medium';
            
            document.getElementById('hrRequestModal').classList.add('show');
        }

        function submitHRRequest() {
            const reason = document.getElementById('hrReason').value.trim();
            if (!reason) {
                alert('Please provide a reason for deactivation');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 'send_hr_request');
            formData.append('staff_id', currentHRStaffId);
            formData.append('reason', reason);
            formData.append('additional_notes', document.getElementById('hrNotes').value);
            formData.append('urgency', document.getElementById('hrUrgency').value);
            
            showLoading();
            
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                hideLoading();
                closeModal('hrRequestModal');
                if (data.success) {
                    showToast(data.message, 'success', 5000);
                    loadStaff();
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Error:', error);
                showToast('Network error', 'error');
            });
        }

        // =====================================================
        // UTILITY FUNCTIONS
        // =====================================================
        function resetFilters() {
            document.getElementById('searchInput').value = '';
            if (canManageAll) {
                document.getElementById('branchFilter').value = '0';
            }
            loadStaff();
        }

        function getRoleColor(level) {
            if (level >= 80) return '#f56565';
            if (level >= 60) return '#ed8936';
            if (level >= 50) return '#48bb78';
            if (level >= 30) return '#4299e1';
            return '#a0aec0';
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // =====================================================
        // INITIALIZATION
        // =====================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadStaff();
            
            document.getElementById('searchInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    loadStaff();
                }
            });
        });
    </script>
</body>
</html>