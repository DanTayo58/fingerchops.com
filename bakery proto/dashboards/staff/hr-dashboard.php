<?php
// =====================================================
// FILE: dashboards/staff/hr-dashboard.php
// PURPOSE: Simple HR Dashboard for Testing
// =====================================================

session_start();
require_once '../../conn.php';
require_once '../../includes/User.php';

// Security check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login_signup.html');
    exit;
}

$user = User::findById($conn, $_SESSION['user_id']);
if (!$user || $user->getType() !== 'staff') {
    header('Location: /login_signup.html');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard · Fingerchops</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <a href="../../logout.php" class="logout">← Logout</a>
</body>
</html>