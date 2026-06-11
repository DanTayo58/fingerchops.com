<?php
// reset_password.php - Web interface to reset any user's password
// Access via: http://localhost/bakery%20proto/reset_password.php

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once 'conn.php';
require_once 'includes/Security.php';
require_once 'includes/User.php';

// Initialize variables
$message = '';
$messageType = '';
$users = [];

// Get database instance
$db = Database::getInstance();

// Fetch all users for dropdown
$userQuery = "SELECT id, username, fullname, user_type, user_id FROM bakery_users ORDER BY username";
$userResult = $db->query($userQuery);
if ($userResult) {
    while ($row = mysqli_fetch_assoc($userResult)) {
        $users[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    if ($userId && $newPassword && $confirmPassword) {
        if ($newPassword !== $confirmPassword) {
            $message = "Passwords do not match!";
            $messageType = "error";
        } else {
            // Generate new password hash
            $salt = generateSalt();
            $hash = hashPassword($newPassword, $salt);
            
            // Update the password
            $updateQuery = "UPDATE bakery_users SET 
                           password_hash = '" . mysqli_real_escape_string($db->getConnection(), $hash) . "',
                           password_salt = '" . mysqli_real_escape_string($db->getConnection(), $salt) . "',
                           force_password_change = 0,
                           last_password_change = NOW()
                           WHERE id = $userId";
            
            if ($db->query($updateQuery)) {
                $message = "Password updated successfully!";
                $messageType = "success";
                
                // Log the action
                error_log("Password reset for user ID: $userId");
            } else {
                $message = "Error updating password: " . mysqli_error($db->getConnection());
                $messageType = "error";
            }
        }
    } else {
        $message = "Please fill all fields";
        $messageType = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Tool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .content {
            padding: 30px;
        }
        
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        select, input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        select {
            background: white;
            cursor: pointer;
        }
        
        .password-hint {
            margin-top: 5px;
            font-size: 12px;
            color: #666;
        }
        
        .button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .button:active {
            transform: translateY(0);
        }
        
        .user-info {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .user-info-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #ddd;
        }
        
        .user-info-item:last-child {
            border-bottom: none;
        }
        
        .user-info-label {
            font-weight: 600;
            color: #555;
        }
        
        .user-info-value {
            color: #333;
        }
        
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 13px;
            border-left: 4px solid #ffc107;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
            color: #666;
        }
        
        .footer strong {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔑 Password Reset Tool</h1>
            <p>Reset password for any user in the system</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" onsubmit="return validateForm()">
                <div class="form-group">
                    <label for="user_id">👤 Select User</label>
                    <select id="user_id" name="user_id" required onchange="updateUserInfo()">
                        <option value="">-- Select a user --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    data-fullname="<?php echo htmlspecialchars($user['fullname']); ?>"
                                    data-type="<?php echo $user['user_type']; ?>"
                                    data-userid="<?php echo $user['user_id']; ?>">
                                <?php echo htmlspecialchars($user['username']); ?> 
                                (<?php echo htmlspecialchars($user['fullname']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="userInfo" class="user-info" style="display: none;">
                    <div class="user-info-item">
                        <span class="user-info-label">Full Name:</span>
                        <span class="user-info-value" id="displayFullname"></span>
                    </div>
                    <div class="user-info-item">
                        <span class="user-info-label">User Type:</span>
                        <span class="user-info-value" id="displayUserType"></span>
                    </div>
                    <div class="user-info-item">
                        <span class="user-info-label">User ID:</span>
                        <span class="user-info-value" id="displayUserId"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">🔒 New Password</label>
                    <input type="text" id="new_password" name="new_password" 
                           value="Admin_123" 
                           required
                           pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}"
                           title="Must contain at least one number, one uppercase and lowercase letter, and at least 8 characters">
                    <div class="password-hint">
                        Suggested: <strong>Admin_123</strong> (or create your own)
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">✓ Confirm Password</label>
                    <input type="text" id="confirm_password" name="confirm_password" 
                           value="Admin_123"
                           required>
                </div>
                
                <button type="submit" class="button">🔄 Reset Password</button>
            </form>
            
            <div class="warning">
                <strong>⚠️ IMPORTANT:</strong> Delete this file immediately after use! 
                This tool allows password reset for any user without current password verification.
            </div>
        </div>
        
        <div class="footer">
            <p>Fingerchops Ventures · Password Reset Tool</p>
            <p><strong>DELETE THIS FILE AFTER USE</strong></p>
        </div>
    </div>
    
    <script>
        function updateUserInfo() {
            const select = document.getElementById('user_id');
            const userInfo = document.getElementById('userInfo');
            const selectedOption = select.options[select.selectedIndex];
            
            if (select.value) {
                const fullname = selectedOption.getAttribute('data-fullname');
                const userType = selectedOption.getAttribute('data-type');
                const userId = selectedOption.getAttribute('data-userid');
                
                document.getElementById('displayFullname').textContent = fullname;
                document.getElementById('displayUserType').textContent = userType;
                document.getElementById('displayUserId').textContent = userId;
                
                userInfo.style.display = 'block';
            } else {
                userInfo.style.display = 'none';
            }
        }
        
        function validateForm() {
            const password = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const userId = document.getElementById('user_id').value;
            
            if (!userId) {
                alert('Please select a user');
                return false;
            }
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 8) {
                alert('Password must be at least 8 characters long');
                return false;
            }
            
            return confirm('Are you sure you want to reset this user\'s password?');
        }
        
        // Pre-fill the form with the admin user if it exists
        window.onload = function() {
            const select = document.getElementById('user_id');
            for (let i = 0; i < select.options.length; i++) {
                if (select.options[i].text.includes('admin')) {
                    select.selectedIndex = i;
                    updateUserInfo();
                    break;
                }
            }
        };
    </script>
</body>
</html>