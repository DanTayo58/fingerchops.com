<?php
// =====================================================
// FILE: dashboards/staff/inventory-dashboard.php
// VERSION: 24.0 - Fixed approval logic, edit button inside search field
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$root_path = dirname(__DIR__, 2) . '/';
require_once $root_path . 'conn.php';
require_once $root_path . 'includes/User.php';
require_once $root_path . 'includes/Security.php';
require_once $root_path . 'includes/Helpers.php';
require_once $root_path . 'config/config_loader.php';

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

$privilege_level = $userObj->getPrivilegeLevel();
$can_manage_inventory = ($privilege_level >= 30);
$can_transfer_stock = ($privilege_level >= 30);
$can_manage_purchases = ($privilege_level >= 30);
$is_admin = ($privilege_level >= 80);
$is_accounting = false;

$user_roles = $userObj->getRoles();
foreach ($user_roles as $role) {
    if (stripos($role['role_name'], 'accountant') !== false || $role['role_code'] === 'ACCOUNTANT') {
        $is_accounting = true;
        break;
    }
}

$user_branch_id = $user['branch_id'] ?? 1;
$branch_info = $db->preparedFetchOne("SELECT branch_name, branch_code FROM branches WHERE id = ?", 'i', [$user_branch_id]);
$branch_name = $branch_info['branch_name'] ?? 'Main Branch';
$branch_code = $branch_info['branch_code'] ?? 'HQ';
$is_headquarters = ($user_branch_id == 1 || $branch_code === 'HQ');

