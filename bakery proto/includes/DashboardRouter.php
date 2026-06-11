<?php
// includes/DashboardRouter.php - Department-first routing with database mappings
// Version: 3.0 (ORIGINAL WORKING - DO NOT MODIFY PATH LOGIC)

require_once dirname(__FILE__) . '/../conn.php';
require_once dirname(__FILE__) . '/User.php';

class DashboardRouter {
    private $config;
    private $base_path;
    private $doc_root;
    private $db;
    
    private $deptCache = array();
    private $roleCache = array();
    private $branchCache = array();
    private $permissionCache = array();
    private $adminCache = array();
    private $mappingsCache = null;
    
    private $defaultCustomerDashboard = 'dashboards/customer-dashboard.php';
    private $defaultStaffDashboard = 'dashboards/staff/general-dashboard.php';
    private $defaultAdminDashboard = 'dashboards/staff/admin-dashboard.php';
    
    public function __construct($config = array()) {
        $this->config = $config;
        $this->db = Database::getInstance();
        $this->detectBasePath();
        $this->detectDocumentRoot();
    }
    
    private function detectBasePath() {
        $script_name = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $script_dir = dirname($script_name);
        $this->base_path = rtrim($script_dir, '/');
        
        if (strpos($this->base_path, '/bakery proto') !== false) {
            $this->base_path = '/bakery proto';
        } else {
            $this->base_path = '';
        }
        error_log("Router: Base path detected as: " . ($this->base_path ?: '(root)'));
    }
    
