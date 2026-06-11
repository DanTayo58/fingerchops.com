<?php
// includes/Helpers.php - Global helper functions for PHP 8.3
// Version: 4.0 (PHP 8.3+ with type safety, enhanced utilities, and performance improvements)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

// =====================================================
// STRING HELPERS
// =====================================================

if (!function_exists('str_truncate')) {
    function str_truncate(string $string, int $length = 100, string $append = '...'): string {
        if (mb_strlen($string) <= $length) {
            return $string;
        }
        
        $string = mb_substr($string, 0, $length);
        $lastSpace = mb_strrpos($string, ' ');
        
        if ($lastSpace !== false) {
            $string = mb_substr($string, 0, $lastSpace);
        }
        
        return $string . $append;
    }
}

if (!function_exists('create_slug')) {
    function create_slug(string $string): string {
        $string = mb_strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        return trim($string, '-');
    }
}

if (!function_exists('format_currency')) {
    function format_currency(float|int $amount, string $currency = '₦'): string {
        return $currency . number_format((float)$amount, 2);
    }
}

if (!function_exists('format_date')) {
    function format_date(string|int|null $date, ?string $format = null): string {
        if (empty($date)) {
            return 'N/A';
        }
        
        $format ??= setting('date_format', 'd/m/Y');
        $timestamp = is_numeric($date) ? (int)$date : strtotime((string)$date);
        
        if ($timestamp === false) {
            return 'N/A';
        }
        
        return date($format, $timestamp);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(string|int|null $datetime, ?string $format = null): string {
        if (empty($datetime)) {
            return 'N/A';
        }
        
        $format ??= setting('datetime_format', 'd/m/Y H:i');
        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
        
        if ($timestamp === false) {
            return 'N/A';
        }
        
        return date($format, $timestamp);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(string|int|null $datetime): string {
        if (empty($datetime)) {
            return 'N/A';
        }
        
        $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
        
        if ($timestamp === false) {
            return 'N/A';
        }
        
        $seconds = time() - $timestamp;
        
        $units = [
            31536000 => 'year',
            2592000 => 'month',
            604800 => 'week',
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute',
            1 => 'second'
        ];
        
        foreach ($units as $unit => $text) {
            if ($seconds < $unit) {
                continue;
            }
            $number = (int)floor($seconds / $unit);
            return $number . ' ' . $text . ($number > 1 ? 's' : '') . ' ago';
        }
        
        return 'just now';
    }
}

if (!function_exists('mask_string')) {
    function mask_string(string $string, int $visibleChars = 4, string $maskChar = '*'): string {
        $length = strlen($string);
        
        if ($length <= $visibleChars * 2) {
            return str_repeat($maskChar, $length);
        }
        
        $visibleStart = substr($string, 0, $visibleChars);
        $visibleEnd = substr($string, -$visibleChars);
        $maskedLength = $length - ($visibleChars * 2);
        
        return $visibleStart . str_repeat($maskChar, $maskedLength) . $visibleEnd;
    }
}

if (!function_exists('random_string')) {
    function random_string(int $length = 10, string $type = 'alnum'): string {
        $characters = match ($type) {
            'alnum' => '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'alpha' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'numeric' => '0123456789',
            'hex' => '0123456789abcdef',
            default => '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        };
        
        $charLength = strlen($characters);
        $random = '';
        
        try {
            for ($i = 0; $i < $length; $i++) {
                $random .= $characters[random_int(0, $charLength - 1)];
            }
        } catch (Exception $e) {
            for ($i = 0; $i < $length; $i++) {
                $random .= $characters[mt_rand(0, $charLength - 1)];
            }
        }
        
        return $random;
    }
}

// =====================================================
// ARRAY HELPERS
// =====================================================

if (!function_exists('array_get')) {
    function array_get(array $array, ?string $key, mixed $default = null): mixed {
        if ($key === null) {
            return $array;
        }
        
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        
        // Handle dot notation
        if (str_contains($key, '.')) {
            $keys = explode('.', $key);
            $value = $array;
            
            foreach ($keys as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }
            
            return $value;
        }
        
        return $default;
    }
}

if (!function_exists('is_assoc')) {
    function is_assoc(array $array): bool {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}

if (!function_exists('array_pluck')) {
    function array_pluck(array $array, string $key): array {
        $result = [];
        
        foreach ($array as $item) {
            if (is_object($item) && property_exists($item, $key)) {
                $result[] = $item->$key;
            } elseif (is_array($item) && array_key_exists($key, $item)) {
                $result[] = $item[$key];
            }
        }
        
        return $result;
    }
}

// =====================================================
// FILE HELPERS
// =====================================================

if (!function_exists('file_extension')) {
    function file_extension(string $filename): string {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}

if (!function_exists('is_image')) {
    function is_image(string $filename): bool {
        $ext = file_extension($filename);
        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'], true);
    }
}

if (!function_exists('format_size')) {
    function format_size(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= 1024 ** $pow;
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

if (!function_exists('ensure_directory')) {
    function ensure_directory(string $path): bool {
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }
}

// =====================================================
// URL HELPERS
// =====================================================

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string {
        static $base = null;
        
        if ($base === null) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            $base = rtrim($protocol . $host . $script, '/') . '/';
        }
        
        return $base . ltrim($path, '/');
    }
}

if (!function_exists('current_url')) {
    function current_url(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        return $protocol . $host . $uri;
    }
}

if (!function_exists('add_query_param')) {
    function add_query_param(string $url, string $key, string $value): string {
        $parsed = parse_url($url);
        $query = [];
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        
        $query[$key] = $value;
        $parsed['query'] = http_build_query($query);
        
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        
        return $scheme . $host . $path . '?' . $parsed['query'];
    }
}

// =====================================================
// HTML HELPERS
// =====================================================

if (!function_exists('select_options')) {
    function select_options(array $options, mixed $selected = null, string $valueKey = 'id', string $labelKey = 'name'): string {
        $html = '';
        
        foreach ($options as $option) {
            if (is_array($option)) {
                $value = $option[$valueKey] ?? '';
                $label = $option[$labelKey] ?? '';
            } else {
                $value = (string)$option;
                $label = (string)$option;
            }
            
            $isSelected = ($selected == $value) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"' . $isSelected . '>';
            $html .= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
            $html .= '</option>';
        }
        
        return $html;
    }
}

if (!function_exists('pagination_links')) {
    function pagination_links(int $currentPage, int $totalPages, string $urlPattern): string {
        if ($totalPages <= 1) {
            return '';
        }
        
        $html = '<ul class="pagination">';
        
        // Previous link
        if ($currentPage > 1) {
            $prevUrl = str_replace('{page}', (string)($currentPage - 1), $urlPattern);
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($prevUrl, ENT_QUOTES) . '">Previous</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
        }
        
        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        
        if ($start > 1) {
            $url = str_replace('{page}', '1', $urlPattern);
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }
        
        for ($i = $start; $i <= $end; $i++) {
            $url = str_replace('{page}', (string)$i, $urlPattern);
            $active = ($i === $currentPage) ? ' active' : '';
            $html .= '<li class="page-item' . $active . '">';
            $html .= '<a class="page-link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $i . '</a>';
            $html .= '</li>';
        }
        
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            $url = str_replace('{page}', (string)$totalPages, $urlPattern);
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $totalPages . '</a></li>';
        }
        
        // Next link
        if ($currentPage < $totalPages) {
            $nextUrl = str_replace('{page}', (string)($currentPage + 1), $urlPattern);
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($nextUrl, ENT_QUOTES) . '">Next</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
        }
        
        $html .= '</ul>';
        
        return $html;
    }
}

if (!function_exists('star_rating')) {
    function star_rating(float $rating, int $max = 5): string {
        $html = '<div class="star-rating">';
        $rating = round($rating * 2) / 2; // Round to nearest 0.5
        
        for ($i = 1; $i <= $max; $i++) {
            if ($i <= $rating) {
                $html .= '<i class="fas fa-star"></i>';
            } elseif ($i - 0.5 === $rating) {
                $html .= '<i class="fas fa-star-half-alt"></i>';
            } else {
                $html .= '<i class="far fa-star"></i>';
            }
        }
        
        $html .= '</div>';
        return $html;
    }
}

// =====================================================
// DATA FORMATTING HELPERS
// =====================================================

if (!function_exists('format_phone')) {
    function format_phone(string $phone, string $country = 'NG'): string {
        // Remove non-numeric
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if ($country === 'NG') {
            if (strlen($phone) === 11 && str_starts_with($phone, '0')) {
                return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7, 4);
            }
            if (strlen($phone) === 13 && str_starts_with($phone, '234')) {
                return '+234 ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3) . ' ' . substr($phone, 9, 4);
            }
        }
        
        return $phone;
    }
}

if (!function_exists('truncate_html')) {
    function truncate_html(string $html, int $limit = 100, string $ellipsis = '...'): string {
        $text = strip_tags($html);
        if (mb_strlen($text) <= $limit) {
            return $html;
        }
        
        return mb_substr($text, 0, $limit) . $ellipsis;
    }
}

if (!function_exists('nl2p')) {
    function nl2p(string $string): string {
        $paragraphs = '';
        
        foreach (explode("\n\n", $string) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $paragraphs .= '<p>' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }
        
        return $paragraphs;
    }
}

// =====================================================
// SYSTEM HELPERS
// =====================================================

if (!function_exists('microtime_float')) {
    function microtime_float(): float {
        return microtime(true);
    }
}

if (!function_exists('debug_log')) {
    function debug_log(string $message, mixed $data = null): void {
        if (!defined('DEVELOPMENT_MODE') || !DEVELOPMENT_MODE) {
            return;
        }
        
        $log = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        
        if ($data !== null) {
            $log .= ': ' . print_r($data, true);
        }
        
        error_log($log, 3, dirname(__DIR__) . '/logs/debug.log');
    }
}

if (!function_exists('memory_usage')) {
    function memory_usage(): string {
        return format_size(memory_get_usage(true));
    }
}

if (!function_exists('memory_peak')) {
    function memory_peak(): string {
        return format_size(memory_get_peak_usage(true));
    }
}

// =====================================================
// COLOR HELPERS
// =====================================================

if (!function_exists('random_color')) {
    function random_color(): string {
        return '#' . str_pad(dechex(random_int(0, 0xFFFFFF)), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('hex_to_rgb')) {
    function hex_to_rgb(string $hex): array {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            return [
                'r' => hexdec(str_repeat(substr($hex, 0, 1), 2)),
                'g' => hexdec(str_repeat(substr($hex, 1, 1), 2)),
                'b' => hexdec(str_repeat(substr($hex, 2, 1), 2)),
            ];
        }
        
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }
}

if (!function_exists('contrasting_color')) {
    function contrasting_color(string $hex): string {
        $rgb = hex_to_rgb($hex);
        $brightness = ($rgb['r'] * 299 + $rgb['g'] * 587 + $rgb['b'] * 114) / 1000;
        
        return $brightness > 128 ? '#000000' : '#FFFFFF';
    }
}

// =====================================================
// VALIDATION HELPERS (Quick wrappers)
// =====================================================

if (!function_exists('is_valid_email')) {
    function is_valid_email(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('is_valid_url')) {
    function is_valid_url(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('is_valid_ip')) {
    function is_valid_ip(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('is_json')) {
    function is_json(string $string): bool {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

// =====================================================
// ENCRYPTION HELPERS (Simple wrappers)
// =====================================================

if (!function_exists('simple_encrypt')) {
    function simple_encrypt(string $data, string $key): string {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
}

if (!function_exists('simple_decrypt')) {
    function simple_decrypt(string $data, string $key): string|false {
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }
}

// =====================================================
// SESSION HELPERS
// =====================================================

if (!function_exists('set_flash')) {
    function set_flash(string $message, string $type = 'info'): void {
        $_SESSION['flash'] = [
            'message' => $message,
            'type' => $type,
            'time' => time()
        ];
    }
}

if (!function_exists('get_flash')) {
    function get_flash(): ?array {
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }
        return null;
    }
}

if (!function_exists('show_flash')) {
    function show_flash(): void {
        $flash = get_flash();
        
        if ($flash) {
            $type = htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8');
            $message = htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8');
            
            echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>";
            echo $message;
            echo "<button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>";
            echo "</div>";
        }
    }
}

// =====================================================
// USER/PERMISSION HELPERS (Quick wrappers)
// =====================================================

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
}

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('current_user_type')) {
    function current_user_type(): ?string {
        return $_SESSION['user_type'] ?? null;
    }
}

if (!function_exists('user_can')) {
    function user_can(string $permission): bool {
        if (!is_logged_in()) {
            return false;
        }
        
        static $auth = null;
        
        if ($auth === null) {
            require_once dirname(__FILE__) . '/Auth.php';
            $auth = new Auth();
        }
        
        return $auth->hasPermission(current_user_id(), $permission);
    }
}

// =====================================================
// DEBUGGING HELPERS
// =====================================================

if (!function_exists('dump')) {
    function dump(mixed $var, bool $exit = false): void {
        echo '<pre style="background: #f4f4f4; padding: 10px; border: 1px solid #ccc; margin: 10px; overflow: auto; font-family: monospace;">';
        
        if (is_array($var) || is_object($var)) {
            print_r($var);
        } else {
            var_dump($var);
        }
        
        echo '</pre>';
        
        if ($exit) {
            exit;
        }
    }
}

if (!function_exists('log_var')) {
    function log_var(mixed $var, string $label = ''): void {
        $log = '[' . date('Y-m-d H:i:s') . '] ';
        
        if ($label !== '') {
            $log .= $label . ': ';
        }
        
        $log .= print_r($var, true) . "\n";
        
        file_put_contents(dirname(__DIR__) . '/logs/variables.log', $log, FILE_APPEND);
    }
}

// =====================================================
// MISC HELPERS
// =====================================================

if (!function_exists('client_ip')) {
    function client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // Check for proxy headers
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP'];
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && $_SERVER[$header] !== '') {
                $ips = explode(',', $_SERVER[$header]);
                $candidate = trim($ips[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    $ip = $candidate;
                    break;
                }
            }
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ?: $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('user_agent')) {
    function user_agent(): string {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}

if (!function_exists('is_ajax')) {
    function is_ajax(): bool {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }
}

if (!function_exists('is_get')) {
    function is_get(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(string $action): string {
        $token = generateCSRFToken($action);
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">' . "\n" .
               '<input type="hidden" name="csrf_action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('table_status')) {
    function table_status(string $tableName): ?array {
        $db = Database::getInstance();
        $tableName = $db->escape($tableName);
        $result = $db->query("SHOW TABLE STATUS LIKE '$tableName'");
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return null;
    }
}

if (!function_exists('getBranchName')) {
    function getBranchName(int $branchId): string {
        static $branchNames = [];
        if (isset($branchNames[$branchId])) {
            return $branchNames[$branchId];
        }
        $db = Database::getInstance();
        $row = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$branchId]);
        $branchNames[$branchId] = $row['branch_name'] ?? 'Unknown';
        return $branchNames[$branchId];
    }
}

if (!function_exists('system_load')) {
    function system_load(): ?array {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => $load[0],
                '5min' => $load[1],
                '15min' => $load[2]
            ];
        }
        
        return null;
    }
}

// =====================================================
// INITIALIZATION
// =====================================================

// Ensure logs directory exists
$logsDir = dirname(__DIR__) . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Set timezone if configured
if (function_exists('setting')) {
    $timezone = setting('timezone', 'Africa/Lagos');
    if (is_string($timezone)) {
        @date_default_timezone_set($timezone);
    }
}