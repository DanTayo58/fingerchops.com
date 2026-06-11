<?php
// =====================================================
// FILE: dashboards/staff/repos.php
// PURPOSE: Repository Management - Create, Edit, Delete, View Stock
// VERSION: 3.0 - Correct permissions: owner controls opened_for_session, others need password each time
// =====================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$root_path = dirname(__DIR__, 3) . '/';
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
$can_manage_repos = ($privilege_level >= 30);

$user_branch_id = $user['branch_id'] ?? 1;
$branch_info = $db->preparedFetchOne("SELECT branch_name, branch_code FROM branches WHERE id = ?", 'i', [$user_branch_id]);
$branch_name = $branch_info['branch_name'] ?? 'Main Branch';
$branch_code = $branch_info['branch_code'] ?? 'HQ';
$is_headquarters = ($user_branch_id == 1 || $branch_code === 'HQ');

$all_branches = [];
if ($is_headquarters) {
    $all_branches = $db->preparedFetchAll("SELECT id, branch_name, branch_code FROM branches WHERE is_active = 1 ORDER BY branch_name", '', []);
}

$selected_branch_id = isset($_GET['branch']) ? (int)$_GET['branch'] : $user_branch_id;
if (!$is_headquarters) {
    $selected_branch_id = $user_branch_id;
}

