<?php
// conn.php - Enhanced database connection management (Prepared‑statement ready)
// Version: 5.0 (PHP 8.3+ with enhanced security, connection pooling, and improved error handling)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

// =====================================================
// DATABASE CONFIGURATION - Environment-based
// =====================================================
// These constants are defined in config/config_loader.php
// but provide fallbacks for backward compatibility
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'fingerchops_bakery');
}
if (!defined('DB_PORT')) {
    define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

// Ensure logs directory exists
$logDir = dirname(__FILE__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// =====================================================
// ERROR REPORTING (Production-safe)
// =====================================================
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/php_errors.log');
}

/**
 * UUID Generator Function (MySQL-compatible)
 */
if (!function_exists('generate_mysql_uuid')) {
    function generate_mysql_uuid(): string {
        if (function_exists('generateUUID')) {
            return generateUUID();
        }
        
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

/**
 * Database Connection Class (MySQLi with Enhanced Features)
 * 
 * @package Fingerchops
 * @version 5.0
 */
class Database {
    private static ?self $instance = null;
    private ?mysqli $connection = null;
    private int $transactionLevel = 0;
    private string $lastQuery = '';
    private int $queryCount = 0;
    private float $connectionTime = 0.0;
    private bool $inTransaction = false;
    
    // Connection pool configuration
    private static array $connectionStats = [
        'total_queries' => 0,
        'slow_queries' => 0,
        'total_time' => 0.0,
    ];
    
    private const SLOW_QUERY_THRESHOLD = 1.0; // seconds
    
    private function __construct() {
        $this->connect();
    }
    
    private function __clone() {}
    
    public function __wakeup() {
        throw new Exception('Cannot unserialize Database singleton');
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Establish database connection with retry logic
     */
    private function connect(): void {
        $startTime = microtime(true);
        $maxRetries = defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE ? 1 : 3;
        $retryCount = 0;
        
        while ($retryCount < $maxRetries) {
            $this->connection = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            
            if ($this->connection && !$this->connection->connect_error) {
                break;
            }
            
            $retryCount++;
            if ($retryCount < $maxRetries) {
                usleep(100000); // Wait 0.1 seconds before retry
            }
        }
        
        if (!$this->connection || $this->connection->connect_error) {
            $error = $this->connection ? $this->connection->connect_error : 'Connection failed';
            $errno = $this->connection ? $this->connection->connect_errno : 0;
            
            error_log("Database connection failed [{$errno}]: {$error}");
            
            if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
                die("Database connection error: {$error}");
            }
            die("We're experiencing technical difficulties. Please try again later.");
        }
        
        $this->connectionTime = microtime(true) - $startTime;
        
        // Set charset and connection options
        if (!$this->connection->set_charset(DB_CHARSET)) {
            error_log("Failed to set charset: " . $this->connection->error);
        }
        
        // Set MySQL session variables for optimal performance
        $this->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->query("SET SESSION time_zone = '+01:00'");
        
        // Enable query logging for debugging (only in development)
        if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
            $this->query("SET SESSION log_queries_not_using_indexes = 1");
        }
    }
    
    /**
     * Get the MySQLi connection (with automatic reconnection)
     */
    public function getConnection(): mysqli {
        $this->ping();
        return $this->connection;
    }
    
    /**
     * Execute a raw query with error handling
     */
    public function query(string $sql) {
        $this->ping();
        $this->lastQuery = $sql;
        $this->queryCount++;
        self::$connectionStats['total_queries']++;
        
        $startTime = microtime(true);
        $sql = $this->processUUIDFunctions($sql);
        $result = $this->connection->query($sql);
        $executionTime = microtime(true) - $startTime;
        
        self::$connectionStats['total_time'] += $executionTime;
        
        if ($executionTime > self::SLOW_QUERY_THRESHOLD) {
            self::$connectionStats['slow_queries']++;
            error_log("SLOW QUERY ({$executionTime}s): " . substr($sql, 0, 500));
        }
        
        if ($result === false) {
            $error = $this->connection->error;
            $errno = $this->connection->errno;
            
            error_log("MySQL Error [{$errno}]: {$error} in query: " . substr($sql, 0, 1000));
            $this->logError($errno, $error, $sql);
            
            return false;
        }
        
        return $result;
    }
    
    /**
     * Process UUID() function calls (replaces with generated UUIDs)
     */
    private function processUUIDFunctions(string $sql): string {
        // Handle UUID() in INSERT statements
        if (stripos($sql, 'UUID()') !== false) {
            // Check if UUID() is in VALUES clause (INSERT)
            if (preg_match('/INSERT\s+INTO.*VALUES.*UUID\(\)/i', $sql)) {
                $uuid = generate_mysql_uuid();
                $sql = str_replace('UUID()', "'{$uuid}'", $sql);
            }
            // Check if UUID() is in SET clause (UPDATE)
            elseif (preg_match('/UPDATE.*SET.*UUID\(\)/i', $sql)) {
                $uuid = generate_mysql_uuid();
                $sql = str_replace('UUID()', "'{$uuid}'", $sql);
            }
        }
        
        // Handle CONCAT with UUID()
        if (preg_match('/CONCAT\s*\(\s*[\'"]?(.*?)[\'"]?\s*,\s*UUID\s*\(\s*\)\s*\)/i', $sql, $matches)) {
            $prefix = trim($matches[1] ?? '', "'\"");
            $uuid = generate_mysql_uuid();
            $replacement = "'" . $prefix . $uuid . "'";
            $sql = preg_replace('/CONCAT\s*\(\s*[\'"]?(.*?)[\'"]?\s*,\s*UUID\s*\(\s*\)\s*\)/i', $replacement, $sql);
        }
        
        return $sql;
    }
    
    /**
     * Prepare a statement with proper error handling
     */
    public function prepare(string $sql): ?mysqli_stmt {
        $this->ping();
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $this->connection->error);
            return null;
        }
        
        return $stmt;
    }
    
    /**
     * Execute a prepared statement and return the statement object
     * 
     * @param string $sql SQL query with placeholders
     * @param string $types Parameter types (i, d, s, b)
     * @param array $params Parameters to bind
     * @return mysqli_stmt|false
     */
    public function executePrepared(string $sql, string $types, array $params) {
        $this->ping();
        
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->connection->error);
            return false;
        }
        
        // Only bind parameters if there are any
        if (!empty($params) && !empty($types)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            $stmt->close();
            return false;
        }
        
        return $stmt;
    }
    
    /**
     * Execute a prepared statement and fetch all rows as associative array
     */
    public function preparedFetchAll(string $sql, string $types, array $params): array {
        $stmt = $this->executePrepared($sql, $types, $params);
        if (!$stmt) {
            return [];
        }
        
        $result = $stmt->get_result();
        $rows = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        
        $stmt->close();
        return $rows;
    }
    
    /**
     * Execute a prepared statement and fetch one row as associative array
     */
    public function preparedFetchOne(string $sql, string $types, array $params): ?array {
        $stmt = $this->executePrepared($sql, $types, $params);
        if (!$stmt) {
            return null;
        }
        
        $result = $stmt->get_result();
        $row = ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
        
        if ($result) {
            $result->free();
        }
        $stmt->close();
        
        return $row;
    }
    
    /**
     * Execute a prepared statement that doesn't return rows (INSERT, UPDATE, DELETE)
     */
    public function preparedExecute(string $sql, string $types, array $params): bool {
        $stmt = $this->executePrepared($sql, $types, $params);
        if (!$stmt) {
            return false;
        }
        
        $success = ($stmt->affected_rows !== -1);
        $stmt->close();
        
        return $success;
    }
    
    /**
     * Fetch one row from a raw query (for backward compatibility)
     * @deprecated Use preparedFetchOne instead
     */
    public function fetchOne(string $sql): ?array {
        $result = $this->query($sql);
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }
    
    /**
     * Fetch all rows from a raw query (for backward compatibility)
     * @deprecated Use preparedFetchAll instead
     */
    public function fetchAll(string $sql): array {
        $result = $this->query($sql);
        $rows = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
        }
        
        return $rows;
    }
    
    /**
     * Fetch a single column from a raw query (for backward compatibility)
     * @deprecated Use preparedFetchOne with specific column selection
     */
    public function fetchColumn(string $sql, int $column = 0): mixed {
        $result = $this->query($sql);
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_row();
            return $row[$column] ?? null;
        }
        return null;
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId(): int {
        return (int)$this->connection->insert_id;
    }
    
    /**
     * Get number of affected rows from last query
     */
    public function affectedRows(): int {
        return $this->connection->affected_rows;
    }
    
    /**
     * Escape a string for safe use in queries
     * @deprecated Use prepared statements instead
     */
    public function escape(string $string): string {
        $this->ping();
        return $this->connection->real_escape_string($string);
    }
    
    // =====================================================
    // TRANSACTION METHODS
    // =====================================================
    
    public function beginTransaction(): bool {
        if ($this->transactionLevel === 0) {
            $this->query("START TRANSACTION");
            $this->inTransaction = true;
        } else {
            $this->query("SAVEPOINT level_{$this->transactionLevel}");
        }
        $this->transactionLevel++;
        return true;
    }
    
    public function commit(): bool {
        if ($this->transactionLevel === 1) {
            $this->query("COMMIT");
            $this->transactionLevel = 0;
            $this->inTransaction = false;
        } elseif ($this->transactionLevel > 1) {
            $this->query("RELEASE SAVEPOINT level_" . ($this->transactionLevel - 1));
            $this->transactionLevel--;
        }
        return true;
    }
    
    public function rollback(): bool {
        if ($this->transactionLevel === 1) {
            $this->query("ROLLBACK");
            $this->transactionLevel = 0;
            $this->inTransaction = false;
        } elseif ($this->transactionLevel > 1) {
            $this->query("ROLLBACK TO SAVEPOINT level_" . ($this->transactionLevel - 1));
            $this->transactionLevel--;
        }
        return true;
    }
    
    public function inTransaction(): bool {
        return $this->inTransaction;
    }
    
    // =====================================================
    // CONNECTION MANAGEMENT
    // =====================================================
    
    /**
     * Ping the database and reconnect if necessary
     */
    public function ping(): bool {
        if (!$this->connection) {
            $this->connect();
            return false;
        }
        
        if (!$this->connection->ping()) {
            error_log("Database connection lost, reconnecting...");
            $this->connect();
            return false;
        }
        
        return true;
    }
    
    /**
     * Log database errors to error_log table
     */
    private function logError(int $errno, string $error, string $sql): void {
        // Avoid recursion - don't log if we're already logging an error
        if (strpos($sql, 'INSERT INTO error_log') !== false) {
            return;
        }
        
        // Check if error_log table exists
        $checkResult = $this->connection->query("SHOW TABLES LIKE 'error_log'");
        if ($checkResult && $checkResult->num_rows > 0) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? "'" . $this->escape($_SERVER['REMOTE_ADDR']) . "'" : 'NULL';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? "'" . $this->escape(substr($_SERVER['HTTP_USER_AGENT'], 0, 255)) . "'" : 'NULL';
            $errno = (int)$errno;
            $error = "'" . $this->escape($error) . "'";
            $sql = "'" . $this->escape($sql) . "'";
            
            $logSql = "INSERT INTO error_log (errno, error, query, ip_address, user_agent, created_at) 
                       VALUES ({$errno}, {$error}, {$sql}, {$ip}, {$ua}, NOW())";
            $this->query($logSql);
        }
    }
    
    // =====================================================
    // STATISTICS AND DIAGNOSTICS
    // =====================================================
    
    public function getStats(): array {
        return [
            'query_count' => $this->queryCount,
            'last_query' => $this->lastQuery,
            'connection_time' => $this->connectionTime,
            'transaction_level' => $this->transactionLevel,
            'in_transaction' => $this->inTransaction,
            'total_queries' => self::$connectionStats['total_queries'],
            'slow_queries' => self::$connectionStats['slow_queries'],
            'total_time' => round(self::$connectionStats['total_time'], 4),
        ];
    }
    
    public function getTableStatus(?string $tableName = null): array {
        $sql = "SHOW TABLE STATUS";
        if ($tableName !== null) {
            $tableName = $this->escape($tableName);
            $sql .= " LIKE '{$tableName}'";
        }
        return $this->fetchAll($sql);
    }
    
    public function getDatabaseSize(): ?array {
        $sql = "SELECT 
                    SUM(data_length + index_length) as total_size,
                    SUM(data_length) as data_size,
                    SUM(index_length) as index_size
                FROM information_schema.tables 
                WHERE table_schema = '" . DB_NAME . "'";
        return $this->fetchOne($sql);
    }
    
    /**
     * Close the database connection
     */
    public function close(): void {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
            self::$instance = null;
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}

// =====================================================
// GLOBAL DATABASE ACCESS FUNCTIONS
// =====================================================

if (!function_exists('db')) {
    function db(): Database {
        return Database::getInstance();
    }
}

if (!function_exists('query')) {
    function query(string $sql) {
        return db()->query($sql);
    }
}

if (!function_exists('fetch_one')) {
    function fetch_one(string $sql): ?array {
        return db()->fetchOne($sql);
    }
}

if (!function_exists('fetch_all')) {
    function fetch_all(string $sql): array {
        return db()->fetchAll($sql);
    }
}

if (!function_exists('escape')) {
    function escape(string $string): string {
        return db()->escape($string);
    }
}

// Initialize database connection
$db = db();