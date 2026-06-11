<?php
// admin_power/products.php - Product management with discounts, targets, and filters
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once '../../../conn.php';
require_once '../../../includes/User.php';
require_once '../../../includes/Security.php';
require_once '../../../config/config_loader.php';

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
$privilege_level = $userObj->getPrivilegeLevel();
$minAdminLevel = setting('admin_privilege_level', 100);
if ($privilege_level < $minAdminLevel && !$userObj->hasPermission($_SESSION['user_id'], 'can_manage_system')) {
    header('Location: ../admin-dashboard.php?error=permission');
    exit;
}

// Handle AJAX
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if ($action === 'save_discounts') {
        $product_id = intval($_POST['product_id']);
        $new_discounts = json_decode($_POST['discounts'], true);
        if (!is_array($new_discounts)) {
            $response['message'] = 'Invalid data';
            echo json_encode($response);
            exit;
        }
        
        // Get existing discounts for this product
        $existing = [];
        $res = $db->query("SELECT id, role_code, role_name, discount_percent FROM product_role_discounts WHERE product_id = $product_id");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $existing[] = $row;
            }
        }
        
        $db->beginTransaction();
        $success = true;
        
        // Process new discounts (upsert)
        foreach ($new_discounts as $role_code => $role_names) {
            foreach ($role_names as $role_name => $percent) {
                $percent = floatval($percent);
                if ($percent < 0 || $percent > 100) continue;
                if ($percent == 0) {
                    // Delete if exists
                    $db->query("DELETE FROM product_role_discounts WHERE product_id = $product_id AND role_code = '$role_code' AND role_name = '$role_name'");
                } else {
                    $check = $db->query("SELECT id FROM product_role_discounts WHERE product_id = $product_id AND role_code = '$role_code' AND role_name = '$role_name'");
                    if ($check && mysqli_num_rows($check) > 0) {
                        $update = $db->query("UPDATE product_role_discounts SET discount_percent = $percent WHERE product_id = $product_id AND role_code = '$role_code' AND role_name = '$role_name'");
                        if (!$update) $success = false;
                    } else {
                        $insert = $db->query("INSERT INTO product_role_discounts (product_id, role_code, role_name, discount_percent) VALUES ($product_id, '$role_code', '$role_name', $percent)");
                        if (!$insert) $success = false;
                    }
                }
            }
        }
        
        // Delete any existing discounts that are not in new_discounts
        $new_set = [];
        foreach ($new_discounts as $role_code => $role_names) {
            foreach ($role_names as $role_name => $percent) {
                $new_set["$role_code|$role_name"] = true;
            }
        }
        foreach ($existing as $row) {
            $key = $row['role_code'] . '|' . $row['role_name'];
            if (!isset($new_set[$key])) {
                $delete = $db->query("DELETE FROM product_role_discounts WHERE id = {$row['id']}");
                if (!$delete) $success = false;
            }
        }
        
        if ($success) {
            $db->commit();
            logActivity($_SESSION['user_id'], "Updated role name discounts for product ID $product_id", 'product', $product_id);
            $response = ['success' => true, 'message' => 'Discounts saved'];
        } else {
            $db->rollback();
            $response['message'] = 'Database error';
        }
        echo json_encode($response);
        exit;
    }
    
    if ($action === 'update_target') {
        $product_id = intval($_POST['product_id']);
        $target_quantity = intval($_POST['target_quantity']);
        $period_start = date('Y-m-01');
        $period_end = date('Y-m-t');
        $check = $db->query("SELECT id FROM sales_targets WHERE product_id = $product_id AND period_start = '$period_start' AND period_type = 'monthly'");
        if ($check && mysqli_num_rows($check) > 0) {
            $row = mysqli_fetch_assoc($check);
            $update = $db->query("UPDATE sales_targets SET target_quantity = $target_quantity WHERE id = {$row['id']}");
        } else {
            $update = $db->query("INSERT INTO sales_targets (product_id, target_quantity, period_type, period_start, period_end, created_by) VALUES ($product_id, $target_quantity, 'monthly', '$period_start', '$period_end', {$_SESSION['user_id']})");
        }
        if ($update) {
            $response = ['success' => true, 'message' => 'Target updated'];
        } else {
            $response['message'] = 'Database error';
        }
        echo json_encode($response);
        exit;
    }
    
    echo json_encode($response);
    exit;
}

