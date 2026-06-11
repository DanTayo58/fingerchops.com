<?php
/**
 * Sales Analysis Dashboard
 * Displays sales trends, comparisons, target insights, and stock per branch
 */

session_start();

// Production-safe error reporting
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
require_once '../../../config/config_loader.php';
require_once '../../../includes/DashboardRouter.php';

$db = Database::getInstance();

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

$router = new DashboardRouter();
$dashboardUrl = $router->getDashboard(['id' => $_SESSION['user_id'], 'user_type' => $user['user_type']]);

// Get user's branch and permissions
$user_branch_id = $user['branch_id'] ?? null;
$user_permissions = $userObj->getPermissionsFlattened();
$is_headquarters = ($user_branch_id === null || $user_branch_id == 1);
$can_edit = $is_headquarters || ($user_permissions['can_manage_inventory'] ?? false);

// Fetch all branches for filter (HQ only)
$all_branches = [];
if ($is_headquarters) {
    $all_branches = $db->preparedFetchAll("SELECT id, branch_code, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_code", '', []);
}

// =====================================================
// ENSURE SAMPLE DATA EXISTS
// =====================================================
$sales_count = $db->query("SELECT COUNT(*) as cnt FROM sales_records");
if ($sales_count && ($row = mysqli_fetch_assoc($sales_count)) && $row['cnt'] == 0) {
    $products_res = $db->query("SELECT id FROM products WHERE is_active = 1");
    $product_ids = [];
    while ($row = mysqli_fetch_assoc($products_res)) {
        $product_ids[] = $row['id'];
    }
    if (!empty($product_ids)) {
        $customers_res = $db->query("SELECT id FROM bakery_users WHERE user_type IN ('customer', 'vendor') LIMIT 5");
        $customer_ids = [];
        while ($row = mysqli_fetch_assoc($customers_res)) {
            $customer_ids[] = $row['id'];
        }
        if (empty($customer_ids)) $customer_ids[] = null;
        
        $start_date = strtotime('-30 days');
        $end_date = time();
        for ($d = $start_date; $d <= $end_date; $d += 86400) {
            $date = date('Y-m-d', $d);
            $num_sales = rand(0, 5);
            for ($i = 0; $i < $num_sales; $i++) {
                $product_id = $product_ids[array_rand($product_ids)];
                $quantity = rand(1, 20);
                $price_query = $db->query("SELECT base_price FROM products WHERE id = $product_id");
                $price_row = mysqli_fetch_assoc($price_query);
                $base_price = $price_row['base_price'];
                $total_price = $base_price * $quantity;
                $customer_id = $customer_ids[array_rand($customer_ids)];
                $recorded_by = $_SESSION['user_id'];
                $db->query("INSERT INTO sales_records (product_id, quantity_sold, sale_price, sale_date, customer_id, recorded_by)
                            VALUES ($product_id, $quantity, $total_price, '$date', " . ($customer_id ?: 'NULL') . ", $recorded_by)");
            }
        }
        error_log("Inserted sample sales records for last 30 days.");
    }
}

$targets_check = $db->query("SELECT COUNT(*) as cnt FROM sales_targets");
if ($targets_check && ($row = mysqli_fetch_assoc($targets_check)) && $row['cnt'] == 0) {
    $products_res = $db->query("SELECT id, base_price FROM products WHERE is_active = 1");
    $product_ids = [];
    while ($row = mysqli_fetch_assoc($products_res)) {
        $product_ids[] = $row['id'];
    }
    if (!empty($product_ids)) {
        $current_year = date('Y');
        $start_month = date('Y-m-01');
        $end_month = date('Y-m-t');
        foreach ($product_ids as $pid) {
            $target = rand(50, 200);
            $db->query("INSERT INTO sales_targets (product_id, target_quantity, period_type, period_start, period_end, created_by)
                        VALUES ($pid, $target, 'monthly', '$start_month', '$end_month', {$_SESSION['user_id']})");
        }
        $year_start = $current_year . '-01-01';
        $year_end = $current_year . '-12-31';
        foreach ($product_ids as $pid) {
            $target = rand(500, 2000);
            $db->query("INSERT INTO sales_targets (product_id, target_quantity, period_type, period_start, period_end, created_by)
                        VALUES ($pid, $target, 'yearly', '$year_start', '$year_end', {$_SESSION['user_id']})");
        }
        error_log("Inserted sample sales targets.");
    }
}

// --- AJAX Request Handling ---
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    // Stock per Branch endpoints
    if (isset($_GET['action']) && $_GET['action'] === 'get_stock_by_branch') {
        $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        
        if (!$is_headquarters && $branch_id != $user_branch_id && $branch_id != 0) {
            echo json_encode(['success' => false, 'message' => 'Access denied. You can only view your own branch.']);
            exit;
        }
        
        $where = "";
        $params = [];
        $types = "";
        
        if (!$is_headquarters && $user_branch_id) {
            $where = " WHERE pa.branch_id = ?";
            $params[] = $user_branch_id;
            $types = "i";
        } elseif ($branch_id > 0) {
            $where = " WHERE pa.branch_id = ?";
            $params[] = $branch_id;
            $types = "i";
        }
        
        $sql = "SELECT 
                    p.id as product_id,
                    p.name as product_name,
                    p.base_price,
                    p.delivery_rate,
                    p.category,
                    pa.branch_id,
                    b.branch_code,
                    b.branch_name,
                    pa.quantity as stock_quantity,
                    CASE 
                        WHEN pa.quantity <= 0 THEN 'out_of_stock'
                        WHEN pa.quantity <= 5 THEN 'critical'
                        WHEN pa.quantity <= 10 THEN 'low'
                        ELSE 'in_stock'
                    END as stock_status
                FROM product_amount pa
                JOIN products p ON pa.product_id = p.id
                JOIN branches b ON pa.branch_id = b.id
                $where
                ORDER BY b.branch_name, p.name";
        
        $results = $db->preparedFetchAll($sql, $types, $params);
        echo json_encode(['success' => true, 'data' => $results, 'can_edit' => $can_edit, 'is_headquarters' => $is_headquarters, 'user_branch_id' => $user_branch_id]);
        exit;
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_stock') {
        if (!$can_edit) {
            echo json_encode(['success' => false, 'message' => 'You do not have permission to edit stock.']);
            exit;
        }
        
        $product_id = (int)($_POST['product_id'] ?? 0);
        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        
        if (!$is_headquarters && $branch_id != $user_branch_id) {
            echo json_encode(['success' => false, 'message' => 'You can only edit stock for your own branch.']);
            exit;
        }
        
        if ($product_id <= 0 || $branch_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid product or branch']);
            exit;
        }
        
        $existing = $db->preparedFetchOne("SELECT id FROM product_amount WHERE product_id = ? AND branch_id = ?", 'ii', [$product_id, $branch_id]);
        if ($existing) {
            $result = $db->preparedExecute("UPDATE product_amount SET quantity = ? WHERE product_id = ? AND branch_id = ?", 'iii', [$quantity, $product_id, $branch_id]);
        } else {
            $result = $db->preparedExecute("INSERT INTO product_amount (product_id, branch_id, quantity) VALUES (?, ?, ?)", 'iii', [$product_id, $branch_id, $quantity]);
        }
        
        if ($result) {
            logActivity($_SESSION['user_id'], "Updated stock for product ID $product_id at branch $branch_id to $quantity", 'inventory', $product_id);
            echo json_encode(['success' => true, 'message' => 'Stock updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }
    
    if (isset($_GET['action']) && $_GET['action'] === 'get_branches') {
        $branches = $db->preparedFetchAll("SELECT id, branch_code, branch_name FROM branches WHERE is_active = 1 ORDER BY branch_code", '', []);
        echo json_encode(['success' => true, 'data' => $branches]);
        exit;
    }
    
    // --- Sales Analysis Endpoints with Branch Filter ---
    $type = $_GET['type'] ?? '';
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date   = $_GET['end_date']   ?? date('Y-m-d');
    $period     = $_GET['period']     ?? 'daily';
    $product_ids = isset($_GET['product_ids']) ? array_filter(explode(',', $_GET['product_ids']), 'is_numeric') : [];
    $buyer_ids   = isset($_GET['buyer_ids'])   ? array_filter(explode(',', $_GET['buyer_ids']), 'is_numeric') : [];
    $categories  = isset($_GET['categories']) ? array_filter(explode(',', $_GET['categories'])) : [];
    $buyer_types = isset($_GET['buyer_types']) ? array_filter(explode(',', $_GET['buyer_types'])) : [];
    $compare_by  = $_GET['compare_by'] ?? 'product';
    $target_month = $_GET['target_month'] ?? date('m');
    $target_year  = $_GET['target_year']  ?? date('Y');
    $target_period_type = $_GET['target_period_type'] ?? 'monthly';
    
    // Get branch filter
    $branch_filter = isset($_GET['branch_filter']) ? (int)$_GET['branch_filter'] : 0;
    if (!$is_headquarters && $user_branch_id) {
        $branch_filter = $user_branch_id;
    }

    // Validate dates
    $start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : date('Y-m-d', strtotime('-30 days'));
    $end_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)   ? $end_date   : date('Y-m-d');
    if ($start_date > $end_date) list($start_date, $end_date) = [$end_date, $start_date];

    // Build WHERE clause with branch filter
    $where = "sr.sale_date BETWEEN '$start_date' AND '$end_date'";
    
    if ($branch_filter > 0) {
        $where .= " AND sr.branch_id = $branch_filter";
    }

    // Product filters
    if (!empty($categories)) {
        $escaped_cats = array();
        foreach ($categories as $cat) {
            $escaped_cats[] = $db->escape($cat);
        }
        $cats = implode("','", $escaped_cats);
        $where .= " AND p.category IN ('$cats')";
    } elseif (!empty($product_ids)) {
        $ids = implode(',', array_map('intval', $product_ids));
        $where .= " AND sr.product_id IN ($ids)";
    }

    // Buyer filters
    if (!empty($buyer_types)) {
        $escaped_types = array();
        foreach ($buyer_types as $bt) {
            $escaped_types[] = $db->escape($bt);
        }
        $types_list = implode("','", $escaped_types);
        $where .= " AND u.user_type IN ('$types_list')";
    } elseif (!empty($buyer_ids)) {
        $ids = implode(',', array_map('intval', $buyer_ids));
        $where .= " AND sr.customer_id IN ($ids)";
    }

    // --- Trends ---
    if ($type === 'trends') {
        $group_by = '';
        $date_format = '';
        switch ($period) {
            case 'daily':   $group_by = "DATE(sr.sale_date)"; $date_format = "%Y-%m-%d"; break;
            case 'weekly':  $group_by = "YEARWEEK(sr.sale_date, 1)"; $date_format = "%Y-%m-%d"; break;
            case 'monthly': $group_by = "DATE_FORMAT(sr.sale_date, '%Y-%m-01')"; $date_format = "%Y-%m"; break;
            case 'yearly':  $group_by = "YEAR(sr.sale_date)"; $date_format = "%Y"; break;
            default:        $group_by = "DATE(sr.sale_date)"; $date_format = "%Y-%m-%d";
        }
        $query = "
            SELECT 
                DATE_FORMAT(sr.sale_date, '$date_format') as date_label,
                SUM(sr.quantity_sold * sr.sale_price) as total_sales,
                COUNT(DISTINCT sr.id) as transaction_count,
                SUM(sr.quantity_sold) as total_quantity
            FROM sales_records sr
            LEFT JOIN products p ON sr.product_id = p.id
            LEFT JOIN bakery_users u ON sr.customer_id = u.id
            WHERE $where
            GROUP BY $group_by
            ORDER BY sr.sale_date ASC
        ";
        $result = $db->query($query);
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // --- Comparisons ---
    elseif ($type === 'comparisons') {
        if ($compare_by === 'product') {
            $query = "
                SELECT 
                    p.name as label,
                    SUM(sr.quantity_sold * sr.sale_price) as total_sales,
                    SUM(sr.quantity_sold) as total_quantity
                FROM sales_records sr
                JOIN products p ON sr.product_id = p.id
                LEFT JOIN bakery_users u ON sr.customer_id = u.id
                WHERE $where
                GROUP BY sr.product_id
                ORDER BY total_sales DESC
                LIMIT 20
            ";
        } else {
            $query = "
                SELECT 
                    u.fullname as label,
                    u.id as buyer_id,
                    SUM(sr.quantity_sold * sr.sale_price) as total_sales,
                    SUM(sr.quantity_sold) as total_quantity
                FROM sales_records sr
                JOIN bakery_users u ON sr.customer_id = u.id
                LEFT JOIN products p ON sr.product_id = p.id
                WHERE $where
                GROUP BY sr.customer_id
                ORDER BY total_sales DESC
                LIMIT 20
            ";
        }
        $result = $db->query($query);
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // --- Target Insights ---
    elseif ($type === 'target_insights') {
        if ($target_period_type === 'yearly') {
            $target_start = date('Y-01-01', strtotime("$target_year-01-01"));
            $target_end   = date('Y-12-31', strtotime("$target_year-12-31"));
            $target_condition = "st.period_start = '$target_start' AND st.period_end = '$target_end' AND st.period_type = 'yearly'";
        } else {
            $target_start = date('Y-m-01', strtotime("$target_year-$target_month-01"));
            $target_end   = date('Y-m-t', strtotime($target_start));
            $target_condition = "st.period_start = '$target_start' AND st.period_end = '$target_end' AND st.period_type = 'monthly'";
        }

        if (!empty($categories)) {
            $escaped_cats = array();
            foreach ($categories as $cat) {
                $escaped_cats[] = $db->escape($cat);
            }
            $cats = implode("','", $escaped_cats);
            $target_condition .= " AND p.category IN ('$cats')";
        } elseif (!empty($product_ids)) {
            $ids = implode(',', array_map('intval', $product_ids));
            $target_condition .= " AND st.product_id IN ($ids)";
        }
        
        $branch_sql = "";
        if ($branch_filter > 0) {
            $branch_sql = " AND sr.branch_id = $branch_filter";
        }

        $query = "
            SELECT 
                p.id as product_id,
                p.name as product_name,
                COALESCE(st.target_quantity, 0) as target_quantity,
                COALESCE(actual.actual_sold, 0) as actual_sold,
                CASE 
                    WHEN COALESCE(st.target_quantity, 0) > 0 
                    THEN ROUND((COALESCE(actual.actual_sold, 0) / st.target_quantity) * 100, 1)
                    ELSE 0
                END as percentage_achieved
            FROM products p
            LEFT JOIN sales_targets st ON p.id = st.product_id AND $target_condition
            LEFT JOIN (
                SELECT product_id, SUM(quantity_sold) as actual_sold
                FROM sales_records sr
                WHERE sr.sale_date BETWEEN '$target_start' AND '$target_end' $branch_sql
                GROUP BY product_id
            ) actual ON p.id = actual.product_id
            WHERE p.is_active = 1
            ORDER BY p.name
        ";
        $result = $db->query($query);
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Database error']);
            exit;
        }
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        exit;
    }

    // --- Update Target ---
    if (isset($_POST['update_target'])) {
        $product_id = intval($_POST['product_id']);
        $target_quantity = intval($_POST['target_quantity']);
        $period_type = $_POST['period_type'] ?? 'monthly';
        $target_month = isset($_POST['target_month']) ? intval($_POST['target_month']) : null;
        $target_year = intval($_POST['target_year']);

        if ($period_type === 'yearly') {
            $period_start = "$target_year-01-01";
            $period_end   = "$target_year-12-31";
        } else {
            $period_start = date('Y-m-01', strtotime("$target_year-$target_month-01"));
            $period_end   = date('Y-m-t', strtotime($period_start));
        }

        $check = $db->query("SELECT id FROM sales_targets 
                             WHERE product_id = $product_id 
                             AND period_start = '$period_start' 
                             AND period_type = '$period_type'");
        if ($check && mysqli_num_rows($check) > 0) {
            $row = mysqli_fetch_assoc($check);
            $update = $db->query("UPDATE sales_targets SET target_quantity = $target_quantity WHERE id = {$row['id']}");
        } else {
            $update = $db->query("INSERT INTO sales_targets (product_id, target_quantity, period_type, period_start, period_end, created_by) 
                                  VALUES ($product_id, $target_quantity, '$period_type', '$period_start', '$period_end', {$_SESSION['user_id']})");
        }
        if ($update) {
            echo json_encode(['success' => true, 'message' => 'Target updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request type']);
    exit;
}

// --- Initial Data for Filters ---
$all_products = [];
$prod_res = $db->query("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name");
if ($prod_res) {
    while ($row = mysqli_fetch_assoc($prod_res)) {
        $all_products[] = $row;
    }
}

$all_buyers = [];
$buyer_res = $db->query("SELECT id, fullname FROM bakery_users WHERE user_type IN ('customer','vendor') ORDER BY fullname");
if ($buyer_res) {
    while ($row = mysqli_fetch_assoc($buyer_res)) {
        $all_buyers[] = $row;
    }
}

$categories_list = [];
$cat_res = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
if ($cat_res) {
    while ($row = mysqli_fetch_assoc($cat_res)) {
        $categories_list[] = $row['category'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Sales Analysis · Fingerchops Ventures</title>
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="css/sales-analysis.css">
</head>
<body>
<div class="dashboard-container">
    <div class="main-content">
        <a href="../sales-dashboard.php" class="back-dashboard-btn">
            <i class="fas fa-home"></i> Dashboard
        </a>

        <div class="sidebar-toggle-btn" id="sidebarToggleBtn">
            <i class="fas fa-chevron-right"></i>
        </div>

        <div class="tabs">
            <div class="tab active" data-tab="trends">
                <i class="fas fa-chart-line"></i> Trends
            </div>
            <div class="tab" data-tab="comparisons">
                <i class="fas fa-chart-bar"></i> Comparisons
            </div>
            <div class="tab" data-tab="targets">
                <i class="fas fa-bullseye"></i> Target Insights
            </div>
            <div class="tab" data-tab="stock">
                <i class="fas fa-boxes"></i> Stock per Branch
            </div>
        </div>

        <div id="trends" class="tab-content active">
            <div class="chart-container">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        <div id="comparisons" class="tab-content">
            <div class="chart-container">
                <canvas id="barChart"></canvas>
            </div>
        </div>

        <div id="targets" class="tab-content">
            <div class="chart-container">
                <div class="period-toggle">
                    <button id="btnMonthly" class="active">Monthly</button>
                    <button id="btnYearly">Yearly</button>
                </div>
                <div id="monthlyControls">
                    <select id="targetMonth">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <select id="targetYear">
                        <?php $currentYear = date('Y'); for ($y = $currentYear-2; $y <= $currentYear+1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div id="yearlyControls" style="display: none;">
                    <select id="targetYearYearly">
                        <?php for ($y = $currentYear-2; $y <= $currentYear+1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $currentYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button id="loadTargetsBtn"><i class="fas fa-chart-simple"></i> Load Targets</button>
                <div id="targetTableContainer">
                    <p style="text-align:center; color:#64748b;">Select period and click "Load Targets".</p>
                </div>
                <div class="ai-insight" id="aiInsight">
                    <i class="fas fa-robot"></i> <strong>AI Insight</strong><br>
                    <span id="insightText">Click "Load Targets" to see performance analysis.</span>
                </div>
            </div>
        </div>
        
        <!-- Stock per Branch Tab -->
        <div id="stock" class="tab-content">
            <div class="chart-container">
                <h3><i class="fas fa-boxes"></i> Stock per Branch</h3>
                
                <div class="permission-note">
                    <i class="fas fa-info-circle"></i> 
                    <?php if ($is_headquarters): ?>
                        You have full access to all branches.
                    <?php elseif ($can_edit): ?>
                        You can edit stock for your branch.
                    <?php else: ?>
                        You have read-only access to your branch.
                    <?php endif; ?>
                </div>
                
                <?php if ($is_headquarters): ?>
                <div class="branch-filter">
                    <label>Select Branch:</label>
                    <select id="branchFilterSelect">
                        <option value="all">All Branches</option>
                        <?php foreach ($all_branches as $branch): ?>
                            <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="stock-table-container">
                    <table class="stock-table">
                        <thead>
                             <tr><th>Product</th><th>Category</th><th>Branch</th><th>Stock Quantity</th><th>Status</th><th>Base Price</th><th>Delivery Rate</th><?php if ($can_edit): ?><th>Actions</th><?php endif; ?></tr>
                        </thead>
                        <tbody id="stockTableBody">
                            <tr><td colspan="7" style="text-align: center;">Loading stock data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="analysis-sidebar" id="sidebar">
        <div class="sidebar-inner">
            <h3><i class="fas fa-chart-line"></i> Analysis Controls</h3>
            
            <?php if ($is_headquarters): ?>
            <div class="control-group">
                <label><i class="fas fa-store"></i> Branch Filter</label>
                <select id="branchFilter">
                    <option value="0">All Branches</option>
                    <?php foreach ($all_branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="control-group">
                <label><i class="fas fa-calendar-alt"></i> Date Range</label>
                <div class="date-range">
                    <input type="date" id="startDate" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
                    <input type="date" id="endDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <div class="control-group">
                <label><i class="fas fa-clock"></i> Granularity (Trends)</label>
                <select id="period">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                </select>
            </div>

            <div class="control-group">
                <label><i class="fas fa-tag"></i> Compare by (Bar Chart)</label>
                <select id="compareBy">
                    <option value="product">Products</option>
                    <option value="buyer">Buyers (Customers/Vendors)</option>
                </select>
            </div>

            <div class="customize-toggle">
                <button id="customizeToggleBtn">
                    <span><i class="fas fa-sliders-h"></i> Customize Insight</span>
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>

            <div id="customizePanel" class="customize-panel">
                <div class="sub-tabs">
                    <div class="sub-tab active" data-subtab="products"><i class="fas fa-cake-candles"></i> Products</div>
                    <div class="sub-tab" data-subtab="buyers"><i class="fas fa-users"></i> Buyers</div>
                </div>

                <div id="products-subtab" class="sub-tab-content active">
                    <div class="selection-mode">
                        <label><input type="radio" name="product_mode" value="all" checked> <i class="fas fa-check-circle"></i> All Products</label>
                        <label><input type="radio" name="product_mode" value="category"> <i class="fas fa-tags"></i> Select by Category</label>
                        <label><input type="radio" name="product_mode" value="selected"> <i class="fas fa-list-ul"></i> Select Individual</label>
                    </div>
                    <div id="productCategoryGroup" class="category-select" style="display: none;">
                        <div class="checkbox-group">
                            <?php foreach ($categories_list as $cat): ?>
                                <label><input type="checkbox" class="category-checkbox" value="<?php echo htmlspecialchars($cat); ?>"> <?php echo htmlspecialchars($cat); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Select one or more categories</small>
                    </div>
                    <div id="productCheckboxGroup" class="checkbox-group" style="display: none;">
                        <?php foreach ($all_products as $p): ?>
                            <label><input type="checkbox" value="<?php echo $p['id']; ?>"> <?php echo htmlspecialchars($p['name']); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div id="buyers-subtab" class="sub-tab-content">
                    <div class="selection-mode">
                        <label><input type="radio" name="buyer_mode" value="roles" checked> <i class="fas fa-tags"></i> Select by Roles</label>
                        <label><input type="radio" name="buyer_mode" value="selected"> <i class="fas fa-list-ul"></i> Select Individual</label>
                    </div>
                    <div id="buyerRolesGroup" class="role-select" style="display: block;">
                        <div class="checkbox-group">
                            <label><input type="checkbox" class="role-checkbox" value="customer"> <i class="fas fa-user"></i> Customers</label>
                            <label><input type="checkbox" class="role-checkbox" value="vendor"> <i class="fas fa-store"></i> Vendors</label>
                            <label><input type="checkbox" class="role-checkbox" value="staff"> <i class="fas fa-user-tie"></i> Staff</label>
                        </div>
                        <small class="text-muted">Check one or more roles (none means all)</small>
                    </div>
                    <div id="buyerCheckboxGroup" class="checkbox-group" style="display: none;">
                        <?php foreach ($all_buyers as $b): ?>
                            <label><input type="checkbox" value="<?php echo $b['id']; ?>"> <?php echo htmlspecialchars($b['fullname']); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <button id="applyBtn"><i class="fas fa-filter"></i> Apply Filters</button>
        </div>
    </div>
</div>

<script>
// =====================================================
// SALES ANALYSIS JAVASCRIPT
// =====================================================

let currentStockData = [];
let editingRow = null;
let lineChart, barChart;
let currentTab = 'trends';
let resizeObserver = null;
let currentPeriodType = 'monthly';

// Helper functions
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}<span style="float:right; cursor:pointer;" onclick="this.parentElement.remove()">×</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function getSelectedValues(containerId) {
    const checkboxes = document.querySelectorAll(`#${containerId} input[type="checkbox"]:checked`);
    return Array.from(checkboxes).map(cb => cb.value);
}

function getFilters() {
    const productMode = document.querySelector('input[name="product_mode"]:checked')?.value;
    let product_ids = [];
    let categories = [];
    if (productMode === 'selected') {
        product_ids = getSelectedValues('productCheckboxGroup');
    } else if (productMode === 'category') {
        const catCheckboxes = document.querySelectorAll('#productCategoryGroup .category-checkbox:checked');
        categories = Array.from(catCheckboxes).map(cb => cb.value);
    }

    const buyerMode = document.querySelector('input[name="buyer_mode"]:checked')?.value;
    let buyer_ids = [];
    let buyer_types = [];
    if (buyerMode === 'selected') {
        buyer_ids = getSelectedValues('buyerCheckboxGroup');
    } else if (buyerMode === 'roles') {
        const roleCheckboxes = document.querySelectorAll('.role-checkbox:checked');
        buyer_types = Array.from(roleCheckboxes).map(cb => cb.value);
    }

    const branchFilter = document.getElementById('branchFilter')?.value || 0;

    return {
        start_date: document.getElementById('startDate').value,
        end_date: document.getElementById('endDate').value,
        period: document.getElementById('period').value,
        product_ids: product_ids,
        categories: categories,
        buyer_ids: buyer_ids,
        buyer_types: buyer_types,
        compare_by: document.getElementById('compareBy').value,
        branch_filter: branchFilter
    };
}

async function fetchData(type, extraParams = {}) {
    const filters = getFilters();
    const params = new URLSearchParams({
        ajax: 1,
        type: type,
        start_date: filters.start_date,
        end_date: filters.end_date,
        period: filters.period,
        product_ids: filters.product_ids.join(','),
        categories: filters.categories.join(','),
        buyer_ids: filters.buyer_ids.join(','),
        buyer_types: filters.buyer_types.join(','),
        compare_by: filters.compare_by,
        branch_filter: filters.branch_filter,
        ...extraParams
    });
    const response = await fetch(window.location.href + '?' + params.toString());
    return await response.json();
}

function forceChartResize() {
    setTimeout(() => {
        if (lineChart) lineChart.resize();
        if (barChart) barChart.resize();
        forceTargetTableResize();
    }, 300);
}

function forceTargetTableResize() {
    const targetContainer = document.getElementById('targetTableContainer');
    if (targetContainer && currentTab === 'targets') {
        const originalDisplay = targetContainer.style.display;
        targetContainer.style.display = 'none';
        void targetContainer.offsetHeight;
        targetContainer.style.display = originalDisplay;
        const table = targetContainer.querySelector('.target-table');
        if (table) table.style.width = '100%';
    }
}

async function loadTrends() {
    const data = await fetchData('trends');
    const ctx = document.getElementById('lineChart').getContext('2d');
    if (data.success && data.data && data.data.length) {
        const labels = data.data.map(item => item.date_label);
        const sales = data.data.map(item => parseFloat(item.total_sales));
        const quantities = data.data.map(item => parseInt(item.total_quantity));

        if (lineChart) lineChart.destroy();
        lineChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total Sales (₦)',
                        data: sales,
                        borderColor: '#4f46e5',
                        backgroundColor: 'rgba(79, 70, 229, 0.1)',
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Units Sold',
                        data: quantities,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { title: { display: true, text: 'Sales (₦)' }, beginAtZero: true },
                    y1: { position: 'right', title: { display: true, text: 'Units Sold' }, beginAtZero: true }
                }
            }
        });
    } else {
        if (lineChart) lineChart.destroy();
        ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
        ctx.font = '14px Inter';
        ctx.fillStyle = '#94a3b8';
        ctx.textAlign = 'center';
        ctx.fillText('No data available for the selected filters.', ctx.canvas.width / 2, ctx.canvas.height / 2);
    }
}

async function loadComparisons() {
    const data = await fetchData('comparisons');
    const ctx = document.getElementById('barChart').getContext('2d');
    if (data.success && data.data && data.data.length) {
        const labels = data.data.map(item => item.label);
        const sales = data.data.map(item => parseFloat(item.total_sales));

        if (barChart) barChart.destroy();
        barChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Total Sales (₦)',
                    data: sales,
                    backgroundColor: 'rgba(79, 70, 229, 0.7)',
                    borderColor: '#4f46e5',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Sales (₦)' } } }
            }
        });
    } else {
        if (barChart) barChart.destroy();
        ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
        ctx.font = '14px Inter';
        ctx.fillStyle = '#94a3b8';
        ctx.textAlign = 'center';
        ctx.fillText('No data available for the selected filters.', ctx.canvas.width / 2, ctx.canvas.height / 2);
    }
}

async function loadTargetInsights() {
    const loadBtn = document.getElementById('loadTargetsBtn');
    if (!loadBtn) return;
    const originalText = loadBtn.innerHTML;
    loadBtn.innerHTML = '<span class="spinner"></span> Loading...';
    loadBtn.disabled = true;
    try {
        const filters = getFilters();
        let month, year, periodType = currentPeriodType;
        if (periodType === 'yearly') {
            year = document.getElementById('targetYearYearly').value;
            month = null;
        } else {
            month = document.getElementById('targetMonth').value;
            year = document.getElementById('targetYear').value;
        }
        const params = {
            target_month: month,
            target_year: year,
            target_period_type: periodType,
            product_ids: filters.product_ids.join(',')
        };
        const data = await fetchData('target_insights', params);
        const container = document.getElementById('targetTableContainer');
        const insightSpan = document.getElementById('insightText');

        if (data.success && data.data.length) {
            let html = `<div style="overflow-x: auto; width: 100%;">
                            <table class="target-table">
                                <thead>
                                    <tr><th>Product</th><th>Target Qty</th><th>Actual Sold</th><th>% Achieved</th><th>Progress</th><th>Actions</th></tr>
                                </thead>
                                <tbody>`;
            let totalAchieved = 0, countWithTarget = 0;
            data.data.forEach(row => {
                const percent = row.percentage_achieved;
                const progressWidth = Math.min(percent, 100);
                html += `
                    <tr>
                        <td>${escapeHtml(row.product_name)}</td>
                        <td>${row.target_quantity}</td>
                        <td>${row.actual_sold}</td>
                        <td>${percent}%</td>
                        <td><div class="progress-bar"><div class="progress-fill" style="width: ${progressWidth}%"></div></div></td>
                        <td><button class="edit-target-btn" data-product-id="${row.product_id}" data-target="${row.target_quantity}" data-period-type="${periodType}" data-period-identifier="${periodType === 'yearly' ? year : year + '-' + month}"><i class="fas fa-edit"></i> Edit</button></td>
                    </tr>`;
                if (row.target_quantity > 0) {
                    totalAchieved += percent;
                    countWithTarget++;
                }
            });
            html += `</tbody></table></div>`;
            container.innerHTML = html;
            let avgAchieved = countWithTarget > 0 ? (totalAchieved / countWithTarget).toFixed(1) : 'No targets set';
            let insightMsg = countWithTarget > 0 
                ? `Average target achievement: ${avgAchieved}%. `
                : 'No targets set for this period. ';
            if (avgAchieved < 50) insightMsg += 'Consider reviewing underperforming products.';
            else if (avgAchieved < 80) insightMsg += 'Good, but there is room for improvement.';
            else if (avgAchieved !== 'No targets set') insightMsg += 'Excellent performance! Keep up the momentum.';
            insightSpan.textContent = insightMsg;
            
            document.querySelectorAll('.edit-target-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const productId = btn.dataset.productId;
                    const currentTarget = btn.dataset.target;
                    const periodType = btn.dataset.periodType;
                    const periodIdentifier = btn.dataset.periodIdentifier;
                    openTargetEditModal(productId, currentTarget, periodType, periodIdentifier);
                });
            });
            forceTargetTableResize();
        } else {
            container.innerHTML = '<p style="text-align:center; color:#64748b;">No target data available for the selected period and filters.</p>';
            insightSpan.textContent = 'No data to analyze. Adjust filters or create sales targets.';
        }
    } finally {
        loadBtn.innerHTML = originalText;
        loadBtn.disabled = false;
    }
}

function openTargetEditModal(productId, currentTarget, periodType, periodIdentifier) {
    const existingModal = document.querySelector('.target-edit-modal');
    if (existingModal) existingModal.remove();
    
    const modal = document.createElement('div');
    modal.className = 'modal target-edit-modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Target</h2>
                <button class="modal-close" onclick="this.closest('.modal').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" class="edit-product-id" value="${productId}">
                <input type="hidden" class="edit-period-type" value="${periodType}">
                <input type="hidden" class="edit-period-identifier" value="${periodIdentifier}">
                <div class="form-group">
                    <label>Target Quantity</label>
                    <input type="number" class="edit-target-value" min="0" step="1" value="${currentTarget}">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary cancel-btn">Cancel</button>
                <button class="btn btn-primary save-btn">Save Changes</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    modal.classList.add('show');
    
    const cancelBtn = modal.querySelector('.cancel-btn');
    cancelBtn.addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
    
    const saveBtn = modal.querySelector('.save-btn');
    saveBtn.addEventListener('click', () => {
        const productId = modal.querySelector('.edit-product-id').value;
        const newTarget = modal.querySelector('.edit-target-value').value;
        const periodType = modal.querySelector('.edit-period-type').value;
        const periodIdentifier = modal.querySelector('.edit-period-identifier').value;
        
        if (!productId || newTarget === undefined) return;
        let targetMonth = null, targetYear = null;
        if (periodType === 'yearly') {
            targetYear = periodIdentifier;
        } else {
            const parts = periodIdentifier.split('-');
            targetYear = parts[0];
            targetMonth = parts[1];
        }
        
        const formData = new FormData();
        formData.append('update_target', true);
        formData.append('product_id', productId);
        formData.append('target_quantity', newTarget);
        formData.append('period_type', periodType);
        if (targetMonth) formData.append('target_month', targetMonth);
        if (targetYear) formData.append('target_year', targetYear);
        
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner"></span> Saving...';
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Target updated successfully');
                    modal.remove();
                    loadTargetInsights();
                } else {
                    alert(data.message || 'Error updating target');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Save Changes';
                }
            })
            .catch(err => {
                alert('Network error');
                saveBtn.disabled = false;
                saveBtn.innerHTML = 'Save Changes';
            });
    });
}

async function applyFilters() {
    const applyBtn = document.getElementById('applyBtn');
    if (!applyBtn) return;
    const originalText = applyBtn.innerHTML;
    applyBtn.innerHTML = '<span class="spinner"></span> Loading...';
    applyBtn.disabled = true;
    try {
        if (currentTab === 'trends') await loadTrends();
        else if (currentTab === 'comparisons') await loadComparisons();
        else if (currentTab === 'targets') await loadTargetInsights();
    } finally {
        applyBtn.innerHTML = originalText;
        applyBtn.disabled = false;
        forceChartResize();
    }
}

function switchTab(tabId) {
    currentTab = tabId;
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.tab[data-tab="${tabId}"]`).classList.add('active');
    
    if (tabId === 'trends') loadTrends();
    else if (tabId === 'comparisons') loadComparisons();
    else if (tabId === 'targets') loadTargetInsights();
    else if (tabId === 'stock') loadStockData();
    forceChartResize();
}

function setPeriodType(type) {
    currentPeriodType = type;
    const monthlyBtn = document.getElementById('btnMonthly');
    const yearlyBtn = document.getElementById('btnYearly');
    const monthlyControls = document.getElementById('monthlyControls');
    const yearlyControls = document.getElementById('yearlyControls');
    if (type === 'monthly') {
        monthlyBtn.classList.add('active');
        yearlyBtn.classList.remove('active');
        monthlyControls.style.display = 'flex';
        yearlyControls.style.display = 'none';
    } else {
        monthlyBtn.classList.remove('active');
        yearlyBtn.classList.add('active');
        monthlyControls.style.display = 'none';
        yearlyControls.style.display = 'flex';
    }
}

// Stock per Branch Functions
async function loadBranches() {
    <?php if ($is_headquarters): ?>
    try {
        const response = await fetch(window.location.href + '?ajax=1&action=get_branches');
        const data = await response.json();
        if (data.success && data.data) {
            const select = document.getElementById('branchFilterSelect');
            if (select) {
                select.innerHTML = '<option value="all">All Branches</option>';
                data.data.forEach(branch => {
                    const option = document.createElement('option');
                    option.value = branch.id;
                    option.textContent = branch.branch_name + ' (' + branch.branch_code + ')';
                    select.appendChild(option);
                });
                select.addEventListener('change', loadStockData);
            }
        }
    } catch (error) {
        console.error('Error loading branches:', error);
    }
    <?php endif; ?>
}

async function loadStockData() {
    const tbody = document.getElementById('stockTableBody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;"><span class="spinner"></span> Loading...</td></tr>';
    
    let url = window.location.href + '?ajax=1&action=get_stock_by_branch';
    <?php if ($is_headquarters): ?>
    const branchFilter = document.getElementById('branchFilterSelect')?.value;
    if (branchFilter && branchFilter !== 'all') {
        url += '&branch_id=' + branchFilter;
    }
    <?php endif; ?>
    
    try {
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success && data.data) {
            currentStockData = data.data;
            renderStockTable(data.data, data.can_edit);
        } else {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Error loading stock data: ' + (data.message || 'Unknown error') + '</td></tr>';
        }
    } catch (error) {
        console.error('Error loading stock data:', error);
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">Error loading stock data</td></tr>';
    }
}

function getStockStatusBadge(status, quantity) {
    const statusConfig = {
        'in_stock': { class: 'in_stock', icon: 'fa-check-circle', text: 'In Stock' + (quantity ? ' (' + quantity + ')' : '') },
        'low': { class: 'low', icon: 'fa-exclamation-triangle', text: 'Low Stock (' + quantity + ')' },
        'critical': { class: 'critical', icon: 'fa-exclamation-circle', text: 'Critical (' + quantity + ')' },
        'out_of_stock': { class: 'out_of_stock', icon: 'fa-times-circle', text: 'Out of Stock' }
    };
    const config = statusConfig[status] || statusConfig['out_of_stock'];
    return `<span class="stock-badge ${config.class}"><i class="fas ${config.icon}"></i> ${config.text}</span>`;
}

function startEditStock(productId, branchId, currentQuantity) {
    if (editingRow) cancelEdit();
    
    const row = document.querySelector(`tr[data-product-id="${productId}"][data-branch-id="${branchId}"]`);
    if (!row) return;
    
    const actionsCell = row.querySelector('.actions-cell');
    const quantityCell = row.querySelector('.quantity-cell');
    
    if (!actionsCell || !quantityCell) return;
    
    const originalHtml = quantityCell.innerHTML;
    const originalActions = actionsCell.innerHTML;
    
    quantityCell.innerHTML = `
        <div class="inline-edit-form">
            <input type="number" id="edit-quantity-${productId}" value="${currentQuantity}" min="0" step="1">
            <button class="save-edit" onclick="saveEditStock(${productId}, ${branchId})"><i class="fas fa-save"></i></button>
            <button class="cancel" onclick="cancelEdit()"><i class="fas fa-times"></i></button>
        </div>
    `;
    
    actionsCell.innerHTML = '<span style="color: #94a3b8;">Editing...</span>';
    editingRow = { productId, branchId, originalHtml, originalActions };
}

async function saveEditStock(productId, branchId) {
    const input = document.getElementById(`edit-quantity-${productId}`);
    const newQuantity = parseInt(input.value);
    
    if (isNaN(newQuantity)) {
        alert('Please enter a valid number');
        return;
    }
    
    const formData = new FormData();
    formData.append('ajax', 1);
    formData.append('action', 'update_stock');
    formData.append('product_id', productId);
    formData.append('branch_id', branchId);
    formData.append('quantity', newQuantity);
    
    const saveBtn = document.querySelector(`#edit-quantity-${productId}`).parentElement.querySelector('.save-edit');
    saveBtn.innerHTML = '<span class="spinner"></span>';
    saveBtn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast(data.message, 'success');
            loadStockData();
        } else {
            alert(data.message);
        }
    } catch (error) {
        console.error('Error updating stock:', error);
        alert('Network error');
    } finally {
        cancelEdit();
    }
}

