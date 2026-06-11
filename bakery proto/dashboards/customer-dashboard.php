<?php
// customer-dashboard.php - Refactored with prepared statements
// Version: 5.3 - Removed branch-aware stock (global stock only)
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
if (!isset($_SESSION['user_agent'])) {
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
} elseif ($_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
    session_destroy();
    header('Location: /login_signup.php?error=security');
    exit;
}

require_once dirname(__DIR__) . '/conn.php';
require_once dirname(__DIR__) . '/includes/Auth.php';
require_once dirname(__DIR__) . '/includes/User.php';
require_once dirname(__DIR__) . '/includes/Helpers.php';
require_once dirname(__DIR__) . '/includes/DashboardRouter.php';
require_once dirname(__DIR__) . '/config/config_loader.php';

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

$user_type = $user['user_type'];
$fullname = $user['fullname'];
$user_id = $user['user_id'];
$email = $user['email'] ?? '';
$phone = $user['phone'] ?? '';
$username = $user['username'] ?? '';
$member_since = $user['created_at'] ?? '';
$formatted_member_since = !empty($member_since) ? date('M Y', strtotime($member_since)) : 'Recent';
$first_name = explode(' ', $fullname)[0];

$permissions = $userObj->getPermissionsFlattened();
$userRoles = $userObj->getRoles();

$wholesale_discount = isset($userData['wholesale_discount']) ? (float)$userData['wholesale_discount'] : setting('default_wholesale_discount', 15);
$vendor_multiplier = ($user_type === 'vendor') ? (100 - $wholesale_discount) / 100 : 1.0;

define('MIN_ITEMS_PER_PRODUCT', setting('min_items_per_product', 10));
define('MIN_TOTAL_ITEMS', setting('min_total_items', 30));
define('LOW_STOCK_THRESHOLD', setting('low_stock_threshold', 10));
define('CRITICAL_STOCK_THRESHOLD', setting('critical_stock_threshold', 5));
define('PRODUCT_IMAGES_PATH', 'images/products/');
define('FALLBACK_IMAGE', 'logo.jpeg');

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$db = Database::getInstance();

// ==================== DISCOUNT CACHING WITH REFRESH ====================
$user_role_codes = [];
foreach ($userRoles as $role) {
    if (isset($role['role_code'])) {
        $user_role_codes[] = $role['role_code'];
    }
}
if (empty($user_role_codes)) {
    $user_role_codes[] = ($user_type === 'customer') ? 'CUSTOMER' : 'VENDOR';
}

function getUserRoleDiscounts($userId, $userRoleCodes, $forceRefresh = false) {
    if (!$forceRefresh && isset($_SESSION['role_discounts']) && isset($_SESSION['role_discounts_timestamp'])) {
        if (time() - $_SESSION['role_discounts_timestamp'] < 300) {
            return $_SESSION['role_discounts'];
        }
    }
    
    $db = Database::getInstance();
    $discounts = [];
    if (empty($userRoleCodes)) {
        return $discounts;
    }

    $placeholders = implode(',', array_fill(0, count($userRoleCodes), '?'));
    $sql = "SELECT product_id, discount_percent 
            FROM product_role_discounts 
            WHERE role_code IN ($placeholders)
            ORDER BY discount_percent DESC";
    $rows = $db->preparedFetchAll($sql, str_repeat('s', count($userRoleCodes)), $userRoleCodes);
    foreach ($rows as $row) {
        $product_id = $row['product_id'];
        $discount = (float)$row['discount_percent'];
        if (!isset($discounts[$product_id]) || $discount > $discounts[$product_id]) {
            $discounts[$product_id] = $discount;
        }
    }
    
    $_SESSION['role_discounts'] = $discounts;
    $_SESSION['role_discounts_timestamp'] = time();
    return $discounts;
}

$roleDiscounts = getUserRoleDiscounts($user['id'], $user_role_codes, false);

function getDiscountedPrice($basePrice, $vendorMultiplier, $roleDiscountPercent) {
    $priceAfterVendor = $basePrice * $vendorMultiplier;
    if ($roleDiscountPercent > 0) {
        return $priceAfterVendor * (1 - $roleDiscountPercent / 100);
    }
    return $priceAfterVendor;
}

// ==================== UTILITY FUNCTIONS ====================
function getAllCategories() {
    $db = Database::getInstance();
    $sql = "SELECT DISTINCT category FROM products WHERE is_active = 1 AND category IS NOT NULL AND category != '' ORDER BY category";
    $rows = $db->preparedFetchAll($sql, '', []);
    $categories = [];
    foreach ($rows as $row) {
        $categories[] = $row['category'];
    }
    return $categories;
}

function getProductImage($product_id) {
    $image_path = PRODUCT_IMAGES_PATH . $product_id;
    $extensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.JPG', '.JPEG', '.PNG', '.WEBP'];
    
    foreach ($extensions as $ext) {
        if (file_exists($image_path . $ext) && is_file($image_path . $ext)) {
            return $image_path . $ext;
        }
    }
    return FALLBACK_IMAGE;
}

// GLOBAL stock checking - sum across all branches
function getProductStock($product_id) {
    $db = Database::getInstance();
    $row = $db->preparedFetchOne(
        "SELECT COALESCE(SUM(quantity), 0) as total FROM product_stock WHERE product_id = ?",
        'i', [$product_id]
    );
    return $row ? (int)$row['total'] : 0;
}

function getStockStatus($stock) {
    if ($stock <= 0) {
        return ['class' => 'out-of-stock', 'icon' => 'fa-times-circle', 'text' => 'Out of Stock'];
    } elseif ($stock <= CRITICAL_STOCK_THRESHOLD) {
        return ['class' => 'critical-stock', 'icon' => 'fa-exclamation-triangle', 'text' => 'Critical Stock - Only ' . $stock . ' left!'];
    } elseif ($stock <= LOW_STOCK_THRESHOLD) {
        return ['class' => 'low-stock', 'icon' => 'fa-exclamation-circle', 'text' => 'Low Stock - Only ' . $stock . ' left!'];
    } else {
        return ['class' => 'in-stock', 'icon' => 'fa-check-circle', 'text' => $stock . ' available'];
    }
}

