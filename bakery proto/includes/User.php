<?php
// includes/User.php - User management class for PHP 8.3
// Version: 5.0 (PHP 8.3+ with prepared statements, fixed role assignment logic, enhanced security)

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

/**
 * User Management Class
 * 
 * @package Fingerchops
 * @version 5.0
 */
class User {
    private Database $db;
    private Validation $validator;
    private ?int $userId = null;
    private ?array $userData = null;
    private array $errors = [];
    
    // User type constants
    private const USER_TYPES = ['customer', 'vendor', 'staff'];
    
    // Vendor status constants
    private const VENDOR_STATUSES = ['pending', 'active', 'suspended', 'inactive'];
    
    public function __construct(?int $userId = null) {
        $this->db = Database::getInstance();
        $this->validator = new Validation();
        
        if ($userId !== null) {
            $this->loadUser($userId);
        }
    }
    
    public function getErrors(): array {
        return $this->errors;
    }
    
    public function clearErrors(): void {
        $this->errors = [];
    }
    
    /**
     * Load user data by ID
     */
    private function loadUser(int $userId): bool {
        $userId = (int)$userId;
        
        $this->userData = $this->db->preparedFetchOne(
            "SELECT * FROM bakery_users WHERE id = ?",
            'i',
            [$userId]
        );
        
        if ($this->userData) {
            $this->userId = $userId;
            return true;
        }
        
        $this->errors[] = "User not found with ID: {$userId}";
        return false;
    }
    
    public function getData(?string $field = null): mixed {
        if ($field === null) {
            return $this->userData;
        }
        return $this->userData[$field] ?? null;
    }
    
    public function getId(): ?int {
        return $this->userId;
    }
    
    /**
     * Create a new user
     */
    public function create(array $data): array {
        $this->errors = [];
        
        // Validate required fields
        $required = ['fullname', 'username', 'email', 'phone', 'password', 'user_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->errors[] = ucfirst($field) . ' is required';
                return ['success' => false, 'errors' => $this->errors];
            }
        }
        
        // Validate user type
        if (!in_array($data['user_type'], self::USER_TYPES, true)) {
            $this->errors[] = 'Invalid user type';
            return ['success' => false, 'errors' => $this->errors];
        }
        
        // Validate each field
        if (!$this->validator->validateEmail($data['email'])) {
            $this->errors = array_merge($this->errors, $this->validator->getErrors());
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validator->validatePhone($data['phone'])) {
            $this->errors = array_merge($this->errors, $this->validator->getErrors());
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validator->validateUsername($data['username'])) {
            $this->errors = array_merge($this->errors, $this->validator->getErrors());
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validator->validateFullName($data['fullname'])) {
            $this->errors = array_merge($this->errors, $this->validator->getErrors());
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validator->validatePassword($data['password'])) {
            $this->errors = array_merge($this->errors, $this->validator->getErrors());
            return ['success' => false, 'errors' => $this->errors];
        }
        
        // Check uniqueness
        if (!$this->validator->isEmailUnique($data['email'])) {
            $this->errors[] = 'Email already exists';
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validator->isUsernameUnique($data['username'])) {
            $this->errors[] = 'Username already exists';
            return ['success' => false, 'errors' => $this->errors];
        }
        
        if (!$this->validator->isPhoneUnique($data['phone'])) {
            $this->errors[] = 'Phone number already exists';
            return ['success' => false, 'errors' => $this->errors];
        }
        
        // Generate user ID
        $userId = $this->generateUserId($data['user_type']);
        $passwordHash = hashPassword($data['password']);
        
