<?php
// =====================================================
// FILE: dashboards/staff/tools/sales-history.php
// VERSION: 18.2 - FINAL: Full features + inventory breakdown
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$root_path = dirname(__DIR__, 3) . '/';
require_once $root_path . 'conn.php';
require_once $root_path . 'includes/User.php';
require_once $root_path . 'includes/Security.php';

$db = Database::getInstance();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $root_path . 'login_signup.php');
    exit;
}

$userObj = new User($_SESSION['user_id']);
$user = $userObj->getData();

if (!$user) {
    header('Location: ' . $root_path . 'login_signup.php');
    exit;
}

$user_branch_id = $user['branch_id'] ?? 1;
$branch_info = $db->preparedFetchOne("SELECT branch_name, branch_code FROM branches WHERE id = ?", 'i', [$user_branch_id]);
$branch_name = $branch_info['branch_name'] ?? 'Main Branch';
$branch_code = $branch_info['branch_code'] ?? 'HQ';
$is_headquarters = ($user_branch_id == 1);
$user_privilege_level = $userObj->getPrivilegeLevel();
$can_process_returns = ($user_privilege_level >= 50);

$all_branches = [];
if ($is_headquarters) {
    $all_branches = $db->preparedFetchAll("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name", '', []);
}

$selected_branch_id = isset($_GET['branch']) ? (int)$_GET['branch'] : $user_branch_id;
if (!$is_headquarters) {
    $selected_branch_id = $user_branch_id;
}

if (!isset($_SESSION['sales_history_csrf_token'])) {
    $_SESSION['sales_history_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['sales_history_csrf_token'];

// Error handler for debugging
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['success' => false, 'message' => "PHP Error: $errstr in $errfile:$errline"]);
    exit;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode(['success' => false, 'message' => "Fatal: {$error['message']} in {$error['file']}:{$error['line']}"]);
        exit;
    }
});

