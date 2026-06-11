<?php
// =====================================================
// FILE: admin_power/roles.php
// PURPOSE: Manage all roles (flexible, with department context)
// ACCESS: Admin only (Level 100)
// =====================================================

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
require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
require_once '../../../config/config_loader.php';

// Get database instance
$db = Database::getInstance();

// Security check – only admins
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login_signup.php');
    exit;
}

// Get current user
$adminObj = new User($_SESSION['user_id']);
$admin = $adminObj->getData();

if (!$admin) {
    header('Location: ../../../login_signup.php');
    exit;
}

// Check privilege level
$privilege_level = $adminObj->getPrivilegeLevel();
$minAdminLevel = setting('admin_privilege_level', 100);
if ($privilege_level < $minAdminLevel) {
    header('Location: ../../../login_signup.php');
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['roles_csrf_token'])) {
    $_SESSION['roles_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['roles_csrf_token'];

// Fetch distinct role codes for dropdown
$role_codes_query = $db->query("SELECT DISTINCT role_code FROM roles ORDER BY role_code");
$role_codes = [];
if ($role_codes_query) {
    while ($row = mysqli_fetch_assoc($role_codes_query)) {
        $role_codes[] = $row['role_code'];
    }
}

// Fetch departments for dropdown
$departments_query = $db->query("SELECT id, dept_name, dept_code FROM departments WHERE is_active = 1 ORDER BY dept_name");
$departments = [];
if ($departments_query) {
    while ($row = mysqli_fetch_assoc($departments_query)) {
        $departments[] = $row;
    }
}

// ==================== AJAX HANDLERS ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['roles_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }

    $ajax_action = $_POST['ajax']; // the action is in 'ajax' parameter

    // Get role details
    if ($ajax_action === 'get_role') {
        $role_id = (int)$_POST['role_id'];
        $query = "SELECT * FROM roles WHERE id = $role_id";
        $result = $db->query($query);
        if ($result && mysqli_num_rows($result) > 0) {
            $role = mysqli_fetch_assoc($result);
            $response['success'] = true;
            $response['role'] = $role;
        } else {
            $response['message'] = 'Role not found';
        }
        echo json_encode($response);
        exit;
    }

    // Update role
    if ($ajax_action === 'update_role') {
        $role_id = (int)$_POST['role_id'];
        $is_new_code = isset($_POST['is_new_code']) && $_POST['is_new_code'] === '1';
        $role_code = '';
        if ($is_new_code) {
            $role_code = isset($_POST['new_role_code']) ? $db->escape($_POST['new_role_code']) : '';
        } else {
            $role_code = isset($_POST['role_code']) ? $db->escape($_POST['role_code']) : '';
        }
        $role_name = $db->escape($_POST['role_name']);
        $privilege_level = (int)$_POST['privilege_level'];
        $description = isset($_POST['description']) ? $db->escape($_POST['description']) : '';
        $department_id = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : 'NULL';
        $max_occupants = (int)$_POST['max_occupants'];

        $can_manage_users = isset($_POST['can_manage_users']) ? 1 : 0;
        $can_approve_requests = isset($_POST['can_approve_requests']) ? 1 : 0;
        $can_manage_budget = isset($_POST['can_manage_budget']) ? 1 : 0;
        $can_view_reports = isset($_POST['can_view_reports']) ? 1 : 0;
        $can_manage_system = isset($_POST['can_manage_system']) ? 1 : 0;

        // Validation
        if (empty($role_code)) {
            echo json_encode(['success' => false, 'message' => 'Role code is required']);
            exit;
        }
        if (empty($role_name)) {
            echo json_encode(['success' => false, 'message' => 'Role name is required']);
            exit;
        }
        if ($max_occupants < 0) {
            echo json_encode(['success' => false, 'message' => 'Max occupants must be 0 or greater']);
            exit;
        }

        // Check duplicate role name
        $dup_check = $db->query("SELECT id FROM roles WHERE role_name = '$role_name' AND id != $role_id");
        if ($dup_check && mysqli_num_rows($dup_check) > 0) {
            echo json_encode(['success' => false, 'message' => 'Role name already exists']);
            exit;
        }

        // Validate department if provided
        if ($department_id !== 'NULL') {
            $dept_check = $db->query("SELECT id FROM departments WHERE id = $department_id");
            if (!$dept_check || mysqli_num_rows($dept_check) == 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid department']);
                exit;
            }
        }

        if ($is_new_code && !preg_match('/^[A-Z0-9_]+$/', $role_code)) {
            echo json_encode(['success' => false, 'message' => 'Role code must contain only uppercase letters, numbers, and underscores']);
            exit;
        }

        $dept_sql = ($department_id === 'NULL') ? 'NULL' : $department_id;

        $update = "UPDATE roles SET 
                    role_code = '$role_code',
                    role_name = '$role_name',
                    privilege_level = $privilege_level,
                    description = '$description',
                    department_id = $dept_sql,
                    max_occupants = $max_occupants,
                    can_manage_users = $can_manage_users,
                    can_approve_requests = $can_approve_requests,
                    can_manage_budget = $can_manage_budget,
                    can_view_reports = $can_view_reports,
                    can_manage_system = $can_manage_system
                  WHERE id = $role_id";

        if ($db->query($update)) {
            logActivity($_SESSION['user_id'], "Updated role: $role_name", 'role', $role_id);
            echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error: ' . mysqli_error($db->getConnection())]);
        }
        exit;
    }

    $response['message'] = 'Invalid action';
    echo json_encode($response);
    exit;
}

// ==================== NON-AJAX FORM HANDLERS ====================

// Add new role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['roles_csrf_token']) {
        die('Invalid security token');
    }

    $is_new_code = isset($_POST['is_new_code']) && $_POST['is_new_code'] === '1';
    $role_code = '';
    if ($is_new_code) {
        $role_code = isset($_POST['new_role_code']) ? $db->escape($_POST['new_role_code']) : '';
    } else {
        $role_code = isset($_POST['role_code']) ? $db->escape($_POST['role_code']) : '';
    }
    $role_name = $db->escape($_POST['role_name']);
    $privilege_level = (int)$_POST['privilege_level'];
    $description = isset($_POST['description']) ? $db->escape($_POST['description']) : '';
    $department_id = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : 'NULL';
    $max_occupants = (int)$_POST['max_occupants'];

    $can_manage_users = isset($_POST['can_manage_users']) ? 1 : 0;
    $can_approve_requests = isset($_POST['can_approve_requests']) ? 1 : 0;
    $can_manage_budget = isset($_POST['can_manage_budget']) ? 1 : 0;
    $can_view_reports = isset($_POST['can_view_reports']) ? 1 : 0;
    $can_manage_system = isset($_POST['can_manage_system']) ? 1 : 0;

    // Validation
    $errors = [];
    if (empty($role_code)) $errors[] = "Role code is required";
    if (empty($role_name)) $errors[] = "Role name is required";
    if ($max_occupants < 0) $errors[] = "Max occupants must be 0 or greater";

    // Check duplicate role name
    $check_name = $db->query("SELECT id FROM roles WHERE role_name = '$role_name'");
    if ($check_name && mysqli_num_rows($check_name) > 0) {
        $errors[] = "Role name already exists. Please choose a different name.";
    }

    // Validate department
    if ($department_id !== 'NULL') {
        $dept_check = $db->query("SELECT id FROM departments WHERE id = $department_id");
        if (!$dept_check || mysqli_num_rows($dept_check) == 0) {
            $errors[] = "Invalid department";
        }
    }

    if ($is_new_code) {
        if (!preg_match('/^[A-Z0-9_]+$/', $role_code)) {
            $errors[] = "Role code must contain only uppercase letters, numbers, and underscores";
        }
    }

    if (empty($errors)) {
        $dept_sql = ($department_id === 'NULL') ? 'NULL' : $department_id;
        $insert = "INSERT INTO roles (
            role_code, role_name, privilege_level, description,
            department_id, max_occupants,
            can_manage_users, can_approve_requests, can_manage_budget, can_view_reports, can_manage_system
        ) VALUES (
            '$role_code', '$role_name', $privilege_level, '$description',
            $dept_sql, $max_occupants,
            $can_manage_users, $can_approve_requests, $can_manage_budget, $can_view_reports, $can_manage_system
        )";

        if ($db->query($insert)) {
            $message = "Role added successfully";
            logActivity($_SESSION['user_id'], "Added role: $role_name", 'role', $db->lastInsertId());

            // Refresh role codes for dropdown
            $role_codes_query = $db->query("SELECT DISTINCT role_code FROM roles ORDER BY role_code");
            $role_codes = [];
            if ($role_codes_query) {
                while ($row = mysqli_fetch_assoc($role_codes_query)) {
                    $role_codes[] = $row['role_code'];
                }
            }
        } else {
            $error = "Error adding role: " . mysqli_error($db->getConnection());
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Delete role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['roles_csrf_token']) {
        die('Invalid security token');
    }

    $role_id = (int)$_POST['role_id'];

    // Check if role is in use in user_roles
    $check_users = $db->query("SELECT COUNT(*) as count FROM user_roles WHERE role_id = $role_id");
    $user_count = $check_users ? mysqli_fetch_assoc($check_users)['count'] : 0;

    $role_info = $db->query("SELECT role_code, role_name FROM roles WHERE id = $role_id");
    $role_info = $role_info ? mysqli_fetch_assoc($role_info) : null;

    $critical_roles = ['ADMIN', 'CUSTOMER', 'VENDOR'];

    if (!$role_info) {
        $warning = "Role not found";
    } elseif (in_array($role_info['role_code'], $critical_roles)) {
        $warning = "Cannot delete system-critical role: {$role_info['role_name']}";
    } elseif ($user_count > 0) {
        $warning = "Cannot delete role that is currently assigned to $user_count user(s). Remove all assignments first.";
    } else {
        $delete = "DELETE FROM roles WHERE id = $role_id";
        if ($db->query($delete)) {
            $message = "Role deleted successfully";
            logActivity($_SESSION['user_id'], "Deleted role: {$role_info['role_name']}", 'role', $role_id);
        } else {
            $error = "Error deleting role: " . mysqli_error($db->getConnection());
        }
    }
}