function cancelEdit() {
    if (editingRow) {
        const row = document.querySelector(`tr[data-product-id="${editingRow.productId}"][data-branch-id="${editingRow.branchId}"]`);
        if (row) {
            const quantityCell = row.querySelector('.quantity-cell');
            const actionsCell = row.querySelector('.actions-cell');
            if (quantityCell) quantityCell.innerHTML = editingRow.originalHtml;
            if (actionsCell) actionsCell.innerHTML = editingRow.originalActions;
        }
        editingRow = null;
    }
}

function renderStockTable(data, canEdit) {
    const tbody = document.getElementById('stockTableBody');
    if (!data.length) {
        tbody.innerHTML = '英语<td colspan="7" style="text-align: center;">No stock data available</td> </tbody>';
        return;
    }
    
    tbody.innerHTML = '';
    data.forEach(item => {
        const row = document.createElement('tr');
        row.setAttribute('data-product-id', item.product_id);
        row.setAttribute('data-branch-id', item.branch_id);
        
        let actionsHtml = '';
        if (canEdit) {
            actionsHtml = `<td class="actions-cell"><button class="edit-stock-btn" onclick="startEditStock(${item.product_id}, ${item.branch_id}, ${item.stock_quantity})"><i class="fas fa-edit"></i> Edit</button> </td>`;
        }
        
        row.innerHTML = `
             <td>${escapeHtml(item.product_name)}</td>
             <td>${escapeHtml(item.category || '-')}</td>
             <td>${escapeHtml(item.branch_name)} (${escapeHtml(item.branch_code)})</td>
            <td class="quantity-cell">${item.stock_quantity}</td>
             <td>${getStockStatusBadge(item.stock_status, item.stock_quantity)}</td>
             <td>₦${parseFloat(item.base_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
             <td>₦${parseFloat(item.delivery_rate).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
            ${actionsHtml}
        `;
        tbody.appendChild(row);
    });
}

