<?php
// =====================================================
// FILE: dashboards/staff/sales-dashboard.php
// PURPOSE: Sales Dashboard with Branch-Aware Stock Management
// VERSION: 4.2 (Active products only, CSRF protection added)
// =====================================================

// Production-safe error reporting
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

session_start();

// Load required files
require_once '../../conn.php';
require_once '../../includes/User.php';
require_once '../../includes/Security.php';
require_once '../../config/config_loader.php';

// Get database instance
$db = Database::getInstance();

// =====================================================
// CONFIGURABLE CONSTANTS
// =====================================================
define('LOW_STOCK_THRESHOLD', setting('low_stock_threshold', 10));
define('CRITICAL_STOCK_THRESHOLD', setting('critical_stock_threshold', 5));
define('PRODUCT_MANAGE_PRIVILEGE', setting('product_manage_privilege', 50));
define('PRICE_EDIT_PRIVILEGE', setting('price_edit_privilege', 60));
define('INVENTORY_MANAGE_PRIVILEGE', setting('inventory_manage_privilege', 30));
define('MANAGE_STAFF_PRIVILEGE', setting('manage_staff_privilege', 50));
define('AUTO_REFRESH_SECONDS', setting('sales_auto_refresh_seconds', 50));
define('RECENT_ACTIVITY_LIMIT', setting('recent_activity_limit', 10));

// Security check - must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login_signup.php');
    exit;
}

// Load user
$userObj = new User($_SESSION['user_id']);
$user = $userObj->getData();

if (!$user) {
    header('Location: ../../login_signup.php');
    exit;
}

// Get user's branch with name
$user_branch_id = $user['branch_id'] ?? null;
$user_branch_name = '';
$user_branch_code = '';
if ($user_branch_id) {
    $branch_info = $db->preparedFetchOne("SELECT branch_name, branch_code FROM branches WHERE id = ?", 'i', [$user_branch_id]);
    if ($branch_info) {
        $user_branch_name = $branch_info['branch_name'];
        $user_branch_code = $branch_info['branch_code'];
    }
}
$is_headquarters = ($user_branch_id == 1);

// Get all branches for HQ filter
$all_branches = [];
if ($is_headquarters) {
    $all_branches = $db->preparedFetchAll("SELECT id, branch_code, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_name", '', []);
}

// Determine if user is in Sales department
$in_sales = false;
$user_role = '';
$is_manager = false;
$privilege_level = $userObj->getPrivilegeLevel();
$department_id = null;

