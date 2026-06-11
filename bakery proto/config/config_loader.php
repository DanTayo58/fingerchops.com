<?php
// config/config_loader.php - Configuration loader (Single source of truth)
// Version: 4.0 (PHP 8.3+ with MySQLi, production-ready)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

// =====================================================
// ENVIRONMENT DETECTION (via Apache SetEnv or .env)
// =====================================================
$env = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production');
define('APP_ENV', $env);
define('DEVELOPMENT_MODE', APP_ENV === 'development');
define('PRODUCTION_MODE', APP_ENV === 'production');

// =====================================================
// ERROR REPORTING (Production-safe)
// =====================================================
if (DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');

// Ensure logs directory exists
$logsDir = dirname(__DIR__) . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

/**
 * ConfigLoader - Central configuration management with caching
 * 
 * @package Fingerchops
 * @version 4.0
 */
class ConfigLoader
{
    private static ?self $instance = null;
    
    private array $fileConfig = [];
    private array $dbConfig = [];
    private array $merged = [];
    
    private ?mysqli $db = null;
    private bool $dbAvailable = false;
    
    private int $cacheTimeout = 3600; // 1 hour
    private string $cacheFile;
    
    private const REQUIRED_CONFIGS = [
        'app_name' => 'Fingerchops Ventures',
        'timezone' => 'Africa/Lagos',
        'session_lifetime' => 28800,
        'password_min_length' => 8,
    ];
    
    private function __construct()
    {
        $this->cacheFile = dirname(__DIR__) . '/cache/config_cache.php';
        
        // Try to load from cache first
        if (!$this->loadFromCache()) {
            $this->loadFileConfig();
            $this->initDatabase();
            $this->loadDatabaseConfig();
            $this->mergeConfigs();
            $this->validateConfig();
            $this->setTimezone();
            $this->saveToCache();
        }
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load configuration from cache file if valid
     */
    private function loadFromCache(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }
        
        $cacheData = include $this->cacheFile;
        
        if (!is_array($cacheData) || !isset($cacheData['timestamp'])) {
            return false;
        }
        
        // Check if cache is still fresh
        if (time() - $cacheData['timestamp'] >= $this->cacheTimeout) {
            return false;
        }
        
        $this->merged = $cacheData['config'] ?? [];
        $this->fileConfig = $cacheData['fileConfig'] ?? [];
        $this->dbConfig = $cacheData['dbConfig'] ?? [];
        
        return !empty($this->merged);
    }
    
    /**
     * Save current configuration to cache
     */
    private function saveToCache(): void
    {
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cacheData = [
            'timestamp' => time(),
            'config' => $this->merged,
            'fileConfig' => $this->fileConfig,
            'dbConfig' => $this->dbConfig,
        ];
        
        $content = "<?php\n// Configuration cache - Generated: " . date('Y-m-d H:i:s') . "\nreturn " . var_export($cacheData, true) . ";\n";
        file_put_contents($this->cacheFile, $content, LOCK_EX);
    }
    
    /**
     * Load configuration from roles.php file
     */
    private function loadFileConfig(): void
    {
        $configPath = dirname(__DIR__) . '/config/roles.php';
        
        if (!file_exists($configPath)) {
            error_log("ConfigLoader: roles.php not found at {$configPath}");
            $this->fileConfig = [];
            return;
        }
        
        $fileConfig = include $configPath;
        
        if (!is_array($fileConfig)) {
            error_log("ConfigLoader: roles.php did not return a valid array");
            $this->fileConfig = [];
            return;
        }
        
        $this->fileConfig = $fileConfig;
    }
    
    /**
     * Initialize database connection using MySQLi
     */
    private function initDatabase(): void
    {
        // Load database configuration from environment or fallback to constants
        $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
        $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
        $pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
        $name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'fingerchops_bakery');
        $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3306);
        
        try {
            $this->db = new mysqli($host, $user, $pass, $name, (int)$port);
            
            if ($this->db->connect_error) {
                throw new Exception($this->db->connect_error);
            }
            
            $this->db->set_charset('utf8mb4');
            $this->dbAvailable = true;
        } catch (Exception $e) {
            error_log("ConfigLoader: Database connection failed - " . $e->getMessage());
            $this->dbAvailable = false;
            $this->db = null;
        }
    }
    
    /**
     * Load configuration from system_config table
     */
    private function loadDatabaseConfig(): void
    {
        if (!$this->dbAvailable || $this->db === null) {
            $this->dbConfig = [];
            return;
        }
        
        $result = $this->db->query("SELECT config_key, config_value, config_type FROM system_config");
        
        if (!$result) {
            error_log("ConfigLoader: Failed to load database config - " . $this->db->error);
            $this->dbConfig = [];
            return;
        }
        
        $this->dbConfig = [];
        while ($row = $result->fetch_assoc()) {
            $value = $row['config_value'];
            
            $value = match ($row['config_type']) {
                'integer' => (int)$value,
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'json' => json_decode($value, true) ?? [],
                default => $value,
            };
            
            $this->dbConfig[$row['config_key']] = $value;
        }
        $result->free();
    }
    
    /**
     * Merge file and database configurations
     */
    private function mergeConfigs(): void
    {
        $this->merged = $this->fileConfig;
        
        // Override with database values
        foreach ($this->dbConfig as $key => $value) {
            if (str_contains($key, '.')) {
                $parts = explode('.', $key);
                $this->setNestedValue($this->merged, $parts, $value);
            } else {
                $this->merged[$key] = $value;
            }
        }
        
        // Build settings array for backward compatibility
        if (!isset($this->merged['settings']) || !is_array($this->merged['settings'])) {
            $this->merged['settings'] = [];
        }
        
        // Populate settings from non-structural keys
        $structuralKeys = ['departments', 'roles', 'department_roles', 'permission_flags', 'navigation', 'dashboard_mapping'];
        foreach ($this->dbConfig as $key => $value) {
            if (!in_array($key, $structuralKeys, true) && !str_contains($key, '.')) {
                $this->merged['settings'][$key] = $value;
            }
        }
        
        // Apply department overrides (colors, icons)
        if (isset($this->dbConfig['department_colors']) && is_array($this->dbConfig['department_colors'])) {
            foreach ($this->dbConfig['department_colors'] as $code => $color) {
                if (isset($this->merged['departments'][$code])) {
                    $this->merged['departments'][$code]['color'] = $color;
                }
            }
        }
        
        if (isset($this->dbConfig['department_icons']) && is_array($this->dbConfig['department_icons'])) {
            foreach ($this->dbConfig['department_icons'] as $code => $icon) {
                if (isset($this->merged['departments'][$code])) {
                    $this->merged['departments'][$code]['icon'] = $icon;
                }
            }
        }
        
        // Apply role badge overrides
        if (isset($this->dbConfig['role_badges']) && is_array($this->dbConfig['role_badges'])) {
            foreach ($this->dbConfig['role_badges'] as $role => $badge) {
                if (isset($this->merged['roles'][$role])) {
                    $this->merged['roles'][$role]['badge'] = $badge;
                }
            }
        }
    }
    
    /**
     * Set nested array value using dot notation parts
     */
    private function setNestedValue(array &$array, array $parts, mixed $value): void
    {
        $current = &$array;
        $last = array_pop($parts);
        
        foreach ($parts as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        
        $current[$last] = $value;
    }
    
    /**
     * Validate required configuration keys
     */
    private function validateConfig(): void
    {
        foreach (self::REQUIRED_CONFIGS as $key => $default) {
            $found = isset($this->merged[$key]) || isset($this->merged['settings'][$key]);
            
            if (!$found) {
                error_log("ConfigLoader: Missing required config: {$key} - using default");
                $this->merged['settings'][$key] = $default;
            }
        }
    }
    
    /**
     * Set PHP timezone from configuration
     */
    private function setTimezone(): void
    {
        $timezone = $this->merged['settings']['timezone'] ?? $this->merged['timezone'] ?? 'Africa/Lagos';
        
        if (!date_default_timezone_set($timezone)) {
            error_log("ConfigLoader: Invalid timezone '{$timezone}', using Africa/Lagos");
            date_default_timezone_set('Africa/Lagos');
        }
    }
    
    // =====================================================
    // PUBLIC API METHODS
    // =====================================================
    
    /**
     * Get all configuration as array
     */
    public function getAll(): array
    {
        return $this->merged;
    }
    
    /**
     * Get configuration value by key (supports dot notation)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $current = $this->merged;
            
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    return $default;
                }
                $current = $current[$part];
            }
            
            return $current;
        }
        
        return $this->merged[$key] ?? $default;
    }
    
    /**
     * Get department configuration by code
     */
    public function getDepartment(string $code): ?array
    {
        return $this->merged['departments'][$code] ?? null;
    }
    
    /**
     * Get all departments
     */
    public function getDepartments(): array
    {
        return $this->merged['departments'] ?? [];
    }
    
    /**
     * Get role configuration by role code
     */
    public function getRole(string $roleCode): ?array
    {
        return $this->merged['roles'][$roleCode] ?? null;
    }
    
    /**
     * Get all roles
     */
    public function getRoles(): array
    {
        return $this->merged['roles'] ?? [];
    }
    
    /**
     * Get dashboard path based on user type, role, and department
     */
    public function getDashboard(string $userType, ?string $role = null, ?string $department = null): string
    {
        $mapping = $this->merged['dashboard_mapping'] ?? [];
        
        // Department-specific dashboard
        if ($department !== null) {
            $deptKey = 'department_' . strtolower($department);
            if (isset($mapping[$deptKey])) {
                return $mapping[$deptKey];
            }
        }
        
        // Role-specific dashboard
        if ($role !== null && isset($mapping[$role])) {
            return $mapping[$role];
        }
        
        // User type dashboard
        if (isset($mapping[$userType])) {
            return $mapping[$userType];
        }
        
        // Default fallbacks
        if ($userType === 'staff') {
            return $mapping['default_staff'] ?? '/dashboards/staff/general-dashboard.php';
        }
        
        return $mapping['default'] ?? '/dashboards/customer-dashboard.php';
    }
    
    /**
     * Get navigation menu for a role
     */
    public function getNavigation(string $role): array
    {
        return $this->merged['navigation'][$role] ?? [];
    }
    
    /**
     * Get a specific setting value
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $settings = $this->merged['settings'] ?? [];
        return $settings[$key] ?? $default;
    }
    
    /**
     * Check if a role has a specific permission
     */
    public function hasPermission(string $roleCode, string $permission): bool
    {
        $role = $this->getRole($roleCode);
        
        if (!$role) {
            return false;
        }
        
        // Check explicit permission flag
        if (isset($role['permissions'][$permission])) {
            return (bool)$role['permissions'][$permission];
        }
        
        // Fallback to privilege level
        $level = $role['level'] ?? 1;
        
        return match ($permission) {
            'can_manage_users' => $level >= 80,
            'can_manage_budget' => $level >= 80,
            'can_approve_requests' => $level >= 60,
            'can_view_reports' => $level >= 50,
            'can_manage_system' => $level >= 100,
            default => false,
        };
    }
    
    /**
     * Get department color
     */
    public function getDepartmentColor(string $deptCode): string
    {
        $dept = $this->getDepartment($deptCode);
        return $dept['color'] ?? '#6c757d';
    }
    
    /**
     * Get department icon
     */
    public function getDepartmentIcon(string $deptCode): string
    {
        $dept = $this->getDepartment($deptCode);
        return $dept['icon'] ?? 'building';
    }
    
    /**
     * Get role badge style
     */
    public function getRoleBadge(string $roleCode): string
    {
        $role = $this->getRole($roleCode);
        return $role['badge'] ?? 'secondary';
    }
    
    /**
     * Set a configuration value (saves to database)
     */
    public function set(string $key, mixed $value, string $type = 'string', string $description = ''): bool
    {
        if (!$this->dbAvailable || $this->db === null) {
            return false;
        }
        
        // Auto-detect type if not specified
        if ($type === 'string') {
            if (is_bool($value)) {
                $type = 'boolean';
                $value = $value ? 'true' : 'false';
            } elseif (is_int($value)) {
                $type = 'integer';
            } elseif (is_array($value)) {
                $type = 'json';
                $value = json_encode($value);
            }
        }
        
        $valueStr = is_array($value) ? json_encode($value) : (string)$value;
        
        // Escape values for MySQLi
        $keyEscaped = $this->db->real_escape_string($key);
        $valueEscaped = $this->db->real_escape_string($valueStr);
        $typeEscaped = $this->db->real_escape_string($type);
        $descEscaped = $this->db->real_escape_string($description);
        
        // Check if key exists
        $result = $this->db->query("SELECT id FROM system_config WHERE config_key = '{$keyEscaped}'");
        
        if ($result && $result->num_rows > 0) {
            $sql = "UPDATE system_config 
                    SET config_value = '{$valueEscaped}', config_type = '{$typeEscaped}', 
                        description = '{$descEscaped}', updated_at = NOW() 
                    WHERE config_key = '{$keyEscaped}'";
            $success = $this->db->query($sql);
        } else {
            $sql = "INSERT INTO system_config (config_key, config_value, config_type, description) 
                    VALUES ('{$keyEscaped}', '{$valueEscaped}', '{$typeEscaped}', '{$descEscaped}')";
            $success = $this->db->query($sql);
        }
        
        if ($success) {
            // Clear cache to force reload
            $this->clearCache();
            return true;
        }
        
        error_log("ConfigLoader: Failed to set config {$key} - " . $this->db->error);
        return false;
    }
    
    /**
     * Clear configuration cache
     */
    public function clearCache(): void
    {
        $this->dbConfig = [];
        
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
        
        $this->loadDatabaseConfig();
        $this->mergeConfigs();
    }
    
    /**
     * Check if database connection is available
     */
    public function isDbConnected(): bool
    {
        return $this->dbAvailable && $this->db !== null && $this->db->ping();
    }
    
    /**
     * Get the underlying MySQLi connection (for other classes)
     */
    public function getMysqli(): ?mysqli
    {
        return $this->db;
    }
    
    /**
     * Set cache timeout in seconds
     */
    public function setCacheTimeout(int $seconds): void
    {
        $this->cacheTimeout = max(60, $seconds);
    }
}

