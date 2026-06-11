<?php
/**
 * customer-actions/orders.php - Enhanced Orders Page with Returns Integration
 * Version: 6.1 - Fixed HTML syntax errors and improved search logic
 */
session_start();

if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Session security
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}
if (!isset($_SESSION['user_agent']) || $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
    session_destroy();
    header('Location: /login_signup.php?error=security');
    exit;
}

require_once dirname(__DIR__) . '/../conn.php';
require_once dirname(__DIR__) . '/../includes/Auth.php';
require_once dirname(__DIR__) . '/../includes/User.php';
require_once dirname(__DIR__) . '/../includes/Helpers.php';
require_once dirname(__DIR__) . '/../includes/DashboardRouter.php';
require_once dirname(__DIR__) . '/../config/config_loader.php';

$auth = new Auth();
if (!$auth->validateSession()) {
    header('Location: ../login_signup.php');
    exit;
}
$user = $auth->getCurrentUser();
if (!$user) {
    $auth->logout();
    header('Location: /login_signup.php');
    exit;
}
if (!in_array($user['user_type'], ['customer', 'vendor'])) {
    $router = new DashboardRouter();
    $correctDashboard = $router->getDashboard(['id' => $user['id'], 'user_type' => $user['user_type']]);
    header('Location: ' . $correctDashboard);
    exit;
}

$userObj = new User($user['id']);
$userData = $userObj->getData();

$fullname = trim($user['fullname'] ?? '');
$first_name = !empty($fullname) ? explode(' ', $fullname)[0] : 'User';
$email = $user['email'] ?? '';
$phone = $user['phone'] ?? '';
$user_id_display = $user['user_id'] ?? '';
$member_since = $user['created_at'] ?? '';
$formatted_member_since = !empty($member_since) ? date('M Y', strtotime($member_since)) : 'Recent';

$db = Database::getInstance();

// CSRF token
if (!isset($_SESSION['user_csrf_token'])) {
    $_SESSION['user_csrf_token'] = generateSecureToken(64);
}
$csrf_token = $_SESSION['user_csrf_token'];

function verifyRequestToken($token) {
    return isset($_SESSION['user_csrf_token']) && hash_equals($_SESSION['user_csrf_token'], $token);
}

// Helper functions
function orderHasReceipt($order_id) {
    $receipt_dir = dirname(__DIR__) . '/images/receipts/';
    $extensions = ['jpg', 'jpeg', 'png', 'pdf'];
    foreach ($extensions as $ext) {
        if (file_exists($receipt_dir . $order_id . '.' . $ext)) {
            return true;
        }
    }
    return false;
}

function getReceiptPath($order_id) {
    $receipt_dir = dirname(__DIR__) . '/images/receipts/';
    $extensions = ['jpg', 'jpeg', 'png', 'pdf'];
    foreach ($extensions as $ext) {
        if (file_exists($receipt_dir . $order_id . '.' . $ext)) {
            return '../images/receipts/' . $order_id . '.' . $ext;
        }
    }
    return null;
}

