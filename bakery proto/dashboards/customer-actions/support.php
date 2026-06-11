<?php
session_start();
require_once '../../conn.php';
require_once '../../includes/User.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login_signup.html');
    exit;
}

$user = User::findById($conn, $_SESSION['user_id']);
if (!$user) {
    header('Location: /login_signup.html');
    exit;
}

$fullname = $user->getFullname();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Finger Chops - Page Title</title>
    <link rel="stylesheet" href="/css/customer-dashboard.css">
</head>
<body>
    <h1>Coming Soon</h1>
    <p>This page is under construction.</p>
    <a href="/customer-dashboard.php">Back to Dashboard</a>
</body>
</html>