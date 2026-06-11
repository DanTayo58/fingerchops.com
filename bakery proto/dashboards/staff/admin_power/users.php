<?php
// admin_power/users.php - Manage all users
// Access: Admin only

// Production-safe error reporting
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

$adminObj = new User($_SESSION['user_id']);
$admin = $adminObj->getData();
if (!$admin) {
    header('Location: ../../../login_signup.php');
    exit;
}

$privilege_level = $adminObj->getPrivilegeLevel();
$minAdminLevel = setting('admin_privilege_level', 100);
if ($privilege_level < $minAdminLevel) {
    header('Location: ../../../login_signup.php');
    exit;
}

// CSRF token
if (!isset($_SESSION['users_csrf_token'])) {
    $_SESSION['users_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['users_csrf_token'];
$csrf_field = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') . '">';

// Handle actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['users_csrf_token']) {
        die('Invalid security token');
    }

    $action = $_POST['action'] ?? '';

    // Update user role
    if ($action === 'update_role') {
        $user_id = (int)$_POST['user_id'];
        $new_role_id = (int)$_POST['role_id'];
        $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

        $user = new User($user_id);
        if (!$user->getData()) {
            $error = "User not found";
        } else {
            // Remove current active role
            $user->removeRole($user_id, null); // We'll need a method to remove all active roles; we'll implement a simple query
            // Alternatively, assignRole already deactivates previous active roles
            $result = $user->assignRole($user_id, $new_role_id, $department_id);
            if ($result) {
                $message = "User role updated successfully";
                logActivity($_SESSION['user_id'], "Updated role for user #$user_id", 'user', $user_id);
            } else {
                $error = "Failed to update role: " . implode(', ', $user->getErrors());
            }
        }
    }

    // Update user details
    if ($action === 'update_details') {
        $user_id = (int)$_POST['user_id'];
        $fullname = trim($_POST['fullname']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;

        $user = new User($user_id);
        $update_data = ['fullname' => $fullname, 'email' => $email, 'phone' => $phone];
        if ($branch_id !== null) {
            $update_data['branch_id'] = $branch_id;
        }
        $result = $user->update($user_id, $update_data);
        if ($result['success']) {
            $message = "User details updated successfully";
            logActivity($_SESSION['user_id'], "Updated details for user #$user_id", 'user', $user_id);
        } else {
            $error = "Failed to update details: " . implode(', ', $user->getErrors());
        }
    }

    // Force reset password
    if ($action === 'force_reset_password') {
        $user_id = (int)$_POST['user_id'];
        $defaultPassword = "User@132";
        $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);
        
        $sql = "UPDATE bakery_users SET password_hash = ?, force_password_change = 1 WHERE id = ?";
        $result = $db->preparedExecute($sql, 'si', [$hashedPassword, $user_id]);
        
        if ($result) {
            $message = "User password has been reset to '$defaultPassword' and forced change enabled.";
            logActivity($_SESSION['user_id'], "Force reset password for user #$user_id", 'user', $user_id);
        } else {
            $error = "Failed to reset password.";
        }
    }
}