        // Prepare fields for insertion
        $fields = [
            'user_id' => $userId,
            'fullname' => $data['fullname'],
            'username' => $data['username'],
            'user_type' => $data['user_type'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password_hash' => $passwordHash,
            'is_verified' => (int)($data['is_verified'] ?? 0),
            'created_at' => 'NOW()', // Handled separately
        ];
        
        // Add type-specific fields
        if (in_array($data['user_type'], ['customer', 'vendor'], true)) {
            $fields['user_type'] = $data['user_role'];
        }
        
        if ($data['user_type'] === 'vendor') {
            $fields['vendor_status'] = 'pending';
            if (isset($data['wholesale_discount'])) {
                $fields['wholesale_discount'] = (float)$data['wholesale_discount'];
            }
        }
        
        if ($data['user_type'] === 'staff') {
            $fields['employee_id'] = $data['employee_id'] ?? 'EMP' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $fields['hire_date'] = $data['hire_date'] ?? date('Y-m-d');
            $fields['force_password_change'] = 1;
            if (isset($data['branch_id'])) {
                $fields['branch_id'] = (int)$data['branch_id'];
            }
        }
        
        // Build prepared statement dynamically
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = '';
        
        foreach ($fields as $col => $val) {
            if ($col === 'created_at') {
                continue;
            }
            $columns[] = $col;
            $placeholders[] = '?';
            $values[] = $val;
            
            if (is_int($val)) {
                $types .= 'i';
            } elseif (is_float($val)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        
        $sql = "INSERT INTO bakery_users (" . implode(', ', $columns) . ", created_at) 
                VALUES (" . implode(', ', $placeholders) . ", NOW())";
        
        $this->db->beginTransaction();
        
        try {
            $success = $this->db->preparedExecute($sql, $types, $values);
            
            if (!$success) {
                throw new Exception('Failed to create user: ' . ($this->db->getConnection()->error ?? 'Unknown error'));
            }
            
            $newUserId = $this->db->lastInsertId();
            
            // Assign default role based on user_type
            $defaultRoleId = $this->getDefaultRoleId($data['user_type']);
            
            if ($defaultRoleId) {
                // Use system user (0) or current user as assigner for default role
                $assignedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : $newUserId;
                $roleAssigned = $this->assignRole($newUserId, $defaultRoleId, null, $assignedBy);
                
                if (!$roleAssigned) {
                    throw new Exception('User created but failed to assign default role');
                }
            }
            
            // If staff and specific role provided, assign that role (FIXED: don't duplicate)
            if ($data['user_type'] === 'staff' && !empty($data['role_id'])) {
                // Check if we're trying to assign the same role as default
                if ($defaultRoleId !== (int)$data['role_id']) {
                    $roleAssigned = $this->assignRole(
                        $newUserId,
                        (int)$data['role_id'],
                        $data['department_id'] ?? null,
                        $data['assigned_by'] ?? ($_SESSION['user_id'] ?? $newUserId)
                    );
                    
                    if (!$roleAssigned) {
                        throw new Exception('User created but failed to assign specific role');
                    }
                }
            }
            
            logActivity($newUserId, 'User account created', 'user', $newUserId, null, $data);
            $this->db->commit();
            
            $this->loadUser($newUserId);
            
            return [
                'success' => true,
                'user_id' => $newUserId,
                'message' => 'User created successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->errors[] = $e->getMessage();
            return ['success' => false, 'errors' => $this->errors];
        }
    }
    
    /**
     * Update user data
     */
    public function update(int $userId, array $data): array {
        $this->errors = [];
        
        if (!$this->loadUser($userId)) {
            $this->errors[] = 'User not found';
            return ['success' => false, 'errors' => $this->errors];
        }
        
        $updates = [];
        $values = [];
        $types = '';
        $oldData = $this->userData;
        
        $this->db->beginTransaction();
        
        try {
            // Email update
            if (isset($data['email']) && $data['email'] !== $this->userData['email']) {
                if (!$this->validator->validateEmail($data['email'])) {
                    throw new Exception(implode(', ', $this->validator->getErrors()));
                }
                if (!$this->validator->isEmailUnique($data['email'], $userId)) {
                    throw new Exception('Email already exists');
                }
                $updates[] = "email = ?";
                $values[] = $data['email'];
                $types .= 's';
            }
            
            // Phone update
            if (isset($data['phone']) && $data['phone'] !== $this->userData['phone']) {
                if (!$this->validator->validatePhone($data['phone'])) {
                    throw new Exception(implode(', ', $this->validator->getErrors()));
                }
                if (!$this->validator->isPhoneUnique($data['phone'], $userId)) {
                    throw new Exception('Phone number already exists');
                }
                $updates[] = "phone = ?";
                $values[] = $data['phone'];
                $types .= 's';
            }
            
            // Fullname update
            if (isset($data['fullname']) && $data['fullname'] !== $this->userData['fullname']) {
                if (!$this->validator->validateFullName($data['fullname'])) {
                    throw new Exception(implode(', ', $this->validator->getErrors()));
                }
                $updates[] = "fullname = ?";
                $values[] = $data['fullname'];
                $types .= 's';
            }
            
            // Username update
            if (isset($data['username']) && $data['username'] !== $this->userData['username']) {
                if (!$this->validator->validateUsername($data['username'])) {
                    throw new Exception(implode(', ', $this->validator->getErrors()));
                }
                if (!$this->validator->isUsernameUnique($data['username'], $userId)) {
                    throw new Exception('Username already exists');
                }
                $updates[] = "username = ?";
                $values[] = $data['username'];
                $types .= 's';
            }
            
            // Branch update
            if (isset($data['branch_id'])) {
                $branchId = empty($data['branch_id']) ? null : (int)$data['branch_id'];
                $updates[] = "branch_id = ?";
                $values[] = $branchId;
                $types .= 'i';
            }
            
            // Password update
            if (isset($data['password']) && !empty($data['password'])) {
                if (!$this->validator->validatePassword($data['password'])) {
                    throw new Exception(implode(', ', $this->validator->getErrors()));
                }
                $newHash = hashPassword($data['password']);
                $updates[] = "password_hash = ?";
                $updates[] = "last_password_change = NOW()";
                $updates[] = "force_password_change = 0";
                $values[] = $newHash;
                $types .= 's';
            }
            
            // Vendor specific updates
            if ($this->userData['user_type'] === 'vendor') {
                if (isset($data['vendor_status']) && in_array($data['vendor_status'], self::VENDOR_STATUSES, true)) {
                    $updates[] = "vendor_status = ?";
                    $values[] = $data['vendor_status'];
                    $types .= 's';
                }
                if (isset($data['wholesale_discount'])) {
                    $updates[] = "wholesale_discount = ?";
                    $values[] = (float)$data['wholesale_discount'];
                    $types .= 'd';
                }
            }
            
            // Customer specific updates
            if ($this->userData['user_type'] === 'customer' && isset($data['loyalty_points'])) {
                $updates[] = "loyalty_points = ?";
                $values[] = (int)$data['loyalty_points'];
                $types .= 'i';
            }
            
            // Status flags
            if (isset($data['is_active'])) {
                $updates[] = "is_active = ?";
                $values[] = (int)$data['is_active'];
                $types .= 'i';
            }
            
            if (isset($data['is_verified'])) {
                $updates[] = "is_verified = ?";
                $values[] = (int)$data['is_verified'];
                $types .= 'i';
            }
            
            if (empty($updates)) {
                return ['success' => true, 'message' => 'No changes to update'];
            }
            
            $sql = "UPDATE bakery_users SET " . implode(', ', $updates) . " WHERE id = ?";
            $values[] = $userId;
            $types .= 'i';
            
            $success = $this->db->preparedExecute($sql, $types, $values);
            
            if (!$success) {
                throw new Exception('Failed to update user');
            }
            
            logActivity($userId, 'User updated', 'user', $userId, $oldData, $data);
            $this->db->commit();
            $this->loadUser($userId);
            
            return ['success' => true, 'message' => 'User updated successfully'];
            
        } catch (Exception $e) {
            $this->db->rollback();
            $this->errors[] = $e->getMessage();
            return ['success' => false, 'errors' => $this->errors];
        }
    }
    
    /**
     * Generate a unique user_id
     */
    private function generateUserId(string $userType): string {
        $prefix = match ($userType) {
            'customer' => 'FNG-CS-',
            'vendor' => 'FNG-VN-',
            'staff' => 'FNG-ST-',
            default => 'FNG-',
        };
        
        $attempts = 0;
        $maxAttempts = 10;
        
        while ($attempts < $maxAttempts) {
            try {
                $suffix = strtoupper(generateRandomString(6, 'alnum'));
            } catch (Exception $e) {
                $suffix = strtoupper(substr(uniqid(), -6));
            }
            
            $userId = $prefix . $suffix;
            
            $row = $this->db->preparedFetchOne(
                "SELECT id FROM bakery_users WHERE user_id = ?",
                's',
                [$userId]
            );
            
            if (!$row) {
                return $userId;
            }
            
            $attempts++;
        }
        
        return $prefix . time();
    }
    
    /**
     * Get default role ID for a user type
     */
    private function getDefaultRoleId(string $userType): ?int {
        $roleCode = strtoupper($userType);
        
        $row = $this->db->preparedFetchOne(
            "SELECT id FROM roles WHERE role_code = ? LIMIT 1",
            's',
            [$roleCode]
        );
        
        return $row ? (int)$row['id'] : null;
    }
    
    /**
     * Assign a role to a user
     */
    public function assignRole(int $userId, int $roleId, ?int $departmentId = null, ?int $assignedBy = null): bool {
        $userId = (int)$userId;
        $roleId = (int)$roleId;
        $departmentId = $departmentId !== null ? (int)$departmentId : null;
        $assignedBy = $assignedBy ?? ($_SESSION['user_id'] ?? null);
        
        // Validate assigner exists (if provided)
        if ($assignedBy !== null) {
            $assigner = $this->db->preparedFetchOne(
                "SELECT id FROM bakery_users WHERE id = ?",
                'i',
                [$assignedBy]
            );
            if (!$assigner) {
                $this->errors[] = 'Invalid assigner';
                return false;
            }
        }
        
        // Deactivate any existing active role for this user
        $this->db->preparedExecute(
            "UPDATE user_roles SET is_active = 0 WHERE user_id = ? AND is_active = 1",
            'i',
            [$userId]
        );
        
        $success = $this->db->preparedExecute(
            "INSERT INTO user_roles (user_id, role_id, department_id, assigned_by, assigned_date)
             VALUES (?, ?, ?, ?, NOW())",
            'iiii',
            [$userId, $roleId, $departmentId, $assignedBy]
        );
        
        if ($success) {
            logActivity($userId, 'Role assigned', 'role', $roleId);
            return true;
        }
        
        $this->errors[] = 'Failed to assign role';
        return false;
    }
    
    /**
     * Remove a role from a user (deactivate)
     */
    public function removeRole(int $userId, int $roleId, ?int $departmentId = null): bool {
        $userId = (int)$userId;
        $roleId = (int)$roleId;
        $departmentId = $departmentId !== null ? (int)$departmentId : null;
        
        $sql = "UPDATE user_roles SET is_active = 0 
                WHERE user_id = ? AND role_id = ?";
        $params = [$userId, $roleId];
        $types = 'ii';
        
        if ($departmentId !== null) {
            $sql .= " AND (department_id = ? OR (department_id IS NULL AND ? IS NULL))";
            $params[] = $departmentId;
            $params[] = $departmentId;
            $types .= 'ii';
        }
        
        $success = $this->db->preparedExecute($sql, $types, $params);
        
        if ($success) {
            logActivity($userId, 'Role removed', 'role', $roleId);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get roles assigned to a user
     */
    public function getRoles(?int $userId = null): array {
        $userId = $userId ?? $this->userId;
        if (!$userId) {
            return [];
        }
        
        return $this->db->preparedFetchAll(
            "SELECT r.*, ur.department_id, ur.assigned_date, ur.assigned_by,
                    d.dept_name as department_name, d.dept_code,
                    b.branch_name
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             LEFT JOIN departments d ON (r.department_id = d.id OR ur.department_id = d.id)
             LEFT JOIN branches b ON (SELECT branch_id FROM bakery_users WHERE id = ?) = b.id
             WHERE ur.user_id = ? AND ur.is_active = 1
             ORDER BY r.privilege_level DESC",
            'ii',
            [$userId, $userId]
        );
    }
    
    /**
     * Get permissions for a user (flattened)
     */
    public function getPermissionsFlattened(?int $userId = null): array {
        $userId = $userId ?? $this->userId;
        if (!$userId) {
            return [];
        }
        
        $rows = $this->db->preparedFetchAll(
            "SELECT r.privilege_level, r.can_manage_users, r.can_approve_requests,
                    r.can_manage_budget, r.can_view_reports, r.can_manage_system
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.is_active = 1",
            'i',
            [$userId]
        );
        
        $flattened = [
            'can_manage_users' => false,
            'can_approve_requests' => false,
            'can_manage_budget' => false,
            'can_view_reports' => false,
            'can_manage_system' => false,
            'privilege_level' => 0
        ];
        
        foreach ($rows as $perm) {
            $flattened['privilege_level'] = max($flattened['privilege_level'], (int)$perm['privilege_level']);
            
            foreach (['can_manage_users', 'can_approve_requests', 'can_manage_budget', 'can_view_reports', 'can_manage_system'] as $p) {
                if ($perm[$p]) {
                    $flattened[$p] = true;
                }
            }
        }
        
        return $flattened;
    }
    
    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission, ?int $userId = null): bool {
        $permissions = $this->getPermissionsFlattened($userId);
        return $permissions[$permission] ?? false;
    }
    
    /**
     * Get privilege level of user
     */
    public function getPrivilegeLevel(?int $userId = null): int {
        $perms = $this->getPermissionsFlattened($userId);
        return $perms['privilege_level'];
    }
    
    /**
     * Get users by department
     */
    public function getUsersByDepartment(int $departmentId, bool $activeOnly = true): array {
        $departmentId = (int)$departmentId;
        $activeFilter = $activeOnly ? " AND u.is_active = 1" : "";
        
        return $this->db->preparedFetchAll(
            "SELECT DISTINCT u.*, r.role_name
             FROM bakery_users u
             JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
             JOIN roles r ON ur.role_id = r.id
             WHERE (r.department_id = ? OR ur.department_id = ?) {$activeFilter}
             ORDER BY u.fullname",
            'ii',
            [$departmentId, $departmentId]
        );
    }
    
    /**
     * Get users by branch
     */
    public function getUsersByBranch(int $branchId, bool $activeOnly = true): array {
        $branchId = (int)$branchId;
        $activeFilter = $activeOnly ? " AND u.is_active = 1" : "";
        
        return $this->db->preparedFetchAll(
            "SELECT u.*, r.role_name
             FROM bakery_users u
             JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
             JOIN roles r ON ur.role_id = r.id
             WHERE u.branch_id = ? {$activeFilter}
             ORDER BY u.fullname",
            'i',
            [$branchId]
        );
    }
    
    /**
     * Get users by role
     */
    public function getUsersByRole(int $roleId, bool $activeOnly = true): array {
        $roleId = (int)$roleId;
        $activeFilter = $activeOnly ? " AND u.is_active = 1" : "";
        
        return $this->db->preparedFetchAll(
            "SELECT u.*
             FROM bakery_users u
             JOIN user_roles ur ON u.id = ur.user_id
             WHERE ur.role_id = ? AND ur.is_active = 1 {$activeFilter}
             ORDER BY u.fullname",
            'i',
            [$roleId]
        );
    }
    
    /**
     * Search users by term
     */
    public function searchUsers(string $term, ?string $userType = null, int $limit = 50): array {
        $searchTerm = "%{$term}%";
        $limit = max(1, min(100, $limit));
        
        $sql = "SELECT * FROM bakery_users 
                WHERE (fullname LIKE ? OR username LIKE ? OR email LIKE ? OR phone LIKE ? OR user_id LIKE ?)";
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
        $types = 'sssss';
        
        if ($userType !== null && in_array($userType, self::USER_TYPES, true)) {
            $sql .= " AND user_type = ?";
            $params[] = $userType;
            $types .= 's';
        }
        
        $sql .= " ORDER BY fullname LIMIT ?";
        $params[] = $limit;
        $types .= 'i';
        
        return $this->db->preparedFetchAll($sql, $types, $params);
    }
    
    /**
     * Get user statistics
     */
    public function getUserStats(): array {
        $stats = [];
        
        // Total by user_type
        $rows = $this->db->preparedFetchAll(
            "SELECT user_type, COUNT(*) as count FROM bakery_users GROUP BY user_type",
            '',
            []
        );
        
        foreach ($rows as $row) {
            $stats[$row['user_type'] . '_total'] = (int)$row['count'];
        }
        
        // Active users
        $row = $this->db->preparedFetchOne(
            "SELECT COUNT(*) as count FROM bakery_users WHERE is_active = 1",
            '',
            []
        );
        $stats['active_users'] = (int)($row['count'] ?? 0);
        
        // Verified users
        $row = $this->db->preparedFetchOne(
            "SELECT COUNT(*) as count FROM bakery_users WHERE is_verified = 1",
            '',
            []
        );
        $stats['verified_users'] = (int)($row['count'] ?? 0);
        
        // New today
        $row = $this->db->preparedFetchOne(
            "SELECT COUNT(*) as count FROM bakery_users WHERE DATE(created_at) = CURDATE()",
            '',
            []
        );
        $stats['new_today'] = (int)($row['count'] ?? 0);
        
        // New this week
        $row = $this->db->preparedFetchOne(
            "SELECT COUNT(*) as count FROM bakery_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '',
            []
        );
        $stats['new_week'] = (int)($row['count'] ?? 0);
        
        // Vendor status breakdown
        $rows = $this->db->preparedFetchAll(
            "SELECT vendor_status, COUNT(*) as count FROM bakery_users WHERE user_type = 'vendor' GROUP BY vendor_status",
            '',
            []
        );
        
        foreach ($rows as $row) {
            $stats['vendor_' . $row['vendor_status']] = (int)$row['count'];
        }
        
        // Locked accounts
        $row = $this->db->preparedFetchOne(
            "SELECT COUNT(*) as count FROM bakery_users WHERE account_locked_until > NOW()",
            '',
            []
        );
        $stats['locked_accounts'] = (int)($row['count'] ?? 0);
        
        return $stats;
    }
    
    /**
     * Delete or deactivate user
     */
    public function deleteUser(int $userId, bool $hardDelete = false): bool {
        if (!$this->loadUser($userId)) {
            $this->errors[] = 'User not found';
            return false;
        }
        
        if ($hardDelete) {
            $success = $this->db->preparedExecute(
                "DELETE FROM bakery_users WHERE id = ?",
                'i',
                [$userId]
            );
        } else {
            $success = $this->db->preparedExecute(
                "UPDATE bakery_users SET is_active = 0, account_locked_until = NULL WHERE id = ?",
                'i',
                [$userId]
            );
        }
        
        if ($success) {
            logActivity($userId, 'User ' . ($hardDelete ? 'deleted' : 'deactivated'), 'user', $userId);
            return true;
        }
        
        $this->errors[] = 'Failed to ' . ($hardDelete ? 'delete' : 'deactivate') . ' user';
        return false;
    }
    
    /**
     * Reactivate user
     */
    public function reactivateUser(int $userId): bool {
        $success = $this->db->preparedExecute(
            "UPDATE bakery_users SET is_active = 1 WHERE id = ?",
            'i',
            [$userId]
        );
        
        if ($success) {
            logActivity($userId, 'User reactivated', 'user', $userId);
            return true;
        }
        
        $this->errors[] = 'Failed to reactivate user';
        return false;
    }
    
    /**
     * Get department heads (users with can_manage_users permission)
     */
    public function getDepartmentHeads(): array {
        return $this->db->preparedFetchAll(
            "SELECT u.*, r.role_name
             FROM bakery_users u
             JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
             JOIN roles r ON ur.role_id = r.id
             WHERE r.can_manage_users = 1
             ORDER BY u.fullname",
            '',
            []
        );
    }
    
    /**
     * Get direct reports (users reporting to this user)
     */
    public function getDirectReports(?int $userId = null): array {
        $userId = $userId ?? $this->userId;
        if (!$userId) {
            return [];
        }
        
        // Get users where this user is the manager (based on role hierarchy)
        return $this->db->preparedFetchAll(
            "SELECT u.*, r.role_name, d.dept_name
             FROM bakery_users u
             JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = 1
             JOIN roles r ON ur.role_id = r.id
             LEFT JOIN departments d ON r.department_id = d.id
             WHERE ur.role_id IN (
                 SELECT id FROM roles WHERE privilege_level < (
                     SELECT privilege_level FROM roles r2
                     JOIN user_roles ur2 ON r2.id = ur2.role_id
                     WHERE ur2.user_id = ? AND ur2.is_active = 1
                     ORDER BY r2.privilege_level DESC LIMIT 1
                 )
             ) AND u.id != ?
             ORDER BY u.fullname",
            'ii',
            [$userId, $userId]
        );
    }
    
    /**
     * Check if user is online (session active within last 15 minutes)
     */
    public function isOnline(?int $userId = null): bool {
        $userId = $userId ?? $this->userId;
        if (!$userId) {
            return false;
        }
        
        $row = $this->db->preparedFetchOne(
            "SELECT id FROM user_sessions 
             WHERE user_id = ? AND is_active = 1 
             AND last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
             LIMIT 1",
            'i',
            [$userId]
        );
        
        return !empty($row);
    }
    
    /**
     * Get last login time
     */
    public function getLastLogin(?int $userId = null): ?string {
        $userId = $userId ?? $this->userId;
        if (!$userId) {
            return null;
        }
        
        $row = $this->db->preparedFetchOne(
            "SELECT login_time FROM user_sessions 
             WHERE user_id = ? 
             ORDER BY login_time DESC 
             LIMIT 1",
            'i',
            [$userId]
        );
        
        return $row['login_time'] ?? null;
    }
    
    /**
     * Update loyalty points
     */
    public function updateLoyaltyPoints(int $userId, int $points, string $operation = 'add'): bool {
        $userId = (int)$userId;
        $points = (int)$points;
        
        if ($points === 0) {
            return true;
        }
        
        $sql = match ($operation) {
            'add' => "UPDATE bakery_users SET loyalty_points = loyalty_points + ? WHERE id = ? AND user_type = 'customer'",
            'subtract' => "UPDATE bakery_users SET loyalty_points = GREATEST(0, loyalty_points - ?) WHERE id = ? AND user_type = 'customer'",
            'set' => "UPDATE bakery_users SET loyalty_points = ? WHERE id = ? AND user_type = 'customer'",
            default => null,
        };
        
        if ($sql === null) {
            return false;
        }
        
        $success = $this->db->preparedExecute($sql, 'ii', [$points, $userId]);
        
        if ($success && $this->db->affectedRows() > 0) {
            logActivity($userId, "Loyalty points updated: {$operation} {$points}", 'user', $userId);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get audit trail for user
     */
    public function getAuditTrail(?int $userId = null, int $limit = 50): array {
        $userId = $userId ?? $this->userId;
        if (!$userId) {
            return [];
        }
        
        $limit = max(1, min(500, $limit));
        
        return $this->db->preparedFetchAll(
            "SELECT * FROM audit_trail WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            'ii',
            [$userId, $limit]
        );
    }
}