// Sidebar logic
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const toggleBtn = document.getElementById('sidebarToggleBtn');

function isMobile() { return window.innerWidth <= 768; }

function updateSidebarIcon() {
    if (!toggleBtn) return;
    const icon = toggleBtn.querySelector('i');
    if (isMobile()) {
        if (sidebar.classList.contains('open')) {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-left');
        } else {
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
        }
    } else {
        if (sidebar.classList.contains('collapsed')) {
            icon.classList.remove('fa-chevron-right');
            icon.classList.add('fa-chevron-left');
        } else {
            icon.classList.remove('fa-chevron-left');
            icon.classList.add('fa-chevron-right');
        }
    }
}

function toggleSidebar() {
    if (isMobile()) {
        sidebar.classList.toggle('open');
        if (sidebar.classList.contains('open')) {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            document.body.classList.add('sidebar-open');
        } else {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            document.body.classList.remove('sidebar-open');
        }
    } else {
        sidebar.classList.toggle('collapsed');
        setTimeout(() => forceChartResize(), 300);
    }
    updateSidebarIcon();
}

function closeSidebar() {
    if (isMobile()) {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        document.body.classList.remove('sidebar-open');
        updateSidebarIcon();
    }
}

function initSidebar() {
    if (isMobile()) {
        sidebar.classList.remove('collapsed');
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        document.body.classList.remove('sidebar-open');
    } else {
        sidebar.classList.remove('open');
        sidebar.classList.remove('collapsed');
    }
    updateSidebarIcon();
}