// Pagination and filtering
$search = isset($_GET['search']) ? $db->escape($_GET['search']) : '';
$user_type = isset($_GET['user_type']) ? $db->escape($_GET['user_type']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$where = "1=1";
if (!empty($search)) {
    $where .= " AND (fullname LIKE '%$search%' OR username LIKE '%$search%' OR email LIKE '%$search%' OR user_id LIKE '%$search%')";
}
if (!empty($user_type)) {
    $where .= " AND user_type = '$user_type'";
}

// Count total
$count_query = "SELECT COUNT(*) as total FROM bakery_users WHERE $where";
$count_result = $db->query($count_query);
$total_rows = $count_result ? mysqli_fetch_assoc($count_result)['total'] : 0;
$total_pages = ceil($total_rows / $limit);

// Fetch users with their current role
$users_query = "
    SELECT 
        u.*,
        r.role_name, r.role_code, r.id as role_id,
        ur.department_id,
        d.dept_name as department_name,
        b.branch_name
    FROM bakery_users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
    LEFT JOIN roles r ON ur.role_id = r.id
    LEFT JOIN departments d ON (r.department_id = d.id OR ur.department_id = d.id)
    LEFT JOIN branches b ON u.branch_id = b.id
    WHERE $where
    ORDER BY u.created_at DESC
    LIMIT $offset, $limit
";
$users = $db->query($users_query);

// Fetch all roles for dropdown
$roles_query = "SELECT id, role_name, role_code, department_id FROM roles ORDER BY privilege_level DESC, role_name";
$roles = $db->query($roles_query);
$all_roles = [];
if ($roles) {
    while ($row = mysqli_fetch_assoc($roles)) {
        $all_roles[] = $row;
    }
}

// Fetch all departments for dropdown
$depts_query = "SELECT id, dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name";
$departments = $db->query($depts_query);
$dept_list = [];
if ($departments) {
    while ($row = mysqli_fetch_assoc($departments)) {
        $dept_list[] = $row;
    }
}

// Fetch all branches for dropdown
$branches_query = "SELECT id, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name";
$branches = $db->query($branches_query);
$branch_list = [];
if ($branches) {
    while ($row = mysqli_fetch_assoc($branches)) {
        $branch_list[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users · Fingerchops Bakery</title>
    <link rel="icon" type="image/jpeg" href="../logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="css/users.css">
</head>
<body>
    <div class="preloader" id="preloader"><div class="preloader-spinner"></div></div>
    <div class="dashboard-container">
        <div class="page-header">
            <h1><i class="fas fa-users"></i> Manage Users</h1>
            <div class="header-actions">
                <a href="../admin-dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <a href="new-staff.php" class="btn btn-primary"><i class="fas fa-user-plus"></i> Add Staff</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="filter-bar">
            <form method="GET" style="display: flex; gap: 12px;">
                <input type="text" name="search" placeholder="Search by name, email, user ID" value="<?php echo htmlspecialchars($search); ?>">
                <select name="user_type">
                    <option value="">All Types</option>
                    <option value="staff" <?php echo $user_type === 'staff' ? 'selected' : ''; ?>>Staff</option>
                    <option value="customer" <?php echo $user_type === 'customer' ? 'selected' : ''; ?>>Customer</option>
                    <option value="vendor" <?php echo $user_type === 'vendor' ? 'selected' : ''; ?>>Vendor</option>
                </select>
                <button type="submit">Filter</button>
                <a href="users.php" class="btn-secondary">Reset</a>
            </form>
        </div>

        <div class="table-responsive">
            <table class="user-table">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Type</th><th>Branch</th><th>Role</th><th>Actions</th> </thead>
                <tbody>
                    <?php if ($users && mysqli_num_rows($users) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($users)): ?>
                             <tr>
                                <td><?php echo $row['user_id']; ?></td>
                                <td><?php echo htmlspecialchars($row['fullname']); ?></td>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo ucfirst($row['user_type']); ?></td>
                                <td><?php echo $row['branch_name'] ?? 'Headquarters'; ?></td>
                                <td><span class="role-badge"><?php echo $row['role_name'] ?? 'No Role'; ?></span></td>
                                <td class="action-buttons">
                                    <button class="btn-sm" title="Edit User" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['fullname']); ?>', '<?php echo addslashes($row['email']); ?>', '<?php echo addslashes($row['phone']); ?>', '<?php echo $row['branch_id']; ?>', '<?php echo $row['role_id']; ?>', '<?php echo $row['department_id']; ?>')"><i class="fas fa-edit"></i></button>
                                    <button class="btn-sm" title="Change Role" onclick="openRoleModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['fullname']); ?>', '<?php echo $row['role_id']; ?>', '<?php echo $row['department_id']; ?>')"><i class="fas fa-user-tag"></i></button>
                                    <button class="btn-sm btn-warning-sm" title="Reset Password" onclick="openResetModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['fullname']); ?>')"><i class="fas fa-key"></i></button>
                                </td>
                             </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="empty-state">No users found</td></tr>
                    <?php endif; ?>
                </tbody>
             </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&user_type=<?php echo urlencode($user_type); ?>" class="page-btn <?php echo $page === $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Edit Details Modal -->
    <div class="modal" id="editDetailsModal">
        <div class="modal-content">
            <div class="modal-header"><h3>Edit User Details</h3><button class="modal-close" onclick="closeModal('editDetailsModal')">&times;</button></div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_details">
                <?php echo $csrf_field; ?>
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group"><label>Full Name</label><input type="text" name="fullname" id="edit_fullname" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" required></div>
                <div class="form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" required></div>
                <div class="form-group"><label>Branch</label><select name="branch_id" id="edit_branch_id"><option value="">Headquarters</option><?php foreach ($branch_list as $branch): ?><option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-actions"><button type="button" class="btn-secondary" onclick="closeModal('editDetailsModal')">Cancel</button><button type="submit" class="btn-primary">Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- Change Role Modal -->
    <div class="modal" id="roleModal">
        <div class="modal-content">
            <div class="modal-header"><h3>Change Role</h3><button class="modal-close" onclick="closeModal('roleModal')">&times;</button></div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_role">
                <?php echo $csrf_field; ?>
                <input type="hidden" name="user_id" id="role_user_id">
                <div class="form-group"><label>User</label><input type="text" id="role_user_name" disabled style="background:#f5f5f5;"></div>
                <div class="form-group"><label>Role</label><select name="role_id" id="role_select" required><option value="">Select Role</option><?php foreach ($all_roles as $role): ?><option value="<?php echo $role['id']; ?>" data-dept="<?php echo $role['department_id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-group" id="dept_select_group" style="display: none;"><label>Department (if role is global)</label><select name="department_id" id="dept_select"><option value="">None</option><?php foreach ($dept_list as $dept): ?><option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['dept_name']); ?></option><?php endforeach; ?></select></div>
                <div class="form-actions"><button type="button" class="btn-secondary" onclick="closeModal('roleModal')">Cancel</button><button type="submit" class="btn-primary">Update Role</button></div>
            </form>
        </div>
    </div>

    <!-- Force Reset Password Modal -->
    <div class="modal" id="resetPasswordModal">
        <div class="modal-content">
            <div class="modal-header"><h3>Force Password Reset</h3><button class="modal-close" onclick="closeModal('resetPasswordModal')">&times;</button></div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="force_reset_password">
                <?php echo $csrf_field; ?>
                <input type="hidden" name="user_id" id="reset_user_id">
                <p>Are you sure you want to reset the password for <strong id="reset_user_name"></strong>?</p>
                <div class="alert alert-warning" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; margin-top:15px;">
                    <i class="fas fa-info-circle"></i> This will set the password to <strong>User@132</strong> and force the user to change it on their next login.
                </div>
                <div class="form-actions"><button type="button" class="btn-secondary" onclick="closeModal('resetPasswordModal')">Cancel</button><button type="submit" class="btn-primary" style="background:#dc2626;">Yes, Reset Password</button></div>
            </form>
        </div>
    </div>

    <script>
        // Preloader hide
        window.addEventListener('load', function() { setTimeout(function() { const preloader = document.getElementById('preloader'); if (preloader) preloader.classList.add('fade-out'); }, 500); });
        setTimeout(function() { const preloader = document.getElementById('preloader'); if (preloader && !preloader.classList.contains('fade-out')) preloader.classList.add('fade-out'); }, 3000);

        function openEditModal(userId, fullname, email, phone, branchId, roleId, deptId) {
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_fullname').value = fullname;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_branch_id').value = branchId || '';
            document.getElementById('editDetailsModal').classList.add('show');
        }

        function openRoleModal(userId, fullname, currentRoleId, currentDeptId) {
            document.getElementById('role_user_id').value = userId;
            document.getElementById('role_user_name').value = fullname;
            document.getElementById('role_select').value = currentRoleId || '';
            // Show department field only for roles that are global (department_id null) and not a specific dept role
            const roleSelect = document.getElementById('role_select');
            const deptGroup = document.getElementById('dept_select_group');
            function toggleDeptField() {
                const selectedRole = roleSelect.options[roleSelect.selectedIndex];
                const roleDept = selectedRole ? selectedRole.getAttribute('data-dept') : null;
                if (!roleDept || roleDept === '') {
                    deptGroup.style.display = 'block';
                } else {
                    deptGroup.style.display = 'none';
                    // If role has a fixed department, we could set the hidden field accordingly, but the form's department_id will be ignored for role-specific roles? Actually, we can keep it hidden.
                }
            }
            roleSelect.addEventListener('change', toggleDeptField);
            toggleDeptField();
            document.getElementById('roleModal').classList.add('show');
        }

        function openResetModal(userId, fullname) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_user_name').textContent = fullname;
            document.getElementById('resetPasswordModal').classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        };
    </script>
</body>
</html>