$active_role = $db->query("
    SELECT r.*, ur.department_id as assigned_dept, d.dept_name, d.dept_code
    FROM user_roles ur
    JOIN roles r ON ur.role_id = r.id
    LEFT JOIN departments d ON (r.department_id = d.id OR ur.department_id = d.id)
    WHERE ur.user_id = {$_SESSION['user_id']} AND ur.is_active = 1
    LIMIT 1
");

if ($active_role && mysqli_num_rows($active_role) > 0) {
    $role_data = mysqli_fetch_assoc($active_role);
    $user_role = $role_data['role_name'];
    $privilege_level = $role_data['privilege_level'];
    $is_manager = ($role_data['can_approve_requests'] == 1);
    $department_id = $role_data['department_id'] ?? $role_data['assigned_dept'] ?? null;
    $dept_name = $role_data['dept_name'] ?? '';
    $dept_code = $role_data['dept_code'] ?? '';

    if (stripos($dept_name, 'sales') !== false || $dept_code === 'SL') {
        $in_sales = true;
    }
}

if (!$in_sales) {
    error_log("Non-sales user attempted to access sales dashboard: " . $user['fullname']);
    header('Location: ../../login_signup.php?error=not_sales');
    exit;
}

$can_manage_staff = ($is_manager || $privilege_level >= MANAGE_STAFF_PRIVILEGE);

error_log("Sales dashboard accessed by: " . $user['fullname'] . " (Role: $user_role, Branch: $user_branch_name, HQ: " . ($is_headquarters ? 'Yes' : 'No') . ")");

$fullname = $user['fullname'] ?? 'User';
$first_name = explode(' ', $fullname)[0];

define('PRODUCT_IMAGE_DIR', '../images/products/');
if (!file_exists(PRODUCT_IMAGE_DIR)) {
    mkdir(PRODUCT_IMAGE_DIR, 0777, true);
}

// =====================================================
// CSRF PROTECTION - Session based
// =====================================================
if (!isset($_SESSION['sales_csrf_token'])) {
    $_SESSION['sales_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['sales_csrf_token'];

// =====================================================
// PERMISSION FUNCTIONS
// =====================================================
function canManageProducts($privilege_level, $is_manager) {
    return ($privilege_level >= PRODUCT_MANAGE_PRIVILEGE || $is_manager);
}

function canEditPrices($privilege_level, $is_manager) {
    return ($privilege_level >= PRICE_EDIT_PRIVILEGE || $is_manager);
}

function canManageInventory($privilege_level, $is_manager) {
    return ($privilege_level >= INVENTORY_MANAGE_PRIVILEGE || $is_manager);
}

$can_manage_products = canManageProducts($privilege_level, $is_manager);
$can_edit_prices = canEditPrices($privilege_level, $is_manager);
$can_manage_inventory = canManageInventory($privilege_level, $is_manager);

// =====================================================
// HANDLE ACCESS REQUESTS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_access') {
    header('Content-Type: application/json');
    
    // CSRF check for request access
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['sales_csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    
    $requested_permission = $db->escape($_POST['permission']);
    $reason = $db->escape($_POST['reason']);
    
    $has_permission = false;
    switch ($requested_permission) {
        case 'manage_products': $has_permission = $can_manage_products; break;
        case 'edit_prices': $has_permission = $can_edit_prices; break;
        case 'manage_inventory': $has_permission = $can_manage_inventory; break;
    }
    
    if ($has_permission) {
        echo json_encode(['success' => false, 'message' => 'You already have this permission']);
        exit;
    }
    
    $check = $db->query("SELECT id FROM permission_requests 
                        WHERE requester_id = {$_SESSION['user_id']} 
                        AND requested_permission = '$requested_permission' 
                        AND status = 'pending'");
    
    if ($check && mysqli_num_rows($check) > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending request']);
        exit;
    }
    
    $query = "INSERT INTO permission_requests (requester_id, requested_permission, reason, status, created_at) 
              VALUES ({$_SESSION['user_id']}, '$requested_permission', '$reason', 'pending', NOW())";
    
    if ($db->query($query)) {
        logActivity($_SESSION['user_id'], "Requested permission: $requested_permission", 'permission', $db->lastInsertId());
        echo json_encode(['success' => true, 'message' => 'Request submitted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit request']);
    }
    exit;
}

// =====================================================
// HANDLE PRODUCT ACTIONS (AJAX) - USING product_stock
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    // CSRF Protection - validate session token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['sales_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    // Upload Product Image
    if (isset($_POST['upload_image'])) {
        if (!$can_manage_inventory) {
            $response = ['success' => false, 'message' => 'You do not have permission to manage inventory'];
            echo json_encode($response);
            exit;
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['product_image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($ext, $allowed)) {
                $response = ['success' => false, 'message' => 'Invalid file type'];
                echo json_encode($response);
                exit;
            }
            
            foreach ($allowed as $old_ext) {
                $old_file = PRODUCT_IMAGE_DIR . $product_id . '.' . $old_ext;
                if (file_exists($old_file)) unlink($old_file);
            }
            
            $new_filename = $product_id . '.' . $ext;
            $destination = PRODUCT_IMAGE_DIR . $new_filename;
            
            if (!is_dir(PRODUCT_IMAGE_DIR)) mkdir(PRODUCT_IMAGE_DIR, 0777, true);
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                logActivity($_SESSION['user_id'], "Updated image for product ID: $product_id", 'product', $product_id);
                $response = ['success' => true, 'message' => 'Image uploaded successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Failed to upload image'];
            }
        } else {
            $response = ['success' => false, 'message' => 'No image uploaded'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Delete Product Image
    if (isset($_POST['delete_image'])) {
        if (!$can_manage_inventory) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $product_id = intval($_POST['product_id']);
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $deleted = false;
        
        foreach ($extensions as $ext) {
            $file = PRODUCT_IMAGE_DIR . $product_id . '.' . $ext;
            if (file_exists($file)) { unlink($file); $deleted = true; }
        }
        
        if ($deleted) {
            logActivity($_SESSION['user_id'], "Deleted image for product ID: $product_id", 'product', $product_id);
            $response = ['success' => true, 'message' => 'Image deleted successfully'];
        } else {
            $response = ['success' => false, 'message' => 'No image found'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Quick Update Product (for non-stock fields)
    if (isset($_POST['quick_update'])) {
        $field = $_POST['field'];
        
        if ($field === 'base_price' && !$can_edit_prices) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        if (in_array($field, ['name', 'description', 'category']) && !$can_manage_inventory) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $id = intval($_POST['product_id']);
        $field = $db->escape($_POST['field']);
        $value = $db->escape($_POST['value']);
        
        $allowed_fields = ['name', 'description', 'base_price', 'category', 'is_active'];
        if (!in_array($field, $allowed_fields)) {
            $response = ['success' => false, 'message' => 'Invalid field'];
            echo json_encode($response);
            exit;
        }
        
        if ($field === 'base_price') $value = floatval($value);
        
        $query = "UPDATE products SET $field = '$value' WHERE id = $id";
        
        if ($db->query($query)) {
            logActivity($_SESSION['user_id'], "Updated product $field: ID $id", 'product', $id);
            $response = ['success' => true, 'message' => 'Product updated'];
        } else {
            $response = ['success' => false, 'message' => 'Update failed'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Update Stock - Updates product_stock table for user's branch
    if (isset($_POST['update_stock'])) {
        if (!$can_manage_inventory) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        
        // Get the branch to update (use filter from AJAX if HQ, otherwise user's branch)
        $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        
        // Check permission: only HQ can update other branches
        if (!$is_headquarters && $branch_id != $user_branch_id) {
            $response = ['success' => false, 'message' => 'You can only edit stock for your own branch'];
            echo json_encode($response);
            exit;
        }
        
        if ($quantity < 0) $quantity = 0;
        
        // Check if product_stock exists for this branch
        $existing = $db->preparedFetchOne("SELECT id FROM product_stock WHERE product_id = ? AND branch_id = ?", 'ii', [$product_id, $branch_id]);
        if ($existing) {
            $result = $db->preparedExecute("UPDATE product_stock SET quantity = ? WHERE product_id = ? AND branch_id = ?", 'iii', [$quantity, $product_id, $branch_id]);
        } else {
            $result = $db->preparedExecute("INSERT INTO product_stock (product_id, branch_id, quantity) VALUES (?, ?, ?)", 'iii', [$product_id, $branch_id, $quantity]);
        }
        
        if ($result) {
            logActivity($_SESSION['user_id'], "Updated stock for product ID $product_id at branch $branch_id to $quantity", 'inventory', $product_id);
            $response = ['success' => true, 'message' => 'Stock updated successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Database error'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Add Product
    if (isset($_POST['add_product'])) {
        if (!$can_manage_products) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $name = $db->escape($_POST['name']);
        $description = $db->escape($_POST['description']);
        $base_price = floatval($_POST['base_price']);
        $category = $db->escape($_POST['category']);
        $initial_stock = intval($_POST['stock']);
        
        // Get the branch to add stock to (use filter from AJAX if HQ, otherwise user's branch)
        $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        
        if (!$is_headquarters && $branch_id != $user_branch_id) {
            $response = ['success' => false, 'message' => 'You can only add products to your own branch'];
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        
        $query = "INSERT INTO products (name, description, base_price, category, is_active, created_at) 
                  VALUES ('$name', '$description', $base_price, '$category', 1, NOW())";
        
        if ($db->query($query)) {
            $product_id = $db->lastInsertId();
            $db->preparedExecute("INSERT INTO product_stock (product_id, branch_id, quantity) VALUES (?, ?, ?)", 'iii', [$product_id, $branch_id, $initial_stock]);
            logActivity($_SESSION['user_id'], "Added new product: $name (ID: $product_id)", 'product', $product_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Product added successfully', 'product_id' => $product_id];
        } else {
            $db->rollback();
            $response = ['success' => false, 'message' => 'Failed to add product'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Toggle Product Status
    if (isset($_POST['toggle_status'])) {
        if (!$can_manage_products) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $id = intval($_POST['product_id']);
        $status = intval($_POST['status']);
        
        $query = "UPDATE products SET is_active = $status WHERE id = $id";
        
        if ($db->query($query)) {
            $action = $status ? 'Activated' : 'Deactivated';
            logActivity($_SESSION['user_id'], "$action product ID: $id", 'product', $id);
            $response = ['success' => true, 'message' => 'Product status updated'];
        } else {
            $response = ['success' => false, 'message' => 'Failed to update status'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Edit Description
    if (isset($_POST['edit_description'])) {
        if (!$can_manage_inventory) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $id = intval($_POST['product_id']);
        $description = $db->escape($_POST['description']);
        
        $query = "UPDATE products SET description = '$description' WHERE id = $id";
        
        if ($db->query($query)) {
            logActivity($_SESSION['user_id'], "Edited description for product ID: $id", 'product', $id);
            $response = ['success' => true, 'message' => 'Description updated'];
        } else {
            $response = ['success' => false, 'message' => 'Update failed'];
        }
        echo json_encode($response);
        exit;
    }
    
    // Update Sales Target - Branch-specific
    if (isset($_POST['update_target'])) {
        if (!$can_manage_products) {
            $response = ['success' => false, 'message' => 'Permission denied'];
            echo json_encode($response);
            exit;
        }
        
        $target_id = intval($_POST['target_id']);
        $product_id = intval($_POST['product_id']);
        $new_target = intval($_POST['target_quantity']);
        $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        
        if ($new_target < 0) $new_target = 0;
        
        $current_month_start = date('Y-m-01');
        $current_month_end = date('Y-m-t');
        
        if ($target_id > 0) {
            $db->preparedExecute("UPDATE sales_targets SET target_quantity = ? WHERE id = ? AND branch_id = ?", 'iii', [$new_target, $target_id, $branch_id]);
        } else {
            $check = $db->preparedFetchOne("SELECT id FROM sales_targets 
                                            WHERE product_id = ? 
                                            AND period_start = ? 
                                            AND period_type = 'monthly'
                                            AND branch_id = ?", 'isi', [$product_id, $current_month_start, $branch_id]);
            if ($check) {
                $db->preparedExecute("UPDATE sales_targets SET target_quantity = ? WHERE id = ?", 'ii', [$new_target, $check['id']]);
            } else {
                $db->preparedExecute("INSERT INTO sales_targets (product_id, target_quantity, period_type, period_start, period_end, created_by, branch_id) 
                                      VALUES (?, ?, 'monthly', ?, ?, ?, ?)", 'iissii', [$product_id, $new_target, $current_month_start, $current_month_end, $_SESSION['user_id'], $branch_id]);
            }
        }
        
        logActivity($_SESSION['user_id'], "Updated sales target for product ID $product_id at branch $branch_id to $new_target", 'sales_target', $product_id);
        $response = ['success' => true, 'message' => 'Target updated successfully'];
        echo json_encode($response);
        exit;
    }
}

// =====================================================
// GET SELECTED BRANCH FOR FILTERING (HQ only)
// =====================================================
$selected_branch_id = isset($_GET['branch']) ? (int)$_GET['branch'] : $user_branch_id;
if (!$is_headquarters) {
    $selected_branch_id = $user_branch_id;
}
$selected_branch_name = '';
if ($selected_branch_id) {
    $branch_info = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$selected_branch_id]);
    if ($branch_info) {
        $selected_branch_name = $branch_info['branch_name'];
    }
}

// =====================================================
// FETCH DATA - WITH BRANCH-AWARE STOCK
// =====================================================

// Get products with branch-specific stock from product_stock - ONLY ACTIVE PRODUCTS
$products = $db->preparedFetchAll("
    SELECT p.*, 
           COALESCE(ps.quantity, 0) as branch_stock
    FROM products p
    LEFT JOIN product_stock ps ON p.id = ps.product_id AND ps.branch_id = ?
    WHERE p.is_active = 1
    ORDER BY p.created_at DESC
", 'i', [$selected_branch_id]);

// Get product sales performance with branch-specific targets and sales - ONLY ACTIVE PRODUCTS
$sales_performance = $db->preparedFetchAll("
    SELECT 
        p.id,
        p.name,
        p.category,
        st.id as target_id,
        st.target_quantity,
        COALESCE(SUM(sr.quantity_sold), 0) as actual_sold,
        CASE 
            WHEN COALESCE(st.target_quantity, 0) > 0 
            THEN ROUND((COALESCE(SUM(sr.quantity_sold), 0) / st.target_quantity) * 100, 1)
            ELSE 0
        END as percentage_achieved
    FROM products p
    LEFT JOIN sales_targets st ON p.id = st.product_id 
        AND st.period_start <= CURDATE() 
        AND st.period_end >= CURDATE()
        AND st.period_type = 'monthly'
        AND (st.branch_id IS NULL OR st.branch_id = ?)
    LEFT JOIN sales_records sr ON p.id = sr.product_id 
        AND sr.sale_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        AND (sr.branch_id IS NULL OR sr.branch_id = ?)
    WHERE p.is_active = 1
    GROUP BY p.id, st.id, st.target_quantity
    ORDER BY percentage_achieved DESC
", 'ii', [$selected_branch_id, $selected_branch_id]);

// Get categories list - ONLY FROM ACTIVE PRODUCTS
$categories_list = [];
$cat_result = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' AND is_active = 1 ORDER BY category");
if ($cat_result) {
    while ($cat = mysqli_fetch_assoc($cat_result)) {
        $categories_list[] = $cat['category'];
    }
}
$categories_json = json_encode($categories_list);

// Stats - using branch-specific stock - ONLY ACTIVE PRODUCTS
$total_products = $db->preparedFetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 1", '', [])['count'] ?? 0;
$active_products = $db->preparedFetchOne("SELECT COUNT(*) as count FROM products WHERE is_active = 1", '', [])['count'] ?? 0;

// Low stock based on branch stock (using product_stock) - ONLY ACTIVE PRODUCTS
$low_stock = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM product_stock ps 
    JOIN products p ON ps.product_id = p.id
    WHERE ps.branch_id = ? AND ps.quantity < ? AND ps.quantity > 0 AND p.is_active = 1
", 'ii', [$selected_branch_id, LOW_STOCK_THRESHOLD])['count'] ?? 0;

$out_of_stock = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM product_stock ps 
    JOIN products p ON ps.product_id = p.id
    WHERE ps.branch_id = ? AND ps.quantity = 0 AND p.is_active = 1
", 'i', [$selected_branch_id])['count'] ?? 0;

$categories_count = count($categories_list);

// Get department staff count
$staff_count = 0;
if ($can_manage_staff && $department_id) {
    $staff_result = $db->query("
        SELECT COUNT(DISTINCT ur.user_id) as count 
        FROM user_roles ur
        JOIN roles r ON ur.role_id = r.id
        WHERE ur.is_active = 1 
          AND (r.department_id = $department_id OR ur.department_id = $department_id)
    ");
    if ($staff_result) {
        $staff_count = mysqli_fetch_assoc($staff_result)['count'];
    }
}

// Recent activity
$recent_activity = $db->query("
    SELECT al.*, u.fullname 
    FROM activity_logs al
    JOIN bakery_users u ON al.user_id = u.id
    WHERE al.action LIKE '%product%' OR al.action LIKE '%image%' OR al.action LIKE '%description%' OR al.action LIKE '%stock%'
    ORDER BY al.created_at DESC
    LIMIT " . RECENT_ACTIVITY_LIMIT
);

$current_date = date('l, F j, Y');
$branch_display_name = $selected_branch_name ?: ($is_headquarters ? 'All Branches' : ($user_branch_name ?: 'Unassigned'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Sales Dashboard · Fingerchops Ventures</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/sales-dashboard.css">
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
    <script>
        const AUTO_REFRESH_SECONDS = <?php echo AUTO_REFRESH_SECONDS; ?>;
        const CATEGORIES_LIST = <?php echo $categories_json; ?>;
        const USER_BRANCH_ID = <?php echo $user_branch_id ?: 0; ?>;
        const SELECTED_BRANCH_ID = <?php echo $selected_branch_id ?: 0; ?>;
        const IS_HEADQUARTERS = <?php echo $is_headquarters ? 'true' : 'false'; ?>;
        const LOW_STOCK_THRESHOLD = <?php echo LOW_STOCK_THRESHOLD; ?>;
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
    </script>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-message">Processing...</div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>
    
    <!-- Auto-refresh Indicator -->
    <div class="auto-refresh-indicator" id="refreshIndicator">
        <i class="fas fa-sync-alt"></i>
        <span id="refreshTimer"><?php echo AUTO_REFRESH_SECONDS; ?></span>s
    </div>
    
    <!-- Access Request Modal -->
    <div class="modal" id="requestModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-key"></i> Request Access</h2>
                <button class="modal-close" onclick="closeModal('requestModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="request-permission-info" id="requestPermissionInfo"></div>
                <div class="form-group">
                    <label for="requestReason">Reason for request:</label>
                    <textarea id="requestReason" rows="3" placeholder="Explain why you need this access..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('requestModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitRequestBtn">Submit Request</button>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        
        <!-- HEADER with Branch Info -->
        <div class="sales-header">
            <div class="header-top">
                <div class="header-title">
                    <h1><i class="fas fa-chart-line"></i> Sales Dashboard</h1>
                    <p>Welcome back, <strong><?php echo htmlspecialchars($first_name); ?></strong> · <?php echo $current_date; ?></p>
                    <p class="branch-info">
                        <i class="fas fa-store"></i> Viewing: 
                        <strong><?php echo htmlspecialchars($branch_display_name); ?></strong>
                        <?php if ($is_headquarters && $selected_branch_id): ?>
                            <a href="?branch=0" class="branch-link">(View All)</a>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="header-actions">
                    <button class="dashboard-btn" style="cursor:default;"><i class="fas fa-home"></i> Main Dashboard</button>
                    <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    <div class="header-badge">
                        <i class="fas fa-shield-alt"></i> Level <?php echo $privilege_level; ?>
                        <span class="role-tag"><?php echo htmlspecialchars($user_role ?: 'Sales Staff'); ?></span>
                    </div>
                </div>
            </div>
            <div class="header-footer">
                <div class="permission-indicators">
                    <span class="perm-badge <?php echo $can_manage_products ? 'perm-allowed' : 'perm-restricted'; ?>">
                        <i class="fas <?php echo $can_manage_products ? 'fa-check-circle' : 'fa-lock'; ?>"></i> Products
                    </span>
                    <span class="perm-badge <?php echo $can_edit_prices ? 'perm-allowed' : 'perm-restricted'; ?>">
                        <i class="fas <?php echo $can_edit_prices ? 'fa-check-circle' : 'fa-lock'; ?>"></i> Prices
                    </span>
                    <span class="perm-badge <?php echo $can_manage_inventory ? 'perm-allowed' : 'perm-restricted'; ?>">
                        <i class="fas <?php echo $can_manage_inventory ? 'fa-check-circle' : 'fa-lock'; ?>"></i> Inventory
                    </span>
                </div>
            </div>
        </div>
        
        <!-- BRANCH FILTER FOR HQ -->
        <?php if ($is_headquarters): ?>
        <div class="branch-filter-bar">
            <label><i class="fas fa-store"></i> Filter by Branch:</label>
            <select id="branchFilterSelect" class="branch-filter-select">
                <option value="0" <?php echo $selected_branch_id == 0 ? 'selected' : ''; ?>>All Branches</option>
                <?php foreach ($all_branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch_id == $branch['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button id="applyBranchFilter" class="apply-filter-btn">Apply</button>
        </div>
        <?php endif; ?>
        
        <!-- QUICK ACTIONS SECTION -->
        <div class="quick-actions-section">
            <div class="section-header">
                <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                <span class="section-subtitle">Frequently used tools</span>
            </div>
            <div class="quick-actions-grid">
                <a href="tools/add-sale.php" class="quick-action-card">
                    <div class="card-icon"><i class="fas fa-cart-plus"></i></div>
                    <div class="card-content">
                        <span class="card-title">Cashier Sales</span>
                        <small>Add new sales transaction</small>
                    </div>
                </a>
                <a href="tools/sales-history.php" class="quick-action-card">
                    <div class="card-icon"><i class="fas fa-history"></i></div>
                    <div class="card-content">
                        <span class="card-title">Sales History</span>
                        <small>View past transactions</small>
                    </div>
                </a>
                <?php if ($can_manage_staff): ?>
                <a href="tools/manage-staff.php" class="quick-action-card highlight">
                    <div class="card-icon"><i class="fas fa-users"></i></div>
                    <div class="card-content">
                        <span class="card-title">Manage Staff</span>
                        <small><?php echo $staff_count; ?> team members</small>
                    </div>
                </a>
                <?php else: ?>
                <div class="quick-action-card locked" onclick="showRequestModal('manage_staff', 'Manage Staff')">
                    <div class="card-icon"><i class="fas fa-lock"></i></div>
                    <div class="card-content">
                        <span class="card-title">Manage Staff</span>
                        <small>Manager access only</small>
                    </div>
                </div>
                <?php endif; ?>
                <a href="tools/sales-analysis.php" class="quick-action-card">
                    <div class="card-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="card-content">
                        <span class="card-title">Sales Analysis</span>
                        <small>Analyze sales trends</small>
                    </div>
                </a>
                <a href="tools/sales-targets.php" class="quick-action-card">
                    <div class="card-icon"><i class="fas fa-bullseye"></i></div>
                    <div class="card-content">
                        <span class="card-title">Sales Targets</span>
                        <small>Set & track goals</small>
                    </div>
                </a>
                <a href="tools/customer-feedback.php" class="quick-action-card">
                    <div class="card-icon"><i class="fas fa-star"></i></div>
                    <div class="card-content">
                        <span class="card-title">Customer Feedback</span>
                        <small>View ratings & reviews</small>
                    </div>
                </a>
            </div>
        </div>
        
        <!-- STATS GRID -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon-wrapper"><i class="fas fa-boxes"></i></div>
                <div class="stat-number"><?php echo number_format($total_products); ?></div>
                <div class="stat-label">Total Products</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo number_format($active_products); ?></div>
                <div class="stat-label">Active Products</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon-wrapper"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?php echo number_format($low_stock); ?></div>
                <div class="stat-label">Low Stock</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-icon-wrapper"><i class="fas fa-times-circle"></i></div>
                <div class="stat-number"><?php echo number_format($out_of_stock); ?></div>
                <div class="stat-label">Out of Stock</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper"><i class="fas fa-tags"></i></div>
                <div class="stat-number"><?php echo number_format($categories_count); ?></div>
                <div class="stat-label">Categories</div>
            </div>
        </div>
        
        <!-- SALES PERFORMANCE SECTION -->
        <div class="performance-section">
            <div class="section-header">
                <h2><i class="fas fa-chart-line"></i> Product Performance (Monthly)</h2>
                <span class="branch-subtitle"><?php echo htmlspecialchars($branch_display_name); ?> Branch</span>
            </div>
            <div class="performance-filters">
                <input type="text" id="perfSearch" placeholder="Search products..." class="filter-input">
                <select id="perfCategoryFilter" class="filter-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories_list as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="performance-grid-wrapper">
                <div class="performance-grid" id="performanceGrid">
                    <?php if ($sales_performance && count($sales_performance) > 0): ?>
                        <?php foreach ($sales_performance as $product): 
                            $percentage = $product['percentage_achieved'];
                            $bar_color = $percentage >= 100 ? '#10b981' : ($percentage >= 70 ? '#f59e0b' : '#ef4444');
                        ?>
                        <div class="performance-card" data-category="<?php echo htmlspecialchars($product['category']); ?>" data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>">
                            <div class="performance-header">
                                <h4><?php echo htmlspecialchars($product['name']); ?></h4>
                                <span class="product-category"><?php echo htmlspecialchars($product['category']); ?></span>
                            </div>
                            <div class="performance-stats">
                                <div class="stat-item">
                                    <span class="stat-value"><?php echo number_format($product['actual_sold']); ?></span>
                                    <span class="stat-label">Sold</span>
                                </div>
                                <div class="stat-item target-stat">
                                    <span class="stat-value" data-target-id="<?php echo $product['target_id']; ?>" data-product-id="<?php echo $product['id']; ?>">
                                        <?php echo number_format($product['target_quantity']); ?>
                                    </span>
                                    <span class="stat-label">Target</span>
                                    <?php if ($can_manage_products): ?>
                                        <i class="fas fa-pencil-alt edit-target-icon" style="cursor:pointer; margin-left:5px; font-size:12px;" 
                                        data-product-id="<?php echo $product['id']; ?>" 
                                        data-target-id="<?php echo $product['target_id']; ?>"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-value <?php echo $percentage >= 100 ? 'text-success' : ($percentage >= 70 ? 'text-warning' : 'text-danger'); ?>">
                                        <?php echo $percentage; ?>%
                                    </span>
                                    <span class="stat-label">Achieved</span>
                                </div>
                            </div>
                            <div class="progress-container">
                                <div class="progress-bar" style="width: <?php echo min(100, $percentage); ?>%; background: <?php echo $bar_color; ?>;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <p>No sales data available for this branch</p>
                            <small>Record sales to see performance</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- MAIN INVENTORY SECTION -->
        <div class="inventory-section">
            <div class="section-header">
                <h2><i class="fas fa-boxes"></i> Inventory Management</h2>
                <?php if ($can_manage_products): ?>
                    <button class="btn-primary" onclick="showAddProductModal()"><i class="fas fa-plus"></i> Add Product</button>
                <?php else: ?>
                    <button class="btn-primary locked" onclick="showRequestModal('manage_products', 'Add Product')"><i class="fas fa-lock"></i> Add Product</button>
                <?php endif; ?>
            </div>
            
            <!-- AWARENESS NOTICE -->
            <div class="inventory-notice-banner">
                <div class="notice-icon">
                    <i class="fas fa-warehouse"></i>
                </div>
                <div class="notice-content">
                    <strong>Showing active products only.</strong> 
                    If you need to reactivate a deactivated product, please contact the 
                    <span class="highlight-dept">Inventory Department</span>.
                </div>
            </div>
            
            <div class="inventory-filters">
                <input type="text" id="invSearch" placeholder="Search products..." class="filter-input">
                <select id="invCategoryFilter" class="filter-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories_list as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="table-container">
                <div class="table-scroll-wrapper">
                    <table class="products-table">
                        <thead>
                                <th>ID</th>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                             </thead>
                        <tbody id="productsTableBody">
                            <?php if ($products && count($products) > 0): ?>
                                <?php foreach ($products as $product): 
                                    $stock = $product['branch_stock'];
                                    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                    $image_found = '../../logo.jpeg';
                                    $has_image = false;
                                    
                                    foreach ($image_extensions as $ext) {
                                        $image_path = PRODUCT_IMAGE_DIR . $product['id'] . '.' . $ext;
                                        if (file_exists($image_path)) {
                                            $image_found = '../images/products/' . $product['id'] . '.' . $ext;
                                            $has_image = true;
                                            break;
                                        }
                                    }
                                    
                                    if ($stock == 0) {
                                        $stock_class = 'stock-out';
                                        $stock_label = 'Out of Stock';
                                    } elseif ($stock < LOW_STOCK_THRESHOLD) {
                                        $stock_class = 'stock-low';
                                        $stock_label = 'Low Stock';
                                    } else {
                                        $stock_class = 'stock-good';
                                        $stock_label = 'In Stock';
                                    }
                                ?>
                                <tr class="product-row" data-product-id="<?php echo $product['id']; ?>" data-category="<?php echo htmlspecialchars($product['category']); ?>" data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>">
                                    <td class="product-id">#<?php echo $product['id']; ?>
                                    <td class="product-image-cell">
                                        <?php if ($can_manage_inventory): ?>
                                            <div class="product-image-wrapper" onclick="showImageUploadModal(<?php echo $product['id']; ?>, '<?php echo $image_found; ?>', <?php echo $has_image ? 'true' : 'false'; ?>)">
                                                <img src="<?php echo $image_found; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-thumbnail" onerror="this.src='../../logo.jpeg'">
                                                <div class="image-overlay"><i class="fas fa-camera"></i></div>
                                            </div>
                                        <?php else: ?>
                                            <div class="product-image-wrapper locked" onclick="showRequestModal('manage_inventory', 'Upload product image')">
                                                <img src="<?php echo $image_found; ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-thumbnail" onerror="this.src='../../logo.jpeg'">
                                                <div class="image-overlay"><i class="fas fa-lock"></i></div>
                                            </div>
                                        <?php endif; ?>
                                      </div>
                                     </div>
                                     </td>
                                    <td class="product-name-cell">
                                        <?php if ($can_manage_inventory): ?>
                                            <span class="editable-field stock-field" data-field="stock_quantity" data-id="<?php echo $product['id']; ?>" data-value="<?php echo $stock; ?>">
                                                <span class="stock-badge <?php echo $stock_class; ?>"><?php echo $stock; ?> units</span>
                                            </span>
                                            <span class="editable-field" data-field="name" data-id="<?php echo $product['id']; ?>" data-value="<?php echo htmlspecialchars($product['name']); ?>" style="display: block; margin-bottom: 4px;">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </span>
                                            <?php if (!empty($product['description'])): ?>
                                                <div class="description-preview-trigger" onclick="editDescription(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['description'])); ?>')">
                                                    <i class="fas fa-align-left"></i> <span>description</span>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($product['name']); ?>
                                            <?php if (!empty($product['description'])): ?>
                                                <div class="description-preview-trigger" onclick="editDescription(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['description'])); ?>')">
                                                    <i class="fas fa-align-left"></i> <span>description</span>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                     </div>
                                     </td>
                                    <td class="product-category-cell">
                                        <?php if ($can_manage_inventory): ?>
                                            <span class="editable-field category-field" data-field="category" data-id="<?php echo $product['id']; ?>" data-value="<?php echo htmlspecialchars($product['category']); ?>">
                                                <?php if ($product['category']): ?>
                                                    <span class="category-badge"><?php echo htmlspecialchars($product['category']); ?></span>
                                                <?php else: ?>
                                                    <span class="category-badge uncategorized">Uncategorized</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <?php if ($product['category']): ?>
                                                <span class="category-badge"><?php echo htmlspecialchars($product['category']); ?></span>
                                            <?php else: ?>
                                                <span class="category-badge uncategorized">Uncategorized</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                     </div>
                                     </td>
                                    <td class="product-price-cell">
                                        <?php if ($can_edit_prices): ?>
                                            <span class="editable-field price-field" data-field="base_price" data-id="<?php echo $product['id']; ?>" data-value="<?php echo $product['base_price']; ?>">
                                                ₦<?php echo number_format($product['base_price'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="price-locked" onclick="showRequestModal('edit_prices', 'Edit prices')">
                                                ₦<?php echo number_format($product['base_price'], 2); ?>
                                                <i class="fas fa-lock"></i>
                                            </span>
                                        <?php endif; ?>
                                     </div>
                                     </td>
                                    <td class="product-stock-cell">
                                        <?php if ($can_manage_inventory): ?>
                                            <span class="editable-field stock-field" data-field="stock_quantity" data-id="<?php echo $product['id']; ?>" data-value="<?php echo $stock; ?>">
                                                <span class="stock-badge <?php echo $stock_class; ?>"><?php echo $stock; ?> units</span>
                                            </span>
                                            <button class="save-stock-btn" data-id="<?php echo $product['id']; ?>" style="display: none; margin-left: 8px; background: #28a745; color: white; border: none; padding: 2px 8px; border-radius: 4px; cursor: pointer;">Save</button>
                                        <?php else: ?>
                                            <span class="stock-badge <?php echo $stock_class; ?>"><?php echo $stock; ?> units</span>
                                        <?php endif; ?>
                                     </div>
                                     </td>
                                    <td class="product-status-cell">
                                        <span class="status-badge <?php echo $product['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $product['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                     </div>
                                     </td>
                                    <td class="action-cell">
                                        <?php if ($can_manage_inventory && $can_manage_products): ?>
                                            <button class="take-button" data-id="<?php echo $product['id']; ?>" onclick="takeChanges(<?php echo $product['id']; ?>)"><i class="fas fa-check"></i> Save All</button>
                                        <?php endif; ?>
                                        <?php if ($can_manage_products): ?>
                                            <button class="btn-icon btn-toggle" onclick="toggleProductStatus(<?php echo $product['id']; ?>, <?php echo $product['is_active']; ?>)" title="<?php echo $product['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="fas <?php echo $product['is_active'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                            </button>
                                        <?php endif; ?>
                                     </div>
                                     </td>
                                 </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                 <tr>
                                    <td colspan="8" class="empty-table">
                                        <i class="fas fa-box-open"></i>
                                        <p>No active products found</p>
                                        <small>Click "Add Product" to get started</small>
                                     </td>
                                 </tr>
                            <?php endif; ?>
                        </tbody>
                     </table>
                </div>
            </div>
        </div>
        
        <!-- RECENT ACTIVITY -->
        <div class="recent-section">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Recent Activity</h2>
            </div>
            <div class="activity-list">
                <?php if ($recent_activity && mysqli_num_rows($recent_activity) > 0): ?>
                    <?php while ($act = mysqli_fetch_assoc($recent_activity)): ?>
                        <div class="activity-item">
                            <span class="activity-time"><?php echo date('H:i', strtotime($act['created_at'])); ?></span>
                            <span class="activity-user"><?php echo htmlspecialchars($act['fullname']); ?></span>
                            <span class="activity-action"><?php echo htmlspecialchars($act['action']); ?></span>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-small">
                        <i class="fas fa-inbox"></i>
                        <p>No recent activity</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer-note">
            <i class="fas fa-shield-alt"></i> All changes are logged · Auto-refresh every <?php echo AUTO_REFRESH_SECONDS; ?>s · Sales Department Access
        </div>
    </div>

    <!-- Image Upload Modal -->
    <div class="modal" id="imageUploadModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-image"></i> Product Image</h2>
                <button class="modal-close" onclick="closeImageModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="imageProductId">
                <div class="current-image-preview">
                    <img id="currentImageDisplay" src="../../logo.jpeg" alt="Current product image">
                </div>
                <div class="image-upload-area" onclick="document.getElementById('imageUpload').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Click to upload new image</p>
                    <small>PNG, JPG, GIF, WEBP up to 2MB</small>
                </div>
                <input type="file" id="imageUpload" accept="image/*" style="display: none;">
                <div class="image-filename" id="selectedFileName"></div>
                <div class="image-actions">
                    <button class="btn-danger" id="deleteImageBtn" onclick="deleteProductImage()"><i class="fas fa-trash"></i> Delete</button>
                    <button class="btn-primary" id="uploadImageBtn" onclick="uploadProductImage()"><i class="fas fa-upload"></i> Upload</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div class="modal" id="addProductModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Product</h2>
                <button class="modal-close" onclick="closeModal('addProductModal')">&times;</button>
            </div>
            <form id="addProductForm" onsubmit="event.preventDefault(); submitAddProduct();">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Product Name <span class="required">*</span></label>
                        <input type="text" id="productName" required placeholder="e.g., Chocolate Croissant">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="productDescription" rows="3" placeholder="Product description..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Price (₦) <span class="required">*</span></label>
                            <input type="number" id="productPrice" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Initial Stock <span class="required">*</span></label>
                            <input type="number" id="productStock" min="0" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" id="productCategory" list="categoryOptions" placeholder="Select or type new">
                        <datalist id="categoryOptions">
                            <?php foreach ($categories_list as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addProductModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Description Edit Modal -->
    <div class="modal" id="descriptionEditModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-pen"></i> Edit Description</h2>
                <button class="modal-close" onclick="closeDescriptionModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editProductId">
                <div class="form-group">
                    <label for="editDescription">Product Description</label>
                    <textarea id="editDescription" class="description-edit-textarea" maxlength="1000" placeholder="Enter product description..."></textarea>
                    <div class="char-counter" id="charCounter">0/1000 characters</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDescriptionModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveDescription()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Target Edit Modal -->
    <div class="modal" id="targetEditModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-bullseye"></i> Edit Sales Target</h2>
                <button class="modal-close" onclick="closeModal('targetEditModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editTargetId">
                <div class="form-group">
                    <label for="editTargetValue">New Target Quantity</label>
                    <input type="number" id="editTargetValue" class="form-control" min="0" step="1" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('targetEditModal')">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveTargetBtn">Save Changes</button>
            </div>
        </div>
    </div>

    <script>
        // =====================================================
        // AUTO-REFRESH WITH SCROLL POSITION
        // =====================================================
        let refreshTimer = AUTO_REFRESH_SECONDS;
        let refreshInterval = null;
        
        function saveScrollPosition() {
            sessionStorage.setItem('salesScrollPos', window.scrollY);
        }
        
        function restoreScrollPosition() {
            const saved = sessionStorage.getItem('salesScrollPos');
            if (saved) {
                setTimeout(() => {
                    window.scrollTo(0, parseInt(saved));
                }, 100);
                sessionStorage.removeItem('salesScrollPos');
            }
        }
        
        function startAutoRefresh() {
            refreshInterval = setInterval(function() {
                refreshTimer--;
                const timerElement = document.getElementById('refreshTimer');
                if (timerElement) timerElement.textContent = refreshTimer;
                if (refreshTimer <= 0) {
                    refreshTimer = AUTO_REFRESH_SECONDS;
                    if (timerElement) timerElement.textContent = AUTO_REFRESH_SECONDS;
                    saveScrollPosition();
                    window.location.reload();
                }
            }, 1000);
        }
        
        // Session heartbeat - automatically redirect on expiry
        let sessionCheckInterval = null;
        let sessionExpired = false;
        
        function checkSessionStatus() {
            if (sessionExpired) return;
            
            fetch('../../auth.php?action=check_session', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => response.json())
            .then(data => {
                if (!data.valid && !sessionExpired) {
                    sessionExpired = true;
                    showToast('Your session has expired. Please log in again.', 'warning', 5000);
                    setTimeout(() => {
                        window.location.href = '../../login_signup.php?session=expired';
                    }, 3000);
                } else if (data.time_remaining && data.time_remaining < 60) {
                    showToast(`Session expires in ${data.time_remaining} seconds. Please save your work.`, 'warning', 10000);
                }
            })
            .catch(error => console.error('Session check error:', error));
        }
        
        function startSessionCheck() {
            if (sessionCheckInterval) clearInterval(sessionCheckInterval);
            sessionCheckInterval = setInterval(checkSessionStatus, 30000);
        }
        
        startSessionCheck();
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) checkSessionStatus();
        });
        
        // =====================================================
        // TOAST AND LOADING
        // =====================================================
        const toastContainer = document.getElementById('toastContainer');
        const loadingOverlay = document.getElementById('loadingOverlay');
        let changedProducts = new Set();
        let originalValues = {};
        let pendingChanges = {};
        let currentImageProductId = null;
        let currentRequestPermission = '';
        
        function showToast(message, type = 'info', duration = 3000) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${message}<span style="float:right; cursor:pointer;" onclick="this.parentElement.remove()">×</span>`;
            toastContainer.appendChild(toast);
            setTimeout(() => toast.remove(), duration);
        }
        
        function showLoading() { loadingOverlay.classList.add('show'); }
        function hideLoading() { loadingOverlay.classList.remove('show'); }
        function closeModal(modalId) { document.getElementById(modalId).classList.remove('show'); }
        
        function refreshAfterAction(message, type = 'success') {
            showToast(message, type);
            setTimeout(() => showLoading(), 1500);
            saveScrollPosition();
            setTimeout(() => window.location.reload(), 2000);
        }
        
        // =====================================================
        // BRANCH FILTER (HQ only)
        // =====================================================
        <?php if ($is_headquarters): ?>
        const branchFilterSelect = document.getElementById('branchFilterSelect');
        const applyBranchFilter = document.getElementById('applyBranchFilter');
        
        if (applyBranchFilter) {
            applyBranchFilter.addEventListener('click', function() {
                const branchId = branchFilterSelect.value;
                saveScrollPosition();
                window.location.href = window.location.pathname + '?branch=' + branchId;
            });
        }
        <?php endif; ?>
        
        // =====================================================
        // REQUEST MODAL
        // =====================================================
        function showRequestModal(permission, action) {
            currentRequestPermission = permission;
            document.getElementById('requestPermissionInfo').innerHTML = `
                <div class="request-info">
                    <i class="fas fa-info-circle"></i>
                    <p>You need <strong>${action}</strong> permission to access this feature.</p>
                </div>
            `;
            document.getElementById('requestModal').classList.add('show');
        }
        
        function submitRequest() {
            const reason = document.getElementById('requestReason').value.trim();
            if (!reason) { showToast('Please provide a reason', 'error'); return; }
            
            const formData = new FormData();
            formData.append('action', 'request_access');
            formData.append('permission', currentRequestPermission);
            formData.append('reason', reason);
            formData.append('csrf_token', CSRF_TOKEN);
            
            closeModal('requestModal');
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        showToast('Request submitted! An admin will review it.', 'success');
                        document.getElementById('requestReason').value = '';
                    } else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        document.getElementById('submitRequestBtn').addEventListener('click', submitRequest);
        
        // =====================================================
        // ADD PRODUCT
        // =====================================================
        function showAddProductModal() {
            <?php if (!$can_manage_products): ?>
            showRequestModal('manage_products', 'Add Product');
            return;
            <?php endif; ?>
            document.getElementById('addProductModal').classList.add('show');
        }
        
        function submitAddProduct() {
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('add_product', true);
            formData.append('name', document.getElementById('productName').value);
            formData.append('description', document.getElementById('productDescription').value);
            formData.append('base_price', document.getElementById('productPrice').value);
            formData.append('stock', document.getElementById('productStock').value);
            formData.append('category', document.getElementById('productCategory').value);
            formData.append('csrf_token', CSRF_TOKEN);
            <?php if ($is_headquarters): ?>
            formData.append('branch_id', document.getElementById('branchFilterSelect')?.value || SELECTED_BRANCH_ID);
            <?php endif; ?>
            
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) refreshAfterAction(data.message, 'success');
                    else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        // =====================================================
        // IMAGE FUNCTIONS
        // =====================================================
        function showImageUploadModal(productId, currentImage, hasImage) {
            <?php if (!$can_manage_inventory): ?>
            showRequestModal('manage_inventory', 'Upload product image');
            return;
            <?php endif; ?>
            currentImageProductId = productId;
            document.getElementById('imageProductId').value = productId;
            document.getElementById('currentImageDisplay').src = currentImage;
            document.getElementById('selectedFileName').innerHTML = '';
            document.getElementById('deleteImageBtn').disabled = !hasImage;
            document.getElementById('imageUploadModal').classList.add('show');
        }
        
        function closeImageModal() { document.getElementById('imageUploadModal').classList.remove('show'); }
        
        document.getElementById('imageUpload').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                document.getElementById('selectedFileName').innerHTML = `Selected: ${this.files[0].name}`;
                const reader = new FileReader();
                reader.onload = e => document.getElementById('currentImageDisplay').src = e.target.result;
                reader.readAsDataURL(this.files[0]);
            }
        });
        
        function uploadProductImage() {
            const fileInput = document.getElementById('imageUpload');
            const productId = document.getElementById('imageProductId').value;
            if (!fileInput.files || !fileInput.files[0]) { showToast('Select an image', 'error'); return; }
            
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('upload_image', true);
            formData.append('product_id', productId);
            formData.append('product_image', fileInput.files[0]);
            formData.append('csrf_token', CSRF_TOKEN);
            
            closeImageModal();
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) refreshAfterAction(data.message, 'success');
                    else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        function deleteProductImage() {
            const productId = document.getElementById('imageProductId').value;
            if (!confirm('Delete this image?')) return;
            
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('delete_image', true);
            formData.append('product_id', productId);
            formData.append('csrf_token', CSRF_TOKEN);
            
            closeImageModal();
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) refreshAfterAction(data.message, 'success');
                    else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        // =====================================================
        // PRODUCT STATUS TOGGLE
        // =====================================================
        function toggleProductStatus(productId, currentStatus) {
            <?php if (!$can_manage_products): ?>
            showRequestModal('manage_products', 'Change product status');
            return;
            <?php endif; ?>
            const action = currentStatus ? 'deactivate' : 'activate';
            if (!confirm(`Are you sure you want to ${action} this product?`)) return;
            
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('toggle_status', true);
            formData.append('product_id', productId);
            formData.append('status', currentStatus ? 0 : 1);
            formData.append('csrf_token', CSRF_TOKEN);
            
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) refreshAfterAction(data.message, 'success');
                    else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        // =====================================================
        // DESCRIPTION EDIT
        // =====================================================
        function editDescription(productId, currentDescription) {
            <?php if (!$can_manage_inventory): ?>
            showRequestModal('manage_inventory', 'Edit product description');
            return;
            <?php endif; ?>
            document.getElementById('editProductId').value = productId;
            document.getElementById('editDescription').value = currentDescription;
            updateCharCounter();
            document.getElementById('descriptionEditModal').classList.add('show');
        }
        
        function closeDescriptionModal() { document.getElementById('descriptionEditModal').classList.remove('show'); }
        
        function updateCharCounter() {
            const textarea = document.getElementById('editDescription');
            const counter = document.getElementById('charCounter');
            counter.textContent = `${textarea.value.length}/1000 characters`;
        }
        
        document.getElementById('editDescription').addEventListener('input', updateCharCounter);
        
        function saveDescription() {
            const productId = document.getElementById('editProductId').value;
            const description = document.getElementById('editDescription').value;
            
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('edit_description', true);
            formData.append('product_id', productId);
            formData.append('description', description);
            formData.append('csrf_token', CSRF_TOKEN);
            
            closeDescriptionModal();
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) refreshAfterAction(data.message, 'success');
                    else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        // =====================================================
        // STOCK UPDATE
        // =====================================================
        function updateStock(productId, newQuantity) {
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('update_stock', true);
            formData.append('product_id', productId);
            formData.append('quantity', newQuantity);
            formData.append('csrf_token', CSRF_TOKEN);
            <?php if ($is_headquarters): ?>
            formData.append('branch_id', document.getElementById('branchFilterSelect')?.value || SELECTED_BRANCH_ID);
            <?php endif; ?>
            
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) {
                        refreshAfterAction(data.message, 'success');
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        // =====================================================
        // TARGET EDIT
        // =====================================================
        function openTargetEditModal(targetId, productId, productName, currentTarget) {
            document.getElementById('editTargetId').value = targetId;
            document.getElementById('editProductId').value = productId;
            document.getElementById('editTargetValue').value = currentTarget;
            const modalTitle = document.querySelector('#targetEditModal .modal-header h2');
            if (modalTitle) modalTitle.innerHTML = `<i class="fas fa-bullseye"></i> Edit Target for ${escapeHtml(productName)}`;
            document.getElementById('targetEditModal').classList.add('show');
        }
        
        function saveTarget() {
            const targetId = document.getElementById('editTargetId').value;
            const productId = document.getElementById('editProductId').value;
            const newTarget = document.getElementById('editTargetValue').value;
            if ((!targetId || targetId == 0) && !productId) { showToast('Invalid product', 'error'); return; }
            if (newTarget === undefined || newTarget === '') return;
            
            const formData = new FormData();
            formData.append('ajax', true);
            formData.append('update_target', true);
            formData.append('target_id', targetId);
            formData.append('product_id', productId);
            formData.append('target_quantity', newTarget);
            formData.append('csrf_token', CSRF_TOKEN);
            <?php if ($is_headquarters): ?>
            formData.append('branch_id', document.getElementById('branchFilterSelect')?.value || SELECTED_BRANCH_ID);
            <?php endif; ?>
            
            showLoading();
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    hideLoading();
                    if (data.success) refreshAfterAction(data.message, 'success');
                    else showToast(data.message, 'error');
                })
                .catch(() => { hideLoading(); showToast('Network error', 'error'); });
        }
        
        document.getElementById('saveTargetBtn')?.addEventListener('click', saveTarget);
        
        // =====================================================
        // FILTER FUNCTIONS
        // =====================================================
        function filterPerformance() {
            const searchTerm = document.getElementById('perfSearch')?.value.toLowerCase() || '';
            const category = document.getElementById('perfCategoryFilter')?.value || '';
            const cards = document.querySelectorAll('.performance-card');
            cards.forEach(card => {
                const name = card.querySelector('h4')?.innerText.toLowerCase() || '';
                const cat = card.querySelector('.product-category')?.innerText || '';
                let show = true;
                if (searchTerm && !name.includes(searchTerm)) show = false;
                if (category && cat !== category) show = false;
                card.style.display = show ? '' : 'none';
            });
        }
        
        function filterInventory() {
            const searchTerm = document.getElementById('invSearch')?.value.toLowerCase() || '';
            const category = document.getElementById('invCategoryFilter')?.value || '';
            const rows = document.querySelectorAll('#productsTableBody .product-row');
            rows.forEach(row => {
                const nameCell = row.querySelector('.product-name-cell');
                const name = nameCell ? nameCell.innerText.toLowerCase() : '';
                const catCell = row.querySelector('.product-category-cell');
                const cat = catCell ? catCell.innerText.trim() : '';
                let show = true;
                if (searchTerm && !name.includes(searchTerm)) show = false;
                if (category && cat !== category) show = false;
                row.style.display = show ? '' : 'none';
            });
        }
        
        // =====================================================
        // TAKE CHANGES (BULK UPDATE)
        // =====================================================
        function takeChanges(productId) {
            if (!pendingChanges[productId] || Object.keys(pendingChanges[productId]).length === 0) {
                showToast('No changes to save', 'info');
                return;
            }
            
            const changes = pendingChanges[productId];
            let completed = 0;
            let total = Object.keys(changes).length;
            let allSuccessful = true;
            
            showLoading();
            
            for (let [field, value] of Object.entries(changes)) {
                if (field === 'stock_quantity') {
                    updateStock(productId, value);
                    completed++;
                    if (completed === total) {
                        setTimeout(() => {
                            hideLoading();
                            delete pendingChanges[productId];
                            changedProducts.delete(productId);
                            if (allSuccessful) refreshAfterAction(`Product #${productId} updated!`, 'success');
                            else showToast('Some changes failed', 'error');
                        }, 1000);
                    }
                } else {
                    const formData = new FormData();
                    formData.append('ajax', true);
                    formData.append('quick_update', true);
                    formData.append('product_id', productId);
                    formData.append('field', field);
                    formData.append('value', value);
                    formData.append('csrf_token', CSRF_TOKEN);
                    
                    fetch(window.location.href, { method: 'POST', body: formData })
                        .then(r => r.json())
                        .then(data => {
                            completed++;
                            if (!data.success) allSuccessful = false;
                            if (completed === total) {
                                hideLoading();
                                delete pendingChanges[productId];
                                changedProducts.delete(productId);
                                if (allSuccessful) refreshAfterAction(`Product #${productId} updated!`, 'success');
                                else showToast('Some changes failed', 'error');
                            }
                        })
                        .catch(() => { completed++; if (completed === total) hideLoading(); });
                }
            }
        }
        
        // =====================================================
        // INLINE EDITING
        // =====================================================
        function startEditing(element) {
            <?php if (!$can_manage_inventory && !$can_edit_prices): ?>
            showRequestModal('manage_inventory', 'Edit product details');
            return;
            <?php endif; ?>
            
            const field = element.dataset.field;
            const productId = element.dataset.id;
            const currentValue = element.dataset.value;
            
            if (element.classList.contains('editing-active')) return;
            element.classList.add('editing-active');
            
            let inputHtml = '';
            if (field === 'category') {
                const options = Array.from(document.querySelectorAll('#categoryOptions option')).map(opt => opt.value);
                let opts = '<option value="">Uncategorized</option>';
                options.forEach(cat => opts += `<option value="${cat}" ${cat === currentValue ? 'selected' : ''}>${cat}</option>`);
                inputHtml = `<select class="inline-edit-select">${opts}</select>`;
            } else if (field === 'base_price') {
                inputHtml = `<input type="number" class="inline-edit-input" value="${currentValue}" step="0.01" min="0">`;
            } else if (field === 'stock_quantity') {
                inputHtml = `<input type="number" class="inline-edit-input" value="${currentValue}" step="1" min="0">`;
            } else {
                inputHtml = `<input type="text" class="inline-edit-input" value="${currentValue.replace(/"/g, '&quot;')}">`;
            }
            
            originalValues[`${productId}_${field}`] = currentValue;
            element.innerHTML = inputHtml;
            
            const input = element.querySelector('input, select');
            if (input) {
                input.setAttribute('data-field', field);
                input.setAttribute('data-id', productId);
                input.focus();
                input.addEventListener('blur', () => finishEditing(element, input.value));
                input.addEventListener('keypress', e => { if (e.key === 'Enter') finishEditing(element, input.value); });
            }
            
            // Add save button for stock field
            if (field === 'stock_quantity') {
                const saveBtn = document.createElement('button');
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                saveBtn.className = 'save-stock-btn-inline';
                saveBtn.style.marginLeft = '8px';
                saveBtn.style.background = '#28a745';
                saveBtn.style.color = 'white';
                saveBtn.style.border = 'none';
                saveBtn.style.padding = '2px 8px';
                saveBtn.style.borderRadius = '4px';
                saveBtn.style.cursor = 'pointer';
                saveBtn.onclick = () => {
                    const newValue = input.value;
                    finishEditing(element, newValue);
                };
                element.appendChild(saveBtn);
            }
        }
        
        function finishEditing(element, newValue) {
            const field = element.dataset.field;
            const productId = element.dataset.id;
            const originalValue = originalValues[`${productId}_${field}`];
            
            if (newValue === undefined || newValue === null) {
                const input = element.querySelector('input, select');
                newValue = input ? input.value : originalValue;
            }
            
            element.dataset.value = newValue;
            
            // Update display
            if (field === 'category') {
                element.innerHTML = newValue ? `<span class="category-badge">${escapeHtml(newValue)}</span>` : `<span class="category-badge uncategorized">Uncategorized</span>`;
            } else if (field === 'base_price') {
                element.innerHTML = `₦${parseFloat(newValue).toFixed(2)}`;
            } else if (field === 'stock_quantity') {
                const stockQty = parseInt(newValue);
                const stockClass = stockQty === 0 ? 'stock-out' : (stockQty < LOW_STOCK_THRESHOLD ? 'stock-low' : 'stock-good');
                element.innerHTML = `<span class="stock-badge ${stockClass}">${stockQty} units</span>`;
                // Also update the stock value in the cell for the Save All button
                const stockCell = element.closest('tr').querySelector('.product-stock-cell');
                if (stockCell) {
                    stockCell.setAttribute('data-stock-value', stockQty);
                }
            } else if (field === 'name') {
                element.innerHTML = escapeHtml(newValue);
            } else {
                element.innerHTML = escapeHtml(newValue);
            }
            
            element.classList.remove('editing-active');
            
            if (newValue != originalValue) {
                changedProducts.add(productId);
                if (!pendingChanges[productId]) pendingChanges[productId] = {};
                pendingChanges[productId][field] = newValue;
                showToast(`${field} changed - click "Save All" to apply`, 'info', 2000);
            }
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
        }
        
        // =====================================================
        // EVENT LISTENERS
        // =====================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Attach editable field click handlers
            document.querySelectorAll('.editable-field').forEach(field => {
                field.addEventListener('click', e => { e.stopPropagation(); startEditing(field); });
            });
            
            restoreScrollPosition();
            document.addEventListener('keydown', e => { if (e.ctrlKey && e.key === 'Enter' && changedProducts.size > 0) changedProducts.forEach(id => takeChanges(id)); });
            
            const perfSearch = document.getElementById('perfSearch');
            if (perfSearch) perfSearch.addEventListener('input', filterPerformance);
            const perfCat = document.getElementById('perfCategoryFilter');
            if (perfCat) perfCat.addEventListener('change', filterPerformance);
            const invSearch = document.getElementById('invSearch');
            if (invSearch) invSearch.addEventListener('input', filterInventory);
            const invCat = document.getElementById('invCategoryFilter');
            if (invCat) invCat.addEventListener('change', filterInventory);
            
            document.querySelectorAll('.edit-target-icon').forEach(icon => {
                icon.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const statItem = this.closest('.stat-item');
                    const targetSpan = statItem.querySelector('.stat-value');
                    const productName = statItem.closest('.performance-card').querySelector('h4').innerText;
                    const currentTarget = targetSpan.innerText.replace(/,/g, '');
                    openTargetEditModal(this.dataset.targetId, this.dataset.productId, productName, currentTarget);
                });
            });
        });
        
        startAutoRefresh();
        
        // Make functions global
        window.showAddProductModal = showAddProductModal;
        window.closeModal = closeModal;
        window.submitAddProduct = submitAddProduct;
        window.toggleProductStatus = toggleProductStatus;
        window.takeChanges = takeChanges;
        window.showImageUploadModal = showImageUploadModal;
        window.closeImageModal = closeImageModal;
        window.uploadProductImage = uploadProductImage;
        window.deleteProductImage = deleteProductImage;
        window.editDescription = editDescription;
        window.closeDescriptionModal = closeDescriptionModal;
        window.saveDescription = saveDescription;
        window.showRequestModal = showRequestModal;
        window.updateStock = updateStock;
    </script>
</body>
</html>