if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
if (overlay) overlay.addEventListener('click', closeSidebar);

let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
        if (isMobile()) {
            if (sidebar.classList.contains('collapsed')) sidebar.classList.remove('collapsed');
            if (!sidebar.classList.contains('open')) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        } else {
            if (sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        updateSidebarIcon();
        forceChartResize();
    }, 150);
});

// Customize panel toggle
const customizeBtn = document.getElementById('customizeToggleBtn');
const customizePanel = document.getElementById('customizePanel');
if (customizeBtn && customizePanel) {
    customizeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        customizePanel.classList.toggle('open');
        const icon = customizeBtn.querySelector('i:last-child');
        if (customizePanel.classList.contains('open')) {
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        } else {
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    });
}

// Sub-tab switching
document.querySelectorAll('.sub-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        const subtab = tab.dataset.subtab;
        document.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        document.querySelectorAll('.sub-tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(`${subtab}-subtab`).classList.add('active');
    });
});

function updateProductCheckboxVisibility() {
    const mode = document.querySelector('input[name="product_mode"]:checked')?.value;
    const group = document.getElementById('productCheckboxGroup');
    const catGroup = document.getElementById('productCategoryGroup');
    if (group) group.style.display = mode === 'selected' ? 'block' : 'none';
    if (catGroup) catGroup.style.display = mode === 'category' ? 'block' : 'none';
}

