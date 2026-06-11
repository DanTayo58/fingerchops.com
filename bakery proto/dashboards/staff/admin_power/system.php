<?php
// admin_power/system.php - System Configuration Tool
// Version: 6.4 - Fixed syntax errors, modal issues, and preloader
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Block favicon requests
if (strpos($_SERVER['REQUEST_URI'], 'favicon.ico') !== false) {
    http_response_code(404);
    exit;
}

require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
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

$privilege_level = $userObj->getPrivilegeLevel();
$minAdminLevel = setting('admin_privilege_level', 100);
if ($privilege_level < $minAdminLevel && !$userObj->hasPermission($_SESSION['user_id'], 'can_manage_system')) {
    header('Location: ../admin-dashboard.php?error=permission');
    exit;
}

// Persistent CSRF token
if (!isset($_SESSION['user_csrf_token'])) {
    $_SESSION['user_csrf_token'] = generateSecureToken(64);
}
$csrf_token = $_SESSION['user_csrf_token'];

// AJAX handler
if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    header('Content-Type: application/json');
    
    try {
        $token = $_POST['csrf_token'] ?? '';
        if ($token !== $_SESSION['user_csrf_token']) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        
        $action = $_POST['action'] ?? '';
        $response = ['success' => false, 'message' => 'Invalid action'];
        
        switch ($action) {
            case 'update_branch_fee':
                $branch_id = (int)($_POST['branch_id'] ?? 0);
                $fee = (float)($_POST['branch_fee'] ?? 0);
                if ($branch_id > 0) {
                    $db->preparedExecute("UPDATE branches SET branch_fee = ? WHERE id = ?", 'di', [$fee, $branch_id]);
                    $response = ['success' => true, 'message' => 'Branch fee updated'];
                }
                break;
            
            case 'add_branch_location_mapping':
                $branch_id = (int)($_POST['branch_id'] ?? 0);
                $location_id = (int)($_POST['location_id'] ?? 0);
                $multiplier = (float)($_POST['multiplier'] ?? 1.0);
                
                if ($branch_id > 0 && $location_id > 0) {
                    // First, check if this exact location is already mapped
                    $exists = $db->preparedFetchOne("SELECT id FROM branch_state_mappings WHERE branch_id = ? AND location_id = ?", 'ii', [$branch_id, $location_id]);
                    if ($exists) {
                        $response = ['success' => false, 'message' => 'This location is already mapped to this branch'];
                        break;
                    }
                    
                    // Get location details
                    $location = $db->preparedFetchOne("SELECT state, city FROM locations WHERE id = ?", 'i', [$location_id]);
                    if (!$location) {
                        $response = ['success' => false, 'message' => 'Location not found'];
                        break;
                    }
                    
                    $state = $location['state'];
                    
                    // Try to insert - if unique constraint exists, this will fail
                    $result = $db->preparedExecute("INSERT INTO branch_state_mappings (branch_id, location_id, state, multiplier) VALUES (?, ?, ?, ?)", 
                        'iisd', [$branch_id, $location_id, $state, $multiplier]);
                    
                    if ($result) {
                        $response = ['success' => true, 'message' => 'Location mapped to branch'];
                    } else {
                        // If insert fails, it might be due to the unique constraint on (branch_id, state)
                        // Let's check if there's already a mapping for this state
                        $existing_state = $db->preparedFetchOne("SELECT id, multiplier FROM branch_state_mappings WHERE branch_id = ? AND state = ? AND location_id IS NULL", 'is', [$branch_id, $state]);
                        if ($existing_state) {
                            $response = ['success' => false, 'message' => 'This state already has a default mapping. Please remove it first or map by city instead.'];
                        } else {
                            $response = ['success' => false, 'message' => 'Database error: Could not insert mapping. The unique constraint on (branch_id, state) may still exist.'];
                        }
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Branch ID and Location ID are required'];
                }
                break;
            
            case 'update_branch_location_multiplier':
                $mapping_id = (int)($_POST['mapping_id'] ?? 0);
                $multiplier = (float)($_POST['multiplier'] ?? 1.0);
                if ($mapping_id > 0) {
                    $db->preparedExecute("UPDATE branch_state_mappings SET multiplier = ? WHERE id = ?", 'di', [$multiplier, $mapping_id]);
                    $response = ['success' => true, 'message' => 'Multiplier updated'];
                }
                break;
            
            case 'remove_branch_location_mapping':
                $mapping_id = (int)($_POST['mapping_id'] ?? 0);
                if ($mapping_id > 0) {
                    $db->preparedExecute("DELETE FROM branch_state_mappings WHERE id = ?", 'i', [$mapping_id]);
                    $response = ['success' => true, 'message' => 'Location mapping removed'];
                }
                break;
            
            case 'get_branch_location_mappings':
                $branch_id = (int)($_POST['branch_id'] ?? 0);
                if ($branch_id > 0) {
                    $mappings = $db->preparedFetchAll("
                        SELECT bsm.id, bsm.branch_id, bsm.location_id, bsm.state, bsm.multiplier, 
                               l.city, l.state as location_state
                        FROM branch_state_mappings bsm
                        LEFT JOIN locations l ON bsm.location_id = l.id
                        WHERE bsm.branch_id = ? 
                        ORDER BY l.state, l.city
                    ", 'i', [$branch_id]);
                    $response = ['success' => true, 'mappings' => $mappings];
                }
                break;
            
            case 'get_available_locations':
                $branch_id = (int)($_POST['branch_id'] ?? 0);
                if ($branch_id > 0) {
                    // Get location IDs already mapped to this branch
                    $mapped_locations = $db->preparedFetchAll("SELECT location_id FROM branch_state_mappings WHERE branch_id = ? AND location_id IS NOT NULL", 'i', [$branch_id]);
                    $mapped_location_ids = array_column($mapped_locations, 'location_id');
                    
                    $sql = "SELECT id, state, city FROM locations WHERE 1=1";
                    $params = [];
                    $types = '';
                    
                    if (!empty($mapped_location_ids)) {
                        $placeholders = implode(',', array_fill(0, count($mapped_location_ids), '?'));
                        $sql .= " AND id NOT IN ($placeholders)";
                        $params = array_merge($params, $mapped_location_ids);
                        $types .= str_repeat('i', count($mapped_location_ids));
                    }
                    
                    $sql .= " ORDER BY state, city";
                    
                    $available = $db->preparedFetchAll($sql, $types, $params);
                    $response = ['success' => true, 'locations' => $available];
                }
                break;
            
            case 'update_product_delivery_rate':
                $product_id = (int)($_POST['product_id'] ?? 0);
                $rate = (float)($_POST['delivery_rate'] ?? 0);
                if ($product_id > 0) {
                    $db->preparedExecute("UPDATE products SET delivery_rate = ? WHERE id = ?", 'di', [$rate, $product_id]);
                    $response = ['success' => true, 'message' => 'Delivery rate updated'];
                }
                break;
            
            case 'update_fuel_price':
                $price = (float)($_POST['price'] ?? 0);
                $shared = (float)($_POST['shared_percentage'] ?? 0);
                if ($price > 0) {
                    $check = $db->preparedFetchOne("SELECT id FROM fuel_price LIMIT 1", '', []);
                    if ($check) {
                        $db->preparedExecute("UPDATE fuel_price SET price = ?, shared_percentage = ?, updated_at = NOW()", 'dd', [$price, $shared]);
                    } else {
                        $db->preparedExecute("INSERT INTO fuel_price (price, shared_percentage) VALUES (?, ?)", 'dd', [$price, $shared]);
                    }
                    $response = ['success' => true, 'message' => 'Fuel price updated'];
                }
                break;
            
            case 'save_location':
                $id = (int)($_POST['id'] ?? 0);
                $state = trim($_POST['state'] ?? '');
                $city = trim($_POST['city'] ?? '');
                if (empty($state) || empty($city)) {
                    $response = ['success' => false, 'message' => 'State and city required'];
                    break;
                }
                if ($id > 0) {
                    $db->preparedExecute("UPDATE locations SET state = ?, city = ? WHERE id = ?", 'ssi', [$state, $city, $id]);
                    $response = ['success' => true, 'message' => 'Location updated'];
                } else {
                    $db->preparedExecute("INSERT INTO locations (state, city) VALUES (?, ?)", 'ss', [$state, $city]);
                    $response = ['success' => true, 'message' => 'Location added'];
                }
                break;
            
            case 'delete_location':
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    // Check if location is used in any mappings
                    $used = $db->preparedFetchOne("SELECT id FROM branch_state_mappings WHERE location_id = ?", 'i', [$id]);
                    if ($used) {
                        $response = ['success' => false, 'message' => 'Cannot delete: Location is mapped to a branch'];
                        break;
                    }
                    $db->preparedExecute("DELETE FROM locations WHERE id = ?", 'i', [$id]);
                    $response = ['success' => true, 'message' => 'Location deleted'];
                }
                break;
            
            case 'save_dashboard_mapping':
                $id = (int)($_POST['id'] ?? 0);
                $department_code = strtoupper(trim($_POST['department_code'] ?? ''));
                $department_name = trim($_POST['department_name'] ?? '');
                $dashboard_file = trim($_POST['dashboard_file'] ?? '');
                $dashboard_name = trim($_POST['dashboard_name'] ?? '');
                $priority = (int)($_POST['priority'] ?? 0);
                if (empty($department_code) || empty($dashboard_file) || empty($dashboard_name)) {
                    $response = ['success' => false, 'message' => 'Required fields missing'];
                    break;
                }
                if ($id > 0) {
                    $db->preparedExecute("UPDATE dashboard_mappings SET department_code = ?, department_name = ?, dashboard_file = ?, dashboard_name = ?, priority = ?, is_active = 1 WHERE id = ?", 
                        'ssssii', [$department_code, $department_name, $dashboard_file, $dashboard_name, $priority, $id]);
                    $response = ['success' => true, 'message' => 'Mapping updated'];
                } else {
                    $check = $db->preparedFetchOne("SELECT id FROM dashboard_mappings WHERE department_code = ?", 's', [$department_code]);
                    if ($check) {
                        $response = ['success' => false, 'message' => 'Mapping for this department already exists'];
                        break;
                    }
                    $db->preparedExecute("INSERT INTO dashboard_mappings (department_code, department_name, dashboard_file, dashboard_name, priority) VALUES (?, ?, ?, ?, ?)", 
                        'ssssi', [$department_code, $department_name, $dashboard_file, $dashboard_name, $priority]);
                    $response = ['success' => true, 'message' => 'Mapping added'];
                }
                break;
            
            case 'update_setting':
                $key = $_POST['key'] ?? '';
                $value = $_POST['value'] ?? '';
                $type = $_POST['type'] ?? 'string';
                $description = $_POST['description'] ?? '';
                if (empty($key)) {
                    $response = ['success' => false, 'message' => 'Key is required'];
                    break;
                }
                $configLoader = ConfigLoader::getInstance();
                $result = $configLoader->set($key, $value, $type, $description);
                $response = $result ? ['success' => true, 'message' => 'Setting saved'] : ['success' => false, 'message' => 'Failed to save setting'];
                break;
            
            default:
                $response = ['success' => false, 'message' => 'Unknown action: ' . $action];
        }
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch data for display
$products = $db->preparedFetchAll("SELECT id, name, delivery_rate FROM products ORDER BY name", '', []);
$branches = $db->preparedFetchAll("SELECT id, branch_code, branch_name, branch_fee FROM branches WHERE is_active = 1 ORDER BY branch_code", '', []);
$fuel = $db->preparedFetchOne("SELECT price, shared_percentage FROM fuel_price ORDER BY id DESC LIMIT 1", '', []);
if (!$fuel) {
    $fuel = ['price' => 1000.00, 'shared_percentage' => 5.00];
}
$locations = $db->preparedFetchAll("SELECT id, state, city FROM locations ORDER BY state, city", '', []);
$mappings = $db->preparedFetchAll("SELECT id, department_code, department_name, dashboard_file, dashboard_name, priority FROM dashboard_mappings WHERE is_active = 1 ORDER BY priority DESC", '', []);

// Fetch all branch-location mappings
$all_branch_mappings = [];
try {
    $all_branch_mappings = $db->preparedFetchAll("
        SELECT bsm.id, bsm.branch_id, bsm.location_id, bsm.state, bsm.multiplier,
               b.branch_code, b.branch_name,
               l.city, l.state as location_state
        FROM branch_state_mappings bsm
        JOIN branches b ON bsm.branch_id = b.id
        LEFT JOIN locations l ON bsm.location_id = l.id
        ORDER BY b.branch_code, l.state, l.city
    ", '', []);
} catch (Exception $e) {
    $all_branch_mappings = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Configuration - Fingerchops Bakery</title>
    <link rel="icon" type="image/jpeg" href="../logo.jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.0.0-beta3/css/all.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="css/system.css">
</head>
<body>
    <div class="preloader" id="preloader">
        <div class="preloader-spinner"></div>
    </div>
    <div class="dashboard-container">
        <div class="admin-header">
            <div class="header-top">
                <div class="header-title">
                    <h1><i class="fas fa-cog"></i> System Configuration</h1>
                    <p>Manage system settings, delivery configuration, and more</p>
                </div>
                <div class="header-actions">
                    <a href="../admin-dashboard.php" class="logout-btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>

        <div class="tabs-container">
            <ul class="tabs">
                <li class="tab" data-tab="general">General Settings</li>
                <li class="tab" data-tab="delivery">Delivery Config</li>
                <li class="tab active" data-tab="branch_fees">Branch Fees & Mapping</li>
                <li class="tab" data-tab="product_rates">Product Delivery Rates</li>
                <li class="tab" data-tab="locations">Locations</li>
                <li class="tab" data-tab="mappings">Dashboard Mappings</li>
                <li class="tab" data-tab="info">System Info</li>
            </ul>
        </div>

        <!-- General Settings Tab -->
        <div id="general" class="tab-content">
            <h3>General Settings</h3>
            <form id="settings-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="form-group"><label>App Name</label><input type="text" class="form-control" name="app_name" value="<?php echo htmlspecialchars(setting('app_name', 'Fingerchops Ventures')); ?>"></div>
                <div class="form-group"><label>Site Name</label><input type="text" class="form-control" name="site_name" value="<?php echo htmlspecialchars(setting('site_name', 'Fingerchops Bakery')); ?>"></div>
                <div class="form-group"><label>Timezone</label><input type="text" class="form-control" name="timezone" value="<?php echo htmlspecialchars(setting('timezone', 'Africa/Lagos')); ?>"></div>
                <div class="form-group"><label>Date Format</label><input type="text" class="form-control" name="date_format" value="<?php echo htmlspecialchars(setting('date_format', 'Y-m-d')); ?>"></div>
                <div class="form-group"><label>DateTime Format</label><input type="text" class="form-control" name="datetime_format" value="<?php echo htmlspecialchars(setting('datetime_format', 'Y-m-d H:i:s')); ?>"></div>
                <div class="form-group"><label>Currency Symbol</label><input type="text" class="form-control" name="currency" value="<?php echo htmlspecialchars(setting('currency', '₦')); ?>"></div>
                <div class="form-group"><label>Low Stock Threshold</label><input type="number" class="form-control" name="low_stock_threshold" value="<?php echo setting('low_stock_threshold', 10); ?>"></div>
                <div class="form-group"><label>Critical Stock Threshold</label><input type="number" class="form-control" name="critical_stock_threshold" value="<?php echo setting('critical_stock_threshold', 5); ?>"></div>
                <div class="form-group"><label>Admin Privilege Level</label><input type="number" class="form-control" name="admin_privilege_level" value="<?php echo setting('admin_privilege_level', 100); ?>"></div>
                <div class="form-group"><label>Max Login Attempts</label><input type="number" class="form-control" name="max_login_attempts" value="<?php echo setting('max_login_attempts', 5); ?>"></div>
                <div class="form-group"><label>Lockout Duration (minutes)</label><input type="number" class="form-control" name="lockout_duration" value="<?php echo setting('lockout_duration', 15); ?>"></div>
                <div class="form-group"><label>Session Lifetime (seconds)</label><input type="number" class="form-control" name="session_lifetime" value="<?php echo setting('session_lifetime', 28800); ?>"></div>
                <div class="form-group"><label>Session Inactivity Timeout (seconds)</label><input type="number" class="form-control" name="session_inactivity_timeout" value="<?php echo setting('session_inactivity_timeout', 1800); ?>"></div>
                <div class="form-group"><label>Force Password Change (days)</label><input type="number" class="form-control" name="force_password_change_days" value="<?php echo setting('force_password_change_days', 90); ?>"></div>
                <div class="form-group"><label>Remember Me Days</label><input type="number" class="form-control" name="remember_me_days" value="<?php echo setting('remember_me_days', 30); ?>"></div>
                <button type="submit" class="btn-primary">Save All Settings</button>
            </form>
        </div>

        <!-- Delivery Config Tab (Fuel Price) -->
        <div id="delivery" class="tab-content">
            <h3>Delivery Configuration (Fuel Price)</h3>
            <div class="form-group">
                <label>Fuel Price (₦)</label>
                <input type="number" id="fuel_price" step="0.01" class="form-control" value="<?php echo $fuel['price']; ?>">
            </div>
            <div class="form-group">
                <label>Surcharge Percentage (e.g., 5 = 5%)</label>
                <input type="number" id="fuel_shared" step="0.01" class="form-control" value="<?php echo $fuel['shared_percentage']; ?>">
            </div>
            <button id="saveFuelBtn" class="btn-primary">Save Fuel Price</button>
        </div>

        <!-- Branch Fees & Mapping Tab -->
        <div id="branch_fees" class="tab-content active">
            <h3>Branch Delivery Multipliers & Location Mapping</h3>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr><th>Branch Code</th><th>Branch Name</th><th>Multiplier</th><th>Action</th><th>Mapped Locations</th> </thead>
                    <tbody>
                        <?php foreach ($branches as $branch): 
                            $branch_mappings = array_filter($all_branch_mappings, function($m) use ($branch) { return $m['branch_id'] == $branch['id']; });
                        ?>
                        <tr id="branch-row-<?php echo $branch['id']; ?>">
                            <td class="branch-code"><?php echo htmlspecialchars($branch['branch_code']); ?>     </td>
                            <td class="branch-name"><?php echo htmlspecialchars($branch['branch_name']); ?>     </td>
                            <td><input type="number" step="0.01" class="branch-fee-input" data-id="<?php echo $branch['id']; ?>" value="<?php echo $branch['branch_fee']; ?>" style="width:80px;"></td>
                            <td><button class="btn-update" data-action="update_branch_fee" data-id="<?php echo $branch['id']; ?>">Update Fee</button></td>
                            <td class="branch-mappings-cell">
                                <button class="btn-manage-locations" data-branch-id="<?php echo $branch['id']; ?>" data-branch-name="<?php echo htmlspecialchars($branch['branch_name']); ?>" data-branch-code="<?php echo htmlspecialchars($branch['branch_code']); ?>">
                                    <i class="fas fa-map-marker-alt"></i> Manage Locations 
                                    <span class="mapping-count">(<?php echo count($branch_mappings); ?>)</span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Location Mapping Modal -->
        <div id="locationMappingModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="locationMappingModalTitle">Manage Branch Locations</h3>
                    <button class="modal-close" id="closeLocationMappingModalBtnTop">&times;</button>
                </div>
                <div class="modal-body" id="locationMappingModalBody">
                    <div id="branch-info">
                        <strong>Branch:</strong> <span id="modal-branch-name"></span> (<span id="modal-branch-code"></span>)
                    </div>
                    
                    <div id="mapped-locations-section">
                        <h4><i class="fas fa-map-marked-alt"></i> Mapped Locations</h4>
                        <div id="mapped-locations-list">
                            <div class="loading-states">Loading mapped locations...</div>
                        </div>
                    </div>
                    
                    <div id="add-location-section" style="margin-top: 1.5rem;">
                        <h4><i class="fas fa-plus-circle"></i> Add New Location</h4>
                        <div class="add-mapping-form">
                            <select id="modal-location-select" class="form-control">
                                <option value="">-- Select Location (City, State) --</option>
                            </select>
                            <input type="number" id="modal-multiplier" class="form-control" placeholder="Multiplier" value="1.00" step="0.01" style="width: 100px;">
                            <button id="modal-add-location-btn" class="btn-primary" disabled>Add Location</button>
                        </div>
                        <small class="text-muted">Multiplier affects delivery fee for this location (default: 1.00)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="closeLocationMappingModalBtn" class="btn-delete" style="background:#6c757d;">Close</button>
                </div>
            </div>
        </div>

        <!-- Product Delivery Rates Tab -->
        <div id="product_rates" class="tab-content">
            <h3>Product Delivery Rates (per item)</h3>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr><th>Product</th><th>Delivery Rate (₦)</th><th>Action</th> </thead>
                    <tbody>
                        <?php foreach ($products as $prod): ?>
                        <tr id="product-row-<?php echo $prod['id']; ?>">
                            <td><?php echo htmlspecialchars($prod['name']); ?></td>
                            <td><input type="number" step="0.01" class="product-rate-input" data-id="<?php echo $prod['id']; ?>" value="<?php echo $prod['delivery_rate']; ?>"></td>
                            <td><button class="btn-update" data-action="update_product_delivery_rate" data-id="<?php echo $prod['id']; ?>">Update</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Locations Tab -->
        <div id="locations" class="tab-content">
            <div class="section-header">
                <h3>Manage Delivery Locations (Cities)</h3>
                <button id="addLocationBtn" class="btn-primary">Add Location</button>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr><th>State</th><th>City</th><th>Actions</th> </thead>
                    <tbody id="locations-table">
                        <?php foreach ($locations as $loc): ?>
                        <tr data-id="<?php echo $loc['id']; ?>">
                            <td class="loc-state"><?php echo htmlspecialchars($loc['state']); ?></td>
                            <td class="loc-city"><?php echo htmlspecialchars($loc['city']); ?></td>
                            <td><button class="btn-edit edit-loc" data-id="<?php echo $loc['id']; ?>">Edit</button> <button class="btn-delete delete-loc" data-id="<?php echo $loc['id']; ?>">Delete</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Dashboard Mappings Tab -->
        <div id="mappings" class="tab-content">
            <div class="section-header">
                <h3>Dashboard Mappings</h3>
                <button class="btn-primary" id="addMappingBtn">Add Mapping</button>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr><th>Dept Code</th><th>Dept Name</th><th>Dashboard File</th><th>Dashboard Name</th><th>Priority</th><th>Actions</th> </thead>
                    <tbody>
                        <?php foreach ($mappings as $map): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($map['department_code']); ?></td>
                            <td><?php echo htmlspecialchars($map['department_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($map['dashboard_file']); ?></td>
                            <td><?php echo htmlspecialchars($map['dashboard_name']); ?></td>
                            <td><?php echo $map['priority']; ?></td>
                            <td><button class="btn-edit" onclick="editMapping(<?php echo htmlspecialchars(json_encode($map)); ?>)">Edit</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- System Info Tab -->
        <div id="info" class="tab-content">
            <h3>System Information</h3>
            <ul class="system-info-list">
                <li><strong>PHP Version:</strong> <span><?php echo phpversion(); ?></span></li>
                <li><strong>MySQL Version:</strong> <span><?php echo $db->getConnection()->server_info; ?></span></li>
                <li><strong>Server Software:</strong> <span><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></span></li>
                <li><strong>Document Root:</strong> <span><?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'; ?></span></li>
                <li><strong>Session Save Path:</strong> <span><?php echo session_save_path() ?: 'Default'; ?></span></li>
                <li><strong>Max Execution Time:</strong> <span><?php echo ini_get('max_execution_time'); ?> seconds</span></li>
                <li><strong>Memory Limit:</strong> <span><?php echo ini_get('memory_limit'); ?></span></li>
                <li><strong>Upload Max Filesize:</strong> <span><?php echo ini_get('upload_max_filesize'); ?></span></li>
                <li><strong>Post Max Size:</strong> <span><?php echo ini_get('post_max_size'); ?></span></li>
            </ul>
            <hr>
            <h4>Database Tables</h4>
            <ul class="database-tables">
                <?php
                $tables = $db->preparedFetchAll("SHOW TABLES", '', []);
                foreach ($tables as $row) {
                    $table = reset($row);
                    echo "<li>" . htmlspecialchars($table) . "</li>";
                }
                ?>
            </ul>
        </div>
    </div>

    <!-- Modal for forms -->
    <div id="modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Modal Title</h3>
                <button class="modal-close" id="modalClose">&times;</button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>
    <div id="overlay" class="overlay"></div>
    <div id="toastContainer" class="toast-container"></div>

    <script>
        const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
        const STORAGE_KEY = 'system_config_active_tab';
        
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `${message}<span style="float:right; cursor:pointer;" onclick="this.parentElement.remove()">×</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        async function sendRequest(action, data, callback, showSuccessToast = true) {
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('action', action);
            for (let key in data) {
                formData.append(key, data[key]);
            }
            
            try {
                const response = await fetch(window.location.href + '?t=' + Date.now(), {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const text = await response.text();
                const res = JSON.parse(text);
                
                if (res.success) {
                    if (showSuccessToast && res.message) {
                        showToast(res.message, 'success');
                    }
                    if (callback) callback(res);
                } else {
                    showToast(res.message || 'Request failed', 'error');
                }
            } catch (err) {
                console.error('Request Error:', err);
                showToast('Network error: ' + err.message, 'error');
            }
        }

        // Tab switching
        function switchTab(tabId) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            const tabElement = document.querySelector(`.tab[data-tab="${tabId}"]`);
            if (tabElement) tabElement.classList.add('active');
            const contentElement = document.getElementById(tabId);
            if (contentElement) contentElement.classList.add('active');
            localStorage.setItem(STORAGE_KEY, tabId);
        }

        function initTabs() {
            const savedTab = localStorage.getItem(STORAGE_KEY);
            const defaultTab = savedTab && document.getElementById(savedTab) ? savedTab : 'branch_fees';
            switchTab(defaultTab);
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    switchTab(tab.dataset.tab);
                });
            });
        }

        // Branch fee updates
        document.querySelectorAll('.btn-update[data-action="update_branch_fee"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const branch_id = btn.dataset.id;
                const row = document.getElementById('branch-row-' + branch_id);
                if (!row) return;
                const input = row.querySelector('.branch-fee-input');
                if (!input) return;
                const fee = input.value;
                sendRequest('update_branch_fee', { branch_id: branch_id, branch_fee: fee });
            });
        });

        // Product delivery rates
        document.querySelectorAll('.btn-update[data-action="update_product_delivery_rate"]').forEach(btn => {
            btn.addEventListener('click', () => {
                const product_id = btn.dataset.id;
                const row = document.getElementById('product-row-' + product_id);
                if (!row) return;
                const input = row.querySelector('.product-rate-input');
                if (!input) return;
                const rate = input.value;
                sendRequest('update_product_delivery_rate', { product_id: product_id, delivery_rate: rate });
            });
        });

        // Fuel price
        const saveFuelBtn = document.getElementById('saveFuelBtn');
        if (saveFuelBtn) {
            saveFuelBtn.addEventListener('click', () => {
                const price = document.getElementById('fuel_price')?.value;
                const shared = document.getElementById('fuel_shared')?.value;
                if (price && shared) {
                    sendRequest('update_fuel_price', { price: price, shared_percentage: shared });
                }
            });
        }

        // General Settings form
        const settingsForm = document.getElementById('settings-form');
        if (settingsForm) {
            settingsForm.addEventListener('submit', e => {
                e.preventDefault();
                const elements = settingsForm.elements;
                let requests = [];
                for (let el of elements) {
                    if (el.name && el.name !== 'csrf_token') {
                        const formData = new FormData();
                        formData.append('action', 'update_setting');
                        formData.append('key', el.name);
                        formData.append('value', el.value);
                        formData.append('type', el.type === 'number' ? 'integer' : 'string');
                        requests.push(fetch(window.location.href, {
                            method: 'POST',
                            body: formData,
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(r => r.json()));
                    }
                }
                Promise.all(requests)
                    .then(results => {
                        let allSuccess = true;
                        results.forEach(r => { if (!r.success) { allSuccess = false; showToast(r.message, 'error'); } });
                        if (allSuccess) showToast('All settings saved', 'success');
                    })
                    .catch(err => showToast('Error saving settings', 'error'));
            });
        }

        // Modal functions
        const modal = document.getElementById('modal');
        const overlay = document.getElementById('overlay');
        const modalClose = document.getElementById('modalClose');
        
        function openModal(title, bodyContent, footerContent = '') {
            const modalTitle = document.getElementById('modalTitle');
            const modalBody = document.getElementById('modalBody');
            const modalFooter = document.getElementById('modalFooter');
            if (!modalTitle || !modalBody || !modalFooter) return;
            
            modalTitle.textContent = title;
            modalBody.innerHTML = bodyContent;
            if (footerContent) {
                modalFooter.innerHTML = footerContent;
                modalFooter.style.display = 'flex';
            } else {
                modalFooter.style.display = 'none';
            }
            if (modal) modal.style.display = 'flex';
            if (overlay) overlay.classList.add('active');
        }
        
        function closeModal() {
            if (modal) modal.style.display = 'none';
            if (overlay) overlay.classList.remove('active');
        }
        
        if (modalClose) modalClose.onclick = closeModal;
        if (overlay) overlay.onclick = closeModal;

        // Location management
        function openLocationModal(id = null, state = '', city = '') {
            const bodyContent = `
                <form id="locationForm">
                    <input type="hidden" name="id" value="${id || ''}">
                    <div class="form-group">
                        <label>State</label>
                        <input type="text" name="state" class="form-control" value="${escapeHtml(state)}" required>
                    </div>
                    <div class="form-group">
                        <label>City</label>
                        <input type="text" name="city" class="form-control" value="${escapeHtml(city)}" required>
                    </div>
                </form>
            `;
            const footerContent = `
                <button class="btn-primary" id="saveLocationBtn">Save</button>
                <button class="btn-delete" id="cancelLocationBtn" style="background:#6c757d;">Cancel</button>
            `;
            openModal(id ? 'Edit Location' : 'Add Location', bodyContent, footerContent);
            
            const saveBtn = document.getElementById('saveLocationBtn');
            if (saveBtn) {
                saveBtn.onclick = () => {
                    const formData = new FormData(document.getElementById('locationForm'));
                    sendRequest('save_location', Object.fromEntries(formData), () => location.reload());
                };
            }
            const cancelBtn = document.getElementById('cancelLocationBtn');
            if (cancelBtn) cancelBtn.onclick = closeModal;
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

        const addLocationBtn = document.getElementById('addLocationBtn');
        if (addLocationBtn) addLocationBtn.addEventListener('click', () => openLocationModal());
        
        document.querySelectorAll('.edit-loc').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const row = btn.closest('tr');
                if (!row) return;
                const state = row.querySelector('.loc-state')?.innerText || '';
                const city = row.querySelector('.loc-city')?.innerText || '';
                openLocationModal(id, state, city);
            });
        });
        
        document.querySelectorAll('.delete-loc').forEach(btn => {
            btn.addEventListener('click', () => {
                if (confirm('Delete this location?')) {
                    const id = btn.dataset.id;
                    sendRequest('delete_location', { id: id }, () => location.reload());
                }
            });
        });

        // Dashboard Mappings functions
        function addMapping() {
            const bodyContent = `
                <form id="mappingForm">
                    <div class="form-group">
                        <label>Department Code (e.g., HR)</label>
                        <input type="text" name="department_code" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Department Name (optional)</label>
                        <input type="text" name="department_name" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Dashboard File (e.g., dashboards/staff/hr-dashboard.php)</label>
                        <input type="text" name="dashboard_file" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Dashboard Name</label>
                        <input type="text" name="dashboard_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <input type="number" name="priority" class="form-control" value="50">
                    </div>
                </form>
            `;
            const footerContent = `
                <button class="btn-primary" id="saveMappingBtn">Add</button>
                <button class="btn-delete" id="cancelMappingBtn" style="background:#6c757d;">Cancel</button>
            `;
            openModal('Add Dashboard Mapping', bodyContent, footerContent);
            
            const saveBtn = document.getElementById('saveMappingBtn');
            if (saveBtn) {
                saveBtn.onclick = () => {
                    const formData = new FormData(document.getElementById('mappingForm'));
                    formData.append('id', '0');
                    sendRequest('save_dashboard_mapping', Object.fromEntries(formData), () => location.reload());
                };
            }
            const cancelBtn = document.getElementById('cancelMappingBtn');
            if (cancelBtn) cancelBtn.onclick = closeModal;
        }

        window.editMapping = function(mapping) {
            const bodyContent = `
                <form id="mappingForm">
                    <input type="hidden" name="id" value="${mapping.id}">
                    <div class="form-group">
                        <label>Department Code</label>
                        <input type="text" name="department_code" class="form-control" value="${escapeHtml(mapping.department_code)}" required>
                    </div>
                    <div class="form-group">
                        <label>Department Name</label>
                        <input type="text" name="department_name" class="form-control" value="${escapeHtml(mapping.department_name || '')}">
                    </div>
                    <div class="form-group">
                        <label>Dashboard File</label>
                        <input type="text" name="dashboard_file" class="form-control" value="${escapeHtml(mapping.dashboard_file)}" required>
                    </div>
                    <div class="form-group">
                        <label>Dashboard Name</label>
                        <input type="text" name="dashboard_name" class="form-control" value="${escapeHtml(mapping.dashboard_name)}" required>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <input type="number" name="priority" class="form-control" value="${mapping.priority}">
                    </div>
                </form>
            `;
            const footerContent = `
                <button class="btn-primary" id="saveMappingBtn">Update</button>
                <button class="btn-delete" id="cancelMappingBtn" style="background:#6c757d;">Cancel</button>
            `;
            openModal('Edit Dashboard Mapping', bodyContent, footerContent);
            
            const saveBtn = document.getElementById('saveMappingBtn');
            if (saveBtn) {
                saveBtn.onclick = () => {
                    const formData = new FormData(document.getElementById('mappingForm'));
                    sendRequest('save_dashboard_mapping', Object.fromEntries(formData), () => location.reload());
                };
            }
            const cancelBtn = document.getElementById('cancelMappingBtn');
            if (cancelBtn) cancelBtn.onclick = closeModal;
        }

        const addMappingBtn = document.getElementById('addMappingBtn');
        if (addMappingBtn) addMappingBtn.addEventListener('click', addMapping);

        // Location Mapping Modal Variables
        let currentBranchId = null;
        let currentBranchMappings = [];

        // Location Mapping Modal Functions
        function openLocationMappingModal(branchId, branchName, branchCode) {
            currentBranchId = branchId;
            const branchNameSpan = document.getElementById('modal-branch-name');
            const branchCodeSpan = document.getElementById('modal-branch-code');
            const modalTitle = document.getElementById('locationMappingModalTitle');
            
            if (branchNameSpan) branchNameSpan.textContent = branchName;
            if (branchCodeSpan) branchCodeSpan.textContent = branchCode;
            if (modalTitle) modalTitle.textContent = `Manage Locations - ${branchName}`;
            
            loadMappedLocations(branchId);
            loadAvailableLocations(branchId);
            
            const modal = document.getElementById('locationMappingModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('active');
            }
            if (overlay) overlay.classList.add('active');
        }

        function closeLocationMappingModal() {
            const modal = document.getElementById('locationMappingModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('active');
            }
            if (overlay) overlay.classList.remove('active');
            currentBranchId = null;
            currentBranchMappings = [];
        }

        function loadMappedLocations(branchId) {
            const container = document.getElementById('mapped-locations-list');
            if (!container) return;
            container.innerHTML = '<div class="loading-states">Loading...</div>';
            
            sendRequest('get_branch_location_mappings', { branch_id: branchId }, (data) => {
                if (data.success && data.mappings) {
                    currentBranchMappings = data.mappings;
                    if (data.mappings.length === 0) {
                        container.innerHTML = '<div class="no-mappings">No locations mapped to this branch yet.</div>';
                    } else {
                        container.innerHTML = '';
                        data.mappings.forEach(mapping => {
                            const displayText = mapping.city ? `${escapeHtml(mapping.city)} (${escapeHtml(mapping.state)})` : `State: ${escapeHtml(mapping.state)}`;
                            const tag = document.createElement('div');
                            tag.className = 'mapping-tag';
                            tag.innerHTML = `
                                <i class="fas fa-map-marker-alt"></i>
                                ${displayText}
                                <span class="multiplier-badge">×${parseFloat(mapping.multiplier || 1).toFixed(2)}</span>
                                <input type="number" class="multiplier-input" data-id="${mapping.id}" value="${mapping.multiplier || 1}" step="0.01" style="width: 65px;">
                                <button class="update-multiplier-btn" data-id="${mapping.id}" title="Update multiplier">
                                    <i class="fas fa-save"></i>
                                </button>
                                <button class="remove-mapping-btn" data-id="${mapping.id}" title="Remove this location">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            `;
                            container.appendChild(tag);
                        });
                        
                        document.querySelectorAll('.update-multiplier-btn').forEach(btn => {
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const mappingId = btn.dataset.id;
                                const input = btn.parentElement.querySelector('.multiplier-input');
                                const multiplier = input ? input.value : 1;
                                sendRequest('update_branch_location_multiplier', { mapping_id: mappingId, multiplier: multiplier }, () => {
                                    loadMappedLocations(currentBranchId);
                                    showToast('Multiplier updated', 'success');
                                }, false);
                            });
                        });
                        
                        document.querySelectorAll('.remove-mapping-btn').forEach(btn => {
                            btn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                const mappingId = btn.dataset.id;
                                if (confirm('Remove this location mapping?')) {
                                    sendRequest('remove_branch_location_mapping', { mapping_id: mappingId }, () => {
                                        loadMappedLocations(currentBranchId);
                                        loadAvailableLocations(currentBranchId);
                                        const countSpan = document.querySelector(`.btn-manage-locations[data-branch-id="${currentBranchId}"] .mapping-count`);
                                        if (countSpan) {
                                            const newCount = currentBranchMappings.length - 1;
                                            countSpan.textContent = `(${newCount})`;
                                        }
                                        showToast('Location removed successfully', 'success');
                                    }, false);
                                }
                            });
                        });
                    }
                }
            }, false);
        }

        function loadAvailableLocations(branchId) {
            const select = document.getElementById('modal-location-select');
            if (!select) return;
            select.innerHTML = '<option value="">-- Select Location --</option>';
            select.disabled = true;
            const addBtn = document.getElementById('modal-add-location-btn');
            if (addBtn) addBtn.disabled = true;
            
            sendRequest('get_available_locations', { branch_id: branchId }, (data) => {
                if (data.success && data.locations) {
                    if (data.locations.length === 0) {
                        select.innerHTML = '<option value="">No available locations to add</option>';
                        select.disabled = true;
                        if (addBtn) addBtn.disabled = true;
                    } else {
                        data.locations.forEach(loc => {
                            const option = document.createElement('option');
                            option.value = loc.id;
                            option.textContent = `${escapeHtml(loc.city)} (${escapeHtml(loc.state)})`;
                            select.appendChild(option);
                        });
                        select.disabled = false;
                        if (addBtn) addBtn.disabled = true;
                    }
                }
            }, false);
        }

        const locationSelect = document.getElementById('modal-location-select');
        if (locationSelect) {
            locationSelect.addEventListener('change', function() {
                const addBtn = document.getElementById('modal-add-location-btn');
                if (addBtn) addBtn.disabled = !this.value;
            });
        }

        const addLocationBtnModal = document.getElementById('modal-add-location-btn');
        if (addLocationBtnModal) {
            addLocationBtnModal.addEventListener('click', function() {
                const select = document.getElementById('modal-location-select');
                const location_id = select ? select.value : null;
                const multiplier = document.getElementById('modal-multiplier')?.value || 1.0;
                if (!location_id) return;
                
                sendRequest('add_branch_location_mapping', { 
                    branch_id: currentBranchId, 
                    location_id: location_id, 
                    multiplier: multiplier 
                }, () => {
                    loadMappedLocations(currentBranchId);
                    loadAvailableLocations(currentBranchId);
                    const countSpan = document.querySelector(`.btn-manage-locations[data-branch-id="${currentBranchId}"] .mapping-count`);
                    if (countSpan) {
                        const currentText = countSpan.textContent;
                        const currentCount = parseInt(currentText.replace(/[()]/g, '')) || 0;
                        countSpan.textContent = `(${currentCount + 1})`;
                    }
                });
            });
        }

        // Manage Locations buttons
        document.querySelectorAll('.btn-manage-locations').forEach(btn => {
            btn.addEventListener('click', () => {
                const branchId = btn.dataset.branchId;
                const branchName = btn.dataset.branchName;
                const branchCode = btn.dataset.branchCode;
                if (branchId && branchName && branchCode) {
                    openLocationMappingModal(branchId, branchName, branchCode);
                }
            });
        });

        // Modal close handlers
        const closeTopBtn = document.getElementById('closeLocationMappingModalBtnTop');
        const closeBottomBtn = document.getElementById('closeLocationMappingModalBtn');
        
        if (closeTopBtn) closeTopBtn.addEventListener('click', closeLocationMappingModal);
        if (closeBottomBtn) closeBottomBtn.addEventListener('click', closeLocationMappingModal);

        // Preloader
        window.addEventListener('load', function() {
            setTimeout(function() {
                const preloader = document.getElementById('preloader');
                if (preloader) {
                    preloader.classList.add('fade-out');
                    setTimeout(function() {
                        if (preloader.parentNode) preloader.parentNode.removeChild(preloader);
                    }, 500);
                }
            }, 500);
            initTabs();
        });
        
        // Also remove preloader after max 3 seconds as fallback
        setTimeout(function() {
            const preloader = document.getElementById('preloader');
            if (preloader && preloader.parentNode) {
                preloader.classList.add('fade-out');
                setTimeout(function() {
                    if (preloader.parentNode) preloader.parentNode.removeChild(preloader);
                }, 500);
            }
        }, 3000);
    </script>
</body>
</html>