// =====================================================
// GLOBAL HELPER FUNCTIONS
// =====================================================

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return ConfigLoader::getInstance()->get($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return ConfigLoader::getInstance()->getSetting($key, $default);
    }
}

if (!function_exists('department')) {
    function department(string $code): ?array
    {
        return ConfigLoader::getInstance()->getDepartment($code);
    }
}

if (!function_exists('role')) {
    function role(string $roleCode): ?array
    {
        return ConfigLoader::getInstance()->getRole($roleCode);
    }
}

if (!function_exists('dashboard_path')) {
    function dashboard_path(string $userType, ?string $role = null, ?string $department = null): string
    {
        return ConfigLoader::getInstance()->getDashboard($userType, $role, $department);
    }
}

if (!function_exists('has_permission')) {
    function has_permission(string $roleCode, string $permission): bool
    {
        return ConfigLoader::getInstance()->hasPermission($roleCode, $permission);
    }
}

if (!function_exists('dept_color')) {
    function dept_color(string $deptCode): string
    {
        return ConfigLoader::getInstance()->getDepartmentColor($deptCode);
    }
}

if (!function_exists('dept_icon')) {
    function dept_icon(string $deptCode): string
    {
        return ConfigLoader::getInstance()->getDepartmentIcon($deptCode);
    }
}

if (!function_exists('role_badge')) {
    function role_badge(string $roleCode): string
    {
        return ConfigLoader::getInstance()->getRoleBadge($roleCode);
    }
}

if (!function_exists('set_config')) {
    function set_config(string $key, mixed $value, string $type = 'string', string $description = ''): bool
    {
        return ConfigLoader::getInstance()->set($key, $value, $type, $description);
    }
}