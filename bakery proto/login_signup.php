<?php
// login_signup.php - Consolidated authentication interface
// Version: 4.2 (Refactored with prepared statements, AJAX endpoints, no fallback roles)

session_start();

// Production-safe error reporting
if (defined('DEVELOPMENT_MODE') && DEVELOPMENT_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ==================== SECURITY & INCLUDES ====================
require_once 'conn.php';
require_once 'includes/Auth.php';
require_once 'includes/User.php';
require_once 'includes/Helpers.php';
require_once 'includes/DashboardRouter.php';
require_once 'includes/Security.php';
require_once 'includes/Validation.php';

// Check if already logged in using Auth class
$auth = new Auth();
if ($auth->validateSession()) {
    $user = $auth->getCurrentUser();
    $router = new DashboardRouter();
    $dashboard = $router->getDashboard(['id' => $user['id'], 'user_type' => $user['user_type']]);
    header('Location: ' . $dashboard);
    exit;
}

// Get database instance
$db = Database::getInstance();

// ==================== DYNAMIC ROLE DROPDOWN ====================
$publicRoles = [];

$rolesSql = "SELECT role_code, role_name, description, privilege_level 
             FROM roles 
             WHERE role_code IN ('CUSTOMER', 'VENDOR') 
             ORDER BY privilege_level DESC, role_name ASC";
$rolesRows = $db->preparedFetchAll($rolesSql, '', array());

foreach ($rolesRows as $row) {
    $icon = $row['role_code'] === 'CUSTOMER' ? '🍞 ' : '🥖 ';
    $displayName = $icon . $row['role_name'];
    $publicRoles[] = [
        'role_code' => $row['role_code'],
        'role_name' => $row['role_name'],
        'display_name' => $displayName,
        'value' => strtolower($row['role_code']),
        'description' => $row['description'] ?? ''
    ];
}

// No fallback – if no roles, the dropdown will be empty and signup will fail validation.

// Generate initial CSRF tokens for forms
$loginCsrfToken = generateCSRFToken('login');
$signupCsrfToken = generateCSRFToken('register');
$resetCsrfToken = generateCSRFToken('request_password_reset');
$forceCsrfToken = generateCSRFToken('force_change_password');
$logoutCsrfToken = generateCSRFToken('logout');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Fingerchops Ventures - Bakery Management System Login">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    
    <!-- Security Headers -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:;">
    
    <title>Fingerchops Ventures · Sign In / Join</title>
    <link rel="icon" type="image/jpeg" href="logo.jpeg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300..600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="signup.css">
    <link rel="stylesheet" href="TandC.css">
</head>
<body>
    <!-- Preloader -->
    <div class="preloader" id="preloader">
        <div class="preloader-logo"></div>
    </div>

    <!-- FORCE PASSWORD CHANGE MODAL -->
    <div class="force-password-modal" id="forcePasswordModal" role="dialog" aria-labelledby="forcePasswordTitle" aria-modal="true">
        <div class="force-password-content">
            <h3 id="forcePasswordTitle"><i class="fas fa-exclamation-triangle"></i> Password Expired</h3>
            <p>For security reasons, you must change your password before continuing.</p>
            
            <div id="forceUserContext" class="user-context" style="display: none; margin-bottom: 15px; padding: 10px; background: #f5efe8; border-radius: 8px;">
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-user"></i></div>
                    <div class="info-content">
                        <span class="info-label">User</span>
                        <div class="info-value" id="forceUserFullname"></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-building"></i></div>
                    <div class="info-content">
                        <span class="info-label">Department</span>
                        <div class="info-value" id="forceUserDepartment"></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="info-content">
                        <span class="info-label">Branch</span>
                        <div class="info-value" id="forceUserBranch"></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-tag"></i></div>
                    <div class="info-content">
                        <span class="info-label">Position</span>
                        <div class="info-value" id="forceUserPosition"></div>
                    </div>
                </div>
            </div>
            
            <form id="forcePasswordForm" novalidate>
                <input type="hidden" name="csrf_token" id="force_csrf_token" value="<?php echo htmlspecialchars($forceCsrfToken); ?>">
                <input type="hidden" id="logout_csrf_token" value="<?php echo htmlspecialchars($logoutCsrfToken); ?>">
                <input type="hidden" name="csrf_action" value="force_change_password">
                <input type="hidden" name="user_id" id="force_user_id" value="">
                <input type="hidden" name="action" value="change_password">
                
                <div class="input-group password-group">
                    <label for="force_current_password">Current Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="force_current_password" name="current_password" class="signup-field password-input" placeholder="Enter current password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="force-current-error"></div>
                </div>
                
                <div class="input-group password-group">
                    <label for="force_new_password">New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="force_new_password" name="new_password" class="signup-field password-input" placeholder="Enter new password" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    
                    <div class="password-strength-meter">
                        <div class="password-strength-bar" id="force-password-strength-bar"></div>
                    </div>
                    
                    <div class="password-requirements" id="force-password-requirements">
                        <small>Password must have:</small>
                        <ul>
                            <li id="force-req-length" class="req-invalid">✗ At least 8 characters</li>
                            <li id="force-req-uppercase" class="req-invalid">✗ One uppercase letter</li>
                            <li id="force-req-lowercase" class="req-invalid">✗ One lowercase letter</li>
                            <li id="force-req-number" class="req-invalid">✗ One number</li>
                            <li id="force-req-special" class="req-invalid">✗ One special character (!@#$%^&*()_-=+)</li>
                        </ul>
                    </div>
                    <div class="error-message" id="force-new-error"></div>
                </div>
                
                <div class="input-group password-group">
                    <label for="force_confirm_password">Confirm New Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="force_confirm_password" name="confirm_password" class="signup-field password-input" placeholder="Confirm new password" autocomplete="off" required>
                        <button type="button" class="password-toggle" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="force-confirm-error"></div>
                </div>
                
                <div class="button-group">
                    <button class="btn btn-primary" type="submit">Update Password</button>
                    <button class="btn btn-secondary" type="button" id="forcePasswordLogout">Logout</button>
                </div>
            </form>
            
            <div id="force-error-container" class="form-error-container" role="alert"></div>
        </div>
    </div>

    <!-- PASSWORD RESET MODAL -->
    <div class="reset-modal" id="resetModal" role="dialog" aria-labelledby="resetModalTitle" aria-modal="true">
        <div class="reset-content">
            <h3 id="resetModalTitle">Reset Password</h3>
            <p>Enter your email address and we'll send you instructions to reset your password.</p>
            
            <div id="reset-step-1">
                <form id="resetRequestForm" novalidate>
                    <input type="hidden" name="csrf_token" id="reset_csrf_token" value="<?php echo htmlspecialchars($resetCsrfToken); ?>">
                    <input type="hidden" name="csrf_action" value="request_password_reset">
                    <input type="hidden" name="action" value="request_password_reset">
                    
                    <div class="input-group">
                        <label for="reset_email">Email Address</label>
                        <input type="email" id="reset_email" name="email" class="signup-field" placeholder="e.g., ada@example.com" autocomplete="email" required>
                        <div class="error-message" id="reset-email-error"></div>
                    </div>
                    
                    <div class="button-group">
                        <button class="btn btn-primary" type="submit">Send Reset Instructions</button>
                        <button class="btn btn-secondary" type="button" id="closeResetModal">Cancel</button>
                    </div>
                </form>
            </div>
            
            <div id="reset-step-2" style="display: none;">
                <div id="reset-success-container" class="form-success-container">
                    <i class="fas fa-check-circle"></i>
                    <p>Reset instructions sent!</p>
                </div>
                
                <div id="reset-email-notice" class="email-notice" style="display: none;">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <p><strong>Didn't receive the email?</strong></p>
                        <p>Check your spam folder. If you don't see it within 5 minutes, <a href="#" id="resendResetEmail">click here to resend</a> or verify you entered the correct email address.</p>
                        <p class="email-display">Email: <span id="reset-email-display"></span> <a href="#" id="changeResetEmail">(change)</a></p>
                    </div>
                </div>
                
                <div id="resend-timer" class="resend-timer" style="display: none;">
                    <i class="fas fa-hourglass-half"></i> You can request another email in <span id="resend-countdown">5:00</span>
                </div>
                
                <button class="btn btn-secondary" id="backToResetForm">← Back</button>
            </div>
            
            <div id="reset-error-container" class="form-error-container" role="alert"></div>
        </div>
    </div>

    <!-- CARD THEMED SIGNUP PAGE -->
    <div class="card cover-right" id="toggleCard">
        <!-- LEFT: LOGIN -->
        <div class="col login-col">
            <h2>Welcome back!</h2>
            <p class="sub-text">Sign in to your Fingerchops Ventures account</p>
            <p class="hint-text">Use Email, Username, or User ID (e.g., FNG-CS-8K9M2P)</p>
            
            <form id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" id="login_csrf_token" value="<?php echo htmlspecialchars($loginCsrfToken); ?>">
                <input type="hidden" name="csrf_action" value="login">
                <input type="hidden" name="action" value="login">
                
                <div class="input-group">
                    <label for="login_username">Email / Username / User ID</label>
                    <input type="text" id="login_username" name="login_username" class="login-field" placeholder="e.g., FNG-CS-8K9M2P or ada@example.com" autocomplete="username" required>
                    <div class="error-message" id="login-username-error"></div>
                </div>
                
                <div class="input-group password-group">
                    <label for="login_password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="login_password" name="login_password" class="login-field password-input" placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="login-password-error"></div>
                </div>
                
                <div class="input-group remember-me-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember_me" id="remember_me" value="1">
                        <span class="checkbox-label">Remember me for 30 days</span>
                    </label>
                    <a href="#" id="forgotPasswordLink" class="forgot-password">Forgot password?</a>
                </div>
                
                <button class="btn btn-primary" type="submit" id="login_submit">Sign in</button>
            </form>
            
            <div id="login-error-container" class="form-error-container" role="alert"></div>
            <div id="login-lockout-container" class="lockout-message" style="display: none;" role="alert"></div>
            <div id="login-attempts-left" class="attempts-left" style="display: none;"></div>
        </div>

        <!-- RIGHT: SIGNUP -->
        <div class="col signup-col">
            <h2>Join Fingerchops Ventures</h2>
            <p class="sub-text">Register as customer or vendor</p>
            <p class="hint-text">Staff members are created by administrators only</p>

            <form id="signupForm" novalidate>
                <input type="hidden" name="csrf_token" id="signup_csrf_token" value="<?php echo htmlspecialchars($signupCsrfToken); ?>">
                <input type="hidden" name="csrf_action" value="register">
                <input type="hidden" name="action" value="register">
                
                <div class="input-group">
                    <label for="fullname">Full name</label>
                    <input type="text" id="fullname" name="fullname" class="signup-field" placeholder="e.g., Ada Eze" pattern="[A-Za-z\s\-']+" autocomplete="name" required>
                    <div class="error-message" id="fullname-error"></div>
                </div>

                <div class="input-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" class="signup-field" placeholder="e.g., ada_bakes" pattern="[A-Za-z0-9_]{3,}" autocomplete="username" required>
                    <div class="error-message" id="username-error"></div>
                    <div class="error-message" id="username-duplicate-error"></div>
                    <div class="username-suggestions" id="username-suggestions"></div>
                </div>
                
                <div class="input-group">
                    <label for="user_role">Register as</label>
                    <div class="custom-select-wrapper">
                        <select id="user_role" name="user_role" class="signup-field select-professional" required>
                            <option value="" disabled selected>– Select your account type –</option>
                            <?php foreach ($publicRoles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role['value']); ?>" 
                                        data-role-code="<?php echo htmlspecialchars($role['role_code']); ?>"
                                        data-description="<?php echo htmlspecialchars($role['description']); ?>">
                                    <?php echo htmlspecialchars($role['display_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="select-arrow"></span>
                    </div>
                    <div class="error-message" id="role-error"></div>
                    <div class="role-description" id="role-description" style="display: none; font-size: 0.8rem; color: #8b6f55; margin-top: 5px;"></div>
                </div>

                <div class="input-group">
                    <label for="phone_number">Phone (Nigeria)</label>
                    <div class="phone-wrapper">
                        <span class="phone-prefix">+234</span>
                        <input type="tel" id="phone_number" name="phone_number" class="signup-field phone-input" placeholder="8012345678" pattern="[0-9]{10}" maxlength="10" autocomplete="tel" required>
                    </div>
                    <input type="hidden" name="phone" id="full_phone" value="">
                    <div class="error-message" id="phone-error"></div>
                    <div class="error-message" id="phone-duplicate-error"></div>
                </div>

                <div class="input-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="signup-field" placeholder="e.g., ada@example.com" autocomplete="email" required>
                    <div class="error-message" id="email-error"></div>
                    <div class="error-message" id="email-duplicate-error"></div>
                </div>
                
                <div class="input-group password-group">
                    <label for="signup_password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="signup_password" name="password" class="signup-field password-input" placeholder="Create a strong password" autocomplete="new-password" required>
                        <button type="button" class="password-toggle" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    
                    <div class="password-strength-meter">
                        <div class="password-strength-bar" id="password-strength-bar"></div>
                    </div>
                    
                    <div class="password-requirements">
                        <small>Password must have:</small>
                        <ul>
                            <li id="req-length" class="req-invalid">✗ At least 8 characters</li>
                            <li id="req-uppercase" class="req-invalid">✗ One uppercase letter</li>
                            <li id="req-lowercase" class="req-invalid">✗ One lowercase letter</li>
                            <li id="req-number" class="req-invalid">✗ One number</li>
                            <li id="req-special" class="req-invalid">✗ One special character (!@#$%^&*()_-=+)</li>
                        </ul>
                    </div>
                    
                    <div class="password-match" id="password-match" style="display: none;">
                        <i class="fas fa-check"></i> Passwords match
                    </div>
                    <div class="password-mismatch" id="password-mismatch" style="display: none;">
                        <i class="fas fa-times"></i> Passwords do not match
                    </div>
                    
                    <div class="error-message" id="signup-password-error"></div>
                </div>

                <div class="input-group password-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" class="signup-field password-input" placeholder="Confirm your password" autocomplete="off" required>
                        <button type="button" class="password-toggle" tabindex="-1" aria-label="Toggle password visibility">
                            <i class="far fa-eye"></i>
                        </button>
                    </div>
                    <div class="error-message" id="confirm-password-error"></div>
                </div>

                <div class="input-group terms-group">
                    <label class="checkbox-container">
                        <input type="checkbox" name="terms" id="terms" value="1" required>
                        <span class="checkbox-label">I agree to the <a href="#" id="termsLink">Terms of Service</a> and <a href="#" id="privacyLink">Privacy Policy</a></span>
                    </label>
                    <div class="error-message" id="terms-error"></div>
                </div>

                <button class="btn btn-primary" type="submit" id="register_submit">Create account</button>
            </form>
            
            <div id="signup-error-container" class="form-error-container" role="alert"></div>
        </div>

        <!-- sliding cover -->
        <div class="sliding-cover" id="cover">
            <div class="cover-content">
                <div class="brand-logo">
                    <div class="logo-image"></div>
                    <div class="brand-name">FINGERCHOPS<br>VENTURES</div>
                </div>
                <button class="toggle-btn" id="toggleCoverBtn" type="button" aria-label="Toggle between login and signup">create account →</button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="success-modal" id="successModal" role="dialog" aria-labelledby="successTitle" aria-modal="true">
        <div class="success-content">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2 id="successTitle">Welcome!</h2>
            <div id="successMessage"></div>
            
            <div class="user-id-box" id="userIdBox" style="display: none;">
                <div class="label">Your Unique User ID</div>
                <div class="id-value" id="displayUserId"></div>
                <small>Save this ID to login</small>
            </div>
            
            <div class="user-info" id="userInfo">
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-user"></i></div>
                    <div class="info-content">
                        <span class="info-label">Name</span>
                        <div class="info-value" id="displayName"></div>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-tag"></i></div>
                    <div class="info-content">
                        <span class="info-label">Account Type</span>
                        <div class="info-value" id="displayType"></div>
                    </div>
                </div>
                
                <div class="info-row" id="displayDepartmentRow" style="display: none;">
                    <div class="info-icon"><i class="fas fa-building"></i></div>
                    <div class="info-content">
                        <span class="info-label">Department</span>
                        <div class="info-value">
                            <span id="displayDepartment"></span>
                            <span id="displayDeptBadge" class="dept-badge"></span>
                        </div>
                    </div>
                </div>
                
                <div class="info-row" id="displayBranchRow">
                    <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="info-content">
                        <span class="info-label">Branch</span>
                        <div class="info-value">
                            <span id="displayBranch"></span>
                            <span id="displayBranchBadge" class="branch-badge"></span>
                        </div>
                    </div>
                </div>
                
                <div class="info-row" id="displayPositionRow" style="display: none;">
                    <div class="info-icon"><i class="fas fa-id-badge"></i></div>
                    <div class="info-content">
                        <span class="info-label">Position</span>
                        <div class="info-value">
                            <span id="displayPosition"></span>
                            <span id="displayManagerBadge" class="manager-badge" style="display: none;">Manager</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="info-content">
                        <span class="info-label">Dashboard</span>
                        <div class="info-value" id="displayDashboard"></div>
                    </div>
                </div>
            </div>
            
            <div class="redirect-timer" id="redirectTimer">Redirecting in 3 seconds...</div>
            <button class="close-btn" onclick="closeModal()">Continue Now</button>
        </div>
    </div>

    <!-- Terms of Service - Sliding Panel -->
    <div class="terms-overlay" id="termsOverlay"></div>
    
    <div class="terms-panel" id="termsPanel">
        <div class="terms-panel-header">
            <h3>Terms of Service</h3>
            <button type="button" class="terms-panel-close" id="closeTermsPanel" aria-label="Close">×</button>
        </div>
        
        <div class="terms-panel-content">
            <div class="terms-section">
                <h4>1. Acceptance of Terms</h4>
                <p>By accessing and using Fingerchops Ventures services, you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use our services.</p>
            </div>
            
            <div class="terms-section">
                <h4>2. User Accounts</h4>
                <p>You are responsible for maintaining the confidentiality of your account credentials. You agree to provide accurate and complete information when creating an account.</p>
                <ul>
                    <li>You must be at least 18 years old to create an account</li>
                    <li>You are responsible for all activities under your account</li>
                    <li>Notify us immediately of any unauthorized use</li>
                </ul>
            </div>
            
            <div class="terms-section">
                <h4>3. Privacy Policy</h4>
                <p>Your privacy is important to us. Our Privacy Policy explains how we collect, use, and protect your personal information. By using our services, you consent to our data practices.</p>
            </div>
            
            <div class="terms-section">
                <h4>4. Service Usage</h4>
                <p>You agree to use our services only for lawful purposes and in accordance with these terms. Prohibited activities include:</p>
                <ul>
                    <li>Violating any applicable laws or regulations</li>
                    <li>Impersonating any person or entity</li>
                    <li>Interfering with the proper functioning of the service</li>
                    <li>Attempting to gain unauthorized access to our systems</li>
                </ul>
            </div>
            
            <div class="terms-section">
                <h4>5. Orders and Payments</h4>
                <p>All orders are subject to acceptance and availability. Prices are subject to change without notice. Payment must be received before order processing.</p>
            </div>
            
            <div class="terms-section">
                <h4>6. Intellectual Property</h4>
                <p>All content, trademarks, and data on this website are the property of Fingerchops Ventures. Unauthorized use is prohibited.</p>
            </div>
            
            <div class="terms-section">
                <h4>7. Limitation of Liability</h4>
                <p>Fingerchops Ventures shall not be liable for any indirect, incidental, special, consequential, or punitive damages arising from your use of our services.</p>
            </div>
            
            <div class="terms-section">
                <h4>8. Changes to Terms</h4>
                <p>We reserve the right to modify these terms at any time. Continued use of our services after changes constitutes acceptance of the new terms.</p>
            </div>
            
            <div class="terms-section">
                <h4>9. Contact Information</h4>
                <p>For questions about these Terms, please contact us at:</p>
                <p>Email: legal@fingerchops.com<br>
                Address: Lagos Mainland, Nigeria</p>
            </div>
        </div>
        
        <div class="terms-panel-footer">
            <button class="btn btn-primary" id="acceptTermsBtn">I Understand & Close</button>
        </div>
    </div>

    <script>
        // ============== ENHANCED JAVASCRIPT ==============
        
        const CONFIG = {
            MIN_PASSWORD_LENGTH: 8,
            RATE_LIMIT_WARNING: 'Too many attempts. Please wait {minutes} minutes.',
            SESSION_TIMEOUT: 1800,
            RESEND_COOLDOWN: 300
        };
        
        let csrfToken = {
            login: '<?php echo htmlspecialchars($loginCsrfToken); ?>',
            signup: '<?php echo htmlspecialchars($signupCsrfToken); ?>',
            reset: '<?php echo htmlspecialchars($resetCsrfToken); ?>',
            force: '<?php echo htmlspecialchars($forceCsrfToken); ?>',
            logout: '<?php echo htmlspecialchars($logoutCsrfToken); ?>'
        };
        let resetEmail = '';
        let resendCooldown = false;
        let countdownInterval = null;
        
        // ============== UTILITIES ==============
        
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }
        
        function setButtonLoading(button, isLoading) {
            if (isLoading) {
                button.classList.add('loading');
                button.disabled = true;
            } else {
                button.classList.remove('loading');
                button.disabled = false;
            }
        }
        
        function showError(elementId, message) {
            const el = document.getElementById(elementId);
            if (el) {
                el.innerHTML = message;
                el.style.display = message ? 'flex' : 'none';
            }
        }
        
        function clearErrors(containerId) {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = '';
                container.classList.remove('show');
            }
        }
        
        function refreshCSRFToken(actionType, elementId, tokenKey = actionType) {
            fetch('auth.php?action=refresh_csrf&action_type=' + encodeURIComponent(actionType) + '&t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    if (data.token) {
                        if (elementId) {
                            const el = document.getElementById(elementId);
                            if (el) el.value = data.token;
                        }
                        csrfToken[tokenKey] = data.token;
                    }
                })
                .catch(console.error);
        }
        
        // ============== PASSWORD STRENGTH ==============
        
        function checkPasswordStrength(password) {
            return {
                length: password.length >= CONFIG.MIN_PASSWORD_LENGTH,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()\-_=+{};:,<.>]/.test(password)
            };
        }
        
        function updatePasswordRequirements(password, prefix = '') {
            const checks = checkPasswordStrength(password);
            const strengthCount = Object.values(checks).filter(Boolean).length;
            let strengthLevel = 'weak';
            if (strengthCount >= 5) strengthLevel = 'very-strong';
            else if (strengthCount >= 4) strengthLevel = 'strong';
            else if (strengthCount >= 3) strengthLevel = 'good';
            else if (strengthCount >= 2) strengthLevel = 'fair';
            
            for (let [key, met] of Object.entries(checks)) {
                const el = document.getElementById(prefix + 'req-' + key);
                if (el) {
                    if (met) {
                        el.innerHTML = '✓ ' + el.innerHTML.substring(2).replace('✗ ', '');
                        el.classList.remove('req-invalid');
                        el.classList.add('req-valid');
                    } else {
                        if (!el.innerHTML.startsWith('✗')) {
                            el.innerHTML = '✗ ' + el.innerHTML.substring(2);
                        }
                        el.classList.remove('req-valid');
                        el.classList.add('req-invalid');
                    }
                }
            }
            
            const bar = document.getElementById(prefix + 'password-strength-bar');
            if (bar) {
                bar.className = 'password-strength-bar';
                bar.classList.add('strength-' + strengthLevel);
            }
        }
        
        function checkPasswordMatch(password, confirm) {
            const match = document.getElementById('password-match');
            const mismatch = document.getElementById('password-mismatch');
            if (!password || !confirm) {
                if (match) match.style.display = 'none';
                if (mismatch) mismatch.style.display = 'none';
                return false;
            }
            if (password === confirm) {
                if (match) match.style.display = 'block';
                if (mismatch) mismatch.style.display = 'none';
                return true;
            } else {
                if (match) match.style.display = 'none';
                if (mismatch) mismatch.style.display = 'block';
                return false;
            }
        }
        
        // ============== FORM VALIDATION ==============
        
        function validateLoginForm() {
            let valid = true;
            const username = document.getElementById('login_username');
            const password = document.getElementById('login_password');
            showError('login-username-error', '');
            showError('login-password-error', '');
            if (!username.value.trim()) {
                showError('login-username-error', 'Username/email/ID is required');
                valid = false;
            }
            if (!password.value) {
                showError('login-password-error', 'Password is required');
                valid = false;
            }
            return valid;
        }
        
        function validateSignupForm() {
            let valid = true;
            document.querySelectorAll('.error-message:not([id*="duplicate"])').forEach(el => el.innerHTML = '');
            
            const fullname = document.getElementById('fullname');
            if (!fullname.value.trim()) {
                showError('fullname-error', 'Full name is required');
                valid = false;
            } else if (!/^[A-Za-z\s\-']+$/.test(fullname.value)) {
                showError('fullname-error', 'Name should contain only letters, spaces, hyphens, and apostrophes');
                valid = false;
            }
            
            const username = document.getElementById('username');
            if (!username.value.trim()) {
                showError('username-error', 'Username is required');
                valid = false;
            } else if (!/^[A-Za-z0-9_]{3,}$/.test(username.value)) {
                showError('username-error', 'Username must be at least 3 characters and contain only letters, numbers, underscores');
                valid = false;
            }
            
            const role = document.getElementById('user_role');
            if (!role.value) {
                showError('role-error', 'Please select an account type');
                valid = false;
            }
            
            const phone = document.getElementById('phone_number');
            if (!phone.value) {
                showError('phone-error', 'Phone number is required');
                valid = false;
            } else if (!/^\d{10}$/.test(phone.value)) {
                showError('phone-error', 'Phone number must be exactly 10 digits');
                valid = false;
            } else {
                document.getElementById('full_phone').value = '+234' + phone.value;
            }
            
            const email = document.getElementById('email');
            if (!email.value) {
                showError('email-error', 'Email is required');
                valid = false;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
                showError('email-error', 'Please enter a valid email address');
                valid = false;
            }
            
            const password = document.getElementById('signup_password').value;
            const checks = checkPasswordStrength(password);
            if (!password) {
                showError('signup-password-error', 'Password is required');
                valid = false;
            } else if (!Object.values(checks).every(Boolean)) {
                showError('signup-password-error', 'Password must meet all requirements below');
                valid = false;
            }
            
            const confirm = document.getElementById('confirm_password').value;
            if (password !== confirm) {
                showError('confirm-password-error', 'Passwords do not match');
                valid = false;
            }
            
            const terms = document.getElementById('terms');
            if (!terms.checked) {
                showError('terms-error', 'You must agree to the Terms of Service');
                valid = false;
            }
            
            if (document.getElementById('email-duplicate-error').innerHTML) valid = false;
            if (document.getElementById('phone-duplicate-error').innerHTML) valid = false;
            if (document.getElementById('username-duplicate-error').innerHTML) valid = false;
            
            return valid;
        }
        
        // ============== DUPLICATE CHECKS (AJAX) ==============
        
        const checkEmailDuplicate = debounce(function(email) {
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;
            fetch('auth.php?action=check_email&email=' + encodeURIComponent(email) + '&t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    const err = document.getElementById('email-duplicate-error');
                    err.innerHTML = data.exists ? 'This email is already registered.' : '';
                })
                .catch(() => {});
        }, 500);
        
        const checkPhoneDuplicate = debounce(function(phone) {
            if (!phone || !/^\d{10}$/.test(phone)) return;
            const fullPhone = '+234' + phone;
            fetch('auth.php?action=check_phone&phone=' + encodeURIComponent(fullPhone) + '&t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    const err = document.getElementById('phone-duplicate-error');
                    err.innerHTML = data.exists ? 'This phone number is already registered.' : '';
                })
                .catch(() => {});
        }, 500);
        
        const checkUsernameDuplicate = debounce(function(username) {
            if (!username || !/^[A-Za-z0-9_]{3,}$/.test(username)) return;
            fetch('auth.php?action=check_username&username=' + encodeURIComponent(username) + '&t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    const err = document.getElementById('username-duplicate-error');
                    const suggestionsDiv = document.getElementById('username-suggestions');
                    if (data.exists) {
                        err.innerHTML = 'This username is already taken.';
                        if (data.suggestions && data.suggestions.length) {
                            suggestionsDiv.innerHTML = 'Suggestions: ' + data.suggestions.map(s => 
                                '<span class="username-suggestion" onclick="document.getElementById(\'username\').value=\'' + s + '\'; checkUsernameDuplicate(\'' + s + '\');">' + s + '</span>'
                            ).join('');
                        } else {
                            suggestionsDiv.innerHTML = '';
                        }
                    } else {
                        err.innerHTML = '';
                        suggestionsDiv.innerHTML = '';
                    }
                })
                .catch(() => {});
        }, 500);
        
        // ============== MODAL FUNCTIONS ==============
        
        function showSuccessModal(data, isLogin) {
            const modal = document.getElementById('successModal');
            const title = document.getElementById('successTitle');
            const message = document.getElementById('successMessage');
            const userIdBox = document.getElementById('userIdBox');
            const displayUserId = document.getElementById('displayUserId');
            const displayName = document.getElementById('displayName');
            const displayType = document.getElementById('displayType');
            const displayDashboard = document.getElementById('displayDashboard');
            const redirectTimer = document.getElementById('redirectTimer');
            
            const displayDepartmentRow = document.getElementById('displayDepartmentRow');
            const displayDepartment = document.getElementById('displayDepartment');
            const displayDeptBadge = document.getElementById('displayDeptBadge');
            const displayBranch = document.getElementById('displayBranch');
            const displayBranchBadge = document.getElementById('displayBranchBadge');
            const displayPositionRow = document.getElementById('displayPositionRow');
            const displayPosition = document.getElementById('displayPosition');
            const displayManagerBadge = document.getElementById('displayManagerBadge');
            
            if (isLogin) {
                title.textContent = 'Welcome Back!';
                message.innerHTML = '<p>' + (data.message || 'Login successful!') + '</p>';
                userIdBox.style.display = 'none';
            } else {
                title.textContent = 'Registration Successful!';
                message.innerHTML = '<p>Your account has been created.</p>';
                userIdBox.style.display = 'block';
                displayUserId.textContent = data.user_id || data.user?.id || '';
            }
            
            const userData = data.user || data;
            const displayInfo = data.display_info || null;
            
            displayName.textContent = displayInfo?.user?.fullname || userData.fullname || data.fullname || 'User';
            let userType = displayInfo?.user?.user_type || userData.user_type || data.user_type || 'user';
            displayType.textContent = userType.charAt(0).toUpperCase() + userType.slice(1);
            
            if (displayInfo?.department?.name) {
                displayDepartmentRow.style.display = 'flex';
                displayDepartment.textContent = displayInfo.department.name;
                displayDeptBadge.textContent = displayInfo.department.code || '';
                displayDeptBadge.className = 'dept-badge ' + (displayInfo.department.code || '');
            } else if (data.department?.name) {
                displayDepartmentRow.style.display = 'flex';
                displayDepartment.textContent = data.department.name;
                displayDeptBadge.textContent = data.department.code || '';
                displayDeptBadge.className = 'dept-badge ' + (data.department.code || '');
            } else {
                displayDepartmentRow.style.display = 'none';
            }
            
            if (displayInfo?.branch?.name) {
                displayBranch.textContent = displayInfo.branch.name;
                displayBranchBadge.textContent = displayInfo.branch.code || 'HQ';
            } else if (data.branch?.name) {
                displayBranch.textContent = data.branch.name;
                displayBranchBadge.textContent = data.branch.code || 'HQ';
            } else {
                displayBranch.textContent = 'Headquarters';
                displayBranchBadge.textContent = 'HQ';
            }
            
            if (displayInfo?.position?.title) {
                displayPositionRow.style.display = 'flex';
                displayPosition.textContent = displayInfo.position.title;
                displayManagerBadge.style.display = displayInfo.position.is_manager ? 'inline-block' : 'none';
            } else if (data.position?.title) {
                displayPositionRow.style.display = 'flex';
                displayPosition.textContent = data.position.title;
                displayManagerBadge.style.display = data.position.is_manager ? 'inline-block' : 'none';
            } else {
                displayPositionRow.style.display = 'none';
            }
            
            let dashboardName = 'Dashboard';
            if (displayInfo?.dashboard?.name) dashboardName = displayInfo.dashboard.name;
            else if (data.dashboard_name) dashboardName = data.dashboard_name;
            else if (userType === 'admin' || userType === 'staff') dashboardName = 'Staff Dashboard';
            else if (userType === 'customer') dashboardName = 'Customer Dashboard';
            else if (userType === 'vendor') dashboardName = 'Vendor Dashboard';
            displayDashboard.innerHTML = '<strong>' + dashboardName + '</strong>';
            
            modal.classList.add('show');
            let seconds = 3;
            redirectTimer.textContent = 'Redirecting in ' + seconds + ' seconds...';
            const timer = setInterval(() => {
                seconds--;
                if (seconds > 0) {
                    redirectTimer.textContent = 'Redirecting in ' + seconds + ' seconds...';
                } else {
                    clearInterval(timer);
                    if (data.redirect) window.location.href = data.redirect;
                }
            }, 1000);
            setTimeout(() => { if (data.redirect) window.location.href = data.redirect; }, 3000);
        }
        
        function showForcePasswordModal(userId, userData) {
            const modal = document.getElementById('forcePasswordModal');
            document.getElementById('force_user_id').value = userId;
            const contextDiv = document.getElementById('forceUserContext');
            if (userData) {
                contextDiv.style.display = 'block';
                document.getElementById('forceUserFullname').textContent = userData.fullname || (userData.user ? userData.user.fullname : '');
                if (userData.department) {
                    document.getElementById('forceUserDepartment').innerHTML = userData.department.name + '<span class="dept-badge ' + userData.department.code + '">' + userData.department.code + '</span>';
                } else {
                    document.getElementById('forceUserDepartment').innerHTML = 'N/A';
                }
                if (userData.branch) {
                    document.getElementById('forceUserBranch').innerHTML = userData.branch.name + '<span class="branch-badge">' + userData.branch.code + '</span>';
                } else {
                    document.getElementById('forceUserBranch').innerHTML = 'Headquarters';
                }
                if (userData.position) {
                    const positionHtml = userData.position.title + (userData.position.is_manager ? '<span class="manager-badge">Manager</span>' : '');
                    document.getElementById('forceUserPosition').innerHTML = positionHtml;
                } else {
                    document.getElementById('forceUserPosition').innerHTML = 'Staff';
                }
            }
            modal.classList.add('show');
            refreshCSRFToken('force_change_password', 'force_csrf_token', 'force');
            setTimeout(() => document.getElementById('force_current_password').focus(), 100);
        }
        
        function closeModal() {
            document.getElementById('successModal').classList.remove('show');
        }
        
        function openTermsPanel() {
            document.getElementById('termsOverlay').classList.add('show');
            document.getElementById('termsPanel').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        
        function closeTermsPanel() {
            document.getElementById('termsOverlay').classList.remove('show');
            document.getElementById('termsPanel').classList.remove('open');
            document.body.style.overflow = '';
        }
        
        // ============== PASSWORD RESET ==============
        
        function startResendCountdown(seconds = CONFIG.RESEND_COOLDOWN) {
            resendCooldown = true;
            const timerDiv = document.getElementById('resend-timer');
            const countdownSpan = document.getElementById('resend-countdown');
            const resendLink = document.getElementById('resendResetEmail');
            timerDiv.style.display = 'block';
            if (resendLink) {
                resendLink.style.pointerEvents = 'none';
                resendLink.style.opacity = '0.5';
            }
            function updateTimer() {
                const minutes = Math.floor(seconds / 60);
                const rem = seconds % 60;
                countdownSpan.textContent = minutes + ':' + (rem < 10 ? '0' : '') + rem;
            }
            updateTimer();
            countdownInterval = setInterval(() => {
                seconds--;
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    timerDiv.style.display = 'none';
                    resendCooldown = false;
                    if (resendLink) {
                        resendLink.style.pointerEvents = 'auto';
                        resendLink.style.opacity = '1';
                    }
                } else {
                    updateTimer();
                }
            }, 1000);
        }
        
        function sendPasswordResetEmail(email) {
            const formData = new FormData();
            formData.append('action', 'request_password_reset');
            formData.append('email', email);
            formData.append('csrf_token', document.getElementById('reset_csrf_token').value);
            formData.append('csrf_action', 'request_password_reset');
            return fetch('auth.php', { method: 'POST', body: formData }).then(res => res.json());
        }
        
        // ============== EVENT LISTENERS ==============
        
        document.addEventListener('DOMContentLoaded', function() {
            // Check existing session
            fetch('auth.php?action=check_session&t=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    if (data.authenticated && data.valid) {
                        fetch('auth.php?action=get_dashboard_info&t=' + Date.now())
                            .then(res => res.json())
                            .then(dashData => {
                                if (dashData.success && dashData.display_info?.dashboard?.path) {
                                    window.location.href = dashData.display_info.dashboard.path;
                                } else {
                                    window.location.href = 'dashboards/customer-dashboard.php';
                                }
                            })
                            .catch(() => window.location.href = 'dashboards/customer-dashboard.php');
                    }
                })
                .catch(() => {});
            
            setTimeout(() => document.getElementById('preloader')?.classList.add('fade-out'), 500);
            
            // Password toggles
            document.querySelectorAll('.password-toggle').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const wrapper = this.closest('.password-wrapper');
                    const input = wrapper.querySelector('.password-input');
                    const icon = this.querySelector('i');
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
            
            // Password strength
            const signupPass = document.getElementById('signup_password');
            const confirmPass = document.getElementById('confirm_password');
            if (signupPass) {
                signupPass.addEventListener('input', function() {
                    updatePasswordRequirements(this.value, '');
                    if (confirmPass.value) checkPasswordMatch(this.value, confirmPass.value);
                });
            }
            if (confirmPass) {
                confirmPass.addEventListener('input', function() {
                    checkPasswordMatch(signupPass.value, this.value);
                });
            }
            const forcePass = document.getElementById('force_new_password');
            if (forcePass) {
                forcePass.addEventListener('input', function() {
                    updatePasswordRequirements(this.value, 'force-');
                });
            }
            
            // Duplicate checks
            document.getElementById('email')?.addEventListener('blur', function() {
                checkEmailDuplicate(this.value.trim());
            });
            document.getElementById('phone_number')?.addEventListener('blur', function() {
                checkPhoneDuplicate(this.value.trim());
            });
            document.getElementById('username')?.addEventListener('blur', function() {
                checkUsernameDuplicate(this.value.trim());
            });
            
            // Login form
            document.getElementById('loginForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!validateLoginForm()) return;
                const submitBtn = document.getElementById('login_submit');
                setButtonLoading(submitBtn, true);
                const formData = new FormData(this);
                fetch('auth.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        setButtonLoading(submitBtn, false);
                        clearErrors('login-error-container');
                        const lockout = document.getElementById('login-lockout-container');
                        lockout.style.display = 'none';
                        if (data.success) {
                            if (data.force_password_change) {
                                fetch('auth.php?action=get_dashboard_info&t=' + Date.now())
                                    .then(res => res.json())
                                    .then(userData => showForcePasswordModal(data.user_id, userData.display_info || null))
                                    .catch(() => showForcePasswordModal(data.user_id, null));
                            } else {
                                showSuccessModal(data, true);
                            }
                        } else {
                            const errContainer = document.getElementById('login-error-container');
                            errContainer.classList.add('show');
                            if (data.errors && Array.isArray(data.errors)) {
                                data.errors.forEach(err => { const p = document.createElement('p'); p.textContent = err; errContainer.appendChild(p); });
                            } else if (data.message) {
                                const p = document.createElement('p'); p.textContent = data.message; errContainer.appendChild(p);
                            }
                            if (data.locked) {
                                lockout.innerHTML = data.message;
                                lockout.style.display = 'block';
                            }
                            if (data.attempts_left !== undefined) {
                                const attemptsEl = document.getElementById('login-attempts-left');
                                attemptsEl.style.display = 'block';
                                if (data.attempts_left <= 3) {
                                    attemptsEl.innerHTML = '⚠️ ' + data.attempts_left + ' login attempts remaining before account lockout';
                                    attemptsEl.style.background = '#fff3cd';
                                }
                            }
                            refreshCSRFToken('login', 'login_csrf_token', 'login');
                        }
                    })
                    .catch(err => { setButtonLoading(submitBtn, false); console.error(err); alert('An error occurred. Please try again.'); });
            });
            
            // Signup form
            document.getElementById('signupForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!validateSignupForm()) return;
                const submitBtn = document.getElementById('register_submit');
                setButtonLoading(submitBtn, true);
                const formData = new FormData(this);
                fetch('auth.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        setButtonLoading(submitBtn, false);
                        clearErrors('signup-error-container');
                        if (data.success) {
                            refreshCSRFToken('register', 'signup_csrf_token', 'signup');
                            showSuccessModal(data, false);
                        } else {
                            const errContainer = document.getElementById('signup-error-container');
                            errContainer.classList.add('show');
                            if (data.errors) {
                                if (Array.isArray(data.errors)) {
                                    data.errors.forEach(err => { const p = document.createElement('p'); p.textContent = err; errContainer.appendChild(p); });
                                } else if (typeof data.errors === 'object') {
                                    for (let [field, msg] of Object.entries(data.errors)) {
                                        const fieldErr = document.getElementById(field + '-error');
                                        if (fieldErr) fieldErr.innerHTML = msg;
                                        else { const p = document.createElement('p'); p.textContent = field + ': ' + msg; errContainer.appendChild(p); }
                                    }
                                }
                            } else if (data.message) {
                                const p = document.createElement('p'); p.textContent = data.message; errContainer.appendChild(p);
                            }
                            errContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    })
                    .catch(err => { setButtonLoading(submitBtn, false); console.error(err); alert('An error occurred. Please try again.'); });
            });
            
            // Force password change
            document.getElementById('forcePasswordForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                const newPass = document.getElementById('force_new_password').value;
                const confirm = document.getElementById('force_confirm_password').value;
                if (newPass !== confirm) {
                    document.getElementById('force-confirm-error').innerHTML = 'Passwords do not match';
                    return;
                }
                const checks = checkPasswordStrength(newPass);
                if (!Object.values(checks).every(Boolean)) {
                    document.getElementById('force-new-error').innerHTML = 'Password does not meet requirements';
                    return;
                }
                const submitBtn = this.querySelector('button[type="submit"]');
                setButtonLoading(submitBtn, true);
                const formData = new FormData(this);
                
                // Debug: log all form fields
                console.log('Password change form data:');
                for (let [key, value] of formData.entries()) {
                    console.log(`  ${key}: ${key === 'csrf_token' ? '[HIDDEN]' : value === 'change_password' ? '[hidden]' : key.includes('password') ? '[PASSWORD]' : value}`);
                }
                console.log('Form fields by ID:');
                console.log(`  user_id: ${document.getElementById('force_user_id').value}`);
                console.log(`  current_password populated: ${document.getElementById('force_current_password').value.length > 0}`);
                console.log(`  new_password populated: ${document.getElementById('force_new_password').value.length > 0}`);
                
                fetch('auth.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        console.log('Password change response:', data);
                        setButtonLoading(submitBtn, false);
                        clearErrors('force-error-container');
                        if (data.success) {
                            document.getElementById('forcePasswordModal').classList.remove('show');
                            fetch('auth.php?action=get_dashboard_info&t=' + Date.now())
                                .then(res => res.json())
                                .then(userData => {
                                    if (userData.success) {
                                        showSuccessModal({
                                            message: 'Password changed successfully!',
                                            redirect: userData.display_info.dashboard.path,
                                            fullname: userData.display_info.user.fullname,
                                            user_type: userData.display_info.user.user_type,
                                            department: userData.display_info.department,
                                            branch: userData.display_info.branch,
                                            position: userData.display_info.position,
                                            dashboard_name: userData.display_info.dashboard.name
                                        }, true);
                                    } else {
                                        window.location.href = 'dashboards/customer-dashboard.php';
                                    }
                                })
                                .catch(() => window.location.href = 'dashboards/customer-dashboard.php');
                        } else {
                            const errContainer = document.getElementById('force-error-container');
                            errContainer.classList.add('show');
                            if (data.errors && Array.isArray(data.errors)) {
                                data.errors.forEach(err => { const p = document.createElement('p'); p.textContent = err; errContainer.appendChild(p); });
                            } else if (data.message) {
                                const p = document.createElement('p'); p.textContent = data.message; errContainer.appendChild(p);
                            }
                            if (data.debug) {
                                const p = document.createElement('p'); 
                                p.textContent = '[DEBUG] ' + data.debug; 
                                p.style.color = '#cc00cc'; 
                                p.style.fontSize = '0.9em';
                                errContainer.appendChild(p);
                                console.error('[DEBUG] Password change error:', data.debug);
                            }
                            refreshCSRFToken('force_change_password', 'force_csrf_token', 'force');
                        }
                    })
                    .catch(err => { setButtonLoading(submitBtn, false); console.error('Fetch error:', err); alert('An error occurred. Please try again.'); });
            });
            
            // Password reset request
            document.getElementById('resetRequestForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                const email = document.getElementById('reset_email').value.trim();
                const emailError = document.getElementById('reset-email-error');
                emailError.innerHTML = '';
                if (!email) { emailError.innerHTML = 'Email is required'; return; }
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { emailError.innerHTML = 'Invalid email format'; return; }
                const submitBtn = this.querySelector('button[type="submit"]');
                setButtonLoading(submitBtn, true);
                resetEmail = email;
                document.getElementById('reset-email-display').textContent = email;
                sendPasswordResetEmail(email)
                    .then(data => {
                        setButtonLoading(submitBtn, false);
                        clearErrors('reset-error-container');
                        if (data.success) {
                            document.getElementById('reset-step-1').style.display = 'none';
                            document.getElementById('reset-step-2').style.display = 'block';
                            document.getElementById('reset-email-notice').style.display = 'flex';
                            startResendCountdown(CONFIG.RESEND_COOLDOWN);
                            refreshCSRFToken('request_password_reset', 'reset_csrf_token', 'reset');
                        } else {
                            const errContainer = document.getElementById('reset-error-container');
                            errContainer.classList.add('show');
                            const p = document.createElement('p'); p.textContent = data.message || 'An error occurred'; errContainer.appendChild(p);
                        }
                    })
                    .catch(err => { setButtonLoading(submitBtn, false); console.error(err); alert('An error occurred. Please try again.'); });
            });
            
            // Other UI event listeners
            document.getElementById('forcePasswordLogout')?.addEventListener('click', function() {
                const formData = new FormData();
                formData.append('action', 'logout');
                formData.append('csrf_token', csrfToken.logout);
                formData.append('csrf_action', 'logout');
                fetch('auth.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = 'login_signup.php';
                        } else {
                            const errContainer = document.getElementById('force-error-container');
                            errContainer.classList.add('show');
                            errContainer.innerHTML = '<p>' + (data.message || 'Logout failed. Please try again.') + '</p>';
                            if (data.code === 'INVALID_CSRF_TOKEN') {
                                refreshCSRFToken('logout', 'logout_csrf_token', 'logout');
                            }
                        }
                    })
                    .catch(() => {
                        const errContainer = document.getElementById('force-error-container');
                        errContainer.classList.add('show');
                        errContainer.innerHTML = '<p>Unable to logout at this time. Please try again.</p>';
                    });
            });
            document.getElementById('forgotPasswordLink')?.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('resetModal').classList.add('show');
                document.getElementById('reset-step-1').style.display = 'block';
                document.getElementById('reset-step-2').style.display = 'none';
                document.getElementById('reset-email-error').innerHTML = '';
                clearErrors('reset-error-container');
                if (countdownInterval) clearInterval(countdownInterval);
                resendCooldown = false;
                refreshCSRFToken('request_password_reset', 'reset_csrf_token', 'reset');
            });
            document.getElementById('closeResetModal')?.addEventListener('click', () => document.getElementById('resetModal').classList.remove('show'));
            document.getElementById('backToResetForm')?.addEventListener('click', function() {
                document.getElementById('reset-step-1').style.display = 'block';
                document.getElementById('reset-step-2').style.display = 'none';
                document.getElementById('reset-email-notice').style.display = 'none';
                document.getElementById('resend-timer').style.display = 'none';
                if (countdownInterval) clearInterval(countdownInterval);
                resendCooldown = false;
            });
            document.getElementById('resendResetEmail')?.addEventListener('click', function(e) {
                e.preventDefault();
                if (resendCooldown) return;
                if (resetEmail) {
                    sendPasswordResetEmail(resetEmail).then(data => {
                        if (data.success) {
                            startResendCountdown(CONFIG.RESEND_COOLDOWN);
                            const notice = document.getElementById('reset-email-notice');
                            const original = notice.innerHTML;
                            notice.innerHTML = '<i class="fas fa-check-circle"></i> <div><p>Reset email resent! Check your inbox.</p></div>';
                            setTimeout(() => notice.innerHTML = original, 3000);
                        }
                    });
                }
            });
            document.getElementById('changeResetEmail')?.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('reset-step-1').style.display = 'block';
                document.getElementById('reset-step-2').style.display = 'none';
                document.getElementById('reset_email').value = '';
                document.getElementById('reset_email').focus();
            });
            
            // Terms panel
            document.getElementById('termsLink')?.addEventListener('click', e => { e.preventDefault(); openTermsPanel(); });
            document.getElementById('privacyLink')?.addEventListener('click', e => { e.preventDefault(); openTermsPanel(); });
            document.getElementById('closeTermsPanel')?.addEventListener('click', closeTermsPanel);
            document.getElementById('acceptTermsBtn')?.addEventListener('click', closeTermsPanel);
            document.getElementById('termsOverlay')?.addEventListener('click', closeTermsPanel);
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTermsPanel(); });
            
            // Cover toggle
            const card = document.getElementById('toggleCard');
            const toggleBtn = document.getElementById('toggleCoverBtn');
            let isCoverRight = true;
            function clearFields(selector) {
                document.querySelectorAll(selector).forEach(field => {
                    if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                        const roleDesc = document.getElementById('role-description');
                        if (roleDesc) roleDesc.style.display = 'none';
                    } else field.value = '';
                });
                document.querySelectorAll('.error-message').forEach(el => el.innerHTML = '');
                document.querySelectorAll('.form-error-container').forEach(el => { el.innerHTML = ''; el.classList.remove('show'); });
            }
            function setCoverState(right) {
                if (right) card.classList.add('cover-right');
                else card.classList.remove('cover-right');
                isCoverRight = right;
                toggleBtn.textContent = isCoverRight ? 'create account →' : '← sign in';
            }
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    if (isCoverRight) clearFields('.signup-field');
                    else clearFields('.login-field');
                    setCoverState(!isCoverRight);
                });
            }
            setCoverState(true);
            
            // Role description
            function setupRoleDescription() {
                const roleSelect = document.getElementById('user_role');
                const roleDescDiv = document.getElementById('role-description');
                if (roleSelect && roleDescDiv) {
                    roleSelect.addEventListener('change', function() {
                        const selected = this.options[this.selectedIndex];
                        const desc = selected.getAttribute('data-description');
                        if (desc && this.value) {
                            roleDescDiv.style.display = 'block';
                            roleDescDiv.innerHTML = '<i class="fas fa-info-circle"></i> ' + desc;
                        } else roleDescDiv.style.display = 'none';
                    });
                }
            }
            setupRoleDescription();
            
            // Close modals on outside click
            window.addEventListener('click', event => {
                const force = document.getElementById('forcePasswordModal');
                const reset = document.getElementById('resetModal');
                const success = document.getElementById('successModal');
                if (event.target === force) force.classList.remove('show');
                if (event.target === reset) reset.classList.remove('show');
                if (event.target === success) success.classList.remove('show');
            });
        });
    </script>
</body>
</html>