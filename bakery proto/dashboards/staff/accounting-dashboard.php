<?php
// =====================================================
// FILE: dashboards/staff/accounting-dashboard.php
// PURPOSE: Accounting Department Dashboard - Budget Approval
// VERSION: 1.0
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

// Check if user has accounting role
$is_accounting = false;
$user_roles = $userObj->getRoles();
foreach ($user_roles as $role) {
    if (stripos($role['role_name'], 'accountant') !== false || $role['role_code'] === 'ACCOUNTANT') {
        $is_accounting = true;
        break;
    }
}

if (!$is_accounting && $privilege_level < 60) {
    header('Location: ' . $root_path . '/login_signup.php?error=unauthorized');
    exit;
}

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

$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : 'pending';

if (!isset($_SESSION['accounting_csrf_token'])) {
    $_SESSION['accounting_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['accounting_csrf_token'];

// =====================================================
// AJAX HANDLERS
// =====================================================
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['accounting_csrf_token']) {
        $response['message'] = 'Invalid security token';
        echo json_encode($response);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // Get budget requests
    if ($action === 'get_budget_requests') {
        $status_filter = $_POST['status_filter'] ?? 'pending';
        
        $sql = "
            SELECT br.*, 
                   u.fullname as requester_name,
                   d.dept_name as department_name,
                   a_admin.fullname as admin_approved_by_name,
                   a_acc.fullname as accounting_approved_by_name,
                   p.order_number,
                   p.total_cost,
                   p.approval_status as purchase_approval_status
            FROM budget_requests br
            LEFT JOIN departments d ON br.department_id = d.id
            JOIN bakery_users u ON br.requester_id = u.id
            LEFT JOIN bakery_users a_admin ON br.admin_approved_by = a_admin.id
            LEFT JOIN bakery_users a_acc ON br.accounting_approved_by = a_acc.id
            LEFT JOIN purchases p ON br.purchase_id = p.id
            WHERE 1=1
        ";
        
        if ($status_filter === 'pending') {
            $sql .= " AND br.accounting_status = 'pending'";
        } elseif ($status_filter === 'approved') {
            $sql .= " AND br.accounting_status = 'approved'";
        } elseif ($status_filter === 'rejected') {
            $sql .= " AND br.accounting_status = 'rejected'";
        }
        
        $sql .= " ORDER BY br.created_at DESC";
        
        $requests = $db->preparedFetchAll($sql, '', []);
        $response = ['success' => true, 'requests' => $requests];
        echo json_encode($response);
        exit;
    }
    
    // Approve budget (Accounting)
    if ($action === 'approve_budget') {
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
            
            logActivity($_SESSION['user_id'], "Approved budget request #$budget_id as Accounting", 'budget', $budget_id);
            
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
            $response = ['success' => true, 'message' => 'Budget approved successfully'];
        } catch (Exception $e) {
            $db->rollback();
            $response['message'] = $e->getMessage();
        }
        echo json_encode($response);
        exit;
    }
    
    // Reject budget (Accounting)
    if ($action === 'reject_budget') {
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
                SET accounting_status = 'rejected',
                    accounting_approved_by = ?, 
                    accounting_approved_at = NOW(),
                    accounting_notes = CONCAT(COALESCE(accounting_notes, ''), '\n', ?),
                    overall_status = 'rejected'
                WHERE id = ?
            ", 'isi', [$_SESSION['user_id'], $notes, $budget_id]);
            
            $db->preparedExecute("
                UPDATE purchases 
                SET approval_status = 'rejected',
                    purchase_status = 'idle',
                    can_modify = 1
                WHERE id = ?
            ", 'i', [$budget['purchase_id']]);
            
            logActivity($_SESSION['user_id'], "Rejected budget request #$budget_id as Accounting", 'budget', $budget_id);
            $db->commit();
            $response = ['success' => true, 'message' => 'Budget rejected'];
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

$roleDetails = $userObj->getRoles();
$userRole = !empty($roleDetails) ? $roleDetails[0]['role_name'] : 'Accountant';

$pending_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM budget_requests WHERE accounting_status = 'pending'
", '', [])['count'] ?? 0;

$approved_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM budget_requests WHERE accounting_status = 'approved'
", '', [])['count'] ?? 0;

$rejected_count = $db->preparedFetchOne("
    SELECT COUNT(*) as count FROM budget_requests WHERE accounting_status = 'rejected'
", '', [])['count'] ?? 0;

$branch_display_name = '';
if ($selected_branch_id) {
    $binfo = $db->preparedFetchOne("SELECT branch_name FROM branches WHERE id = ?", 'i', [$selected_branch_id]);
    if ($binfo) $branch_display_name = $binfo['branch_name'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Dashboard · Fingerchops Ventures</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/accounting-dashboard.css">
    <link rel="icon" href="../../logo.jpeg" type="image/jpeg">
</head>
<body>
    <div class="preloader" id="preloader"><div class="preloader-spinner"></div><div class="preloader-text">Processing...</div></div>
    
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="header-left">
                <h1><i class="fas fa-calculator"></i> Accounting Dashboard</h1>
                <p class="header-date"><?php echo $current_date; ?></p>
            </div>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($user['fullname']); ?></span>
                <span class="role-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($userRole); ?></span>
                <span class="perm-badge"><i class="fas fa-shield-alt"></i> Level <?php echo $privilege_level; ?></span>
                <span class="branch-badge"><i class="fas fa-store"></i> <?php echo htmlspecialchars($branch_name); ?></span>
                <a href="../sales-dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
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
        
        <div class="stats-grid">
            <div class="stat-card info">
                <i class="fas fa-clock"></i>
                <div class="stat-value"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card success">
                <i class="fas fa-check-circle"></i>
                <div class="stat-value"><?php echo $approved_count; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card danger">
                <i class="fas fa-times-circle"></i>
                <div class="stat-value"><?php echo $rejected_count; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
        
        <div class="filter-bar">
            <label><i class="fas fa-filter"></i> Filter by Status:</label>
            <select id="statusFilterSelect" class="status-filter-select">
                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="approved" <?php echo $filter_status == 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="rejected" <?php echo $filter_status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <button id="applyFilterBtn" class="btn-sm btn-primary">Apply Filter</button>
        </div>
        
        <div id="budgetRequestsContainer" class="requests-container">
            <div class="loading">Loading budget requests...</div>
        </div>
        
        <div class="footer-note"><i class="fas fa-shield-alt"></i> All actions are logged.</div>
    </div>
    
    <!-- Budget Approval Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Budget</h3>
                <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="approveBudgetId">
                <div class="form-group">
                    <label>Approval Notes (optional)</label>
                    <textarea id="approveNotes" rows="3" placeholder="Add any notes about this approval..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
                <button class="btn-primary" id="confirmApproveBtn">Confirm Approval</button>
            </div>
        </div>
    </div>
    
    <!-- Budget Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Budget</h3>
                <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rejectBudgetId">
                <div class="form-group">
                    <label>Rejection Reason <span class="required">*</span></label>
                    <textarea id="rejectNotes" rows="3" placeholder="Please provide a reason for rejection..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
                <button class="btn-primary" id="confirmRejectBtn">Confirm Rejection</button>
            </div>
        </div>
    </div>
    
    <div id="toastContainer" class="toast-container"></div>
    <div id="overlay" class="overlay"></div>
    
    <script>
        const csrfToken = '<?php echo $csrf_token; ?>';
        const selectedBranchId = <?php echo $selected_branch_id ?: 0; ?>;
        const isHeadquarters = <?php echo $is_headquarters ? 'true' : 'false'; ?>;
        
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
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => m === '&' ? '&amp;' : m === '<' ? '&lt;' : '&gt;');
        }
        
        // ========== LOAD BUDGET REQUESTS ==========
        async function loadBudgetRequests() {
            const container = document.getElementById('budgetRequestsContainer');
            container.innerHTML = '<div class="loading">Loading budget requests...</div>';
            
            const statusFilter = document.getElementById('statusFilterSelect').value;
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'get_budget_requests');
            formData.append('status_filter', statusFilter);
            
            try {
                const data = await ajaxPost(formData);
                if (data.success && data.requests && data.requests.length > 0) {
                    let html = '';
                    for (const req of data.requests) {
                        const adminStatus = req.admin_status === 'approved' ? '✓ Approved' : (req.admin_status === 'rejected' ? 'Rejected' : 'Pending');
                        const adminClass = req.admin_status === 'approved' ? 'approved' : (req.admin_status === 'rejected' ? 'rejected' : 'pending');
                        const accountingClass = req.accounting_status === 'approved' ? 'approved' : (req.accounting_status === 'rejected' ? 'rejected' : 'pending');
                        
                        html += `
                            <div class="request-card">
                                <div class="request-header">
                                    <div>
                                        <h3>
                                            ${req.purchase_id ? `Purchase Order #${req.purchase_id}` : escapeHtml(req.title)}
                                        </h3>
                                        <span class="request-date">${new Date(req.created_at).toLocaleString()}</span>
                                    </div>
                                    <div>
                                        <span class="amount">₦${parseFloat(req.amount).toFixed(2)}</span>
                                        <span class="status-badge ${req.accounting_status === 'approved' ? 'approved' : (req.accounting_status === 'rejected' ? 'rejected' : 'pending')}">
                                            ${req.accounting_status === 'approved' ? 'Approved' : (req.accounting_status === 'rejected' ? 'Rejected' : 'Pending')}
                                        </span>
                                    </div>
                                </div>
                                <div class="request-body">
                                    <div><strong>Requester:</strong> ${escapeHtml(req.requester_name)}</div>
                                    ${req.department_name ? `<div><strong>Department:</strong> ${escapeHtml(req.department_name)}</div>` : ''}
                                    ${req.description ? `<div><strong>Description:</strong> ${escapeHtml(req.description)}</div>` : ''}
                                    <div class="approval-status">
                                        <div class="approval-item">
                                            <span class="approval-label">Admin Approval:</span>
                                            <span class="approval-badge ${adminClass}">${adminStatus}</span>
                                            ${req.admin_approved_by_name ? `<span class="approval-by">by: ${escapeHtml(req.admin_approved_by_name)}</span>` : ''}
                                        </div>
                                        <div class="approval-item">
                                            <span class="approval-label">Accounting Approval:</span>
                                            <span class="approval-badge ${accountingClass}">
                                                ${req.accounting_status === 'approved' ? '✓ Approved' : (req.accounting_status === 'rejected' ? 'Rejected' : 'Pending')}
                                            </span>
                                            ${req.accounting_approved_by_name ? `<span class="approval-by">by: ${escapeHtml(req.accounting_approved_by_name)}</span>` : ''}
                                        </div>
                                    </div>
                                </div>
                                ${req.accounting_status === 'pending' ? `
                                    <div class="request-actions">
                                        <button class="btn-approve" onclick="openApproveModal(${req.id})">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn-reject" onclick="openRejectModal(${req.id})">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                ` : ''}
                                ${req.accounting_notes ? `<div class="request-notes"><strong>Notes:</strong> ${escapeHtml(req.accounting_notes)}</div>` : ''}
                            </div>
                        `;
                    }
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No budget requests found</p></div>';
                }
            } catch(e) {
                console.error(e);
                container.innerHTML = '<div class="error-state">Error loading budget requests</div>';
            }
        }
        
        // ========== APPROVE/REJECT MODALS ==========
        function openApproveModal(budgetId) {
            document.getElementById('approveBudgetId').value = budgetId;
            document.getElementById('approveNotes').value = '';
            openModal('approveModal');
        }
        
        function openRejectModal(budgetId) {
            document.getElementById('rejectBudgetId').value = budgetId;
            document.getElementById('rejectNotes').value = '';
            openModal('rejectModal');
        }
        
        document.getElementById('confirmApproveBtn')?.addEventListener('click', async () => {
            const budgetId = document.getElementById('approveBudgetId').value;
            const notes = document.getElementById('approveNotes').value;
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'approve_budget');
            formData.append('budget_id', budgetId);
            formData.append('notes', notes);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('approveModal');
                    loadBudgetRequests();
                    // Update stats
                    location.reload();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        document.getElementById('confirmRejectBtn')?.addEventListener('click', async () => {
            const budgetId = document.getElementById('rejectBudgetId').value;
            const notes = document.getElementById('rejectNotes').value;
            
            if (!notes.trim()) {
                showToast('Please provide a reason for rejection', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax', 1);
            formData.append('action', 'reject_budget');
            formData.append('budget_id', budgetId);
            formData.append('notes', notes);
            
            showLoading();
            try {
                const data = await ajaxPost(formData);
                if (data.success) {
                    showToast(data.message, 'success');
                    closeModal('rejectModal');
                    loadBudgetRequests();
                    location.reload();
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Network error: ' + e.message, 'error');
            } finally {
                hideLoading();
            }
        });
        
        // ========== FILTERS ==========
        document.getElementById('applyFilterBtn')?.addEventListener('click', () => {
            const status = document.getElementById('statusFilterSelect').value;
            const branchId = document.getElementById('branchSelect')?.value || selectedBranchId;
            window.location.href = window.location.pathname + '?branch=' + branchId + '&filter_status=' + status;
        });
        
        document.getElementById('applyBranchBtn')?.addEventListener('click', () => {
            const branchId = document.getElementById('branchSelect').value;
            const status = document.getElementById('statusFilterSelect').value;
            window.location.href = window.location.pathname + '?branch=' + branchId + '&filter_status=' + status;
        });
        
        // Initial load
        loadBudgetRequests();
        
        window.addEventListener('load', () => {
            setTimeout(() => document.getElementById('preloader').classList.add('fade-out'), 500);
        });
    </script>
</body>
</html>