if (!isset($_SESSION['repos_csrf_token'])) {
    $_SESSION['repos_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['repos_csrf_token'];

// =====================================================
// HELPER: Check if user has write access to a repository
// =====================================================
function hasWriteAccess($repo, $user_id) {
    // Owner always has access
    if ($repo['author_id'] == $user_id) {
        return true;
    }
    // If opened_for_session is 1, anyone has access
    if ($repo['opened_for_session'] == 1) {
        return true;
    }
    return false;
}

// =====================================================
// HELPER: Verify password for a repository (non-owners only)
// =====================================================
function verifyRepositoryPassword($repo_id, $password, $db) {
    $repo = $db->preparedFetchOne("SELECT password FROM repos WHERE id = ?", 'i', [$repo_id]);
    if (!$repo || empty($repo['password'])) {
        return false;
    }
    return password_verify($password, $repo['password']);
}

// =====================================================
// AJAX HANDLERS
// =====================================================
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['repos_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // Get repositories for selected branch
    if ($action === 'get_repositories') {
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        $repos = $db->preparedFetchAll("
            SELECT r.*, 
                   u.fullname as author_name,
                   COUNT(DISTINCT rs.product_id) as product_count,
                   COALESCE(SUM(rs.quantity), 0) as total_items
            FROM repos r
            LEFT JOIN bakery_users u ON r.author_id = u.id
            LEFT JOIN repos_stock rs ON r.id = rs.repos_id
            WHERE r.branch_id = ?
            GROUP BY r.id
            ORDER BY r.created_at DESC
        ", 'i', [$branch_id]);
        
        // Determine access level for each repo
        foreach ($repos as &$repo) {
            $repo['is_owner'] = ($repo['author_id'] == $_SESSION['user_id']);
            $repo['has_write_access'] = hasWriteAccess($repo, $_SESSION['user_id']);
            $repo['is_open_for_session'] = ($repo['opened_for_session'] == 1);
            $repo['has_password'] = !empty($repo['password']);
        }
        
        $response = ['success' => true, 'repositories' => $repos];
        echo json_encode($response);
        exit;
    }
    
    // Create repository
    if ($action === 'create_repository') {
        if (!$can_manage_repos) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $repo_name = trim($_POST['repo_name']);
        $branch_id = intval($_POST['branch_id'] ?? $selected_branch_id);
        $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;
        
        if (empty($repo_name)) {
            $response['message'] = 'Repository name required';
            echo json_encode($response);
            exit;
        }
        
        $db->preparedExecute("
            INSERT INTO repos (repo_name, branch_id, password, last_password_change, author_id, opened_for_session, created_at)
            VALUES (?, ?, ?, NOW(), ?, 0, NOW())
        ", 'sisi', [$repo_name, $branch_id, $password, $_SESSION['user_id']]);
        
        $repo_id = $db->lastInsertId();
        
        logActivity($_SESSION['user_id'], "Created repository: $repo_name at branch $branch_id", 'repository', $repo_id);
        $response = ['success' => true, 'message' => 'Repository created successfully'];
        echo json_encode($response);
        exit;
    }
    
    // Update repository (name, password, opened_for_session)
    if ($action === 'update_repository') {
        if (!$can_manage_repos) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $repo_id = intval($_POST['repo_id']);
        $repo_name = trim($_POST['repo_name']);
        $new_password = trim($_POST['password'] ?? '');
        $opened_for_session = isset($_POST['opened_for_session']) ? 1 : 0;
        $action_password = $_POST['action_password'] ?? ''; // Password for non-owners
        
        if (empty($repo_name)) {
            $response['message'] = 'Repository name required';
            echo json_encode($response);
            exit;
        }
        
        // Get current repo data
        $repo = $db->preparedFetchOne("SELECT author_id, password, opened_for_session FROM repos WHERE id = ?", 'i', [$repo_id]);
        if (!$repo) {
            $response['message'] = 'Repository not found';
            echo json_encode($response);
            exit;
        }
        
        $is_owner = ($repo['author_id'] == $_SESSION['user_id']);
        $has_write = hasWriteAccess($repo, $_SESSION['user_id']);
        
        // Check permission
        if (!$is_owner && !$has_write) {
            // Non-owner without write access must provide password
            if (empty($action_password)) {
                $response['message'] = 'Password required to edit this repository';
                $response['require_password'] = true;
                echo json_encode($response);
                exit;
            }
            
            if (!verifyRepositoryPassword($repo_id, $action_password, $db)) {
                $response['message'] = 'Invalid password';
                echo json_encode($response);
                exit;
            }
        }
        
        $updates = [];
        $params = [];
        $types = '';
        
        $updates[] = "repo_name = ?";
        $params[] = $repo_name;
        $types .= 's';
        
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $updates[] = "password = ?";
            $params[] = $hashed_password;
            $types .= 's';
            $updates[] = "last_password_change = NOW()";
        }
        
        // Only owner can change opened_for_session
        if ($is_owner) {
            $updates[] = "opened_for_session = ?";
            $params[] = $opened_for_session;
            $types .= 'i';
        }
        
        $sql = "UPDATE repos SET " . implode(", ", $updates) . " WHERE id = ?";
        $params[] = $repo_id;
        $types .= 'i';
        
        $db->preparedExecute($sql, $types, $params);
        
        logActivity($_SESSION['user_id'], "Updated repository ID: $repo_id", 'repository', $repo_id);
        $response = ['success' => true, 'message' => 'Repository updated successfully'];
        echo json_encode($response);
        exit;
    }
    
    // Delete repository (owner only)
    if ($action === 'delete_repository') {
        if (!$can_manage_repos) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $repo_id = intval($_POST['repo_id']);
        
        $repo = $db->preparedFetchOne("SELECT author_id FROM repos WHERE id = ?", 'i', [$repo_id]);
        if (!$repo || $repo['author_id'] != $_SESSION['user_id']) {
            $response['message'] = 'Only the repository owner can delete it';
            echo json_encode($response);
            exit;
        }
        
        // Check if repository has stock
        $stock_count = $db->preparedFetchOne("SELECT COUNT(*) as count FROM repos_stock WHERE repos_id = ?", 'i', [$repo_id])['count'] ?? 0;
        if ($stock_count > 0) {
            $response['message'] = 'Cannot delete repository with existing stock. Transfer stock first.';
            echo json_encode($response);
            exit;
        }
        
        $db->preparedExecute("DELETE FROM repos WHERE id = ?", 'i', [$repo_id]);
        
        logActivity($_SESSION['user_id'], "Deleted repository ID: $repo_id", 'repository', $repo_id);
        $response = ['success' => true, 'message' => 'Repository deleted successfully'];
        echo json_encode($response);
        exit;
    }
    
    // Get repository stock (always allowed - view only)
    if ($action === 'get_repository_stock') {
        $repo_id = intval($_POST['repo_id']);
        
        $stock = $db->preparedFetchAll("
            SELECT rs.*, p.name as product_name, p.base_price, p.category
            FROM repos_stock rs
            JOIN products p ON rs.product_id = p.id
            WHERE rs.repos_id = ?
            ORDER BY p.name ASC
        ", 'i', [$repo_id]);
        
        $response = ['success' => true, 'stock' => $stock];
        echo json_encode($response);
        exit;
    }
    
    // Transfer stock (requires write access or password)
    if ($action === 'transfer_stock') {
        if (!$can_manage_repos) {
            $response['message'] = 'Permission denied';
            echo json_encode($response);
            exit;
        }
        
        $from_repo_id = intval($_POST['from_repo_id']);
        $to_repo_id = intval($_POST['to_repo_id']);
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $notes = trim($_POST['notes'] ?? '');
        $action_password = $_POST['action_password'] ?? '';
        
        if ($from_repo_id == $to_repo_id) {
            $response['message'] = 'Source and destination cannot be the same';
            echo json_encode($response);
            exit;
        }
        
        if ($quantity <= 0) {
            $response['message'] = 'Invalid quantity';
            echo json_encode($response);
            exit;
        }
        
        // Get source repository data
        $source_repo = $db->preparedFetchOne("SELECT author_id, password, opened_for_session FROM repos WHERE id = ?", 'i', [$from_repo_id]);
        if (!$source_repo) {
            $response['message'] = 'Source repository not found';
            echo json_encode($response);
            exit;
        }
        
        $is_owner = ($source_repo['author_id'] == $_SESSION['user_id']);
        $has_write = hasWriteAccess($source_repo, $_SESSION['user_id']);
        
        // Check permission
        if (!$is_owner && !$has_write) {
            // Non-owner without write access must provide password
            if (empty($action_password)) {
                $response['message'] = 'Password required to transfer from this repository';
                $response['require_password'] = true;
                echo json_encode($response);
                exit;
            }
            
            if (!verifyRepositoryPassword($from_repo_id, $action_password, $db)) {
                $response['message'] = 'Invalid password';
                echo json_encode($response);
                exit;
            }
        }
        
        // Check source stock
        $source_stock = $db->preparedFetchOne("
            SELECT quantity FROM repos_stock 
            WHERE repos_id = ? AND product_id = ?
        ", 'ii', [$from_repo_id, $product_id]);
        
        if (!$source_stock || $source_stock['quantity'] < $quantity) {
            $response['message'] = 'Insufficient stock in source repository';
            echo json_encode($response);
            exit;
        }
        
        $db->beginTransaction();
        try {
            // Deduct from source
            $new_source_qty = $source_stock['quantity'] - $quantity;
            if ($new_source_qty == 0) {
                $db->preparedExecute("DELETE FROM repos_stock WHERE repos_id = ? AND product_id = ?", 'ii', [$from_repo_id, $product_id]);
            } else {
                $db->preparedExecute("
                    UPDATE repos_stock SET quantity = ? 
                    WHERE repos_id = ? AND product_id = ?
                ", 'iii', [$new_source_qty, $from_repo_id, $product_id]);
            }
            
            // Add to destination
            $dest_stock = $db->preparedFetchOne("
                SELECT id, quantity FROM repos_stock 
                WHERE repos_id = ? AND product_id = ?
            ", 'ii', [$to_repo_id, $product_id]);
            
            if ($dest_stock) {
                $db->preparedExecute("
                    UPDATE repos_stock SET quantity = quantity + ? 
                    WHERE repos_id = ? AND product_id = ?
                ", 'iii', [$quantity, $to_repo_id, $product_id]);
            } else {
                $db->preparedExecute("
                    INSERT INTO repos_stock (repos_id, product_id, quantity) 
                    VALUES (?, ?, ?)
                ", 'iii', [$to_repo_id, $product_id, $quantity]);
            }
            
            $product = $db->preparedFetchOne("SELECT name FROM products WHERE id = ?", 'i', [$product_id]);
            logActivity($_SESSION['user_id'], "Transferred $quantity units of {$product['name']} from repository $from_repo_id to $to_repo_id", 'repository_transfer', $product_id);
            
            $db->commit();
            $response = ['success' => true, 'message' => 'Stock transferred successfully'];
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
$current_date = date('l, F j, Y');

$branch_display_name = '';
if ($selected_branch_id) {
    $binfo = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$selected_branch_id]);
    if ($binfo) $branch_display_name = $binfo['branch_name'];
}

// Get all products for transfer dropdown
$all_products = $db->preparedFetchAll("SELECT id, name FROM products WHERE is_active = 1 ORDER BY name", '', []);
$products_json = json_encode($all_products);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repositories · Fingerchops Ventures</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/repos.css">
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
</head>
<body>
    <div class="preloader" id="preloader">
        <div class="preloader-spinner"></div>
    </div>
    
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-left">
                <h1><i class="fas fa-database"></i> Repositories</h1>
                <p class="header-date"><?php echo $current_date; ?></p>
            </div>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($user['fullname']); ?></span>
                <span class="perm-badge"><i class="fas fa-shield-alt"></i> Level <?php echo $privilege_level; ?></span>
                <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                <a href="../inventory-dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Inventory</a>
                <a href="../../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        
        <?php if ($is_headquarters): ?>
        <div class="branch-filter">
            <label><i class="fas fa-store"></i> Filter by Branch:</label>
            <select id="branchSelect">
                <option value="0">All Branches (HQ View)</option>
                <?php foreach ($all_branches as $branch): ?>
                    <option value="<?php echo $branch['id']; ?>" <?php echo $selected_branch_id == $branch['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($branch['branch_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button id="applyBranchBtn" class="btn-sm">Apply</button>
        </div>
        <?php endif; ?>
        
        <div class="action-bar">
            <button id="createRepoBtn" class="btn-primary"><i class="fas fa-plus"></i> Create Repository</button>
        </div>
        
        <div id="repositoriesContainer" class="repositories-grid">
            <div class="loading">Loading repositories...</div>
        </div>
        
        <div class="footer-note"><i class="fas fa-shield-alt"></i> All changes are logged.</div>
    </div>
    
    <!-- Create/Edit Repository Modal -->
    <div id="repoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="repoModalTitle"><i class="fas fa-database"></i> Create Repository</h3>
                <button class="modal-close" onclick="closeModal('repoModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editRepoId" value="0">
                <div class="form-group">
                    <label>Repository Name <span class="required">*</span></label>
                    <input type="text" id="repoName" placeholder="e.g., Main Warehouse, Cold Storage, Bakery Storage">
                </div>
                <div class="form-group">
                    <label>Password (Optional)</label>
                    <input type="password" id="repoPassword" placeholder="Leave empty for no password">
                    <small>Set a password to restrict access. Non-owners will need this password to transfer stock or edit when repository is locked.</small>
                </div>
                <div class="form-group" id="openSessionGroup" style="display:none;">
                    <label class="checkbox-label">
                        <input type="checkbox" id="openSession"> Open for everyone this session
                    </label>
                    <small>If checked, anyone can transfer stock from this repository without a password for this session.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('repoModal')">Cancel</button>
                <button class="btn-primary" id="submitRepoBtn">Save Repository</button>
            </div>
        </div>
    </div>
    
    <!-- Password Required Modal (for non-owner actions) -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Password Required</h3>
                <button class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="passwordActionRepoId">
                <input type="hidden" id="passwordActionType">
                <input type="hidden" id="passwordTransferData">
                <div class="form-group">
                    <label>Repository Password <span class="required">*</span></label>
                    <input type="password" id="actionPassword" placeholder="Enter repository password">
                    <small>This repository is locked. Only the owner or someone with the password can perform this action.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closePasswordModal()">Cancel</button>
                <button class="btn-primary" id="confirmPasswordBtn">Continue</button>
            </div>
        </div>
    </div>
    
    <!-- Transfer Stock Modal -->
    <div id="transferModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-exchange-alt"></i> Transfer Stock</h3>
                <button class="modal-close" onclick="closeModal('transferModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="transferFromRepoId">
                <div class="form-group">
                    <label>From Repository</label>
                    <input type="text" id="transferFromRepoName" readonly disabled style="background:#f3f4f6;">
                </div>
                <div class="form-group">
                    <label>Product <span class="required">*</span></label>
                    <select id="transferProductId" class="form-control">
                        <option value="">-- Select Product --</option>
                        <?php foreach ($all_products as $product): ?>
                            <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Available Quantity</label>
                    <input type="text" id="availableQuantity" readonly disabled style="background:#f3f4f6;">
                </div>
                <div class="form-group">
                    <label>Quantity to Transfer <span class="required">*</span></label>
                    <input type="number" id="transferQuantity" min="1" value="1">
                </div>
                <div class="form-group">
                    <label>To Repository <span class="required">*</span></label>
                    <select id="transferToRepoId" class="form-control">
                        <option value="">-- Select Destination --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <textarea id="transferNotes" rows="2" placeholder="Reason for transfer..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('transferModal')">Cancel</button>
                <button class="btn-primary" id="confirmTransferBtn">Transfer Stock</button>
            </div>
        </div>
    </div>
    
    <!-- Stock Details Modal -->
    <div id="stockModal" class="modal modal-large">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-boxes"></i> Repository Stock</h3>
                <button class="modal-close" onclick="closeModal('stockModal')">&times;</button>
            </div>
            <div class="modal-body" id="stockModalBody">
                <div class="loading">Loading stock...</div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('stockModal')">Close</button>
            </div>
        </div>
    </div>
    
    <div id="toastContainer" class="toast-container"></div>
    <div id="overlay" class="overlay"></div>
    
    <script>
        const csrfToken = '<?php echo $csrf_token; ?>';
        const selectedBranchId = <?php echo $selected_branch_id ?: 0; ?>;
        const isHeadquarters = <?php echo $is_headquarters ? 'true' : 'false'; ?>;
        const canManageRepos = <?php echo $can_manage_repos ? 'true' : 'false'; ?>;
        const allProducts = <?php echo $products_json; ?>;
        const currentUserId = <?php echo $_SESSION['user_id']; ?>;
        
        let currentRepositories = [];
        let pendingAction = null;
        
        function showToast(msg, type) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i> ${msg}<span onclick="this.parentElement.remove()">×</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        function showLoading() { document.getElementById('preloader').classList.add('show'); }
        function hideLoading() { document.getElementById('preloader').classList.remove('show'); }
        
        async function ajaxPost(formData) {
            formData.append('csrf_token', csrfToken);
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
        
        function closePasswordModal() {
            closeModal('passwordModal');
            document.getElementById('actionPassword').value = '';
            pendingAction = null;
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
        }
        
        // ========== LOAD REPOSITORIES ==========
        async function loadRepositories() {
            const container = document.getElementById('repositoriesContainer');
            container.innerHTML = '<div class="loading">Loading repositories...</div>';
            
            const branchId = isHeadquarters ? (document.getElementById('branchSelect')?.value || selectedBranchId) : selectedBranchId;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_repositories');
            formData.append('branch_id', branchId);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.repositories && data.repositories.length > 0) {
                    currentRepositories = data.repositories;
                    let html = '';
                    for (const repo of data.repositories) {
                        const isLocked = !repo.has_write_access && repo.has_password;
                        const lockIcon = isLocked ? '<i class="fas fa-lock locked-icon"></i>' : '<i class="fas fa-lock-open unlocked-icon"></i>';
                        const sessionStatus = repo.is_open_for_session ? '<span class="session-badge open">🔓 Session Open</span>' : '<span class="session-badge closed">🔒 Session Closed</span>';
                        
                        html += `
                            <div class="repo-card" data-repo-id="${repo.id}" data-repo-name="${escapeHtml(repo.repo_name)}">
                                <div class="repo-header">
                                    <div class="repo-title">
                                        ${lockIcon}
                                        <h3>${escapeHtml(repo.repo_name)}</h3>
                                        ${repo.is_owner ? sessionStatus : ''}
                                    </div>
                                    <div class="repo-actions">
                                        <button class="view-stock-btn btn-sm" data-id="${repo.id}"><i class="fas fa-boxes"></i> View Stock</button>
                                        ${repo.has_write_access ? 
                                            `<button class="transfer-stock-btn btn-sm" data-id="${repo.id}" data-name="${escapeHtml(repo.repo_name)}"><i class="fas fa-exchange-alt"></i> Transfer</button>
                                             <button class="edit-repo-btn btn-sm" data-id="${repo.id}" data-name="${escapeHtml(repo.repo_name)}" data-has-password="${repo.has_password}" data-is-open="${repo.is_open_for_session}" data-is-owner="${repo.is_owner}"><i class="fas fa-edit"></i> Edit</button>
                                             <button class="delete-repo-btn btn-sm" data-id="${repo.id}" data-name="${escapeHtml(repo.repo_name)}" data-is-owner="${repo.is_owner}"><i class="fas fa-trash"></i> Delete</button>` : 
                                            `<button class="transfer-stock-btn btn-sm" data-id="${repo.id}" data-name="${escapeHtml(repo.repo_name)}" data-locked="true"><i class="fas fa-exchange-alt"></i> Transfer (Locked)</button>
                                             <button class="edit-repo-btn btn-sm" data-id="${repo.id}" data-name="${escapeHtml(repo.repo_name)}" data-has-password="${repo.has_password}" data-is-open="${repo.is_open_for_session}" data-is-owner="${repo.is_owner}"><i class="fas fa-edit"></i> Edit (Locked)</button>`
                                        }
                                    </div>
                                </div>
                                <div class="repo-stats">
                                    <div class="stat">
                                        <span class="stat-label">Products</span>
                                        <span class="stat-value">${repo.product_count || 0}</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-label">Total Items</span>
                                        <span class="stat-value">${repo.total_items || 0}</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-label">Created By</span>
                                        <span class="stat-value">${escapeHtml(repo.author_name || 'Unknown')}</span>
                                    </div>
                                    <div class="stat">
                                        <span class="stat-label">Created</span>
                                        <span class="stat-value">${new Date(repo.created_at).toLocaleDateString()}</span>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    container.innerHTML = html;
                    
                    // Attach event listeners
                    document.querySelectorAll('.view-stock-btn').forEach(btn => {
                        btn.addEventListener('click', () => viewRepositoryStock(btn.dataset.id));
                    });
                    document.querySelectorAll('.transfer-stock-btn').forEach(btn => {
                        btn.addEventListener('click', () => openTransferModal(btn.dataset.id, btn.dataset.name, btn.dataset.locked === 'true'));
                    });
                    document.querySelectorAll('.edit-repo-btn').forEach(btn => {
                        btn.addEventListener('click', () => openEditModal(btn.dataset.id, btn.dataset.name, btn.dataset.hasPassword === 'true', btn.dataset.isOpen === 'true', btn.dataset.isOwner === 'true'));
                    });
                    document.querySelectorAll('.delete-repo-btn').forEach(btn => {
                        btn.addEventListener('click', () => deleteRepository(btn.dataset.id, btn.dataset.name, btn.dataset.isOwner === 'true'));
                    });
                    
                } else {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-database"></i><p>No repositories found</p><button class="btn-primary" onclick="openCreateModal()">Create Repository</button></div>';
                }
            } catch(e) {
                console.error(e);
                container.innerHTML = '<div class="error-state">Error loading repositories</div>';
            }
        }
        
        // ========== REPOSITORY CRUD ==========
        function openCreateModal() {
            document.getElementById('editRepoId').value = '0';
            document.getElementById('repoModalTitle').innerHTML = '<i class="fas fa-plus"></i> Create Repository';
            document.getElementById('repoName').value = '';
            document.getElementById('repoPassword').value = '';
            document.getElementById('openSessionGroup').style.display = 'none';
            document.getElementById('openSession').checked = false;
            openModal('repoModal');
        }
        
        function openEditModal(id, name, hasPassword, isOpen, isOwner) {
            document.getElementById('editRepoId').value = id;
            document.getElementById('repoModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Repository';
            document.getElementById('repoName').value = name;
            document.getElementById('repoPassword').value = '';
            
            if (isOwner) {
                document.getElementById('openSessionGroup').style.display = 'block';
                document.getElementById('openSession').checked = isOpen;
            } else {
                document.getElementById('openSessionGroup').style.display = 'none';
            }
            
            openModal('repoModal');
        }
        
        document.getElementById('submitRepoBtn')?.addEventListener('click', async () => {
            const repoId = document.getElementById('editRepoId').value;
            const repoName = document.getElementById('repoName').value.trim();
            const password = document.getElementById('repoPassword').value;
            const openSession = document.getElementById('openSession').checked;
            const isEdit = repoId !== '0';
            
            if (!repoName) {
                showToast('Repository name required', 'error');
                return;
            }
            
            const action = isEdit ? 'update_repository' : 'create_repository';
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', action);
            formData.append('repo_name', repoName);
            if (password) formData.append('password', password);
            if (isEdit) {
                formData.append('repo_id', repoId);
                formData.append('opened_for_session', openSession ? 1 : 0);
            }
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('repoModal');
                    loadRepositories();
                } else if (data.require_password) {
                    // Store pending action and show password modal
                    pendingAction = { type: 'edit', repoId: repoId, formData: formData };
                    document.getElementById('passwordActionRepoId').value = repoId;
                    document.getElementById('passwordActionType').value = 'edit';
                    openModal('passwordModal');
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        async function deleteRepository(id, name, isOwner) {
            if (!isOwner) {
                showToast('Only the repository owner can delete it', 'error');
                return;
            }
            if (!confirm(`Delete repository "${name}"? This action cannot be undone.`)) return;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'delete_repository');
            formData.append('repo_id', id);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    loadRepositories();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        // ========== VIEW STOCK (Always allowed) ==========
        async function viewRepositoryStock(repoId) {
            const modalBody = document.getElementById('stockModalBody');
            modalBody.innerHTML = '<div class="loading">Loading stock...</div>';
            openModal('stockModal');
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_repository_stock');
            formData.append('repo_id', repoId);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.stock && data.stock.length > 0) {
                    let html = '<table class="stock-table"><thead><tr><th>Product</th><th>Category</th><th>Quantity</th><th>Unit Price</th><th>Total Value</th></tr></thead><tbody>';
                    for (const item of data.stock) {
                        const totalValue = item.quantity * item.base_price;
                        html += `
                            <tr>
                                <td><strong>${escapeHtml(item.product_name)}</strong></td>
                                <td>${escapeHtml(item.category || 'Uncategorized')}</td>
                                <td>${item.quantity}</td>
                                <td>₦${parseFloat(item.base_price).toFixed(2)}</td>
                                <td>₦${totalValue.toFixed(2)}</td>
                            </tr>
                        `;
                    }
                    html += '</tbody></table>';
                    modalBody.innerHTML = html;
                } else {
                    modalBody.innerHTML = '<div class="empty-state"><i class="fas fa-box-open"></i><p>No stock in this repository</p></div>';
                }
            } catch(e) {
                modalBody.innerHTML = '<div class="error-state">Error loading stock</div>';
            }
        }
        
        // ========== TRANSFER STOCK ==========
        let pendingTransferData = null;
        
        async function openTransferModal(repoId, repoName, isLocked) {
            // Check if we need password
            const repo = currentRepositories.find(r => r.id == repoId);
            if (repo && !repo.has_write_access && repo.has_password) {
                // Store transfer data and show password modal
                pendingTransferData = { repoId, repoName };
                document.getElementById('passwordActionRepoId').value = repoId;
                document.getElementById('passwordActionType').value = 'transfer';
                openModal('passwordModal');
                return;
            }
            
            // Proceed with transfer
            showTransferForm(repoId, repoName);
        }
        
        function showTransferForm(repoId, repoName) {
            document.getElementById('transferFromRepoId').value = repoId;
            document.getElementById('transferFromRepoName').value = repoName;
            document.getElementById('transferProductId').value = '';
            document.getElementById('transferQuantity').value = '1';
            document.getElementById('transferNotes').value = '';
            document.getElementById('availableQuantity').value = '';
            
            // Populate destination repositories
            const toRepoSelect = document.getElementById('transferToRepoId');
            toRepoSelect.innerHTML = '<option value="">-- Select Destination --</option>';
            for (const repo of currentRepositories) {
                if (repo.id != repoId) {
                    toRepoSelect.innerHTML += `<option value="${repo.id}">${escapeHtml(repo.repo_name)}</option>`;
                }
            }
            
            openModal('transferModal');
        }
        
        document.getElementById('transferProductId')?.addEventListener('change', async () => {
            const productId = document.getElementById('transferProductId').value;
            const fromRepoId = document.getElementById('transferFromRepoId').value;
            
            if (!productId) {
                document.getElementById('availableQuantity').value = '';
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_repository_stock');
            formData.append('repo_id', fromRepoId);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.stock) {
                    const stockItem = data.stock.find(s => s.product_id == productId);
                    document.getElementById('availableQuantity').value = stockItem ? stockItem.quantity : 0;
                }
            } catch(e) {
                console.error(e);
            }
        });
        
        document.getElementById('confirmTransferBtn')?.addEventListener('click', async () => {
            const fromRepoId = document.getElementById('transferFromRepoId').value;
            const toRepoId = document.getElementById('transferToRepoId').value;
            const productId = document.getElementById('transferProductId').value;
            const quantity = parseInt(document.getElementById('transferQuantity').value);
            const notes = document.getElementById('transferNotes').value;
            
            if (!toRepoId) {
                showToast('Please select destination repository', 'error');
                return;
            }
            if (!productId) {
                showToast('Please select a product', 'error');
                return;
            }
            if (isNaN(quantity) || quantity <= 0) {
                showToast('Valid quantity required', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'transfer_stock');
            formData.append('from_repo_id', fromRepoId);
            formData.append('to_repo_id', toRepoId);
            formData.append('product_id', productId);
            formData.append('quantity', quantity);
            formData.append('notes', notes);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('transferModal');
                    loadRepositories();
                } else if (data.require_password) {
                    // Store for password retry
                    pendingTransferData = { fromRepoId, toRepoId, productId, quantity, notes };
                    document.getElementById('passwordActionRepoId').value = fromRepoId;
                    document.getElementById('passwordActionType').value = 'transfer';
                    openModal('passwordModal');
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        // ========== PASSWORD MODAL HANDLER ==========
        document.getElementById('confirmPasswordBtn')?.addEventListener('click', async () => {
            const repoId = document.getElementById('passwordActionRepoId').value;
            const actionType = document.getElementById('passwordActionType').value;
            const password = document.getElementById('actionPassword').value;
            
            if (!password) {
                showToast('Password required', 'error');
                return;
            }
            
            if (actionType === 'transfer' && pendingTransferData) {
                // Retry transfer with password
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'transfer_stock');
                formData.append('from_repo_id', pendingTransferData.fromRepoId || repoId);
                formData.append('to_repo_id', pendingTransferData.toRepoId);
                formData.append('product_id', pendingTransferData.productId);
                formData.append('quantity', pendingTransferData.quantity);
                formData.append('notes', pendingTransferData.notes || '');
                formData.append('action_password', password);
                
                showLoading();
                try {
                    const data = await ajaxPost(formData);
                    if (data.success) {
                        showToast(data.message, 'success');
                        closePasswordModal();
                        closeModal('transferModal');
                        loadRepositories();
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch(e) {
                    showToast('Network error: ' + e.message, 'error');
                } finally {
                    hideLoading();
                }
            } else if (actionType === 'edit' && pendingAction?.formData) {
                // Retry edit with password
                pendingAction.formData.append('action_password', password);
                
                showLoading();
                try {
                    const data = await ajaxPost(pendingAction.formData);
                    if (data.success) {
                        showToast(data.message, 'success');
                        closePasswordModal();
                        closeModal('repoModal');
                        loadRepositories();
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch(e) {
                    showToast('Network error: ' + e.message, 'error');
                } finally {
                    hideLoading();
                }
            }
            
            pendingAction = null;
            pendingTransferData = null;
        });
        
        // ========== FILTERS ==========
        document.getElementById('applyBranchBtn')?.addEventListener('click', () => {
            const branchId = document.getElementById('branchSelect').value;
            window.location.href = window.location.pathname + '?branch=' + branchId;
        });
        
        document.getElementById('createRepoBtn')?.addEventListener('click', openCreateModal);
        
        // Initial load
        loadRepositories();
        
        window.addEventListener('load', () => {
            setTimeout(() => document.getElementById('preloader').classList.add('fade-out'), 500);
        });
    </script>
</body>
</html>