// Get filter and search parameters
$filter = $_GET['filter'] ?? 'all';
$search = isset($_GET['search']) ? $db->escape($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build where clause
$where = "1=1";
if ($filter === 'admin') {
    $where .= " AND privilege_level >= 80";
} elseif ($filter === 'staff') {
    $where .= " AND privilege_level BETWEEN 10 AND 60";
} elseif ($filter === 'customer') {
    $where .= " AND role_code IN ('CUSTOMER', 'VENDOR')";
}
if (!empty($search)) {
    $where .= " AND (role_name LIKE '%$search%' OR role_code LIKE '%$search%')";
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM roles WHERE $where";
$count_result = $db->query($count_query);
$total_rows = $count_result ? mysqli_fetch_assoc($count_result)['total'] : 0;
$total_pages = $total_rows > 0 ? ceil($total_rows / $limit) : 1;

// Fetch roles with additional info (no positions)
$query = "
    SELECT 
        r.*,
        d.dept_name as department_name,
        (SELECT COUNT(*) FROM user_roles WHERE role_id = r.id) as user_count
    FROM roles r
    LEFT JOIN departments d ON r.department_id = d.id
    WHERE $where
    ORDER BY r.privilege_level DESC, r.role_name
    LIMIT $offset, $limit
";
$roles = $db->query($query);

// Get stats
$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(privilege_level >= 80) as admin_roles,
        SUM(privilege_level BETWEEN 10 AND 60) as staff_roles,
        SUM(role_code IN ('CUSTOMER', 'VENDOR')) as customer_roles
    FROM roles
";
$stats_result = $db->query($stats_query);
$stats = $stats_result ? mysqli_fetch_assoc($stats_result) : ['total' => 0, 'admin_roles' => 0, 'staff_roles' => 0, 'customer_roles' => 0];

// Privilege level options
$privilege_options = [
    100 => 'Administrator (100)',
    80 => 'Head of Department (80)',
    60 => 'Supervisor (60)',
    50 => 'Manager (50)',
    30 => 'Officer (30)',
    20 => 'Assistant (20)',
    15 => 'Staff (15)',
    10 => 'Basic Staff (10)',
    1 => 'Customer/Vendor (1)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Roles · Fingerchops Bakery</title>
    <link rel="icon" type="image/jpeg" href="../logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/roles.css">
</head>
<body>
    <div class="preloader" id="preloader">
        <div class="preloader-spinner"></div>
    </div>

    <div class="dashboard-container">
        <div class="page-header">
            <h1><i class="fas fa-lock"></i> Roles & Permissions Management</h1>
            <div class="header-actions">
                <a href="../admin-dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <button class="btn btn-primary btn-large" onclick="openAddModal()"><i class="fas fa-plus-circle"></i> Add New Role</button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-number"><?php echo $stats['total']; ?></div><div class="stat-label">Total Roles</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['admin_roles']; ?></div><div class="stat-label">Admin Level</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['staff_roles']; ?></div><div class="stat-label">Staff Roles</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['customer_roles']; ?></div><div class="stat-label">Customer/Vendor</div></div>
        </div>

        <!-- Search and Filter -->
        <div class="search-section">
            <form method="GET" class="search-box">
                <input type="text" name="search" placeholder="Search by role name or code..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit"><i class="fas fa-search"></i> Search</button>
            </form>
            <div class="filter-buttons">
                <a href="?filter=all<?php echo $search ? '&search='.$search : ''; ?>" class="filter-icon <?php echo $filter === 'all' ? 'active' : ''; ?>" title="Show all roles"><i class="fas fa-list-ul"></i></a>
                <a href="?filter=admin<?php echo $search ? '&search='.$search : ''; ?>" class="filter-icon <?php echo $filter === 'admin' ? 'active' : ''; ?>" title="Show admin level roles (80-100)"><i class="fas fa-crown"></i></a>
                <a href="?filter=staff<?php echo $search ? '&search='.$search : ''; ?>" class="filter-icon <?php echo $filter === 'staff' ? 'active' : ''; ?>" title="Show staff roles (10-60)"><i class="fas fa-user-tie"></i></a>
                <a href="?filter=customer<?php echo $search ? '&search='.$search : ''; ?>" class="filter-icon <?php echo $filter === 'customer' ? 'active' : ''; ?>" title="Show customer/vendor roles (1)"><i class="fas fa-user"></i></a>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($warning): ?>
            <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo $warning; ?></div>
        <?php endif; ?>

        <!-- Roles Table -->
        <div class="table-responsive">
            <table class="roles-table">
                <thead>
                    <tr><th>Code</th><th>Role Name</th><th>Department</th><th>Privilege</th><th>Occupancy</th><th>Permissions</th><th>Usage</th><th>Actions</th> </thead>
                <tbody>
                    <?php if ($roles && mysqli_num_rows($roles) > 0): ?>
                        <?php while ($role = mysqli_fetch_assoc($roles)): 
                            $priv_class = $role['privilege_level'] >= 80 ? 'privilege-high' : ($role['privilege_level'] >= 50 ? 'privilege-medium' : ($role['privilege_level'] >= 20 ? 'privilege-low' : 'privilege-minimal'));
                            $disable_edit = ($role['role_code'] === 'ADMIN');
                            $disable_delete = in_array($role['role_code'], ['ADMIN', 'CUSTOMER', 'VENDOR']);
                            $occupancy = $role['user_count'] . ' / ' . ($role['max_occupants'] > 0 ? $role['max_occupants'] : '∞');
                        ?>
                            <td><span class="role-code"><?php echo htmlspecialchars($role['role_code']); ?></span>   </td>
                            <td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong>   </td>
                            <td><?php echo $role['department_name'] ? htmlspecialchars($role['department_name']) : '<em>Global</em>'; ?>   </td>
                            <td><span class="privilege-badge <?php echo $priv_class; ?>"><?php echo $role['privilege_level']; ?></span>  </td>
                            <td><?php echo $occupancy; ?>  </td>
                            <td>
                                <div class="permission-icons">
                                    <span class="perm-icon <?php echo $role['can_manage_users'] ? 'active' : ''; ?>" title="Manage users"><i class="fas fa-users-cog"></i></span>
                                    <span class="perm-icon <?php echo $role['can_approve_requests'] ? 'active' : ''; ?>" title="Approve requests"><i class="fas fa-check-double"></i></span>
                                    <span class="perm-icon <?php echo $role['can_manage_budget'] ? 'active' : ''; ?>" title="Manage budget"><i class="fas fa-coins"></i></span>
                                    <span class="perm-icon <?php echo $role['can_view_reports'] ? 'active' : ''; ?>" title="View reports"><i class="fas fa-chart-bar"></i></span>
                                    <span class="perm-icon <?php echo $role['can_manage_system'] ? 'active' : ''; ?>" title="Manage system"><i class="fas fa-cog"></i></span>
                                </div>
                               </td>
                            <td><span class="usage-text"><?php echo $role['user_count']; ?> user(s)</span>  </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal(<?php echo $role['id']; ?>)" title="Edit Role" <?php echo $disable_edit ? 'disabled' : ''; ?>><i class="fas fa-edit"></i></button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?php echo addslashes($role['role_name']); ?>', <?php echo $role['user_count']; ?>, '<?php echo $role['role_code']; ?>');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="role_id" value="<?php echo $role['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <button type="submit" class="action-btn delete-btn" title="Delete Role" <?php echo $disable_delete ? 'disabled' : ''; ?>><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </div>
                               </td>
                             </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="empty-state"><i class="fas fa-lock"></i><h3>No roles found</h3><p>Click the "Add New Role" button to create one.</p><button class="btn btn-primary" onclick="openAddModal()"><i class="fas fa-plus-circle"></i> Add Role</button></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?><?php echo $search ? '&search='.$search : ''; ?>" class="page-btn <?php echo $page === $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Add Role Modal -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header"><h2><i class="fas fa-plus-circle"></i> Add New Role</h2><button class="modal-close" onclick="closeModal('addModal')">&times;</button></div>
            <form method="POST" action="roles.php" onsubmit="return validateAddForm()">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="is_new_code" id="is_new_code" value="0">

                <div class="modal-body">
                    <div class="toggle-container">
                        <span class="toggle-label">Role Code Source:</span>
                        <label class="toggle-switch"><input type="checkbox" id="codeToggle" onchange="toggleCodeInput()"><span class="toggle-slider"></span></label>
                        <span class="toggle-status"><span class="existing">Using existing code</span><span class="new">Creating new code</span></span>
                    </div>
                    <div class="form-group role-code-field" id="existingCodeField">
                        <label><i class="fas fa-tag"></i> Role Code *</label>
                        <select name="role_code" id="existing_role_code">
                            <option value="">Select Existing Code</option>
                            <?php foreach ($role_codes as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint-text">Select an existing role code from the database</div>
                    </div>
                    <div class="form-group role-code-field hidden" id="newCodeField">
                        <label><i class="fas fa-plus-circle"></i> New Role Code *</label>
                        <input type="text" name="new_role_code" id="new_role_code" placeholder="e.g., SUPERVISOR, COORDINATOR" maxlength="20" style="text-transform: uppercase;">
                        <div class="hint-text">Create a new role code (uppercase letters, numbers, underscore)</div>
                    </div>
                    <div class="form-group"><label><i class="fas fa-user-tag"></i> Role Name *</label><input type="text" name="role_name" required placeholder="e.g., Department Supervisor"></div>
                    <div class="form-group">
                        <label>
                            <i class="fas fa-building"></i> Department
                        </label>
                        <select name="department_id" id="edit_department_id">
                            <option value="">Global (No Department)</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['dept_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint-text">If assigned to a department, this role is only available in that department</div>
                    </div>
                    <div class="form-group"><label><i class="fas fa-users"></i> Max Occupants</label><input type="number" name="max_occupants" id="add_max_occupants" value="1" min="0"><div class="hint-text">Maximum number of users who can hold this role (0 = unlimited)</div></div>
                    <div class="form-group"><label><i class="fas fa-level-up-alt"></i> Privilege Level *</label><select name="privilege_level" required><?php foreach ($privilege_options as $level => $label): ?><option value="<?php echo $level; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label><i class="fas fa-align-left"></i> Description</label><textarea name="description" rows="3" placeholder="What this role can do..."></textarea></div>
                    <div class="form-group"><label>Permissions</label>
                        <div class="checkbox-group"><input type="checkbox" name="can_manage_users" value="1" id="add_can_manage_users"><label for="add_can_manage_users">Can manage users</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_approve_requests" value="1" id="add_can_approve_requests"><label for="add_can_approve_requests">Can approve requests</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_manage_budget" value="1" id="add_can_manage_budget"><label for="add_can_manage_budget">Can manage budget</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_view_reports" value="1" id="add_can_view_reports"><label for="add_can_view_reports">Can view reports</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_manage_system" value="1" id="add_can_manage_system"><label for="add_can_manage_system">Can manage system</label></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button><button type="submit" class="btn btn-primary">Create Role</button></div>
            </form>
        </div>
    </div>

    <!-- Edit Role Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content edit-modal-content">
            <div class="modal-header"><h2><i class="fas fa-edit"></i> Edit Role</h2><button class="modal-close" onclick="closeModal('editModal')">&times;</button></div>
            <div class="modal-body edit-modal-body">
                <form id="editRoleForm">
                    <input type="hidden" name="ajax" value="update_role">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="role_id" id="edit_role_id">
                    <input type="hidden" name="is_new_code" id="edit_is_new_code" value="0">

                    <div class="toggle-container">
                        <span class="toggle-label">Role Code Source:</span>
                        <label class="toggle-switch"><input type="checkbox" id="editCodeToggle" onchange="toggleEditCodeInput()"><span class="toggle-slider"></span></label>
                        <span class="toggle-status"><span class="existing">Using existing code</span><span class="new">Creating new code</span></span>
                    </div>
                    <div class="form-group role-code-field" id="editExistingCodeField">
                        <label><i class="fas fa-tag"></i> Role Code *</label>
                        <select name="role_code" id="edit_existing_role_code">
                            <option value="">Select Existing Code</option>
                            <?php foreach ($role_codes as $code): ?>
                                <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($code); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group role-code-field hidden" id="editNewCodeField">
                        <label><i class="fas fa-plus-circle"></i> New Role Code *</label>
                        <input type="text" name="new_role_code" id="edit_new_role_code" placeholder="e.g., SUPERVISOR, COORDINATOR" maxlength="20" style="text-transform: uppercase;">
                    </div>
                    <div class="form-group"><label><i class="fas fa-user-tag"></i> Role Name *</label><input type="text" name="role_name" id="edit_role_name" required></div>
                    <div class="form-group">
                        <label>
                            <i class="fas fa-building"></i> Department
                        </label>
                        <select name="department_id" id="edit_department_id">
                            <option value="">Global (No Department)</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['dept_name']); ?></option>
                            <?php endforeach; ?>
                        </select> 
                    </div>                
                    <div class="form-group"><label><i class="fas fa-users"></i> Max Occupants</label><input type="number" name="max_occupants" id="edit_max_occupants" value="1" min="0"></div>
                    <div class="form-group"><label><i class="fas fa-level-up-alt"></i> Privilege Level *</label><select name="privilege_level" id="edit_privilege_level" required><?php foreach ($privilege_options as $level => $label): ?><option value="<?php echo $level; ?>"><?php echo $label; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label><i class="fas fa-align-left"></i> Description</label><textarea name="description" id="edit_description" rows="3"></textarea></div>
                    <div class="form-group"><label>Permissions</label>
                        <div class="checkbox-group"><input type="checkbox" name="can_manage_users" id="edit_can_manage_users" value="1"><label for="edit_can_manage_users">Can manage users</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_approve_requests" id="edit_can_approve_requests" value="1"><label for="edit_can_approve_requests">Can approve requests</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_manage_budget" id="edit_can_manage_budget" value="1"><label for="edit_can_manage_budget">Can manage budget</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_view_reports" id="edit_can_view_reports" value="1"><label for="edit_can_view_reports">Can view reports</label></div>
                        <div class="checkbox-group"><input type="checkbox" name="can_manage_system" id="edit_can_manage_system" value="1"><label for="edit_can_manage_system">Can manage system</label></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button><button type="button" class="btn btn-primary" onclick="updateRole()">Save Changes</button></div>
        </div>
    </div>

    <script>
        window.addEventListener('load', function() {
            setTimeout(function() { const preloader = document.getElementById('preloader'); if (preloader) preloader.classList.add('fade-out'); }, 500);
        });
        setTimeout(function() { const preloader = document.getElementById('preloader'); if (preloader && !preloader.classList.contains('fade-out')) preloader.classList.add('fade-out'); }, 3000);

        function openAddModal() {
            document.getElementById('addModal').classList.add('show');
            document.getElementById('codeToggle').checked = false;
            toggleCodeInput();
            document.getElementById('existing_role_code').value = '';
            document.getElementById('new_role_code').value = '';
            document.querySelector('#addModal input[name="role_name"]').value = '';
            document.querySelector('#addModal select[name="privilege_level"]').value = '';
            document.querySelector('#addModal textarea[name="description"]').value = '';
            document.querySelector('#addModal input[name="max_occupants"]').value = '1';
            document.querySelector('#addModal select[name="department_id"]').value = '';
            document.querySelectorAll('#addModal .checkbox-group input').forEach(cb => cb.checked = false);
        }

        function closeModal(modalId) { document.getElementById(modalId).classList.remove('show'); }

        function toggleCodeInput() {
            const isChecked = document.getElementById('codeToggle').checked;
            const existingField = document.getElementById('existingCodeField');
            const newField = document.getElementById('newCodeField');
            const isNewCode = document.getElementById('is_new_code');
            if (isChecked) {
                existingField.classList.add('hidden');
                newField.classList.remove('hidden');
                document.getElementById('existing_role_code').required = false;
                document.getElementById('new_role_code').required = true;
                isNewCode.value = '1';
            } else {
                existingField.classList.remove('hidden');
                newField.classList.add('hidden');
                document.getElementById('existing_role_code').required = true;
                document.getElementById('new_role_code').required = false;
                isNewCode.value = '0';
            }
        }

        function toggleEditCodeInput() {
            const isChecked = document.getElementById('editCodeToggle').checked;
            const existingField = document.getElementById('editExistingCodeField');
            const newField = document.getElementById('editNewCodeField');
            const isNewCode = document.getElementById('edit_is_new_code');
            if (isChecked) {
                existingField.classList.add('hidden');
                newField.classList.remove('hidden');
                document.getElementById('edit_existing_role_code').required = false;
                document.getElementById('edit_new_role_code').required = true;
                isNewCode.value = '1';
            } else {
                existingField.classList.remove('hidden');
                newField.classList.add('hidden');
                document.getElementById('edit_existing_role_code').required = true;
                document.getElementById('edit_new_role_code').required = false;
                isNewCode.value = '0';
            }
        }

        function validateAddForm() {
            const isNewCode = document.getElementById('is_new_code').value === '1';
            if (isNewCode) {
                const newCode = document.getElementById('new_role_code').value.trim();
                if (!newCode) { alert('Please enter a new role code'); return false; }
                if (!/^[A-Z0-9_]+$/.test(newCode)) { alert('Role code must contain only uppercase letters, numbers, and underscores'); return false; }
            } else {
                const existingCode = document.getElementById('existing_role_code').value;
                if (!existingCode) { alert('Please select a role code'); return false; }
            }
            const roleName = document.querySelector('#addModal input[name="role_name"]').value.trim();
            if (!roleName) { alert('Please enter a role name'); return false; }
            const privilegeLevel = document.querySelector('#addModal select[name="privilege_level"]').value;
            if (!privilegeLevel) { alert('Please select a privilege level'); return false; }
            const maxOccupants = document.getElementById('add_max_occupants').value;
            if (maxOccupants < 0) { alert('Max occupants cannot be negative'); return false; }
            return true;
        }

        function openEditModal(roleId) {
            const modal = document.getElementById('editModal');
            modal.classList.add('show');
            document.getElementById('editCodeToggle').checked = false;
            toggleEditCodeInput();
            document.getElementById('edit_role_id').value = roleId;
            document.getElementById('edit_role_name').value = 'Loading...';
            document.getElementById('edit_description').value = 'Loading...';
            document.getElementById('edit_new_role_code').value = '';
            document.getElementById('edit_is_new_code').value = '0';
            const formData = new FormData();
            formData.append('ajax', 'get_role');
            formData.append('role_id', roleId);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const role = data.role;
                    document.getElementById('edit_role_name').value = role.role_name;
                    document.getElementById('edit_privilege_level').value = role.privilege_level;
                    document.getElementById('edit_description').value = role.description || '';
                    document.getElementById('edit_existing_role_code').value = role.role_code;
                    document.getElementById('edit_department_id').value = role.department_id || '';
                    document.getElementById('edit_max_occupants').value = role.max_occupants;
                    document.getElementById('edit_can_manage_users').checked = role.can_manage_users == 1;
                    document.getElementById('edit_can_approve_requests').checked = role.can_approve_requests == 1;
                    document.getElementById('edit_can_manage_budget').checked = role.can_manage_budget == 1;
                    document.getElementById('edit_can_view_reports').checked = role.can_view_reports == 1;
                    document.getElementById('edit_can_manage_system').checked = role.can_manage_system == 1;
                } else { alert('Error loading role data: ' + (data.message || 'Unknown error')); closeModal('editModal'); }
            })
            .catch(error => { console.error('Error:', error); alert('Error loading role data'); closeModal('editModal'); });
        }

        function updateRole() {
            const formData = new FormData(document.getElementById('editRoleForm'));
            const isChecked = document.getElementById('editCodeToggle').checked;
            if (isChecked) {
                const newCode = document.getElementById('edit_new_role_code').value.trim();
                if (!newCode) { alert('Please enter a new role code'); return; }
                if (!/^[A-Z0-9_]+$/.test(newCode)) { alert('Role code must contain only uppercase letters, numbers, and underscores'); return; }
                formData.set('new_role_code', newCode);
            } else {
                const selectedCode = document.getElementById('edit_existing_role_code').value;
                if (!selectedCode) { alert('Please select a role code'); return; }
                formData.set('role_code', selectedCode);
            }
            const roleName = document.getElementById('edit_role_name').value.trim();
            if (!roleName) { alert('Please enter a role name'); return; }
            const privilegeLevel = document.getElementById('edit_privilege_level').value;
            if (!privilegeLevel) { alert('Please select a privilege level'); return; }
            const maxOccupants = document.getElementById('edit_max_occupants').value;
            if (maxOccupants < 0) { alert('Max occupants cannot be negative'); return; }
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeModal('editModal');
                    window.location.reload();
                } else {
                    alert(data.message || 'Error updating role');
                }
            })
            .catch(error => { console.error('Error:', error); alert('Error updating role'); });
        }

        // (positions removed)
        function confirmDelete(roleName, userCount, roleCode) {
            const criticalRoles = ['ADMIN', 'CUSTOMER', 'VENDOR'];
            if (criticalRoles.includes(roleCode)) { alert(`Cannot delete system role: ${roleName}`); return false; }
            if (userCount > 0) { alert(`Cannot delete "${roleName}" because it is assigned to ${userCount} user(s). Remove all assignments first.`); return false; }
            return confirm(`⚠️ PERMANENT DELETION\n\nAre you sure you want to permanently delete the role "${roleName}"?\n\nThis action CANNOT be undone.`);
        }

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(el => { el.style.transition = 'opacity 0.5s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 500); });
        }, 5000);

        window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.classList.remove('show'); }

        // At the bottom of the <script> section, replace the preloader code with:
        window.addEventListener('load', function() {
            setTimeout(function() {
                const preloader = document.getElementById('preloader');
                if (preloader) {
                    preloader.classList.add('fade-out');
                    // Fallback: hide after transition (0.5s)
                    setTimeout(function() {
                        preloader.style.display = 'none';
                    }, 500);
                }
            }, 500);
        });
        // Also add a safety timeout in case load never fires
        setTimeout(function() {
            const preloader = document.getElementById('preloader');
            if (preloader && !preloader.classList.contains('fade-out')) {
                preloader.classList.add('fade-out');
                setTimeout(() => { preloader.style.display = 'none'; }, 500);
            }
        }, 3000);
    </script>
</body>
</html>