    private function detectDocumentRoot() {
        if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $this->doc_root = $_SERVER['DOCUMENT_ROOT'];
        } else {
            $this->doc_root = realpath(dirname(__FILE__) . '/../');
        }
        $this->doc_root = rtrim(str_replace('\\', '/', $this->doc_root), '/');
        error_log("Router: Document root: " . $this->doc_root);
    }
    
    private function sanitizeFilename($filename) {
        $filename = str_replace(array('/', '\\', '..'), '', $filename);
        $filename = preg_replace('/[^a-z0-9]+/', '-', strtolower($filename));
        return trim($filename, '-');
    }
    
    private function dashboardExists($relative_path) {
        if (strpos($relative_path, '..') !== false || strpos($relative_path, './') !== false) {
            error_log("Router: Invalid path characters: $relative_path");
            return false;
        }
        
        $relative_path = ltrim($relative_path, '/');
        $relative_path = preg_replace('#/+#', '/', $relative_path);
        
        $base = $this->base_path ? rtrim($this->base_path, '/') : '';
        $full_path = $this->doc_root . $base . '/' . $relative_path;
        $full_path = str_replace('\\', '/', $full_path);
        $full_path = preg_replace('#/+#', '/', $full_path);
        
        error_log("Router: Checking existence: $full_path");
        
        if (strpos($full_path, $this->doc_root) !== 0) {
            error_log("Router: Path outside document root: $full_path");
            return false;
        }
        
        $exists = file_exists($full_path) && is_file($full_path);
        error_log("Router: File " . ($exists ? "exists" : "does NOT exist") . " at: $full_path");
        
        return $exists;
    }
    
    private function getDashboardMappings() {
        if ($this->mappingsCache !== null) {
            return $this->mappingsCache;
        }
        
        $rows = $this->db->preparedFetchAll(
            "SELECT department_code, department_name, dashboard_file, dashboard_name, priority, is_active
             FROM dashboard_mappings 
             WHERE is_active = 1 
             ORDER BY priority DESC, department_code ASC",
            '',
            array()
        );
        
        $mappings = array(
            'by_code' => array(),
            'by_name' => array(),
            'all' => array()
        );
        
        foreach ($rows as $row) {
            $deptCode = strtoupper($row['department_code']);
            $deptName = $row['department_name'] ? strtolower($row['department_name']) : null;
            
            $mappings['by_code'][$deptCode] = $row;
            
            if ($deptName) {
                $mappings['by_name'][$deptName] = $row;
            }
            
            $mappings['all'][] = $row;
        }
        
        $this->mappingsCache = $mappings;
        error_log("Router: Loaded " . count($mappings['all']) . " dashboard mappings");
        
        return $mappings;
    }
    
    private function getDashboardFromMappings($deptCode, $deptName = null) {
        $mappings = $this->getDashboardMappings();
        $deptCodeUpper = strtoupper($deptCode);
        
        if (isset($mappings['by_code'][$deptCodeUpper])) {
            return $mappings['by_code'][$deptCodeUpper]['dashboard_file'];
        }
        
        if ($deptName) {
            $deptNameLower = strtolower($deptName);
            if (isset($mappings['by_name'][$deptNameLower])) {
                return $mappings['by_name'][$deptNameLower]['dashboard_file'];
            }
        }
        
        return null;
    }
    
    private function getDashboardNameFromMappings($deptCode, $deptName = null) {
        $mappings = $this->getDashboardMappings();
        $deptCodeUpper = strtoupper($deptCode);
        
        if (isset($mappings['by_code'][$deptCodeUpper])) {
            return $mappings['by_code'][$deptCodeUpper]['dashboard_name'];
        }
        
        if ($deptName) {
            $deptNameLower = strtolower($deptName);
            if (isset($mappings['by_name'][$deptNameLower])) {
                return $mappings['by_name'][$deptNameLower]['dashboard_name'];
            }
        }
        
        return 'General Dashboard';
    }
    
    public function getUserDepartment($userId) {
        $userId = (int)$userId;
        
        if (isset($this->deptCache[$userId])) {
            return $this->deptCache[$userId];
        }
        
        $result = $this->db->preparedFetchOne(
            "SELECT d.id, d.dept_code, d.dept_name, d.dept_head_id
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             LEFT JOIN departments d ON (r.department_id = d.id OR ur.department_id = d.id)
             WHERE ur.user_id = ? AND ur.is_active = 1
             ORDER BY 
                 CASE WHEN r.department_id IS NOT NULL THEN 0 ELSE 1 END ASC,
                 r.privilege_level DESC
             LIMIT 1",
            'i',
            array($userId)
        );
        
        $this->deptCache[$userId] = $result ?: null;
        return $this->deptCache[$userId];
    }
    
    public function getUserRoleDetails($userId) {
        $userId = (int)$userId;
        
        if (isset($this->roleCache[$userId])) {
            return $this->roleCache[$userId];
        }
        
        $result = $this->db->preparedFetchOne(
            "SELECT r.*, ur.department_id as assigned_department, ur.assigned_date,
                    d.dept_name, d.dept_code,
                    b.id as branch_id, b.branch_code, b.branch_name
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             LEFT JOIN departments d ON (r.department_id = d.id OR ur.department_id = d.id)
             LEFT JOIN bakery_users u ON ur.user_id = u.id
             LEFT JOIN branches b ON u.branch_id = b.id
             WHERE ur.user_id = ? AND ur.is_active = 1
             ORDER BY r.privilege_level DESC
             LIMIT 1",
            'i',
            array($userId)
        );
        
        $this->roleCache[$userId] = $result ?: null;
        return $this->roleCache[$userId];
    }
    
    public function isUserAdmin($userId) {
        $userId = (int)$userId;
        
        if (isset($this->adminCache[$userId])) {
            return $this->adminCache[$userId];
        }
        
        $row = $this->db->preparedFetchOne(
            "SELECT r.can_manage_system
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.is_active = 1
             AND r.can_manage_system = 1
             LIMIT 1",
            'i',
            array($userId)
        );
        
        $this->adminCache[$userId] = !empty($row);
        return $this->adminCache[$userId];
    }
    
    public function getUserBranchContext($userId) {
        $userId = (int)$userId;
        
        if (isset($this->branchCache[$userId])) {
            return $this->branchCache[$userId];
        }
        
        $result = $this->db->preparedFetchOne(
            "SELECT b.* FROM bakery_users u
             LEFT JOIN branches b ON u.branch_id = b.id
             WHERE u.id = ?",
            'i',
            array($userId)
        );
        
        $this->branchCache[$userId] = $result ?: null;
        return $this->branchCache[$userId];
    }
    
    public function isDepartmentHead($userId) {
        $userId = (int)$userId;
        $row = $this->db->preparedFetchOne(
            "SELECT r.can_manage_users
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.is_active = 1
             AND r.can_manage_users = 1
             LIMIT 1",
            'i',
            array($userId)
        );
        return !empty($row);
    }
    
    public function isBranchManager($userId) {
        $userId = (int)$userId;
        $row = $this->db->preparedFetchOne(
            "SELECT r.can_approve_requests
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.is_active = 1
             AND r.privilege_level >= 50 AND r.can_approve_requests = 1
             LIMIT 1",
            'i',
            array($userId)
        );
        return !empty($row);
    }
    
    public function getDashboard($user) {
        if (!is_array($user)) {
            error_log("DashboardRouter: Invalid user data");
            return $this->defaultCustomerDashboard;
        }
        
        $user_id = isset($user['id']) ? (int)$user['id'] : 0;
        $user_type = isset($user['user_type']) ? $user['user_type'] : '';
        
        error_log("Router: getDashboard called for user_id=$user_id, user_type=$user_type");
        
        if ($user_type === 'customer' || $user_type === 'vendor') {
            return $this->defaultCustomerDashboard;
        }
        
        if ($user_type === 'staff' && $user_id > 0) {
            $path = $this->getStaffDashboardPath($user_id);
            $path = preg_replace('#/+#', '/', $path);
            return $path;
        }
        
        return $this->defaultCustomerDashboard;
    }
    
    private function getStaffDashboardPath($userId) {
        error_log("Router: Getting staff dashboard path for user $userId");
        
        $department = $this->getUserDepartment($userId);
        
        if ($department) {
            $dept_code = isset($department['dept_code']) ? $department['dept_code'] : '';
            $dept_name = isset($department['dept_name']) ? $department['dept_name'] : '';
            
            error_log("Router: User $userId is in department: $dept_code ($dept_name)");
            
            $dashboardFile = $this->getDashboardFromMappings($dept_code, $dept_name);
            
            if ($dashboardFile && $this->dashboardExists($dashboardFile)) {
                error_log("Router: Using mapping: $dashboardFile");
                return $dashboardFile;
            }
            
            $fallback = "dashboards/staff/" . strtolower($dept_code) . "-dashboard.php";
            if ($this->dashboardExists($fallback)) {
                error_log("Router: Using fallback: $fallback");
                return $fallback;
            }
        }
        
        if ($this->isUserAdmin($userId)) {
            error_log("Router: User $userId is admin - admin dashboard");
            return $this->defaultAdminDashboard;
        }
        
        error_log("Router: Using general staff dashboard");
        return $this->defaultStaffDashboard;
    }
    
    public function getUserDisplayInfo($userId) {
        $userId = (int)$userId;
        
        $user = $this->db->preparedFetchOne(
            "SELECT id, fullname, username, user_type, email, phone, 
                    loyalty_points, is_verified, created_at
             FROM bakery_users WHERE id = ?",
            'i',
            array($userId)
        );
        
        if (!$user) {
            $user = array();
        }
        
        $roleDetails = $this->getUserRoleDetails($userId);
        $department = $this->getUserDepartment($userId);
        $branch = $this->getUserBranchContext($userId);
        
        $isDeptHead = $this->isDepartmentHead($userId);
        $isBranchManager = $this->isBranchManager($userId);
        $isAdmin = $this->isUserAdmin($userId);
        
        $dashboardName = 'Staff Dashboard';
        if ($department && !empty($department['dept_code'])) {
            $dashboardName = $this->getDashboardNameFromMappings(
                $department['dept_code'],
                isset($department['dept_name']) ? $department['dept_name'] : null
            );
        } elseif ($isAdmin) {
            $dashboardName = 'System Administration';
        }
        
        // Use actual user_type from database, not hardcoded 'staff'
        $userType = isset($user['user_type']) ? $user['user_type'] : 'staff';
        $dashboardPath = $this->getDashboard(array('id' => $userId, 'user_type' => $userType));
        
        return array(
            'user' => $user,
            'department' => array(
                'id' => isset($department['id']) ? $department['id'] : null,
                'code' => isset($department['dept_code']) ? $department['dept_code'] : null,
                'name' => isset($department['dept_name']) ? $department['dept_name'] : 'Administration',
                'is_head' => $isDeptHead
            ),
            'branch' => array(
                'id' => isset($branch['id']) ? $branch['id'] : null,
                'code' => isset($branch['branch_code']) ? $branch['branch_code'] : 'HQ',
                'name' => isset($branch['branch_name']) ? $branch['branch_name'] : 'Headquarters',
                'location' => isset($branch['branch_location']) ? $branch['branch_location'] : null,
                'is_manager' => $isBranchManager
            ),
            'position' => array(
                'title' => isset($roleDetails['role_name']) ? $roleDetails['role_name'] : null,
                'code' => isset($roleDetails['role_code']) ? $roleDetails['role_code'] : null,
                'role_name' => isset($roleDetails['role_name']) ? $roleDetails['role_name'] : null,
                'role_code' => isset($roleDetails['role_code']) ? $roleDetails['role_code'] : null,
                'is_manager' => isset($roleDetails['can_approve_requests']) && $roleDetails['can_approve_requests'] == 1,
                'privilege_level' => isset($roleDetails['privilege_level']) ? (int)$roleDetails['privilege_level'] : 1,
                'assigned_date' => isset($roleDetails['assigned_date']) ? $roleDetails['assigned_date'] : null,
                'permissions' => array(
                    'can_manage_users' => isset($roleDetails['can_manage_users']) && $roleDetails['can_manage_users'],
                    'can_approve_requests' => isset($roleDetails['can_approve_requests']) && $roleDetails['can_approve_requests'],
                    'can_manage_budget' => isset($roleDetails['can_manage_budget']) && $roleDetails['can_manage_budget'],
                    'can_view_reports' => isset($roleDetails['can_view_reports']) && $roleDetails['can_view_reports'],
                    'can_manage_system' => isset($roleDetails['can_manage_system']) && $roleDetails['can_manage_system']
                )
            ),
            'dashboard' => array(
                'path' => $dashboardPath,
                'name' => $dashboardName
            ),
            'is_admin' => $isAdmin,
            'audit_context' => array(
                'department_code' => isset($department['dept_code']) ? $department['dept_code'] : 'ADMIN',
                'branch_code' => isset($branch['branch_code']) ? $branch['branch_code'] : 'HQ',
                'position_code' => isset($roleDetails['role_code']) ? $roleDetails['role_code'] : 'STAFF',
                'is_manager' => (isset($roleDetails['can_approve_requests']) && $roleDetails['can_approve_requests'] == 1) || $isDeptHead || $isBranchManager || $isAdmin
            )
        );
    }
    
    public function clearCache($userId = null) {
        if ($userId !== null) {
            unset($this->deptCache[$userId]);
            unset($this->roleCache[$userId]);
            unset($this->branchCache[$userId]);
            unset($this->permissionCache[$userId]);
            unset($this->adminCache[$userId]);
        } else {
            $this->deptCache = array();
            $this->roleCache = array();
            $this->branchCache = array();
            $this->permissionCache = array();
            $this->adminCache = array();
            $this->mappingsCache = null;
        }
    }
    
    public function refreshMappings() {
        $this->mappingsCache = null;
        return $this->getDashboardMappings();
    }
}

// =====================================================
// GLOBAL HELPER FUNCTIONS
// =====================================================

if (!function_exists('get_user_dashboard')) {
    function get_user_dashboard($user_id = null) {
        if ($user_id === null && isset($_SESSION['user_id'])) {
            $user_id = (int)$_SESSION['user_id'];
        }
        
        if (!$user_id) {
            return '/home.html';
        }
        
        static $router = null;
        if ($router === null) {
            $router = new DashboardRouter();
        }
        
        $user = array(
            'id' => $user_id,
            'user_type' => isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'staff'
        );
        
        return $router->getDashboard($user);
    }
}

if (!function_exists('get_current_user_display')) {
    function get_current_user_display() {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        static $router = null;
        if ($router === null) {
            $router = new DashboardRouter();
        }
        
        return $router->getUserDisplayInfo((int)$_SESSION['user_id']);
    }
}

if (!function_exists('is_current_user_admin')) {
    function is_current_user_admin() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        static $router = null;
        if ($router === null) {
            $router = new DashboardRouter();
        }
        
        return $router->isUserAdmin((int)$_SESSION['user_id']);
    }
}