$user_role = $db->preparedFetchOne("
    SELECT r.role_name, r.privilege_level 
    FROM user_roles ur 
    JOIN roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND ur.is_active = 1 
    LIMIT 1
", 'i', [$_SESSION['user_id']]);
$role_name = $user_role['role_name'] ?? 'Staff';

$all_branches = [];
if ($is_headquarters) {
    $all_branches = $db->preparedFetchAll("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name", '', []);
}

$selected_branch_id = isset($_GET['branch']) ? (int)$_GET['branch'] : $user_branch_id;
if (!$is_headquarters) {
    $selected_branch_id = $user_branch_id;
}

$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'approved';
$purchase_filter = isset($_GET['purchase_filter']) ? $_GET['purchase_filter'] : 'idle';
$returns_date_from = isset($_GET['returns_date_from']) ? $_GET['returns_date_from'] : '';
$returns_date_to = isset($_GET['returns_date_to']) ? $_GET['returns_date_to'] : '';

// =====================================================
// AJAX HANDLERS
// =====================================================
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    // Session-based validation: check if user has active session
    if (!isset($_SESSION['user_id'])) {
        $response['message'] = 'Session expired. Please log in again.';
        echo json_encode($response);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // Get returns with date filters
    if ($action === 'get_returns') {
        $status_filter = $_POST['status_filter'] ?? 'approved';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $sql = "
            SELECT 
                r.id as return_id,
                r.return_number,
                r.status as return_status,
                r.inventory_processed_at,
                r.inventory_processed_by,
                r.created_at,
                ri.id as return_item_id,
                ri.returned_quantity,
                ria.id as action_id,
                ria.resell_quantity,
                ria.destroy_quantity,
                ria.status as item_status,
                p.name as product_name,
                p.id as product_id
            FROM order_returns r
            JOIN return_items ri ON r.id = ri.return_id
            JOIN return_inventory_actions ria ON ri.id = ria.return_item_id
            JOIN products p ON ri.original_product_id = p.id
            WHERE r.status = ?
        ";
        $params = [$status_filter];
        $types = 's';
        
        if (!empty($date_from)) {
            $sql .= " AND DATE(r.created_at) >= ?";
            $params[] = $date_from;
            $types .= 's';
        }
        if (!empty($date_to)) {
            $sql .= " AND DATE(r.created_at) <= ?";
            $params[] = $date_to;
            $types .= 's';
        }
        
        $sql .= " ORDER BY r.created_at DESC, ri.id ASC";
        
        $results = $db->preparedFetchAll($sql, $types, $params);
        
        $returns = [];
        foreach ($results as $row) {
            $return_id = $row['return_id'];
            if (!isset($returns[$return_id])) {
                $returns[$return_id] = [
                    'return_id' => $return_id,
                    'return_number' => $row['return_number'],
                    'return_status' => $row['return_status'],
                    'inventory_processed_at' => $row['inventory_processed_at'],
                    'inventory_processed_by' => $row['inventory_processed_by'],
                    'created_at' => $row['created_at'],
                    'items' => []
                ];
            }
            $returns[$return_id]['items'][] = [
                'action_id' => $row['action_id'],
                'return_item_id' => $row['return_item_id'],
                'product_name' => $row['product_name'],
                'product_id' => $row['product_id'],
                'returned_quantity' => $row['returned_quantity'],
                'resell_quantity' => $row['resell_quantity'],
                'destroy_quantity' => $row['destroy_quantity'],
                'item_status' => $row['item_status'] ?? 'pending'
            ];
        }
        
        $response = ['success' => true, 'returns' => $returns];
        echo json_encode($response);
        exit;
    }
    
    // Process a single return item
    if ($action === 'process_return_item') {
        if (!$can_manage_inventory) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $action_id = intval($_POST['action_id']);
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        
        $item = $db->preparedFetchOne("
            SELECT ria.*, ri.original_product_id, ri.return_id
            FROM return_inventory_actions ria
            JOIN return_items ri ON ria.return_item_id = ri.id
            WHERE ria.id = ?
        ", 'i', [$action_id]);
        
        if (!$item) {
            $response['message'] = 'Item not found';
            echo json_encode($response);
            exit;
        }
        
        if ($item['status'] === 'handled') {
            $response['message'] = 'Item already processed';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            if ($item['destroy_quantity'] > 0) {
                $current = $db->preparedFetchOne("SELECT quantity FROM product_stock WHERE product_id = ? AND branch_id = ?", 'ii', [$item['original_product_id'], $branch_id]);
                $current_qty = $current ? (int)$current['quantity'] : 0;
                $new_qty = $current_qty - $item['destroy_quantity'];
                
                if ($new_qty < 0) {
                    throw new Exception("Cannot destroy {$item['destroy_quantity']} units - only {$current_qty} available in stock");
                }
                
                $db->preparedExecute("UPDATE product_stock SET quantity = ? WHERE product_id = ? AND branch_id = ?", 'iii', [$new_qty, $item['original_product_id'], $branch_id]);
                logActivity($_SESSION['user_id'], "Destroyed {$item['destroy_quantity']} units of product {$item['original_product_id']} at branch $branch_id", 'inventory', $item['original_product_id']);
            }
            
            $db->preparedExecute("UPDATE return_inventory_actions SET status = 'handled' WHERE id = ?", 'i', [$action_id]);
            
            $remaining = $db->preparedFetchOne("
                SELECT COUNT(*) as count 
                FROM return_inventory_actions ria 
                JOIN return_items ri ON ria.return_item_id = ri.id 
                WHERE ri.return_id = ? AND ria.status = 'pending'
            ", 'i', [$item['return_id']])['count'] ?? 0;
            
            if ($remaining == 0) {
                $db->preparedExecute("
                    UPDATE order_returns 
                    SET inventory_processed_at = NOW(), 
                        inventory_processed_by = ?,
                        status = 'completed'
                    WHERE id = ?
                ", 'ii', [$_SESSION['user_id'], $item['return_id']]);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Item processed successfully'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Complete entire return
    if ($action === 'complete_return') {
        if (!$can_manage_inventory) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $return_id = intval($_POST['return_id']);
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE return_inventory_actions ria
                JOIN return_items ri ON ria.return_item_id = ri.id
                SET ria.status = 'handled'
                WHERE ri.return_id = ? AND ria.status = 'pending'
            ", 'i', [$return_id]);
            
            $db->preparedExecute("
                UPDATE order_returns 
                SET inventory_processed_at = NOW(), 
                    inventory_processed_by = ?,
                    status = 'completed'
                WHERE id = ?
            ", 'ii', [$_SESSION['user_id'], $return_id]);
            
            logActivity($_SESSION['user_id'], "Completed return ID: $return_id", 'return', $return_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Return marked as completed'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Adjust stock
    if ($action === 'adjust_stock') {
        if (!$can_manage_inventory) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $reason = trim($_POST['reason'] ?? '');
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        
        if (!$product_id || $quantity == 0) {
            $response['message'] = 'Invalid quantity';
            echo json_encode($response);
            exit;
        }
        
        $current = $db->preparedFetchOne("SELECT quantity FROM product_stock WHERE product_id = ? AND branch_id = ?", 'ii', [$product_id, $branch_id]);
        $current_qty = $current ? (int)$current['quantity'] : 0;
        $new_qty = $current_qty + $quantity;
        if ($new_qty < 0) {
            $response['message'] = 'Cannot reduce stock below zero';
            echo json_encode($response);
            exit;
        }
        
        if ($current) {
            $db->preparedExecute("UPDATE product_stock SET quantity = ? WHERE product_id = ? AND branch_id = ?", 'iii', [$new_qty, $product_id, $branch_id]);
        } else {
            $db->preparedExecute("INSERT INTO product_stock (product_id, branch_id, quantity) VALUES (?, ?, ?)", 'iii', [$product_id, $branch_id, $new_qty]);
        }
        
        logActivity($_SESSION['user_id'], "Adjusted stock for product ID $product_id at branch $branch_id: $quantity units. Reason: $reason", 'inventory', $product_id);
        $response = ['success' => true, 'message' => 'Stock updated', 'new_quantity' => $new_qty];
        echo json_encode($response);
        exit;
    }
    
    // Search products for stock adjustment
    if ($action === 'search_products_stock') {
        $term = trim($_POST['term'] ?? '');
        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'products' => []]);
            exit;
        }
        $searchTerm = '%' . $term . '%';
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        $products = $db->preparedFetchAll("
            SELECT p.id, p.name, p.base_price, COALESCE(ps.quantity, 0) as stock_quantity
            FROM products p
            LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.branch_id = ?
            WHERE p.is_active = 1 AND (p.name LIKE ? OR p.id LIKE ?)
            ORDER BY p.name LIMIT 20
        ", 'iss', [$branch_id, $searchTerm, $searchTerm]);
        echo json_encode(['success' => true, 'products' => $products]);
        exit;
    }
    
    // Search products for transfer
    if ($action === 'search_products_transfer') {
        $term = trim($_POST['term'] ?? '');
        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'products' => []]);
            exit;
        }
        $searchTerm = '%' . $term . '%';
        $products = $db->preparedFetchAll("
            SELECT p.id, p.name, p.base_price
            FROM products p
            WHERE p.is_active = 1 AND (p.name LIKE ? OR p.id LIKE ?)
            ORDER BY p.name LIMIT 20
        ", 'ss', [$searchTerm, $searchTerm]);
        echo json_encode(['success' => true, 'products' => $products]);
        exit;
    }
    
    // Search products for purchase
    if ($action === 'search_products_purchase') {
        $term = trim($_POST['term'] ?? '');
        if (strlen($term) < 2) {
            echo json_encode(['success' => true, 'products' => []]);
            exit;
        }
        $searchTerm = '%' . $term . '%';
        $products = $db->preparedFetchAll("
            SELECT id, name, base_price, category
            FROM products 
            WHERE name LIKE ? OR id LIKE ?
            ORDER BY name ASC LIMIT 15
        ", 'ss', [$searchTerm, $searchTerm]);
        echo json_encode(['success' => true, 'products' => $products]);
        exit;
    }
    
    // Get categories
    if ($action === 'get_categories') {
        $categories = $db->preparedFetchAll("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category", '', []);
        $cat_list = array_column($categories, 'category');
        echo json_encode(['success' => true, 'categories' => $cat_list]);
        exit;
    }
    
    // Create new product
    if ($action === 'create_product_quick') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $name = trim($_POST['name']);
        $category = trim($_POST['category']);
        $base_price = floatval($_POST['base_price']);
        
        if (empty($name)) {
            $response['message'] = 'Product name required';
            echo json_encode($response);
            exit;
        }
        
        $existing = $db->preparedFetchOne("SELECT id FROM products WHERE name = ?", 's', [$name]);
        if ($existing) {
            $response['success'] = true;
            $response['product_id'] = $existing['id'];
            $response['product_name'] = $name;
            $response['message'] = 'Product already exists';
            echo json_encode($response);
            exit;
        }
        
        $db->preparedExecute("
            INSERT INTO products (name, base_price, category, is_active, created_at)
            VALUES (?, ?, ?, 1, NOW())
        ", 'sds', [$name, $base_price, $category]);
        
        $product_id = $db->lastInsertId();
        logActivity($_SESSION['user_id'], "Created new product: $name (ID: $product_id)", 'product', $product_id);
        
        $response = ['success' => true, 'product_id' => $product_id, 'product_name' => $name, 'message' => 'Product created'];
        echo json_encode($response);
        exit;
    }
    
    // Update product name
    if ($action === 'update_product_name') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $product_id = intval($_POST['product_id']);
        $new_name = trim($_POST['new_name']);
        $category = trim($_POST['category'] ?? '');
        
        if (empty($new_name)) {
            $response['message'] = 'Product name required';
            echo json_encode($response);
            exit;
        }
        
        $existing = $db->preparedFetchOne("SELECT id FROM products WHERE name = ? AND id != ?", 'si', [$new_name, $product_id]);
        if ($existing) {
            $response['message'] = 'Product name already exists';
            echo json_encode($response);
            exit;
        }
        
        $db->preparedExecute("UPDATE products SET name = ?, category = ? WHERE id = ?", 'ssi', [$new_name, $category, $product_id]);
        logActivity($_SESSION['user_id'], "Updated product name: $new_name (ID: $product_id)", 'product', $product_id);
        
        $response = ['success' => true, 'message' => 'Product name updated successfully', 'product_name' => $new_name];
        echo json_encode($response);
        exit;
    }
    
    // Create purchase order
    if ($action === 'create_purchase') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $items = json_decode($_POST['items'], true);
        $total_cost = floatval($_POST['total_cost']);
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        
        if (empty($items)) {
            $response['message'] = 'No items in purchase order';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                INSERT INTO purchases (total_cost, purchase_status, purchased_by, branch_id, can_modify, created_at)
                VALUES (?, 'idle', ?, ?, 1, NOW())
            ", 'dii', [$total_cost, $_SESSION['user_id'], $branch_id]);
            $purchase_id = $db->lastInsertId();
            
            foreach ($items as $item) {
                $repos_id = !empty($item['repos_id']) ? intval($item['repos_id']) : null;
                $db->preparedExecute("
                    INSERT INTO purchase_items (purchase_id, product_id, repos_id, branch_id, quantity, price_per_item, item_status)
                    VALUES (?, ?, ?, ?, ?, ?, 'not_bought')
                ", 'iiiiid', [$purchase_id, $item['product_id'], $repos_id, $branch_id, $item['quantity'], $item['price_per_item']]);
            }
            
            logActivity($_SESSION['user_id'], "Created purchase order #$purchase_id with " . count($items) . " items", 'purchase', $purchase_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Purchase order created', 'purchase_id' => $purchase_id];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Update purchase order
    if ($action === 'update_purchase') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $purchase_id = intval($_POST['purchase_id']);
        $items = json_decode($_POST['items'], true);
        $total_cost = floatval($_POST['total_cost']);
        
        $purchase = $db->preparedFetchOne("SELECT purchase_status, can_modify FROM purchases WHERE id = ?", 'i', [$purchase_id]);
        if (!$purchase || $purchase['purchase_status'] !== 'idle' || $purchase['can_modify'] != 1) {
            $response['message'] = 'Purchase order cannot be edited';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("DELETE FROM purchase_items WHERE purchase_id = ?", 'i', [$purchase_id]);
            
            foreach ($items as $item) {
                $repos_id = !empty($item['repos_id']) ? intval($item['repos_id']) : null;
                $db->preparedExecute("
                    INSERT INTO purchase_items (purchase_id, product_id, repos_id, branch_id, quantity, price_per_item, item_status)
                    VALUES (?, ?, ?, ?, ?, ?, 'not_bought')
                ", 'iiiiid', [$purchase_id, $item['product_id'], $repos_id, $selected_branch_id, $item['quantity'], $item['price_per_item']]);
            }
            
            $db->preparedExecute("UPDATE purchases SET total_cost = ? WHERE id = ?", 'di', [$total_cost, $purchase_id]);
            
            logActivity($_SESSION['user_id'], "Updated purchase order #$purchase_id", 'purchase', $purchase_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Purchase order updated'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Submit budget request
    if ($action === 'submit_budget_request') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $purchase_id = intval($_POST['purchase_id']);
        
        $purchase = $db->preparedFetchOne("SELECT id, total_cost, branch_id FROM purchases WHERE id = ? AND purchase_status = 'idle'", 'i', [$purchase_id]);
        if (!$purchase) {
            $response['message'] = 'Purchase order not found or already submitted';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE purchases 
                SET purchase_status = 'pending', 
                    can_modify = 0,
                    approval_status = 'pending'
                WHERE id = ?
            ", 'i', [$purchase_id]);
            
            $db->preparedExecute("
                INSERT INTO budget_requests (
                    purchase_id, department_id, requester_id, title, amount, description, 
                    status, admin_status, accounting_status, overall_status, created_at
                ) VALUES (
                    ?, NULL, ?, ?, ?, ?, 
                    'pending', 'pending', 'pending', 'pending', NOW()
                )
            ", 'iisds', [$purchase_id, $_SESSION['user_id'], "Purchase Order #$purchase_id", $purchase['total_cost'], "Purchase order submitted for approval"]);
            
            logActivity($_SESSION['user_id'], "Submitted purchase order #$purchase_id as budget request", 'purchase', $purchase_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget request submitted for approval'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Approve budget admin
    if ($action === 'approve_budget_admin') {
        if (!$is_admin) {
            $response['message'] = 'Admin permission required';
            echo json_encode($response);
            exit;
        }
        
        $budget_id = intval($_POST['budget_id']);
        $notes = trim($_POST['notes'] ?? '');
        
        $budget = $db->preparedFetchOne("SELECT id, purchase_id, admin_status, accounting_status FROM budget_requests WHERE id = ?", 'i', [$budget_id]);
        if (!$budget) {
            $response['message'] = 'Budget request not found';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE budget_requests 
                SET admin_status = 'approved', 
                    admin_approved_by = ?, 
                    admin_approved_at = NOW(),
                    admin_notes = CONCAT(COALESCE(admin_notes, ''), '\n', ?),
                    overall_status = CASE 
                        WHEN accounting_status = 'approved' THEN 'approved' 
                        ELSE 'partially_approved' 
                    END
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            $db->preparedExecute("
                INSERT INTO approval_logs (budget_request_id, approver_id, approver_role, action, notes)
                VALUES (?, ?, 'admin', 'approved', ?)
            ", 'iis', [$budget_id, $_SESSION['user_id'], $notes]);
            
            if ($budget['accounting_status'] === 'approved') {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'fully_approved',
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ", 'ii', [$_SESSION['user_id'], $budget['purchase_id']]);
            } else {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'admin_approved'
                    WHERE id = ?
                ", 'i', [$budget['purchase_id']]);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget approved by Admin'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Approve budget accounting
    if ($action === 'approve_budget_accounting') {
        if (!$is_accounting) {
            $response['message'] = 'Accounting permission required';
            echo json_encode($response);
            exit;
        }
        
        $budget_id = intval($_POST['budget_id']);
        $notes = trim($_POST['notes'] ?? '');
        
        $budget = $db->preparedFetchOne("SELECT id, purchase_id, admin_status, accounting_status FROM budget_requests WHERE id = ?", 'i', [$budget_id]);
        if (!$budget) {
            $response['message'] = 'Budget request not found';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE budget_requests 
                SET accounting_status = 'approved', 
                    accounting_approved_by = ?, 
                    accounting_approved_at = NOW(),
                    accounting_notes = CONCAT(COALESCE(accounting_notes, ''), '\n', ?),
                    overall_status = CASE 
                        WHEN admin_status = 'approved' THEN 'approved' 
                        ELSE 'partially_approved' 
                    END
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            $db->preparedExecute("
                INSERT INTO approval_logs (budget_request_id, approver_id, approver_role, action, notes)
                VALUES (?, ?, 'accounting', 'approved', ?)
            ", 'iis', [$budget_id, $_SESSION['user_id'], $notes]);
            
            if ($budget['admin_status'] === 'approved') {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'fully_approved',
                        approved_at = NOW(),
                        approved_by = ?
                    WHERE id = ?
                ", 'ii', [$_SESSION['user_id'], $budget['purchase_id']]);
            } else {
                $db->preparedExecute("
                    UPDATE purchases 
                    SET approval_status = 'accounting_approved'
                    WHERE id = ?
                ", 'i', [$budget['purchase_id']]);
            }
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget approved by Accounting'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Get purchases
    if ($action === 'get_purchases') {
        $status_filter = $_POST['status_filter'] ?? 'idle';
        
        $purchases = $db->preparedFetchAll("
            SELECT p.*, u.fullname as created_by_name
            FROM purchases p
            JOIN bakery_users u ON p.purchased_by = u.id
            WHERE p.purchase_status = ?
            ORDER BY p.created_at DESC
        ", 's', [$status_filter]);
        
        foreach ($purchases as &$purchase) {
            $items = $db->preparedFetchAll("
                SELECT pi.*, pr.name as product_name, r.repo_name
                FROM purchase_items pi
                JOIN products pr ON pi.product_id = pr.id
                LEFT JOIN repos r ON pi.repos_id = r.id
                WHERE pi.purchase_id = ?
                ORDER BY pi.id ASC
            ", 'i', [$purchase['id']]);
            $purchase['items'] = $items;
            
            if ($purchase['purchase_status'] === 'pending') {
                $budget = $db->preparedFetchOne("
                    SELECT br.*, 
                           a_admin.fullname as admin_approved_by,
                           a_acc.fullname as accounting_approved_by,
                           br.admin_notes,
                           br.accounting_notes
                    FROM budget_requests br
                    LEFT JOIN bakery_users a_admin ON br.admin_approved_by = a_admin.id
                    LEFT JOIN bakery_users a_acc ON br.accounting_approved_by = a_acc.id
                    WHERE br.purchase_id = ?
                ", 'i', [$purchase['id']]);
                $purchase['budget'] = $budget;
            }
            
            if (!$purchase['total_cost']) {
                $total = 0;
                foreach ($items as $item) {
                    $total += $item['quantity'] * $item['price_per_item'];
                }
                $purchase['total_cost'] = $total;
            }
        }
        
        $response = ['success' => true, 'purchases' => $purchases];
        echo json_encode($response);
        exit;
    }
    
    // Mark item as bought
    if ($action === 'mark_item_bought') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $item_id = intval($_POST['item_id']);
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        
        $item = $db->preparedFetchOne("
            SELECT pi.*, pr.name as product_name, p.approval_status, p.purchase_status
            FROM purchase_items pi
            JOIN products pr ON pi.product_id = pr.id
            JOIN purchases p ON pi.purchase_id = p.id
            WHERE pi.id = ?
        ", 'i', [$item_id]);
        
        if (!$item) {
            $response['message'] = 'Item not found';
            echo json_encode($response);
            exit;
        }
        
        if ($item['approval_status'] !== 'fully_approved') {
            $response['message'] = 'Purchase order not fully approved yet. Waiting for Admin and Accounting approval.';
            echo json_encode($response);
            exit;
        }
        
        if ($item['item_status'] === 'bought') {
            $response['message'] = 'Item already marked as bought';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("UPDATE purchase_items SET item_status = 'bought' WHERE id = ?", 'i', [$item_id]);
            
            if ($item['repos_id']) {
                $current = $db->preparedFetchOne("
                    SELECT quantity FROM product_stock 
                    WHERE product_id = ? AND branch_id = ?
                ", 'ii', [$item['product_id'], $branch_id]);
                
                $new_qty = ($current ? (int)$current['quantity'] : 0) + $item['quantity'];
                
                if ($current) {
                    $db->preparedExecute("
                        UPDATE product_stock SET quantity = ? 
                        WHERE product_id = ? AND branch_id = ?
                    ", 'iii', [$new_qty, $item['product_id'], $branch_id]);
                } else {
                    $db->preparedExecute("
                        INSERT INTO product_stock (product_id, branch_id, quantity) 
                        VALUES (?, ?, ?)
                    ", 'iii', [$item['product_id'], $branch_id, $item['quantity']]);
                }
                
                $repos_stock = $db->preparedFetchOne("
                    SELECT id FROM repos_stock 
                    WHERE repos_id = ? AND product_id = ?
                ", 'ii', [$item['repos_id'], $item['product_id']]);
                
                if ($repos_stock) {
                    $db->preparedExecute("
                        UPDATE repos_stock SET quantity = quantity + ? 
                        WHERE repos_id = ? AND product_id = ?
                    ", 'iii', [$item['quantity'], $item['repos_id'], $item['product_id']]);
                } else {
                    $db->preparedExecute("
                        INSERT INTO repos_stock (repos_id, product_id, quantity) 
                        VALUES (?, ?, ?)
                    ", 'iii', [$item['repos_id'], $item['product_id'], $item['quantity']]);
                }
            }
            
            logActivity($_SESSION['user_id'], "Marked purchase item as bought: {$item['product_name']} x{$item['quantity']}", 'purchase', $item_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Item marked as bought and added to stock'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Complete purchase
    if ($action === 'complete_purchase') {
        if (!$can_manage_purchases) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $purchase_id = intval($_POST['purchase_id']);
        
        $db->preparedExecute("
            UPDATE purchases SET purchase_status = 'handled' WHERE id = ?
        ", 'i', [$purchase_id]);
        
        logActivity($_SESSION['user_id'], "Completed purchase order #$purchase_id", 'purchase', $purchase_id);
        $response = ['success' => true, 'message' => 'Purchase order completed'];
        echo json_encode($response);
        exit;
    }
    
    // Get repositories
    if ($action === 'get_repositories') {
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        $repos = $db->preparedFetchAll("
            SELECT id, repo_name, branch_id
            FROM repos 
            WHERE branch_id = ?
            ORDER BY repo_name ASC
        ", 'i', [$branch_id]);
        $response = ['success' => true, 'repositories' => $repos];
        echo json_encode($response);
        exit;
    }
    
    // Get department requests
    if ($action === 'get_department_requests') {
        $status = $_POST['status'] ?? 'pending';
        $date_from = $_POST['date_from'] ?? '';
        $date_to = $_POST['date_to'] ?? '';
        
        $sql = "
            SELECT dr.*, 
                   u.fullname as requester_name,
                   d.dept_name as department_name,
                   p.name as product_name,
                   r_from.repo_name as from_repo_name,
                   r_to.repo_name as to_repo_name
            FROM department_requests dr
            JOIN bakery_users u ON dr.requester_id = u.id
            JOIN departments d ON dr.requester_department_id = d.id
            JOIN products p ON dr.product_id = p.id
            LEFT JOIN repos r_from ON dr.from_repos_id = r_from.id
            LEFT JOIN repos r_to ON dr.to_repos_id = r_to.id
            WHERE dr.status = ?
        ";
        $params = [$status];
        $types = 's';
        
        if (!empty($date_from)) {
            $sql .= " AND DATE(dr.created_at) >= ?";
            $params[] = $date_from;
            $types .= 's';
        }
        if (!empty($date_to)) {
            $sql .= " AND DATE(dr.created_at) <= ?";
            $params[] = $date_to;
            $types .= 's';
        }
        
        $sql .= " ORDER BY dr.created_at DESC";
        
        $requests = $db->preparedFetchAll($sql, $types, $params);
        $response = ['success' => true, 'requests' => $requests];
        echo json_encode($response);
        exit;
    }
    
    // Create department request
    if ($action === 'create_department_request') {
        if (!$can_manage_inventory) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $from_repos_id = !empty($_POST['from_repos_id']) ? intval($_POST['from_repos_id']) : null;
        $to_repos_id = !empty($_POST['to_repos_id']) ? intval($_POST['to_repos_id']) : null;
        $notes = trim($_POST['notes'] ?? '');
        $request_number = 'DR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        $db->preparedExecute("
            INSERT INTO department_requests (
                request_number, requester_id, requester_department_id, product_id, 
                quantity, from_repos_id, to_repos_id, notes, status, created_at
            ) VALUES (
                ?, ?, (SELECT department_id FROM user_roles WHERE user_id = ? AND is_active = 1 LIMIT 1),
                ?, ?, ?, ?, ?, 'pending', NOW()
            )
        ", 'siiiiis', [$request_number, $_SESSION['user_id'], $_SESSION['user_id'], $product_id, $quantity, $from_repos_id, $to_repos_id, $notes]);
        
        logActivity($_SESSION['user_id'], "Created department request #$request_number", 'department_request', $db->lastInsertId());
        $response = ['success' => true, 'message' => 'Department request created'];
        echo json_encode($response);
        exit;
    }
    
    // Fulfill department request
    if ($action === 'fulfill_department_request') {
        if (!$can_manage_inventory) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $request_id = intval($_POST['request_id']);
        
        $request = $db->preparedFetchOne("
            SELECT dr.*, rs.quantity as available_stock
            FROM department_requests dr
            LEFT JOIN repos_stock rs ON dr.from_repos_id = rs.repos_id AND dr.product_id = rs.product_id
            WHERE dr.id = ? AND dr.status = 'pending'
        ", 'i', [$request_id]);
        
        if (!$request) {
            $response['message'] = 'Request not found or already processed';
            echo json_encode($response);
            exit;
        }
        
        if ($request['available_stock'] < $request['quantity']) {
            $response['message'] = 'Insufficient stock in source repository';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            $db->preparedExecute("
                UPDATE repos_stock 
                SET quantity = quantity - ? 
                WHERE repos_id = ? AND product_id = ?
            ", 'iii', [$request['quantity'], $request['from_repos_id'], $request['product_id']]);
            
            $dest_stock = $db->preparedFetchOne("
                SELECT id FROM repos_stock 
                WHERE repos_id = ? AND product_id = ?
            ", 'ii', [$request['to_repos_id'], $request['product_id']]);
            
            if ($dest_stock) {
                $db->preparedExecute("
                    UPDATE repos_stock 
                    SET quantity = quantity + ? 
                    WHERE repos_id = ? AND product_id = ?
                ", 'iii', [$request['quantity'], $request['to_repos_id'], $request['product_id']]);
            } else {
                $db->preparedExecute("
                    INSERT INTO repos_stock (repos_id, product_id, quantity) 
                    VALUES (?, ?, ?)
                ", 'iii', [$request['to_repos_id'], $request['product_id'], $request['quantity']]);
            }
            
            $db->preparedExecute("
                UPDATE department_requests 
                SET status = 'fulfilled', 
                    fulfilled_by = ?, 
                    fulfilled_at = NOW()
                WHERE id = ?
            ", 'ii', [$_SESSION['user_id'], $request_id]);
            
            logActivity($_SESSION['user_id'], "Fulfilled department request #{$request['request_number']}", 'department_request', $request_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Request fulfilled'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// =====================================================
// PAGE DATA
// =====================================================
$low_stock_threshold = setting('low_stock_threshold', 10);
$critical_stock_threshold = setting('critical_stock_threshold', 5);
$current_date = date('l, F j, Y');
$current_time = date('h:i A');

$total_products = $db->preparedFetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 1", '', [])['count'] ?? 0;

$low_stock_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count
    FROM product_stock ps
    WHERE ps.branch_id = ? AND ps.quantity <= ? AND ps.quantity > 0
", 'ii', [$selected_branch_id, $low_stock_threshold])['count'] ?? 0;
$out_of_stock_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count
    FROM product_stock ps
    WHERE ps.branch_id = ? AND ps.quantity = 0
", 'i', [$selected_branch_id])['count'] ?? 0;

$pending_returns_count = $db->preparedFetchOne("
    SELECT COUNT(DISTINCT r.id) as count
    FROM order_returns r
    JOIN return_items ri ON r.id = ri.return_id
    JOIN return_inventory_actions ria ON ri.id = ria.return_item_id
    WHERE r.status = 'approved' AND ria.status = 'pending'
", '', [])['count'] ?? 0;

$repositories_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM repos WHERE branch_id = ?
", 'i', [$selected_branch_id])['count'] ?? 0;

$idle_purchases_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM purchases WHERE purchase_status = 'idle'
", '', [])['count'] ?? 0;

$pending_purchases_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM purchases WHERE purchase_status = 'pending'
", '', [])['count'] ?? 0;

$pending_requests_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM department_requests WHERE status = 'pending'
", '', [])['count'] ?? 0;

$branch_display_name = '';
if ($selected_branch_id) {
    $binfo = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$selected_branch_id]);
    if ($binfo) $branch_display_name = $binfo['branch_name'];
}

$all_categories = $db->preparedFetchAll("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category", '', []);
$categories_json = json_encode(array_column($all_categories, 'category'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Dashboard · Fingerchops Ventures</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/inventory-dashboard.css">
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
</head>
<body>
    <div class="preloader" id="preloader">
        <img src="../../logo.jpeg" alt="Fingerchops Ventures" class="preloader-logo" onerror="this.style.display='none'">
    </div>
    
    <div class="refresh-indicator" id="refreshIndicator">
        <i class="fas fa-sync-alt"></i>
        <span id="refreshTimer">300</span>s
    </div>
    
    <div class="dashboard-container">
        <!-- HEADER with Branch Info -->
        <div class="inventory-header">
            <div class="header-top">
                <div class="header-title">
                    <h1><i class="fas fa-boxes"></i> Inventory Dashboard</h1>
                    <p>Welcome back, <strong><?php echo htmlspecialchars(explode(' ', $user['fullname'])[0]); ?></strong> · <?php echo $current_date; ?></p>
                    <p class="branch-info">
                        <i class="fas fa-store"></i> Viewing: 
                        <strong><?php echo htmlspecialchars($branch_name); ?></strong>
                        <?php if ($is_headquarters && $selected_branch_id): ?>
                            <a href="?branch=0" class="branch-link">(View All)</a>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="header-actions">
                    <button class="dashboard-btn" style="cursor:default;"><i class="fas fa-warehouse"></i> Main Dashboard</button>
                    <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    <div class="header-badge">
                        <i class="fas fa-shield-alt"></i> Level <?php echo $privilege_level; ?>
                        <span class="role-tag"><?php echo htmlspecialchars($role_name); ?></span>
                    </div>
                </div>
            </div>
            <div class="header-footer">
                <div class="permission-indicators">
                    <span class="perm-badge <?php echo $can_manage_inventory ? 'perm-allowed' : 'perm-restricted'; ?>">
                        <i class="fas <?php echo $can_manage_inventory ? 'fa-check-circle' : 'fa-lock'; ?>"></i> Manage Stock
                    </span>
                    <span class="perm-badge <?php echo $can_transfer_stock ? 'perm-allowed' : 'perm-restricted'; ?>">
                        <i class="fas <?php echo $can_transfer_stock ? 'fa-check-circle' : 'fa-lock'; ?>"></i> Transfers
                    </span>
                    <span class="perm-badge <?php echo $can_manage_purchases ? 'perm-allowed' : 'perm-restricted'; ?>">
                        <i class="fas <?php echo $can_manage_purchases ? 'fa-check-circle' : 'fa-lock'; ?>"></i> Purchases
                    </span>
                </div>
            </div>
        </div>
        
        <!-- BRANCH FILTER FOR HQ -->
        <?php if ($is_headquarters): ?>
        <div class="branch-filter-bar">
            <label><i class="fas fa-store"></i> Filter by Branch:</label>
            <select id="branchSelect" class="branch-filter-select">
                <option value="0" <?php echo $selected_branch_id == 0 ? 'selected' : ''; ?>>All Branches</option>
                <?php foreach ($all_branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch_id == $branch['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch['branch_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button id="applyBranchBtn" class="apply-filter-btn">Apply</button>
        </div>
        <?php endif; ?>
        
        <div class="section-header">
            <h2><i class="fas fa-info-circle"></i> Inventory Information</h2>
            <span class="section-subtitle">A sharper overview of current stock, branch metrics and procurement readiness.</span>
        </div>
        <div class="stats-grid">
            <div class="stat-card"><i class="fas fa-box"></i><div class="stat-value"><?php echo number_format($total_products); ?></div><div class="stat-label">Active Products</div></div>
            <div class="stat-card warning"><i class="fas fa-exclamation-triangle"></i><div class="stat-value"><?php echo $low_stock_count; ?></div><div class="stat-label">Low Stock</div></div>
            <div class="stat-card danger"><i class="fas fa-times-circle"></i><div class="stat-value"><?php echo $out_of_stock_count; ?></div><div class="stat-label">Out of Stock</div></div>
            <div class="stat-card info"><i class="fas fa-exchange-alt"></i><div class="stat-value"><?php echo $pending_returns_count; ?></div><div class="stat-label">Pending Returns</div></div>
            <div class="stat-card info"><i class="fas fa-shopping-cart"></i><div class="stat-value"><?php echo $pending_purchases_count; ?></div><div class="stat-label">Pending Purchases</div></div>
            <div class="stat-card info"><i class="fas fa-pen"></i><div class="stat-value"><?php echo $idle_purchases_count; ?></div><div class="stat-label">Draft Purchases</div></div>
            <div class="stat-card info"><i class="fas fa-truck"></i><div class="stat-value"><?php echo $pending_requests_count; ?></div><div class="stat-label">Dept Requests</div></div>
            <div class="stat-card repositories-card" onclick="location.href='tools/repos.php?branch=<?php echo $selected_branch_id; ?>'">
                <i class="fas fa-database"></i>
                <div class="stat-value"><?php echo $repositories_count; ?></div>
                <div class="stat-label">Repositories</div>
                <small class="click-hint">Click to manage</small>
            </div>
        </div>
        
        <div class="tabs">
            <button class="tab" data-tab="stock">Stock Management</button>
            <button class="tab" data-tab="transfers">Stock Transfers</button>
            <button class="tab active" data-tab="returns-inventory">Returns to Inventory</button>
            <button class="tab" data-tab="purchases">Purchase Report</button>
            <button class="tab" data-tab="reports">Reports & Tools</button>
        </div>
        
        <!-- Stock Management Tab -->
        <div id="stock-tab" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-sliders-h"></i> Handle Request</h2>
                <span class="section-subtitle">Search a product and remove stock with a clear reason.</span>
                <button class="btn-primary" id="showLowStockBtn"><i class="fas fa-eye"></i> View Low Stock</button>
            </div>
            <div class="arrange-purchase-section">
                <div class="section-header">
                    <h2><i class="fas fa-shopping-cart"></i> Arrange Purchase</h2>
                    <span class="section-subtitle">Start a purchase order or review inventory repositories before stock adjustment.</span>
                </div>
                <div class="tool-cards-grid arrange-purchase-grid">
                    <div class="tool-card" onclick="document.getElementById('createPurchaseBtn').click()">
                        <i class="fas fa-hand-holding-usd"></i>
                        <h3>New Purchase Order</h3>
                        <p>Create a purchase order for restocking.</p>
                    </div>
                    <div class="tool-card" onclick="activateInventoryTab('purchases')">
                        <i class="fas fa-truck-loading"></i>
                        <h3>Manage Arrival</h3>
                        <p>Track incoming purchase deliveries and mark items as received.</p>
                    </div>
                    <div class="tool-card" onclick="location.href='tools/repos.php?branch=<?php echo $selected_branch_id; ?>'">
                        <i class="fas fa-warehouse"></i>
                        <h3>Repository Review</h3>
                        <p>Inspect branch repository stock and choices.</p>
                    </div>
                </div>
            </div>
            <div class="stock-adjust-form">
                <div class="form-row">
                    <div class="form-group search-wrapper">
                        <label>Search Product</label>
                        <input type="text" id="productSearchStock" placeholder="Type product name or ID...">
                        <div id="productSearchResults" class="search-results" style="display:none;"></div>
                    </div>
                    <div class="form-group">
                        <label>Current Stock</label>
                        <input type="text" id="currentStock" readonly disabled>
                    </div>
                    <div class="form-group">
                        <label>Amount to Remove</label>
                        <input type="number" id="adjustQty" step="1" min="1" value="1" placeholder="Enter units to remove">
                    </div>
                    <div class="form-group">
                        <label>Reason</label>
                        <input type="text" id="adjustReason" placeholder="e.g., Damaged items, Missing stock">
                    </div>
                    <div class="form-group">
                        <button id="applyAdjustmentBtn" class="btn-primary">Remove Stock</button>
                    </div>
                </div>
            </div>
            <div class="low-stock-section" id="lowStockSection" style="display:none;">
                <h3><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h3>
                <div class="table-wrapper">
                    <table class="data-table" id="lowStockTable">
                        <thead>
                            <tr><th>Product</th><th>Stock</th><th>Status</th><th>Action</th>
                        </thead>
                        <tbody>
                            <tr><td colspan="4">Click "View Low Stock" to load</tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Stock Transfers Tab -->
        <div id="transfers-tab" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-exchange-alt"></i> Transfer Stock Between Branches</h2>
            </div>
            <div class="transfer-form">
                <div class="form-row">
                    <div class="form-group search-wrapper">
                        <label>Product</label>
                        <input type="text" id="transferProductSearch" placeholder="Search product...">
                        <div id="transferProductResults" class="search-results" style="display:none;"></div>
                        <input type="hidden" id="transferProductId">
                    </div>
                    <div class="form-group">
                        <label>Quantity</label>
                        <input type="number" id="transferQty" step="1" min="1" placeholder="Enter quantity">
                    </div>
                    <div class="form-group">
                        <label>From Branch</label>
                        <select id="fromBranch">
                            <?php if ($is_headquarters): ?>
                                <?php foreach ($all_branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="<?php echo $user_branch_id; ?>"><?php echo htmlspecialchars($branch_name); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>To Branch</label>
                        <select id="toBranch">
                            <?php foreach ($all_branches as $branch): ?>
                                <?php if ($branch['id'] != ($is_headquarters ? $selected_branch_id : $user_branch_id)): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" id="transferNotes" placeholder="Optional notes">
                    </div>
                    <div class="form-group">
                        <button id="initiateTransferBtn" class="btn-primary">Initiate Transfer</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Returns to Inventory Tab -->
        <div id="returns-inventory-tab" class="tab-content active">
            <div class="section-header">
                <h2><i class="fas fa-undo-alt"></i> Process Returned Items</h2>
                <button class="btn-secondary toggle-collapse-btn" data-target="returns-filters"><i class="fas fa-filter"></i> Show/Hide Filters</button>
                <button id="refreshReturnsBtn" class="btn-secondary"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
            
            <div id="returns-filters" class="filter-panel" style="display: none;">
                <div class="status-filter-bar">
                    <label><i class="fas fa-filter"></i> Filter by Return Status:</label>
                    <select id="statusFilterSelect" class="status-filter-select">
                        <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved (Pending Processing)</option>
                        <option value="completed" <?php echo $filter_status == 'completed' ? 'selected' : ''; ?>>Completed (Processed)</option>
                    </select>
                </div>
                <div class="date-filter-bar">
                    <div class="filter-group">
                        <label>Date From:</label>
                        <input type="date" id="returnsDateFrom" value="<?php echo $returns_date_from; ?>">
                    </div>
                    <div class="filter-group">
                        <label>Date To:</label>
                        <input type="date" id="returnsDateTo" value="<?php echo $returns_date_to; ?>">
                    </div>
                    <button id="applyReturnsFiltersBtn" class="btn-sm btn-primary">Apply Filters</button>
                    <button id="clearReturnsFiltersBtn" class="btn-sm btn-secondary">Clear</button>
                </div>
            </div>
            
            <div id="returnsContainer"><div class="text-center loading-pulse">Loading returns...</div></div>
        </div>
        
        <!-- Purchase Report Tab -->
        <div id="purchases-tab" class="tab-content">
            <div class="section-header">
                <h2><i class="fas fa-shopping-cart"></i> Purchase Orders</h2>
                <button id="createPurchaseBtn" class="btn-primary"><i class="fas fa-plus"></i> Create Purchase Order</button>
            </div>
            
            <div class="status-filter-bar">
                <label><i class="fas fa-filter"></i> Filter by Status:</label>
                <select id="purchaseStatusFilter" class="status-filter-select">
                    <option value="idle" <?php echo $purchase_filter == 'idle' ? 'selected' : ''; ?>>Draft (Idle)</option>
                    <option value="pending" <?php echo $purchase_filter == 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="handled" <?php echo $purchase_filter == 'handled' ? 'selected' : ''; ?>>Handled (Completed)</option>
                </select>
                <button id="applyPurchaseFilterBtn" class="btn-sm btn-primary">Apply Filter</button>
            </div>
            
            <div id="purchasesContainer"><div class="text-center loading-pulse">Loading purchase orders...</div></div>
        </div>
        
        <!-- Reports Tab -->
        <div id="reports-tab" class="tab-content">
            <div class="tool-cards-grid">
                <div class="tool-card" onclick="openDepartmentRequestsModal()">
                    <i class="fas fa-exchange-alt"></i>
                    <h3>Department Requests</h3>
                    <p>View and manage inter-department transfer requests</p>
                    <?php if ($pending_requests_count > 0): ?>
                        <span class="badge"><?php echo $pending_requests_count; ?> pending</span>
                    <?php endif; ?>
                </div>

                <div class="tool-card" onclick="location.href='tools/notifications.php'">
                    <i class="fas fa-bell"></i>
                    <h3>Notifications</h3>
                    <p>Open the central notifications manager for staff alerts.</p>
                </div>

                <div class="tool-card placeholder">
                    <i class="fas fa-chart-line"></i>
                    <h3>Stock Movement Report</h3>
                    <p>View stock movement history (Coming Soon)</p>
                </div>
                
                <div class="tool-card placeholder">
                    <i class="fas fa-chart-pie"></i>
                    <h3>Inventory Valuation</h3>
                    <p>View stock valuation report (Coming Soon)</p>
                </div>
            </div>
        </div>
        
        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> All changes are logged. Auto-refresh every 5 minutes.
        </div>
    </div>
    
    <!-- Create/Edit Purchase Modal -->
    <div id="purchaseModal" class="modal modal-large">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="purchaseModalTitle"><i class="fas fa-shopping-cart"></i> Create Purchase Order</h3>
                <button class="modal-close" onclick="closeModal('purchaseModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editPurchaseId" value="0">
                <div id="purchaseItemsContainer"></div>
                <button type="button" id="addPurchaseItemBtn" class="btn-secondary btn-sm"><i class="fas fa-plus"></i> Add Item</button>
            </div>
            <div class="modal-footer">
                <div class="purchase-summary">
                    <span>Total Cost:</span>
                    <strong id="purchaseTotalCost">₦0.00</strong>
                </div>
                <div class="modal-footer-buttons">
                    <button class="btn-secondary" onclick="closeModal('purchaseModal')">Cancel</button>
                    <button class="btn-primary" id="submitPurchaseBtn">Save Purchase Order</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Product Modal -->
    <div id="editProductModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Product</h3>
                <button class="modal-close" onclick="closeModal('editProductModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editProductId">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="editProductName" placeholder="Enter product name">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" id="editProductCategory" placeholder="Select or type category" list="category-list">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('editProductModal')">Cancel</button>
                <button class="btn-primary" id="confirmEditProductBtn">Update Product</button>
            </div>
        </div>
    </div>
    
    <!-- Budget Approval Modal -->
    <div id="budgetApprovalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Budget</h3>
                <button class="modal-close" onclick="closeModal('budgetApprovalModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approveBudgetId">
                <input type="hidden" id="approveRole">
                <div class="form-group">
                    <label>Approval Notes (optional)</label>
                    <textarea id="approveNotes" rows="3" placeholder="Add any notes about this approval..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('budgetApprovalModal')">Cancel</button>
                <button class="btn-primary" id="confirmApproveBtn">Confirm Approval</button>
            </div>
        </div>
    </div>
    
    <!-- Department Requests Modal -->
    <div id="deptRequestsModal" class="modal modal-large">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Department Requests</h3>
                <button class="modal-close" onclick="closeModal('deptRequestsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="filter-panel">
                    <div class="date-filter-bar">
                        <div class="filter-group">
                            <label>Date From:</label>
                            <input type="date" id="requestsDateFrom">
                        </div>
                        <div class="filter-group">
                            <label>Date To:</label>
                            <input type="date" id="requestsDateTo">
                        </div>
                        <button id="applyRequestsFiltersBtn" class="btn-sm btn-primary">Apply Filters</button>
                        <button id="clearRequestsFiltersBtn" class="btn-sm btn-secondary">Clear</button>
                    </div>
                </div>
                <div id="deptRequestsBody"><div class="loading">Loading requests...</div></div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('deptRequestsModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Create Department Request Modal -->
    <div id="createDeptRequestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> New Department Request</h3>
                <button class="modal-close" onclick="closeModal('createDeptRequestModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group search-wrapper">
                    <label>Product <span class="required">*</span></label>
                    <input type="text" id="deptProductSearch" placeholder="Search product...">
                    <div id="deptProductResults" class="search-results" style="display:none;"></div>
                    <input type="hidden" id="deptProductId">
                </div>
                <div class="form-group">
                    <label>Quantity <span class="required">*</span></label>
                    <input type="number" id="deptQuantity" min="1" value="1">
                </div>
                <div class="form-group">
                    <label>From Repository</label>
                    <select id="deptFromRepos">
                        <option value="">-- Select Source Repository --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>To Repository</label>
                    <select id="deptToRepos">
                        <option value="">-- Select Destination Repository --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="deptNotes" rows="3" placeholder="Reason for request..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('createDeptRequestModal')">Cancel</button>
                <button class="btn-primary" id="submitDeptRequestBtn">Submit Request</button>
            </div>
        </div>
    </div>
    
    <!-- Notification modal removed — use central Notifications tool -->
    
    <datalist id="category-list"></datalist>
    
    <div id="toastContainer" class="toast-container"></div>
    <div id="overlay" class="overlay"></div>
    
    <script>
        // Session-based validation - no explicit CSRF token needed for AJAX calls
        const selectedBranchId = <?php echo $selected_branch_id ?: 0; ?>;
        const isHeadquarters = <?php echo $is_headquarters ? 'true' : 'false'; ?>;
        const isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        const isAccounting = <?php echo $is_accounting ? 'true' : 'false'; ?>;
        const allCategories = <?php echo $categories_json; ?>;
        
        let selectedProductId = null;
        let currentStockValue = 0;
        let purchaseItemCount = 0;
        let repositories = [];
        let refreshCountdown = 300;
        let refreshTimer = null;
        let isEditingPurchase = false;
        let purchaseSearchDebounces = {};
        
        const categoryList = document.getElementById('category-list');
        if (allCategories && allCategories.length) {
            allCategories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat;
                categoryList.appendChild(option);
            });
        }
        
        window.addEventListener('load', function() {
            setTimeout(function() {
                const preloader = document.getElementById('preloader');
                if (preloader) {
                    preloader.classList.add('fade-out');
                    setTimeout(function() {
                        preloader.style.display = 'none';
                    }, 500);
                }
            }, 800);
        });
        
        function startAutoRefresh() {
            if (refreshTimer) clearInterval(refreshTimer);
            const timerElement = document.getElementById('refreshTimer');
            refreshCountdown = 300;
            if (timerElement) timerElement.textContent = refreshCountdown;
            
            refreshTimer = setInterval(() => {
                refreshCountdown--;
                if (timerElement) timerElement.textContent = refreshCountdown;
                if (refreshCountdown <= 0) {
                    localStorage.setItem('inventoryScrollPos', window.scrollY);
                    localStorage.setItem('returnsDateFrom', document.getElementById('returnsDateFrom')?.value || '');
                    localStorage.setItem('returnsDateTo', document.getElementById('returnsDateTo')?.value || '');
                    window.location.reload();
                }
            }, 1000);
        }
        
        function restoreScrollPosition() {
            const savedTab = localStorage.getItem('inventoryActiveTab');
            if (savedTab && document.querySelector('.tab[data-tab="' + savedTab + '"]')) {
                activateInventoryTab(savedTab);
                localStorage.removeItem('inventoryActiveTab');
            }
            const savedPos = localStorage.getItem('inventoryScrollPos');
            if (savedPos) {
                setTimeout(() => {
                    window.scrollTo(0, parseInt(savedPos));
                    localStorage.removeItem('inventoryScrollPos');
                }, 50);
            }
            const savedDateFrom = localStorage.getItem('returnsDateFrom');
            const savedDateTo = localStorage.getItem('returnsDateTo');
            if (savedDateFrom && document.getElementById('returnsDateFrom')) {
                document.getElementById('returnsDateFrom').value = savedDateFrom;
                localStorage.removeItem('returnsDateFrom');
            }
            if (savedDateTo && document.getElementById('returnsDateTo')) {
                document.getElementById('returnsDateTo').value = savedDateTo;
                localStorage.removeItem('returnsDateTo');
            }
        }

        function activateInventoryTab(tabName) {
            if (!tabName) return;
            document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabName));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === tabName + '-tab'));
            if (tabName === 'returns-inventory') {
                loadReturns();
            } else if (tabName === 'purchases') {
                loadPurchases();
            }
            localStorage.setItem('inventoryActiveTab', tabName);
        }
        
        window.addEventListener('beforeunload', function() {
            localStorage.setItem('inventoryScrollPos', window.scrollY);
            const activeTab = document.querySelector('.tab.active')?.dataset.tab || 'returns-inventory';
            localStorage.setItem('inventoryActiveTab', activeTab);
        });
        
        window.addEventListener('load', function() {
            restoreScrollPosition();
            startAutoRefresh();
        });
        
        function showToast(msg, type) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${msg}<span onclick="this.parentElement.remove()">×</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        function showLoading() { 
            const preloader = document.getElementById('preloader');
            if (preloader) {
                preloader.style.display = 'flex';
                preloader.classList.remove('fade-out');
            }
        }
        
        function hideLoading() { 
            const preloader = document.getElementById('preloader');
            if (preloader) {
                preloader.classList.add('fade-out');
                setTimeout(() => {
                    if (preloader) preloader.style.display = 'none';
                }, 500);
            }
        }
        
        async function ajaxPost(formData) {
            // No CSRF token needed - session validates the action
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            return await response.json();
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.getElementById('overlay').classList.remove('active');
        }
        
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.getElementById('overlay').classList.add('active');
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
        }
        
        function toggleCollapse(header) {
            const card = header.closest('.return-group-card, .purchase-card, .request-card');
            const body = card.querySelector('.return-group-body, .purchase-group-body, .request-group-body');
            const icon = header.querySelector('.collapse-icon');
            if (body) {
                if (body.style.display === 'none' || body.style.display === '') {
                    body.style.display = 'block';
                    if (icon) icon.style.transform = 'rotate(180deg)';
                } else {
                    body.style.display = 'none';
                    if (icon) icon.style.transform = 'rotate(0deg)';
                }
            }
        }
        
        document.querySelectorAll('.toggle-collapse-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const target = document.getElementById(targetId);
                if (target) {
                    if (target.style.display === 'none') {
                        target.style.display = 'block';
                        this.innerHTML = '<i class="fas fa-filter"></i> Hide Filters';
                    } else {
                        target.style.display = 'none';
                        this.innerHTML = '<i class="fas fa-filter"></i> Show Filters';
                    }
                }
            });
        });
        
        // ========== STOCK MANAGEMENT SEARCH ==========
        let stockSearchDebounce = null;
        const productSearchStock = document.getElementById('productSearchStock');
        if (productSearchStock) {
            productSearchStock.addEventListener('input', function() {
                const term = this.value.trim();
                const resultsDiv = document.getElementById('productSearchResults');
                
                if (term.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }
                
                if (stockSearchDebounce) clearTimeout(stockSearchDebounce);
                stockSearchDebounce = setTimeout(async () => {
                    const branchId = isHeadquarters ? (document.getElementById('branchSelect')?.value || selectedBranchId) : selectedBranchId;
                    const formData = new FormData();
                    formData.append('ajax', 1);
                    formData.append('action', 'search_products_stock');
                    formData.append('term', term);
                    formData.append('branch_id', branchId);
                    
                    try {
                        const data = await ajaxPost(formData);
                        if (data.success && data.products && data.products.length > 0) {
                            resultsDiv.innerHTML = data.products.map(p => `
                                <div class="search-result" onclick="selectStockProduct(${p.id}, '${escapeHtml(p.name)}', ${p.stock_quantity})">
                                    <strong>${escapeHtml(p.name)}</strong> (ID: ${p.id}) - Stock: ${p.stock_quantity} units
                                </div>
                            `).join('');
                            resultsDiv.style.display = 'block';
                        } else {
                            resultsDiv.innerHTML = '<div class="search-result">No products found</div>';
                            resultsDiv.style.display = 'block';
                        }
                    } catch(e) { 
                        console.error(e); 
                    }
                }, 300);
            });
        }
        
        window.selectStockProduct = function(productId, productName, currentStock) {
            document.getElementById('productSearchStock').value = productName;
            document.getElementById('currentStock').value = currentStock;
            selectedProductId = productId;
            currentStockValue = currentStock;
            document.getElementById('productSearchResults').style.display = 'none';
            showToast(`Selected: ${productName} (Current stock: ${currentStock})`, 'success');
        };
        
        document.getElementById('applyAdjustmentBtn')?.addEventListener('click', async () => {
            if (!selectedProductId) {
                showToast('Please select a product first', 'error');
                return;
            }
            
            const removeQty = parseInt(document.getElementById('adjustQty').value, 10);
            if (Number.isNaN(removeQty) || removeQty < 1) {
                showToast('Please enter a valid quantity to remove', 'error');
                return;
            }
            
            const reason = document.getElementById('adjustReason').value.trim();
            if (!reason) {
                showToast('Please provide a reason for removal', 'error');
                return;
            }
            
            const branchId = isHeadquarters ? (document.getElementById('branchSelect')?.value || selectedBranchId) : selectedBranchId;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'adjust_stock');
            formData.append('product_id', selectedProductId);
            formData.append('quantity', -Math.abs(removeQty));
            formData.append('reason', reason);
            formData.append('branch_id', branchId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    const newStock = currentStockValue - removeQty;
                    document.getElementById('currentStock').value = newStock;
                    currentStockValue = newStock;
                    document.getElementById('adjustQty').value = 1;
                    document.getElementById('adjustReason').value = '';
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        // ========== STOCK TRANSFER SEARCH ==========
        let transferSearchDebounce = null;
        const transferProductSearch = document.getElementById('transferProductSearch');
        if (transferProductSearch) {
            transferProductSearch.addEventListener('input', function() {
                const term = this.value.trim();
                const resultsDiv = document.getElementById('transferProductResults');
                
                if (term.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }
                
                if (transferSearchDebounce) clearTimeout(transferSearchDebounce);
                transferSearchDebounce = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax', 1);
                    formData.append('action', 'search_products_transfer');
                    formData.append('term', term);
                    
                    try {
                        const data = await ajaxPost(formData);
                        if (data.success && data.products && data.products.length > 0) {
                            resultsDiv.innerHTML = data.products.map(p => `
                                <div class="search-result" onclick="selectTransferProduct(${p.id}, '${escapeHtml(p.name)}')">
                                    <strong>${escapeHtml(p.name)}</strong> (ID: ${p.id})
                                </div>
                            `).join('');
                            resultsDiv.style.display = 'block';
                        } else {
                            resultsDiv.innerHTML = '<div class="search-result">No products found</div>';
                            resultsDiv.style.display = 'block';
                        }
                    } catch(e) { 
                        console.error(e); 
                    }
                }, 300);
            });
        }
        
        window.selectTransferProduct = function(productId, productName) {
            document.getElementById('transferProductSearch').value = productName;
            document.getElementById('transferProductId').value = productId;
            document.getElementById('transferProductResults').style.display = 'none';
            showToast(`Selected: ${productName}`, 'success');
        };
        
        document.getElementById('initiateTransferBtn')?.addEventListener('click', async () => {
            const productId = document.getElementById('transferProductId').value;
            const quantity = parseInt(document.getElementById('transferQty').value);
            const fromBranch = document.getElementById('fromBranch').value;
            const toBranch = document.getElementById('toBranch').value;
            const notes = document.getElementById('transferNotes').value;
            
            if (!productId) {
                showToast('Please select a product', 'error');
                return;
            }
            if (!quantity || quantity <= 0) {
                showToast('Please enter a valid quantity', 'error');
                return;
            }
            if (fromBranch === toBranch) {
                showToast('Source and destination branches must be different', 'error');
                return;
            }
            
            showToast('Transfer functionality - would transfer ' + quantity + ' units', 'info');
        });
        
        // ========== LOAD RETURNS ==========
        async function loadReturns() {
            const container = document.getElementById('returnsContainer');
            container.innerHTML = '<div class="text-center loading-pulse">Loading returns...</div>';
            
            const statusFilter = document.getElementById('statusFilterSelect').value;
            const dateFrom = document.getElementById('returnsDateFrom')?.value || '';
            const dateTo = document.getElementById('returnsDateTo')?.value || '';
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_returns');
            formData.append('status_filter', statusFilter);
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.returns && Object.keys(data.returns).length > 0) {
                    let html = '';
                    for (const returnId in data.returns) {
                        const ret = data.returns[returnId];
                        let itemsHtml = '';
                        let allItemsHandled = true;
                        
                        for (const item of ret.items) {
                            const isHandled = item.item_status === 'handled';
                            const statusClass = isHandled ? 'status-badge-handled' : 'status-badge-pending';
                            const statusText = isHandled ? 'Handled' : 'Pending';
                            const processBtnDisabled = isHandled ? 'disabled' : '';
                            
                            if (!isHandled) allItemsHandled = false;
                            
                            itemsHtml += `
                                <tr>
                                    <td>${escapeHtml(item.product_name)} (ID: ${item.product_id})</td>
                                    <td>${item.returned_quantity}</td>
                                    <td>${item.resell_quantity}</td>
                                    <td>${item.destroy_quantity}</td>
                                    <td><span class="${statusClass}">${statusText}</span></td>
                                    <td>
                                        <button class="btn-primary btn-sm process-item-btn" data-action-id="${item.action_id}" ${processBtnDisabled}>
                                            ${isHandled ? 'Done' : 'Process'}
                                        </button>
                                    </td>
                                </tr>
                            `;
                        }
                        
                        html += `
                            <div class="return-group-card">
                                <div class="return-group-header" onclick="toggleCollapse(this)">
                                    <div>
                                        <h3><i class="fas fa-receipt"></i> ${escapeHtml(ret.return_number)}</h3>
                                        <span class="return-badge">Status: ${ret.return_status === 'approved' ? 'Approved' : 'Completed'}</span>
                                        <span class="return-date">${new Date(ret.created_at).toLocaleDateString()}</span>
                                    </div>
                                    <div>
                                        <button class="complete-return-btn" data-return-id="${ret.return_id}" ${!allItemsHandled ? 'disabled' : ''}>
                                            <i class="fas fa-check-circle"></i> Complete Return
                                        </button>
                                        <i class="fas fa-chevron-down collapse-icon"></i>
                                    </div>
                                </div>
                                <div class="return-group-body" style="display: none;">
                                    <div class="table-wrapper">
                                        <table class="return-items-table">
                                            <thead>
                                                <tr><th>Product</th><th>Returned Qty</th><th>Resell</th><th>Destroy</th><th>Status</th><th>Action</th></tr>
                                            </thead>
                                            <tbody>${itemsHtml}</tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    container.innerHTML = html;
                    
                    document.querySelectorAll('.process-item-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const actionId = btn.dataset.actionId;
                            if (btn.disabled) return;
                            await processReturnItem(actionId);
                        });
                    });
                    
                    document.querySelectorAll('.complete-return-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const returnId = btn.dataset.returnId;
                            if (btn.disabled) return;
                            await completeReturn(returnId);
                        });
                    });
                    
                } else {
                    container.innerHTML = '<div class="text-center empty-state">No returns found for the selected filter.</div>';
                }
            } catch(e) {
                console.error(e);
                container.innerHTML = '<div class="text-center error-state">Error loading returns.</div>';
            }
        }
        
        async function processReturnItem(actionId) {
            if (!confirm('Process this return item? Destroy quantity will be removed from stock.')) return;
            
            const branchId = isHeadquarters ? (document.getElementById('branchSelect')?.value || selectedBranchId) : selectedBranchId;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'process_return_item');
            formData.append('action_id', actionId);
            formData.append('branch_id', branchId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadReturns();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        async function completeReturn(returnId) {
            if (!confirm('Mark this entire return as completed? This will mark all pending items as handled.')) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'complete_return');
            formData.append('return_id', returnId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadReturns();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        // ========== LOAD REPOSITORIES ==========
        async function loadRepositories() {
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_repositories');
            formData.append('branch_id', selectedBranchId);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    repositories = data.repositories;
                }
            } catch(e) {
                console.error('Error loading repositories:', e);
            }
        }
        
        // ========== PURCHASE FUNCTIONS ==========
        async function loadPurchases() {
            const container = document.getElementById('purchasesContainer');
            container.innerHTML = '<div class="text-center loading-pulse">Loading purchases...</div>';
            
            const statusFilter = document.getElementById('purchaseStatusFilter').value;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_purchases');
            formData.append('status_filter', statusFilter);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.purchases && data.purchases.length > 0) {
                    let html = '<div class="purchases-grid">';
                    for (const purchase of data.purchases) {
                        let allItemsBought = true;
                        let itemsHtml = '';
                        for (const item of purchase.items) {
                            const isBought = item.item_status === 'bought';
                            if (!isBought) allItemsBought = false;
                            itemsHtml += `
                                <tr>
                                    <td>${escapeHtml(item.product_name)}</td>
                                    <td>${item.quantity}</td>
                                    <td>₦${parseFloat(item.price_per_item).toFixed(2)}</td>
                                    <td>₦${parseFloat(item.quantity * item.price_per_item).toFixed(2)}</td>
                                    <td>${item.repo_name ? escapeHtml(item.repo_name) : '—'}</td>
                                    <td><span class="${isBought ? 'status-badge-handled' : 'status-badge-pending'}">${isBought ? 'Bought' : 'Pending'}</span></td>
                                    <td>
                                        ${!isBought && purchase.approval_status === 'fully_approved' ? `<button class="btn-primary btn-sm mark-bought-btn" data-item-id="${item.id}">Mark Bought</button>` : 
                                          (!isBought && purchase.approval_status !== 'fully_approved' ? '<span class="text-muted">Awaiting Approval</span>' : '—')}
                                    </td>
                                </tr>
                            `;
                        }
                        
                        let approvalHtml = '';
                        if (purchase.purchase_status === 'pending' && purchase.budget) {
                            const budget = purchase.budget;
                            const isFullyApproved = (budget.admin_status === 'approved' && budget.accounting_status === 'approved');
                            const overallStatusText = isFullyApproved ? 'Fully Approved ✓' : 'Awaiting Approval';
                            const overallStatusClass = isFullyApproved ? 'fully-approved' : 'pending';
                            
                            approvalHtml = `
                                <div class="approval-status">
                                    <div class="approval-item">
                                        <span class="approval-label">Admin Approval:</span>
                                        <span class="approval-badge ${budget.admin_status === 'approved' ? 'approved' : 'pending'}">
                                            ${budget.admin_status === 'approved' ? '✓ Approved' : 'Pending'}
                                        </span>
                                        ${budget.admin_approved_by ? `<span class="approval-by">by: ${escapeHtml(budget.admin_approved_by)}</span>` : ''}
                                        ${budget.admin_notes ? `<span class="approval-notes">notes: ${escapeHtml(budget.admin_notes)}</span>` : ''}
                                    </div>
                                    <div class="approval-item">
                                        <span class="approval-label">Accounting Approval:</span>
                                        <span class="approval-badge ${budget.accounting_status === 'approved' ? 'approved' : 'pending'}">
                                            ${budget.accounting_status === 'approved' ? '✓ Approved' : 'Pending'}
                                        </span>
                                        ${budget.accounting_approved_by ? `<span class="approval-by">by: ${escapeHtml(budget.accounting_approved_by)}</span>` : ''}
                                        ${budget.accounting_notes ? `<span class="approval-notes">notes: ${escapeHtml(budget.accounting_notes)}</span>` : ''}
                                    </div>
                                    <div class="approval-item overall">
                                        <span class="approval-label">Overall Status:</span>
                                        <span class="approval-badge ${overallStatusClass}">${overallStatusText}</span>
                                    </div>
                                </div>
                            `;
                            
                            if (!isFullyApproved) {
                                if (isAdmin && budget.admin_status !== 'approved') {
                                    approvalHtml += `<button class="btn-primary btn-sm approve-budget-btn" data-budget-id="${budget.id}" data-role="admin">Approve as Admin</button>`;
                                }
                                if (isAccounting && budget.accounting_status !== 'approved') {
                                    approvalHtml += `<button class="btn-primary btn-sm approve-budget-btn" data-budget-id="${budget.id}" data-role="accounting">Approve as Accounting</button>`;
                                }
                            }
                        }
                        
                        html += `
                            <div class="purchase-card">
                                <div class="purchase-header" onclick="toggleCollapse(this)">
                                    <div>
                                        <h3><i class="fas fa-receipt"></i> Purchase Order #${purchase.id}</h3>
                                        <span class="purchase-date">${new Date(purchase.created_at).toLocaleString()}</span>
                                    </div>
                                    <div>
                                        <span class="purchase-badge ${purchase.purchase_status === 'handled' ? 'handled' : (purchase.purchase_status === 'pending' ? 'pending' : 'idle')}">
                                            ${purchase.purchase_status === 'handled' ? 'Completed' : (purchase.purchase_status === 'pending' ? 'Pending Approval' : 'Draft')}
                                        </span>
                                        <span class="purchase-total">₦${parseFloat(purchase.total_cost).toFixed(2)}</span>
                                        <i class="fas fa-chevron-down collapse-icon"></i>
                                    </div>
                                </div>
                                <div class="purchase-group-body" style="display: none;">
                                    <div class="purchase-details">
                                        <div><strong>Created by:</strong> ${escapeHtml(purchase.created_by_name)}</div>
                                        ${approvalHtml}
                                    </div>
                                    <div class="table-wrapper">
                                        <table class="purchase-items-table">
                                            <thead>
                                                <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th><th>Repository</th><th>Status</th><th>Action</th></tr>
                                            </thead>
                                            <tbody>${itemsHtml}</tbody>
                                        </table>
                                    </div>
                                    ${purchase.purchase_status === 'idle' ? `
                                        <div class="purchase-actions">
                                            <button class="btn-secondary btn-sm review-purchase-btn" data-purchase-id="${purchase.id}" data-purchase-data='${JSON.stringify(purchase)}'>Review & Edit</button>
                                            <button class="btn-primary submit-budget-btn" data-purchase-id="${purchase.id}">Submit as Budget Request</button>
                                        </div>
                                    ` : (purchase.purchase_status === 'pending' && allItemsBought ? `
                                        <div class="purchase-actions">
                                            <button class="btn-primary complete-purchase-btn" data-purchase-id="${purchase.id}">Complete Purchase Order</button>
                                        </div>
                                    ` : '')}
                                </div>
                            </div>
                        `;
                    }
                    html += '</div>';
                    container.innerHTML = html;
                    
                    document.querySelectorAll('.mark-bought-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const itemId = btn.dataset.itemId;
                            await markItemBought(itemId);
                        });
                    });
                    
                    document.querySelectorAll('.complete-purchase-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const purchaseId = btn.dataset.purchaseId;
                            await completePurchase(purchaseId);
                        });
                    });
                    
                    document.querySelectorAll('.submit-budget-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const purchaseId = btn.dataset.purchaseId;
                            await submitBudgetRequest(purchaseId);
                        });
                    });
                    
                    document.querySelectorAll('.review-purchase-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const purchaseData = JSON.parse(btn.dataset.purchaseData);
                            await openEditPurchaseModal(purchaseData);
                        });
                    });
                    
                    document.querySelectorAll('.approve-budget-btn').forEach(btn => {
                        btn.addEventListener('click', async (e) => {
                            e.stopPropagation();
                            const budgetId = btn.dataset.budgetId;
                            const role = btn.dataset.role;
                            openBudgetApprovalModal(budgetId, role);
                        });
                    });
                    
                } else {
                    container.innerHTML = '<div class="text-center empty-state">No purchase orders found.</div>';
                }
            } catch(e) {
                console.error(e);
                container.innerHTML = '<div class="text-center error-state">Error loading purchases.</div>';
            }
        }
        
        async function markItemBought(itemId) {
            if (!confirm('Mark this item as bought? It will be added to stock.')) return;
            
            const branchId = isHeadquarters ? (document.getElementById('branchSelect')?.value || selectedBranchId) : selectedBranchId;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'mark_item_bought');
            formData.append('item_id', itemId);
            formData.append('branch_id', branchId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadPurchases();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        async function completePurchase(purchaseId) {
            if (!confirm('Mark this entire purchase as completed?')) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'complete_purchase');
            formData.append('purchase_id', purchaseId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadPurchases();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        async function submitBudgetRequest(purchaseId) {
            if (!confirm('Submit this purchase as a budget request for approval?')) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'submit_budget_request');
            formData.append('purchase_id', purchaseId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadPurchases();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        function openBudgetApprovalModal(budgetId, role) {
            document.getElementById('approveBudgetId').value = budgetId;
            document.getElementById('approveRole').value = role;
            document.getElementById('approveNotes').value = '';
            openModal('budgetApprovalModal');
        }
        
        document.getElementById('confirmApproveBtn')?.addEventListener('click', async () => {
            const budgetId = document.getElementById('approveBudgetId').value;
            const role = document.getElementById('approveRole').value;
            const notes = document.getElementById('approveNotes').value;
            
            const action = role === 'admin' ? 'approve_budget_admin' : 'approve_budget_accounting';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', action);
            formData.append('budget_id', budgetId);
            formData.append('notes', notes);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('budgetApprovalModal');
                    loadPurchases();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        // ========== EDIT PRODUCT FUNCTION ==========
        window.openEditProductModal = function(productId, productName, productCategory) {
            document.getElementById('editProductId').value = productId;
            document.getElementById('editProductName').value = productName;
            document.getElementById('editProductCategory').value = productCategory || '';
            openModal('editProductModal');
        };
        
        document.getElementById('confirmEditProductBtn')?.addEventListener('click', async () => {
            const productId = document.getElementById('editProductId').value;
            const newName = document.getElementById('editProductName').value.trim();
            const category = document.getElementById('editProductCategory').value.trim();
            
            if (!newName) {
                showToast('Product name required', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'update_product_name');
            formData.append('product_id', productId);
            formData.append('new_name', newName);
            formData.append('category', category);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('editProductModal');
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        // notification modal removed
        
        // ========== PURCHASE MODAL FUNCTIONS ==========
        function initPurchaseModal() {
            purchaseItemCount = 0;
            document.getElementById('purchaseItemsContainer').innerHTML = '';
            addPurchaseItemRow();
            updatePurchaseTotal();
        }
        
        function addPurchaseItemRowWithData(item) {
            const container = document.getElementById('purchaseItemsContainer');
            const index = purchaseItemCount;
            
            const newRow = document.createElement('div');
            newRow.className = 'purchase-item-row';
            newRow.setAttribute('data-item-index', index);
            newRow.innerHTML = `
                <div class="form-group search-wrapper">
                    <label>Product <span class="required">*</span></label>
                    <div class="product-search-wrapper">
                        <div class="search-input-group">
                            <input type="text" class="purchase-product-search" value="${escapeHtml(item.product_name)}" data-index="${index}" data-product-id="${item.product_id}">
                            <button type="button" class="edit-product-icon" data-product-id="${item.product_id}" data-product-name="${escapeHtml(item.product_name)}" data-product-category="${escapeHtml(item.category || '')}" title="Edit product name"><i class="fas fa-pencil-alt"></i></button>
                        </div>
                        <div class="purchase-search-results" data-index="${index}" style="display:none;"></div>
                    </div>
                    <input type="hidden" class="purchase-product-id" value="${item.product_id}" data-index="${index}">
                </div>
                <div class="new-product-fields" data-index="${index}" style="display:none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Product Category</label>
                            <input type="text" class="new-product-category" placeholder="Select or type new category" list="category-list" data-index="${index}">
                        </div>
                        <div class="form-group">
                            <label>Base Price (₦)</label>
                            <input type="number" class="new-product-price" step="0.01" min="0" placeholder="0.00" data-index="${index}">
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity <span class="required">*</span></label>
                        <input type="number" class="purchase-quantity" data-index="${index}" min="1" value="${item.quantity}">
                    </div>
                    <div class="form-group">
                        <label>Price per Item (₦) <span class="required">*</span></label>
                        <input type="number" class="purchase-price" data-index="${index}" step="0.01" min="0" value="${item.price_per_item}">
                    </div>
                    <div class="form-group">
                        <label>Repository</label>
                        <select class="purchase-repos" data-index="${index}">
                            <option value="">-- Select Repository --</option>
                            ${repositories.map(r => `<option value="${r.id}" ${item.repos_id == r.id ? 'selected' : ''}>${escapeHtml(r.repo_name)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subtotal</label>
                        <input type="text" class="purchase-subtotal" data-index="${index}" readonly disabled value="₦${(item.quantity * item.price_per_item).toFixed(2)}">
                    </div>
                    <div class="form-group">
                        <button type="button" class="remove-item-btn btn-secondary btn-sm" data-index="${index}" style="${index === 0 ? 'display:none;' : ''}"><i class="fas fa-trash"></i> Remove</button>
                    </div>
                </div>
            `;
            
            container.appendChild(newRow);
            attachPurchaseItemEvents(index);
            
            const editBtn = newRow.querySelector('.edit-product-icon');
            if (editBtn) {
                editBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const productId = editBtn.dataset.productId;
                    const productName = editBtn.dataset.productName;
                    const productCategory = editBtn.dataset.productCategory;
                    openEditProductModal(productId, productName, productCategory);
                });
            }
            
            purchaseItemCount++;
        }
        
        function addPurchaseItemRow() {
            const container = document.getElementById('purchaseItemsContainer');
            const index = purchaseItemCount;
            
            const newRow = document.createElement('div');
            newRow.className = 'purchase-item-row';
            newRow.setAttribute('data-item-index', index);
            newRow.innerHTML = `
                <div class="form-group search-wrapper">
                    <label>Product <span class="required">*</span></label>
                    <div class="product-search-wrapper">
                        <div class="search-input-group">
                            <input type="text" class="purchase-product-search" placeholder="Search existing product or type new name..." data-index="${index}">
                            <button type="button" class="edit-product-icon" style="display:none;" disabled><i class="fas fa-pencil-alt"></i></button>
                        </div>
                        <div class="purchase-search-results" data-index="${index}" style="display:none;"></div>
                    </div>
                    <input type="hidden" class="purchase-product-id" data-index="${index}">
                </div>
                <div class="new-product-fields" data-index="${index}" style="display:none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Product Category</label>
                            <input type="text" class="new-product-category" placeholder="Select or type new category" list="category-list" data-index="${index}">
                        </div>
                        <div class="form-group">
                            <label>Base Price (₦)</label>
                            <input type="number" class="new-product-price" step="0.01" min="0" placeholder="0.00" data-index="${index}">
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Quantity <span class="required">*</span></label>
                        <input type="number" class="purchase-quantity" data-index="${index}" min="1" value="1">
                    </div>
                    <div class="form-group">
                        <label>Price per Item (₦) <span class="required">*</span></label>
                        <input type="number" class="purchase-price" data-index="${index}" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Repository</label>
                        <select class="purchase-repos" data-index="${index}">
                            <option value="">-- Select Repository --</option>
                            ${repositories.map(r => `<option value="${r.id}">${escapeHtml(r.repo_name)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subtotal</label>
                        <input type="text" class="purchase-subtotal" data-index="${index}" readonly disabled>
                    </div>
                    <div class="form-group">
                        <button type="button" class="remove-item-btn btn-secondary btn-sm" data-index="${index}" style="${index === 0 ? 'display:none;' : ''}"><i class="fas fa-trash"></i> Remove</button>
                    </div>
                </div>
            `;
            
            container.appendChild(newRow);
            attachPurchaseItemEvents(index);
            purchaseItemCount++;
        }
        
        function removePurchaseItemRow(button) {
            const row = button.closest('.purchase-item-row');
            if (row && document.querySelectorAll('.purchase-item-row').length > 1) {
                row.remove();
                document.querySelectorAll('.purchase-item-row').forEach((row, idx) => {
                    row.setAttribute('data-item-index', idx);
                    row.querySelectorAll('[data-index]').forEach(el => {
                        el.setAttribute('data-index', idx);
                    });
                });
                purchaseItemCount = document.querySelectorAll('.purchase-item-row').length;
                updatePurchaseTotal();
            } else {
                showToast('Cannot remove the last item', 'error');
            }
        }
        
        function attachPurchaseItemEvents(index) {
            const searchInput = document.querySelector(`.purchase-product-search[data-index="${index}"]`);
            const quantityInput = document.querySelector(`.purchase-quantity[data-index="${index}"]`);
            const priceInput = document.querySelector(`.purchase-price[data-index="${index}"]`);
            const subtotalInput = document.querySelector(`.purchase-subtotal[data-index="${index}"]`);
            const removeBtn = document.querySelector(`.remove-item-btn[data-index="${index}"]`);
            const editBtn = document.querySelector(`.edit-product-icon[data-index="${index}"]`);
            
            if (removeBtn) {
                removeBtn.onclick = () => removePurchaseItemRow(removeBtn);
            }
            
            function updateSubtotal() {
                const qty = parseInt(quantityInput?.value) || 0;
                const price = parseFloat(priceInput?.value) || 0;
                const subtotal = qty * price;
                if (subtotalInput) subtotalInput.value = `₦${subtotal.toFixed(2)}`;
                updatePurchaseTotal();
            }
            
            quantityInput?.addEventListener('input', updateSubtotal);
            priceInput?.addEventListener('input', updateSubtotal);
            
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const term = this.value.trim();
                    const resultsDiv = document.querySelector(`.purchase-search-results[data-index="${index}"]`);
                    
                    if (term.length < 2) {
                        resultsDiv.innerHTML = '';
                        resultsDiv.style.display = 'none';
                        return;
                    }
                    
                    if (purchaseSearchDebounces[index]) clearTimeout(purchaseSearchDebounces[index]);
                    purchaseSearchDebounces[index] = setTimeout(async () => {
                        const formData = new FormData();
                        formData.append('ajax', 1);
                        formData.append('action', 'search_products_purchase');
                        formData.append('term', term);
                        
                        try {
                            const data = await ajaxPost(formData);
                            if (data.success && data.products && data.products.length > 0) {
                                resultsDiv.innerHTML = data.products.map(p => `
                                    <div class="search-result" onclick="selectPurchaseProduct(${index}, ${p.id}, '${escapeHtml(p.name)}', '${escapeHtml(p.category || '')}')">
                                        <strong>${escapeHtml(p.name)}</strong> (ID: ${p.id}) - ₦${parseFloat(p.base_price).toFixed(2)}
                                    </div>
                                `).join('');
                                resultsDiv.innerHTML += `
                                    <div class="search-result" onclick="createNewProductFromSearch(${index}, '${escapeHtml(term)}')">
                                        <strong><i class="fas fa-plus"></i> Create new: "${escapeHtml(term)}"</strong>
                                    </div>
                                `;
                                resultsDiv.style.display = 'block';
                            } else {
                                resultsDiv.innerHTML = `
                                    <div class="search-result" onclick="createNewProductFromSearch(${index}, '${escapeHtml(term)}')">
                                        <strong><i class="fas fa-plus"></i> Create new product: "${escapeHtml(term)}"</strong>
                                    </div>
                                `;
                                resultsDiv.style.display = 'block';
                            }
                        } catch(e) { console.error(e); }
                    }, 300);
                });
            }
        }
        
        window.selectPurchaseProduct = function(index, productId, productName, productCategory) {
            const searchInput = document.querySelector(`.purchase-product-search[data-index="${index}"]`);
            const productIdInput = document.querySelector(`.purchase-product-id[data-index="${index}"]`);
            const newProductFields = document.querySelector(`.new-product-fields[data-index="${index}"]`);
            const resultsDiv = document.querySelector(`.purchase-search-results[data-index="${index}"]`);
            const editBtn = document.querySelector(`.edit-product-icon[data-index="${index}"]`);
            
            if (searchInput) {
                searchInput.value = productName;
                searchInput.setAttribute('data-product-id', productId);
            }
            if (productIdInput) productIdInput.value = productId;
            if (newProductFields) newProductFields.style.display = 'none';
            if (resultsDiv) resultsDiv.style.display = 'none';
            
            if (editBtn) {
                editBtn.style.display = 'inline-flex';
                editBtn.disabled = false;
                editBtn.setAttribute('data-product-id', productId);
                editBtn.setAttribute('data-product-name', productName);
                editBtn.setAttribute('data-product-category', productCategory);
                editBtn.onclick = (e) => {
                    e.stopPropagation();
                    openEditProductModal(productId, productName, productCategory);
                };
            }
            
            const qtyInput = document.querySelector(`.purchase-quantity[data-index="${index}"]`);
            const priceInput = document.querySelector(`.purchase-price[data-index="${index}"]`);
            if (qtyInput && priceInput) {
                const event = new Event('input');
                priceInput.dispatchEvent(event);
            }
            
            showToast(`Product selected: ${productName}`, 'success');
        };
        
        window.createNewProductFromSearch = async function(index, productName) {
            const newProductFields = document.querySelector(`.new-product-fields[data-index="${index}"]`);
            const searchInput = document.querySelector(`.purchase-product-search[data-index="${index}"]`);
            const resultsDiv = document.querySelector(`.purchase-search-results[data-index="${index}"]`);
            const editBtn = document.querySelector(`.edit-product-icon[data-index="${index}"]`);
            
            if (searchInput) searchInput.value = productName;
            if (resultsDiv) resultsDiv.style.display = 'none';
            if (newProductFields) newProductFields.style.display = 'block';
            if (editBtn) editBtn.style.display = 'none';
            
            showToast(`Enter category and price for new product: ${productName}`, 'info');
        };
        
        async function createNewProduct(index) {
            const searchInput = document.querySelector(`.purchase-product-search[data-index="${index}"]`);
            const productName = searchInput?.value.trim();
            const categoryInput = document.querySelector(`.new-product-category[data-index="${index}"]`);
            const priceInput = document.querySelector(`.new-product-price[data-index="${index}"]`);
            const productIdInput = document.querySelector(`.purchase-product-id[data-index="${index}"]`);
            const newProductFields = document.querySelector(`.new-product-fields[data-index="${index}"]`);
            const editBtn = document.querySelector(`.edit-product-icon[data-index="${index}"]`);
            
            if (!productName) {
                showToast('Product name required', 'error');
                return false;
            }
            
            const category = categoryInput?.value.trim() || 'Uncategorized';
            const basePrice = parseFloat(priceInput?.value) || 0;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'create_product_quick');
            formData.append('name', productName);
            formData.append('category', category);
            formData.append('base_price', basePrice);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    if (productIdInput) productIdInput.value = data.product_id;
                    if (newProductFields) newProductFields.style.display = 'none';
                    
                    if (editBtn) {
                        editBtn.style.display = 'inline-flex';
                        editBtn.setAttribute('data-product-id', data.product_id);
                        editBtn.setAttribute('data-product-name', productName);
                        editBtn.setAttribute('data-product-category', category);
                        editBtn.onclick = (e) => {
                            e.stopPropagation();
                            openEditProductModal(data.product_id, productName, category);
                        };
                    }
                    
                    showToast(`Product "${productName}" created!`, 'success');
                    
                    const qtyInput = document.querySelector(`.purchase-quantity[data-index="${index}"]`);
                    const pricePerItem = document.querySelector(`.purchase-price[data-index="${index}"]`);
                    if (qtyInput && pricePerItem) {
                        const event = new Event('input');
                        pricePerItem.dispatchEvent(event);
                    }
                    
                    return true;
                } else {
                    showToast(data.message, 'error');
                    return false;
                }
            } catch(e) {
                showToast('Error creating product', 'error');
                return false;
            }
        }
        
        function updatePurchaseTotal() {
            let total = 0;
            document.querySelectorAll('.purchase-subtotal').forEach(input => {
                const val = input.value.replace('₦', '');
                const subtotal = parseFloat(val) || 0;
                total += subtotal;
            });
            document.getElementById('purchaseTotalCost').innerHTML = `₦${total.toFixed(2)}`;
        }
        
        async function openEditPurchaseModal(purchase) {
            isEditingPurchase = true;
            document.getElementById('editPurchaseId').value = purchase.id;
            document.getElementById('purchaseModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Purchase Order #' + purchase.id;
            
            document.getElementById('purchaseItemsContainer').innerHTML = '';
            purchaseItemCount = 0;
            
            if (purchase.items && purchase.items.length > 0) {
                for (const item of purchase.items) {
                    addPurchaseItemRowWithData(item);
                }
            }
            if (purchaseItemCount === 0) {
                addPurchaseItemRow();
            }
            
            updatePurchaseTotal();
            openModal('purchaseModal');
        }
        
        async function submitPurchase() {
            const items = [];
            let hasError = false;
            const totalRows = document.querySelectorAll('.purchase-item-row').length;
            const purchaseId = document.getElementById('editPurchaseId').value;
            const isEdit = purchaseId && purchaseId !== '0';
            
            for (let i = 0; i < totalRows; i++) {
                const productIdInput = document.querySelector(`.purchase-product-id[data-index="${i}"]`);
                const searchInput = document.querySelector(`.purchase-product-search[data-index="${i}"]`);
                const newProductFields = document.querySelector(`.new-product-fields[data-index="${i}"]`);
                const isNewProduct = newProductFields && newProductFields.style.display === 'block';
                
                let productId = productIdInput?.value;
                
                if (!productId && isNewProduct) {
                    const created = await createNewProduct(i);
                    if (created) {
                        productId = productIdInput?.value;
                    } else {
                        hasError = true;
                        continue;
                    }
                }
                
                if (!productId) {
                    showToast(`Item ${i+1}: Please select or create a product`, 'error');
                    hasError = true;
                    continue;
                }
                
                const quantity = parseInt(document.querySelector(`.purchase-quantity[data-index="${i}"]`)?.value) || 0;
                const price = parseFloat(document.querySelector(`.purchase-price[data-index="${i}"]`)?.value) || 0;
                const reposId = document.querySelector(`.purchase-repos[data-index="${i}"]`)?.value;
                
                if (quantity <= 0) {
                    showToast(`Item ${i+1}: Quantity must be at least 1`, 'error');
                    hasError = true;
                    continue;
                }
                
                if (price <= 0) {
                    showToast(`Item ${i+1}: Price per item is required`, 'error');
                    hasError = true;
                    continue;
                }
                
                items.push({
                    product_id: parseInt(productId),
                    quantity: quantity,
                    price_per_item: price,
                    repos_id: reposId || null
                });
            }
            
            if (hasError || items.length === 0) return;
            
            const total = parseFloat(document.getElementById('purchaseTotalCost').innerHTML.replace('₦', ''));
            const branchId = isHeadquarters ? (document.getElementById('branchSelect')?.value || selectedBranchId) : selectedBranchId;
            
            const action = isEdit ? 'update_purchase' : 'create_purchase';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', action);
            formData.append('items', JSON.stringify(items));
            formData.append('total_cost', total);
            formData.append('branch_id', branchId);
            if (isEdit) formData.append('purchase_id', purchaseId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('purchaseModal');
                    loadPurchases();
                    isEditingPurchase = false;
                    document.getElementById('editPurchaseId').value = '0';
                    document.getElementById('purchaseModalTitle').innerHTML = '<i class="fas fa-shopping-cart"></i> Create Purchase Order';
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        // ========== DEPARTMENT REQUESTS FUNCTIONS ==========
        async function openDepartmentRequestsModal() {
            const body = document.getElementById('deptRequestsBody');
            body.innerHTML = '<div class="loading">Loading requests...</div>';
            openModal('deptRequestsModal');
            await loadDepartmentRequests();
        }
        
        async function loadDepartmentRequests() {
            const body = document.getElementById('deptRequestsBody');
            const dateFrom = document.getElementById('requestsDateFrom')?.value || '';
            const dateTo = document.getElementById('requestsDateTo')?.value || '';
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_department_requests');
            formData.append('status', 'pending');
            formData.append('date_from', dateFrom);
            formData.append('date_to', dateTo);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.requests && data.requests.length > 0) {
                    let html = '<div class="requests-list">';
                    for (const req of data.requests) {
                        html += `
                            <div class="request-card">
                                <div class="request-header" onclick="toggleCollapse(this)">
                                    <div>
                                        <strong>${escapeHtml(req.request_number)}</strong>
                                        <span class="request-badge ${req.status}">${req.status}</span>
                                        <span class="request-date">${new Date(req.created_at).toLocaleDateString()}</span>
                                    </div>
                                    <div>
                                        <i class="fas fa-chevron-down collapse-icon"></i>
                                    </div>
                                </div>
                                <div class="request-group-body" style="display: none;">
                                    <div class="request-body">
                                        <div><strong>From:</strong> ${escapeHtml(req.department_name)}</div>
                                        <div><strong>Requested by:</strong> ${escapeHtml(req.requester_name)}</div>
                                        <div><strong>Product:</strong> ${escapeHtml(req.product_name)} (x${req.quantity})</div>
                                        <div><strong>From Repository:</strong> ${req.from_repo_name ? escapeHtml(req.from_repo_name) : '—'}</div>
                                        <div><strong>To Repository:</strong> ${req.to_repo_name ? escapeHtml(req.to_repo_name) : '—'}</div>
                                        ${req.notes ? `<div><strong>Notes:</strong> ${escapeHtml(req.notes)}</div>` : ''}
                                    </div>
                                    ${req.status === 'pending' ? `
                                        <div class="request-actions">
                                            <button class="btn-primary btn-sm" onclick="fulfillDepartmentRequest(${req.id})">Fulfill Request</button>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    }
                    html += '</div>';
                    body.innerHTML = html;
                } else {
                    body.innerHTML = '<div class="empty-state">No pending department requests.</div>';
                }
            } catch(e) {
                console.error(e);
                body.innerHTML = '<div class="error-state">Error loading requests.</div>';
            }
        }
        
        async function fulfillDepartmentRequest(requestId) {
            if (!confirm('Fulfill this request? Stock will be transferred from source to destination repository.')) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'fulfill_department_request');
            formData.append('request_id', requestId);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadDepartmentRequests();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        window.fulfillDepartmentRequest = fulfillDepartmentRequest;
        
        async function openCreateDeptRequestModal() {
            await loadRepositories();
            
            document.getElementById('deptProductSearch').value = '';
            document.getElementById('deptProductId').value = '';
            document.getElementById('deptQuantity').value = '1';
            document.getElementById('deptNotes').value = '';
            
            const fromRepos = document.getElementById('deptFromRepos');
            const toRepos = document.getElementById('deptToRepos');
            
            fromRepos.innerHTML = '<option value="">-- Select Source Repository --</option>' + 
                repositories.map(r => `<option value="${r.id}">${escapeHtml(r.repo_name)}</option>`).join('');
            toRepos.innerHTML = '<option value="">-- Select Destination Repository --</option>' + 
                repositories.map(r => `<option value="${r.id}">${escapeHtml(r.repo_name)}</option>`).join('');
            
            openModal('createDeptRequestModal');
        }
        
        let deptSearchDebounce = null;
        const deptProductSearch = document.getElementById('deptProductSearch');
        if (deptProductSearch) {
            deptProductSearch.addEventListener('input', function() {
                const term = this.value.trim();
                const resultsDiv = document.getElementById('deptProductResults');
                if (term.length < 2) {
                    resultsDiv.innerHTML = '';
                    resultsDiv.style.display = 'none';
                    return;
                }
                if (deptSearchDebounce) clearTimeout(deptSearchDebounce);
                deptSearchDebounce = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax', 1);
                    formData.append('action', 'search_products_purchase');
                    formData.append('term', term);
                    try {
                        const data = await ajaxPost(formData);
                        if (data.success && data.products && data.products.length > 0) {
                            resultsDiv.innerHTML = data.products.map(p => `
                                <div class="search-result" onclick="selectDeptProduct(${p.id}, '${escapeHtml(p.name)}')">
                                    <strong>${escapeHtml(p.name)}</strong> (ID: ${p.id})
                                </div>
                            `).join('');
                            resultsDiv.style.display = 'block';
                        } else {
                            resultsDiv.innerHTML = '<div class="search-result">No products found</div>';
                            resultsDiv.style.display = 'block';
                        }
                    } catch(e) { console.error(e); }
                }, 300);
            });
        }
        
        window.selectDeptProduct = function(id, name) {
            document.getElementById('deptProductSearch').value = name;
            document.getElementById('deptProductId').value = id;
            document.getElementById('deptProductResults').style.display = 'none';
        };
        
        document.getElementById('submitDeptRequestBtn')?.addEventListener('click', async () => {
            const productId = document.getElementById('deptProductId').value;
            const quantity = parseInt(document.getElementById('deptQuantity').value);
            const fromReposId = document.getElementById('deptFromRepos').value;
            const toReposId = document.getElementById('deptToRepos').value;
            const notes = document.getElementById('deptNotes').value;
            
            if (!productId) {
                showToast('Please select a product', 'error');
                return;
            }
            if (isNaN(quantity) || quantity <= 0) {
                showToast('Valid quantity required', 'error');
                return;
            }
            if (!fromReposId) {
                showToast('Please select source repository', 'error');
                return;
            }
            if (!toReposId) {
                showToast('Please select destination repository', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'create_department_request');
            formData.append('product_id', productId);
            formData.append('quantity', quantity);
            formData.append('from_repos_id', fromReposId);
            formData.append('to_repos_id', toReposId);
            formData.append('notes', notes);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('createDeptRequestModal');
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        // ========== FILTER APPLICATIONS ==========
        document.getElementById('applyReturnsFiltersBtn')?.addEventListener('click', () => {
            const dateFrom = document.getElementById('returnsDateFrom').value;
            const dateTo = document.getElementById('returnsDateTo').value;
            const status = document.getElementById('statusFilterSelect').value;
            const branchId = document.getElementById('branchSelect')?.value || selectedBranchId;
            window.location.href = window.location.pathname + '?branch=' + branchId + '&filter_status=' + status + '&returns_date_from=' + dateFrom + '&returns_date_to=' + dateTo;
        });
        
        document.getElementById('clearReturnsFiltersBtn')?.addEventListener('click', () => {
            document.getElementById('returnsDateFrom').value = '';
            document.getElementById('returnsDateTo').value = '';
            const status = document.getElementById('statusFilterSelect').value;
            const branchId = document.getElementById('branchSelect')?.value || selectedBranchId;
            window.location.href = window.location.pathname + '?branch=' + branchId + '&filter_status=' + status;
        });
        
        document.getElementById('applyRequestsFiltersBtn')?.addEventListener('click', async () => {
            await loadDepartmentRequests();
        });
        
        document.getElementById('clearRequestsFiltersBtn')?.addEventListener('click', () => {
            document.getElementById('requestsDateFrom').value = '';
            document.getElementById('requestsDateTo').value = '';
            loadDepartmentRequests();
        });
        
        document.getElementById('applyPurchaseFilterBtn')?.addEventListener('click', function() {
            const purchaseStatus = document.getElementById('purchaseStatusFilter').value;
            const branchId = document.getElementById('branchSelect')?.value || selectedBranchId;
            window.location.href = window.location.pathname + '?branch=' + branchId + '&purchase_filter=' + purchaseStatus;
        });
        
        document.getElementById('applyBranchBtn')?.addEventListener('click', function() {
            const branchId = document.getElementById('branchSelect').value;
            const purchaseStatus = document.getElementById('purchaseStatusFilter').value;
            const status = document.getElementById('statusFilterSelect').value;
            window.location.href = window.location.pathname + '?branch=' + branchId + '&filter_status=' + status + '&purchase_filter=' + purchaseStatus;
        });
        
        // ========== INITIALIZATION ==========
        document.getElementById('createPurchaseBtn')?.addEventListener('click', async () => {
            await loadRepositories();
            isEditingPurchase = false;
            document.getElementById('editPurchaseId').value = '0';
            document.getElementById('purchaseModalTitle').innerHTML = '<i class="fas fa-shopping-cart"></i> Create Purchase Order';
            initPurchaseModal();
            openModal('purchaseModal');
        });
        
        document.getElementById('addPurchaseItemBtn')?.addEventListener('click', () => addPurchaseItemRow());
        document.getElementById('submitPurchaseBtn')?.addEventListener('click', submitPurchase);
        
        window.openDepartmentRequestsModal = openDepartmentRequestsModal;
        window.openCreateDeptRequestModal = openCreateDeptRequestModal;
        // notification helper removed; central notifications tool handles messaging
        window.toggleCollapse = toggleCollapse;
        
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                activateInventoryTab(this.dataset.tab);
            });
        });
        
        document.getElementById('refreshReturnsBtn')?.addEventListener('click', loadReturns);
        document.getElementById('showLowStockBtn')?.addEventListener('click', () => {
            const section = document.getElementById('lowStockSection');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                const table = document.getElementById('lowStockTable').querySelector('tbody');
                table.innerHTML = '<tr><td colspan="4">Loading low stock items...</td></tr>';
                setTimeout(() => {
                    table.innerHTML = '<tr><td colspan="4">Low stock feature - would show products below threshold</tbody>';
                }, 500);
            } else {
                section.style.display = 'none';
            }
        });
        
        loadReturns();
        loadRepositories();
        loadPurchases();
    </script>
</body>
</html>