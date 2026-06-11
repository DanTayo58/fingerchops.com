<?php
// =====================================================
// FILE: dashboards/staff/tools/add-sale.php
// PURPOSE: POS System with Live Receipt Preview & Online Order Management
// VERSION: 6.4 - Fixed: sales_records inserted on order confirmation
// =====================================================

if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

session_start();

require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
require_once '../../../includes/Helpers.php';
require_once '../../../config/config_loader.php';

$db = Database::getInstance();

// Security check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../../login_signup.php');
    exit;
}

$userObj = new User($_SESSION['user_id']);
$user = $userObj->getData();

if (!$user) {
    header('Location: ../../../login_signup.php');
    exit;
}

// Determine user's department and permissions
$in_sales = false;
$is_cashier = false;
$privilege_level = $userObj->getPrivilegeLevel();
$user_branch_id = $user['branch_id'] ?? 1;
$branch_name = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$user_branch_id])['branch_name'] ?? 'Main Branch';
$can_edit_prices = ($privilege_level >= 60);

$active_role = $db->preparedFetchOne("
    SELECT r.*, ur.department_id as assigned_dept, d.dept_name, d.dept_code
    FROM user_roles ur
    JOIN roles r ON ur.role_id = r.id
    LEFT JOIN departments d ON (r.department_id = d.id OR ur.department_id = d.id)
    WHERE ur.user_id = ? AND ur.is_active = 1
    LIMIT 1
", 'i', [$_SESSION['user_id']]);

if ($active_role) {
    $role_name = $active_role['role_name'];
    $privilege_level = $active_role['privilege_level'];
    $dept_name = $active_role['dept_name'] ?? '';
    $dept_code = $active_role['dept_code'] ?? '';
    
    if (stripos($dept_name, 'sales') !== false || $dept_code === 'SL') {
        $in_sales = true;
    }
    
    if (stripos($role_name, 'cashier') !== false) {
        $is_cashier = true;
    }
}

if (!$in_sales && $privilege_level >= 50) {
    $in_sales = true;
}

if (!$in_sales && !$is_cashier) {
    header('Location: ../../login_signup.php?error=unauthorized');
    exit;
}

$can_edit_prices = ($privilege_level >= 60 || $is_cashier);

// Get all branches
$all_branches = $db->preparedFetchAll("
    SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name
", '', []);

// =====================================================
// GET PENDING ONLINE ORDERS - ALL PAYMENT METHODS
// =====================================================
$pending_orders = $db->preparedFetchAll("
    SELECT o.id, o.order_number, o.total_amount, o.payment_method, o.created_at,
           u.fullname as customer_name, u.user_id as customer_user_id,
           o.delivery_address, o.delivery_state, o.delivery_city, o.delivery_phone,
           o.delivery_branch_id, o.notes
    FROM customer_orders o
    JOIN bakery_users u ON o.user_id = u.id
    WHERE o.status = 'pending'
    ORDER BY o.created_at ASC
", '', []);

// Get order items for each pending order with stock info
foreach ($pending_orders as $key => $order) {
    $order_items = $db->preparedFetchAll("
        SELECT 
            oi.*, 
            p.name as product_name,
            COALESCE(ps.quantity, 0) as current_branch_stock,
            COALESCE(SUM(brp.quantity), 0) as posted_receipt_stock
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.branch_id = ?
        LEFT JOIN branch_receipt_posts brp ON brp.order_id = oi.order_id 
            AND brp.product_id = oi.product_id 
            AND brp.to_branch_id = ? 
            AND brp.status IN ('pending', 'accepted')
        WHERE oi.order_id = ?
        GROUP BY oi.id, oi.product_id, oi.quantity, oi.unit_price, oi.subtotal, p.name, ps.quantity
    ", 'iii', [$user_branch_id, $user_branch_id, $order['id']]);
    
    // Calculate total available stock for each item
    foreach ($order_items as &$item) {
        $item['total_available_stock'] = $item['current_branch_stock'] + $item['posted_receipt_stock'];
        $item['has_sufficient_stock'] = $item['total_available_stock'] >= $item['quantity'];
        $item['stock_shortfall'] = max(0, $item['quantity'] - $item['total_available_stock']);
    }
    
    $pending_orders[$key]['items'] = $order_items;
}

// Get pending stock requests (incoming)
$incoming_requests = $db->preparedFetchAll("
    SELECT oi.*, p.name as product_name, o.order_number, 
           u.fullname as customer_name, b.branch_name as requesting_branch_name
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN customer_orders o ON oi.order_id = o.id
    JOIN bakery_users u ON o.user_id = u.id
    JOIN branches b ON o.delivery_branch_id = b.id
    WHERE oi.request_to_branch_id = ? 
      AND oi.status = 'requested' 
      AND oi.request_status = 'pending'
    ORDER BY oi.created_at ASC
", 'i', [$user_branch_id]);

// Get incoming receipt posts
$incoming_receipt_posts = $db->preparedFetchAll("
    SELECT brp.*, p.name as product_name, b.branch_name as from_branch_name, o.order_number
    FROM branch_receipt_posts brp
    JOIN products p ON brp.product_id = p.id
    JOIN branches b ON brp.from_branch_id = b.id
    JOIN customer_orders o ON brp.order_id = o.id
    WHERE brp.to_branch_id = ? AND brp.status = 'pending'
    ORDER BY brp.created_at ASC
", 'i', [$user_branch_id]);

$incoming_requests_count = count($incoming_requests);
$incoming_receipts_count = count($incoming_receipt_posts);
$pending_orders_count = count($pending_orders);

// =====================================================
// HELPER FUNCTIONS
// =====================================================

function getProductStockAtBranch($product_id, $branch_id) {
    $db = Database::getInstance();
    $row = $db->preparedFetchOne("
        SELECT quantity FROM product_stock WHERE product_id = ? AND branch_id = ?
    ", 'ii', [$product_id, $branch_id]);
    return $row ? (int)$row['quantity'] : 0;
}

function getProductDiscount($product_id, $user_id = null) {
    $db = Database::getInstance();
    
    if ($user_id) {
        $roles = $db->preparedFetchAll("
            SELECT r.role_code 
            FROM user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? AND ur.is_active = 1
        ", 'i', [$user_id]);
        
        $role_codes = array_column($roles, 'role_code');
        if (empty($role_codes)) {
            $role_codes = ['CUSTOMER'];
        }
        
        $placeholders = implode(',', array_fill(0, count($role_codes), '?'));
        $discount = $db->preparedFetchOne("
            SELECT MAX(discount_percent) as discount 
            FROM product_role_discounts 
            WHERE product_id = ? AND role_code IN ($placeholders)
        ", 'i' . str_repeat('s', count($role_codes)), array_merge([$product_id], $role_codes));
        
        return $discount ? (float)$discount['discount'] : 0;
    }
    
    return 0;
}

function calculateDiscountedPrice($base_price, $discount_percent) {
    return $base_price * (1 - $discount_percent / 100);
}

function getCustomerByUserId($user_id) {
    $db = Database::getInstance();
    return $db->preparedFetchOne("
        SELECT id, fullname, user_type, email, phone, user_id 
        FROM bakery_users 
        WHERE user_id = ? OR id = ?
        LIMIT 1
    ", 'si', [$user_id, $user_id]);
}

function getReceiptNumber() {
    return 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// =====================================================
// AJAX HANDLERS
// =====================================================
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    // Search products with branch stock
    if ($action === 'search_products') {
        $term = '%' . trim($_POST['term'] ?? '') . '%';
        $branch_id = intval($_POST['branch_id'] ?? $user_branch_id);
        $products = $db->preparedFetchAll("
            SELECT p.id, p.name, p.base_price, COALESCE(ps.quantity, 0) as stock_quantity, p.category
            FROM products p
            LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.branch_id = ?
            WHERE p.is_active = 1 AND (p.name LIKE ? OR p.id LIKE ?)
            GROUP BY p.id
            ORDER BY p.name LIMIT 30
        ", 'iss', [$branch_id, $term, $term]);
        
        echo json_encode(['success' => true, 'products' => $products]);
        exit;
    }

    // Check for updates
    if ($action === 'check_for_updates') {
        $current_pending = $db->preparedFetchOne("SELECT COUNT(*) as count FROM customer_orders WHERE status = 'pending'", '', [])['count'] ?? 0;
        $current_requests = $db->preparedFetchOne("SELECT COUNT(*) as count FROM order_items WHERE request_to_branch_id = ? AND status = 'requested' AND request_status = 'pending'", 'i', [$user_branch_id])['count'] ?? 0;
        $current_receipts = $db->preparedFetchOne("SELECT COUNT(*) as count FROM branch_receipt_posts WHERE to_branch_id = ? AND status = 'pending'", 'i', [$user_branch_id])['count'] ?? 0;
        
        $response = [
            'success' => true,
            'pending_orders' => $current_pending,
            'incoming_requests' => $current_requests,
            'incoming_receipts' => $current_receipts
        ];
        echo json_encode($response);
        exit;
    }
    
    // Get product details with pricing for customer
    if ($action === 'get_product_pricing') {
        $product_id = intval($_POST['product_id']);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $branch_id = intval($_POST['branch_id'] ?? $user_branch_id);
        
        $product = $db->preparedFetchOne("
            SELECT p.id, p.name, p.base_price, COALESCE(ps.quantity, 0) as stock_quantity
            FROM products p
            LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.branch_id = ?
            WHERE p.id = ? AND p.is_active = 1
        ", 'ii', [$branch_id, $product_id]);
        
        if ($product) {
            $discount = 0;
            if ($customer_id > 0) {
                $discount = getProductDiscount($product_id, $customer_id);
            }
            $final_price = calculateDiscountedPrice($product['base_price'], $discount);
            
            $response = [
                'success' => true,
                'product' => [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'base_price' => $product['base_price'],
                    'final_price' => $final_price,
                    'discount' => $discount,
                    'stock' => $product['stock_quantity']
                ]
            ];
        } else {
            $response['message'] = 'Product not found';
        }
        echo json_encode($response);
        exit;
    }
    
    // Search customers
    if ($action === 'search_customers') {
        $term = '%' . trim($_POST['term'] ?? '') . '%';
        $customers = $db->preparedFetchAll("
            SELECT id, fullname, user_type, email, phone, user_id 
            FROM bakery_users 
            WHERE (user_type = 'customer' OR user_type = 'vendor') 
              AND (fullname LIKE ? OR email LIKE ? OR phone LIKE ? OR user_id LIKE ?)
            ORDER BY fullname LIMIT 20
        ", 'ssss', [$term, $term, $term, $term]);
        
        echo json_encode(['success' => true, 'customers' => $customers]);
        exit;
    }
    
    // Get customer by ID
    if ($action === 'get_customer_by_id') {
        $customer_id = trim($_POST['customer_id'] ?? '');
        if (empty($customer_id)) {
            echo json_encode(['success' => false, 'message' => 'Customer ID required']);
            exit;
        }
        
        $customer = getCustomerByUserId($customer_id);
        if ($customer) {
            echo json_encode(['success' => true, 'customer' => $customer]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Customer not found. Please ask them to register online.']);
        }
        exit;
    }
    
    // Post receipt to another branch
    if ($action === 'post_receipt') {
        $order_id = intval($_POST['order_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $target_branch_id = intval($_POST['target_branch_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($order_id <= 0 || $product_id <= 0 || $quantity <= 0 || $target_branch_id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid parameters'];
            echo json_encode($response);
            exit;
        }
        
        $stock = getProductStockAtBranch($product_id, $user_branch_id);
        if ($stock < $quantity) {
            $response = ['success' => false, 'message' => 'Insufficient stock to post this receipt'];
            echo json_encode($response);
            exit;
        }
        
        $existing = $db->preparedFetchOne("
            SELECT id FROM branch_receipt_posts 
            WHERE order_id = ? AND product_id = ? AND to_branch_id = ? AND status = 'pending'
        ", 'iii', [$order_id, $product_id, $target_branch_id]);
        
        if ($existing) {
            $response = ['success' => false, 'message' => 'A receipt post for this item is already pending'];
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        
        try {
            $db->preparedExecute("
                INSERT INTO branch_receipt_posts (from_branch_id, to_branch_id, order_id, product_id, quantity, notes, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ", 'iiiiis', [$user_branch_id, $target_branch_id, $order_id, $product_id, $quantity, $notes]);
            
            logActivity($_SESSION['user_id'], 
                "Posted receipt: {$quantity} units of product ID {$product_id} to branch {$target_branch_id} for order #{$order_id}", 
                'receipt_post', $db->lastInsertId());
            
            $db->commit();
            
            $response = [
                'success' => true, 
                'message' => 'Receipt posted successfully! The branch can now fulfill this item.'
            ];
        } catch (Exception $e) {
            $db->rollback();
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Accept receipt post
    if ($action === 'accept_receipt_post') {
        $receipt_id = intval($_POST['receipt_id'] ?? 0);
        
        $receipt = $db->preparedFetchOne("
            SELECT brp.*, o.delivery_branch_id
            FROM branch_receipt_posts brp
            JOIN customer_orders o ON brp.order_id = o.id
            WHERE brp.id = ? AND brp.to_branch_id = ? AND brp.status = 'pending'
        ", 'ii', [$receipt_id, $user_branch_id]);
        
        if (!$receipt) {
            $response = ['success' => false, 'message' => 'Receipt not found or already processed'];
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        
        try {
            $db->preparedExecute("
                UPDATE branch_receipt_posts SET status = 'accepted' WHERE id = ?
            ", 'i', [$receipt_id]);
            
            logActivity($_SESSION['user_id'], 
                "Accepted receipt post for order #{$receipt['order_id']} - {$receipt['quantity']} units of product ID {$receipt['product_id']}", 
                'receipt_post', $receipt_id);
            
            $db->commit();
            
            $response = [
                'success' => true, 
                'message' => 'Receipt accepted! Stock is now available for this order.'
            ];
        } catch (Exception $e) {
            $db->rollback();
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        
        echo json_encode($response);
        exit;
    }

    // Reject receipt post
    if ($action === 'reject_receipt_post') {
        $receipt_id = intval($_POST['receipt_id'] ?? 0);
        $reject_reason = trim($_POST['reject_reason'] ?? 'No reason provided');
        
        $receipt = $db->preparedFetchOne("
            SELECT * FROM branch_receipt_posts 
            WHERE id = ? AND to_branch_id = ? AND status = 'pending'
        ", 'ii', [$receipt_id, $user_branch_id]);
        
        if (!$receipt) {
            $response = ['success' => false, 'message' => 'Receipt not found or already processed'];
            echo json_encode($response);
            exit;
        }
        
        $db->preparedExecute("
            UPDATE branch_receipt_posts SET status = 'rejected', notes = CONCAT(COALESCE(notes, ''), ' | Rejected: ', ?) WHERE id = ?
        ", 'si', [$reject_reason, $receipt_id]);
        
        logActivity($_SESSION['user_id'], 
            "Rejected receipt post for order #{$receipt['order_id']} - Reason: {$reject_reason}", 
            'receipt_post', $receipt_id);
        
        $response = ['success' => true, 'message' => 'Receipt rejected'];
        echo json_encode($response);
        exit;
    }
    
    // Create outgoing stock request
    if ($action === 'create_stock_request') {
        $order_id = intval($_POST['order_id'] ?? 0);
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $target_branch_id = intval($_POST['target_branch_id'] ?? 0);
        $request_notes = trim($_POST['request_notes'] ?? '');
        
        if ($order_id <= 0 || $product_id <= 0 || $quantity <= 0 || $target_branch_id <= 0) {
            $response = ['success' => false, 'message' => 'Invalid request parameters'];
            echo json_encode($response);
            exit;
        }
        
        $order_item = $db->preparedFetchOne("
            SELECT id FROM order_items 
            WHERE order_id = ? AND product_id = ? AND status = 'pending'
        ", 'ii', [$order_id, $product_id]);
        
        if (!$order_item) {
            $response = ['success' => false, 'message' => 'Order item not found'];
            echo json_encode($response);
            exit;
        }
        
        $item_id = $order_item['id'];
        
        $existing = $db->preparedFetchOne("
            SELECT id FROM order_items 
            WHERE id = ? AND status = 'requested' AND request_status = 'pending'
        ", 'i', [$item_id]);
        
        if ($existing) {
            $response = ['success' => false, 'message' => 'A request for this item is already pending'];
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        
        try {
            $db->preparedExecute("
                UPDATE order_items SET 
                    status = 'requested',
                    request_to_branch_id = ?,
                    request_status = 'pending',
                    request_notes = ?
                WHERE id = ?
            ", 'isi', [$target_branch_id, $request_notes, $item_id]);
            
            logActivity($_SESSION['user_id'], 
                "Requested {$quantity} units of product ID {$product_id} from branch {$target_branch_id} for order #{$order_id}", 
                'stock_request', $item_id);
            
            $db->commit();
            
            $response = [
                'success' => true, 
                'message' => 'Stock request sent successfully'
            ];
        } catch (Exception $e) {
            $db->rollback();
            $response = ['success' => false, 'message' => $e->getMessage()];
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Process a stock request (approve/fulfill incoming)
    if ($action === 'process_stock_request') {
        $request_id = intval($_POST['request_id'] ?? 0);
        $action_type = $_POST['request_action'] ?? '';
        
        $request = $db->preparedFetchOne("
            SELECT oi.*, p.name as product_name, o.order_number, o.delivery_branch_id
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN customer_orders o ON oi.order_id = o.id
            WHERE oi.id = ? AND oi.request_to_branch_id = ? 
            AND oi.status = 'requested' AND oi.request_status = 'pending'
        ", 'ii', [$request_id, $user_branch_id]);
        
        if (!$request) {
            $response = ['success' => false, 'message' => 'Request not found or already processed'];
            echo json_encode($response);
            exit;
        }
        
        if ($action_type === 'approve') {
            $stock = getProductStockAtBranch($request['product_id'], $user_branch_id);
            if ($stock < $request['quantity']) {
                $response = ['success' => false, 'message' => 'Insufficient stock to fulfill this request'];
                echo json_encode($response);
                exit;
            }
            
            $db->beginTransaction();
            try {
                $db->preparedExecute("
                    UPDATE product_stock SET quantity = quantity - ? 
                    WHERE product_id = ? AND branch_id = ?
                ", 'iii', [$request['quantity'], $request['product_id'], $user_branch_id]);
                
                $db->preparedExecute("
                    INSERT INTO product_stock (product_id, branch_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ", 'iiii', [$request['product_id'], $request['delivery_branch_id'], $request['quantity'], $request['quantity']]);
                
                $db->preparedExecute("
                    UPDATE order_items SET 
                        status = 'transferred',
                        request_status = 'approved'
                    WHERE id = ?
                ", 'i', [$request_id]);
                
                logActivity($_SESSION['user_id'], "Approved stock request for {$request['product_name']} (Order #{$request['order_number']})", 'stock_request', $request_id);
                
                $db->commit();
                $response = ['success' => true, 'message' => 'Stock transferred successfully'];
            } catch (Exception $e) {
                $db->rollback();
                $response = ['success' => false, 'message' => $e->getMessage()];
            }
        } elseif ($action_type === 'reject') {
            $reject_reason = trim($_POST['reject_reason'] ?? 'No reason provided');
            $db->preparedExecute("
                UPDATE order_items SET 
                    request_status = 'rejected',
                    request_notes = CONCAT(COALESCE(request_notes, ''), ' | Rejected: ', ?)
                WHERE id = ?
            ", 'si', [$reject_reason, $request_id]);
            
            logActivity($_SESSION['user_id'], "Rejected stock request for {$request['product_name']}: {$reject_reason}", 'stock_request', $request_id);
            $response = ['success' => true, 'message' => 'Request rejected'];
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Confirm online order - FIXED: Now inserts into sales_records
    if ($action === 'confirm_online_order') {
        $order_id = intval($_POST['order_id'] ?? 0);
        
        $order = $db->preparedFetchOne("
            SELECT o.*, u.fullname as customer_name
            FROM customer_orders o
            JOIN bakery_users u ON o.user_id = u.id
            WHERE o.id = ? AND o.status = 'pending'
        ", 'i', [$order_id]);
        
        if (!$order) {
            $response = ['success' => false, 'message' => 'Order not found or already processed'];
            echo json_encode($response);
            exit;
        }
        
        $order_items = $db->preparedFetchAll("
            SELECT oi.*, p.name,
                COALESCE(brp.quantity, 0) as posted_receipt_quantity
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            LEFT JOIN branch_receipt_posts brp ON brp.order_id = oi.order_id 
                AND brp.product_id = oi.product_id 
                AND brp.to_branch_id = ? 
                AND brp.status = 'accepted'
            WHERE oi.order_id = ?
            GROUP BY oi.id
        ", 'ii', [$user_branch_id, $order_id]);
        
        if (empty($order_items)) {
            $response = ['success' => false, 'message' => 'Order has no items'];
            echo json_encode($response);
            exit;
        }
        
        $delivery_branch_id = $order['delivery_branch_id'] ?? $user_branch_id;
        
        $db->beginTransaction();
        
        try {
            foreach ($order_items as $item) {
                $current_stock = getProductStockAtBranch($item['product_id'], $delivery_branch_id);
                $total_available = $current_stock + $item['posted_receipt_quantity'];
                
                if ($total_available < $item['quantity']) {
                    throw new Exception("Insufficient stock for {$item['name']}. Available: {$total_available}, Need: {$item['quantity']}");
                }
                
                $deduct_from_current = min($item['quantity'], $current_stock);
                if ($deduct_from_current > 0) {
                    $db->preparedExecute("
                        UPDATE product_stock SET quantity = quantity - ? 
                        WHERE product_id = ? AND branch_id = ?
                    ", 'iii', [$deduct_from_current, $item['product_id'], $delivery_branch_id]);
                }
                
                $used_from_receipts = $item['quantity'] - $deduct_from_current;
                if ($used_from_receipts > 0) {
                    $db->preparedExecute("
                        UPDATE branch_receipt_posts SET status = 'completed'
                        WHERE order_id = ? AND product_id = ? AND to_branch_id = ? AND status = 'accepted'
                        LIMIT 1
                    ", 'iii', [$order_id, $item['product_id'], $user_branch_id]);
                }
            }
            
            // FIX: Insert into sales_records for each order item
            foreach ($order_items as $item) {
                $db->preparedExecute("
                    INSERT INTO sales_records (product_id, branch_id, quantity_sold, sale_price, sale_date, 
                                    customer_id, recorded_by, notes, created_at) 
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, NOW())
                ", 'iiidisi', [
                    $item['product_id'],
                    $delivery_branch_id,
                    $item['quantity'],
                    $item['unit_price'],
                    $order['user_id'],
                    $_SESSION['user_id'],
                    "Order #{$order['order_number']} confirmed"
                ]);
            }
            
            $db->preparedExecute("
                UPDATE customer_orders SET status = 'confirmed' WHERE id = ?
            ", 'i', [$order_id]);
            
            $db->preparedExecute("
                UPDATE order_items SET status = 'handled' WHERE order_id = ?
            ", 'i', [$order_id]);
            
            logActivity($_SESSION['user_id'], "Confirmed order #{$order['order_number']}", 'order', $order_id);
            
            $db->commit();
            
            $response = [
                'success' => true,
                'message' => "Order #{$order['order_number']} confirmed successfully"
            ];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Cancel online order
    if ($action === 'cancel_online_order') {
        $order_id = intval($_POST['order_id'] ?? 0);
        
        $order = $db->preparedFetchOne("
            SELECT order_number FROM customer_orders 
            WHERE id = ? AND status = 'pending'
        ", 'i', [$order_id]);
        
        if (!$order) {
            $response = ['success' => false, 'message' => 'Order not found or already processed'];
            echo json_encode($response);
            exit;
        }
        
        $db->preparedExecute("
            UPDATE customer_orders SET status = 'cancelled' WHERE id = ?
        ", 'i', [$order_id]);
        
        logActivity($_SESSION['user_id'], "Cancelled order #{$order['order_number']}", 'order', $order_id);
        
        $response = ['success' => true, 'message' => "Order #{$order['order_number']} cancelled"];
        echo json_encode($response);
        exit;
    }
    
    // Process in-store sale (POS)
    if ($action === 'process_sale') {
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'cash');
        $reference_number = trim($_POST['reference_number'] ?? '');
        
        if (empty($cart)) {
            echo json_encode(['success' => false, 'message' => 'Cart is empty']);
            exit;
        }
        
        if ($payment_method !== 'cash' && empty($reference_number)) {
            echo json_encode(['success' => false, 'message' => 'Reference number required for ' . strtoupper($payment_method)]);
            exit;
        }
        
        if ($payment_method !== 'cash' && strlen($reference_number) < 5) {
            echo json_encode(['success' => false, 'message' => 'Reference number must be at least 5 characters']);
            exit;
        }
        
        $order_number = 'CAS-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $receipt_number = getReceiptNumber();
        
        $db->beginTransaction();
        
        try {
            $total_amount = 0;
            $sale_items = [];
            
            foreach ($cart as $item) {
                $product_id = $item['product_id'];
                $quantity = $item['quantity'];
                $price = $item['final_price'];
                $subtotal = $price * $quantity;
                $total_amount += $subtotal;
                
                $stock = getProductStockAtBranch($product_id, $user_branch_id);
                if ($stock < $quantity) {
                    throw new Exception("Insufficient stock for {$item['name']}. Available: {$stock}");
                }
                
                $sale_items[] = [
                    'product_id' => $product_id,
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $subtotal,
                    'name' => $item['name']
                ];
            }
            
            $delivery_address = 'In-store purchase';
            $delivery_state = 'In-store';
            $delivery_city = 'In-store';
            $delivery_phone = $user['phone'] ?? 'N/A';
            $delivery_branch_id = $user_branch_id;
            $location_id = null;
            
            $order_sql = "INSERT INTO customer_orders (
                user_id, order_number, total_amount, delivery_fee, base_delivery_fee, 
                fuel_surcharge, branch_multiplier, branch_multiplier_applied, 
                status, payment_method, notes, 
                delivery_address, delivery_state, delivery_city, delivery_phone, 
                delivery_branch_id, location_id, created_at
            ) VALUES (
                ?, ?, ?, 0, 0, 
                0, 1, 1, 
                'confirmed', ?, ?, 
                ?, ?, ?, ?, 
                ?, ?, NOW()
            )";
            
            $customer_id_val = $customer_id ?: null;
            $notes = "Cashier transaction | Receipt: {$receipt_number} | Payment: {$payment_method}" . ($reference_number ? " | Ref: {$reference_number}" : "");
            
            $order_stmt = $db->prepare($order_sql);
            if (!$order_stmt) {
                throw new Exception("Failed to prepare order statement");
            }
            
            $order_stmt->bind_param(
                'isdssssssii',
                $customer_id_val,
                $order_number,
                $total_amount,
                $payment_method,
                $notes,
                $delivery_address,
                $delivery_state,
                $delivery_city,
                $delivery_phone,
                $delivery_branch_id,
                $location_id
            );
            
            if (!$order_stmt->execute()) {
                throw new Exception("Failed to create order record: " . $order_stmt->error);
            }
            
            $order_id = $db->lastInsertId();
            $order_stmt->close();
            
            foreach ($sale_items as $item) {
                $item_sql = "INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, 'handled', NOW())";
                $item_stmt = $db->prepare($item_sql);
                if (!$item_stmt) {
                    throw new Exception("Failed to prepare item statement");
                }
                
                $item_stmt->bind_param('iiidd', $order_id, $item['product_id'], $item['quantity'], $item['price'], $item['subtotal']);
                if (!$item_stmt->execute()) {
                    throw new Exception("Failed to create order item: " . $item_stmt->error);
                }
                $item_stmt->close();
            }
            
            foreach ($sale_items as $item) {
                $success = $db->preparedExecute("
                    INSERT INTO sales_records (product_id, branch_id, quantity_sold, sale_price, sale_date, 
                                    customer_id, recorded_by, notes, created_at) 
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, NOW())
                ", 'iiidisi', [
                    $item['product_id'],
                    $user_branch_id,
                    $item['quantity'],
                    $item['price'],
                    $customer_id_val,
                    $_SESSION['user_id'],
                    "Receipt: {$receipt_number} | Order: {$order_number}"
                ]);
                
                if (!$success) {
                    throw new Exception('Failed to record sale for ' . $item['name']);
                }
                
                $db->preparedExecute("
                    UPDATE product_stock SET quantity = quantity - ? 
                    WHERE product_id = ? AND branch_id = ?
                ", 'iii', [$item['quantity'], $item['product_id'], $user_branch_id]);
            }
            
            logActivity($_SESSION['user_id'], 
                "Processed cashier sale: Order #{$order_number} | Receipt {$receipt_number} | Total: ₦" . number_format($total_amount, 2) . 
                " | Payment: {$payment_method}" . ($reference_number ? " | Ref: {$reference_number}" : ""),
                'sale', $order_id);
            
            $db->commit();
            
            $response = [
                'success' => true,
                'message' => 'Sale completed successfully',
                'order_number' => $order_number,
                'receipt_number' => $receipt_number,
                'total_amount' => $total_amount,
                'items' => $sale_items,
                'customer' => $customer_id ? getCustomerByUserId($customer_id) : null,
                'payment_method' => $payment_method,
                'reference_number' => $reference_number
            ];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        
        echo json_encode($response);
        exit;
    }
    
    // Get today's sales summary
    if ($action === 'get_today_summary') {
        $total_sales = $db->preparedFetchOne("
            SELECT COALESCE(SUM(quantity_sold * sale_price), 0) as total 
            FROM sales_records 
            WHERE DATE(sale_date) = CURDATE()
        ", '', [])['total'] ?? 0;
        
        $total_transactions = $db->preparedFetchOne("
            SELECT COUNT(DISTINCT notes) as count 
            FROM sales_records 
            WHERE DATE(sale_date) = CURDATE() AND notes LIKE 'Receipt: %'
        ", '', [])['count'] ?? 0;
        
        $total_items = $db->preparedFetchOne("
            SELECT COALESCE(SUM(quantity_sold), 0) as total 
            FROM sales_records 
            WHERE DATE(sale_date) = CURDATE()
        ", '', [])['total'] ?? 0;
        
        $response = [
            'success' => true,
            'total_sales' => $total_sales,
            'total_transactions' => $total_transactions,
            'total_items' => $total_items
        ];
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// Get today's total for display
$today_total = $db->preparedFetchOne("
    SELECT COALESCE(SUM(quantity_sold * sale_price), 0) as total 
    FROM sales_records 
    WHERE DATE(sale_date) = CURDATE()
", '', [])['total'] ?? 0;

$today_transactions = $db->preparedFetchOne("
    SELECT COUNT(DISTINCT notes) as count 
    FROM sales_records 
    WHERE DATE(sale_date) = CURDATE() AND notes LIKE 'Receipt: %'
", '', [])['count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>POS System · Fingerchops Ventures</title>
    <link rel="icon" href="../../../logo.jpeg" type="image/jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/add-sale.css">
</head>
<body>
    <div class="pos-split">
        <!-- LEFT SIDE - Operations -->
        <div class="pos-left">
            <!-- Header with Title and Stats Side by Side -->
            <div class="pos-header">
                <div class="header-title">
                    <h1><i class="fas fa-cash-register"></i> Cashier Console</h1>
                    <div class="action-buttons">
                        <div class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?></div>
                        <button class="action-btn requests-btn" id="viewRequestsBtn">
                            <i class="fas fa-exchange-alt"></i> Order Posts
                            <?php if ($incoming_requests_count + $incoming_receipts_count > 0): ?>
                                <span class="badge-count"><?php echo $incoming_requests_count + $incoming_receipts_count; ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-value">₦<?php echo number_format($today_total, 2); ?></div>
                        <div class="stat-label">Today's Sales</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $today_transactions; ?></div>
                        <div class="stat-label">Transactions</div>
                    </div>
                </div>
            </div>
            
            <!-- Main Tabs -->
            <div class="main-tabs">
                <button class="main-tab active" data-tab="pos">POS Transaction</button>
                <button class="main-tab" data-tab="online-orders">
                    Online Orders
                    <?php if ($pending_orders_count > 0): ?>
                        <span class="badge-count"><?php echo $pending_orders_count; ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <!-- POS Tab -->
            <div id="pos-tab" class="tab-pane active">
                <div class="customer-section">
                    <div class="customer-search">
                        <input type="text" id="customerSearchInput" placeholder="Search customer by name, email, or ID (e.g., FNG-CS-...)" autocomplete="off">
                        <div class="customer-suggestions" id="customerSuggestions"></div>
                    </div>
                    <div id="selectedCustomer" class="selected-customer" style="display: none;"></div>
                    <input type="hidden" id="customerId" value="0">
                </div>
                
                <div class="product-search-section">
                    <div class="search-box">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="productSearch" placeholder="Search products by name or ID..." autocomplete="off">
                    </div>
                </div>
                
                <div class="products-scrollable">
                    <div class="products-grid" id="productsGrid">
                        <div class="search-prompt" style="position: relative; margin-top: 0;">
                            <i class="fas fa-search"></i>
                            <p>Type at least 2 characters to search for products</p>
                        </div>
                    </div>
                </div>
                
                <div class="cart-section">
                    <div class="cart-header">
                        <i class="fas fa-shopping-cart"></i> Current Sale
                        <span class="cart-item-count" id="cartItemCount">0 items</span>
                    </div>
                    <div class="cart-items-scrollable">
                        <div class="cart-items" id="cartItems">
                            <div class="empty-cart">
                                <i class="fas fa-shopping-basket"></i>
                                <p>Cart is empty</p>
                                <small>Add products to begin</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="payment-section">
                    <div class="payment-options">
                        <div class="payment-option selected" data-method="cash">
                            <i class="fas fa-money-bill-wave"></i> Cash
                        </div>
                        <div class="payment-option" data-method="pos">
                            <i class="fas fa-credit-card"></i> POS
                        </div>
                        <div class="payment-option" data-method="bank_transfer">
                            <i class="fas fa-university"></i> Transfer
                        </div>
                    </div>
                    
                    <div class="reference-input" id="referenceInput">
                        <input type="text" id="referenceNumber" placeholder="Enter reference number (min 5 characters)" autocomplete="off">
                    </div>
                    
                    <button class="btn-checkout" id="checkoutBtn">Checkout</button>
                </div>
            </div>
            
            <!-- Online Orders Tab -->
            <div id="online-orders-tab" class="tab-pane">
                <div class="scrollable-orders">
                    <?php if (empty($pending_orders)): ?>
                        <div class="empty-state" style="text-align: center; padding: 3rem;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                            <p>No pending online orders</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_orders as $order): ?>
                            <div class="order-card" data-order-id="<?php echo $order['id']; ?>" onclick="viewOrderDetails(<?php echo htmlspecialchars(json_encode($order)); ?>)">
                                <div class="order-header">
                                    <div>
                                        <span class="order-number">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                                        <span> · <?php echo htmlspecialchars($order['customer_name']); ?></span>
                                    </div>
                                    <div class="order-amount">₦<?php echo number_format($order['total_amount'], 2); ?></div>
                                </div>
                                <div class="order-details">
                                    <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($order['delivery_address']); ?></div>
                                    <div><i class="fas fa-clock"></i> <?php echo date('M j, H:i', strtotime($order['created_at'])); ?></div>
                                    <div><i class="fas fa-credit-card"></i> <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></div>
                                </div>
                                <div class="order-items-preview">
                                    <?php if (isset($order['items']) && count($order['items']) > 0): 
                                        $totalQty = array_sum(array_column($order['items'], 'quantity'));
                                        $hasShortfall = false;
                                        foreach ($order['items'] as $item) {
                                            if (!$item['has_sufficient_stock']) {
                                                $hasShortfall = true;
                                                break;
                                            }
                                        }
                                    ?>
                                        <small>
                                            <i class="fas fa-box"></i> <?php echo $totalQty; ?> items • <?php echo count($order['items']); ?> product(s)
                                            <?php if ($hasShortfall): ?>
                                                <span style="color: #f59e0b; margin-left: 0.5rem;"><i class="fas fa-exclamation-triangle"></i> Low stock</span>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <small style="color: #dc3545;">
                                            <i class="fas fa-exclamation-circle"></i> Items not loaded (Order ID: <?php echo $order['id']; ?>)
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="back-link-container">
                <a href="../sales-dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
        
        <!-- RIGHT SIDE - Live Receipt Preview -->
        <div class="pos-right">
            <div class="right-header">
                <h3><i class="fas fa-receipt"></i> Receipt Preview</h3>
            </div>
            <div class="receipt-scrollable">
                <div class="receipt-preview" id="receiptPreview">
                    <div class="receipt-empty">
                        <i class="fas fa-receipt"></i>
                        <p>Cart is empty</p>
                        <small>Add items to see receipt preview</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Incoming Items Modal (Stock Requests + Receipt Posts) -->
    <div id="incomingItemsModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Incoming Items</h3>
                <button class="modal-close" onclick="closeIncomingItemsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="incomingRequestsList">
                    <h4 style="margin: 0 0 0.5rem 0;"><i class="fas fa-paper-plane"></i> Stock Requests</h4>
                    <?php if (empty($incoming_requests)): ?>
                        <div class="empty-state-small" style="color: #94a3b8; font-size: 0.8rem; margin-bottom: 1rem;">No pending stock requests</div>
                    <?php else: ?>
                        <?php foreach ($incoming_requests as $req): ?>
                            <div class="request-item" data-request-id="<?php echo $req['id']; ?>">
                                <div><strong><?php echo htmlspecialchars($req['product_name']); ?></strong></div>
                                <div>Quantity: <?php echo $req['quantity']; ?></div>
                                <div>From: <?php echo htmlspecialchars($req['requesting_branch_name']); ?></div>
                                <div>Order: <?php echo htmlspecialchars($req['order_number']); ?></div>
                                <div>Customer: <?php echo htmlspecialchars($req['customer_name']); ?></div>
                                <?php if ($req['request_notes']): ?>
                                    <div><small>Notes: <?php echo htmlspecialchars($req['request_notes']); ?></small></div>
                                <?php endif; ?>
                                <div class="request-actions" style="margin-top: 0.5rem;">
                                    <button class="btn-confirm" onclick="processRequest(<?php echo $req['id']; ?>, 'approve')">
                                        <i class="fas fa-check"></i> Approve & Transfer
                                    </button>
                                    <button class="btn-cancel" onclick="showRejectReason(<?php echo $req['id']; ?>)">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                                <div id="reject-reason-<?php echo $req['id']; ?>" class="reject-reason-input" style="display: none; margin-top: 0.5rem;">
                                    <textarea id="reject-reason-text-<?php echo $req['id']; ?>" class="form-control" rows="2" placeholder="Reason for rejection..."></textarea>
                                    <div style="margin-top: 0.5rem;">
                                        <button class="btn-secondary" onclick="hideRejectReason(<?php echo $req['id']; ?>)">Cancel</button>
                                        <button class="btn-danger" onclick="processRequest(<?php echo $req['id']; ?>, 'reject')">Confirm Reject</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div id="incomingReceiptsList" style="margin-top: 1.5rem;">
                    <h4 style="margin: 0 0 0.5rem 0;"><i class="fas fa-receipt"></i> Receipt Posts</h4>
                    <?php if (empty($incoming_receipt_posts)): ?>
                        <div class="empty-state-small" style="color: #94a3b8; font-size: 0.8rem;">No pending receipt posts</div>
                    <?php else: ?>
                        <?php foreach ($incoming_receipt_posts as $receipt): ?>
                            <div class="request-item" data-receipt-id="<?php echo $receipt['id']; ?>">
                                <div><strong><?php echo htmlspecialchars($receipt['product_name']); ?></strong></div>
                                <div>Quantity: <?php echo $receipt['quantity']; ?></div>
                                <div>From: <?php echo htmlspecialchars($receipt['from_branch_name']); ?></div>
                                <div>Order: <?php echo htmlspecialchars($receipt['order_number']); ?></div>
                                <?php if ($receipt['notes']): ?>
                                    <div><small>Notes: <?php echo htmlspecialchars($receipt['notes']); ?></small></div>
                                <?php endif; ?>
                                <div class="request-actions" style="margin-top: 0.5rem;">
                                    <button class="btn-confirm" onclick="acceptReceiptPost(<?php echo $receipt['id']; ?>)">
                                        <i class="fas fa-check"></i> Accept Receipt
                                    </button>
                                    <button class="btn-cancel" onclick="showReceiptRejectReason(<?php echo $receipt['id']; ?>)">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                                <div id="receipt-reject-reason-<?php echo $receipt['id']; ?>" class="reject-reason-input" style="display: none; margin-top: 0.5rem;">
                                    <textarea id="receipt-reject-text-<?php echo $receipt['id']; ?>" class="form-control" rows="2" placeholder="Reason for rejection..."></textarea>
                                    <div style="margin-top: 0.5rem;">
                                        <button class="btn-secondary" onclick="hideReceiptRejectReason(<?php echo $receipt['id']; ?>)">Cancel</button>
                                        <button class="btn-danger" onclick="rejectReceiptPost(<?php echo $receipt['id']; ?>)">Confirm Reject</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeIncomingItemsModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Outgoing Stock Request Modal -->
    <div id="outgoingStockRequestModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3><i class="fas fa-paper-plane"></i> Request Stock from Another Branch</h3>
                <button class="modal-close" onclick="closeOutgoingStockRequestModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="stockRequestInfo" style="background: #f8fafc; padding: 0.75rem; border-radius: 0.75rem; margin-bottom: 1rem;">
                    <div><strong>Product:</strong> <span id="requestProductName"></span></div>
                    <div><strong>Quantity Needed:</strong> <span id="requestQuantity"></span></div>
                    <div><strong>Order #:</strong> <span id="requestOrderNumber"></span></div>
                </div>
                
                <div class="form-group">
                    <label for="targetBranchSelect">Request From Branch <span class="required">*</span></label>
                    <select id="targetBranchSelect" class="form-control" required>
                        <option value="">-- Select Branch --</option>
                        <?php foreach ($all_branches as $branch): ?>
                            <?php if ($branch['id'] != $user_branch_id): ?>
                                <option value="<?php echo $branch['id']; ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="requestNotes">Request Notes (optional)</label>
                    <textarea id="requestNotes" class="form-control" rows="3" placeholder="Any special instructions..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeOutgoingStockRequestModal()">Cancel</button>
                <button class="btn-primary" id="submitStockRequestBtn" onclick="submitStockRequest()">
                    <i class="fas fa-paper-plane"></i> Send Request
                </button>
            </div>
        </div>
    </div>
    
    <!-- Post Receipt to Another Branch Modal -->
    <div id="postReceiptModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Post Receipt to Another Branch</h3>
                <button class="modal-close" onclick="closePostReceiptModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="receiptPostInfo" style="background: #f8fafc; padding: 0.75rem; border-radius: 0.75rem; margin-bottom: 1rem;">
                    <div><strong>Product:</strong> <span id="postProductName"></span></div>
                    <div><strong>Quantity to Post:</strong> <span id="postQuantity"></span></div>
                    <div><strong>Order #:</strong> <span id="postOrderNumber"></span></div>
                    <div><strong>Your Branch Stock:</strong> <span id="postYourStock"></span></div>
                </div>
                
                <div class="form-group">
                    <label for="targetBranchSelectPost">Post To Branch <span class="required">*</span></label>
                    <select id="targetBranchSelectPost" class="form-control" required>
                        <option value="">-- Select Branch --</option>
                        <?php foreach ($all_branches as $branch): ?>
                            <?php if ($branch['id'] != $user_branch_id): ?>
                                <option value="<?php echo $branch['id']; ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="postNotes">Notes (optional)</label>
                    <textarea id="postNotes" class="form-control" rows="3" placeholder="Any special instructions..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closePostReceiptModal()">Cancel</button>
                <button class="btn-primary" id="submitPostReceiptBtn" onclick="submitPostReceipt()">
                    <i class="fas fa-receipt"></i> Post Receipt
                </button>
            </div>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Order Details</h3>
                <button class="modal-close" onclick="closeOrderDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="orderDetailsBody">
                <div class="loading-order">Loading order details...</div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeOrderDetailsModal()">Close</button>
                <button class="btn-primary" id="confirmOrderBtn" onclick="confirmCurrentOrder()">
                    <i class="fas fa-check"></i> Confirm Order
                </button>
                <button class="btn-danger" id="cancelOrderBtn" onclick="cancelCurrentOrder()">
                    <i class="fas fa-times"></i> Cancel Order
                </button>
            </div>
        </div>
    </div>
    
    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="receipt-modal">
            <div class="receipt-preview" id="finalReceiptBody"></div>
            <div class="modal-buttons">
                <button class="btn-print" onclick="printFinalReceipt()"><i class="fas fa-print"></i> Print Receipt</button>
                <button class="btn-close" onclick="closeReceiptModal()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>
    
    <div id="toastContainer" class="toast-container"></div>
    <div id="overlay" class="overlay"></div>
    
    <script>
        // State
        let cart = [];
        let currentPaymentMethod = 'cash';
        let currentCustomer = null;
        let lastReceiptData = null;
        let currentOrderData = null;
        let currentRequestData = null;
        let currentPostData = null;
        
        const userBranchId = <?php echo $user_branch_id; ?>;
        const canEditPrices = <?php echo $can_edit_prices ? 'true' : 'false'; ?>;
        const pendingOrdersCountInitial = <?php echo $pending_orders_count; ?>;
        
        let pendingOrdersCount = pendingOrdersCountInitial;
        let incomingRequestsCount = <?php echo $incoming_requests_count; ?>;
        let incomingReceiptsCount = <?php echo $incoming_receipts_count; ?>;

        // =====================================================
        // AUTO-REFRESH FUNCTIONS
        // =====================================================
        let autoRefreshTimer = null;
        let refreshCountdown = 180;
        let isRefreshing = false;
        const AUTO_REFRESH_INTERVAL = 180;

        function saveScrollPosition() {
            const scrollableElements = document.querySelectorAll('.pos-left, .products-scrollable, .scrollable-orders, .receipt-scrollable');
            const positions = {};
            
            scrollableElements.forEach(el => {
                if (el) {
                    const id = el.className || el.id;
                    positions[id] = el.scrollTop;
                }
            });
            
            positions.mainScroll = window.scrollY;
            positions.cartItems = document.querySelector('.cart-items')?.scrollTop || 0;
            positions.productsGrid = document.querySelector('.products-grid')?.parentElement?.scrollTop || 0;
            
            sessionStorage.setItem('posScrollPositions', JSON.stringify(positions));
            sessionStorage.setItem('posCurrentTab', document.querySelector('.main-tab.active')?.dataset.tab || 'pos');
            sessionStorage.setItem('posCartLength', cart.length);
        }

        function restoreScrollPosition() {
            const saved = sessionStorage.getItem('posScrollPositions');
            if (saved) {
                try {
                    const positions = JSON.parse(saved);
                    if (positions.mainScroll) window.scrollTo(0, positions.mainScroll);
                    
                    Object.keys(positions).forEach(key => {
                        if (key !== 'mainScroll' && key !== 'productsGrid' && key !== 'cartItems') {
                            const element = document.querySelector(`.${key}, #${key}`);
                            if (element && positions[key] !== undefined) {
                                element.scrollTop = positions[key];
                            }
                        }
                    });
                    
                    const productsContainer = document.querySelector('.products-scrollable');
                    if (productsContainer && positions.productsGrid) {
                        productsContainer.scrollTop = positions.productsGrid;
                    }
                    
                    const cartContainer = document.querySelector('.cart-items-scrollable');
                    if (cartContainer && positions.cartItems) {
                        cartContainer.scrollTop = positions.cartItems;
                    }
                    
                    sessionStorage.removeItem('posScrollPositions');
                } catch (e) {
                    console.error('Error restoring scroll position:', e);
                }
            }
            
            const savedTab = sessionStorage.getItem('posCurrentTab');
            if (savedTab && savedTab !== 'pos') {
                switchMainTab(savedTab);
            }
            sessionStorage.removeItem('posCurrentTab');
        }

        function startAutoRefresh() {
            if (autoRefreshTimer) clearInterval(autoRefreshTimer);
            
            autoRefreshTimer = setInterval(() => {
                refreshCountdown--;
                
                const refreshIndicator = document.getElementById('refreshIndicator');
                if (refreshIndicator) {
                    const timerSpan = refreshIndicator.querySelector('.refresh-timer');
                    if (timerSpan) timerSpan.textContent = refreshCountdown;
                    
                    if (refreshCountdown <= 5) {
                        refreshIndicator.classList.add('urgent');
                    } else {
                        refreshIndicator.classList.remove('urgent');
                    }
                }
                
                if (refreshCountdown <= 0) {
                    refreshCountdown = AUTO_REFRESH_INTERVAL;
                    performAutoRefresh();
                }
            }, 1000);
        }

        function performAutoRefresh() {
            if (isRefreshing) return;
            isRefreshing = true;
            saveScrollPosition();
            showToast('Refreshing to check for new orders...', 'info');
            window.location.reload();
        }

        function createRefreshIndicator() {
            if (document.getElementById('refreshIndicator')) return;
            
            const indicator = document.createElement('div');
            indicator.id = 'refreshIndicator';
            indicator.className = 'refresh-indicator';
            indicator.innerHTML = `
                <i class="fas fa-sync-alt"></i>
                <span class="refresh-label">Auto-refresh in</span>
                <span class="refresh-timer">${refreshCountdown}</span>
                <span class="refresh-unit">s</span>
                <button class="refresh-now-btn" onclick="manualRefresh()" title="Refresh now">
                    <i class="fas fa-arrow-rotate-right"></i>
                </button>
            `;
            document.body.appendChild(indicator);
        }

        function manualRefresh() {
            if (isRefreshing) return;
            saveScrollPosition();
            showToast('Refreshing...', 'info');
            window.location.reload();
        }

        async function checkForUpdates() {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'check_for_updates');
                
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    if (data.pending_orders !== undefined && data.pending_orders !== pendingOrdersCount) {
                        const newCount = data.pending_orders;
                        const oldCount = pendingOrdersCount;
                        pendingOrdersCount = newCount;
                        
                        const badge = document.querySelector('.main-tab[data-tab="online-orders"] .badge-count');
                        if (badge) {
                            if (newCount > 0) {
                                badge.textContent = newCount;
                                badge.style.display = 'inline-block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                        
                        if (newCount > oldCount) {
                            showToast(`📦 ${newCount - oldCount} new online order(s) received!`, 'info');
                        }
                    }
                    
                    if (data.incoming_requests !== undefined && data.incoming_requests !== incomingRequestsCount) {
                        const newCount = data.incoming_requests;
                        const oldCount = incomingRequestsCount;
                        incomingRequestsCount = newCount;
                        
                        const badge = document.querySelector('#viewRequestsBtn .badge-count');
                        if (badge) {
                            const total = newCount + incomingReceiptsCount;
                            if (total > 0) {
                                badge.textContent = total;
                                badge.style.display = 'inline-block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                        
                        if (newCount > oldCount) {
                            showToast(`📋 ${newCount - oldCount} new stock request(s) received!`, 'info');
                        }
                    }
                    
                    if (data.incoming_receipts !== undefined && data.incoming_receipts !== incomingReceiptsCount) {
                        const newCount = data.incoming_receipts;
                        const oldCount = incomingReceiptsCount;
                        incomingReceiptsCount = newCount;
                        
                        const badge = document.querySelector('#viewRequestsBtn .badge-count');
                        if (badge) {
                            const total = incomingRequestsCount + newCount;
                            if (total > 0) {
                                badge.textContent = total;
                                badge.style.display = 'inline-block';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                        
                        if (newCount > oldCount) {
                            showToast(`📋 ${newCount - oldCount} new receipt post(s) received!`, 'info');
                        }
                    }
                }
            } catch (error) {
                console.error('Error checking for updates:', error);
            }
        }
        
        // Helper functions
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}<span style="float:right; cursor:pointer;" onclick="this.parentElement.remove()">×</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        function showLoading(button, text = 'Processing...') {
            if (!button) return null;
            button.disabled = true;
            button.classList.add('btn-loading');
            const originalText = button.innerHTML;
            button.setAttribute('data-original-text', originalText);
            button.innerHTML = `<span class="btn-text">${text}</span>`;
            return originalText;
        }
        
        function hideLoading(button, originalText = null) {
            if (!button) return;
            button.disabled = false;
            button.classList.remove('btn-loading');
            if (originalText !== null) {
                button.innerHTML = originalText;
            } else if (button.getAttribute('data-original-text')) {
                button.innerHTML = button.getAttribute('data-original-text');
                button.removeAttribute('data-original-text');
            }
        }
        
        function formatCurrency(amount) {
            return '₦' + amount.toLocaleString(undefined, { minimumFractionDigits: 2 });
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
        
        // Tab switching
        function switchMainTab(tabId) {
            document.querySelectorAll('.main-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.querySelector(`.main-tab[data-tab="${tabId}"]`).classList.add('active');
            document.getElementById(`${tabId}-tab`).classList.add('active');
        }
        
        // Cart functions
        function updateCartItemCount() {
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
            const countSpan = document.getElementById('cartItemCount');
            if (countSpan) {
                countSpan.textContent = totalItems + ' item' + (totalItems !== 1 ? 's' : '');
            }
        }
        
        function updateReceiptPreview() {
            const previewContainer = document.getElementById('receiptPreview');
            const date = new Date();
            
            if (cart.length === 0) {
                previewContainer.innerHTML = `
                    <div class="receipt-empty">
                        <i class="fas fa-receipt"></i>
                        <p>Cart is empty</p>
                        <small>Add items to see receipt preview</small>
                    </div>
                `;
                return;
            }
            
            let subtotal = 0;
            let discountTotal = 0;
            
            cart.forEach(item => {
                const originalTotal = item.base_price * item.quantity;
                const itemTotal = item.final_price * item.quantity;
                subtotal += originalTotal;
                discountTotal += originalTotal - itemTotal;
            });
            const total = subtotal - discountTotal;
            
            let itemsHtml = '';
            cart.forEach(item => {
                itemsHtml += `
                    <div class="receipt-item">
                        <span class="receipt-item-name">${escapeHtml(item.name)}</span>
                        <span class="receipt-item-qty">x${item.quantity}</span>
                        <span class="receipt-item-price">${formatCurrency(item.final_price * item.quantity)}</span>
                    </div>
                `;
            });
            
            const previewHtml = `
                <div class="receipt-header">
                    <h3>FINGERCHOPS VENTURES</h3>
                    <p>${date.toLocaleDateString()} ${date.toLocaleTimeString()}</p>
                    <p><strong>CASHIER TRANSACTION</strong></p>
                    ${currentCustomer ? `<p>Customer: ${escapeHtml(currentCustomer.fullname)}<br>${escapeHtml(currentCustomer.user_id)}</p>` : '<p>Walk-in Customer</p>'}
                </div>
                <div class="receipt-divider"></div>
                ${itemsHtml}
                <div class="receipt-divider"></div>
                <div class="receipt-item">
                    <span>Subtotal:</span>
                    <span>${formatCurrency(subtotal)}</span>
                </div>
                <div class="receipt-item">
                    <span>Discount:</span>
                    <span>-${formatCurrency(discountTotal)}</span>
                </div>
                <div class="receipt-total">
                    <span>TOTAL:</span>
                    <span>${formatCurrency(total)}</span>
                </div>
                <div class="receipt-footer">
                    <p>Payment: ${currentPaymentMethod.toUpperCase()}</p>
                    <p>Thank you for your patronage!</p>
                </div>
            `;
            
            previewContainer.innerHTML = previewHtml;
        }
        
        function updateCartDisplay() {
            const cartContainer = document.getElementById('cartItems');
            
            if (cart.length === 0) {
                cartContainer.innerHTML = `
                    <div class="empty-cart">
                        <i class="fas fa-shopping-basket"></i>
                        <p>Cart is empty</p>
                        <small>Add products to begin</small>
                    </div>
                `;
                updateCartItemCount();
                updateReceiptPreview();
                return;
            }
            
            let html = '';
            cart.forEach((item, index) => {
                html += `
                    <div class="cart-item">
                        <div class="cart-item-info">
                            <div class="cart-item-name">${escapeHtml(item.name)}</div>
                            <div class="cart-item-price">${formatCurrency(item.final_price)} each</div>
                            ${item.discount > 0 ? `<div class="cart-item-discount">-${item.discount}% off</div>` : ''}
                        </div>
                        <div class="cart-item-quantity">
                            <button class="qty-btn" onclick="updateQuantity(${index}, ${item.quantity - 1})">-</button>
                            <span>${item.quantity}</span>
                            <button class="qty-btn" onclick="updateQuantity(${index}, ${item.quantity + 1})">+</button>
                        </div>
                        <div class="cart-item-total">${formatCurrency(item.final_price * item.quantity)}</div>
                        <div class="cart-item-remove" onclick="removeFromCart(${index})">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                    </div>
                `;
            });
            
            cartContainer.innerHTML = html;
            updateCartItemCount();
            updateReceiptPreview();
        }
        
        function updateQuantity(index, newQuantity) {
            if (newQuantity <= 0) {
                removeFromCart(index);
                return;
            }
            
            const item = cart[index];
            if (newQuantity > item.stock) {
                showToast(`Only ${item.stock} units available`, 'error');
                return;
            }
            
            cart[index].quantity = newQuantity;
            updateCartDisplay();
        }
        
        function removeFromCart(index) {
            cart.splice(index, 1);
            updateCartDisplay();
        }
        
        function addToCartWithQuantity(product) {
            const quantityInput = document.getElementById(`qty-${product.id}`);
            let quantity = 1;
            
            if (quantityInput) {
                quantity = parseInt(quantityInput.value) || 1;
                if (quantity < 1) quantity = 1;
                if (quantity > product.stock) {
                    showToast(`Only ${product.stock} units available`, 'error');
                    return;
                }
            }
            
            const existingIndex = cart.findIndex(item => item.product_id === product.id);
            
            if (existingIndex !== -1) {
                const newQuantity = cart[existingIndex].quantity + quantity;
                if (newQuantity > product.stock) {
                    showToast(`Only ${product.stock} units available total`, 'error');
                    return;
                }
                cart[existingIndex].quantity = newQuantity;
            } else {
                if (quantity > product.stock) {
                    showToast(`Only ${product.stock} units available`, 'error');
                    return;
                }
                cart.push({
                    product_id: product.id,
                    name: product.name,
                    base_price: product.base_price,
                    final_price: product.final_price,
                    discount: product.discount,
                    quantity: quantity,
                    stock: product.stock
                });
            }
            
            updateCartDisplay();
            showToast(`${quantity} × ${product.name} added to cart`, 'success');
            
            if (quantityInput) quantityInput.value = 1;
        }
        
        // Search functions
        async function searchProducts(term) {
            const gridContainer = document.getElementById('productsGrid');
            
            if (term.length < 2) {
                gridContainer.innerHTML = `
                    <div class="search-prompt" style="position: relative; margin-top: 0;">
                        <i class="fas fa-search"></i>
                        <p>Type at least 2 characters to search for products</p>
                    </div>
                `;
                return;
            }
            
            gridContainer.innerHTML = `
                <div class="search-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Searching for "${escapeHtml(term)}"...</p>
                </div>
            `;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'search_products');
            formData.append('term', term);
            formData.append('branch_id', userBranchId);
            
            try {
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success && data.products && data.products.length > 0) {
                    const customerId = document.getElementById('customerId').value;
                    
                    const productsWithPricing = await Promise.all(data.products.map(async (product) => {
                        const priceFormData = new FormData();
                        priceFormData.append('ajax', 1);
                        priceFormData.append('action', 'get_product_pricing');
                        priceFormData.append('product_id', product.id);
                        priceFormData.append('customer_id', customerId);
                        priceFormData.append('branch_id', userBranchId);
                        
                        const priceResponse = await fetch(window.location.href, { method: 'POST', body: priceFormData });
                        const priceData = await priceResponse.json();
                        
                        if (priceData.success) {
                            return {
                                id: product.id,
                                name: product.name,
                                base_price: product.base_price,
                                final_price: priceData.product.final_price,
                                discount: priceData.product.discount,
                                stock: product.stock_quantity
                            };
                        }
                        return product;
                    }));
                    
                    let html = '';
                    productsWithPricing.forEach(product => {
                        const stockClass = product.stock < 10 ? 'low-stock' : '';
                        const discountBadge = product.discount > 0 ? `<span class="discount-badge">-${product.discount}%</span>` : '';
                        const maxQuantity = Math.min(product.stock, 99);
                        
                        html += `
                            <div class="product-card" data-product-id="${product.id}" data-product-stock="${product.stock}">
                                <div class="product-name">${escapeHtml(product.name)}</div>
                                <div class="product-price">
                                    ${formatCurrency(product.final_price)}
                                    ${discountBadge}
                                </div>
                                ${product.discount > 0 ? `<div class="product-original-price">${formatCurrency(product.base_price)}</div>` : ''}
                                <div class="product-stock ${stockClass}"><i class="fas fa-box"></i> ${product.stock} left</div>
                                <div class="product-quantity-selector">
                                    <button class="qty-decr" data-product-id="${product.id}">-</button>
                                    <input type="number" class="product-qty-input" id="qty-${product.id}" value="1" min="1" max="${maxQuantity}" step="1">
                                    <button class="qty-incr" data-product-id="${product.id}">+</button>
                                    <button class="add-to-cart-btn" onclick="addToCartWithQuantity({
                                        id: ${product.id},
                                        name: '${escapeHtml(product.name)}',
                                        base_price: ${product.base_price},
                                        final_price: ${product.final_price},
                                        discount: ${product.discount},
                                        stock: ${product.stock}
                                    })">
                                        <i class="fas fa-cart-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    gridContainer.innerHTML = html;
                    
                    document.querySelectorAll('.qty-decr').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const productId = this.dataset.productId;
                            const input = document.getElementById(`qty-${productId}`);
                            let val = parseInt(input.value) || 1;
                            if (val > 1) input.value = val - 1;
                        });
                    });
                    
                    document.querySelectorAll('.qty-incr').forEach(btn => {
                        btn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const productId = this.dataset.productId;
                            const input = document.getElementById(`qty-${productId}`);
                            const max = parseInt(input.max) || 99;
                            let val = parseInt(input.value) || 1;
                            if (val < max) input.value = val + 1;
                        });
                    });
                    
                    document.querySelectorAll('.product-quantity-selector input, .product-quantity-selector button').forEach(el => {
                        el.addEventListener('click', e => e.stopPropagation());
                    });
                    
                } else {
                    gridContainer.innerHTML = `
                        <div class="no-results">
                            <i class="fas fa-search"></i>
                            <p>No products found for "${escapeHtml(term)}"</p>
                            <small>Try a different search term</small>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Search error:', error);
                gridContainer.innerHTML = `
                    <div class="search-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error searching products. Please try again.</p>
                    </div>
                `;
            }
        }
        
        async function searchCustomers(term) {
            if (term.length < 2) {
                document.getElementById('customerSuggestions').style.display = 'none';
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'search_customers');
            formData.append('term', term);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success && data.customers && data.customers.length > 0) {
                const suggestionsDiv = document.getElementById('customerSuggestions');
                suggestionsDiv.innerHTML = '';
                data.customers.forEach(customer => {
                    const div = document.createElement('div');
                    div.className = 'customer-suggestion';
                    div.innerHTML = `<strong>${escapeHtml(customer.fullname)}</strong><br><small>${escapeHtml(customer.user_id)}</small>`;
                    div.onclick = () => selectCustomer(customer);
                    suggestionsDiv.appendChild(div);
                });
                suggestionsDiv.style.display = 'block';
            } else {
                document.getElementById('customerSuggestions').style.display = 'none';
            }
        }
        
        function selectCustomer(customer) {
            currentCustomer = customer;
            document.getElementById('customerId').value = customer.id;
            document.getElementById('customerSearchInput').value = customer.fullname + ' (' + customer.user_id + ')';
            document.getElementById('selectedCustomer').innerHTML = `
                <i class="fas fa-user-check"></i> <strong>${escapeHtml(customer.fullname)}</strong>
                <small>ID: ${escapeHtml(customer.user_id)} | ${customer.user_type}</small>
                <button onclick="clearCustomer()" class="clear-customer-btn">✕</button>
            `;
            document.getElementById('selectedCustomer').style.display = 'block';
            document.getElementById('customerSuggestions').style.display = 'none';
            refreshCartPricing();
        }
        
        function clearCustomer() {
            currentCustomer = null;
            document.getElementById('customerId').value = '0';
            document.getElementById('customerSearchInput').value = '';
            document.getElementById('selectedCustomer').style.display = 'none';
            refreshCartPricing();
        }
        
        async function refreshCartPricing() {
            if (cart.length === 0) return;
            
            const customerId = document.getElementById('customerId').value;
            
            for (let i = 0; i < cart.length; i++) {
                const item = cart[i];
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'get_product_pricing');
                formData.append('product_id', item.product_id);
                formData.append('customer_id', customerId);
                formData.append('branch_id', userBranchId);
                
                const response = await fetch(window.location.href, { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    cart[i].final_price = data.product.final_price;
                    cart[i].discount = data.product.discount;
                }
            }
            
            updateCartDisplay();
        }
        
        // POS Checkout
        async function processCheckout() {
            if (cart.length === 0) {
                showToast('Cart is empty', 'error');
                return;
            }
            
            const paymentMethod = currentPaymentMethod;
            const referenceNumber = document.getElementById('referenceNumber').value;
            const customerId = document.getElementById('customerId').value;
            
            if (paymentMethod !== 'cash' && (!referenceNumber || referenceNumber.length < 5)) {
                showToast('Please enter a valid reference number (min 5 characters)', 'error');
                return;
            }
            
            const checkoutBtn = document.getElementById('checkoutBtn');
            const originalText = showLoading(checkoutBtn, 'Processing...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'process_sale');
            formData.append('cart', JSON.stringify(cart));
            formData.append('customer_id', customerId);
            formData.append('payment_method', paymentMethod);
            formData.append('reference_number', referenceNumber);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(checkoutBtn, originalText);
            
            if (data.success) {
                lastReceiptData = data;
                showFinalReceiptModal(data);
                cart = [];
                updateCartDisplay();
                clearCustomer();
                document.getElementById('referenceNumber').value = '';
                refreshStats();
            } else {
                showToast(data.message, 'error');
            }
        }
        
        function showFinalReceiptModal(data) {
            const date = new Date();
            const finalReceiptHtml = `
                <div class="receipt-header">
                    <h3>FINGERCHOPS VENTURES</h3>
                    <p>${date.toLocaleDateString()} ${date.toLocaleTimeString()}</p>
                    <p><strong>ORDER #: ${data.order_number}</strong></p>
                    <p><strong>RECEIPT #: ${data.receipt_number}</strong></p>
                    ${data.customer ? `<p>Customer: ${escapeHtml(data.customer.fullname)}<br>ID: ${escapeHtml(data.customer.user_id)}</p>` : '<p>Walk-in Customer</p>'}
                    <p>Payment: ${data.payment_method.toUpperCase()}${data.reference_number ? ' | Ref: ' + data.reference_number : ''}</p>
                </div>
                <div class="receipt-divider"></div>
                ${data.items.map(item => `
                    <div class="receipt-item">
                        <span class="receipt-item-name">${escapeHtml(item.name)}</span>
                        <span class="receipt-item-qty">x${item.quantity}</span>
                        <span class="receipt-item-price">${formatCurrency(item.price * item.quantity)}</span>
                    </div>
                `).join('')}
                <div class="receipt-divider"></div>
                <div class="receipt-total">
                    <span>TOTAL:</span>
                    <span>${formatCurrency(data.total_amount)}</span>
                </div>
                <div class="receipt-footer">
                    <p>Thank you for your patronage!</p>
                    <p>Fingerchops Ventures - Quality Baked Goods</p>
                </div>
            `;
            
            document.getElementById('finalReceiptBody').innerHTML = finalReceiptHtml;
            document.getElementById('receiptModal').classList.add('show');
            overlay.classList.add('active');
        }
        
        function closeReceiptModal() {
            document.getElementById('receiptModal').classList.remove('show');
            overlay.classList.remove('active');
        }
        
        function printFinalReceipt() {
            const receiptContent = document.getElementById('finalReceiptBody').innerHTML;
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
                        .receipt-total { display: flex; justify-content: space-between; margin-top: 10px; padding-top: 10px; border-top: 1px dashed #000; font-weight: bold; }
                        .receipt-footer { text-align: center; margin-top: 20px; font-size: 10px; }
                    </style>
                </head>
                <body>${receiptContent}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        async function refreshStats() {
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_today_summary');
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                const stats = document.querySelectorAll('.stat-card .stat-value');
                if (stats[0]) stats[0].innerHTML = '₦' + data.total_sales.toLocaleString(undefined, {minimumFractionDigits: 2});
                if (stats[1]) stats[1].innerHTML = data.total_transactions;
            }
        }
        
        // =====================================================
        // ORDER DETAILS MODAL FUNCTIONS
        // =====================================================
        
        function viewOrderDetails(order) {
            console.log('Order received:', order);
            console.log('Order items:', order.items);
            
            if (!order.items || order.items.length === 0) {
                showToast('Order details not loaded. Please refresh the page.', 'error');
                return;
            }
            
            currentOrderData = order;
            
            const itemsHtml = order.items.map(item => {
                const hasStock = item.has_sufficient_stock;
                const totalAvailable = item.total_available_stock || 0;
                const shortfall = item.stock_shortfall || 0;
                
                return `
                    <div class="receipt-item" style="${!hasStock ? 'background: #fff3e0; margin: 0.25rem 0; padding: 0.25rem; border-radius: 4px;' : ''}">
                        <span class="receipt-item-name" style="flex: 1;">
                            ${escapeHtml(item.product_name)}
                            ${!hasStock ? `<br><small style="color: #dc3545;">⚠️ Need ${item.quantity}, have ${totalAvailable} (shortfall: ${shortfall})</small>` : ''}
                            ${item.posted_receipt_stock > 0 ? `<br><small style="color: #10b981;">✓ +${item.posted_receipt_stock} from posted receipts</small>` : ''}
                            ${item.current_branch_stock > 0 ? `<br><small>📦 Your branch: ${item.current_branch_stock} units</small>` : ''}
                        </span>
                        <span class="receipt-item-qty">x${item.quantity}</span>
                        <span class="receipt-item-price">₦${(item.unit_price * item.quantity).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                        <div style="display: flex; flex-direction: column; gap: 0.25rem; margin-left: 0.5rem;">
                            ${!hasStock && shortfall > 0 ? `
                                <button class="request-stock-btn" onclick="event.stopPropagation(); openOutgoingStockRequestModal(${item.product_id}, '${escapeHtml(item.product_name)}', ${shortfall}, ${order.id}, '${escapeHtml(order.order_number)}')" 
                                    style="background: #f59e0b; color: white; border: none; border-radius: 1.5rem; padding: 0.2rem 0.6rem; font-size: 0.7rem; cursor: pointer;">
                                    <i class="fas fa-exchange-alt"></i> Request
                                </button>
                            ` : ''}
                            ${item.current_branch_stock >= shortfall && shortfall > 0 ? `
                                <button class="post-receipt-btn" onclick="event.stopPropagation(); openPostReceiptModal(${item.product_id}, '${escapeHtml(item.product_name)}', ${shortfall}, ${order.id}, '${escapeHtml(order.order_number)}', ${item.current_branch_stock})" 
                                    style="background: #10b981; color: white; border: none; border-radius: 1.5rem; padding: 0.2rem 0.6rem; font-size: 0.7rem; cursor: pointer; margin-top: 0.25rem;">
                                    <i class="fas fa-receipt"></i> Post Receipt
                                </button>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');
            
            const allItemsAvailable = order.items.every(item => item.has_sufficient_stock);
            
            const receiptHtml = `
                <div class="order-receipt">
                    <div class="receipt-header">
                        <h3>FINGERCHOPS VENTURES</h3>
                        <p>${new Date(order.created_at).toLocaleDateString()} ${new Date(order.created_at).toLocaleTimeString()}</p>
                        <p><strong>ORDER #: ${escapeHtml(order.order_number)}</strong></p>
                        <p>Customer: ${escapeHtml(order.customer_name)}<br>ID: ${escapeHtml(order.customer_user_id)}</p>
                        <p>Payment: ${order.payment_method.toUpperCase()}</p>
                    </div>
                    <div class="receipt-divider"></div>
                    ${itemsHtml}
                    <div class="receipt-divider"></div>
                    <div class="receipt-item">
                        <span>TOTAL:</span>
                        <span>₦${order.total_amount.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                    </div>
                    <div class="receipt-footer">
                        <p>Delivery Address: ${escapeHtml(order.delivery_address)}</p>
                        <p>${escapeHtml(order.delivery_city)}, ${escapeHtml(order.delivery_state)}</p>
                        <p>Phone: ${escapeHtml(order.delivery_phone)}</p>
                        <p>Fingerchops Ventures - Quality Baked Goods</p>
                    </div>
                </div>
            `;
            
            document.getElementById('orderDetailsBody').innerHTML = receiptHtml;
            
            const confirmBtn = document.getElementById('confirmOrderBtn');
            if (confirmBtn) {
                if (!allItemsAvailable) {
                    confirmBtn.disabled = true;
                    confirmBtn.title = 'Cannot confirm - some items are out of stock. Request stock or wait for posted receipts.';
                    confirmBtn.style.opacity = '0.5';
                    confirmBtn.style.cursor = 'not-allowed';
                } else {
                    confirmBtn.disabled = false;
                    confirmBtn.title = '';
                    confirmBtn.style.opacity = '1';
                    confirmBtn.style.cursor = 'pointer';
                }
            }
            
            document.getElementById('orderDetailsModal').classList.add('show');
            overlay.classList.add('active');
        }
        
        function closeOrderDetailsModal() {
            document.getElementById('orderDetailsModal').classList.remove('show');
            overlay.classList.remove('active');
            currentOrderData = null;
        }
        
        function confirmCurrentOrder() {
            if (!currentOrderData) return;
            confirmOnlineOrder(currentOrderData.id);
        }
        
        function cancelCurrentOrder() {
            if (!currentOrderData) return;
            cancelOnlineOrder(currentOrderData.id);
        }
        
        // =====================================================
        // OUTGOING STOCK REQUEST FUNCTIONS
        // =====================================================
        
        function openOutgoingStockRequestModal(productId, productName, quantity, orderId, orderNumber) {
            currentRequestData = {
                product_id: productId,
                product_name: productName,
                quantity: quantity,
                order_id: orderId,
                order_number: orderNumber
            };
            
            document.getElementById('requestProductName').textContent = productName;
            document.getElementById('requestQuantity').textContent = quantity;
            document.getElementById('requestOrderNumber').textContent = orderNumber;
            document.getElementById('targetBranchSelect').value = '';
            document.getElementById('requestNotes').value = '';
            
            document.getElementById('outgoingStockRequestModal').classList.add('show');
            overlay.classList.add('active');
        }
        
        function closeOutgoingStockRequestModal() {
            document.getElementById('outgoingStockRequestModal').classList.remove('show');
            overlay.classList.remove('active');
            currentRequestData = null;
        }
        
        async function submitStockRequest() {
            const targetBranchId = document.getElementById('targetBranchSelect').value;
            const requestNotes = document.getElementById('requestNotes').value;
            
            if (!targetBranchId) {
                showToast('Please select a branch to request from', 'error');
                return;
            }
            
            if (!currentRequestData) return;
            
            const submitBtn = document.getElementById('submitStockRequestBtn');
            const originalText = showLoading(submitBtn, 'Sending...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'create_stock_request');
            formData.append('order_id', currentRequestData.order_id);
            formData.append('product_id', currentRequestData.product_id);
            formData.append('quantity', currentRequestData.quantity);
            formData.append('target_branch_id', targetBranchId);
            formData.append('request_notes', requestNotes);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(submitBtn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                closeOutgoingStockRequestModal();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        // =====================================================
        // POST RECEIPT FUNCTIONS
        // =====================================================
        
        function openPostReceiptModal(productId, productName, quantity, orderId, orderNumber, yourStock) {
            currentPostData = {
                product_id: productId,
                product_name: productName,
                quantity: quantity,
                order_id: orderId,
                order_number: orderNumber,
                your_stock: yourStock
            };
            
            document.getElementById('postProductName').textContent = productName;
            document.getElementById('postQuantity').textContent = quantity;
            document.getElementById('postOrderNumber').textContent = orderNumber;
            document.getElementById('postYourStock').textContent = yourStock;
            document.getElementById('targetBranchSelectPost').value = '';
            document.getElementById('postNotes').value = '';
            
            document.getElementById('postReceiptModal').classList.add('show');
            overlay.classList.add('active');
        }
        
        function closePostReceiptModal() {
            document.getElementById('postReceiptModal').classList.remove('show');
            overlay.classList.remove('active');
            currentPostData = null;
        }
        
        async function submitPostReceipt() {
            const targetBranchId = document.getElementById('targetBranchSelectPost').value;
            const postNotes = document.getElementById('postNotes').value;
            
            if (!targetBranchId) {
                showToast('Please select a branch to post the receipt to', 'error');
                return;
            }
            
            if (!currentPostData) return;
            
            const submitBtn = document.getElementById('submitPostReceiptBtn');
            const originalText = showLoading(submitBtn, 'Posting...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'post_receipt');
            formData.append('order_id', currentPostData.order_id);
            formData.append('product_id', currentPostData.product_id);
            formData.append('quantity', currentPostData.quantity);
            formData.append('target_branch_id', targetBranchId);
            formData.append('notes', postNotes);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(submitBtn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                closePostReceiptModal();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        // =====================================================
        // INCOMING ITEMS FUNCTIONS
        // =====================================================
        
        const overlay = document.getElementById('overlay');
        
        function openIncomingItemsModal() {
            document.getElementById('incomingItemsModal').classList.add('show');
            overlay.classList.add('active');
        }
        
        function closeIncomingItemsModal() {
            document.getElementById('incomingItemsModal').classList.remove('show');
            overlay.classList.remove('active');
            hideAllRejectReasons();
            hideAllReceiptRejectReasons();
        }
        
        // Stock request handlers
        function showRejectReason(requestId) {
            hideAllRejectReasons();
            const reasonDiv = document.getElementById(`reject-reason-${requestId}`);
            if (reasonDiv) reasonDiv.style.display = 'block';
        }
        
        function hideRejectReason(requestId) {
            const reasonDiv = document.getElementById(`reject-reason-${requestId}`);
            if (reasonDiv) reasonDiv.style.display = 'none';
        }
        
        function hideAllRejectReasons() {
            document.querySelectorAll('#incomingRequestsList .reject-reason-input').forEach(el => el.style.display = 'none');
        }
        
        async function processRequest(requestId, action) {
            let rejectReason = '';
            if (action === 'reject') {
                const reasonTextarea = document.getElementById(`reject-reason-text-${requestId}`);
                rejectReason = reasonTextarea ? reasonTextarea.value.trim() : '';
                if (!rejectReason) {
                    showToast('Please provide a reason for rejection', 'error');
                    return;
                }
            }
            
            const btn = action === 'approve' ? 
                document.querySelector(`.request-item[data-request-id="${requestId}"] .btn-confirm`) :
                document.querySelector(`.request-item[data-request-id="${requestId}"] .btn-cancel`);
            const originalText = showLoading(btn, action === 'approve' ? 'Approving...' : 'Rejecting...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'process_stock_request');
            formData.append('request_id', requestId);
            formData.append('request_action', action);
            if (action === 'reject') formData.append('reject_reason', rejectReason);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(btn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        // Receipt post handlers
        function showReceiptRejectReason(receiptId) {
            hideAllReceiptRejectReasons();
            const reasonDiv = document.getElementById(`receipt-reject-reason-${receiptId}`);
            if (reasonDiv) reasonDiv.style.display = 'block';
        }
        
        function hideReceiptRejectReason(receiptId) {
            const reasonDiv = document.getElementById(`receipt-reject-reason-${receiptId}`);
            if (reasonDiv) reasonDiv.style.display = 'none';
        }
        
        function hideAllReceiptRejectReasons() {
            document.querySelectorAll('#incomingReceiptsList .reject-reason-input').forEach(el => el.style.display = 'none');
        }
        
        async function acceptReceiptPost(receiptId) {
            if (!confirm('Accept this receipt? The stock will be added to your available inventory for this order.')) return;
            
            const btn = document.querySelector(`.request-item[data-receipt-id="${receiptId}"] .btn-confirm`);
            const originalText = showLoading(btn, 'Accepting...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'accept_receipt_post');
            formData.append('receipt_id', receiptId);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(btn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        async function rejectReceiptPost(receiptId) {
            const reasonTextarea = document.getElementById(`receipt-reject-text-${receiptId}`);
            const rejectReason = reasonTextarea ? reasonTextarea.value.trim() : '';
            if (!rejectReason) {
                showToast('Please provide a reason for rejection', 'error');
                return;
            }
            
            const btn = document.querySelector(`.request-item[data-receipt-id="${receiptId}"] .btn-cancel`);
            const originalText = showLoading(btn, 'Rejecting...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'reject_receipt_post');
            formData.append('receipt_id', receiptId);
            formData.append('reject_reason', rejectReason);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(btn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        // =====================================================
        // ORDER PROCESSING FUNCTIONS
        // =====================================================
        
        async function confirmOnlineOrder(orderId) {
            if (!confirm('Confirm this order? Stock will be deducted from your branch and any posted receipts will be marked as used.')) return;
            
            const confirmBtn = document.getElementById('confirmOrderBtn');
            const originalText = showLoading(confirmBtn, 'Confirming...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'confirm_online_order');
            formData.append('order_id', orderId);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(confirmBtn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                closeOrderDetailsModal();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        async function cancelOnlineOrder(orderId) {
            if (!confirm('Cancel this order?')) return;
            
            const cancelBtn = document.getElementById('cancelOrderBtn');
            const originalText = showLoading(cancelBtn, 'Cancelling...');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'cancel_online_order');
            formData.append('order_id', orderId);
            
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoading(cancelBtn, originalText);
            
            if (data.success) {
                showToast(data.message, 'success');
                closeOrderDetailsModal();
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message, 'error');
            }
        }
        
        // =====================================================
        // DEBOUNCED SEARCH
        // =====================================================
        
        const debouncedSearch = (() => {
            let timeout;
            return (term) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => searchProducts(term), 300);
            };
        })();
        
        // =====================================================
        // EVENT LISTENERS
        // =====================================================
        
        document.addEventListener('DOMContentLoaded', function() {
            restoreScrollPosition();
            createRefreshIndicator();
            startAutoRefresh();
            setInterval(checkForUpdates, 15000);
            window.addEventListener('beforeunload', saveScrollPosition);
        });
        
        document.querySelectorAll('.main-tab').forEach(tab => {
            tab.addEventListener('click', () => switchMainTab(tab.dataset.tab));
        });
        
        const productSearch = document.getElementById('productSearch');
        if (productSearch) {
            productSearch.addEventListener('input', (e) => debouncedSearch(e.target.value.trim()));
        }
        
        document.getElementById('customerSearchInput').addEventListener('input', (e) => searchCustomers(e.target.value));
        document.getElementById('checkoutBtn').addEventListener('click', processCheckout);
        document.getElementById('viewRequestsBtn').addEventListener('click', openIncomingItemsModal);
        
        document.querySelectorAll('.payment-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                currentPaymentMethod = this.dataset.method;
                
                const referenceInput = document.getElementById('referenceInput');
                if (currentPaymentMethod !== 'cash') {
                    referenceInput.classList.add('show');
                } else {
                    referenceInput.classList.remove('show');
                    document.getElementById('referenceNumber').value = '';
                }
                updateReceiptPreview();
            });
        });
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.customer-search')) {
                document.getElementById('customerSuggestions').style.display = 'none';
            }
        });
        
        // Initial load
        document.getElementById('productsGrid').innerHTML = `
            <div class="search-prompt" style="position: relative; margin-top: 0;">
                <i class="fas fa-search"></i>
                <p>Type at least 2 characters to search for products</p>
            </div>
        `;
        
        // Global functions
        window.updateQuantity = updateQuantity;
        window.removeFromCart = removeFromCart;
        window.addToCartWithQuantity = addToCartWithQuantity;
        window.selectCustomer = selectCustomer;
        window.clearCustomer = clearCustomer;
        window.printFinalReceipt = printFinalReceipt;
        window.closeReceiptModal = closeReceiptModal;
        window.openIncomingItemsModal = openIncomingItemsModal;
        window.closeIncomingItemsModal = closeIncomingItemsModal;
        window.showRejectReason = showRejectReason;
        window.hideRejectReason = hideRejectReason;
        window.processRequest = processRequest;
        window.confirmOnlineOrder = confirmOnlineOrder;
        window.cancelOnlineOrder = cancelOnlineOrder;
        window.switchMainTab = switchMainTab;
        window.viewOrderDetails = viewOrderDetails;
        window.closeOrderDetailsModal = closeOrderDetailsModal;
        window.confirmCurrentOrder = confirmCurrentOrder;
        window.cancelCurrentOrder = cancelCurrentOrder;
        window.manualRefresh = manualRefresh;
        window.openOutgoingStockRequestModal = openOutgoingStockRequestModal;
        window.closeOutgoingStockRequestModal = closeOutgoingStockRequestModal;
        window.submitStockRequest = submitStockRequest;
        window.openPostReceiptModal = openPostReceiptModal;
        window.closePostReceiptModal = closePostReceiptModal;
        window.submitPostReceipt = submitPostReceipt;
        window.acceptReceiptPost = acceptReceiptPost;
        window.rejectReceiptPost = rejectReceiptPost;
        window.showReceiptRejectReason = showReceiptRejectReason;
        window.hideReceiptRejectReason = hideReceiptRejectReason;
    </script>
</body>
</html>