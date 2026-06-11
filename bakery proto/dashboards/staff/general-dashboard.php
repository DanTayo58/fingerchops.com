<?php
// =====================================================
// FILE: dashboards/staff/generic-dashboard.php
// PURPOSE: Generic dashboard for any new department
// =====================================================

session_start();
require_once '../../conn.php';
require_once '../../includes/User.php';
require_once '../../includes/Position.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /login_signup.html');
    exit;
}

$user = User::findById($conn, $_SESSION['user_id']);
if (!$user || $user->getType() !== 'staff') {
    header('Location: /login_signup.html');
    exit;
}

$position = $user->getCurrentPosition();
$department = $position['dept_name'] ?? 'Unknown Department';
$role = $position['role_code'] ?? 'STAFF';
$fullname = $user->getFullname();

// Get role display name
$role_names = [
    'HOD' => 'Head of Department',
    'SUPERVISOR' => 'Supervisor',
    'MANAGER' => 'Manager',
    'OFFICER' => 'Officer',
    'ASSISTANT' => 'Assistant',
    'ADMIN' => 'Administrator'
];
$role_display = $role_names[$role] ?? 'Staff Member';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($department); ?> · Fingerchops Bakery</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../../css/admin-dashboard.css">
    <style>
        .dept-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
        }
        .coming-soon {
            text-align: center;
            padding: 60px;
            background: #f7fafc;
            border-radius: 20px;
        }
        .coming-soon i {
            font-size: 64px;
            color: #a0aec0;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dept-banner">
            <h1><?php echo htmlspecialchars($department); ?> Department</h1>
            <p>Welcome, <?php echo htmlspecialchars($fullname); ?> (<?php echo $role_display; ?>)</p>
        </div>
        
        <div class="coming-soon">
            <i class="fas fa-tools"></i>
            <h2>Dashboard Under Construction</h2>
            <p>The dashboard for <?php echo htmlspecialchars($department); ?> is being built.</p>
            <p>You have the role of <strong><?php echo $role_display; ?></strong>.</p>
        </div>
        
        <a href="../../logout.php" class="logout">Logout</a>
    </div>
</body>
</html>