<?php
// reset-password.php - Password reset page
// Version: 2.0 - Aligned with Auth.php (bcrypt, prepared statements, proper CSRF)

session_start();

require_once 'conn.php';
require_once 'includes/Security.php';
require_once 'includes/Validation.php';
require_once 'includes/Auth.php';
require_once 'includes/Helpers.php';
require_once 'config/config_loader.php';

$token = trim($_GET['token'] ?? '');
$valid = false;
$email = '';
$error = '';

// Verify token using prepared statements
if (!empty($token)) {
    $db = Database::getInstance();

    // Clean expired tokens first
    $db->preparedExecute(
        "UPDATE bakery_users SET password_reset_token = NULL, password_reset_expires = NULL
         WHERE password_reset_expires < NOW()",
        '',
        []
    );

    $row = $db->preparedFetchOne(
        "SELECT email FROM bakery_users
         WHERE password_reset_token = ? AND password_reset_expires > NOW()
         AND is_active = 1 LIMIT 1",
        's',
        [$token]
    );

    if ($row) {
        $valid = true;
        $email = $row['email'];
    } else {
        $error = 'This reset link has expired or is invalid.';
    }
}

// Handle password reset submission — delegate entirely to Auth::resetPassword()
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    header('Content-Type: application/json');

    $response = ['success' => false, 'message' => ''];

    try {
        // Use project-wide CSRF verification from Security.php
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken, 'reset_password')) {
            $response['message'] = 'Security validation failed. Please refresh and try again.';
            echo json_encode($response);
            exit;
        }

        $postToken = trim($_POST['token'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        if (empty($postToken) || empty($password) || empty($confirm)) {
            $response['message'] = 'All fields are required.';
            echo json_encode($response);
            exit;
        }

        if ($password !== $confirm) {
            $response['message'] = 'Passwords do not match.';
            echo json_encode($response);
            exit;
        }

        // Auth::resetPassword handles: token validation, password strength check,
        // history check, bcrypt hashing (no salt column), DB update,
        // session termination, and confirmation email.
        $auth   = new Auth();
        $result = $auth->resetPassword($postToken, $password);

        echo json_encode($result);

    } catch (Exception $e) {
        error_log("reset-password.php error: " . $e->getMessage());
        $response['message'] = 'An unexpected error occurred. Please try again.';
        echo json_encode($response);
    }

    exit;
}