function getPublicRolesForDropdown() {
    $db = Database::getInstance();
    $sql = "SELECT DISTINCT role_code, role_name, privilege_level, description 
            FROM roles 
            WHERE role_code IN ('CUSTOMER', 'VENDOR')
            ORDER BY privilege_level DESC, role_code ASC";
    $rows = $db->preparedFetchAll($sql, '', []);
    $roles = [];
    foreach ($rows as $row) {
        $display_name = $row['role_name'] . " (" . $row['role_code'] . ")";
        $roles[] = [
            'role_code' => $row['role_code'],
            'role_name' => $row['role_name'],
            'display_name' => $display_name,
            'description' => $row['description'] ?? '',
            'privilege_level' => $row['privilege_level']
        ];
    }
    return $roles;
}

function renderProductGrid($user_type, $vendor_multiplier, $roleDiscounts, $search = '', $categories = [], $sort = 'name_asc') {
    $db = Database::getInstance();
    $where = "WHERE p.is_active = 1";
    $params = [];
    $types = "";

    if (!empty($search)) {
        $where .= " AND (p.name LIKE ? OR p.description LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }

    if (!empty($categories)) {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $where .= " AND p.category IN ($placeholders)";
        foreach ($categories as $cat) {
            $params[] = $cat;
            $types .= "s";
        }
    }

    switch ($sort) {
        case 'price_asc': $order = "ORDER BY p.base_price ASC"; break;
        case 'price_desc': $order = "ORDER BY p.base_price DESC"; break;
        case 'name_desc': $order = "ORDER BY p.name DESC"; break;
        default: $order = "ORDER BY p.name ASC";
    }

    $sql = "SELECT p.id, p.name, p.description, p.base_price, p.category,
                   COALESCE(SUM(ps.quantity), 0) as stock_quantity
            FROM products p
            LEFT JOIN product_stock ps ON p.id = ps.product_id
            $where
            GROUP BY p.id
            $order";
    
    $rows = $db->preparedFetchAll($sql, $types, $params);

    $html = '<div class="product-grid">';
    if (!empty($rows)) {
        foreach ($rows as $row) {
            $base_price = $row['base_price'];
            $roleDiscount = isset($roleDiscounts[$row['id']]) ? $roleDiscounts[$row['id']] : 0;
            $price = getDiscountedPrice($base_price, $vendor_multiplier, $roleDiscount);
            $image_url = getProductImage($row['id']);
            $stock = (int)$row['stock_quantity'];
            $stock_status = getStockStatus($stock);
            
            $html .= '<div class="product-card" data-id="' . $row['id'] . '">';
            $html .= '<div class="product-image">';
            $html .= '<img src="' . $image_url . '" alt="' . htmlspecialchars($row['name']) . '" loading="lazy" onerror="this.onerror=null; this.src=\'' . FALLBACK_IMAGE . ';\'">';
            $html .= '<button class="quick-view-btn" data-id="' . $row['id'] . '"><i class="fas fa-eye"></i> Quick View</button>';
            $html .= '</div>';
            $html .= '<div class="product-info">';
            $html .= '<h3 class="product-name">' . htmlspecialchars($row['name']) . '</h3>';
            if (!empty($row['description'])) {
                $desc = strlen($row['description']) > 80 ? substr($row['description'], 0, 80) . '...' : $row['description'];
                $html .= '<p class="product-description">' . htmlspecialchars($desc) . '</p>';
            }
            
            $html .= '<div class="stock-badge ' . $stock_status['class'] . '">';
            $html .= '<i class="fas ' . $stock_status['icon'] . '"></i> ' . $stock_status['text'];
            $html .= '</div>';
            
            $html .= '<div class="product-price-row">';
            $html .= '<span class="product-price">₦' . number_format($price, 2) . '</span>';
            if ($user_type === 'vendor' && $roleDiscount == 0) {
                $html .= '<span class="original-price">₦' . number_format($base_price, 2) . '</span>';
            }
            if ($roleDiscount > 0) {
                $html .= '<span class="discount-badge">-' . $roleDiscount . '%</span>';
            }
            $html .= '</div>';
            
            $html .= '<div class="product-min-notice">';
            $html .= '<i class="fas fa-box"></i> Min. ' . MIN_ITEMS_PER_PRODUCT . ' items';
            if ($stock >= MIN_ITEMS_PER_PRODUCT) {
                $batches = floor($stock / MIN_ITEMS_PER_PRODUCT);
                $html .= ' · <span class="stock-ok">' . $batches . ' batch' . ($batches > 1 ? 'es' : '') . ' available</span>';
            }
            $html .= '</div>';
            
            if ($stock <= 0) {
                $html .= '<button class="add-to-cart disabled" disabled><i class="fas fa-cart-plus"></i> Out of Stock</button>';
            } elseif ($stock < MIN_ITEMS_PER_PRODUCT) {
                $html .= '<button class="add-to-cart disabled" disabled><i class="fas fa-cart-plus"></i> Insufficient Stock</button>';
            } else {
                $html .= '<button class="add-to-cart" data-id="' . $row['id'] . '"><i class="fas fa-cart-plus"></i> Add to cart</button>';
            }
            $html .= '</div></div>';
        }
    } else {
        $html .= '<p class="no-products">No products match your criteria.</p>';
    }
    $html .= '</div>';
    return $html;
}

function getUserRolesFormatted($userObj) {
    $roles = $userObj->getRoles();
    $formatted = [];
    foreach ($roles as $role) {
        $formatted[] = [
            'role_name' => $role['role_name'],
            'role_code' => $role['role_code'],
            'department' => $role['dept_name'] ?? null,
            'branch' => $role['branch_name'] ?? null,
            'display' => $role['role_name'] . ' (' . $role['role_code'] . ')'
        ];
    }
    return $formatted;
}

// ==================== AJAX HANDLERS ====================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false];

    function getCartItemPrice($product_id, $base_price) {
        global $vendor_multiplier, $roleDiscounts;
        $roleDiscount = isset($roleDiscounts[$product_id]) ? $roleDiscounts[$product_id] : 0;
        return getDiscountedPrice($base_price, $vendor_multiplier, $roleDiscount);
    }

    if ($_POST['action'] === 'refreshDiscounts') {
        $roleDiscounts = getUserRoleDiscounts($user['id'], $user_role_codes, true);
        $response['success'] = true;
        $response['message'] = 'Discounts refreshed';
        echo json_encode($response);
        exit;
    }

    switch ($_POST['action']) {
        case 'add':
        case 'update':
        case 'remove':
        case 'getCart':
            $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
            $quantity   = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

            if (in_array($_POST['action'], ['add', 'update']) && $product_id > 0) {
                $stock = getProductStock($product_id);
                $product_row = $db->preparedFetchOne("SELECT name FROM products WHERE id = ?", 'i', [$product_id]);
                
                if ($_POST['action'] === 'add') {
                    if ($stock <= 0) {
                        echo json_encode(['success' => false, 'error' => 'This product is out of stock']);
                        exit;
                    }
                    if ($stock < $quantity) {
                        echo json_encode(['success' => false, 'error' => 'Not enough stock available. Only ' . $stock . ' left.']);
                        exit;
                    }
                }
            }

            switch ($_POST['action']) {
                case 'add':
                    $current_qty = $_SESSION['cart'][$product_id] ?? 0;
                    $new_qty = $current_qty + $quantity;
                    $stock = getProductStock($product_id);

                    if ($new_qty > $stock) {
                        $response['error'] = 'Cannot add more than available stock (' . $stock . ')';
                    } elseif ($new_qty < MIN_ITEMS_PER_PRODUCT && $current_qty == 0) {
                        $response['error'] = 'Minimum ' . MIN_ITEMS_PER_PRODUCT . ' items required';
                        $response['min_items'] = MIN_ITEMS_PER_PRODUCT;
                    } else {
                        $_SESSION['cart'][$product_id] = $new_qty;
                        $response['success'] = true;
                        $response['message'] = 'Item added to cart';
                    }
                    break;

                case 'update':
                    if (isset($_SESSION['cart'][$product_id])) {
                        if ($quantity <= 0) {
                            unset($_SESSION['cart'][$product_id]);
                            $response['success'] = true;
                            $response['message'] = 'Item removed';
                        } else {
                            $stock = getProductStock($product_id);
                            if ($quantity > $stock) {
                                $response['error'] = 'Cannot add more than available stock (' . $stock . ')';
                            } elseif ($quantity < MIN_ITEMS_PER_PRODUCT) {
                                $response['error'] = 'Minimum ' . MIN_ITEMS_PER_PRODUCT . ' items required';
                                $response['min_items'] = MIN_ITEMS_PER_PRODUCT;
                            } else {
                                $_SESSION['cart'][$product_id] = $quantity;
                                $response['success'] = true;
                                $response['message'] = 'Cart updated';
                            }
                        }
                    }
                    break;

                case 'remove':
                    unset($_SESSION['cart'][$product_id]);
                    $response['success'] = true;
                    $response['message'] = 'Item removed';
                    break;

                case 'getCart':
                    $response['success'] = true;
                    break;
            }

            // Build cart data
            $cart_items = [];
            $cart_total = 0;
            if (!empty($_SESSION['cart'])) {
                $ids = array_keys($_SESSION['cart']);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = "SELECT id, name, base_price FROM products WHERE id IN ($placeholders) AND is_active = 1";
                $rows = $db->preparedFetchAll($sql, str_repeat('i', count($ids)), $ids);
                foreach ($rows as $row) {
                    $qty = $_SESSION['cart'][$row['id']];
                    $price = getCartItemPrice($row['id'], $row['base_price']);
                    $subtotal = $price * $qty;
                    $cart_total += $subtotal;
                    $cart_items[] = [
                        'id'       => $row['id'],
                        'name'     => htmlspecialchars($row['name']),
                        'price'    => $price,
                        'quantity' => $qty,
                        'subtotal' => $subtotal
                    ];
                }
            }

            $response['cartCount'] = array_sum($_SESSION['cart'] ?? []);
            $response['cartTotal'] = $cart_total;

            ob_start();
            if (!empty($cart_items)) {
                foreach ($cart_items as $item) {
                    echo '<div class="cart-item" data-id="' . $item['id'] . '">';
                    echo '<div class="cart-item-name">' . $item['name'] . '</div>';
                    echo '<div class="cart-item-price">₦' . number_format($item['price'], 2) . '</div>';
                    echo '<div class="cart-item-qty">';
                    echo '<button class="qty-decr">−</button>';
                    echo '<span>' . $item['quantity'] . '</span>';
                    echo '<button class="qty-incr">+</button>';
                    echo '<button class="remove-item"><i class="fas fa-trash"></i></button>';
                    echo '</div>';
                    if ($item['quantity'] < MIN_ITEMS_PER_PRODUCT) {
                        echo '<div class="cart-item-warning">⚠️ Min. ' . MIN_ITEMS_PER_PRODUCT . ' items required</div>';
                    }
                    echo '<div class="cart-item-subtotal">₦' . number_format($item['subtotal'], 2) . '</div>';
                    echo '</div>';
                }
            } else {
                echo '<p class="empty-cart">Your cart is empty.</p>';
            }
            $response['cartHtml'] = ob_get_clean();
            echo json_encode($response);
            exit;

        case 'validateCheckout':
            $total_items = array_sum($_SESSION['cart'] ?? []);
            $invalid_products = [];
            $out_of_stock = [];

            foreach ($_SESSION['cart'] as $product_id => $quantity) {
                $stock = getProductStock($product_id);
                $row = $db->preparedFetchOne("SELECT name FROM products WHERE id = ?", 'i', [$product_id]);
                if ($row) {
                    if ($stock < $quantity) {
                        $out_of_stock[] = $row['name'] . ' (need ' . $quantity . ', only ' . $stock . ' available)';
                    }
                    if ($quantity < MIN_ITEMS_PER_PRODUCT) {
                        $invalid_products[] = $row['name'] . ' (needs ' . MIN_ITEMS_PER_PRODUCT . ', has ' . $quantity . ')';
                    }
                }
            }

            if (!empty($out_of_stock)) {
                $response['success'] = false;
                $response['error'] = 'out_of_stock';
                $response['message'] = 'Some products are out of stock:<br>• ' . implode('<br>• ', $out_of_stock);
            } elseif ($total_items < MIN_TOTAL_ITEMS) {
                $response['success'] = false;
                $response['error'] = 'checkout_total';
                $response['message'] = 'You need at least ' . MIN_TOTAL_ITEMS . ' items. Current: ' . $total_items;
            } elseif (!empty($invalid_products)) {
                $response['success'] = false;
                $response['error'] = 'checkout_minimums';
                $response['message'] = 'Some products need more items:<br>• ' . implode('<br>• ', $invalid_products);
            } else {
                $response['success'] = true;
                $response['message'] = 'Ready to checkout';
            }
            echo json_encode($response);
            exit;

        case 'filter':
            $search = $_POST['search'] ?? '';
            $categories = isset($_POST['categories']) ? json_decode($_POST['categories']) : [];
            $sort = $_POST['sort'] ?? 'name_asc';
            $gridHtml = renderProductGrid($user_type, $vendor_multiplier, $roleDiscounts, $search, $categories, $sort);
            $response['success'] = true;
            $response['gridHtml'] = $gridHtml;
            echo json_encode($response);
            exit;

        case 'quickview':
            $product_id = (int)($_POST['product_id'] ?? 0);
            if ($product_id > 0) {
                $sql = "SELECT id, name, description, base_price, category 
                        FROM products WHERE id = ? AND is_active = 1";
                $product = $db->preparedFetchOne($sql, 'i', [$product_id]);
                if ($product) {
                    $stock = getProductStock($product_id);
                    $response['success'] = true;
                    $base_price = $product['base_price'];
                    $roleDiscount = isset($roleDiscounts[$product_id]) ? $roleDiscounts[$product_id] : 0;
                    $final_price = getDiscountedPrice($base_price, $vendor_multiplier, $roleDiscount);
                    $product['final_price'] = $final_price;
                    $product['formatted_price'] = '₦' . number_format($final_price, 2);
                    $product['formatted_base'] = '₦' . number_format($base_price, 2);
                    $product['image'] = getProductImage($product['id']);
                    $product['cart_quantity'] = $_SESSION['cart'][$product_id] ?? 0;
                    $product['min_items'] = MIN_ITEMS_PER_PRODUCT;
                    $product['stock'] = $stock;
                    $product['stock_status'] = getStockStatus($stock);
                    $product['discount_percent'] = $roleDiscount;
                    $response['product'] = $product;
                }
            }
            echo json_encode($response);
            exit;

        case 'getRoles':
            $roles = getPublicRolesForDropdown();
            $response['success'] = true;
            $response['roles'] = $roles;
            echo json_encode($response);
            exit;

        case 'getUserRoles':
            $userRolesFormatted = getUserRolesFormatted($userObj);
            $response['success'] = true;
            $response['roles'] = $userRolesFormatted;
            $response['permissions'] = $permissions;
            echo json_encode($response);
            exit;
    }
    exit;
}