// ==================== AJAX HANDLERS ====================
if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    header('Content-Type: application/json');
    
    if (!verifyRequestToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    // Get order receipt data for modal
    if ($action === 'get_receipt_data') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        
        $order = $db->preparedFetchOne("
            SELECT o.*, u.fullname as customer_name, u.user_id as customer_user_id, u.email as customer_email
            FROM customer_orders o
            JOIN bakery_users u ON o.user_id = u.id
            WHERE o.id = ? AND o.user_id = ?
        ", 'ii', [$order_id, $user['id']]);
        
        if ($order) {
            $items = $db->preparedFetchAll("
                SELECT oi.*, p.name as product_name 
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ", 'i', [$order_id]);
            $order['items'] = $items;
            
            $receipt_path = getReceiptPath($order_id);
            $order['receipt_path'] = $receipt_path;
            
            $response = ['success' => true, 'order' => $order];
        } else {
            $response['message'] = 'Order not found';
        }
        echo json_encode($response);
        exit;
    }

    // Update address
    if ($action === 'update_address') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $delivery_address = trim($_POST['delivery_address'] ?? '');
        $delivery_city = trim($_POST['delivery_city'] ?? '');
        $delivery_state = trim($_POST['delivery_state'] ?? '');
        $delivery_phone = trim($_POST['delivery_phone'] ?? '');

        if ($order_id <= 0 || empty($delivery_address)) {
            echo json_encode(['success' => false, 'message' => 'Invalid order or address']);
            exit;
        }

        $order = $db->preparedFetchOne("SELECT id, status FROM customer_orders WHERE id = ? AND user_id = ?", 'ii', [$order_id, $user['id']]);

        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }

        if ($order['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Cannot update address for processed orders']);
            exit;
        }

        $success = $db->preparedExecute(
            "UPDATE customer_orders SET delivery_address = ?, delivery_city = ?, delivery_state = ?, delivery_phone = ?, updated_at = NOW() WHERE id = ?",
            'ssssi', [$delivery_address, $delivery_city, $delivery_state, $delivery_phone, $order_id]
        );

        if ($success) {
            logActivity($user['id'], "Updated address for order #{$order_id}", 'order', $order_id);
            echo json_encode(['success' => true, 'message' => 'Address updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update address']);
        }
        exit;
    }

    // Upload receipt
    if ($action === 'upload_receipt') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id <= 0 || !isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Invalid request or no file uploaded']);
            exit;
        }

        $order = $db->preparedFetchOne("SELECT id, status FROM customer_orders WHERE id = ? AND user_id = ?", 'ii', [$order_id, $user['id']]);
        if (!$order || $order['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'Cannot upload receipt for this order']);
            exit;
        }

        $receipt_dir = dirname(__DIR__) . '/images/receipts/';
        if (!is_dir($receipt_dir)) mkdir($receipt_dir, 0755, true);

        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, PDF']);
            exit;
        }

        // Remove old receipt
        foreach (glob($receipt_dir . $order_id . '.*') as $old) @unlink($old);

        $filename = $order_id . '.' . $ext;
        if (move_uploaded_file($_FILES['receipt']['tmp_name'], $receipt_dir . $filename)) {
            logActivity($user['id'], "Uploaded receipt for order #{$order_id}", 'order', $order_id);
            echo json_encode(['success' => true, 'message' => 'Receipt uploaded successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to upload receipt']);
        }
        exit;
    }

    // Cancel order
    if ($action === 'cancel_order') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid order']);
            exit;
        }

        $order = $db->preparedFetchOne("SELECT id, status FROM customer_orders WHERE id = ? AND user_id = ?", 'ii', [$order_id, $user['id']]);
        if (!$order || $order['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'This order cannot be cancelled']);
            exit;
        }

        $success = $db->preparedExecute("UPDATE customer_orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?", 'i', [$order_id]);

        if ($success) {
            logActivity($user['id'], "Cancelled order #{$order_id}", 'order', $order_id);
            echo json_encode(['success' => true, 'message' => 'Order cancelled successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to cancel order']);
        }
        exit;
    }

    // Delete order
    if ($action === 'delete_order') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        if ($order_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid order']);
            exit;
        }

        $order = $db->preparedFetchOne("SELECT id, status FROM customer_orders WHERE id = ? AND user_id = ?", 'ii', [$order_id, $user['id']]);
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }

        if ($order['status'] !== 'cancelled') {
            echo json_encode(['success' => false, 'message' => 'Only cancelled orders can be deleted']);
            exit;
        }

        $db->beginTransaction();
        
        try {
            $db->preparedExecute("DELETE FROM order_items WHERE order_id = ?", 'i', [$order_id]);
            $success = $db->preparedExecute("DELETE FROM customer_orders WHERE id = ? AND user_id = ?", 'ii', [$order_id, $user['id']]);
            
            if ($success) {
                $receipt_dir = dirname(__DIR__) . '/images/receipts/';
                foreach (glob($receipt_dir . $order_id . '.*') as $old) @unlink($old);
                $db->commit();
                logActivity($user['id'], "Deleted cancelled order #{$order_id}", 'order', $order_id);
                echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
            } else {
                throw new Exception('Failed to delete order');
            }
        } catch (Exception $e) {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to delete order: ' . $e->getMessage()]);
        }
        exit;
    }

    // NEW: Get return details for an order
    if ($action === 'get_return_details') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $return = $db->preparedFetchOne("
            SELECT r.*, o.order_number
            FROM order_returns r
            JOIN customer_orders o ON r.order_id = o.id
            WHERE r.order_id = ? AND o.user_id = ?
        ", 'ii', [$order_id, $user['id']]);
        
        if ($return) {
            $items = $db->preparedFetchAll("
                SELECT ri.*, 
                       op.name as original_product_name,
                       np.name as new_product_name,
                       ria.resell_quantity,
                       ria.destroy_quantity
                FROM return_items ri
                LEFT JOIN products op ON ri.original_product_id = op.id
                LEFT JOIN products np ON ri.new_product_id = np.id
                LEFT JOIN return_inventory_actions ria ON ri.id = ria.return_item_id
                WHERE ri.return_id = ?
            ", 'i', [$return['id']]);
            $return['items'] = $items;
            $response = ['success' => true, 'return' => $return];
        } else {
            $response['message'] = 'No return found for this order.';
        }
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// ==================== FETCH ORDERS WITH RETURN INFO ====================
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql = "SELECT o.id, o.order_number, o.total_amount, o.status, o.created_at, o.payment_method, 
               o.delivery_address, o.delivery_city, o.delivery_state, o.delivery_phone,
               r.id as return_id, r.status as return_status, r.return_number
        FROM customer_orders o
        LEFT JOIN order_returns r ON o.id = r.order_id
        WHERE o.user_id = ?";
$params = [$user['id']];
$types = 'i';

if (!empty($search_term)) {
    $sql .= " AND (o.order_number LIKE ? OR o.delivery_address LIKE ? OR o.delivery_city LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if (!empty($status_filter) && $status_filter !== 'all') {
    $sql .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql .= " ORDER BY o.created_at DESC";

$orders = $db->preparedFetchAll($sql, $types, $params);

// Selected order detail
$selected_order = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $order_id = (int)$_GET['view'];
    $order = $db->preparedFetchOne("
        SELECT o.* FROM customer_orders o
        WHERE o.id = ? AND o.user_id = ?
    ", 'ii', [$order_id, $user['id']]);
    
    if ($order) {
        $items = $db->preparedFetchAll("
            SELECT oi.*, p.name as product_name 
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ", 'i', [$order_id]);
        $order['items'] = $items;
        $selected_order = $order;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fingerchops Ventures · My Orders</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/customer-dashboard.css">
    <link rel="stylesheet" href="css/orders.css">
    <link rel="icon" href="../logo.jpeg" type="image/jpeg">
    <style>
        /* Additional styles for return badge and modal */
        .return-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.7rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }
        .return-badge.pending { background: #fef3c7; color: #d97706; }
        .return-badge.approved { background: #d1fae5; color: #059669; }
        .return-badge.rejected { background: #fee2e2; color: #dc2626; }
        .return-badge.completed { background: #e0e7ff; color: #4f46e5; }
        .return-details-list {
            list-style: none;
            padding-left: 0;
        }
        .return-details-list li {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .return-details-list li:last-child {
            border-bottom: none;
        }
        .view-return-btn {
            background: none;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.85rem;
            padding: 0.2rem 0.5rem;
            border-radius: 1.5rem;
            transition: background 0.2s;
        }
        .view-return-btn:hover {
            background: rgba(0,0,0,0.05);
        }
        .loading-spinner {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
        .error-message {
            color: #dc2626;
            text-align: center;
            padding: 1rem;
        }
    </style>
</head>
<body>
    <div class="preloader" id="preloader">
        <div class="preloader-logo"></div>
    </div>

    <div class="app">
        <header class="main-header">
            <div class="header-left">
                <button class="profile-menu-btn" id="profileMenuBtn">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo">
                    <img src="../logo.jpeg" alt="Fingerchops Ventures" class="logo-img" onerror="this.src='../logo.jpeg'">
                    <span>Fingerchops Ventures</span>
                </div>
            </div>
            <div class="header-actions">
                <span class="user-greeting">👋 <?php echo htmlspecialchars($first_name); ?></span>
                <a href="../customer-dashboard.php" class="cart-button"><i class="fas fa-home"></i></a>
            </div>
        </header>

        <aside class="profile-sidebar" id="profileSidebar">
            <div class="profile-header">
                <div class="profile-close">
                    <button id="closeProfile"><i class="fas fa-times"></i></button>
                </div>
                <div class="profile-avatar">
                    <img src="../logo.jpeg" alt="Profile" onerror="this.src='../logo.jpeg'">
                </div>
                <h3><?php echo htmlspecialchars($fullname); ?></h3>
                <p class="profile-type">
                    <?php echo ucfirst($user['user_type']); ?> · Member since <?php echo $formatted_member_since; ?>
                </p>
                <?php if (!empty($email)): ?>
                    <p class="profile-contact"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($email); ?></p>
                <?php endif; ?>
                <?php if (!empty($phone)): ?>
                    <p class="profile-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone); ?></p>
                <?php endif; ?>
            </div>
            <div class="profile-menu">
                <a href="../customer-dashboard.php" class="profile-menu-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
                <a href="orders.php" class="profile-menu-item active"><i class="fas fa-shopping-bag"></i><span>My Orders</span></a>
                <a href="wishlist.php" class="profile-menu-item"><i class="fas fa-heart"></i><span>Wishlist</span></a>
                <a href="addresses.php" class="profile-menu-item"><i class="fas fa-map-marker-alt"></i><span>Delivery Addresses</span></a>
                <a href="payments.php" class="profile-menu-item"><i class="fas fa-credit-card"></i><span>Payment Methods</span></a>
                <a href="settings.php" class="profile-menu-item"><i class="fas fa-cog"></i><span>Account Settings</span></a>
                <a href="support.php" class="profile-menu-item"><i class="fas fa-headset"></i><span>Help & Support</span></a>
                <a href="../logout.php" class="profile-menu-item logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
            <div class="profile-footer">
                <p>Fingerchops Ventures © 2026</p>
                <p class="app-version">v4.0.0</p>
            </div>
        </aside>

        <div class="overlay" id="overlay"></div>

        <div class="orders-container">
            <div class="orders-header">
                <h2><i class="fas fa-shopping-bag"></i> My Orders</h2>
                <p>View and manage your order history</p>
            </div>
            
            <!-- User Info Card -->
            <div class="user-info-card">
                <div>
                    <i class="fas fa-user-circle"></i>
                    <span><?php echo htmlspecialchars($fullname); ?></span>
                </div>
                <div class="user-id-box">
                    <i class="fas fa-id-card"></i> 
                    <span id="user_id_display"><?php echo htmlspecialchars($user_id_display); ?></span>
                    <button onclick="copyUserId()" title="Copy User ID">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="search-bar">
                <input type="text" id="searchInput" class="search-input" placeholder="Search by order number, address, or city..." value="<?php echo htmlspecialchars($search_term); ?>">
                <select id="statusFilter" class="status-filter">
                    <option value="all" <?php echo $status_filter === 'all' || empty($status_filter) ? 'selected' : ''; ?>>All Orders</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
                <button class="search-btn" onclick="searchOrders()"><i class="fas fa-search"></i> Search</button>
                <button class="reset-btn" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
            </div>
            
            <?php if (!empty($orders)): ?>
                <div class="orders-table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Return</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): 
                                $has_receipt = orderHasReceipt($order['id']);
                                $is_cashier_order = strpos($order['order_number'], 'CAS-') === 0;
                            ?>
                                <tr>
                                    <td>
                                        <a href="?view=<?php echo $order['id']; ?>" class="order-link"><?php echo htmlspecialchars($order['order_number']); ?></a>
                                        <?php if ($is_cashier_order): ?>
                                            <span class="order-badge cashier-badge" style="background:#10b981; color:white; font-size:0.65rem; padding:0.15rem 0.4rem; border-radius:1rem; margin-left:0.5rem;">In-Store</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($order['created_at'])); ?></td>
                                    <td>₦<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $order['payment_method'] ?? 'N/A')); ?></td>
                                    <td><span class="order-status-badge status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span></td>
                                    <td>
                                        <?php if ($order['return_id']): ?>
                                            <button class="view-return-btn" onclick="viewReturnDetails(<?php echo $order['id']; ?>)" title="View Return">
                                                <i class="fas fa-exchange-alt"></i> 
                                                <span class="return-badge <?php echo $order['return_status']; ?>"><?php echo ucfirst($order['return_status']); ?></span>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-buttons">
                                        <button class="receipt-btn" onclick="viewOrderReceipt(<?php echo $order['id']; ?>)" title="View Receipt">
                                            <i class="fas fa-receipt"></i>
                                        </button>
                                        <?php if ($order['status'] === 'pending'): ?>
                                            <button class="edit-btn" onclick="editOrder(<?php echo $order['id']; ?>, '<?php echo addslashes($order['delivery_address'] ?? ''); ?>', '<?php echo addslashes($order['delivery_city'] ?? ''); ?>', '<?php echo addslashes($order['delivery_state'] ?? ''); ?>', '<?php echo addslashes($order['delivery_phone'] ?? ''); ?>')" title="Edit Address">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="receipt-btn" onclick="uploadReceipt(<?php echo $order['id']; ?>, <?php echo $has_receipt ? 'true' : 'false'; ?>)" title="Upload Receipt">
                                                <i class="fas fa-upload"></i>
                                            </button>
                                            <button class="cancel-btn" onclick="cancelOrder(<?php echo $order['id']; ?>)" title="Cancel Order">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($order['status'] === 'cancelled'): ?>
                                            <button class="cancel-btn" onclick="deleteOrder(<?php echo $order['id']; ?>)" title="Delete Order">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-cart-message">
                    <i class="fas fa-shopping-bag fa-3x"></i>
                    <p>No orders found matching your criteria.</p>
                    <?php if (!empty($search_term) || !empty($status_filter)): ?>
                        <a href="orders.php" class="btn-order">Clear Filters</a>
                    <?php else: ?>
                        <a href="../customer-dashboard.php" class="btn-order">Start Shopping</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Order Detail Section (shown when view parameter is present) -->
        <?php if ($selected_order): ?>
        <div class="order-detail-section">
            <div class="order-detail-header">
                <h3><i class="fas fa-receipt"></i> Order #<?php echo htmlspecialchars($selected_order['order_number']); ?></h3>
                <a href="orders.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to orders</a>
            </div>
            
            <div class="order-info-grid">
                <div><strong><i class="fas fa-calendar"></i> Date:</strong> <?php echo date('F j, Y g:i a', strtotime($selected_order['created_at'])); ?></div>
                <div><strong><i class="fas fa-tag"></i> Status:</strong> <span class="order-status-badge status-<?php echo $selected_order['status']; ?>"><?php echo ucfirst($selected_order['status']); ?></span></div>
                <div><strong><i class="fas fa-credit-card"></i> Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $selected_order['payment_method'] ?? 'N/A')); ?></div>
            </div>
            
            <div class="address-box">
                <h4><i class="fas fa-map-marker-alt"></i> Delivery Address</h4>
                <p><?php echo nl2br(htmlspecialchars($selected_order['delivery_address'] ?? 'Not provided')); ?></p>
                <?php if ($selected_order['delivery_city'] || $selected_order['delivery_state']): ?>
                    <p><strong><i class="fas fa-city"></i> City/State:</strong> <?php echo htmlspecialchars($selected_order['delivery_city'] ?? ''); ?>, <?php echo htmlspecialchars($selected_order['delivery_state'] ?? ''); ?></p>
                <?php endif; ?>
                <?php if ($selected_order['delivery_phone']): ?>
                    <p><strong><i class="fas fa-phone"></i> Phone:</strong> <?php echo htmlspecialchars($selected_order['delivery_phone']); ?></p>
                <?php endif; ?>
            </div>
            
            <h4><i class="fas fa-boxes"></i> Order Items</h4>
            <div class="order-items-wrapper">
                <table class="order-items-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($selected_order['items'] as $item): ?>
                         <tr>
                             <td><?php echo htmlspecialchars($item['product_name']); ?> </td>
                             <td><?php echo $item['quantity']; ?> </td>
                             <td>₦<?php echo number_format($item['unit_price'], 2); ?> </td>
                             <td>₦<?php echo number_format($item['subtotal'], 2); ?> </td>
                         </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="order-total">
                <strong>Total Amount:</strong> ₦<?php echo number_format($selected_order['total_amount'], 2); ?>
            </div>
            
            <div style="margin-top: 1rem;">
                <button class="btn-primary" onclick="viewOrderReceipt(<?php echo $selected_order['id']; ?>)">
                    <i class="fas fa-receipt"></i> View Full Receipt
                </button>
            </div>
            
            <?php if ($selected_order['status'] === 'pending'): ?>
                <div class="order-actions">
                    <button class="btn-cancel-order" onclick="cancelOrder(<?php echo $selected_order['id']; ?>)">
                        <i class="fas fa-times-circle"></i> Cancel Order
                    </button>
                </div>
            <?php elseif ($selected_order['status'] === 'cancelled'): ?>
                <div class="order-actions">
                    <button class="btn-delete-order" onclick="deleteOrder(<?php echo $selected_order['id']; ?>)">
                        <i class="fas fa-trash-alt"></i> Delete Order
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- MODALS -->
    
    <!-- Edit Address Modal -->
    <div id="editAddressModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Delivery Address</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit_order_id">
                <div class="form-group">
                    <label>Delivery Address <span class="required">*</span></label>
                    <textarea id="edit_address" rows="3" class="form-control" required></textarea>
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="edit_city" class="form-control">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <input type="text" id="edit_state" class="form-control">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="edit_phone" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button class="btn-primary" onclick="saveAddress()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Upload Receipt Modal -->
    <div id="uploadReceiptModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Upload Payment Receipt</h3>
                <button class="modal-close" onclick="closeReceiptModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="receipt_order_id">
                <div class="receipt-upload-area" onclick="document.getElementById('receipt_file').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Click to upload receipt</p>
                    <small>JPG, PNG, PDF (Max 5MB)</small>
                    <input type="file" id="receipt_file" accept="image/*,.pdf" style="display: none;">
                </div>
                <div id="receipt_preview" class="receipt-preview"></div>
                <div id="current_receipt_info" class="current-receipt"></div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeReceiptModal()">Cancel</button>
                <button class="btn-primary" onclick="saveReceipt()">Upload</button>
            </div>
        </div>
    </div>

    <!-- Cancel Confirmation Modal -->
    <div id="cancelConfirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Cancel Order</h3>
                <button class="modal-close" onclick="closeCancelModal()">×</button>
            </div>
            <div class="modal-body">
                <div class="warning-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <p class="modal-warning-text">
                    <strong>We do not do refunds.</strong><br>
                    Are you sure you want to cancel this order?
                </p>
                <small class="modal-subtext">This action cannot be undone.</small>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeCancelModal()">No, Keep Order</button>
                <button class="btn-primary" id="confirmCancelBtn">Yes, Cancel Order</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-trash-alt"></i> Delete Order</h3>
                <button class="modal-close" onclick="closeDeleteModal()">×</button>
            </div>
            <div class="modal-body">
                <p class="modal-warning-text"><strong> IRREVERSIBLE ACTION</strong><br>
                    Are you absolutely sure you want to delete this order record?
                </p>
                <p>This will permanently remove:</p>
                <ul style="text-align: left; margin: 10px 0 10px 20px; color: #666;">
                    <li>The order record throughout the system</li>
                    <li>All order items</li>
                    <li>Any uploaded receipt</li>
                </ul>
                <small class="modal-subtext" style="color: #dc3545;">This action is permanent and cannot be undone.</small>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn-primary" id="confirmDeleteBtn">Yes, Permanently Delete</button>
            </div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="receipt-modal">
            <div id="receiptContent" class="receipt-content"></div>
            <div class="receipt-actions">
                <button class="btn-print-receipt" onclick="printReceipt()"><i class="fas fa-print"></i> Print Receipt</button>
                <button class="btn-close-receipt" onclick="closeReceiptViewModal()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>

    <!-- Return Details Modal -->
    <div id="returnDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Return Details</h3>
                <button class="modal-close" onclick="closeReturnModal()">&times;</button>
            </div>
            <div class="modal-body" id="returnModalBody">
                <div class="loading-spinner">Loading...</div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeReturnModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <script>
        const csrfToken = '<?= $csrf_token ?>';
        let orderToCancel = null;
        let orderToDelete = null;
        let currentReceiptData = null;

        // ==================== RECEIPT MODAL FUNCTIONS ====================
        function viewOrderReceipt(orderId) {
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_receipt_data');
            formData.append('order_id', orderId);
            formData.append('csrf_token', csrfToken);
            
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        currentReceiptData = data.order;
                        renderReceiptModal(data.order);
                        document.getElementById('receiptModal').classList.add('show');
                        document.body.style.overflow = 'hidden';
                    } else {
                        showToast(data.message || 'Failed to load receipt', 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showToast('Failed to load receipt', 'error');
                });
        }
        
        function renderReceiptModal(order) {
            const date = new Date(order.created_at);
            const receiptHtml = `
                <div class="receipt-header">
                    <h3>FINGERCHOPS VENTURES</h3>
                    <p>${date.toLocaleDateString()} ${date.toLocaleTimeString()}</p>
                    <p><strong>${order.order_number}</strong></p>
                    <p>Customer: ${escapeHtml(order.customer_name)}<br>ID: ${escapeHtml(order.customer_user_id)}</p>
                    <p>Payment: ${order.payment_method ? order.payment_method.toUpperCase() : 'N/A'}</p>
                </div>
                <div class="receipt-divider"></div>
                ${order.items.map(item => `
                    <div class="receipt-item">
                        <span class="receipt-item-name">${escapeHtml(item.product_name)}</span>
                        <span class="receipt-item-qty">x${item.quantity}</span>
                        <span class="receipt-item-price">₦${parseFloat(item.subtotal).toFixed(2)}</span>
                    </div>
                `).join('')}
                <div class="receipt-divider"></div>
                <div class="receipt-total-row">
                    <span>SUBTOTAL:</span>
                    <span>₦${parseFloat(order.total_amount).toFixed(2)}</span>
                </div>
                ${order.delivery_fee > 0 ? `
                <div class="receipt-total-row">
                    <span>DELIVERY FEE:</span>
                    <span>₦${parseFloat(order.delivery_fee).toFixed(2)}</span>
                </div>
                ` : ''}
                <div class="receipt-total-row">
                    <span>TOTAL:</span>
                    <span>₦${parseFloat(order.total_amount).toFixed(2)}</span>
                </div>
                <div class="receipt-footer">
                    <p>Thank you for your patronage!</p>
                    <p>Fingerchops Ventures - Quality Baked Goods</p>
                    ${order.receipt_path ? `<a href="${order.receipt_path}" target="_blank" class="receipt-image-link"><i class="fas fa-image"></i> View Uploaded Receipt Image</a>` : ''}
                </div>
            `;
            document.getElementById('receiptContent').innerHTML = receiptHtml;
        }
        
        function closeReceiptViewModal() {
            document.getElementById('receiptModal').classList.remove('show');
            document.body.style.overflow = '';
            currentReceiptData = null;
        }
        
        function printReceipt() {
            const receiptContent = document.getElementById('receiptContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Receipt - Fingerchops Ventures</title>
                    <style>
                        body { font-family: monospace; padding: 20px; max-width: 300px; margin: 0 auto; }
                        .receipt-header { text-align: center; margin-bottom: 20px; }
                        .receipt-divider { border-top: 1px dashed #000; margin: 10px 0; }
                        .receipt-item { display: flex; justify-content: space-between; margin-bottom: 5px; }
                        .receipt-total-row { display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #000; font-weight: bold; }
                        .receipt-footer { text-align: center; margin-top: 20px; font-size: 10px; }
                    </style>
                </head>
                <body>${receiptContent}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // ==================== CANCEL ORDER MODAL ====================
        function showCancelConfirmation(orderId) {
            orderToCancel = orderId;
            document.getElementById('cancelConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeCancelModal() {
            document.getElementById('cancelConfirmModal').classList.remove('show');
            document.body.style.overflow = '';
            orderToCancel = null;
        }

        document.getElementById('confirmCancelBtn').addEventListener('click', function() {
            if (!orderToCancel) return;

            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'cancel_order');
            formData.append('order_id', orderToCancel);
            formData.append('csrf_token', csrfToken);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeCancelModal();
                        setTimeout(() => window.location.reload(), 1200);
                    } else {
                        showToast(data.message || 'Failed to cancel order', 'error');
                    }
                })
                .catch(() => showToast('Network error. Please try again.', 'error'));
        });

        // ==================== DELETE ORDER MODAL ====================
        function showDeleteConfirmation(orderId) {
            orderToDelete = orderId;
            document.getElementById('deleteConfirmModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDeleteModal() {
            document.getElementById('deleteConfirmModal').classList.remove('show');
            document.body.style.overflow = '';
            orderToDelete = null;
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (!orderToDelete) return;

            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'delete_order');
            formData.append('order_id', orderToDelete);
            formData.append('csrf_token', csrfToken);

            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeDeleteModal();
                        setTimeout(() => window.location.reload(), 1200);
                    } else {
                        showToast(data.message || 'Failed to delete order', 'error');
                    }
                })
                .catch(() => showToast('Network error. Please try again.', 'error'));
        });

        // ==================== SIDEBAR & UTILITIES ====================
        const profileSidebar = document.getElementById('profileSidebar');
        const profileMenuBtn = document.getElementById('profileMenuBtn');
        const closeProfile = document.getElementById('closeProfile');
        const overlay = document.getElementById('overlay');

        profileMenuBtn.addEventListener('click', () => { profileSidebar.classList.add('open'); overlay.classList.add('active'); });
        closeProfile.addEventListener('click', () => { profileSidebar.classList.remove('open'); overlay.classList.remove('active'); });
        overlay.addEventListener('click', () => { profileSidebar.classList.remove('open'); overlay.classList.remove('active'); });

        function showToast(message, type = 'info', duration = 3000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            let icon = 'fa-info-circle';
            if (type === 'success') icon = 'fa-check-circle';
            if (type === 'error') icon = 'fa-exclamation-circle';
            toast.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        function copyUserId() {
            const userId = document.getElementById('user_id_display').innerText;
            navigator.clipboard.writeText(userId).then(() => {
                showToast('User ID copied to clipboard!', 'success');
            }).catch(() => {
                showToast('Failed to copy', 'error');
            });
        }

        function searchOrders() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            
            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (status && status !== 'all') params.set('status', status);
            
            const queryString = params.toString();
            window.location.href = queryString ? 'orders.php?' + queryString : 'orders.php';
        }

        function resetFilters() {
            window.location.href = 'orders.php';
        }

        let currentOrderId = 0;
        
        function editOrder(id, address, city, state, phone) {
            currentOrderId = id;
            document.getElementById('edit_order_id').value = id;
            document.getElementById('edit_address').value = address || '';
            document.getElementById('edit_city').value = city || '';
            document.getElementById('edit_state').value = state || '';
            document.getElementById('edit_phone').value = phone || '';
            document.getElementById('editAddressModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeEditModal() {
            document.getElementById('editAddressModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        function saveAddress() {
            const orderId = document.getElementById('edit_order_id').value;
            const address = document.getElementById('edit_address').value;
            const city = document.getElementById('edit_city').value;
            const state = document.getElementById('edit_state').value;
            const phone = document.getElementById('edit_phone').value;
            
            if (!address) {
                showToast('Delivery address is required', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'update_address');
            formData.append('order_id', orderId);
            formData.append('delivery_address', address);
            formData.append('delivery_city', city);
            formData.append('delivery_state', state);
            formData.append('delivery_phone', phone);
            formData.append('csrf_token', csrfToken);
            
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeEditModal();
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => showToast('Network error', 'error'));
        }
        
        let hasExistingReceipt = false;
        
        function uploadReceipt(id, hasReceipt) {
            currentOrderId = id;
            hasExistingReceipt = hasReceipt;
            document.getElementById('receipt_order_id').value = id;
            document.getElementById('receipt_file').value = '';
            document.getElementById('receipt_preview').innerHTML = '';
            document.getElementById('receipt_preview').style.display = 'none';
            
            const infoDiv = document.getElementById('current_receipt_info');
            if (hasReceipt) {
                infoDiv.innerHTML = '<i class="fas fa-info-circle"></i> A receipt already exists. Uploading a new one will replace it.';
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
            
            document.getElementById('uploadReceiptModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        
        function closeReceiptModal() {
            document.getElementById('uploadReceiptModal').classList.remove('show');
            document.body.style.overflow = '';
        }
        
        document.getElementById('receipt_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('receipt_preview');
            if (file) {
                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        preview.innerHTML = `<img src="${e.target.result}" style="max-width: 100%; max-height: 150px; border-radius: 8px;">`;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    preview.innerHTML = `<p><i class="fas fa-file-pdf"></i> ${file.name}</p>`;
                    preview.style.display = 'block';
                }
            }
        });
        
        function saveReceipt() {
            const fileInput = document.getElementById('receipt_file');
            const orderId = document.getElementById('receipt_order_id').value;
            
            if (!fileInput.files || !fileInput.files[0]) {
                showToast('Please select a file to upload', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'upload_receipt');
            formData.append('order_id', orderId);
            formData.append('receipt', fileInput.files[0]);
            formData.append('csrf_token', csrfToken);
            
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message, 'success');
                        closeReceiptModal();
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => showToast('Network error', 'error'));
        }
        
        function cancelOrder(orderId) {
            showCancelConfirmation(orderId);
        }
        
        function deleteOrder(orderId) {
            showDeleteConfirmation(orderId);
        }

        // ==================== RETURN DETAILS FUNCTIONS ====================
        async function viewReturnDetails(orderId) {
            const modal = document.getElementById('returnDetailsModal');
            const body = document.getElementById('returnModalBody');
            body.innerHTML = '<div class="loading-spinner">Loading...</div>';
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';

            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_return_details');
            formData.append('order_id', orderId);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                if (data.success) {
                    renderReturnDetails(data.return);
                } else {
                    body.innerHTML = `<p class="error-message">${escapeHtml(data.message)}</p>`;
                }
            } catch (err) {
                body.innerHTML = '<p class="error-message">Failed to load return details.</p>';
            }
        }

        function renderReturnDetails(returnData) {
            const date = new Date(returnData.created_at);
            const processedDate = returnData.processed_at ? new Date(returnData.processed_at).toLocaleString() : 'Not processed yet';
            
            let itemsHtml = '';
            for (const item of returnData.items) {
                const resellDestroy = (item.resell_quantity !== null && item.destroy_quantity !== null) 
                    ? `<br><small><i class="fas fa-archive"></i> Inventory: Resell ${item.resell_quantity}, Destroy ${item.destroy_quantity}</small>` 
                    : (item.inventory_action ? `<br><small><i class="fas fa-archive"></i> Inventory: ${item.inventory_action}</small>` : '');
                
                const swapInfo = (item.action_type === 'swap' && item.new_product_name) 
                    ? `<br><small><i class="fas fa-exchange-alt"></i> Swapped with: ${escapeHtml(item.new_product_name)} (${item.new_quantity} x ₦${parseFloat(item.new_unit_price).toFixed(2)})</small>` 
                    : '';
                
                itemsHtml += `
                    <li>
                        <strong>${escapeHtml(item.original_product_name)}</strong><br>
                        Returned: ${item.returned_quantity} pcs (Original purchased: ${item.original_quantity})<br>
                        Reason: ${escapeHtml(item.reason || 'Not specified')}
                        ${swapInfo}
                        ${resellDestroy}
                    </li>
                `;
            }
            
            const html = `
                <div style="margin-bottom: 1rem;">
                    <strong>Return #:</strong> ${escapeHtml(returnData.return_number)}<br>
                    <strong>Order #:</strong> ${escapeHtml(returnData.order_number)}<br>
                    <strong>Request Date:</strong> ${date.toLocaleString()}<br>
                    <strong>Status:</strong> <span class="order-status-badge status-${returnData.status}">${ucfirst(returnData.status)}</span><br>
                    <strong>Type:</strong> ${returnData.return_type === 'swap' ? 'Swap Products' : 'Change Item'}<br>
                    <strong>Processed At:</strong> ${processedDate}
                </div>
                ${returnData.notes ? `<div><strong>Notes:</strong> ${escapeHtml(returnData.notes)}</div>` : ''}
                <div><strong>Items:</strong>
                    <ul class="return-details-list">
                        ${itemsHtml}
                    </ul>
                </div>
                <div>
                    <strong>Financial Summary:</strong><br>
                    Refund: ₦${parseFloat(returnData.total_refund_amount).toFixed(2)}<br>
                    Additional Payment: ₦${parseFloat(returnData.total_additional_payment).toFixed(2)}
                </div>
            `;
            document.getElementById('returnModalBody').innerHTML = html;
        }

        function closeReturnModal() {
            document.getElementById('returnDetailsModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // Close modals when clicking outside or pressing Escape
        window.addEventListener('click', function(e) {
            const editModal = document.getElementById('editAddressModal');
            const uploadModal = document.getElementById('uploadReceiptModal');
            const cancelModal = document.getElementById('cancelConfirmModal');
            const deleteModal = document.getElementById('deleteConfirmModal');
            const receiptModal = document.getElementById('receiptModal');
            const returnModal = document.getElementById('returnDetailsModal');
            if (e.target === editModal) closeEditModal();
            if (e.target === uploadModal) closeReceiptModal();
            if (e.target === cancelModal) closeCancelModal();
            if (e.target === deleteModal) closeDeleteModal();
            if (e.target === receiptModal) closeReceiptViewModal();
            if (e.target === returnModal) closeReturnModal();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (document.getElementById('editAddressModal').classList.contains('show')) closeEditModal();
                if (document.getElementById('uploadReceiptModal').classList.contains('show')) closeReceiptModal();
                if (document.getElementById('cancelConfirmModal').classList.contains('show')) closeCancelModal();
                if (document.getElementById('deleteConfirmModal').classList.contains('show')) closeDeleteModal();
                if (document.getElementById('receiptModal').classList.contains('show')) closeReceiptViewModal();
                if (document.getElementById('returnDetailsModal').classList.contains('show')) closeReturnModal();
            }
        });

        window.addEventListener('load', function() {
            setTimeout(function() { document.getElementById('preloader').classList.add('fade-out'); }, 500);
        });
        
        // Global functions for inline onclick
        window.viewOrderReceipt = viewOrderReceipt;
        window.printReceipt = printReceipt;
        window.closeReceiptViewModal = closeReceiptViewModal;
        window.editOrder = editOrder;
        window.closeEditModal = closeEditModal;
        window.saveAddress = saveAddress;
        window.uploadReceipt = uploadReceipt;
        window.closeReceiptModal = closeReceiptModal;
        window.saveReceipt = saveReceipt;
        window.cancelOrder = cancelOrder;
        window.deleteOrder = deleteOrder;
        window.viewReturnDetails = viewReturnDetails;
        window.closeReturnModal = closeReturnModal;
        window.searchOrders = searchOrders;
        window.resetFilters = resetFilters;
        window.copyUserId = copyUserId;
    </script>
</body>
</html>