function updateBuyerPanelVisibility() {
    const mode = document.querySelector('input[name="buyer_mode"]:checked')?.value;
    const rolesGroup = document.getElementById('buyerRolesGroup');
    const individualGroup = document.getElementById('buyerCheckboxGroup');
    if (rolesGroup) rolesGroup.style.display = mode === 'roles' ? 'block' : 'none';
    if (individualGroup) individualGroup.style.display = mode === 'selected' ? 'block' : 'none';
}

const productRadios = document.querySelectorAll('input[name="product_mode"]');
const buyerRadios = document.querySelectorAll('input[name="buyer_mode"]');
productRadios.forEach(radio => radio.addEventListener('change', updateProductCheckboxVisibility));
buyerRadios.forEach(radio => radio.addEventListener('change', updateBuyerPanelVisibility));
updateProductCheckboxVisibility();
updateBuyerPanelVisibility();

// Main buttons
document.getElementById('applyBtn')?.addEventListener('click', applyFilters);
document.getElementById('loadTargetsBtn')?.addEventListener('click', loadTargetInsights);
document.querySelectorAll('.tab').forEach(tab => tab.addEventListener('click', () => switchTab(tab.dataset.tab)));

// Period toggle buttons
document.getElementById('btnMonthly')?.addEventListener('click', () => setPeriodType('monthly'));
document.getElementById('btnYearly')?.addEventListener('click', () => setPeriodType('yearly'));

// Initialize
initSidebar();
loadTrends();

if (window.ResizeObserver) {
    resizeObserver = new ResizeObserver(() => forceChartResize());
    document.querySelectorAll('.chart-container').forEach(container => resizeObserver.observe(container));
}

// Initialize Stock tab
document.addEventListener('DOMContentLoaded', function() {
    loadBranches();
    loadStockData();
});

// Add spinner style if not present
if (!document.querySelector('#spinner-style')) {
    const style = document.createElement('style');
    style.id = 'spinner-style';
    style.textContent = `
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
}
</script>
</body>
</html>