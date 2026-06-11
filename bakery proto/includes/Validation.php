<?php
// includes/Validation.php - Enhanced input validation class for PHP 8.3
// Version: 4.0 (PHP 8.3+ with type safety, improved validation rules, and database safety)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Validation Class - Comprehensive input validation
 * 
 * @package Fingerchops
 * @version 4.0
 */
class Validation {
    private array $errors = [];
    private ?Database $db = null;
    private array $config = [];
    
    // Validation rule constants
    private const PASSWORD_MIN_LENGTH = 8;
    private const USERNAME_MIN_LENGTH = 3;
    private const USERNAME_MAX_LENGTH = 50;
    private const FULLNAME_MIN_LENGTH = 2;
    private const FULLNAME_MAX_LENGTH = 100;
    private const EMAIL_MAX_LENGTH = 100;
    
    // Common weak passwords
    private const WEAK_PASSWORDS = [
        'password', '123456', 'qwerty', 'admin', 'letmein', 
        'welcome', '12345678', 'abc123', 'password1', 'admin123'
    ];
    
    // Sequential character patterns to block
    private const SEQUENTIAL_PATTERNS = [
        '123', '234', '345', '456', '567', '678', '789',
        'abc', 'bcd', 'cde', 'def', 'efg', 'fgh', 'ghi',
        'hij', 'ijk', 'jkl', 'klm', 'lmn', 'mno', 'nop',
        'opq', 'pqr', 'qrs', 'rst', 'stu', 'tuv', 'uvw',
        'vwx', 'wxy', 'xyz', 'qwerty', 'asdf', 'zxcv'
    ];
    
    // Reserved usernames
    private const RESERVED_USERNAMES = [
        'admin', 'administrator', 'root', 'system', 
        'support', 'webmaster', 'moderator', 'superuser'
    ];
    
    public function __construct(bool $useDatabase = true) {
        if ($useDatabase && class_exists('Database')) {
            $this->db = Database::getInstance();
        }
        
        // Load validation config from system_config
        if (function_exists('setting')) {
            $this->config = [
                'password.min_length' => (int)setting('password_min_length', self::PASSWORD_MIN_LENGTH),
                'password.require_uppercase' => (bool)setting('password_require_uppercase', true),
                'password.require_lowercase' => (bool)setting('password_require_lowercase', true),
                'password.require_number' => (bool)setting('password_require_numbers', true),
                'password.require_special' => (bool)setting('password_require_special', true),
                'username.min_length' => (int)setting('username_min_length', self::USERNAME_MIN_LENGTH),
                'username.max_length' => (int)setting('username_max_length', self::USERNAME_MAX_LENGTH),
                'email.max_length' => (int)setting('email_max_length', self::EMAIL_MAX_LENGTH),
                'max_login_attempts' => (int)setting('max_login_attempts', 5),
            ];
        }
    }
    
