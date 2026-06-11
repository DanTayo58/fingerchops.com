<?php
// =====================================================
// MINIMAL DEBUG - order-management-debug.php
// Place this in: dashboards/staff/tools/
// =====================================================

// Turn on all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>DEBUG - Order Management</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e2e; color: #cbd5e1; }
        .error { color: #ef4444; background: #2a2a3a; padding: 10px; border-left: 4px solid #ef4444; margin: 10px 0; }
        .success { color: #10b981; }
        .info { color: #60a5fa; }
        pre { background: #0f0f1a; padding: 10px; overflow-x: auto; border-radius: 5px; }
        h1 { color: #f59e0b; }
    </style>
</head>
<body>
    <h1>🔍 DEBUG - Order Management Page</h1>
    <p>Current file: " . __FILE__ . "</p>
    <p>Current directory: " . __DIR__ . "</p>
    <hr>";

// =====================================================
// STEP 1: Check session start
// =====================================================
echo "<h2>1. Starting Session</h2>";
session_start();
echo "<p class='success'>✅ Session started. Session ID: " . session_id() . "</p>";

// =====================================================
// STEP 2: Try to find and include conn.php
// =====================================================
echo "<h2>2. Finding conn.php</h2>";

$possible_paths = [
    __DIR__ . '/conn.php',
    __DIR__ . '/../../conn.php',
    __DIR__ . '/../../../conn.php',
    dirname(__DIR__, 2) . '/conn.php',
    dirname(__DIR__, 3) . '/conn.php',
    'C:/wamp64/www/bakery proto/conn.php',
];

$conn_path = null;
foreach ($possible_paths as $path) {
    echo "<p>Checking: " . $path . " - " . (file_exists($path) ? "✅ EXISTS" : "❌ NOT FOUND") . "</p>";
    if (file_exists($path)) {
        $conn_path = $path;
        break;
    }
}

if (!$conn_path) {
    echo "<div class='error'>❌ CRITICAL: conn.php not found in any path!</div>";
    echo "</body></html>";
    exit;
}

echo "<p class='success'>✅ Found conn.php at: " . $conn_path . "</p>";
require_once $conn_path;

// =====================================================
// STEP 3: Test database connection
// =====================================================
echo "<h2>3. Testing Database Connection</h2>";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    if ($conn && !$conn->connect_error) {
        echo "<p class='success'>✅ Database connection successful</p>";
        echo "<p>Database: " . DB_NAME . "</p>";
    } else {
        echo "<div class='error'>❌ Database connection failed: " . ($conn ? $conn->connect_error : 'Unknown') . "</div>";
        echo "</body></html>";
        exit;
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Exception: " . $e->getMessage() . "</div>";
    echo "</body></html>";
    exit;
}

// =====================================================
// STEP 4: Test query
// =====================================================
echo "<h2>4. Testing Simple Query</h2>";

$test = $db->query("SELECT COUNT(*) as cnt FROM customer_orders");
if ($test) {
    $row = $test->fetch_assoc();
    echo "<p class='success'>✅ Query successful. Total orders: " . $row['cnt'] . "</p>";
} else {
    echo "<div class='error'>❌ Query failed: " . $conn->error . "</div>";
}

// =====================================================
// STEP 5: Try to include User.php
// =====================================================
echo "<h2>5. Loading User.php</h2>";

$user_path = dirname($conn_path) . '/includes/User.php';
if (!file_exists($user_path)) {
    $user_path = __DIR__ . '/../../includes/User.php';
}

echo "<p>Looking for User.php at: " . $user_path . " - " . (file_exists($user_path) ? "✅" : "❌") . "</p>";

if (file_exists($user_path)) {
    require_once $user_path;
    echo "<p class='success'>✅ User.php loaded</p>";
} else {
    echo "<div class='error'>❌ User.php not found</div>";
}

// =====================================================
// STEP 6: Check session user
// =====================================================
echo "<h2>6. Session User Check</h2>";

if (isset($_SESSION['user_id'])) {
    echo "<p class='success'>✅ User ID in session: " . $_SESSION['user_id'] . "</p>";
    
    // Try to get user data
    if (class_exists('User')) {
        try {
            $userObj = new User($_SESSION['user_id']);
            $user = $userObj->getData();
            if ($user) {
                echo "<p class='success'>✅ User found: " . ($user['fullname'] ?? 'Unknown') . " (Type: " . ($user['user_type'] ?? 'Unknown') . ")</p>";
            } else {
                echo "<div class='error'>❌ User not found in database</div>";
            }
        } catch (Exception $e) {
            echo "<div class='error'>❌ Error loading user: " . $e->getMessage() . "</div>";
        }
    } else {
        echo "<div class='error'>❌ User class not available</div>";
    }
} else {
    echo "<div class='error'>❌ No user_id in session! User is not logged in.</div>";
    echo "<p>Please log in first at: <a href='../../login_signup.php' style='color:#60a5fa;'>login_signup.php</a></p>";
}

// =====================================================
// STEP 7: Try to fetch orders
// =====================================================
echo "<h2>7. Fetching Orders</h2>";

if (isset($db)) {
    $orders = $db->query("
        SELECT o.id, o.order_number, o.total_amount, o.created_at, o.delivery_status,
               u.fullname as customer_name
        FROM customer_orders o
        JOIN bakery_users u ON o.user_id = u.id
        WHERE o.status = 'confirmed'
        LIMIT 5
    ");
    
    if ($orders && $orders->num_rows > 0) {
        echo "<p class='success'>✅ Found " . $orders->num_rows . " confirmed orders:</p>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Order #</th><th>Customer</th><th>Total</th><th>Delivery Status</th></tr>";
        while ($row = $orders->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['order_number']) . "</td>";
            echo "<td>" . htmlspecialchars($row['customer_name']) . "</td>";
            echo "<td>₦" . number_format($row['total_amount'], 2) . "</td>";
            echo "<td>" . ($row['delivery_status'] ?: 'pending') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>❌ No confirmed orders found</div>";
    }
}

// =====================================================
// STEP 8: Check for PHP errors
// =====================================================
echo "<h2>8. PHP Error Log</h2>";

$error_log = ini_get('error_log');
echo "<p>Error log location: " . ($error_log ?: 'Not set') . "</p>";

// Check for recent errors
if (function_exists('error_get_last')) {
    $last_error = error_get_last();
    if ($last_error) {
        echo "<div class='error'>Last PHP Error: " . print_r($last_error, true) . "</div>";
    } else {
        echo "<p class='success'>No recent PHP errors detected</p>";
    }
}

// =====================================================
// STEP 9: Summary
// =====================================================
echo "<h2>9. Summary</h2>";

$all_good = true;

if (!isset($_SESSION['user_id'])) {
    echo "<div class='error'>❌ You are NOT logged in. Please login first.</div>";
    $all_good = false;
}

if (!isset($db) || !$db->getConnection()) {
    echo "<div class='error'>❌ Database connection failed.</div>";
    $all_good = false;
}

if ($all_good) {
    echo "<div class='success'>✅ All checks passed! The order management page should work.</div>";
    echo "<p>Try accessing the actual page: <a href='order-management.php' style='color:#60a5fa;'>order-management.php</a></p>";
} else {
    echo "<div class='error'>❌ Issues detected. Please fix the above errors first.</div>";
}

echo "
<hr>
<p>Server Info: " . $_SERVER['SERVER_SOFTWARE'] . "</p>
<p>PHP Version: " . phpversion() . "</p>
</body>
</html>";