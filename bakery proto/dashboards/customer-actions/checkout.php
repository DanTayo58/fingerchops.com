<?php
// customer-actions/checkout.php - Checkout with location-based multipliers
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
    header('Location: ../../login_signup.php?error=security');
    exit;
}

require_once dirname(__DIR__, 2) . '/conn.php';
require_once dirname(__DIR__, 2) . '/includes/Auth.php';
require_once dirname(__DIR__, 2) . '/includes/User.php';
require_once dirname(__DIR__, 2) . '/includes/Helpers.php';
require_once dirname(__DIR__, 2) . '/includes/DashboardRouter.php';
require_once dirname(__DIR__, 2) . '/config/config_loader.php';

$auth = new Auth();
if (!$auth->validateSession()) {
    header('Location: ../../login_signup.php');
    exit;
}
$user = $auth->getCurrentUser();
if (!$user) {
    $auth->logout();
    header('Location: ../../login_signup.php');
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

$fullname = $user['fullname'];
$first_name = explode(' ', $fullname)[0];
$email = $user['email'] ?? '';
$phone = $user['phone'] ?? '';
$user_address = $user['address'] ?? '';
$user_default_branch = $user['default_branch_id'] ?? null;

$db = Database::getInstance();

// Fetch fuel price data
$fuel = $db->preparedFetchOne("SELECT price, shared_percentage FROM fuel_price ORDER BY id DESC LIMIT 1", '', []);
if (!$fuel) {
    $fuel = ['price' => 1000.00, 'shared_percentage' => 5.00];
}
$fuel_price = (float)$fuel['price'];
$fuel_shared_percent = (float)$fuel['shared_percentage'];

// Fetch states for dropdown (from locations table)
$states = $db->preparedFetchAll("SELECT DISTINCT state FROM locations ORDER BY state", '', []);

// Fetch bank accounts
$bank_accounts = $db->preparedFetchAll(
    "SELECT id, bank_name, account_name, account_number, account_type, is_default 
     FROM account_details 
     WHERE is_active = 1 
     ORDER BY is_default DESC, id ASC",
    '', []
);

// Get selected account (default)
$selected_account = null;
foreach ($bank_accounts as $acc) {
    if ($acc['is_default']) {
        $selected_account = $acc;
        break;
    }
}
if (!$selected_account && !empty($bank_accounts)) {
    $selected_account = $bank_accounts[0];
}

// PERMANENT CSRF TOKEN - Use session-based token
if (!isset($_SESSION['user_csrf_token'])) {
    $_SESSION['user_csrf_token'] = generateSecureToken(64);
}
$csrf_token = $_SESSION['user_csrf_token'];

// ==================== AJAX: GET CITIES ====================
if (isset($_GET['action']) && $_GET['action'] === 'get_cities') {
    header('Content-Type: application/json');
    $state = isset($_GET['state']) ? trim($_GET['state']) : '';
    if (empty($state)) {
        echo json_encode(['success' => false, 'message' => 'State required']);
        exit;
    }
    $cities = $db->preparedFetchAll("SELECT id, city FROM locations WHERE state = ? ORDER BY city", 's', [$state]);
    echo json_encode(['success' => true, 'cities' => $cities]);
    exit;
}

// ==================== AJAX: GET DELIVERY FEE ====================
if (isset($_GET['action']) && $_GET['action'] === 'get_delivery_fee') {
    header('Content-Type: application/json');
    
    $token = $_GET['csrf_token'] ?? '';
    if ($token !== $_SESSION['user_csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }
    
    $location_id = isset($_GET['location_id']) ? (int)$_GET['location_id'] : 0;
    
    if ($location_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid location']);
        exit;
    }

    // Find branch that serves this location
    $branch = $db->preparedFetchOne("
        SELECT b.id, b.branch_code, bsm.multiplier 
        FROM branches b
        JOIN branch_state_mappings bsm ON b.id = bsm.branch_id
        WHERE bsm.location_id = ? AND b.is_active = 1
        ORDER BY bsm.multiplier ASC
        LIMIT 1
    ", 'i', [$location_id]);

    // If no location-specific mapping found, try state-level mapping
    if (!$branch && $location) {
        $branch = $db->preparedFetchOne("
            SELECT b.id, b.branch_code, bsm.multiplier 
            FROM branches b
            JOIN branch_state_mappings bsm ON b.id = bsm.branch_id
            WHERE bsm.state = ? AND bsm.location_id IS NULL AND b.is_active = 1
            ORDER BY bsm.multiplier ASC
            LIMIT 1
        ", 's', [$location['state']]);
    }
    
    if (!$branch && !$location) {
        echo json_encode(['success' => false, 'message' => 'No branch serves this location yet. Please contact support.']);
        exit;
    }
    $branch_id = $branch['id'];
    $branch_multiplier = (float)$branch['multiplier'];
    
    // Determine if local delivery (customer's default branch matches delivery branch)
    $is_local = ($user_default_branch == $branch_id);
    
    // Calculate multiplier based on local/non-local
    if ($is_local) {
        $applied_multiplier = max($branch_multiplier / 2, 1);
    } else {
        $applied_multiplier = $branch_multiplier;
    }

    $cart = $_SESSION['cart'] ?? [];
    if (empty($cart)) {
        echo json_encode(['success' => false, 'message' => 'Cart is empty']);
        exit;
    }

    // Calculate base delivery = Σ (quantity × product.delivery_rate)
    $base_delivery = 0;
    $errors = [];

    foreach ($cart as $product_id => $quantity) {
        $product = $db->preparedFetchOne("SELECT id, name, delivery_rate FROM products WHERE id = ? AND is_active = 1", 'i', [$product_id]);
        if (!$product) {
            $errors[] = "Product ID $product_id no longer exists.";
            continue;
        }
        $base_delivery += $quantity * (float)$product['delivery_rate'];
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        exit;
    }

    // Fuel component: Fuel Price × (Shared Percentage / 100)
    $fuel_component = $fuel_price * ($fuel_shared_percent / 100);
    
    // Intermediate = Base Delivery + Fuel Component
    $intermediate = $base_delivery + $fuel_component;
    
    // Final delivery = Intermediate × Applied Multiplier
    $final_delivery = $intermediate * $applied_multiplier;

    echo json_encode([
        'success' => true,
        'delivery_fee' => $final_delivery,
        'branch_id' => $branch_id,
        'branch_multiplier' => $branch_multiplier,
        'applied_multiplier' => $applied_multiplier,
        'is_local' => $is_local,
        'base_delivery' => $base_delivery,
        'fuel_component' => $fuel_component,
        'intermediate' => $intermediate
    ]);
    exit;
}

// ==================== ORDER PLACEMENT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    header('Content-Type: application/json');
    
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== $_SESSION['user_csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    
    $cart = $_SESSION['cart'] ?? [];
    if (empty($cart)) {
        echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
        exit;
    }
    
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $delivery_state = trim($_POST['delivery_state'] ?? '');
    $delivery_city = trim($_POST['delivery_city'] ?? '');
    $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : 0;
    $delivery_phone = trim($_POST['delivery_phone'] ?? '');
    $payment_method = trim($_POST['payment_method'] ?? '');
    $delivery_branch_id = isset($_POST['delivery_branch']) ? (int)$_POST['delivery_branch'] : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($delivery_address) || empty($delivery_state) || empty($delivery_city) || empty($delivery_phone) || empty($payment_method) || !$delivery_branch_id) {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields.']);
        exit;
    }
    
    // Get branch details and multiplier for this location
    $branch_data = $db->preparedFetchOne("
        SELECT b.id, b.branch_code, bsm.multiplier 
        FROM branches b
        JOIN branch_state_mappings bsm ON b.id = bsm.branch_id
        WHERE b.id = ? AND bsm.location_id = ?
    ", 'ii', [$delivery_branch_id, $location_id]);
    
    if (!$branch_data) {
        echo json_encode(['success' => false, 'message' => 'Invalid delivery branch or location.']);
        exit;
    }
    $branch_multiplier = (float)$branch_data['multiplier'];
    
    // Determine if local delivery
    $is_local = ($user_default_branch == $delivery_branch_id);
    
    if ($is_local) {
        $applied_multiplier = max($branch_multiplier / 2, 1);
    } else {
        $applied_multiplier = $branch_multiplier;
    }
    
    // Calculate order items and delivery components
    $order_items = [];
    $items_total = 0;
    $base_delivery = 0;
    $errors = [];
    
    $roleDiscounts = $_SESSION['role_discounts'] ?? [];
    $vendor_multiplier = ($user['user_type'] === 'vendor') ? (100 - (float)($userData['wholesale_discount'] ?? setting('default_wholesale_discount', 15))) / 100 : 1.0;
    
    foreach ($cart as $product_id => $quantity) {
        $product = $db->preparedFetchOne("SELECT id, name, base_price, delivery_rate FROM products WHERE id = ? AND is_active = 1", 'i', [$product_id]);
        if (!$product) {
            $errors[] = "Product ID $product_id no longer exists.";
            continue;
        }
        
        // Calculate discounted price
        $unit_price = (float)$product['base_price'];
        $roleDiscount = isset($roleDiscounts[$product_id]) ? (float)$roleDiscounts[$product_id] : 0;
        
        // Apply vendor discount
        $priceAfterVendor = $unit_price * $vendor_multiplier;
        // Apply role discount
        if ($roleDiscount > 0) {
            $final_price = $priceAfterVendor * (1 - $roleDiscount / 100);
        } else {
            $final_price = $priceAfterVendor;
        }
        
        $subtotal = $final_price * $quantity;
        $items_total += $subtotal;
        $base_delivery += $quantity * (float)$product['delivery_rate'];
        
        $order_items[] = [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'unit_price' => $final_price,
            'subtotal' => $subtotal,
            'name' => $product['name']
        ];
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode('<br>', $errors)]);
        exit;
    }
    
    // Delivery calculation
    $fuel_component = $fuel_price * ($fuel_shared_percent / 100);
    $intermediate = $base_delivery + $fuel_component;
    $delivery_fee = $intermediate * $applied_multiplier;
    $total_amount = $items_total + $delivery_fee;
    
    // Generate order number - simple and reliable
    $order_number = 'ORD-' . time() . '-' . $user['id'] . '-' . rand(100, 999);
    
    $db->beginTransaction();
    
    try {
        // INSERT INTO customer_orders - NO receipt_file column
        $sql = "INSERT INTO customer_orders (
            user_id, order_number, total_amount, delivery_fee, base_delivery_fee, 
            fuel_surcharge, branch_multiplier, branch_multiplier_applied, 
            status, payment_method, notes, 
            delivery_address, delivery_state, delivery_city, delivery_phone, 
            delivery_branch_id, location_id, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, 
            ?, ?, ?, 
            'pending', ?, ?, 
            ?, ?, ?, ?, 
            ?, ?, NOW()
        )";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->getConnection()->error);
        }

        $types = "isddddddssssssii";

        $stmt->bind_param(
            $types,
            $user['id'],
            $order_number,
            $total_amount,
            $delivery_fee,
            $base_delivery,
            $fuel_component,
            $branch_multiplier,
            $applied_multiplier,
            $payment_method,
            $notes,
            $delivery_address,
            $delivery_state,
            $delivery_city,
            $delivery_phone,
            $delivery_branch_id,
            $location_id
        );
        
        $success = $stmt->execute();
        
        if (!$success) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $order_id = $db->lastInsertId();
        $stmt->close();
        
        // Handle bank transfer receipt - save using order_id as filename ONLY, no database column
        if ($payment_method === 'bank_transfer' && isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $receipt_dir = dirname(__DIR__) . '/images/receipts/';
            if (!is_dir($receipt_dir)) {
                mkdir($receipt_dir, 0755, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (in_array($ext, $allowed)) {
                $filename = $order_id . '.' . $ext;
                $destination = $receipt_dir . $filename;
                move_uploaded_file($_FILES['receipt']['tmp_name'], $destination);
                // NO DATABASE UPDATE - file is identified by order_id
            }
        }
        
        // Insert order items
        foreach ($order_items as $item) {
            $item_sql = "INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal)
                         VALUES (?, ?, ?, ?, ?)";
            $item_stmt = $db->prepare($item_sql);
            
            if (!$item_stmt) {
                throw new Exception("Prepare item failed: " . $db->getConnection()->error);
            }
            
            $item_types = "iiidd";
            $item_stmt->bind_param(
                $item_types,
                $order_id,
                $item['product_id'],
                $item['quantity'],
                $item['unit_price'],
                $item['subtotal']
            );
            
            if (!$item_stmt->execute()) {
                throw new Exception("Execute item failed: " . $item_stmt->error);
            }
            $item_stmt->close();
        }
        
        // Clear cart
        $_SESSION['cart'] = [];
        
        // Log activity
        logActivity($user['id'], "Placed order #{$order_number}", 'order', $order_id);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'order_id' => $order_id, 'order_number' => $order_number, 'redirect' => 'orders.php']);
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Order placement error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to create order: ' . $e->getMessage()]);
        exit;
    }
}

// ==================== DISPLAY CHECKOUT FORM ====================

// Get most frequent address from user's order history
$most_frequent_address = $db->preparedFetchOne(
    "SELECT delivery_address, delivery_city, delivery_state, delivery_phone 
     FROM customer_orders 
     WHERE user_id = ? AND delivery_address IS NOT NULL AND delivery_address != ''
     GROUP BY delivery_address, delivery_city, delivery_state, delivery_phone
     ORDER BY COUNT(*) DESC 
     LIMIT 1",
    'i', [$user['id']]
);

// Get cart items for display
$cart = $_SESSION['cart'] ?? [];
$cart_items = [];
$items_total = 0;
$roleDiscounts = $_SESSION['role_discounts'] ?? [];
$vendor_multiplier = ($user['user_type'] === 'vendor') ? (100 - (float)($userData['wholesale_discount'] ?? setting('default_wholesale_discount', 15))) / 100 : 1.0;

function displayPrice($base_price, $vendor_multiplier, $roleDiscount) {
    $priceAfterVendor = $base_price * $vendor_multiplier;
    if ($roleDiscount > 0) {
        return $priceAfterVendor * (1 - $roleDiscount / 100);
    }
    return $priceAfterVendor;
}

foreach ($cart as $product_id => $quantity) {
    $product = $db->preparedFetchOne("SELECT id, name, base_price FROM products WHERE id = ? AND is_active = 1", 'i', [$product_id]);
    if ($product) {
        $unit_price = (float)$product['base_price'];
        $roleDiscount = isset($roleDiscounts[$product_id]) ? $roleDiscounts[$product_id] : 0;
        $final_price = displayPrice($unit_price, $vendor_multiplier, $roleDiscount);
        $subtotal = $final_price * $quantity;
        $items_total += $subtotal;
        $cart_items[] = [
            'name' => $product['name'],
            'quantity' => $quantity,
            'unit_price' => $final_price,
            'subtotal' => $subtotal
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fingerchops Ventures · Checkout</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/customer-dashboard.css">
    <link rel="stylesheet" href="css/checkout.css">
    <link rel="icon" href="../logo.jpeg" type="image/jpeg">
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
                    <?php echo ucfirst($user['user_type']); ?>
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
                <a href="orders.php" class="profile-menu-item"><i class="fas fa-shopping-bag"></i><span>My Orders</span></a>
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

        <div class="checkout-container">
            <div class="checkout-wrapper">
                <!-- LEFT COLUMN - FORM SECTION (Scrollable) -->
                <div class="checkout-form-section">
                    <div class="checkout-header">
                        <h2><i class="fas fa-credit-card"></i> Checkout</h2>
                        <p>Complete your order details below</p>
                    </div>
                    
                    <form id="checkoutForm" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="place_order">
                        <input type="hidden" name="delivery_branch" id="delivery_branch_id" value="0">
                        <input type="hidden" name="location_id" id="location_id" value="0">
                        <input type="hidden" name="account_id" id="account_id" value="<?php echo $selected_account['id'] ?? 0; ?>">
                        
                        <?php if ($most_frequent_address && $most_frequent_address['delivery_address']): ?>
                            <div class="saved-address-notice">
                                <i class="fas fa-history"></i> 
                                Using your most frequently used address:
                                <div class="saved-address-preview">
                                    <strong><?php echo htmlspecialchars($most_frequent_address['delivery_address']); ?></strong>
                                    <?php if ($most_frequent_address['delivery_city'] || $most_frequent_address['delivery_state']): ?>
                                        , <?php echo htmlspecialchars($most_frequent_address['delivery_city']); ?>
                                        <?php if ($most_frequent_address['delivery_state']): ?>, <?php echo htmlspecialchars($most_frequent_address['delivery_state']); ?><?php endif; ?>
                                    <?php endif; ?>
                                    <br><small>Phone: <?php echo htmlspecialchars($most_frequent_address['delivery_phone']); ?></small>
                                </div>
                                <label class="checkbox-container">
                                    <input type="checkbox" id="useSavedAddress"> Use this address
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="delivery_state">State <span class="required">*</span></label>
                            <select id="delivery_state" name="delivery_state" required>
                                <option value="">-- Select State --</option>
                                <?php foreach ($states as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s['state']); ?>" <?php echo ($most_frequent_address['delivery_state'] ?? '') == $s['state'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['state']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_city">City <span class="required">*</span></label>
                            <select id="delivery_city" name="delivery_city" required>
                                <option value="">-- Select City --</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_address">Full Delivery Address <span class="required">*</span></label>
                            <textarea id="delivery_address" name="delivery_address" rows="3" required><?php echo htmlspecialchars($most_frequent_address['delivery_address'] ?? $user_address); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="delivery_phone">Phone Number <span class="required">*</span></label>
                            <input type="tel" id="delivery_phone" name="delivery_phone" value="<?php echo htmlspecialchars($most_frequent_address['delivery_phone'] ?? $phone); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="payment_method">Payment Method <span class="required">*</span></label>
                            <select id="payment_method" name="payment_method" required>
                                <option value="">Select payment method</option>
                                <option value="cash_on_delivery">Cash on Delivery</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        
                        <!-- Bank Transfer Details (shown when bank transfer selected) -->
                        <div id="bankDetails" class="bank-details">
                            <?php if (!empty($bank_accounts)): ?>
                                <?php if (count($bank_accounts) > 1): ?>
                                    <div class="bank-account-selector">
                                        <label>Select Account:</label>
                                        <select id="bank_account_select">
                                            <?php foreach ($bank_accounts as $acc): ?>
                                                <option value="<?php echo $acc['id']; ?>" data-name="<?php echo htmlspecialchars($acc['account_name']); ?>" data-number="<?php echo htmlspecialchars($acc['account_number']); ?>" data-bank="<?php echo htmlspecialchars($acc['bank_name']); ?>" <?php echo $acc['is_default'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($acc['bank_name']); ?> - <?php echo htmlspecialchars($acc['account_number']); ?> (<?php echo htmlspecialchars($acc['account_name']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                                
                                <div id="bank_info_display">
                                    <p><strong>Bank:</strong> <span id="bank_name"><?php echo htmlspecialchars($selected_account['bank_name'] ?? ''); ?></span></p>
                                    <p><strong>Account Name:</strong> <span id="account_name"><?php echo htmlspecialchars($selected_account['account_name'] ?? ''); ?></span></p>
                                    <p><strong>Account Number:</strong> <span id="account_number"><?php echo htmlspecialchars($selected_account['account_number'] ?? ''); ?></span></p>
                                </div>
                            <?php else: ?>
                                <div class="error-message">No bank accounts configured. Please contact support.</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Order Notes (optional)</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="Special instructions, delivery time, etc."></textarea>
                        </div>
                        
                        <button type="submit" class="btn-checkout" id="placeOrderBtn">Place Order</button>
                    </form>
                    <a href="../customer-dashboard.php" class="back-link">← Back to Cart</a>
                </div>
                
                <!-- RIGHT COLUMN - ORDER SUMMARY (Sticky) -->
                <div class="checkout-summary-section">
                    <div class="order-summary">
                        <h3><i class="fas fa-shopping-cart"></i> Order Summary</h3>
                        
                        <div class="cart-items-preview">
                            <?php foreach ($cart_items as $item): ?>
                                <div class="cart-preview-item">
                                    <span><?php echo htmlspecialchars($item['name']); ?> x <?php echo $item['quantity']; ?></span>
                                    <span>₦<?php echo number_format($item['subtotal'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="summary-item">
                            <span>Subtotal:</span>
                            <span class="subtotal-amount" id="subtotal_amount">₦<?php echo number_format($items_total, 2); ?></span>
                        </div>
                        <div class="summary-item delivery-fee-item" id="deliveryFeeItem" style="display: none;">
                            <span>Delivery Fee:</span>
                            <span id="delivery_fee_display">₦0.00</span>
                        </div>
                        <div class="summary-total">
                            <span>Total:</span>
                            <strong id="total_amount">₦<?php echo number_format($items_total, 2); ?></strong>
                        </div>
                        
                        <div class="delivery-fee-note" id="deliveryFeeNote" style="display: flex;">
                            <i class="fas fa-info-circle"></i>
                            <span>Select your location to calculate delivery fee</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Confirmation Modal -->
    <div id="paymentModal" class="payment-modal">
        <div class="payment-modal-content">
            <i class="fas fa-university" style="font-size: 3rem; color: #7c3aed;"></i>
            <h3>Bank Transfer Payment</h3>
            <p>Please transfer the exact amount to the account below:</p>
            
            <div id="modalBankInfo" class="bank-info-box">
                <p><strong>Bank:</strong> <span id="modal_bank_name"><?php echo htmlspecialchars($selected_account['bank_name'] ?? ''); ?></span></p>
                <p><strong>Account Name:</strong> <span id="modal_account_name"><?php echo htmlspecialchars($selected_account['account_name'] ?? ''); ?></span></p>
                <p><strong>Account Number:</strong> <span id="modal_account_number"><?php echo htmlspecialchars($selected_account['account_number'] ?? ''); ?></span></p>
            </div>
            
            <div class="amount-display">
                Total Amount: <strong id="modal_total_amount">₦<?php echo number_format($items_total, 2); ?></strong>
            </div>
            
            <div class="receipt-upload-modal" onclick="document.getElementById('modalReceipt').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #7c3aed;"></i>
                <p>Click to upload transfer receipt</p>
                <small>JPG, PNG, PDF (Max 5MB)</small>
                <input type="file" id="modalReceipt" name="receipt" accept="image/*,.pdf" style="display: none;">
                <div id="modalReceiptPreview" class="modal-receipt-preview"></div>
            </div>
            
            <div class="modal-buttons">
                <button class="btn-confirm" onclick="confirmPayment()">Confirm Payment</button>
                <button class="btn-cancel" onclick="closePaymentModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>

    <script>
        // Sidebar functionality
        const profileSidebar = document.getElementById('profileSidebar');
        const profileMenuBtn = document.getElementById('profileMenuBtn');
        const closeProfile = document.getElementById('closeProfile');
        const overlay = document.getElementById('overlay');
        const form = document.getElementById('checkoutForm');
        const submitBtn = document.getElementById('placeOrderBtn');
        const stateSelect = document.getElementById('delivery_state');
        const citySelect = document.getElementById('delivery_city');
        const deliveryFeeItem = document.getElementById('deliveryFeeItem');
        const deliveryFeeSpan = document.getElementById('delivery_fee_display');
        const subtotalSpan = document.getElementById('subtotal_amount');
        const totalAmountSpan = document.getElementById('total_amount');
        const modalTotalAmount = document.getElementById('modal_total_amount');
        const deliveryFeeNote = document.getElementById('deliveryFeeNote');
        const paymentMethodSelect = document.getElementById('payment_method');
        const bankDetailsDiv = document.getElementById('bankDetails');
        const bankAccountSelect = document.getElementById('bank_account_select');
        const accountIdField = document.getElementById('account_id');
        const useSavedAddressCheckbox = document.getElementById('useSavedAddress');
        const deliveryAddressField = document.getElementById('delivery_address');
        const deliveryCityField = document.getElementById('delivery_city');
        const deliveryStateField = document.getElementById('delivery_state');
        const deliveryPhoneField = document.getElementById('delivery_phone');
        const csrfToken = document.getElementById('csrf_token').value;
        const locationIdField = document.getElementById('location_id');
        
        // Payment modal elements
        const paymentModal = document.getElementById('paymentModal');
        const modalReceiptInput = document.getElementById('modalReceipt');
        const modalReceiptPreview = document.getElementById('modalReceiptPreview');
        
        let currentItemsTotal = <?php echo $items_total; ?>;
        let pendingModalReceipt = null;
        let currentCities = [];
        
        // Ensure toast container exists
        function getToastContainer() {
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            return container;
        }
        
        // Toast function - fixed to not take over page
        function showToast(message, type = 'info', duration = 3000) {
            const container = getToastContainer();
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            let icon = 'fa-info-circle';
            if (type === 'success') icon = 'fa-check-circle';
            if (type === 'error') icon = 'fa-exclamation-circle';
            if (type === 'warning') icon = 'fa-exclamation-triangle';
            
            toast.innerHTML = `<i class="fas ${icon}"></i> ${message}`;
            container.appendChild(toast);
            
            // Auto-remove after duration
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }
        
        // Saved address data
        const savedAddress = {
            address: '<?php echo addslashes($most_frequent_address['delivery_address'] ?? ''); ?>',
            city: '<?php echo addslashes($most_frequent_address['delivery_city'] ?? ''); ?>',
            state: '<?php echo addslashes($most_frequent_address['delivery_state'] ?? ''); ?>',
            phone: '<?php echo addslashes($most_frequent_address['delivery_phone'] ?? $phone); ?>'
        };
        
        if (useSavedAddressCheckbox) {
            useSavedAddressCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    deliveryAddressField.value = savedAddress.address;
                    deliveryCityField.value = savedAddress.city;
                    deliveryStateField.value = savedAddress.state;
                    deliveryPhoneField.value = savedAddress.phone;
                    loadCities();
                    updateDeliveryFee();
                } else {
                    deliveryAddressField.value = '';
                    deliveryCityField.value = '';
                    deliveryStateField.value = '';
                    deliveryPhoneField.value = '<?php echo addslashes($phone); ?>';
                    loadCities();
                    updateDeliveryFee();
                }
            });
        }
        
        profileMenuBtn.addEventListener('click', () => { profileSidebar.classList.add('open'); overlay.classList.add('active'); });
        closeProfile.addEventListener('click', () => { profileSidebar.classList.remove('open'); overlay.classList.remove('active'); });
        overlay.addEventListener('click', () => { profileSidebar.classList.remove('open'); overlay.classList.remove('active'); });
        
        // Populate cities when state changes
        async function loadCities() {
            const state = stateSelect.value;
            citySelect.innerHTML = '<option value="">-- Select City --</option>';
            if (!state) return;
            try {
                const response = await fetch(`checkout.php?action=get_cities&state=${encodeURIComponent(state)}&t=${Date.now()}`);
                const data = await response.json();
                if (data.success && data.cities.length) {
                    currentCities = data.cities;
                    data.cities.forEach(city => {
                        const option = document.createElement('option');
                        option.value = city.id;
                        option.textContent = city.city;
                        if (savedAddress.city === city.city) option.selected = true;
                        citySelect.appendChild(option);
                    });
                } else {
                    citySelect.innerHTML = '<option value="">No cities available</option>';
                }
            } catch (error) {
                console.error('Error loading cities:', error);
                showToast('Could not load cities for this state', 'error');
            }
        }
        
        async function updateDeliveryFee() {
            const cityOption = citySelect.options[citySelect.selectedIndex];
            const locationId = cityOption ? cityOption.value : 0;
            const cityName = cityOption ? cityOption.text : '';
            
            if (!stateSelect.value || !locationId || locationId <= 0) {
                deliveryFeeItem.style.display = 'none';
                deliveryFeeNote.style.display = 'flex';
                totalAmountSpan.textContent = '₦' + currentItemsTotal.toFixed(2);
                if (modalTotalAmount) modalTotalAmount.textContent = '₦' + currentItemsTotal.toFixed(2);
                locationIdField.value = 0;
                return;
            }
            
            deliveryFeeNote.style.display = 'none';
            deliveryFeeItem.style.display = 'flex';
            deliveryFeeSpan.textContent = 'Calculating...';
            locationIdField.value = locationId;
            
            try {
                const response = await fetch(`checkout.php?action=get_delivery_fee&location_id=${locationId}&csrf_token=${encodeURIComponent(csrfToken)}&t=${Date.now()}`);
                const data = await response.json();
                
                if (data.success) {
                    const deliveryFee = parseFloat(data.delivery_fee);
                    deliveryFeeSpan.textContent = '₦' + deliveryFee.toFixed(2);
                    
                    const totalWithDelivery = currentItemsTotal + deliveryFee;
                    totalAmountSpan.textContent = '₦' + totalWithDelivery.toFixed(2);
                    if (modalTotalAmount) modalTotalAmount.textContent = '₦' + totalWithDelivery.toFixed(2);
                    
                    document.getElementById('delivery_branch_id').value = data.branch_id;
                } else {
                    deliveryFeeItem.style.display = 'none';
                    deliveryFeeNote.style.display = 'flex';
                    deliveryFeeNote.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ' + (data.message || 'Could not calculate delivery fee');
                    totalAmountSpan.textContent = '₦' + currentItemsTotal.toFixed(2);
                    if (modalTotalAmount) modalTotalAmount.textContent = '₦' + currentItemsTotal.toFixed(2);
                }
            } catch (error) {
                console.error('Error fetching delivery fee:', error);
                deliveryFeeItem.style.display = 'none';
                deliveryFeeNote.style.display = 'flex';
                deliveryFeeNote.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Network error calculating delivery fee';
                totalAmountSpan.textContent = '₦' + currentItemsTotal.toFixed(2);
                if (modalTotalAmount) modalTotalAmount.textContent = '₦' + currentItemsTotal.toFixed(2);
            }
        }
        
        function updateBankInfo() {
            if (bankAccountSelect) {
                const selected = bankAccountSelect.options[bankAccountSelect.selectedIndex];
                const bankName = selected.getAttribute('data-bank') || selected.textContent.split(' - ')[0];
                const accountNumber = selected.getAttribute('data-number') || (selected.textContent.match(/- ([\d]+)/) || [])[1];
                const accountName = selected.getAttribute('data-name') || (selected.textContent.match(/\(([^)]+)\)/) || [])[1];
                const accountId = selected.value;
                
                document.getElementById('bank_name').textContent = bankName;
                document.getElementById('account_name').textContent = accountName;
                document.getElementById('account_number').textContent = accountNumber;
                document.getElementById('modal_bank_name').textContent = bankName;
                document.getElementById('modal_account_name').textContent = accountName;
                document.getElementById('modal_account_number').textContent = accountNumber;
                accountIdField.value = accountId;
            }
        }
        
        function selectPayment(method) {
            if (method === 'bank_transfer') {
                bankDetailsDiv.classList.add('show');
            } else {
                bankDetailsDiv.classList.remove('show');
            }
        }
        
        function showPaymentModal() {
            pendingModalReceipt = null;
            modalReceiptPreview.innerHTML = '';
            modalReceiptInput.value = '';
            paymentModal.classList.add('show');
        }
        
        function closePaymentModal() {
            paymentModal.classList.remove('show');
            pendingModalReceipt = null;
            modalReceiptPreview.innerHTML = '';
            modalReceiptInput.value = '';
        }
        
        function confirmPayment() {
            if (pendingModalReceipt) {
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(pendingModalReceipt);
                
                let receiptInput = document.getElementById('receipt_upload');
                if (!receiptInput) {
                    receiptInput = document.createElement('input');
                    receiptInput.type = 'file';
                    receiptInput.name = 'receipt';
                    receiptInput.id = 'receipt_upload';
                    receiptInput.style.display = 'none';
                    document.getElementById('checkoutForm').appendChild(receiptInput);
                }
                receiptInput.files = dataTransfer.files;
            }
            closePaymentModal();
            setTimeout(() => {
                document.getElementById('checkoutForm').submit();
            }, 100);
        }
        
        if (modalReceiptInput) {
            modalReceiptInput.addEventListener('change', function(e) {
                pendingModalReceipt = e.target.files[0];
                if (pendingModalReceipt) {
                    if (pendingModalReceipt.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            modalReceiptPreview.innerHTML = `<img src="${e.target.result}" style="max-width: 100%; max-height: 80px; border-radius: 8px;">`;
                        };
                        reader.readAsDataURL(pendingModalReceipt);
                    } else {
                        modalReceiptPreview.innerHTML = `<p><i class="fas fa-file-pdf"></i> ${pendingModalReceipt.name}</p>`;
                    }
                }
            });
        }
        
        if (bankAccountSelect) {
            bankAccountSelect.addEventListener('change', updateBankInfo);
        }
        
        stateSelect.addEventListener('change', () => {
            loadCities();
            updateDeliveryFee();
        });
        citySelect.addEventListener('change', updateDeliveryFee);
        
        paymentMethodSelect.addEventListener('change', function() {
            if (this.value === 'bank_transfer') {
                selectPayment('bank_transfer');
            } else {
                selectPayment('cash_on_delivery');
            }
        });
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const paymentMethod = paymentMethodSelect.value;
            const deliveryAddress = deliveryAddressField.value;
            const deliveryState = stateSelect.value;
            const deliveryCity = citySelect.options[citySelect.selectedIndex]?.text || '';
            const locationId = locationIdField.value;
            
            if (!paymentMethod) {
                showToast('Please select a payment method', 'error');
                return;
            }
            
            if (!deliveryAddress.trim()) {
                showToast('Please enter your delivery address', 'error');
                return;
            }
            
            if (!deliveryState) {
                showToast('Please select your state', 'error');
                return;
            }
            
            if (!locationId || locationId <= 0) {
                showToast('Please select your city', 'error');
                return;
            }
            
            if (paymentMethod === 'bank_transfer') {
                showPaymentModal();
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';
            
            const formData = new FormData(form);
            formData.append('action', 'place_order');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    // Show toast notification
                    showToast('Order placed successfully! Redirecting to orders...', 'success', 2000);
                    
                    // Redirect after 2 seconds
                    setTimeout(() => {
                        window.location.href = orders.php;
                    }, 2000);
                } else {
                    showToast(data.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Place Order';
                }
            } catch (error) {
                console.error('Error:', error);
                showToast('An error occurred. Please try again.', 'error');
                submitBtn.disabled = false;
                submitBtn.textContent = 'Place Order';
            }
        });
        
        window.addEventListener('click', function(e) {
            if (e.target === paymentModal) {
                closePaymentModal();
            }
        });
        
        window.addEventListener('load', () => {
            setTimeout(() => {
                const preloader = document.getElementById('preloader');
                if (preloader) preloader.classList.add('fade-out');
            }, 500);
            
            if (stateSelect.value) {
                loadCities();
                if (citySelect.value) {
                    updateDeliveryFee();
                }
            }
            
            if (paymentMethodSelect.value === 'bank_transfer') {
                selectPayment('bank_transfer');
            }
        });
    </script>
</body>
</html>