// =====================================================
// AJAX HANDLERS
// =====================================================
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['sales_history_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_delivery') {
        $order_id = intval($_POST['order_id'] ?? 0);
        $delivery_status = $_POST['delivery_status'] ?? '';
        $allowed = ['pending', 'delivered', 'not_delivered', 'returned'];
        if (!in_array($delivery_status, $allowed)) {
            $response['message'] = 'Invalid status';
            echo json_encode($response);
            exit;
        }
        $success = $db->preparedExecute("UPDATE customer_orders SET delivery_status = ?, delivered_at = CASE WHEN ? = 'delivered' THEN NOW() ELSE delivered_at END WHERE id = ?", 'ssi', [$delivery_status, $delivery_status, $order_id]);
        $response['success'] = $success;
        $response['message'] = $success ? 'Delivery status updated' : 'Update failed';
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'search_orders') {
        $term = trim($_POST['term'] ?? '');
        if (strlen($term) < 2) {
            $response['success'] = true;
            $response['orders'] = [];
            echo json_encode($response);
            exit;
        }
        $searchTerm = '%' . $term . '%';
        $orders = $db->preparedFetchAll("
            SELECT o.id, o.order_number, o.total_amount, o.created_at, u.fullname as customer_name 
            FROM customer_orders o 
            JOIN bakery_users u ON o.user_id = u.id 
            WHERE o.status = 'confirmed' 
              AND (o.order_number LIKE ? OR u.fullname LIKE ? OR u.user_id LIKE ?)
            ORDER BY o.created_at DESC LIMIT 15
        ", 'sss', [$searchTerm, $searchTerm, $searchTerm]);
        $response['success'] = true;
        $response['orders'] = $orders;
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'get_order_items') {
        $order_id = intval($_POST['order_id'] ?? 0);
        $order = $db->preparedFetchOne("SELECT o.id, o.order_number, u.fullname as customer_name FROM customer_orders o JOIN bakery_users u ON o.user_id = u.id WHERE o.id = ? AND o.status = 'confirmed'", 'i', [$order_id]);
        if (!$order) {
            $response['message'] = 'Order not found';
            echo json_encode($response);
            exit;
        }
        $items = $db->preparedFetchAll("SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?", 'i', [$order_id]);
        $order['items'] = $items;
        $response['success'] = true;
        $response['order'] = $order;
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'search_products') {
        $term = trim($_POST['term'] ?? '');
        if (strlen($term) < 2) {
            $response['success'] = true;
            $response['products'] = [];
            echo json_encode($response);
            exit;
        }
        $searchTerm = '%' . $term . '%';
        $products = $db->preparedFetchAll("
            SELECT id, name, base_price 
            FROM products 
            WHERE is_active = 1 AND (name LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY name ASC LIMIT 20
        ", 'ss', [$searchTerm, $searchTerm]);
        $response['success'] = true;
        $response['products'] = $products;
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'create_return') {
        error_log("=== CREATE RETURN START ===");
        $order_id = intval($_POST['order_id'] ?? 0);
        $return_type = $_POST['return_type'] ?? 'change_item';
        $return_items = json_decode($_POST['return_items'] ?? '[]', true);
        $additional_products = json_decode($_POST['additional_products'] ?? '[]', true);
        $notes = trim($_POST['notes'] ?? '');
        
        if (!$order_id || empty($return_items)) {
            $response['message'] = 'Missing required fields';
            echo json_encode($response);
            exit;
        }
        
        $return_number = 'RET-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $total_refund = 0;
        $total_additional = 0;
        
        foreach ($return_items as $item) {
            $total_refund += floatval($item['return_subtotal']);
            if ($item['action_type'] === 'swap' && isset($item['new_subtotal'])) {
                $total_additional += floatval($item['new_subtotal']);
            }
        }
        foreach ($additional_products as $product) {
            $total_additional += floatval($product['subtotal']);
        }
        
        $db->beginTransaction();
        try {
            $result = $db->preparedExecute("
                INSERT INTO order_returns (order_id, return_number, return_type, status, total_refund_amount, total_additional_payment, created_by, notes, created_at)
                VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, NOW())
            ", 'issddis', [$order_id, $return_number, $return_type, $total_refund, $total_additional, $_SESSION['user_id'], $notes]);
            if (!$result) throw new Exception("Failed to insert into order_returns");
            $return_id = $db->lastInsertId();
            error_log("Return ID: $return_id");
            
            foreach ($return_items as $index => $item) {
                $orig_qty = intval($item['original_quantity']);
                $orig_price = floatval($item['original_unit_price']);
                $orig_subtotal = floatval($item['original_subtotal']);
                $return_qty = intval($item['return_quantity']);
                $return_subtotal = floatval($item['return_subtotal']);
                $new_product_id = isset($item['new_product_id']) && $item['new_product_id'] ? intval($item['new_product_id']) : null;
                $new_qty = isset($item['new_quantity']) ? intval($item['new_quantity']) : $return_qty;
                $new_price = isset($item['new_unit_price']) ? floatval($item['new_unit_price']) : $orig_price;
                $new_subtotal = isset($item['new_subtotal']) ? floatval($item['new_subtotal']) : ($new_price * $new_qty);
                $price_diff = floatval($new_subtotal - $return_subtotal);
                $action_type = $item['action_type'];
                $reason = $item['reason'] ?? '';
                
                $itemResult = $db->preparedExecute("
                    INSERT INTO return_items (
                        return_id, original_order_item_id, original_product_id, original_quantity,
                        original_unit_price, original_subtotal, new_product_id, returned_quantity,
                        new_unit_price, new_subtotal, price_difference, action_type, reason, inventory_action
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
                ", 'sssssssssssss', [
                    $return_id, $item['original_order_item_id'], $item['original_product_id'],
                    $orig_qty, $orig_price, $orig_subtotal, $new_product_id, $new_qty,
                    $new_price, $new_subtotal, $price_diff, $action_type, $reason
                ]);
                if (!$itemResult) throw new Exception("Failed to insert return item $index");
            }
            $db->commit();
            $response['success'] = true;
            $response['message'] = 'Return request created successfully.';
        } catch (Exception $e) {
            $db->rollback();
            error_log("ERROR in create_return: " . $e->getMessage());
            $response['success'] = false;
            $response['message'] = 'Database error: ' . $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'process_return') {
        $return_id = intval($_POST['return_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $response_notes = trim($_POST['response_notes'] ?? '');
        $inventory_actions = json_decode($_POST['inventory_actions'] ?? '[]', true);
        
        $allowed = ['approved', 'rejected'];
        if (!in_array($status, $allowed)) {
            $response['message'] = 'Invalid status';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("UPDATE order_returns SET status = ?, processed_at = NOW(), processed_by = ?, notes = CONCAT(COALESCE(notes, ''), '\n[Response: ', ?, ']') WHERE id = ?", 'siss', [$status, $_SESSION['user_id'], $response_notes, $return_id]);
            
            if ($status === 'approved') {
                foreach ($inventory_actions as $action) {
                    $return_item_id = intval($action['return_item_id']);
                    $resell_qty = intval($action['resell']);
                    $destroy_qty = intval($action['destroy']);
                    $itemCheck = $db->preparedFetchOne("SELECT returned_quantity FROM return_items WHERE id = ? AND return_id = ?", 'ii', [$return_item_id, $return_id]);
                    if (!$itemCheck || ($resell_qty + $destroy_qty) != $itemCheck['returned_quantity']) {
                        throw new Exception("Invalid quantities for item ID $return_item_id");
                    }
                    $db->preparedExecute("
                        INSERT INTO return_inventory_actions (return_item_id, resell_quantity, destroy_quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE resell_quantity = VALUES(resell_quantity), destroy_quantity = VALUES(destroy_quantity)
                    ", 'iii', [$return_item_id, $resell_qty, $destroy_qty]);
                }
            }
            $db->commit();
            $response['success'] = true;
            $response['message'] = "Return {$status} successfully";
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'get_return_items_for_processing') {
        $return_id = intval($_POST['return_id'] ?? 0);
        $items = $db->preparedFetchAll("
            SELECT ri.id, ri.returned_quantity, p.name as original_product_name
            FROM return_items ri
            JOIN products p ON ri.original_product_id = p.id
            WHERE ri.return_id = ?
        ", 'i', [$return_id]);
        $response['success'] = true;
        $response['items'] = $items;
        echo json_encode($response);
        exit;
    }
    
    $response['message'] = 'Invalid action';
    echo json_encode($response);
    exit;
}

// =====================================================
// GET DATA
// =====================================================
$orders_query = $db->preparedFetchAll("
    SELECT o.id, o.order_number, o.total_amount, o.created_at, o.delivery_status,
           u.fullname as customer_name,
           CASE WHEN o.order_number LIKE 'CAS-%' THEN 'onsite' ELSE 'online' END as order_source
    FROM customer_orders o
    JOIN bakery_users u ON o.user_id = u.id
    WHERE o.status = 'confirmed'
      AND (o.delivery_branch_id = ? OR o.delivery_branch_id IS NULL)
    ORDER BY o.created_at DESC
", 'i', [$selected_branch_id]);

foreach ($orders_query as $key => $order) {
    $items = $db->preparedFetchAll("
        SELECT oi.*, p.name as product_name
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ", 'i', [$order['id']]);
    $orders_query[$key]['items'] = $items;
}

$returns_query = $db->preparedFetchAll("
    SELECT r.*, o.order_number, u.fullname as customer_name,
           p.fullname as processed_by_name
    FROM order_returns r
    JOIN customer_orders o ON r.order_id = o.id
    JOIN bakery_users u ON o.user_id = u.id
    LEFT JOIN bakery_users p ON r.processed_by = p.id
    WHERE (o.delivery_branch_id = ? OR o.delivery_branch_id IS NULL)
    ORDER BY r.created_at DESC
", 'i', [$selected_branch_id]);

foreach ($returns_query as $key => $return) {
    $items = $db->preparedFetchAll("
        SELECT ri.*, 
               op.name as original_product_name,
               np.name as new_product_name,
               ri.returned_quantity,
               ri.reason as item_reason,
               ri.inventory_action,
               ri.original_quantity as original_purchased_quantity,
               ri.action_type,
               ri.new_unit_price,
               ri.new_subtotal,
               ria.resell_quantity,
               ria.destroy_quantity
        FROM return_items ri
        LEFT JOIN products op ON ri.original_product_id = op.id
        LEFT JOIN products np ON ri.new_product_id = np.id
        LEFT JOIN return_inventory_actions ria ON ri.id = ria.return_item_id
        WHERE ri.return_id = ?
    ", 'i', [$return['id']]);
    $returns_query[$key]['items'] = $items;
}

$products_query = $db->preparedFetchAll("SELECT id, name, base_price FROM products WHERE is_active = 1 ORDER BY name", '', []);
$products_json = json_encode($products_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales History · Fingerchops Ventures</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/sales-history.css">
    <link rel="icon" href="../../../logo.jpeg" type="image/jpeg">
</head>
<body>
    <div class="preloader" id="preloader"><div class="preloader-spinner"></div><div class="preloader-text">Processing...</div></div>
    <div class="container">
        <h1><i class="fas fa-chart-line"></i> Sales History</h1>
        <div class="user-info">
            <div><i class="fas fa-user-circle"></i> <strong><?php echo htmlspecialchars($user['fullname']); ?></strong> · Level <?php echo $user_privilege_level; ?></div>
            <div class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?> (<?php echo htmlspecialchars($branch_code); ?>)</div>
        </div>
        <?php if ($is_headquarters && !empty($all_branches)): ?>
        <div class="branch-filter-bar">
            <label><i class="fas fa-store"></i> Filter by Branch:</label>
            <select id="branchFilterSelect" class="branch-filter-select">
                <option value="0">All Branches</option>
                <?php foreach ($all_branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch_id == $branch['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button id="applyBranchFilter" class="apply-filter-btn">Apply</button>
        </div>
        <?php endif; ?>
        <div class="tabs">
            <button class="tab active" data-tab="history">Sales History</button>
            <button class="tab" data-tab="returns">Returns & Exchanges</button>
        </div>
        <!-- Sales History Tab -->
        <div id="history-tab" class="tab-content active">
            <div class="filter-bar">
                <div class="filter-group"><label><i class="fas fa-tag"></i> Source</label><select id="sourceFilter"><option value="all">All</option><option value="online">Online</option><option value="onsite">Onsite</option></select></div>
                <div class="filter-group"><label><i class="fas fa-truck"></i> Delivery Status</label><select id="deliveryFilter"><option value="all">All</option><option value="pending">Pending</option><option value="delivered">Delivered</option><option value="not_delivered">Not Delivered</option></select></div>
                <div class="filter-group"><label><i class="fas fa-calendar"></i> Date From</label><input type="date" id="dateFrom"></div>
                <div class="filter-group"><label><i class="fas fa-calendar"></i> Date To</label><input type="date" id="dateTo"></div>
                <div class="filter-group search-group"><label><i class="fas fa-search"></i> Search</label><input type="text" id="searchInput" placeholder="Order # or customer name..."></div>
                <button id="resetFiltersBtn" class="btn-secondary"><i class="fas fa-undo-alt"></i> Reset</button>
            </div>
            <div class="stats">
                <div class="stat-card"><i class="fas fa-globe"></i><span>Online Orders</span><strong id="onlineCount">0</strong></div>
                <div class="stat-card"><i class="fas fa-store"></i><span>Onsite Sales</span><strong id="onsiteCount">0</strong></div>
                <div class="stat-card"><i class="fas fa-chart-bar"></i><span>Total Transactions</span><strong id="totalCount">0</strong></div>
            </div>
            <div id="ordersContainer">
                <?php foreach ($orders_query as $order): ?>
                <div class="order-card" data-order-id="<?php echo $order['id']; ?>" data-source="<?php echo $order['order_source']; ?>" data-delivery="<?php echo $order['delivery_status'] ?: 'pending'; ?>" data-date="<?php echo date('Y-m-d', strtotime($order['created_at'])); ?>" data-order-number="<?php echo htmlspecialchars($order['order_number']); ?>" data-customer="<?php echo htmlspecialchars($order['customer_name']); ?>">
                    <div class="order-header" onclick="toggleOrder(this)">
                        <div class="order-info"><span class="order-number">#<?php echo htmlspecialchars($order['order_number']); ?></span><span class="order-date"><i class="far fa-calendar-alt"></i> <?php echo date('M j, Y', strtotime($order['created_at'])); ?></span><span class="order-customer"><i class="fas fa-user"></i> <?php echo htmlspecialchars($order['customer_name']); ?></span><span class="delivery-badge status-<?php echo $order['delivery_status'] ?: 'pending'; ?>"><?php echo ucfirst($order['delivery_status'] ?: 'Pending'); ?></span></div>
                        <div class="order-total">₦<?php echo number_format($order['total_amount'], 2); ?> <span class="expand-icon">▼</span></div>
                    </div>
                    <div class="order-body">
                        <div class="delivery-update"><select class="delivery-select"><option value="pending" <?php echo ($order['delivery_status'] == 'pending') ? 'selected' : ''; ?>>Pending</option><option value="delivered" <?php echo ($order['delivery_status'] == 'delivered') ? 'selected' : ''; ?>>Delivered</option><option value="not_delivered" <?php echo ($order['delivery_status'] == 'not_delivered') ? 'selected' : ''; ?>>Not Delivered</option></select><button class="update-btn"><i class="fas fa-save"></i> Update</button><button class="return-btn" data-order-id="<?php echo $order['id']; ?>" data-order-number="<?php echo htmlspecialchars($order['order_number']); ?>" data-customer-name="<?php echo htmlspecialchars($order['customer_name']); ?>"><i class="fas fa-undo-alt"></i> Create Return</button></div>
                        <div class="table-responsive"><table class="items-table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead><tbody><?php foreach ($order['items'] as $item): ?><tr><td><i class="fas fa-box"></i> <?php echo htmlspecialchars($item['product_name']); ?></td><td><?php echo $item['quantity']; ?></td><td>₦<?php echo number_format($item['unit_price'], 2); ?></td><td>₦<?php echo number_format($item['subtotal'], 2); ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><td colspan="3" style="text-align:right;"><strong>Total</strong></td><td><strong>₦<?php echo number_format($order['total_amount'], 2); ?></strong></td></tr></tfoot></table></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Returns Tab -->
        <div id="returns-tab" class="tab-content">
            <div class="returns-header"><button id="createReturnBtn" class="btn-primary"><i class="fas fa-plus"></i> Create Return/Exchange</button></div>
            <div id="returnsContainer">
                <?php if (empty($returns_query)): ?>
                <div class="empty-state"><i class="fas fa-exchange-alt"></i><p>No returns found</p></div>
                <?php else: foreach ($returns_query as $return): ?>
                <div class="return-card" data-return-id="<?php echo $return['id']; ?>">
                    <div class="return-header"><div><strong class="return-number">#<?php echo htmlspecialchars($return['return_number']); ?></strong><span class="return-order">Order: <?php echo htmlspecialchars($return['order_number']); ?></span><span class="return-customer">Customer: <?php echo htmlspecialchars($return['customer_name']); ?></span></div><span class="return-status status-<?php echo $return['status']; ?>"><?php echo ucfirst($return['status']); ?></span></div>
                    <div class="return-body">
                        <div><i class="fas fa-tag"></i> <strong>Type:</strong> <?php echo $return['return_type']; ?></div>
                        <div><i class="fas fa-boxes"></i> <strong>Items Returned:</strong><ul>
                            <?php foreach ($return['items'] as $ritem): ?>
                            <li>
                                <?php echo htmlspecialchars($ritem['original_product_name']); ?> - <strong>Returned: <?php echo $ritem['returned_quantity']; ?> pcs</strong>
                                <?php if ($ritem['original_purchased_quantity'] > $ritem['returned_quantity']): ?><small>(Originally purchased: <?php echo $ritem['original_purchased_quantity']; ?> pcs)</small><?php endif; ?>
                                <?php if ($ritem['action_type'] === 'swap' && !empty($ritem['new_product_name'])): ?><br><small><i class="fas fa-exchange-alt"></i> <strong>Swapped with:</strong> <?php echo htmlspecialchars($ritem['new_product_name']); ?> (<?php echo $ritem['returned_quantity']; ?> x ₦<?php echo number_format($ritem['new_unit_price'], 2); ?> = ₦<?php echo number_format($ritem['new_subtotal'], 2); ?>)</small><?php endif; ?>
                                <?php if ($ritem['item_reason']): ?><br><small><i class="fas fa-comment"></i> Reason: <?php echo htmlspecialchars($ritem['item_reason']); ?></small><?php endif; ?>
                                <?php if (isset($ritem['resell_quantity']) || isset($ritem['destroy_quantity'])): ?>
                                <br><small><i class="fas fa-archive"></i> Inventory Action: Resell <?php echo intval($ritem['resell_quantity']); ?> | Destroy <?php echo intval($ritem['destroy_quantity']); ?></small>
                                <?php elseif ($ritem['inventory_action']): ?>
                                <br><small><i class="fas fa-archive"></i> Inventory: <?php echo $ritem['inventory_action']; ?></small>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul></div>
                        <?php if ($return['notes']): ?><div><i class="fas fa-sticky-note"></i> <strong>Notes:</strong> <?php echo htmlspecialchars($return['notes']); ?></div><?php endif; ?>
                        <?php if ($return['status'] === 'pending' && $can_process_returns): ?>
                        <button class="process-btn" data-return-id="<?php echo $return['id']; ?>" data-return-number="<?php echo htmlspecialchars($return['return_number']); ?>"><i class="fas fa-clipboard-list"></i> Review & Process</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <a href="../sales-dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Sales Dashboard</a>
    </div>

    <!-- CREATE RETURN MODAL -->
    <div id="returnModal" class="modal modal-large">
        <div class="modal-content">
            <div class="modal-header"><h3><i class="fas fa-undo-alt"></i> Create Return/Exchange</h3><button class="modal-close" onclick="closeModalById('returnModal')">&times;</button></div>
            <div class="modal-body">
                <div class="form-section"><div class="section-title"><span class="step-badge">1</span><h4>Select Order</h4></div><div class="form-group"><input type="text" id="orderSearchInput" placeholder="Search by order number or customer name..." autocomplete="off"><div id="orderSearchResults" class="search-results" style="display:none;"></div></div><div id="selectedOrderDisplay" style="display:none;"></div><input type="hidden" id="selectedOrderId"></div>
                <div class="form-section"><div class="section-title"><span class="step-badge">2</span><h4>Choose Return Type</h4></div><div class="return-type-tabs"><button class="return-type-tab active" data-return-type="change_item"><i class="fas fa-sync-alt"></i> Change Item<small>Exchange for same product</small></button><button class="return-type-tab" data-return-type="swap"><i class="fas fa-exchange-alt"></i> Swap Products<small>Exchange for different products</small></button></div></div>
                <div class="form-section"><div class="section-title"><span class="step-badge">3</span><h4>Select Items to Return</h4></div><div id="orderItemsList" class="order-items-list"></div></div>
                <div id="swapContent" style="display:block;">
                    <div id="returnPreviewSection" style="display:none;"><div class="form-section"><div class="section-title"><span class="step-badge">4</span><h4>Select Swap Products</h4></div><div id="returnPreviewList" class="return-preview-list"></div></div></div>
                    <div id="additionalProductsSection"><div class="form-section"><div class="section-title"><span class="step-badge">5</span><h4>Add Additional Products (Optional)</h4><small>To balance exchange value</small></div><div class="additional-products-list" id="additionalProductsList"></div><button type="button" id="addProductBtn" class="btn-secondary btn-sm"><i class="fas fa-plus"></i> Add Product</button></div></div>
                </div>
                <div id="returnSummary" style="display:none;"></div>
                <div class="form-section"><div class="form-group"><label><i class="fas fa-comment"></i> Additional Notes (optional)</label><textarea id="returnNotes" rows="2" placeholder="Any additional information about this return..."></textarea></div></div>
                <div class="info-message"><i class="fas fa-info-circle"></i> Each returned item requires a reason. Management will determine inventory action upon review.</div>
            </div>
            <div class="modal-footer"><button class="btn-secondary" onclick="closeModalById('returnModal')">Cancel</button><button id="submitReturnBtn" class="btn-primary">Submit Return Request</button></div>
        </div>
    </div>

    <!-- PROCESS RETURN MODAL (with per‑item breakdown) -->
    <div id="processReturnModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header"><h3><i class="fas fa-clipboard-list"></i> Process Return</h3><button class="modal-close" onclick="closeModalById('processReturnModal')">&times;</button></div>
            <div class="modal-body">
                <input type="hidden" id="processReturnId">
                <div class="form-group"><label><i class="fas fa-gavel"></i> Decision</label><select id="processDecision" class="form-control"><option value="approved">Approve Return</option><option value="rejected">Reject Return</option></select></div>
                <div id="inventoryActionsContainer" style="display:none; margin-top: 1rem;">
                    <label><i class="fas fa-boxes"></i> Inventory Breakdown (per returned item)</label>
                    <div id="inventoryActionsList" class="inventory-actions-list"></div>
                </div>
                <div class="form-group"><label><i class="fas fa-comment"></i> Response Notes</label><textarea id="processNotes" rows="3" class="form-control" placeholder="Add any notes about this decision..."></textarea></div>
            </div>
            <div class="modal-footer"><button class="btn-secondary" onclick="closeModalById('processReturnModal')">Cancel</button><button id="processReturnSubmitBtn" class="btn-primary">Submit</button></div>
        </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>
    <div id="overlay" class="overlay"></div>

    <script>
        // =====================================================
        // CONSTANTS & VARIABLES
        // =====================================================
        const csrfToken = '<?php echo $csrf_token; ?>';
        const canProcessReturns = <?php echo $can_process_returns ? 'true' : 'false'; ?>;
        const allProducts = <?php echo $products_json; ?>;
        
        let currentReturnItems = [];
        let additionalProducts = [];
        let selectedOrderData = null;
        let currentProcessReturnId = null;
        let currentReturnType = 'change_item';
        let additionalProductIndex = 0;
        let orderSearchDebounceTimer = null;
        let previewSearchTimers = {};

        // =====================================================
        // UTILITY FUNCTIONS
        // =====================================================
        function showToast(msg, type) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'toast toast-' + type;
            toast.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle') + '"></i> ' + msg + '<span onclick="this.parentElement.remove()">×</span>';
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        function showLoading() { document.getElementById('preloader').classList.add('show'); }
        function hideLoading() { document.getElementById('preloader').classList.remove('show'); }
        function closeModalById(modalId) { document.getElementById(modalId).classList.remove('show'); document.getElementById('overlay').classList.remove('active'); }
        function openModalById(modalId) { document.getElementById(modalId).classList.add('show'); document.getElementById('overlay').classList.add('active'); }
        async function ajaxPost(formData) { formData.append('csrf_token', csrfToken); const response = await fetch(window.location.href, { method: 'POST', body: formData }); return await response.json(); }
        function escapeHtml(str) { if (!str) return ''; return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;'); }
        function formatCurrency(amount) { return '₦' + parseFloat(amount).toFixed(2); }

        // =====================================================
        // ORDER HISTORY FUNCTIONS
        // =====================================================
        function toggleOrder(header) {
            const body = header.closest('.order-card').querySelector('.order-body');
            const icon = header.querySelector('.expand-icon');
            if (body.style.display === 'none' || body.style.display === '') {
                body.style.display = 'block';
                if (icon) icon.innerHTML = '▲';
            } else {
                body.style.display = 'none';
                if (icon) icon.innerHTML = '▼';
            }
        }
        function applyFilters() {
            const source = document.getElementById('sourceFilter').value;
            const delivery = document.getElementById('deliveryFilter').value;
            const fromDate = document.getElementById('dateFrom').value;
            const toDate = document.getElementById('dateTo').value;
            const search = document.getElementById('searchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.order-card');
            let online = 0, onsite = 0, total = 0;
            cards.forEach(card => {
                let show = true;
                if (source !== 'all' && card.dataset.source !== source) show = false;
                if (delivery !== 'all' && card.dataset.delivery !== delivery) show = false;
                if (fromDate && card.dataset.date < fromDate) show = false;
                if (toDate && card.dataset.date > toDate) show = false;
                if (search && !card.dataset.orderNumber.toLowerCase().includes(search) && !card.dataset.customer.toLowerCase().includes(search)) show = false;
                card.style.display = show ? '' : 'none';
                if (show) { total++; if (card.dataset.source === 'online') online++; if (card.dataset.source === 'onsite') onsite++; }
            });
            document.getElementById('onlineCount').innerText = online;
            document.getElementById('onsiteCount').innerText = onsite;
            document.getElementById('totalCount').innerText = total;
        }
        function resetFilters() {
            document.getElementById('sourceFilter').value = 'all';
            document.getElementById('deliveryFilter').value = 'all';
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            document.getElementById('searchInput').value = '';
            applyFilters();
            showToast('Filters reset', 'info');
        }
        async function updateDelivery(btn) {
            const card = btn.closest('.order-card');
            const orderId = card.dataset.orderId;
            const select = card.querySelector('.delivery-select');
            const status = select.value;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'update_delivery');
            formData.append('order_id', orderId);
            formData.append('delivery_status', status);
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) { showToast(data.message, 'success'); setTimeout(() => location.reload(), 1000); }
                else { showToast(data.message, 'error'); }
            } catch(e) { showToast('Error: ' + e.message, 'error'); }
            finally { hideLoading(); }
        }

        // =====================================================
        // ORDER SEARCH (Create Return Modal)
        // =====================================================
        const orderSearchInput = document.getElementById('orderSearchInput');
        if (orderSearchInput) {
            orderSearchInput.addEventListener('input', function() {
                const term = this.value.trim();
                const resultsDiv = document.getElementById('orderSearchResults');
                if (term.length < 2) { if (resultsDiv) { resultsDiv.innerHTML = ''; resultsDiv.style.display = 'none'; } return; }
                if (orderSearchDebounceTimer) clearTimeout(orderSearchDebounceTimer);
                orderSearchDebounceTimer = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax', 1); formData.append('action', 'search_orders'); formData.append('term', term);
                    try {
                        const data = await ajaxPost(formData);
                        if (resultsDiv) {
                            if (data.success && data.orders && data.orders.length > 0) {
                                resultsDiv.innerHTML = data.orders.map(o => `<div class="search-result" onclick="selectOrder(${o.id}, '${escapeHtml(o.order_number)}', '${escapeHtml(o.customer_name)}')"><strong>${escapeHtml(o.order_number)}</strong><small>${escapeHtml(o.customer_name)}</small><small>₦${parseFloat(o.total_amount).toFixed(2)} | ${new Date(o.created_at).toLocaleDateString()}</small></div>`).join('');
                                resultsDiv.style.display = 'block';
                            } else { resultsDiv.innerHTML = '<div class="search-result no-results">No orders found</div>'; resultsDiv.style.display = 'block'; }
                        }
                    } catch(e) { console.error(e); if (resultsDiv) { resultsDiv.innerHTML = '<div class="search-result error">Search error</div>'; resultsDiv.style.display = 'block'; } }
                }, 300);
            });
        }
        function selectOrder(id, number, customer) {
            document.getElementById('selectedOrderId').value = id;
            document.getElementById('selectedOrderDisplay').innerHTML = `<div class="selected-order-card"><strong>Order #${escapeHtml(number)}</strong><br><small>Customer: ${escapeHtml(customer)}</small></div>`;
            document.getElementById('selectedOrderDisplay').style.display = 'block';
            document.getElementById('orderSearchResults').style.display = 'none';
            document.getElementById('orderSearchInput').value = number;
            loadOrderItems(id);
        }
        async function loadOrderItems(orderId) {
            const formData = new FormData();
            formData.append('ajax', 1); formData.append('action', 'get_order_items'); formData.append('order_id', orderId);
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.order) { selectedOrderData = data.order; renderOrderItems(data.order.items); openModalById('returnModal'); }
                else { showToast(data.message || 'Could not load order items', 'error'); }
            } catch(e) { showToast('Error loading order items', 'error'); }
            finally { hideLoading(); }
        }

        // =====================================================
        // RENDER ORDER ITEMS (for return selection)
        // =====================================================
        function renderOrderItems(items) {
            const container = document.getElementById('orderItemsList');
            if (!container) return;
            let html = '';
            currentReturnItems = [];
            items.forEach((item, idx) => {
                html += `<div class="return-item-row" data-item-id="${item.id}" data-product-id="${item.product_id}" data-price="${item.unit_price}" data-max-qty="${item.quantity}">
                    <div class="item-info"><strong><i class="fas fa-box"></i> ${escapeHtml(item.product_name)}</strong><div class="item-details">Qty: ${item.quantity} | Price: ₦${parseFloat(item.unit_price).toFixed(2)} | Subtotal: ₦${parseFloat(item.subtotal).toFixed(2)}</div></div>
                    <div class="item-actions">
                        <label class="checkbox-label"><input type="checkbox" class="item-select" data-idx="${idx}" onchange="toggleReturnItem(this, ${idx})"> <i class="fas fa-check-circle"></i> Return this item</label>
                        <div class="return-qty-group" style="display:none;"><label>Quantity to return (max ${item.quantity}):</label><input type="number" class="return-quantity" min="1" max="${item.quantity}" value="${item.quantity}"></div>
                        <div class="return-reason-group" style="display:none;"><label>Reason for return <span class="required">*</span>:</label><input type="text" class="item-reason-input" placeholder="e.g., Damaged, Wrong item, Expired..."></div>
                    </div>
                </div>`;
            });
            container.innerHTML = html;
        }
        function toggleReturnItem(checkbox, idx) {
            const row = checkbox.closest('.return-item-row');
            const qtyGroup = row.querySelector('.return-qty-group');
            const reasonGroup = row.querySelector('.return-reason-group');
            const itemId = parseInt(row.dataset.itemId);
            const productId = parseInt(row.dataset.productId);
            const price = parseFloat(row.dataset.price);
            const maxQty = parseInt(row.dataset.maxQty);
            if (checkbox.checked) {
                qtyGroup.style.display = 'block';
                reasonGroup.style.display = 'block';
                const qtyInput = row.querySelector('.return-quantity');
                const reasonInput = row.querySelector('.item-reason-input');
                const returnQty = parseInt(qtyInput.value) || maxQty;
                currentReturnItems.push({
                    original_order_item_id: itemId, original_product_id: productId, original_quantity: maxQty,
                    original_unit_price: price, original_subtotal: price * maxQty,
                    return_quantity: returnQty, return_subtotal: price * returnQty,
                    action_type: currentReturnType, reason: reasonInput.value,
                    new_product_id: null, new_quantity: returnQty, new_unit_price: price, new_subtotal: price * returnQty,
                    swap_product_name: null, price_difference: 0
                });
                qtyInput.onchange = () => updateReturnItemQty(itemId, parseInt(qtyInput.value));
                reasonInput.onchange = () => updateReturnItemReason(itemId, reasonInput.value);
            } else {
                qtyGroup.style.display = 'none'; reasonGroup.style.display = 'none';
                currentReturnItems = currentReturnItems.filter(i => i.original_order_item_id !== itemId);
            }
            updateReturnPreview(); updateReturnSummary();
        }
        function updateReturnItemQty(itemId, newQty) {
            const idx = currentReturnItems.findIndex(i => i.original_order_item_id === itemId);
            if (idx !== -1) {
                const maxQty = currentReturnItems[idx].original_quantity;
                const qty = Math.min(Math.max(1, newQty), maxQty);
                currentReturnItems[idx].return_quantity = qty;
                currentReturnItems[idx].return_subtotal = currentReturnItems[idx].original_unit_price * qty;
                currentReturnItems[idx].new_quantity = qty;
                if (currentReturnItems[idx].new_unit_price) {
                    currentReturnItems[idx].new_subtotal = currentReturnItems[idx].new_unit_price * qty;
                    currentReturnItems[idx].price_difference = (currentReturnItems[idx].new_unit_price * qty) - currentReturnItems[idx].return_subtotal;
                }
                updateReturnPreview(); updateReturnSummary();
            }
        }
        function updateReturnItemReason(itemId, reason) {
            const idx = currentReturnItems.findIndex(i => i.original_order_item_id === itemId);
            if (idx !== -1) currentReturnItems[idx].reason = reason;
        }

        // =====================================================
        // RETURN TYPE TABS
        // =====================================================
        function initReturnTypeTabs() {
            const tabs = document.querySelectorAll('.return-type-tab');
            const swapContent = document.getElementById('swapContent');
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const newType = this.dataset.returnType;
                    if (currentReturnType === newType) return;
                    if (currentReturnItems.length > 0 || additionalProducts.length > 0) {
                        if (!confirm('Switching return type will clear your current selections. Continue?')) return;
                        clearAllSelections();
                    }
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    currentReturnType = newType;
                    if (swapContent) swapContent.style.display = newType === 'swap' ? 'block' : 'none';
                    const previewSection = document.getElementById('returnPreviewSection');
                    if (previewSection) previewSection.style.display = 'none';
                    currentReturnItems.forEach(item => {
                        item.action_type = newType;
                        if (newType === 'change_item') {
                            item.new_product_id = item.original_product_id;
                            item.new_unit_price = item.original_unit_price;
                            item.new_subtotal = item.return_subtotal;
                            item.price_difference = 0;
                        } else if (newType === 'swap') {
                            item.new_product_id = null; item.new_unit_price = null; item.new_subtotal = null;
                            item.swap_product_name = null; item.price_difference = 0;
                        }
                    });
                    updateReturnPreview(); updateReturnSummary();
                });
            });
        }
        function clearAllSelections() {
            document.querySelectorAll('.item-select').forEach(cb => {
                cb.checked = false;
                const itemRow = cb.closest('.return-item-row');
                if (itemRow) {
                    const qtyGroup = itemRow.querySelector('.return-qty-group');
                    const reasonGroup = itemRow.querySelector('.return-reason-group');
                    if (qtyGroup) qtyGroup.style.display = 'none';
                    if (reasonGroup) reasonGroup.style.display = 'none';
                }
            });
            currentReturnItems = [];
            additionalProducts = [];
            document.getElementById('additionalProductsList').innerHTML = '';
            additionalProductIndex = 0;
            updateReturnPreview(); updateReturnSummary();
        }

        // =====================================================
        // ADDITIONAL PRODUCTS
        // =====================================================
        function showAddProductSearch() {
            const container = document.getElementById('additionalProductsList');
            if (!container) return;
            const newRow = document.createElement('div');
            newRow.className = 'additional-product-row';
            newRow.setAttribute('data-product-index', additionalProductIndex);
            newRow.innerHTML = `<div class="product-search-wrapper"><input type="text" class="additional-product-search" placeholder="Search for product to add..." autocomplete="off"><div class="product-search-results" style="display:none;"></div></div>
                <div class="product-info-display" style="display:none;"><span class="selected-product-name"></span><span class="selected-product-price"></span><button type="button" class="remove-product-btn"><i class="fas fa-trash-alt"></i> Remove</button></div>
                <div class="product-quantity-wrapper" style="display:none;"><label>Quantity:</label><input type="number" class="additional-product-qty" min="1" value="1"></div>
                <input type="hidden" class="additional-product-id" value=""><input type="hidden" class="additional-product-price" value="">`;
            const searchInput = newRow.querySelector('.additional-product-search');
            const idx = additionalProductIndex;
            let localDebounceTimer = null;
            searchInput.addEventListener('input', function(e) {
                const term = this.value.trim();
                const resultsDiv = this.parentElement.querySelector('.product-search-results');
                if (term.length < 2) { if (resultsDiv) { resultsDiv.innerHTML = ''; resultsDiv.style.display = 'none'; } return; }
                if (localDebounceTimer) clearTimeout(localDebounceTimer);
                localDebounceTimer = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax', 1); formData.append('action', 'search_products'); formData.append('term', term);
                    try {
                        const data = await ajaxPost(formData);
                        if (resultsDiv) {
                            if (data.success && data.products && data.products.length > 0) {
                                resultsDiv.innerHTML = data.products.map(p => `<div class="product-result" onclick="selectAdditionalProduct(${idx}, ${p.id}, '${escapeHtml(p.name)}', ${parseFloat(p.base_price)})"><strong>${escapeHtml(p.name)}</strong><small>₦${parseFloat(p.base_price).toFixed(2)}</small></div>`).join('');
                                resultsDiv.style.display = 'block';
                            } else { resultsDiv.innerHTML = '<div class="product-result no-results">No products found</div>'; resultsDiv.style.display = 'block'; }
                        }
                    } catch(e) { console.error(e); if (resultsDiv) resultsDiv.innerHTML = '<div class="product-result error">Search error</div>'; resultsDiv.style.display = 'block'; }
                }, 300);
            });
            container.appendChild(newRow);
            additionalProductIndex++;
        }
        function selectAdditionalProduct(index, productId, productName, productPrice) {
            const row = document.querySelector(`.additional-product-row[data-product-index="${index}"]`);
            if (!row) return;
            const searchWrapper = row.querySelector('.product-search-wrapper');
            const infoDisplay = row.querySelector('.product-info-display');
            const quantityWrapper = row.querySelector('.product-quantity-wrapper');
            const productIdInput = row.querySelector('.additional-product-id');
            const productPriceInput = row.querySelector('.additional-product-price');
            const productNameSpan = row.querySelector('.selected-product-name');
            const productPriceSpan = row.querySelector('.selected-product-price');
            searchWrapper.style.display = 'none';
            infoDisplay.style.display = 'flex';
            quantityWrapper.style.display = 'block';
            productNameSpan.textContent = productName;
            productPriceSpan.textContent = formatCurrency(productPrice);
            productIdInput.value = productId;
            productPriceInput.value = productPrice;
            const qtyInput = row.querySelector('.additional-product-qty');
            qtyInput.onchange = () => updateAdditionalProduct(index);
            updateAdditionalProduct(index);
            const removeBtn = row.querySelector('.remove-product-btn');
            removeBtn.onclick = () => removeAdditionalProduct(index);
            const resultsDiv = row.querySelector('.product-search-results');
            resultsDiv.style.display = 'none';
        }
        function updateAdditionalProduct(index) {
            const row = document.querySelector(`.additional-product-row[data-product-index="${index}"]`);
            if (!row) return;
            const productId = parseInt(row.querySelector('.additional-product-id').value);
            const price = parseFloat(row.querySelector('.additional-product-price').value);
            const qty = parseInt(row.querySelector('.additional-product-qty').value);
            const productName = row.querySelector('.selected-product-name').textContent;
            if (productId && price) {
                const existingIndex = additionalProducts.findIndex(p => p.product_id === productId);
                const subtotal = price * qty;
                const newProduct = { product_id: productId, product_name: productName, price: price, quantity: qty, subtotal: subtotal };
                if (existingIndex !== -1) additionalProducts[existingIndex] = newProduct;
                else additionalProducts.push(newProduct);
                updateReturnPreview(); updateReturnSummary();
            }
        }
        function removeAdditionalProduct(index) {
            const row = document.querySelector(`.additional-product-row[data-product-index="${index}"]`);
            if (row) {
                const productId = parseInt(row.querySelector('.additional-product-id').value);
                additionalProducts = additionalProducts.filter(p => p.product_id !== productId);
                row.remove();
                updateReturnPreview(); updateReturnSummary();
            }
        }

        // =====================================================
        // RETURN PREVIEW (Swap Products)
        // =====================================================
        function updateReturnPreview() {
            const previewSection = document.getElementById('returnPreviewSection');
            const previewContainer = document.getElementById('returnPreviewList');
            if (!previewSection || !previewContainer) return;
            if (currentReturnType !== 'swap') { previewSection.style.display = 'none'; return; }
            if (currentReturnItems.length === 0) { previewSection.style.display = 'none'; return; }
            previewSection.style.display = 'block';
            let html = '';
            for (let i = 0; i < currentReturnItems.length; i++) {
                const item = currentReturnItems[i];
                let productName = 'Product';
                const cb = document.querySelector(`.item-select[data-idx="${i}"]`);
                if (cb) { const row = cb.closest('.return-item-row'); if (row) { const nameEl = row.querySelector('.item-info strong'); if (nameEl) productName = nameEl.innerText; } }
                const hasSwapProduct = item.new_product_id && item.new_product_id > 0;
                const swapProductName = hasSwapProduct ? (item.swap_product_name || 'Selected Product') : '';
                const swapProductPrice = hasSwapProduct ? item.new_unit_price : 0;
                const swapQuantity = item.new_quantity || item.return_quantity;
                const swapSubtotal = (swapProductPrice * swapQuantity).toFixed(2);
                const originalSubtotal = item.return_subtotal.toFixed(2);
                const difference = (swapProductPrice * swapQuantity - item.return_subtotal).toFixed(2);
                html += `<div class="preview-return-item" data-return-idx="${i}">
                    <div class="preview-return-header"><div class="preview-return-info"><span class="preview-item-name">${escapeHtml(productName)}</span><span class="preview-item-details">Returning: ${item.return_quantity} x ₦${item.original_unit_price.toFixed(2)} = ₦${originalSubtotal}</span></div><button class="preview-remove-return-btn" onclick="removeReturnItemFromPreview(${i})"><i class="fas fa-trash-alt"></i></button></div>
                    <div class="preview-swap-area" data-swap-idx="${i}"><div class="preview-swap-label"><i class="fas fa-exchange-alt"></i> Swap with:</div>`;
                if (hasSwapProduct) {
                    html += `<div class="preview-swap-selected"><div class="swap-product-info"><span class="swap-product-name">${escapeHtml(swapProductName)}</span><span class="swap-product-price">₦${swapProductPrice.toFixed(2)} each</span></div>
                        <div class="swap-quantity-controls"><button class="swap-qty-btn" onclick="updateSwapQuantity(${i}, ${swapQuantity - 1})">-</button><input type="number" class="swap-qty-input" data-swap-idx="${i}" value="${swapQuantity}" min="1" max="${item.original_quantity}" step="1"><button class="swap-qty-btn" onclick="updateSwapQuantity(${i}, ${swapQuantity + 1})">+</button></div>
                        <div class="swap-subtotal">Subtotal: ₦${swapSubtotal}</div>${parseFloat(difference) !== 0 ? `<div class="swap-difference ${parseFloat(difference) > 0 ? 'positive' : 'negative'}">Difference: ${parseFloat(difference) > 0 ? '+' : ''}₦${Math.abs(parseFloat(difference)).toFixed(2)}</div>` : ''}
                        <button class="swap-clear-btn" onclick="clearSwapSelection(${i})"><i class="fas fa-times-circle"></i> Change Product</button></div>`;
                } else {
                    html += `<div class="preview-swap-search"><div class="swap-search-wrapper"><input type="text" class="preview-swap-search-input" data-swap-idx="${i}" placeholder="Search for replacement product..." autocomplete="off"><div class="preview-swap-results" data-swap-idx="${i}" style="display:none;"></div></div></div>`;
                }
                html += `</div></div>`;
            }
            previewContainer.innerHTML = html;
            attachPreviewSearchListeners();
        }
        function attachPreviewSearchListeners() {
            const searchInputs = document.querySelectorAll('.preview-swap-search-input');
            for (let i = 0; i < searchInputs.length; i++) {
                const input = searchInputs[i];
                const swapIdx = parseInt(input.dataset.swapIdx);
                const newInput = input.cloneNode(true);
                input.parentNode.replaceChild(newInput, input);
                newInput.addEventListener('input', function(e) {
                    const term = this.value.trim();
                    const resultsDiv = document.querySelector(`.preview-swap-results[data-swap-idx="${swapIdx}"]`);
                    if (term.length < 2) { if (resultsDiv) { resultsDiv.innerHTML = ''; resultsDiv.style.display = 'none'; } return; }
                    if (previewSearchTimers[swapIdx]) clearTimeout(previewSearchTimers[swapIdx]);
                    previewSearchTimers[swapIdx] = setTimeout(async () => {
                        const formData = new FormData();
                        formData.append('ajax', 1); formData.append('action', 'search_products'); formData.append('term', term);
                        try {
                            const data = await ajaxPost(formData);
                            if (resultsDiv) {
                                if (data.success && data.products && data.products.length > 0) {
                                    resultsDiv.innerHTML = data.products.map(p => `<div class="preview-product-result" onclick="selectSwapProductFromPreview(${swapIdx}, ${p.id}, '${escapeHtml(p.name)}', ${parseFloat(p.base_price)})"><strong>${escapeHtml(p.name)}</strong><small>₦${parseFloat(p.base_price).toFixed(2)}</small></div>`).join('');
                                    resultsDiv.style.display = 'block';
                                } else { resultsDiv.innerHTML = `<div class="preview-product-result no-results">No products found for "${escapeHtml(term)}"</div>`; resultsDiv.style.display = 'block'; }
                            }
                        } catch(e) { console.error(e); if (resultsDiv) resultsDiv.innerHTML = '<div class="preview-product-result error">Search error</div>'; resultsDiv.style.display = 'block'; }
                    }, 300);
                });
            }
            const qtyInputs = document.querySelectorAll('.swap-qty-input');
            for (let i = 0; i < qtyInputs.length; i++) {
                const input = qtyInputs[i];
                const newInput = input.cloneNode(true);
                input.parentNode.replaceChild(newInput, input);
                newInput.addEventListener('change', function() { handleSwapQuantityInput(this); });
                newInput.addEventListener('input', function() {
                    let value = parseInt(this.value);
                    if (!isNaN(value) && value >= 1) {
                        const swapIdx = parseInt(this.dataset.swapIdx);
                        const item = currentReturnItems[swapIdx];
                        if (item && value <= item.original_quantity) updateSwapQuantity(swapIdx, value);
                    }
                });
            }
        }
        function handleSwapQuantityInput(input) {
            const swapIdx = parseInt(input.dataset.swapIdx);
            let value = parseInt(input.value);
            if (isNaN(value)) value = 1;
            const item = currentReturnItems[swapIdx];
            if (item) { value = Math.min(Math.max(1, value), item.original_quantity); input.value = value; updateSwapQuantity(swapIdx, value); }
        }
        function selectSwapProductFromPreview(swapIdx, productId, productName, productPrice) {
            if (swapIdx >= 0 && swapIdx < currentReturnItems.length) {
                const item = currentReturnItems[swapIdx];
                const qty = item.return_quantity;
                currentReturnItems[swapIdx].new_product_id = productId;
                currentReturnItems[swapIdx].new_unit_price = productPrice;
                currentReturnItems[swapIdx].new_subtotal = productPrice * qty;
                currentReturnItems[swapIdx].swap_product_name = productName;
                currentReturnItems[swapIdx].price_difference = (productPrice * qty) - item.return_subtotal;
            }
            updateReturnPreview(); updateReturnSummary();
        }
        function updateSwapQuantity(swapIdx, newQuantity) {
            if (swapIdx >= 0 && swapIdx < currentReturnItems.length) {
                const item = currentReturnItems[swapIdx];
                const maxQty = item.original_quantity;
                const qty = Math.min(Math.max(1, newQuantity), maxQty);
                currentReturnItems[swapIdx].new_quantity = qty;
                if (item.new_unit_price) {
                    currentReturnItems[swapIdx].new_subtotal = item.new_unit_price * qty;
                    currentReturnItems[swapIdx].price_difference = (item.new_unit_price * qty) - item.return_subtotal;
                }
                updateReturnPreview(); updateReturnSummary();
            }
        }
        function clearSwapSelection(swapIdx) {
            if (swapIdx >= 0 && swapIdx < currentReturnItems.length) {
                currentReturnItems[swapIdx].new_product_id = null;
                currentReturnItems[swapIdx].new_unit_price = null;
                currentReturnItems[swapIdx].new_subtotal = null;
                currentReturnItems[swapIdx].swap_product_name = null;
                currentReturnItems[swapIdx].price_difference = 0;
            }
            updateReturnPreview(); updateReturnSummary();
        }
        function removeReturnItemFromPreview(idx) {
            const item = currentReturnItems[idx];
            if (item) {
                const checkbox = document.querySelector(`.item-select[data-idx="${idx}"]`);
                if (checkbox) { checkbox.checked = false; toggleReturnItem(checkbox); }
            }
        }
        function removeAdditionalProductFromPreview(idx) {
            if (idx >= 0 && idx < additionalProducts.length) { additionalProducts.splice(idx, 1); updateReturnPreview(); updateReturnSummary(); }
        }
        function updateAdditionalProductQuantity(idx, newQuantity) {
            if (idx >= 0 && idx < additionalProducts.length) {
                newQuantity = Math.max(1, newQuantity);
                additionalProducts[idx].quantity = newQuantity;
                additionalProducts[idx].subtotal = additionalProducts[idx].price * newQuantity;
                updateReturnPreview(); updateReturnSummary();
            }
        }
        function updateReturnSummary() {
            let totalRefund = 0, totalNew = 0;
            for (let i = 0; i < currentReturnItems.length; i++) {
                const item = currentReturnItems[i];
                totalRefund += item.return_subtotal;
                if (currentReturnType === 'swap') totalNew += item.new_subtotal || item.return_subtotal;
                else totalNew += item.return_subtotal;
            }
            for (let j = 0; j < additionalProducts.length; j++) totalNew += additionalProducts[j].subtotal;
            const difference = totalNew - totalRefund;
            let summaryHtml = `<div class="return-summary"><h4><i class="fas fa-chart-line"></i> Exchange Summary</h4>
                <div><strong>Items Being Returned:</strong> ₦${totalRefund.toFixed(2)}</div>
                <div><strong>New Items Total:</strong> ₦${totalNew.toFixed(2)}</div>
                <div><strong>Difference:</strong> ₦${Math.abs(difference).toFixed(2)}</div>`;
            if (difference < 0) summaryHtml += `<div class="warning-message"><i class="fas fa-exclamation-triangle"></i> Customer needs to add ₦${Math.abs(difference).toFixed(2)} worth of products to balance the exchange.</div>`;
            else if (difference > 0) summaryHtml += `<div class="success-message"><i class="fas fa-check-circle"></i> Customer will pay additional ₦${difference.toFixed(2)} for this exchange.</div>`;
            else summaryHtml += `<div class="success-message"><i class="fas fa-balance-scale"></i> Even exchange - no additional payment needed.</div>`;
            summaryHtml += `</div>`;
            const summaryContainer = document.getElementById('returnSummary');
            if (summaryContainer) { summaryContainer.innerHTML = summaryHtml; summaryContainer.style.display = 'block'; }
        }

        // =====================================================
        // SUBMIT RETURN
        // =====================================================
        async function submitReturn() {
            const orderId = document.getElementById('selectedOrderId').value;
            const notes = document.getElementById('returnNotes').value.trim();
            if (!orderId) { showToast('Please select an order first', 'error'); return; }
            if (currentReturnItems.length === 0) { showToast('Please select at least one item to return', 'error'); return; }
            const missingReasons = currentReturnItems.filter(item => !item.reason || item.reason.trim() === '');
            if (missingReasons.length > 0) { showToast('Please provide a reason for all selected items', 'error'); return; }
            const returnItemsData = currentReturnItems.map(itm => ({
                original_order_item_id: itm.original_order_item_id, original_product_id: itm.original_product_id,
                original_quantity: itm.original_quantity, original_unit_price: itm.original_unit_price,
                original_subtotal: itm.original_subtotal, return_quantity: itm.return_quantity,
                return_subtotal: itm.return_subtotal, action_type: itm.action_type,
                new_product_id: itm.new_product_id, new_quantity: itm.new_quantity,
                new_unit_price: itm.new_unit_price, new_subtotal: itm.new_subtotal,
                reason: itm.reason
            }));
            const formData = new FormData();
            formData.append('ajax', 1); formData.append('action', 'create_return');
            formData.append('order_id', orderId); formData.append('return_type', currentReturnType);
            formData.append('return_items', JSON.stringify(returnItemsData));
            formData.append('additional_products', JSON.stringify(additionalProducts));
            formData.append('notes', notes);
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) { showToast(data.message, 'success'); closeModalById('returnModal'); setTimeout(() => location.reload(), 1500); }
                else { showToast(data.message, 'error'); }
            } catch(e) { showToast('Network error: ' + e.message, 'error'); }
            finally { hideLoading(); }
        }
        function openReturnModal(orderId, orderNumber, customerName) {
            if (orderId) {
                document.getElementById('selectedOrderId').value = orderId;
                document.getElementById('selectedOrderDisplay').innerHTML = `<div class="selected-order-card"><strong>Order #${escapeHtml(orderNumber)}</strong><br><small>Customer: ${escapeHtml(customerName)}</small></div>`;
                document.getElementById('selectedOrderDisplay').style.display = 'block';
                document.getElementById('orderSearchInput').value = orderNumber;
                loadOrderItems(orderId);
            } else {
                document.getElementById('selectedOrderId').value = '';
                document.getElementById('selectedOrderDisplay').style.display = 'none';
                document.getElementById('orderSearchInput').value = '';
                document.getElementById('orderItemsList').innerHTML = '';
                document.getElementById('additionalProductsList').innerHTML = '';
                currentReturnItems = []; additionalProducts = []; additionalProductIndex = 0;
                openModalById('returnModal');
            }
            document.getElementById('returnNotes').value = '';
        }

        // =====================================================
        // PROCESS RETURN (with per‑item breakdown)
        // =====================================================
        let currentReturnItemsForProcess = [];
        async function openProcessReturnModal(returnId, returnNumber) {
            if (!canProcessReturns) { showToast('Permission denied. Level 50+ required.', 'error'); return; }
            currentProcessReturnId = returnId;
            document.getElementById('processReturnId').value = returnId;
            document.getElementById('processNotes').value = '';
            document.getElementById('processDecision').value = 'approved';
            document.getElementById('inventoryActionsContainer').style.display = 'none';
            const formData = new FormData();
            formData.append('ajax', 1); formData.append('action', 'get_return_items_for_processing'); formData.append('return_id', returnId);
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.items) {
                    currentReturnItemsForProcess = data.items;
                    renderInventoryActions(currentReturnItemsForProcess);
                    document.getElementById('inventoryActionsContainer').style.display = 'block';
                } else { showToast('Could not load return items', 'error'); }
            } catch(e) { console.error(e); }
            openModalById('processReturnModal');
        }
        function renderInventoryActions(items) {
            const container = document.getElementById('inventoryActionsList');
            if (!container) return;
            let html = '';
            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                const returnedQty = item.returned_quantity;
                html += `<div class="inventory-action-item" data-item-id="${item.id}">
                    <div class="item-header"><strong>${escapeHtml(item.original_product_name)}</strong> (Returned: ${returnedQty})</div>
                    <div class="quantity-fields">
                        <label>Resell Quantity: <input type="number" class="resell-qty" value="${returnedQty}" min="0" max="${returnedQty}" step="1"></label>
                        <label>Destroy Quantity: <input type="number" class="destroy-qty" value="0" min="0" max="${returnedQty}" step="1"></label>
                    </div>
                </div>`;
            }
            container.innerHTML = html;
            const itemsDivs = document.querySelectorAll('.inventory-action-item');
            itemsDivs.forEach(div => {
                const resellInput = div.querySelector('.resell-qty');
                const destroyInput = div.querySelector('.destroy-qty');
                const maxQty = parseInt(resellInput.max);
                function validate() {
                    let resell = parseInt(resellInput.value) || 0;
                    let destroy = parseInt(destroyInput.value) || 0;
                    if (resell + destroy > maxQty) {
                        if (resell > maxQty - destroy) resell = maxQty - destroy;
                        if (destroy > maxQty - resell) destroy = maxQty - resell;
                        resellInput.value = resell;
                        destroyInput.value = destroy;
                        showToast('Total cannot exceed returned quantity', 'error');
                    }
                }
                resellInput.addEventListener('input', validate);
                destroyInput.addEventListener('input', validate);
            });
        }
        async function submitProcessReturn() {
            if (!currentProcessReturnId) return;
            const status = document.getElementById('processDecision').value;
            const responseNotes = document.getElementById('processNotes').value;
            let inventoryActions = [];
            if (status === 'approved') {
                const items = document.querySelectorAll('.inventory-action-item');
                for (let i = 0; i < items.length; i++) {
                    const itemDiv = items[i];
                    const itemId = itemDiv.dataset.itemId;
                    const resell = parseInt(itemDiv.querySelector('.resell-qty').value) || 0;
                    const destroy = parseInt(itemDiv.querySelector('.destroy-qty').value) || 0;
                    inventoryActions.push({ return_item_id: itemId, resell: resell, destroy: destroy });
                }
            }
            const formData = new FormData();
            formData.append('ajax', 1); formData.append('action', 'process_return');
            formData.append('return_id', currentProcessReturnId); formData.append('status', status);
            formData.append('response_notes', responseNotes); formData.append('inventory_actions', JSON.stringify(inventoryActions));
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) { showToast(data.message, 'success'); closeModalById('processReturnModal'); setTimeout(() => location.reload(), 1000); }
                else { showToast(data.message, 'error'); }
            } catch(e) { showToast('Network error: ' + e.message, 'error'); }
            finally { hideLoading(); }
        }

        // =====================================================
        // EVENT LISTENERS
        // =====================================================
        document.getElementById('submitReturnBtn')?.addEventListener('click', submitReturn);
        document.getElementById('processReturnSubmitBtn')?.addEventListener('click', submitProcessReturn);
        document.getElementById('addProductBtn')?.addEventListener('click', showAddProductSearch);
        document.getElementById('applyBranchFilter')?.addEventListener('click', function() { window.location.href = window.location.pathname + '?branch=' + document.getElementById('branchFilterSelect').value; });
        document.getElementById('sourceFilter')?.addEventListener('change', applyFilters);
        document.getElementById('deliveryFilter')?.addEventListener('change', applyFilters);
        document.getElementById('dateFrom')?.addEventListener('change', applyFilters);
        document.getElementById('dateTo')?.addEventListener('change', applyFilters);
        document.getElementById('searchInput')?.addEventListener('input', applyFilters);
        document.getElementById('resetFiltersBtn')?.addEventListener('click', resetFilters);
        document.querySelectorAll('.update-btn').forEach(btn => btn.addEventListener('click', () => updateDelivery(btn)));
        document.querySelectorAll('.return-btn').forEach(btn => btn.addEventListener('click', function() { openReturnModal(this.dataset.orderId, this.dataset.orderNumber, this.dataset.customerName); }));
        document.getElementById('createReturnBtn')?.addEventListener('click', () => openReturnModal());
        document.getElementById('overlay')?.addEventListener('click', () => { document.querySelectorAll('.modal').forEach(m => m.classList.remove('show')); document.getElementById('overlay').classList.remove('active'); });
        document.getElementById('returnsContainer')?.addEventListener('click', function(e) { const btn = e.target.closest('.process-btn'); if (btn && canProcessReturns) openProcessReturnModal(btn.dataset.returnId, btn.dataset.returnNumber); });
        document.querySelectorAll('.tab').forEach(tab => { tab.addEventListener('click', function() { document.querySelectorAll('.tab').forEach(t => t.classList.remove('active')); document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active')); this.classList.add('active'); document.getElementById(this.dataset.tab + '-tab').classList.add('active'); }); });
        document.querySelectorAll('.order-body').forEach(body => body.style.display = 'none');
        initReturnTypeTabs();
        applyFilters();
    </script>
</body>
</html>