    /**
     * Get validation rule from config
     */
    private function getRule(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Validate email address
     */
    public function validateEmail(string $email, string $fieldName = 'Email'): bool {
        $email = trim($email);
        
        if (empty($email)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError($fieldName, "$fieldName must be a valid email address");
            return false;
        }
        
        $maxLength = $this->getRule('email.max_length', self::EMAIL_MAX_LENGTH);
        if (strlen($email) > $maxLength) {
            $this->addError($fieldName, "$fieldName must be less than $maxLength characters");
            return false;
        }
        
        // Check for disposable email domains (if configured)
        if ($this->getRule('block_disposable_emails', false)) {
            $domain = substr(strrchr($email, "@"), 1);
            $disposableDomains = $this->getRule('disposable_domains', []);
            if (in_array($domain, $disposableDomains, true)) {
                $this->addError($fieldName, "Disposable email addresses are not allowed");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate phone number with configurable patterns
     */
    public function validatePhone(string $phone, string $fieldName = 'Phone', string $country = 'NG'): bool {
        $phone = trim($phone);
        
        if (empty($phone)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        // Remove all non-numeric characters for validation
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        $patterns = [
            'NG' => [
                '/^234[0-9]{10}$/',      // +234XXXXXXXXXX
                '/^0[7-9][0-9]{9}$/',    // 0XXXXXXXXXX
                '/^[7-9][0-9]{9}$/',     // XXXXXXXXXX
            ],
            'US' => [
                '/^1[0-9]{10}$/',        // +1XXXXXXXXXX
                '/^[0-9]{10}$/',         // XXXXXXXXXX
            ],
            'UK' => [
                '/^44[0-9]{10}$/',       // +44XXXXXXXXXX
                '/^0[0-9]{10}$/',        // 0XXXXXXXXXX
            ],
        ];
        
        $countryPatterns = $patterns[$country] ?? $patterns['NG'];
        
        $valid = false;
        foreach ($countryPatterns as $pattern) {
            if (preg_match($pattern, $cleanPhone)) {
                $valid = true;
                break;
            }
        }
        
        if (!$valid) {
            $this->addError($fieldName, "$fieldName must be a valid phone number for $country");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate password strength
     */
    public function validatePassword(string $password, string $fieldName = 'Password', ?string $confirmPassword = null): bool {
        if (empty($password)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        if ($confirmPassword !== null && $password !== $confirmPassword) {
            $this->addError($fieldName, "Passwords do not match");
            return false;
        }
        
        $minLength = $this->getRule('password.min_length', 8);
        $requireUpper = $this->getRule('password.require_uppercase', true);
        $requireLower = $this->getRule('password.require_lowercase', true);
        $requireNumbers = $this->getRule('password.require_number', true);
        $requireSpecial = $this->getRule('password.require_special', true);
        
        // Check minimum length
        if (strlen($password) < $minLength) {
            $this->addError($fieldName, "$fieldName must be at least $minLength characters");
            return false;
        }
        
        // Prevent overly long passwords (DOS protection)
        if (strlen($password) > 128) {
            $this->addError($fieldName, "$fieldName must not exceed 128 characters");
            return false;
        }
        
        // Check uppercase
        if ($requireUpper && !preg_match('/[A-Z]/', $password)) {
            $this->addError($fieldName, "$fieldName must contain at least one uppercase letter (A-Z)");
            return false;
        }
        
        // Check lowercase
        if ($requireLower && !preg_match('/[a-z]/', $password)) {
            $this->addError($fieldName, "$fieldName must contain at least one lowercase letter (a-z)");
            return false;
        }
        
        // Check numbers
        if ($requireNumbers && !preg_match('/[0-9]/', $password)) {
            $this->addError($fieldName, "$fieldName must contain at least one number (0-9)");
            return false;
        }
        
        // Check special characters - expanded to include common special chars
        if ($requireSpecial && !preg_match('/[!@#$%^&*(),.?":{}|<>_\-\[\]\\\\\/]/', $password)) {
            $this->addError($fieldName, "$fieldName must contain at least one special character (!@#$%^&*()_-=+)");
            return false;
        }
        
        // Optional: Check against common weak passwords
        $weakPasswords = ['password', '123456', 'qwerty', 'admin', 'letmein', 'welcome', 'password123'];
        if (in_array(strtolower($password), $weakPasswords)) {
            $this->addError($fieldName, "$fieldName is too common. Please choose a stronger password.");
            return false;
        }
        
        return true;
    }
    
    /**
     * Check for sequential characters in password
     */
    private function hasSequentialChars(string $password): bool {
        foreach (self::SEQUENTIAL_PATTERNS as $seq) {
            if (str_contains($password, $seq)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Validate username
     */
    public function validateUsername(string $username, string $fieldName = 'Username'): bool {
        $username = trim($username);
        
        if (empty($username)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        $minLength = $this->getRule('username.min_length', self::USERNAME_MIN_LENGTH);
        $maxLength = $this->getRule('username.max_length', self::USERNAME_MAX_LENGTH);
        
        if (strlen($username) < $minLength || strlen($username) > $maxLength) {
            $this->addError($fieldName, "$fieldName must be between $minLength and $maxLength characters");
            return false;
        }
        
        // Allowed characters: letters (a-z, A-Z), digits (0-9), underscore (_), hyphen (-), dot (.)
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $this->addError($fieldName, "$fieldName contains invalid characters. Allowed: letters, digits, underscore, hyphen, and dot.");
            return false;
        }
        
        // Check reserved usernames
        if (in_array(strtolower($username), self::RESERVED_USERNAMES, true)) {
            $this->addError($fieldName, "$fieldName is reserved and cannot be used");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate full name
     */
    public function validateFullName(string $fullname, string $fieldName = 'Full name'): bool {
        $fullname = trim($fullname);
        
        if (empty($fullname)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        if (strlen($fullname) < self::FULLNAME_MIN_LENGTH || strlen($fullname) > self::FULLNAME_MAX_LENGTH) {
            $this->addError($fieldName, "$fieldName must be between " . self::FULLNAME_MIN_LENGTH . " and " . self::FULLNAME_MAX_LENGTH . " characters");
            return false;
        }
        
        // Allow letters, spaces, hyphens, apostrophes, and some international characters
        if (!preg_match('/^[\p{L}\s\-\']+$/u', $fullname)) {
            $this->addError($fieldName, "$fieldName contains invalid characters. Only letters, spaces, hyphens and apostrophes are allowed.");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate required field
     */
    public function validateRequired(mixed $value, string $fieldName): bool {
        if (is_array($value)) {
            if (empty($value)) {
                $this->addError($fieldName, "$fieldName is required");
                return false;
            }
        } else {
            $value = trim((string)$value);
            if ($value === '' || $value === null) {
                $this->addError($fieldName, "$fieldName is required");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate numeric value
     */
    public function validateNumeric(mixed $value, string $fieldName, ?float $min = null, ?float $max = null): bool {
        if (!is_numeric($value)) {
            $this->addError($fieldName, "$fieldName must be a number");
            return false;
        }
        
        $value = (float)$value;
        
        if ($min !== null && $value < $min) {
            $this->addError($fieldName, "$fieldName must be at least $min");
            return false;
        }
        
        if ($max !== null && $value > $max) {
            $this->addError($fieldName, "$fieldName must be at most $max");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate integer
     */
    public function validateInteger(mixed $value, string $fieldName, ?int $min = null, ?int $max = null): bool {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->addError($fieldName, "$fieldName must be an integer");
            return false;
        }
        
        return $this->validateNumeric($value, $fieldName, $min, $max);
    }
    
    /**
     * Validate date
     */
    public function validateDate(string $date, string $fieldName, string $format = 'Y-m-d', ?string $minDate = null, ?string $maxDate = null): bool {
        if (empty($date)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        $d = DateTime::createFromFormat($format, $date);
        if (!$d || $d->format($format) !== $date) {
            $this->addError($fieldName, "$fieldName must be a valid date in format $format");
            return false;
        }
        
        $timestamp = $d->getTimestamp();
        
        if ($minDate !== null) {
            $minTimestamp = is_numeric($minDate) ? (int)$minDate : strtotime($minDate);
            if ($timestamp < $minTimestamp) {
                $minDisplay = is_numeric($minDate) ? date($format, $minDate) : $minDate;
                $this->addError($fieldName, "$fieldName must be on or after $minDisplay");
                return false;
            }
        }
        
        if ($maxDate !== null) {
            $maxTimestamp = is_numeric($maxDate) ? (int)$maxDate : strtotime($maxDate);
            if ($timestamp > $maxTimestamp) {
                $maxDisplay = is_numeric($maxDate) ? date($format, $maxDate) : $maxDate;
                $this->addError($fieldName, "$fieldName must be on or before $maxDisplay");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate URL
     */
    public function validateUrl(string $url, string $fieldName = 'URL', bool $requireProtocol = true): bool {
        $url = trim($url);
        
        if (empty($url)) {
            $this->addError($fieldName, "$fieldName is required");
            return false;
        }
        
        if ($requireProtocol && !preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->addError($fieldName, "$fieldName must be a valid URL");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate boolean
     */
    public function validateBoolean(mixed $value, string $fieldName): bool {
        $validBooleans = [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'];
        
        if (!in_array($value, $validBooleans, true)) {
            $this->addError($fieldName, "$fieldName must be a boolean value");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate array structure
     */
    public function validateArray(array $array, string $fieldName, array $requiredKeys = []): bool {
        if (!is_array($array)) {
            $this->addError($fieldName, "$fieldName must be an array");
            return false;
        }
        
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $array)) {
                $this->addError($fieldName, "$fieldName is missing required key: $key");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate file upload
     */
    public function validateFile(array $file, string $fieldName, array $allowedTypes = [], ?int $maxSize = null, ?int $minSize = null): bool {
        if (!isset($file) || !is_array($file)) {
            $this->addError($fieldName, "No file uploaded");
            return false;
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => "File exceeds upload_max_filesize",
                UPLOAD_ERR_FORM_SIZE => "File exceeds MAX_FILE_SIZE",
                UPLOAD_ERR_PARTIAL => "File was only partially uploaded",
                UPLOAD_ERR_NO_FILE => "No file was uploaded",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
                UPLOAD_ERR_EXTENSION => "File upload stopped by extension"
            ];
            
            $errorMsg = $uploadErrors[$file['error']] ?? "Unknown upload error";
            $this->addError($fieldName, "$fieldName: $errorMsg");
            return false;
        }
        
        if ($maxSize !== null && $file['size'] > $maxSize) {
            $this->addError($fieldName, "$fieldName must be less than " . $this->formatBytes($maxSize));
            return false;
        }
        
        if ($minSize !== null && $file['size'] < $minSize) {
            $this->addError($fieldName, "$fieldName must be at least " . $this->formatBytes($minSize));
            return false;
        }
        
        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes, true)) {
                $this->addError($fieldName, "$fieldName has invalid file type. Allowed types: " . implode(', ', $allowedTypes));
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate CSRF token - Delegates to Security.php
     */
    public function validateCsrfToken(?string $token, string $action): bool {
        if (empty($token)) {
            $this->addError('csrf', 'Missing security token');
            return false;
        }
        
        if (function_exists('verifyCSRFToken')) {
            $result = verifyCSRFToken($token, $action);
            if (!$result) {
                $this->addError('csrf', 'Invalid or expired security token');
            }
            return $result;
        }
        
        $this->addError('csrf', 'CSRF verification not available');
        return false;
    }
    
    /**
     * Generate CSRF token - Delegates to Security.php
     */
    public function generateCsrfToken(string $action = 'default'): string|false {
        if (function_exists('generateCSRFToken')) {
            return generateCSRFToken($action);
        }
        return false;
    }
    
    /**
     * Validate CAPTCHA response
     */
    public function validateCaptcha(string $response, ?string $secretKey = null): bool {
        if (function_exists('verifyCaptcha')) {
            return verifyCaptcha($response);
        }
        
        if (isset($_SESSION['captcha_answer']) && $response == $_SESSION['captcha_answer']) {
            unset($_SESSION['captcha_answer']);
            return true;
        }
        
        $this->addError('captcha', 'Invalid captcha response');
        return false;
    }
    
    /**
     * Generate simple math captcha
     */
    public function generateMathCaptcha(): array {
        $num1 = random_int(1, 9);
        $num2 = random_int(1, 9);
        $operation = random_int(0, 1) === 1 ? '+' : '-';
        
        if ($operation === '-') {
            if ($num1 < $num2) {
                $temp = $num1;
                $num1 = $num2;
                $num2 = $temp;
            }
            $answer = $num1 - $num2;
        } else {
            $answer = $num1 + $num2;
        }
        
        $_SESSION['captcha_answer'] = $answer;
        
        return [
            'question' => "$num1 $operation $num2 = ?",
            'answer' => $answer
        ];
    }
    
    /**
     * Check if email exists in database (using prepared statements)
     */
    public function isEmailUnique(string $email, ?int $excludeUserId = null): bool {
        if (!$this->db) {
            return true;
        }
        
        $sql = "SELECT id FROM bakery_users WHERE email = ?";
        $params = [$email];
        $types = 's';
        
        if ($excludeUserId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
            $types .= 'i';
        }
        
        $row = $this->db->preparedFetchOne($sql, $types, $params);
        return empty($row);
    }
    
    /**
     * Check if username exists in database (using prepared statements)
     */
    public function isUsernameUnique(string $username, ?int $excludeUserId = null): bool {
        if (!$this->db) {
            return true;
        }
        
        $sql = "SELECT id FROM bakery_users WHERE username = ?";
        $params = [$username];
        $types = 's';
        
        if ($excludeUserId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
            $types .= 'i';
        }
        
        $row = $this->db->preparedFetchOne($sql, $types, $params);
        return empty($row);
    }
    
    /**
     * Check if phone exists in database (using prepared statements)
     */
    public function isPhoneUnique(string $phone, ?int $excludeUserId = null): bool {
        if (!$this->db) {
            return true;
        }
        
        $sql = "SELECT id FROM bakery_users WHERE phone = ?";
        $params = [$phone];
        $types = 's';
        
        if ($excludeUserId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
            $types .= 'i';
        }
        
        $row = $this->db->preparedFetchOne($sql, $types, $params);
        return empty($row);
    }
    
    /**
     * Validate that a record exists in database
     */
    public function recordExists(int $id, string $table, string $idColumn = 'id'): bool {
        if (!$this->db) {
            return false;
        }
        
        // Validate table name to prevent injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $idColumn)) {
            $this->addError('database', 'Invalid table or column name');
            return false;
        }
        
        $row = $this->db->preparedFetchOne(
            "SELECT $idColumn FROM $table WHERE $idColumn = ?",
            'i',
            [$id]
        );
        
        return !empty($row);
    }
    
    /**
     * Sanitize input for safe output
     */
    public function sanitize(mixed $input, string $type = 'string'): mixed {
        if (is_array($input)) {
            return array_map(fn($item) => $this->sanitize($item, $type), $input);
        }
        
        $input = trim((string)$input);
        
        return match ($type) {
            'email' => filter_var($input, FILTER_SANITIZE_EMAIL),
            'url' => filter_var($input, FILTER_SANITIZE_URL),
            'int', 'integer' => filter_var($input, FILTER_SANITIZE_NUMBER_INT),
            'float' => filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            default => htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        };
    }
    
    /**
     * Validate multiple fields at once
     */
    public function validate(array $data, array $rules): array {
        $this->errors = [];
        $validated = [];
        
        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldName = $fieldRules['label'] ?? ucfirst($field);
            
            foreach ($fieldRules as $rule => $parameter) {
                if ($rule === 'label') {
                    continue;
                }
                
                $result = $this->applyRule($field, $value, $rule, $parameter, $fieldName, $data);
                if ($result === false) {
                    break; // Stop processing rules for this field on failure
                }
            }
            
            if ($this->passes()) {
                $validated[$field] = $value;
            }
        }
        
        return $validated;
    }
    
    /**
     * Apply a single validation rule
     */
    private function applyRule(string $field, mixed $value, string $rule, mixed $parameter, string $fieldName, array $data): bool {
        return match ($rule) {
            'required' => $this->validateRequired($value, $fieldName),
            'email' => !empty($value) ? $this->validateEmail((string)$value, $fieldName) : true,
            'phone' => !empty($value) ? $this->validatePhone((string)$value, $fieldName) : true,
            'password' => $this->validatePassword((string)$value, $fieldName, $data[$parameter] ?? null),
            'username' => !empty($value) ? $this->validateUsername((string)$value, $fieldName) : true,
            'min' => $this->validateMinLength((string)$value, (int)$parameter, $fieldName),
            'max' => $this->validateMaxLength((string)$value, (int)$parameter, $fieldName),
            'numeric' => !empty($value) ? $this->validateNumeric($value, $fieldName) : true,
            'integer' => !empty($value) ? $this->validateInteger($value, $fieldName) : true,
            'unique' => $this->validateUnique($value, (string)$parameter, $field, $fieldName),
            'exists' => $this->validateExists($value, (string)$parameter, $fieldName),
            'in' => $this->validateInArray($value, $parameter, $fieldName),
            'date' => !empty($value) ? $this->validateDate((string)$value, $fieldName) : true,
            'confirmed' => $this->validateConfirmed($value, $data[$parameter ?? $field . '_confirmation'] ?? null, $fieldName),
            'csrf' => $this->validateCsrfToken((string)$value, (string)$parameter),
            default => true,
        };
    }
    
    private function validateMinLength(string $value, int $min, string $fieldName): bool {
        if (strlen($value) < $min) {
            $this->addError($fieldName, "$fieldName must be at least $min characters");
            return false;
        }
        return true;
    }
    
    private function validateMaxLength(string $value, int $max, string $fieldName): bool {
        if (strlen($value) > $max) {
            $this->addError($fieldName, "$fieldName must not exceed $max characters");
            return false;
        }
        return true;
    }
    
    private function validateUnique(mixed $value, string $parameter, string $field, string $fieldName): bool {
        if (empty($value)) {
            return true;
        }
        
        $params = explode(',', $parameter);
        $table = trim($params[0]);
        $column = trim($params[1] ?? $field);
        $excludeId = isset($params[2]) ? (int)trim($params[2]) : null;
        
        if ($this->existsInDatabase((string)$value, $table, $column, $excludeId)) {
            $this->addError($fieldName, "$fieldName already exists");
            return false;
        }
        return true;
    }
    
    private function validateExists(mixed $value, string $parameter, string $fieldName): bool {
        if (empty($value)) {
            return true;
        }
        
        $params = explode(',', $parameter);
        $table = trim($params[0]);
        $column = trim($params[1] ?? 'id');
        
        if (!$this->recordExists((int)$value, $table, $column)) {
            $this->addError($fieldName, "$fieldName does not exist");
            return false;
        }
        return true;
    }
    
    private function validateInArray(mixed $value, mixed $parameter, string $fieldName): bool {
        $options = is_array($parameter) ? $parameter : explode(',', (string)$parameter);
        if (!empty($value) && !in_array($value, $options, true)) {
            $this->addError($fieldName, "$fieldName must be one of: " . implode(', ', $options));
            return false;
        }
        return true;
    }
    
    private function validateConfirmed(mixed $value, mixed $confirmValue, string $fieldName): bool {
        if ((string)$value !== (string)$confirmValue) {
            $this->addError($fieldName, "$fieldName confirmation does not match");
            return false;
        }
        return true;
    }
    
    /**
     * Check if value exists in database table (with safe table/column validation)
     */
    private function existsInDatabase(string $value, string $table, string $column, ?int $excludeId = null): bool {
        if (!$this->db) {
            return false;
        }
        
        // Validate table and column names to prevent injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }
        
        $sql = "SELECT id FROM $table WHERE $column = ?";
        $params = [$value];
        $types = 's';
        
        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }
        
        $row = $this->db->preparedFetchOne($sql, $types, $params);
        return !empty($row);
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= 1024 ** $pow;
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    /**
     * Add error message
     */
    private function addError(string $field, string $message): void {
        $this->errors[$field] = $message;
    }
    
    /**
     * Get all errors
     */
    public function getErrors(): array {
        return $this->errors;
    }
    
    /**
     * Get errors as string
     */
    public function getErrorsAsString(string $separator = "\n"): string {
        return implode($separator, $this->errors);
    }
    
    /**
     * Get error by field
     */
    public function getError(string $field): ?string {
        return $this->errors[$field] ?? null;
    }
    
    /**
     * Check if validation passed
     */
    public function passes(): bool {
        return empty($this->errors);
    }
    
    /**
     * Check if validation failed
     */
    public function fails(): bool {
        return !empty($this->errors);
    }
    
    /**
     * Get first error message
     */
    public function firstError(): ?string {
        return reset($this->errors) ?: null;
    }
    
    /**
     * Clear all errors
     */
    public function clearErrors(): void {
        $this->errors = [];
    }
}