// Fetch ALL role codes and their role names
$role_names = [];
$role_res = $db->query("SELECT role_code, role_name FROM roles ORDER BY role_code, role_name");
if ($role_res) {
    while ($row = mysqli_fetch_assoc($role_res)) {
        $role_names[$row['role_code']][] = $row['role_name'];
    }
}

// Fetch all products with targets and discounts
$products = [];
$query = "
    SELECT 
        p.id, p.name, p.description, p.base_price, p.category, p.stock_quantity, p.is_active,
        COALESCE(st.target_quantity, 0) as target_quantity,
        COALESCE(actual.actual_sold, 0) as actual_sold,
        CASE 
            WHEN COALESCE(st.target_quantity, 0) > 0 
            THEN ROUND((COALESCE(actual.actual_sold, 0) / st.target_quantity) * 100, 1)
            ELSE 0
        END as percentage_achieved
    FROM products p
    LEFT JOIN sales_targets st ON p.id = st.product_id 
        AND st.period_start <= CURDATE() 
        AND st.period_end >= CURDATE()
        AND st.period_type = 'monthly'
    LEFT JOIN (
        SELECT product_id, SUM(quantity_sold) as actual_sold
        FROM sales_records
        WHERE sale_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        GROUP BY product_id
    ) actual ON p.id = actual.product_id
    ORDER BY p.name
";
$result = $db->query($query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $discounts = [];
        $discount_res = $db->query("SELECT role_code, role_name, discount_percent FROM product_role_discounts WHERE product_id = {$row['id']}");
        if ($discount_res) {
            while ($d = mysqli_fetch_assoc($discount_res)) {
                $discounts[$d['role_code']][$d['role_name']] = $d['discount_percent'];
            }
        }
        $row['discounts'] = $discounts;
        $products[] = $row;
    }
}

