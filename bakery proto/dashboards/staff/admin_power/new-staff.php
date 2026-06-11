<?php
// =====================================================
// FILE: admin_power/new-staff.php
// PURPOSE: Create new staff accounts (Admin only)
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

// Generate CSRF token
if (!isset($_SESSION['newstaff_csrf_token'])) {
    $_SESSION['newstaff_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['newstaff_csrf_token'];

// Fetch departments for dropdown
$departments = $db->query("SELECT id, dept_name, dept_code FROM departments WHERE is_active = 1 ORDER BY dept_name");

// Fetch branches for dropdown
$branches = $db->query("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name");

// Fetch roles for staff with current occupancy counts (department-aware)
$roles = $db->query("
    SELECT 
        r.id, 
        r.role_name, 
        r.role_code, 
        r.privilege_level, 
        r.department_id, 
        r.max_occupants,
        COALESCE((
            SELECT COUNT(*) 
            FROM user_roles 
            WHERE role_id = r.id 
            AND is_active = 1 
            AND (r.department_id IS NULL OR department_id = r.department_id)
        ), 0) as current_occupants
    FROM roles r
    WHERE r.role_code NOT IN ('CUSTOMER', 'VENDOR')
    ORDER BY r.privilege_level DESC, r.role_name
");

$roles_array = [];
if ($roles) {
    while ($row = mysqli_fetch_assoc($roles)) {
        $roles_array[] = $row;
    }
    mysqli_data_seek($roles, 0); // reset for later use if needed
}

$message = '';
$error = '';
$show_success_modal = false;
$new_user_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_staff') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['newstaff_csrf_token']) {
        die('Invalid security token');
    }

    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $phone = '+234' . $phone_number;
    $department_id = (int)($_POST['department_id'] ?? 0);
    $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : 'NULL';
    $role_id = (int)($_POST['role_id'] ?? 0);
    $password = $_POST['password'] ?? '';

    // Validation
    $errors = [];
    if (empty($fullname)) $errors[] = "Full name is required";
    if (empty($username)) $errors[] = "Username is required";
    if (strlen($username) < 3) $errors[] = "Username must be at least 3 characters";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (empty($phone_number)) $errors[] = "Phone number is required";
    if (!preg_match('/^[0-9]{10}$/', $phone_number)) $errors[] = "Phone number must be exactly 10 digits";
    if ($department_id <= 0) $errors[] = "Please select a department";
    if ($role_id <= 0) $errors[] = "Please select a role";
    if (empty($password)) $errors[] = "Password is required";
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";

    // Validate role compatibility with department and occupancy limits
    $role_check = $db->query("SELECT department_id, max_occupants FROM roles WHERE id = $role_id");
    if ($role_check && mysqli_num_rows($role_check) > 0) {
        $role_data = mysqli_fetch_assoc($role_check);
        $role_dept = $role_data['department_id'];
        $max_occupants = (int)$role_data['max_occupants'];
        
        // Role must be either global (NULL) or belong to the selected department
        if ($role_dept !== null && $role_dept != $department_id) {
            $errors[] = "Selected role is not available in the chosen department";
        }
        
        // Check max occupants (department-aware)
        if ($max_occupants > 0) {
            $where = "role_id = $role_id AND is_active = 1";
            if ($role_dept !== null) {
                // Role is department-specific, count only within this department
                $where .= " AND department_id = $department_id";
            }
            $current = $db->query("SELECT COUNT(*) as cnt FROM user_roles WHERE $where");
            $current_count = $current ? (int)mysqli_fetch_assoc($current)['cnt'] : 0;
            if ($current_count >= $max_occupants) {
                $errors[] = "This role has reached its maximum occupancy ($max_occupants)";
            }
        }
    } else {
        $errors[] = "Invalid role selected";
    }

    // Check uniqueness using database directly
    if (!empty($username)) {
        $check = $db->query("SELECT id FROM bakery_users WHERE username = '" . $db->escape($username) . "'");
        if ($check && mysqli_num_rows($check) > 0) $errors[] = "Username already taken";
    }
    if (!empty($email)) {
        $check = $db->query("SELECT id FROM bakery_users WHERE email = '" . $db->escape($email) . "'");
        if ($check && mysqli_num_rows($check) > 0) $errors[] = "Email already registered";
    }
    if (!empty($phone)) {
        $check = $db->query("SELECT id FROM bakery_users WHERE phone = '" . $db->escape($phone) . "'");
        if ($check && mysqli_num_rows($check) > 0) $errors[] = "Phone number already registered";
    }

    if (empty($errors)) {
        // Generate unique user ID
        $prefix = 'ST';
        do {
            $random = strtoupper(substr(uniqid(), -6));
            $user_id = "FNG-{$prefix}-{$random}";
            $check = $db->query("SELECT id FROM bakery_users WHERE user_id = '$user_id'");
        } while ($check && mysqli_num_rows($check) > 0);

        $password_hash = hashPassword($password);
        $branch_sql = ($branch_id === 'NULL') ? 'NULL' : $branch_id;

        // Insert into bakery_users
        $insert_user = "INSERT INTO bakery_users (
            user_id, fullname, username, user_type, branch_id, phone, email, password_hash, 
            is_verified, hire_date
        ) VALUES (
            '$user_id', '$fullname', '$username', 'staff', $branch_sql, '$phone', '$email', 
            '" . $db->escape($password_hash) . "', 1, CURDATE()
        )";

        if ($db->query($insert_user)) {
            $new_user_id = $db->lastInsertId();

            // Assign role in user_roles
            $role_assign = "INSERT INTO user_roles (
                user_id, role_id, department_id, assigned_by, assigned_date, is_active
            ) VALUES (
                $new_user_id, $role_id, $department_id, " . $_SESSION['user_id'] . ", NOW(), 1
            )";
            if (!$db->query($role_assign)) {
                error_log("User role insert failed: " . mysqli_error($db->getConnection()));
                // Continue, but log error
            }

            // Get department name
            $dept_info = $db->query("SELECT dept_name FROM departments WHERE id = $department_id");
            $dept_name = ($dept_info && mysqli_num_rows($dept_info) > 0) ? mysqli_fetch_assoc($dept_info)['dept_name'] : 'Unknown';

            // Get role name
            $role_info = $db->query("SELECT role_name FROM roles WHERE id = $role_id");
            $role_name = ($role_info && mysqli_num_rows($role_info) > 0) ? mysqli_fetch_assoc($role_info)['role_name'] : 'Unknown';

            // Get branch name
            $branch_name = 'Headquarters';
            if ($branch_id !== 'NULL') {
                $branch_info = $db->query("SELECT branch_name FROM branches WHERE id = $branch_id");
                if ($branch_info && mysqli_num_rows($branch_info) > 0) {
                    $branch_name = mysqli_fetch_assoc($branch_info)['branch_name'];
                }
            }

            // Log activity
            logActivity($_SESSION['user_id'], "Created new staff: $fullname (ID: $user_id)", 'user', $new_user_id);

            // Prepare success data
            $new_user_data = [
                'user_id' => $user_id,
                'fullname' => $fullname,
                'username' => $username,
                'email' => $email,
                'phone' => $phone,
                'phone_number' => $phone_number,
                'department' => $dept_name,
                'role' => $role_name,
                'branch' => $branch_name
            ];
            $show_success_modal = true;

            // Clear POST to prevent resubmission
            $_POST = [];
        } else {
            $error = "Database error: " . mysqli_error($db->getConnection());
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Staff · Fingerchops Ventures</title>
    <link rel="icon" type="image/jpeg" href="../logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/new-staff.css">
    <style>
        .required { color: #e53e3e; margin-left: 2px; }
        .role-full-badge { color: #e53e3e; font-size: 0.75rem; margin-left: 6px; }
        .role-hint { margin-top: 5px; font-size: 0.75rem; color: #e53e3e; }
    </style>
</head>
<body>
    <div class="preloader" id="preloader"><div class="preloader-spinner"></div></div>
    <div class="dashboard-container">
        <div class="form-container">
            <div class="form-title">
                <h1><i class="fas fa-user-plus"></i> Create New Staff</h1>
                <a href="../admin-dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <div><?php echo $error; ?></div></div>
            <?php endif; ?>
            <form method="POST" action="" id="staffForm" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="create_staff">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Full Name <span class="required">*</span></label>
                        <input type="text" name="fullname" required value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" placeholder="e.g., John Smith">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-at"></i> Username <span class="required">*</span></label>
                        <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" placeholder="e.g., john_smith">
                        <div class="hint-text">Minimum 3 characters, letters/numbers/underscores only</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="john@fingerchops.com">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone (Nigeria) <span class="required">*</span></label>
                        <div class="phone-input-wrapper">
                            <span class="phone-prefix">+234</span>
                            <input type="text" name="phone_number" class="phone-input-field" required value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>" placeholder="8012345678" pattern="[0-9]{10}" maxlength="10" title="Please enter exactly 10 digits">
                        </div>
                        <div class="hint-text"><i class="fas fa-info-circle"></i> Enter <strong>10 digits</strong> after +234 (e.g., 8012345678)</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Department <span class="required">*</span></label>
                        <select name="department_id" id="department_id" required>
                            <option value="">Select Department</option>
                            <?php if ($departments): mysqli_data_seek($departments, 0); while ($dept = mysqli_fetch_assoc($departments)): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo (isset($_POST['department_id']) && $_POST['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                    <?php echo $dept['dept_name']; ?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-map-marker-alt"></i> Branch</label>
                        <select name="branch_id" id="branch_id">
                            <option value="">-- None --</option>
                            <?php if ($branches): mysqli_data_seek($branches, 0); while ($branch = mysqli_fetch_assoc($branches)): ?>
                                <option value="<?php echo $branch['id']; ?>" <?php echo (isset($_POST['branch_id']) && $_POST['branch_id'] == $branch['id']) ? 'selected' : ''; ?>>
                                    <?php echo $branch['branch_name']; ?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Role <span class="required">*</span></label>
                        <select name="role_id" id="role_id" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles_array as $role):
                                $disabled = ($role['max_occupants'] > 0 && $role['current_occupants'] >= $role['max_occupants']);
                                $full_text = $disabled ? ' (FULL)' : '';
                                $disabled_attr = $disabled ? 'disabled' : '';
                                $data_dept = $role['department_id'] ?? '';
                            ?>
                                <option value="<?php echo $role['id']; ?>" data-department="<?php echo $data_dept; ?>" <?php echo $disabled_attr; ?> <?php echo (isset($_POST['role_id']) && $_POST['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role['role_name']); ?> (Level <?php echo $role['privilege_level']; ?>)<?php echo $full_text; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="roleHint" class="role-hint"></div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password <span class="required">*</span></label>
                        <input type="text" id="password" name="password" required value="<?php echo htmlspecialchars($_POST['password'] ?? 'Staff@123'); ?>" placeholder="Default: Staff@123">
                        <div class="hint-text"><i class="fas fa-info-circle"></i> Default password: <strong>Staff@123</strong> (you can change it if needed)</div>
                    </div>
                    <div class="form-group full-width">
                        <label><i class="fas fa-id-card"></i> User ID (Auto-generated)</label>
                        <div class="id-preview">Will be generated as FNG-ST-XXXXXX upon creation</div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="reset" class="btn btn-secondary" id="resetBtn"><i class="fas fa-undo"></i> Clear</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-save"></i> Create Staff Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="success-modal <?php echo $show_success_modal ? 'show' : ''; ?>" id="successModal">
        <div class="success-content">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <h2>Staff Created!</h2>
            <p>New staff account has been created successfully. Please review the details below:</p>
            <div class="user-id-box">
                <div class="label">User ID</div>
                <div class="id-value"><?php echo $new_user_data['user_id'] ?? ''; ?></div>
            </div>
            <div class="success-details">
                <p><i class="fas fa-user"></i> <strong>Full Name:</strong> <?php echo $new_user_data['fullname'] ?? ''; ?></p>
                <p><i class="fas fa-at"></i> <strong>Username:</strong> <?php echo $new_user_data['username'] ?? ''; ?></p>
                <p><i class="fas fa-envelope"></i> <strong>Email:</strong> <?php echo $new_user_data['email'] ?? ''; ?></p>
                <p><i class="fas fa-phone"></i> <strong>Phone:</strong> +234 <?php echo $new_user_data['phone_number'] ?? ''; ?></p>
                <p><i class="fas fa-building"></i> <strong>Department:</strong> <?php echo $new_user_data['department'] ?? ''; ?></p>
                <p><i class="fas fa-tag"></i> <strong>Role:</strong> <?php echo $new_user_data['role'] ?? ''; ?></p>
                <p><i class="fas fa-map-marker-alt"></i> <strong>Branch:</strong> <?php echo $new_user_data['branch'] ?? 'Headquarters'; ?></p>
            </div>
            <div class="review-note"><i class="fas fa-info-circle"></i> Please review this information. If there are any errors, you can edit the user later.</div>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-check"></i> Done</button>
        </div>
    </div>

    <script>
        // Preloader hide
        window.addEventListener('load', function() {
            setTimeout(function() {
                const preloader = document.getElementById('preloader');
                if (preloader) preloader.classList.add('fade-out');
            }, 500);
        });
        setTimeout(function() {
            const preloader = document.getElementById('preloader');
            if (preloader && !preloader.classList.contains('fade-out')) preloader.classList.add('fade-out');
        }, 3000);

        // Role filtering based on selected department, and disable full roles
        const departmentSelect = document.getElementById('department_id');
        const roleSelect = document.getElementById('role_id');
        const allRoles = <?php echo json_encode($roles_array); ?>;

        function filterRolesByDepartment() {
            const selectedDept = departmentSelect.value;
            if (!roleSelect) return;
            const options = roleSelect.options;
            for (let i = 0; i < options.length; i++) {
                const opt = options[i];
                if (!opt.value) continue; // skip placeholder
                const roleDept = opt.getAttribute('data-department');
                // Show if no department selected (show all) OR role is global (empty string) OR role belongs to selected department
                const matchesDept = (!selectedDept) || roleDept === '' || roleDept == selectedDept;
                opt.style.display = matchesDept ? '' : 'none';
            }
            // Reset selection if the currently selected role is hidden
            if (roleSelect.selectedIndex > 0 && roleSelect.options[roleSelect.selectedIndex].style.display === 'none') {
                roleSelect.value = '';
            }
            updateRoleHint();
        }

        function updateRoleHint() {
            const selectedRole = roleSelect.options[roleSelect.selectedIndex];
            if (selectedRole && selectedRole.value) {
                const roleId = selectedRole.value;
                const role = allRoles.find(r => r.id == roleId);
                if (role && role.max_occupants > 0 && role.current_occupants >= role.max_occupants) {
                    document.getElementById('roleHint').innerHTML = '<i class="fas fa-exclamation-triangle"></i> This role is currently full. No more users can be assigned.';
                } else {
                    document.getElementById('roleHint').innerHTML = '';
                }
            } else {
                document.getElementById('roleHint').innerHTML = '';
            }
        }

        departmentSelect?.addEventListener('change', filterRolesByDepartment);
        roleSelect?.addEventListener('change', updateRoleHint);
        filterRolesByDepartment(); // initial run

        // Modal functions
        function closeModal() {
            document.getElementById('successModal').classList.remove('show');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Clear confirmation
        document.getElementById('resetBtn')?.addEventListener('click', function(e) {
            if (!confirm('Clear all form fields?')) e.preventDefault();
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(el => {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            });
        }, 5000);

        // Form validation (additional)
        function validateForm() {
            const fullname = document.querySelector('input[name="fullname"]').value.trim();
            const username = document.querySelector('input[name="username"]').value.trim();
            const email = document.querySelector('input[name="email"]').value.trim();
            const phoneNumber = document.querySelector('input[name="phone_number"]').value.trim();
            const department = document.querySelector('select[name="department_id"]').value;
            const role = document.querySelector('select[name="role_id"]').value;
            const password = document.querySelector('input[name="password"]').value.trim();
            let errors = [];
            if (!fullname) errors.push("Full name is required");
            if (!username) errors.push("Username is required");
            if (username.length < 3) errors.push("Username must be at least 3 characters");
            if (!email) errors.push("Email is required");
            if (!email.includes('@') || !email.includes('.')) errors.push("Invalid email format");
            if (!phoneNumber) errors.push("Phone number is required");
            if (!/^\d+$/.test(phoneNumber)) errors.push("Phone number must contain only digits");
            if (phoneNumber.length !== 10) errors.push("Phone number must be exactly 10 digits");
            if (!department) errors.push("Please select a department");
            if (!role) errors.push("Please select a role");
            if (!password) errors.push("Password is required");
            if (password.length < 8) errors.push("Password must be at least 8 characters");
            if (errors.length > 0) {
                alert(errors.join('\n'));
                return false;
            }
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
            submitBtn.disabled = true;
            return true;
        }
    </script>
</body>
</html>