// Generate CSRF token using project-wide generateCSRFToken() from Security.php
$csrf_token = generateCSRFToken('reset_password');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Fingerchops Ventures</title>
    <link rel="icon" type="image/jpeg" href="logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="signup.css">
    <style>
        .reset-container { max-width: 450px; margin: 100px auto; padding: 0 20px; }
        .reset-box { background: white; padding: 2.5rem; border-radius: 2rem; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
        .reset-box h2 { text-align: center; margin-bottom: 1.5rem; }
        .info-message { background: #e3f2fd; color: #0d47a1; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; text-align: center; }
        .success-message { background: #e8f5e9; color: #2e7d32; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; text-align: center; }
        .error-message { background: #ffebee; color: #c62828; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; text-align: center; }
        .loading-spinner { display: inline-block; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s ease-in-out infinite; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-primary:disabled { opacity: 0.7; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-box">
            <h2>Reset Password</h2>
            
            <?php if (!$valid && !empty($token)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error ?: 'This reset link has expired or is invalid.'); ?>
                </div>
                <p style="text-align: center;">
                    <a href="login_signup.php" class="btn btn-primary" style="text-decoration: none; display: inline-block;">
                        Back to Login
                    </a>
                </p>
            <?php elseif ($valid): ?>
                <div class="info-message">
                    <i class="fas fa-info-circle"></i>
                    Reset password for: <strong><?php echo htmlspecialchars($email); ?></strong>
                </div>
                
                <form id="resetPasswordForm">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="reset_password">
                    
                    <div class="input-group password-group">
                        <label for="password">New Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="password" name="password" class="signup-field password-input" placeholder="Enter new password" required>
                            <button type="button" class="password-toggle">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="input-group password-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" id="confirm_password" name="confirm_password" class="signup-field password-input" placeholder="Confirm new password" required>
                            <button type="button" class="password-toggle">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="password-requirements">
                        <small>Password must have:</small>
                        <ul>
                            <li id="req-length">✗ At least 8 characters</li>
                            <li id="req-uppercase">✗ One uppercase letter</li>
                            <li id="req-lowercase">✗ One lowercase letter</li>
                            <li id="req-number">✗ One number</li>
                            <li id="req-special">✗ One special character (!@#$%^&*()_-=+)</li>
                        </ul>
                    </div>
                    
                    <button type="submit" id="submitBtn" class="btn btn-primary" style="width: 100%;">
                        <span class="btn-text">Reset Password</span>
                        <span class="loading-spinner" style="display: none;"></span>
                    </button>
                </form>
                
                <div id="reset-error-container" class="form-error-container" style="margin-top: 1rem; display: none;"></div>
                <div id="reset-success-container" class="form-success-container" style="margin-top: 1rem; display: none;"></div>
            <?php else: ?>
                <p style="text-align: center;">Invalid reset link</p>
                <p style="text-align: center;">
                    <a href="login_signup.php" class="btn btn-primary" style="text-decoration: none; display: inline-block;">
                        Back to Login
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Password toggle functionality
    document.querySelectorAll('.password-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            var input = this.closest('.password-wrapper').querySelector('.password-input');
            var icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });

    // Password strength checker
    var passwordInput = document.getElementById('password');
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            var password = this.value;
            
            var checks = {
                length: document.getElementById('req-length'),
                uppercase: document.getElementById('req-uppercase'),
                lowercase: document.getElementById('req-lowercase'),
                number: document.getElementById('req-number'),
                special: document.getElementById('req-special')
            };
            
            // Length check
            var hasLength = password.length >= 8;
            checks.length.style.color = hasLength ? '#2e7d32' : '#b85c5c';
            checks.length.innerHTML = (hasLength ? '✓' : '✗') + ' At least 8 characters';
            
            // Uppercase check
            var hasUpper = /[A-Z]/.test(password);
            checks.uppercase.style.color = hasUpper ? '#2e7d32' : '#b85c5c';
            checks.uppercase.innerHTML = (hasUpper ? '✓' : '✗') + ' One uppercase letter';
            
            // Lowercase check
            var hasLower = /[a-z]/.test(password);
            checks.lowercase.style.color = hasLower ? '#2e7d32' : '#b85c5c';
            checks.lowercase.innerHTML = (hasLower ? '✓' : '✗') + ' One lowercase letter';
            
            // Number check
            var hasNumber = /[0-9]/.test(password);
            checks.number.style.color = hasNumber ? '#2e7d32' : '#b85c5c';
            checks.number.innerHTML = (hasNumber ? '✓' : '✗') + ' One number';
            
            // Special character check
            var hasSpecial = /[!@#$%^&*()\-_=+{};:,<.>]/.test(password);
            checks.special.style.color = hasSpecial ? '#2e7d32' : '#b85c5c';
            checks.special.innerHTML = (hasSpecial ? '✓' : '✗') + ' One special character';
        });
    }

    // Form submission
    document.getElementById('resetPasswordForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        var password = document.getElementById('password').value;
        var confirm = document.getElementById('confirm_password').value;
        var errorContainer = document.getElementById('reset-error-container');
        var successContainer = document.getElementById('reset-success-container');
        var submitBtn = document.getElementById('submitBtn');
        var btnText = submitBtn.querySelector('.btn-text');
        var spinner = submitBtn.querySelector('.loading-spinner');
        
        // Hide previous messages
        errorContainer.style.display = 'none';
        successContainer.style.display = 'none';
        
        if (password !== confirm) {
            errorContainer.innerHTML = '<p>Passwords do not match</p>';
            errorContainer.style.display = 'block';
            return;
        }
        
        // Show loading state
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        spinner.style.display = 'inline-block';
        
        var formData = new FormData(this);
        
        fetch('reset-password.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Hide loading state
            submitBtn.disabled = false;
            btnText.style.display = 'inline';
            spinner.style.display = 'none';
            
            if (data.success) {
                successContainer.innerHTML = '<p>' + data.message + '</p>';
                successContainer.style.display = 'block';
                
                // Redirect after 3 seconds
                setTimeout(function() {
                    window.location.href = 'login_signup.php';
                }, 3000);
            } else {
                errorContainer.innerHTML = '<p>' + data.message + '</p>';
                errorContainer.style.display = 'block';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            
            // Hide loading state
            submitBtn.disabled = false;
            btnText.style.display = 'inline';
            spinner.style.display = 'none';
            
            errorContainer.innerHTML = '<p>An error occurred. Please try again.</p>';
            errorContainer.style.display = 'block';
        });
    });
    </script>
</body>
</html>