// Get categories
$categories = [];
$cat_res = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category");
if ($cat_res) {
    while ($row = mysqli_fetch_assoc($cat_res)) {
        $categories[] = $row['category'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management · Admin</title>
    <link rel="icon" href="../../../logo.jpeg" type="image/jpeg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../css/admin-dashboard.css">
    <link rel="stylesheet" href="css/products.css">
</head>
<body>
<div class="dashboard-container">
    <div class="admin-header">
        <div class="header-top">
            <div class="header-title">
                <h1><i class="fas fa-box"></i> Product Management</h1>
                <p>Manage products, set monthly targets, and define role‑based discounts</p>
            </div>
            <div class="header-actions">
                <a href="../admin-dashboard.php" class="logout-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="filters-bar">
        <div class="filter-group">
            <label><i class="fas fa-search"></i></label>
            <input type="text" id="searchName" placeholder="Search by name..." class="filter-input">
        </div>
        <div class="filter-group">
            <label><i class="fas fa-tags"></i></label>
            <select id="filterCategory" multiple size="1" class="filter-select" style="height: auto; min-width: 150px;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-sort"></i></label>
            <select id="sortBy" class="filter-select">
                <option value="name_asc">Name A-Z</option>
                <option value="name_desc">Name Z-A</option>
                <option value="price_asc">Price Low-High</option>
                <option value="price_desc">Price High-Low</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-chart-line"></i></label>
            <select id="filterPerformance" class="filter-select">
                <option value="">All Performance</option>
                <option value="high">≥ 80%</option>
                <option value="medium">50% - 79%</option>
                <option value="low">&lt; 50%</option>
                <option value="no-target">No target set</option>
            </select>
        </div>
        <button id="resetFilters" class="btn-sm btn-outline">Reset</button>
    </div>

    <div id="productsGrid" class="products-grid"></div>
</div>

<!-- Discount Modal -->
<div id="discountModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
            <h3>Set Role Discounts</h3>
            <button class="modal-close" onclick="closeDiscountModal()">&times;</button>
        </div>
        <div id="discountModalBody"></div>
        <div class="modal-footer" style="margin-top:1rem; text-align:right;">
            <button class="btn-sm btn-outline" onclick="closeDiscountModal()">Cancel</button>
            <button class="btn-sm btn-primary" id="saveDiscountsBtn">Save Discounts</button>
        </div>
    </div>
</div>

<!-- Target Edit Modal -->
<div class="modal" id="targetModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Monthly Target</h3>
            <button class="modal-close" onclick="closeTargetModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="targetProductId">
            <div class="form-group">
                <label>Target Quantity</label>
                <input type="number" id="targetQuantity" class="form-control" min="0" step="1">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-sm btn-outline" onclick="closeTargetModal()">Cancel</button>
            <button class="btn-sm btn-primary" id="saveTargetBtn">Save</button>
        </div>
    </div>
</div>

<script>
    const products = <?php echo json_encode($products); ?>;
    const roleNames = <?php echo json_encode($role_names); ?>;
    let currentProduct = null;

    function getProductImage(productId) {
        return '../../images/products/' + productId + '.jpeg';
    }

    function getFilteredAndSorted() {
        let filtered = [...products];
        const search = document.getElementById('searchName').value.toLowerCase();
        if (search) filtered = filtered.filter(p => p.name.toLowerCase().includes(search));
        const categorySelect = document.getElementById('filterCategory');
        const selectedCategories = Array.from(categorySelect.selectedOptions).map(opt => opt.value).filter(v => v);
        if (selectedCategories.length) filtered = filtered.filter(p => selectedCategories.includes(p.category));
        const perf = document.getElementById('filterPerformance').value;
        if (perf === 'high') filtered = filtered.filter(p => p.percentage_achieved >= 80);
        else if (perf === 'medium') filtered = filtered.filter(p => p.percentage_achieved >= 50 && p.percentage_achieved < 80);
        else if (perf === 'low') filtered = filtered.filter(p => p.percentage_achieved < 50 && p.percentage_achieved > 0);
        else if (perf === 'no-target') filtered = filtered.filter(p => p.target_quantity === 0);
        const sort = document.getElementById('sortBy').value;
        if (sort === 'name_asc') filtered.sort((a,b) => a.name.localeCompare(b.name));
        else if (sort === 'name_desc') filtered.sort((a,b) => b.name.localeCompare(a.name));
        else if (sort === 'price_asc') filtered.sort((a,b) => a.base_price - b.base_price);
        else if (sort === 'price_desc') filtered.sort((a,b) => b.base_price - a.base_price);
        return filtered;
    }

    function renderProducts() {
        const filtered = getFilteredAndSorted();
        const grid = document.getElementById('productsGrid');
        if (!filtered.length) {
            grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1; text-align:center; padding:3rem;">No products match the filters.</div>';
            return;
        }
        let html = '';
        filtered.forEach(p => {
            const percent = p.percentage_achieved;
            const barWidth = Math.min(100, percent);
            const targetLabel = p.target_quantity ? `Target: ${p.target_quantity}` : 'No target';
            const soldLabel = `Sold: ${p.actual_sold}`;
            const imageUrl = getProductImage(p.id);
            const hasDiscount = Object.keys(p.discounts).length > 0;
            html += `
                <div class="product-card" data-id="${p.id}">
                    <div class="product-info">
                        <div class="product-name">${escapeHtml(p.name)}</div>
                        <div class="product-category">${escapeHtml(p.category || 'Uncategorized')}</div>
                        <div class="product-price">₦${parseFloat(p.base_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</div>
                        <div class="product-stats">
                            <span>${targetLabel} <i class="fas fa-pencil-alt edit-target-icon" onclick="openTargetModal(${p.id}, ${p.target_quantity})"></i></span>
                            <span>${soldLabel}</span>
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar" style="width: ${barWidth}%; background: ${percent >= 100 ? '#10b981' : (percent >= 70 ? '#f59e0b' : '#ef4444')};"></div>
                        </div>
                        <div class="product-stats">
                            <span>Achieved: ${percent}%</span>
                            <span>Stock: ${p.stock_quantity}</span>
                        </div>
                        <div class="product-actions">
                            <button class="btn-sm btn-outline" onclick="openDiscountModal(${p.id})">Set Discount</button>
                            ${hasDiscount ? '<span class="discount-check"><i class="fas fa-check-circle"></i></span>' : ''}
                        </div>
                    </div>
                    <div class="product-image-wrapper">
                        <img src="${imageUrl}" class="product-image" onerror="this.onerror=null; this.src='../../../logo.jpeg'">
                    </div>
                </div>
            `;
        });
        grid.innerHTML = html;
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

    // Target modal
    function openTargetModal(productId, currentTarget) {
        document.getElementById('targetProductId').value = productId;
        document.getElementById('targetQuantity').value = currentTarget;
        document.getElementById('targetModal').classList.add('show');
    }
    function closeTargetModal() {
        document.getElementById('targetModal').classList.remove('show');
    }
    function saveTarget() {
        const productId = document.getElementById('targetProductId').value;
        const target = document.getElementById('targetQuantity').value;
        if (!productId) return;
        const formData = new FormData();
        formData.append('ajax', true);
        formData.append('action', 'update_target');
        formData.append('product_id', productId);
        formData.append('target_quantity', target);
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    const product = products.find(p => p.id == productId);
                    if (product) product.target_quantity = target;
                    renderProducts();
                    closeTargetModal();
                } else {
                    alert(data.message);
                }
            })
            .catch(err => alert('Network error'));
    }
    document.getElementById('saveTargetBtn').addEventListener('click', saveTarget);

    // Discount modal (with uniform discount feature)
    function openDiscountModal(productId) {
        currentProduct = products.find(p => p.id == productId);
        if (!currentProduct) return;
        
        const discounts = currentProduct.discounts || {};
        let bodyHtml = '';
        for (let roleCode in roleNames) {
            const roleNameList = roleNames[roleCode];
            const roleDiscounts = discounts[roleCode] || {};
            // Determine if all role names have the same non-zero discount (for uniform checkbox initial state)
            let uniformValue = null;
            let allSame = true;
            let firstValue = null;
            for (let i = 0; i < roleNameList.length; i++) {
                const val = roleDiscounts[roleNameList[i]] || 0;
                if (i === 0) firstValue = val;
                if (val !== firstValue) {
                    allSame = false;
                    break;
                }
            }
            if (allSame && firstValue !== null) {
                uniformValue = firstValue;
            }
            const hasUniform = (uniformValue !== null && uniformValue > 0);
            
            bodyHtml += `
                <div class="discount-role-group" data-role-code="${roleCode}">
                    <div class="role-header">
                        <span><strong>${roleCode}</strong></span>
                        <label class="toggle-switch">
                            <input type="checkbox" class="main-toggle" data-role="${roleCode}" ${hasUniform ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div id="roleContent-${roleCode}" style="${hasUniform ? 'display:block' : 'display:none'}">
                        <div class="uniform-discount">
                            <label>
                                <input type="checkbox" class="uniform-toggle" data-role="${roleCode}" ${hasUniform ? 'checked' : ''}>
                                <span>Set uniform discount for all roles</span>
                            </label>
                            <input type="number" class="uniform-value" data-role="${roleCode}" value="${uniformValue !== null ? uniformValue : ''}" step="0.01" min="0" max="100" placeholder="%" ${hasUniform ? '' : 'disabled'}>
                        </div>
                        <div class="role-names" id="roleNames-${roleCode}" style="${hasUniform ? 'display:none' : 'display:block'}">
            `;
            roleNameList.forEach(roleName => {
                const discount = roleDiscounts[roleName] || '';
                bodyHtml += `
                    <div class="role-name-item">
                        <span>${escapeHtml(roleName)}</span>
                        <div>
                            <input type="number" class="discount-input" data-role="${roleCode}" data-role-name="${roleName}" value="${discount}" step="0.01" min="0" max="100" placeholder="%">
                        </div>
                    </div>
                `;
            });
            bodyHtml += `</div></div></div>`;
        }
        document.getElementById('discountModalBody').innerHTML = bodyHtml;
        
        // Attach event listeners for main toggles (enable/disable whole group)
        document.querySelectorAll('.main-toggle').forEach(toggle => {
            toggle.addEventListener('change', function(e) {
                e.stopPropagation();
                const roleCode = this.dataset.role;
                const roleContent = document.getElementById(`roleContent-${roleCode}`);
                if (this.checked) {
                    roleContent.style.display = 'block';
                } else {
                    roleContent.style.display = 'none';
                }
            });
        });
        
        // Attach uniform toggle event
        document.querySelectorAll('.uniform-toggle').forEach(toggle => {
            toggle.addEventListener('change', function(e) {
                const roleCode = this.dataset.role;
                const uniformInput = document.querySelector(`.uniform-value[data-role="${roleCode}"]`);
                const individualDiv = document.getElementById(`roleNames-${roleCode}`);
                if (this.checked) {
                    uniformInput.disabled = false;
                    individualDiv.style.display = 'none';
                } else {
                    uniformInput.disabled = true;
                    individualDiv.style.display = 'block';
                    // Optionally clear uniform input
                    uniformInput.value = '';
                }
            });
        });
        
        // Also make clicking the header toggle the main toggle and the content
        document.querySelectorAll('.role-header').forEach(header => {
            header.addEventListener('click', function(e) {
                if (e.target.tagName === 'LABEL' || e.target.closest('.toggle-switch')) return;
                const group = this.closest('.discount-role-group');
                const toggle = group.querySelector('.main-toggle');
                const roleContent = group.querySelector(`#roleContent-${toggle.dataset.role}`);
                if (toggle.checked) {
                    toggle.checked = false;
                    roleContent.style.display = 'none';
                } else {
                    toggle.checked = true;
                    roleContent.style.display = 'block';
                }
                const event = new Event('change', { bubbles: true });
                toggle.dispatchEvent(event);
            });
        });
        
        document.getElementById('discountModal').classList.add('show');
    }
    
    function closeDiscountModal() {
        document.getElementById('discountModal').classList.remove('show');
    }
    
    function saveDiscounts() {
        if (!currentProduct) return;
        const discounts = {};
        document.querySelectorAll('.discount-role-group').forEach(group => {
            const roleCode = group.dataset.roleCode;
            const mainToggle = group.querySelector('.main-toggle');
            if (!mainToggle.checked) return;
            const uniformToggle = group.querySelector('.uniform-toggle');
            if (uniformToggle && uniformToggle.checked) {
                const uniformValue = group.querySelector('.uniform-value').value;
                const percent = parseFloat(uniformValue);
                if (!isNaN(percent) && percent >= 0 && percent <= 100) {
                    // Apply the same discount to all role names under this role code
                    const roleNameList = roleNames[roleCode] || [];
                    roleNameList.forEach(roleName => {
                        if (!discounts[roleCode]) discounts[roleCode] = {};
                        discounts[roleCode][roleName] = percent;
                    });
                } else {
                    // If uniform value is invalid, we don't set any discounts for this role code
                }
            } else {
                const inputs = group.querySelectorAll('.discount-input');
                inputs.forEach(input => {
                    const roleName = input.dataset.roleName;
                    const value = parseFloat(input.value);
                    if (!isNaN(value) && value >= 0 && value <= 100) {
                        if (!discounts[roleCode]) discounts[roleCode] = {};
                        discounts[roleCode][roleName] = value;
                    }
                });
            }
        });
        
        const formData = new FormData();
        formData.append('ajax', true);
        formData.append('action', 'save_discounts');
        formData.append('product_id', currentProduct.id);
        formData.append('discounts', JSON.stringify(discounts));
        
        fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    currentProduct.discounts = discounts;
                    renderProducts();
                    closeDiscountModal();
                } else {
                    alert(data.message);
                }
            })
            .catch(err => alert('Network error'));
    }
    
    document.getElementById('saveDiscountsBtn').addEventListener('click', saveDiscounts);
    document.getElementById('resetFilters').addEventListener('click', function() {
        document.getElementById('searchName').value = '';
        const catSelect = document.getElementById('filterCategory');
        for (let opt of catSelect.options) opt.selected = false;
        document.getElementById('sortBy').value = 'name_asc';
        document.getElementById('filterPerformance').value = '';
        renderProducts();
    });
    
    document.getElementById('searchName').addEventListener('input', renderProducts);
    document.getElementById('filterCategory').addEventListener('change', renderProducts);
    document.getElementById('sortBy').addEventListener('change', renderProducts);
    document.getElementById('filterPerformance').addEventListener('change', renderProducts);
    
    renderProducts();
</script>
</body>
</html>