// ==================== INITIAL DATA FOR PAGE ====================
$categories = getAllCategories();
$initialGrid = renderProductGrid($user_type, $vendor_multiplier, $roleDiscounts);
$availableRoles = getPublicRolesForDropdown();
$userRolesFormatted = getUserRolesFormatted($userObj);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fingerchops Ventures · <?php echo ucfirst($user_type); ?> Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/customer-dashboard.css">
    <link rel="icon" href="logo.jpeg" type="image/jpeg">
</head>
<body>
    <!-- Quantity Selection Modal -->
    <div class="modal quantity-modal" id="quantityModal" style="display: none;">
        <div class="modal-content">
            <span class="close-quantity-modal" id="closeQuantityModal">&times;</span>
            <div class="quantity-modal-header">
                <img src="logo.jpeg" alt="Fingerchops" class="quantity-modal-logo">
                <h3>Select Quantity</h3>
            </div>
            <div class="quantity-modal-body">
                <div class="quantity-modal-product" id="quantityModalProduct">
                    <div class="product-info-compact">
                        <h4 id="quantityModalProductName">Product Name</h4>
                        <span class="modal-product-price" id="quantityModalProductPrice">₦0.00</span>
                    </div>
                    <div class="minimum-notice-compact">
                        <i class="fas fa-info-circle"></i> Min. <span id="quantityModalMinItems">0</span> items
                    </div>
                </div>
                <div class="quantity-modal-input">
                    <label>Quantity:</label>
                    <div class="quantity-controls-compact">
                        <button class="quantity-btn" id="decrementQty">-</button>
                        <input type="number" id="modalQuantityInput" class="modal-quantity-input" value="1" min="1">
                        <button class="quantity-btn" id="incrementQty">+</button>
                    </div>
                </div>
                <div class="quantity-modal-actions">
                    <button class="cancel-quantity-btn" id="cancelQuantityBtn">Cancel</button>
                    <button class="add-quantity-btn" id="confirmQuantityBtn">Add to Cart</button>
                </div>
            </div>
        </div>
    </div>

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
                    <img src="logo.jpeg" alt="Fingerchops Ventures" class="logo-img" onerror="this.src='logo.jpeg'">
                    <span>Fingerchops Ventures</span>
                </div>
            </div>
            <div class="header-actions">
                <span class="user-greeting">👋 <?php echo htmlspecialchars($first_name); ?></span>
                <button class="notification-button" id="notificationToggle" type="button" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <span class="notification-dot"></span>
                </button>
                <button class="cart-button" id="cartToggle">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="cart-count" id="cartCount"><?php echo array_sum($_SESSION['cart'] ?? []); ?></span>
                </button>
            </div>
        </header>

        <aside class="profile-sidebar" id="profileSidebar">
            <div class="profile-header">
                <div class="profile-close">
                    <button id="closeProfile"><i class="fas fa-times"></i></button>
                </div>
                <div class="profile-avatar">
                    <img src="logo.jpeg" alt="Profile" onerror="this.src='logo.jpeg'">
                </div>
                <h3><?php echo htmlspecialchars($fullname); ?></h3>
                <p class="profile-type">
                    <?php echo ucfirst($user_type); ?> · Member since <?php echo $formatted_member_since; ?>
                </p>
                <?php if (!empty($email)): ?>
                    <p class="profile-contact"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($email); ?></p>
                <?php endif; ?>
                <?php if (!empty($phone)): ?>
                    <p class="profile-contact"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone); ?></p>
                <?php endif; ?>
            </div>
            <div class="profile-menu">
                <a href="customer-dashboard.php" class="profile-menu-item active"><i class="fas fa-home"></i><span>Dashboard</span></a>
                <a href="customer-actions/orders.php" class="profile-menu-item"><i class="fas fa-shopping-bag"></i><span>My Orders</span></a>
                <a href="customer-actions/wishlist.php" class="profile-menu-item"><i class="fas fa-heart"></i><span>Wishlist</span></a>
                <a href="customer-actions/addresses.php" class="profile-menu-item"><i class="fas fa-map-marker-alt"></i><span>Delivery Addresses</span></a>
                <a href="customer-actions/payments.php" class="profile-menu-item"><i class="fas fa-credit-card"></i><span>Payment Methods</span></a>
                <a href="customer-actions/settings.php" class="profile-menu-item"><i class="fas fa-cog"></i><span>Account Settings</span></a>
                <a href="customer-actions/support.php" class="profile-menu-item"><i class="fas fa-headset"></i><span>Help & Support</span></a>
                <a href="logout.php" class="profile-menu-item logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
            <div class="profile-footer">
                <p>Fingerchops Ventures © 2026</p>
                <p class="app-version">v4.0.0</p>
            </div>
        </aside>

        <div class="sticky-top">
            <div class="search-bar">
                <i class="fas fa-search search-icon" id="searchButton"></i>
                <input type="text" id="productSearch" placeholder="Search for croissants, bread, cakes..." autocomplete="off">
                <button class="search-btn" id="searchButtonText">Search</button>
            </div>

            <div class="requirements-notice">
                <i class="fas fa-info-circle"></i> 
                <span>Minimum <?php echo MIN_ITEMS_PER_PRODUCT; ?> items per product</span>
                <span>Minimum <?php echo MIN_TOTAL_ITEMS; ?> items total for checkout</span>
            </div>
        </div>

        <main class="main-content">
            <div class="filter-bar">
                <div class="category-filters">
                    <span>Filter by category:</span>
                    <?php foreach ($categories as $cat): ?>
                        <label class="category-checkbox">
                            <input type="checkbox" class="category-filter" value="<?php echo htmlspecialchars($cat); ?>"> <?php echo htmlspecialchars($cat); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="sort-dropdown">
                    <label for="sortSelect">Sort by:</label>
                    <select id="sortSelect">
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                        <option value="price_asc">Price (Low to High)</option>
                        <option value="price_desc">Price (High to Low)</option>
                    </select>
                </div>
                <div class="filter-buttons">
                    <button class="apply-filters-btn" id="applyFilters">Apply Filters</button>
                    <button class="reset-filters-btn" id="resetFilters"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </div>

            <div id="productGridContainer">
                <?php echo $initialGrid; ?>
            </div>
        </main>

        <aside class="cart-sidebar" id="cartSidebar">
            <div class="cart-header">
                <h3>Your Cart <span class="minimum-badge">Min. <?php echo MIN_TOTAL_ITEMS; ?> items</span></h3>
                <button id="closeCart"><i class="fas fa-times"></i></button>
            </div>
            <div class="cart-items" id="cartItems">
                <p class="empty-cart">Your cart is empty.</p>
            </div>
            <div class="cart-footer">
                <div class="cart-total">
                    <span>Total:</span>
                    <strong id="cartTotal">₦0.00</strong>
                </div>
                <button class="checkout-btn" id="checkoutBtn">Proceed to Checkout</button>
            </div>
        </aside>

        <div class="modal" id="quickViewModal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; z-index: 1001; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
            <span class="close-modal" style="position: absolute; top: 10px; right: 15px; cursor: pointer; font-size: 1.5rem;">&times;</span>
            <div class="modal-body" id="quickViewBody"></div>
        </div>

        <div class="modal" id="roleSelectionModal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; z-index: 1001; max-width: 400px; width: 90%;">
            <span class="close-role-modal" style="position: absolute; top: 10px; right: 15px; cursor: pointer; font-size: 1.5rem;">&times;</span>
            <h3>Select Role</h3>
            <select id="dynamicRoleSelect" class="role-select">
                <option value="">Loading roles...</option>
            </select>
            <div id="roleDescription" style="margin-top: 10px; font-size: 0.85rem; color: #666;"></div>
            <button id="confirmRoleSelect" style="margin-top: 15px; padding: 8px 16px; background: #d4a373; color: white; border: none; border-radius: 5px; cursor: pointer;">Confirm</button>
        </div>

        <div class="overlay" id="overlay"></div>
    </div>

    <script>
        // Data passed from PHP
        const availableRoles = <?php echo json_encode($availableRoles); ?>;
        const userRoles = <?php echo json_encode($userRolesFormatted); ?>;
        const userPermissions = <?php echo json_encode($permissions); ?>;
        const MIN_ITEMS_PER_PRODUCT = <?php echo MIN_ITEMS_PER_PRODUCT; ?>;
        const MIN_TOTAL_ITEMS = <?php echo MIN_TOTAL_ITEMS; ?>;

        // DOM Elements
        const profileSidebar = document.getElementById('profileSidebar');
        const profileMenuBtn = document.getElementById('profileMenuBtn');
        const closeProfile = document.getElementById('closeProfile');
        const cartSidebar = document.getElementById('cartSidebar');
        const cartToggle = document.getElementById('cartToggle');
        const closeCart = document.getElementById('closeCart');
        const overlay = document.getElementById('overlay');
        const productGridContainer = document.getElementById('productGridContainer');
        const searchInput = document.getElementById('productSearch');
        const searchButton = document.getElementById('searchButton');
        const searchButtonText = document.getElementById('searchButtonText');
        const sortSelect = document.getElementById('sortSelect');
        const applyFilters = document.getElementById('applyFilters');
        const resetFilters = document.getElementById('resetFilters');
        const quickViewModal = document.getElementById('quickViewModal');
        const closeModal = document.querySelector('.close-modal');
        const cartItemsDiv = document.getElementById('cartItems');
        const cartCountSpan = document.getElementById('cartCount');
        const cartTotalSpan = document.getElementById('cartTotal');
        const checkoutBtn = document.getElementById('checkoutBtn');
        const roleSelectionModal = document.getElementById('roleSelectionModal');
        const dynamicRoleSelect = document.getElementById('dynamicRoleSelect');
        const roleDescriptionDiv = document.getElementById('roleDescription');
        const confirmRoleSelect = document.getElementById('confirmRoleSelect');
        const closeRoleModal = document.querySelector('.close-role-modal');
        
        // Quantity modal controls
        const quantityModal = document.getElementById('quantityModal');
        const qtyInput = document.getElementById('modalQuantityInput');
        const decrementBtn = document.getElementById('decrementQty');
        const incrementBtn = document.getElementById('incrementQty');
        const confirmBtn = document.getElementById('confirmQuantityBtn');
        const cancelBtn = document.getElementById('cancelQuantityBtn');
        const closeQuantityModal = document.getElementById('closeQuantityModal');

        function closeQuantityModalFunc() {
            quantityModal.style.display = 'none';
            overlay.classList.remove('active');
        }

        if (decrementBtn) {
            decrementBtn.addEventListener('click', () => {
                let val = parseInt(qtyInput.value) || 1;
                const min = parseInt(qtyInput.min) || 1;
                if (val > min) {
                    qtyInput.value = val - 1;
                }
            });
        }
        if (incrementBtn) {
            incrementBtn.addEventListener('click', () => {
                let val = parseInt(qtyInput.value) || 1;
                const max = parseInt(qtyInput.max) || 999;
                if (val < max) {
                    qtyInput.value = val + 1;
                }
            });
        }
        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                const productId = quantityModal.getAttribute('data-product-id');
                const quantity = parseInt(qtyInput.value);
                const min = parseInt(quantityModal.getAttribute('data-min-items')) || 1;
                if (quantity >= min) {
                    addItemToCart(productId, quantity);
                    closeQuantityModalFunc();
                } else {
                    showToast(`Minimum ${min} items required`, 'warning');
                }
            });
        }
        if (cancelBtn) cancelBtn.addEventListener('click', closeQuantityModalFunc);
        if (closeQuantityModal) closeQuantityModal.addEventListener('click', closeQuantityModalFunc);

        const toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);

        function showToast(message, type = 'info', duration = 3000) {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${message}`;
            toastContainer.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        function populateRoleDropdown(roles) {
            if (!dynamicRoleSelect) return;
            dynamicRoleSelect.innerHTML = '<option value="">Select a role...</option>';
            roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.role_code;
                option.textContent = role.display_name;
                option.setAttribute('data-description', role.description || '');
                option.setAttribute('data-privilege', role.privilege_level);
                dynamicRoleSelect.appendChild(option);
            });
        }

        function updateRoleDescription() {
            const selectedOption = dynamicRoleSelect.options[dynamicRoleSelect.selectedIndex];
            const description = selectedOption ? selectedOption.getAttribute('data-description') : '';
            if (description) {
                roleDescriptionDiv.innerHTML = `<i class="fas fa-info-circle"></i> ${description}`;
            } else {
                roleDescriptionDiv.innerHTML = '';
            }
        }

        function fetchRolesFromServer() {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=getRoles'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.roles) {
                    populateRoleDropdown(data.roles);
                }
            })
            .catch(error => console.error('Error fetching roles:', error));
        }

        function openRoleSelectionModal() {
            if (availableRoles.length === 0) {
                fetchRolesFromServer();
            } else {
                populateRoleDropdown(availableRoles);
            }
            roleSelectionModal.style.display = 'block';
            overlay.classList.add('active');
        }

        function closeRoleSelectionModal() {
            roleSelectionModal.style.display = 'none';
            overlay.classList.remove('active');
        }

        let sessionCheckInterval = null;

        function checkSessionStatus() {
            fetch('../auth.php?action=check_session', {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(response => response.json())
            .then(data => {
                if (!data.valid) {
                    showToast('Your session has expired. Please log in again.', 'warning', 5000);
                    setTimeout(() => window.location.href = '../login_signup.php?session=expired', 3000);
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

        profileMenuBtn.addEventListener('click', () => { profileSidebar.classList.add('open'); overlay.classList.add('active'); });
        closeProfile.addEventListener('click', () => { profileSidebar.classList.remove('open'); overlay.classList.remove('active'); });
        cartToggle.addEventListener('click', () => { cartSidebar.classList.add('open'); overlay.classList.add('active'); updateCartUI(); });
        closeCart.addEventListener('click', () => { cartSidebar.classList.remove('open'); overlay.classList.remove('active'); });
        overlay.addEventListener('click', () => {
            profileSidebar.classList.remove('open');
            cartSidebar.classList.remove('open');
            quickViewModal.style.display = 'none';
            roleSelectionModal.style.display = 'none';
            quantityModal.style.display = 'none';
            overlay.classList.remove('active');
        });

        function fetchFilteredProducts() {
            const search = searchInput.value;
            const categories = Array.from(document.querySelectorAll('.category-filter:checked')).map(cb => cb.value);
            const sort = sortSelect.value;

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=filter&search=${encodeURIComponent(search)}&categories=${encodeURIComponent(JSON.stringify(categories))}&sort=${sort}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    productGridContainer.innerHTML = data.gridHtml;
                    attachProductEventListeners();
                    showToast('Filters applied successfully!', 'success');
                }
            })
            .catch(error => { console.error('Error:', error); showToast('Error applying filters', 'error'); });
        }

        function resetAllFilters() {
            searchInput.value = '';
            document.querySelectorAll('.category-filter:checked').forEach(cb => cb.checked = false);
            sortSelect.value = 'name_asc';
            fetchFilteredProducts();
        }

        function handleAddToCart(e) {
            e.preventDefault();
            const btn = e.currentTarget;
            const productId = btn.dataset.id;
            const productCard = btn.closest('.product-card');
            const productName = productCard.querySelector('.product-name').textContent;
            const productPrice = productCard.querySelector('.product-price').textContent;
            const minItems = MIN_ITEMS_PER_PRODUCT;
            
            let currentQty = 0;
            const cartItem = document.querySelector(`.cart-item[data-id="${productId}"]`);
            if (cartItem) {
                const qtySpan = cartItem.querySelector('.cart-item-qty span');
                if (qtySpan) currentQty = parseInt(qtySpan.textContent);
            }
            
            if (currentQty === 0) {
                document.getElementById('quantityModalProductName').textContent = productName;
                document.getElementById('quantityModalProductPrice').textContent = productPrice;
                document.getElementById('quantityModalMinItems').textContent = minItems;
                qtyInput.value = minItems;
                qtyInput.min = minItems;
                
                const stockText = productCard.querySelector('.stock-badge')?.textContent;
                let maxStock = 999;
                const match = stockText?.match(/(\d+)\s+available/);
                if (match) maxStock = parseInt(match[1]);
                qtyInput.max = maxStock;
                
                quantityModal.setAttribute('data-product-id', productId);
                quantityModal.setAttribute('data-min-items', minItems);
                quantityModal.setAttribute('data-max-stock', maxStock);
                
                quantityModal.style.display = 'block';
                overlay.classList.add('active');
                qtyInput.focus();
                qtyInput.select();
            } else {
                addItemToCart(productId, 1);
            }
        }

        function addItemToCart(productId, quantity) {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=add&product_id=${productId}&quantity=${quantity}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    cartCountSpan.textContent = data.cartCount;
                    showToast(data.message || 'Item added to cart!', 'success');
                    updateCartUI();
                } else if (data.error && data.min_items) {
                    showToast(data.error, 'error');
                } else {
                    showToast(data.error || 'Error adding item to cart', 'error');
                }
            })
            .catch(error => { console.error('Error:', error); showToast('Error adding item to cart', 'error'); });
        }

        function handleQuickView(e) {
            e.preventDefault();
            const btn = e.currentTarget;
            const productId = btn.dataset.id;

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=quickview&product_id=${productId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const p = data.product;
                    document.getElementById('quickViewBody').innerHTML = `
                        <div class="quick-view-product">
                            <img src="${p.image}" alt="${p.name}" onerror="this.onerror=null; this.src='logo.jpeg';">
                            <h2>${p.name}</h2>
                            <p class="category">${p.category}</p>
                            <p class="description">${p.description || 'No description'}</p>
                            <div class="stock-info">
                                <span class="stock-badge ${p.stock_status.class}">
                                    <i class="fas ${p.stock_status.icon}"></i> ${p.stock_status.text}
                                </span>
                            </div>
                            <div class="price-block">
                                <span class="current-price">${p.formatted_price}</span>
                                ${p.base_price !== p.final_price ? `<span class="old-price">${p.formatted_base}</span>` : ''}
                                ${p.discount_percent > 0 ? `<span class="discount-badge">-${p.discount_percent}%</span>` : ''}
                            </div>
                            <div class="minimum-notice">
                                <i class="fas fa-info-circle"></i> Minimum order: ${p.min_items} items
                            </div>
                            ${p.cart_quantity > 0 ? `<p class="in-cart">In cart: <strong>${p.cart_quantity}</strong></p>` : ''}
                            ${p.stock > 0 && p.stock >= p.min_items ? `
                            <div class="quantity-selector">
                                <label for="qvQty">Quantity (min. ${p.min_items}):</label>
                                <input type="number" id="qvQty" value="${p.min_items}" min="${p.min_items}" max="${p.stock}">
                            </div>
                            <button class="add-to-cart-modal" data-id="${p.id}">Add to Cart</button>
                            ` : '<button class="add-to-cart-modal disabled" disabled>Out of Stock</button>'}
                        </div>
                    `;
                    quickViewModal.style.display = 'block';
                    overlay.classList.add('active');

                    document.querySelector('.add-to-cart-modal:not(.disabled)')?.addEventListener('click', function() {
                        const qty = document.getElementById('qvQty').value;
                        fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=add&product_id=${p.id}&quantity=${qty}`
                        })
                        .then(res => res.json())
                        .then(data2 => {
                            if (data2.success) {
                                cartCountSpan.textContent = data2.cartCount;
                                showToast('Item added to cart!', 'success');
                                quickViewModal.style.display = 'none';
                                overlay.classList.remove('active');
                                updateCartUI();
                            } else {
                                showToast(data2.error || 'Error adding item', 'error');
                            }
                        });
                    });
                }
            })
            .catch(error => { console.error('Error:', error); showToast('Error loading product details', 'error'); });
        }

        function updateCartUI() {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=getCart'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    cartCountSpan.textContent = data.cartCount;
                    cartTotalSpan.textContent = '₦' + (data.cartTotal || 0).toFixed(2);
                    cartItemsDiv.innerHTML = data.cartHtml || '<p class="empty-cart">Your cart is empty.</p>';
                    attachCartItemEvents();
                    validateCartForCheckout();
                }
            })
            .catch(error => { console.error('Error:', error); showToast('Error updating cart', 'error'); });
        }

        function validateCartForCheckout() {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=validateCheckout'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    checkoutBtn.disabled = false;
                    checkoutBtn.style.opacity = '1';
                    checkoutBtn.style.cursor = 'pointer';
                } else {
                    checkoutBtn.disabled = true;
                    checkoutBtn.style.opacity = '0.6';
                    checkoutBtn.style.cursor = 'not-allowed';
                    checkoutBtn.title = data.message.replace(/<br>/g, ' ');
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function attachCartItemEvents() {
            document.querySelectorAll('.qty-incr').forEach(btn => {
                btn.removeEventListener('click', handleIncrement);
                btn.addEventListener('click', handleIncrement);
            });
            document.querySelectorAll('.qty-decr').forEach(btn => {
                btn.removeEventListener('click', handleDecrement);
                btn.addEventListener('click', handleDecrement);
            });
            document.querySelectorAll('.remove-item').forEach(btn => {
                btn.removeEventListener('click', handleRemove);
                btn.addEventListener('click', handleRemove);
            });
        }

        function handleIncrement(e) {
            const item = e.target.closest('.cart-item');
            if (!item) return;
            const id = item.dataset.id;
            const qtySpan = item.querySelector('.cart-item-qty span');
            let qty = parseInt(qtySpan.textContent) + 1;
            updateCartItem(id, qty);
        }

        function handleDecrement(e) {
            const item = e.target.closest('.cart-item');
            if (!item) return;
            const id = item.dataset.id;
            const qtySpan = item.querySelector('.cart-item-qty span');
            let qty = parseInt(qtySpan.textContent) - 1;
            if (qty < 1) qty = 0;
            updateCartItem(id, qty);
        }

        function handleRemove(e) {
            const item = e.target.closest('.cart-item');
            if (!item) return;
            const id = item.dataset.id;
            updateCartItem(id, 0);
        }

        function updateCartItem(productId, quantity) {
            let action = 'update';
            if (quantity === 0) action = 'remove';

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=${action}&product_id=${productId}&quantity=${quantity}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    cartCountSpan.textContent = data.cartCount;
                    cartTotalSpan.textContent = '₦' + (data.cartTotal || 0).toFixed(2);
                    cartItemsDiv.innerHTML = data.cartHtml || '<p class="empty-cart">Your cart is empty.</p>';
                    attachCartItemEvents();
                    if (data.message) showToast(data.message, 'success');
                    validateCartForCheckout();
                } else if (data.error) {
                    showToast(data.error, 'error');
                    updateCartUI();
                }
            })
            .catch(error => { console.error('Error:', error); showToast('Error updating cart', 'error'); });
        }

        function attachProductEventListeners() {
            document.querySelectorAll('.add-to-cart:not(.disabled)').forEach(btn => {
                btn.removeEventListener('click', handleAddToCart);
                btn.addEventListener('click', handleAddToCart);
            });

            document.querySelectorAll('.quick-view-btn').forEach(btn => {
                btn.removeEventListener('click', handleQuickView);
                btn.addEventListener('click', handleQuickView);
            });
        }

        searchButton.addEventListener('click', fetchFilteredProducts);
        searchButtonText.addEventListener('click', fetchFilteredProducts);
        searchInput.addEventListener('keypress', (e) => { if (e.key === 'Enter') fetchFilteredProducts(); });
        applyFilters.addEventListener('click', fetchFilteredProducts);
        resetFilters.addEventListener('click', resetAllFilters);

        if (closeModal) closeModal.addEventListener('click', () => { quickViewModal.style.display = 'none'; overlay.classList.remove('active'); });
        if (closeRoleModal) closeRoleModal.addEventListener('click', closeRoleSelectionModal);
        if (dynamicRoleSelect) dynamicRoleSelect.addEventListener('change', updateRoleDescription);
        if (confirmRoleSelect) confirmRoleSelect.addEventListener('click', () => {
            const selectedRole = dynamicRoleSelect.value;
            if (selectedRole) {
                const selectedOption = dynamicRoleSelect.options[dynamicRoleSelect.selectedIndex];
                const roleDisplayName = selectedOption.textContent;
                showToast(`Selected: ${roleDisplayName}`, 'success');
                closeRoleSelectionModal();
            } else {
                showToast('Please select a role', 'warning');
            }
        });

        checkoutBtn.addEventListener('click', () => {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=validateCheckout'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'customer-actions/checkout.php';
                } else {
                    showToast(data.message, 'error', 5000);
                    cartSidebar.classList.add('open');
                    overlay.classList.add('active');
                    updateCartUI();
                }
            })
            .catch(error => { console.error('Error:', error); showToast('Error validating cart', 'error'); });
        });

        attachProductEventListeners();
        updateCartUI();

        window.addEventListener('load', function() {
            setTimeout(function() { 
                const preloader = document.getElementById('preloader');
                if (preloader) preloader.classList.add('fade-out'); 
            }, 500);
        });
    </script>
</body>
</html>