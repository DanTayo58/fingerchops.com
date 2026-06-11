<?php
// includes/Mailer.php - Email handling class for PHP 8.3
// Version: 4.0 (PHP 8.3+ with improved SMTP handling, template system, and security)

declare(strict_types=1);

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Mailer Class - Professional email handling with SMTP and queue support
 * 
 * @package Fingerchops
 * @version 4.0
 */
class Mailer {
    private array $config;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $encryption;
    private bool $debug = false;
    private bool $useMailFunction = false;
    private string $queueTable = 'email_queue';
    private ?Database $db = null;
    
    private const PRIORITY_MAP = [
        'highest' => 1,
        'high' => 2,
        'normal' => 3,
        'low' => 4,
        'lowest' => 5,
    ];
    
    public function __construct() {
        $this->loadConfig();
        $this->initDatabase();
    }
    
    /**
     * Load mail configuration from environment or config
     */
    private function loadConfig(): void {
        if (function_exists('config')) {
            $mailConfig = config('mail', []);
            $this->host = $mailConfig['host'] ?? getenv('MAIL_HOST') ?: 'sandbox.smtp.mailtrap.io';
            $this->port = (int)($mailConfig['port'] ?? getenv('MAIL_PORT') ?: 2525);
            $this->username = $mailConfig['username'] ?? getenv('MAIL_USERNAME') ?: '';
            $this->password = $mailConfig['password'] ?? getenv('MAIL_PASSWORD') ?: '';
            $this->encryption = $mailConfig['encryption'] ?? getenv('MAIL_ENCRYPTION') ?: 'tls';
            $this->fromEmail = $mailConfig['from_email'] ?? getenv('MAIL_FROM_EMAIL') ?: 'noreply@fingerchopsng.com';
            $this->fromName = $mailConfig['from_name'] ?? getenv('MAIL_FROM_NAME') ?: 'Fingerchops Ventures';
            $this->debug = (bool)($mailConfig['debug'] ?? getenv('MAIL_DEBUG') ?: false);
        } else {
            $this->host = getenv('MAIL_HOST') ?: 'sandbox.smtp.mailtrap.io';
            $this->port = (int)(getenv('MAIL_PORT') ?: 2525);
            $this->username = getenv('MAIL_USERNAME') ?: '';
            $this->password = getenv('MAIL_PASSWORD') ?: '';
            $this->encryption = getenv('MAIL_ENCRYPTION') ?: 'tls';
            $this->fromEmail = getenv('MAIL_FROM_EMAIL') ?: 'noreply@fingerchopsng.com';
            $this->fromName = getenv('MAIL_FROM_NAME') ?: 'Fingerchops Ventures';
        }
        
        // Fallback to mail() function if no SMTP credentials
        if (empty($this->username) || empty($this->password)) {
            $this->useMailFunction = true;
            $this->log("Using mail() function as fallback (no SMTP credentials)");
        }
    }
    
    /**
     * Initialize database connection for queue
     */
    private function initDatabase(): void {
        if (class_exists('Database')) {
            $this->db = Database::getInstance();
        }
    }
    
    /**
     * Send email to single or multiple recipients
     */
    public function send(array|string $to, string $subject, string $body, array $options = []): bool {
        if (is_array($to)) {
            $success = true;
            foreach ($to as $recipient) {
                if (!$this->sendSingle($recipient, $subject, $body, $options)) {
                    $success = false;
                }
            }
            return $success;
        }
        return $this->sendSingle($to, $subject, $body, $options);
    }
    
    /**
     * Send email to a single recipient
     */
    private function sendSingle(array|string $to, string $subject, string $body, array $options = []): bool {
        $options = array_merge([
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'reply_to' => null,
            'cc' => null,
            'bcc' => null,
            'attachments' => [],
            'is_html' => true,
            'priority' => 'normal',
            'queue' => false,
            'template' => null,
        ], $options);
        
        // Queue if requested
        if ($options['queue']) {
            return $this->queueEmail($to, $subject, $body, $options);
        }
        
        $emailData = $this->prepareEmail($to, $subject, $body, $options);
        
        if ($this->useMailFunction) {
            return $this->sendViaMailFunction($emailData);
        }
        
        return $this->sendViaSMTP($emailData);
    }
    
    /**
     * Prepare email data structure
     */
    private function prepareEmail(array|string $to, string $subject, string $body, array $options): array {
        if (is_array($to)) {
            $toEmail = $to['email'] ?? $to[0] ?? '';
            $toName = $to['name'] ?? '';
        } else {
            $toEmail = $to;
            $toName = '';
        }
        
        $boundary = md5(time() . uniqid('', true));
        
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'From: ' . $this->encodeHeader($options['from_name']) . ' <' . $options['from_email'] . '>';
        $headers[] = 'Reply-To: ' . ($options['reply_to'] ?? $options['from_email']);
        $headers[] = 'X-Mailer: Fingerchops Ventures Mailer/4.0';
        $headers[] = 'X-Priority: ' . ($this->getPriorityCode($options['priority']));
        $headers[] = 'X-Originating-IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        if ($options['cc']) {
            $headers[] = 'Cc: ' . $options['cc'];
        }
        if ($options['bcc']) {
            $headers[] = 'Bcc: ' . $options['bcc'];
        }
        
        // Build message body
        if ($options['is_html']) {
            $message = $this->buildHtmlMessage($toName, $subject, $body, $options);
        } else {
            $message = $body;
        }
        
        return [
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'headers' => $headers,
            'body' => $message,
            'is_html' => $options['is_html'],
            'attachments' => $options['attachments'],
            'boundary' => $boundary,
        ];
    }
    
    /**
     * Build HTML email with template support
     */
    private function buildHtmlMessage(string $toName, string $subject, string $content, array $options): string {
        $year = date('Y');
        $toName = htmlspecialchars($toName ?: 'Valued Customer', ENT_QUOTES, 'UTF-8');
        
        // Use template if specified
        if (!empty($options['template'])) {
            $templateContent = $this->loadTemplate($options['template'], [
                'name' => $toName,
                'subject' => $subject,
                'content' => $content,
                'year' => $year,
            ]);
            if ($templateContent !== null) {
                return $templateContent;
            }
        }
        
        // Default HTML template
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$this->escapeHtml($subject)}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { text-align: center; padding: 20px 0; border-bottom: 2px solid #e2d6cb; }
        .header h2 { color: #2e241e; margin: 0; font-size: 24px; }
        .content { padding: 30px 0; }
        .footer { text-align: center; padding: 20px 0; border-top: 1px solid #e2d6cb; color: #8b6f55; font-size: 12px; }
        .button { display: inline-block; background: #2e241e; color: #fff; padding: 12px 30px; text-decoration: none; border-radius: 25px; margin: 20px 0; }
        @media (max-width: 600px) { .container { width: 100%; padding: 15px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Fingerchops Ventures</h2>
        </div>
        <div class="content">
            <p>Hello <strong>{$toName}</strong>,</p>
            {$content}
        </div>
        <div class="footer">
            &copy; {$year} Fingerchops Ventures. All rights reserved.<br>
            <small>Lagos | Abuja | Port Harcourt</small>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Load email template from file
     */
    private function loadTemplate(string $template, array $vars): ?string {
        $templatePath = dirname(__DIR__) . '/templates/email/' . $template . '.php';
        
        if (!file_exists($templatePath)) {
            $this->log("Template not found: {$template}");
            return null;
        }
        
        try {
            extract($vars, EXTR_SKIP);
            ob_start();
            include $templatePath;
            return ob_get_clean();
        } catch (Exception $e) {
            $this->logError("Template error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send email via SMTP with proper error handling
     */
    private function sendViaSMTP(array $emailData): bool {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 30);
        
        if (!$socket) {
            $this->logError("SMTP Connection failed - {$errstr} ({$errno})");
            return $this->fallbackToMail($emailData);
        }
        
        stream_set_timeout($socket, 30);
        
        try {
            // Read server greeting
            $response = $this->smtpGetResponse($socket);
            $this->debug("Server: " . trim($response));
            
            // Send EHLO
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            $response = $this->smtpGetResponse($socket);
            $this->debug("EHLO: " . trim($response));
            
            // STARTTLS if enabled
            if ($this->encryption === 'tls' && str_contains($response, 'STARTTLS')) {
                fputs($socket, "STARTTLS\r\n");
                $response = $this->smtpGetResponse($socket);
                $this->debug("STARTTLS: " . trim($response));
                
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fputs($socket, "EHLO " . gethostname() . "\r\n");
                    $response = $this->smtpGetResponse($socket);
                    $this->debug("EHLO (TLS): " . trim($response));
                }
            }
            
            // Authentication
            if (!empty($this->username)) {
                fputs($socket, "AUTH LOGIN\r\n");
                $response = $this->smtpGetResponse($socket);
                $this->debug("AUTH: " . trim($response));
                
                fputs($socket, base64_encode($this->username) . "\r\n");
                $response = $this->smtpGetResponse($socket);
                $this->debug("Username: " . trim($response));
                
                fputs($socket, base64_encode($this->password) . "\r\n");
                $response = $this->smtpGetResponse($socket);
                $this->debug("Password: " . trim($response));
                
                if (!str_starts_with($response, '235')) {
                    throw new Exception("Authentication failed: " . trim($response));
                }
            }
            
            // MAIL FROM
            fputs($socket, "MAIL FROM: <{$this->fromEmail}>\r\n");
            $response = $this->smtpGetResponse($socket);
            $this->debug("MAIL FROM: " . trim($response));
            
            // RCPT TO
            fputs($socket, "RCPT TO: <{$emailData['to_email']}>\r\n");
            $response = $this->smtpGetResponse($socket);
            $this->debug("RCPT TO: " . trim($response));
            
            // DATA
            fputs($socket, "DATA\r\n");
            $response = $this->smtpGetResponse($socket);
            $this->debug("DATA: " . trim($response));
            
            // Send email content
            $fullEmail = $this->buildFullEmail($emailData);
            fputs($socket, $fullEmail . "\r\n.\r\n");
            $response = $this->smtpGetResponse($socket);
            $this->debug("Send: " . trim($response));
            
            // QUIT
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            
            if (str_starts_with($response, '250')) {
                $this->log("Email sent successfully to {$emailData['to_email']}");
                return true;
            }
            
            throw new Exception("Message not accepted: " . trim($response));
            
        } catch (Exception $e) {
            $this->logError("SMTP Error: " . $e->getMessage());
            @fclose($socket);
            return $this->fallbackToMail($emailData);
        }
    }
    
    /**
     * Build full email message with headers and body
     */
    private function buildFullEmail(array $emailData): string {
        $email = "";
        
        foreach ($emailData['headers'] as $header) {
            $email .= $header . "\r\n";
        }
        
        $email .= "Subject: " . $this->encodeHeader($emailData['subject']) . "\r\n";
        
        // Handle attachments
        if (!empty($emailData['attachments'])) {
            $email .= "Content-Type: multipart/mixed; boundary=\"{$emailData['boundary']}\"\r\n";
            $email .= "\r\n";
            $email .= "--{$emailData['boundary']}\r\n";
        }
        
        // Content type
        if ($emailData['is_html']) {
            $email .= "Content-Type: text/html; charset=UTF-8\r\n";
        } else {
            $email .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }
        $email .= "Content-Transfer-Encoding: 8bit\r\n";
        $email .= "\r\n";
        $email .= $emailData['body'] . "\r\n";
        
        // Attachments
        if (!empty($emailData['attachments'])) {
            foreach ($emailData['attachments'] as $attachment) {
                $email .= "--{$emailData['boundary']}\r\n";
                $email .= $this->buildAttachment($attachment);
            }
            $email .= "--{$emailData['boundary']}--\r\n";
        }
        
        return $email;
    }
    
    /**
     * Build attachment part
     */
    private function buildAttachment(array $attachment): string {
        $content = "Content-Type: " . ($attachment['type'] ?? 'application/octet-stream') . 
                   "; name=\"{$attachment['name']}\"\r\n";
        $content .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n";
        $content .= "Content-Transfer-Encoding: base64\r\n";
        $content .= "\r\n";
        $content .= chunk_split(base64_encode($attachment['content'])) . "\r\n";
        return $content;
    }
    
    /**
     * Send email via PHP mail() function
     */
    private function sendViaMailFunction(array $emailData): bool {
        $to = $emailData['to_email'];
        $subject = $emailData['subject'];
        $headers = implode("\r\n", $emailData['headers']);
        
        if ($emailData['is_html']) {
            $headers .= "\r\nContent-Type: text/html; charset=UTF-8";
        }
        
        // Add additional headers for better deliverability
        $headers .= "\r\nX-Mailer: Fingerchops Ventures Mailer/4.0";
        
        $result = mail($to, $subject, $emailData['body'], $headers, "-f{$this->fromEmail}");
        
        if ($result) {
            $this->log("Email sent via mail() to {$emailData['to_email']}");
        } else {
            $this->logError("Failed to send via mail() to {$emailData['to_email']}");
        }
        
        return $result;
    }
    
    /**
     * Fallback to mail() function when SMTP fails
     */
    private function fallbackToMail(array $emailData): bool {
        $this->log("Falling back to mail() function");
        return $this->sendViaMailFunction($emailData);
    }
    
    /**
     * Queue email for later sending
     */
    private function queueEmail(array|string $to, string $subject, string $body, array $options): bool {
        if ($this->db === null) {
            $this->logError("Database not available for email queue");
            return false;
        }
        
        $toJson = is_array($to) ? json_encode($to) : $to;
        $optionsJson = json_encode($options);
        
        return $this->db->preparedExecute(
            "INSERT INTO email_queue (recipient, subject, body, options, created_at, status)
             VALUES (?, ?, ?, ?, NOW(), 'pending')",
            'ssss',
            [$toJson, $subject, $body, $optionsJson]
        );
    }
    
    /**
     * Process queued emails
     */
    public function processQueue(int $limit = 10): int {
        if ($this->db === null) {
            $this->logError("Database not available for email queue processing");
            return 0;
        }
        
        $limit = max(1, min(100, $limit));
        
        // Get pending emails
        $rows = $this->db->preparedFetchAll(
            "SELECT * FROM email_queue 
             WHERE status = 'pending' AND attempts < 3
             ORDER BY created_at ASC 
             LIMIT ?",
            'i',
            [$limit]
        );
        
        if (empty($rows)) {
            return 0;
        }
        
        $processed = 0;
        
        foreach ($rows as $row) {
            $recipient = json_decode($row['recipient'], true);
            $options = json_decode($row['options'], true) ?: [];
            
            $success = $this->send($recipient, $row['subject'], $row['body'], $options);
            
            $attempts = (int)$row['attempts'] + 1;
            
            if ($success) {
                $this->db->preparedExecute(
                    "UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = ? WHERE id = ?",
                    'ii',
                    [$attempts, $row['id']]
                );
                $processed++;
            } else {
                $status = $attempts >= 3 ? 'failed' : 'pending';
                $this->db->preparedExecute(
                    "UPDATE email_queue SET attempts = ?, last_attempt = NOW(), status = ? WHERE id = ?",
                    'isi',
                    [$attempts, $status, $row['id']]
                );
            }
        }
        
        return $processed;
    }
    
    /**
     * Get SMTP response
     */
    private function smtpGetResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }
    
    /**
     * Encode header with UTF-8 support
     */
    private function encodeHeader(string $text): string {
        if (preg_match('/[^\x20-\x7E]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }
    
    /**
     * Get priority code for X-Priority header
     */
    private function getPriorityCode(string $priority): int {
        return self::PRIORITY_MAP[$priority] ?? 3;
    }
    
    /**
     * Escape HTML special characters
     */
    private function escapeHtml(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    
    // =====================================================
    // SPECIFIC EMAIL TYPES
    // =====================================================
    
    /**
     * Send password reset email
     */
    public function sendPasswordReset(string $toEmail, string $toName, string $token): bool {
        $resetLink = $this->getResetLink($token);
        
        $content = <<<HTML
<p>We received a request to reset your password. Click the button below to create a new password:</p>
<div style="text-align: center; margin: 30px 0;">
    <a href="{$resetLink}" class="button">Reset Password</a>
</div>
<p>Or copy this link to your browser:</p>
<p style="background: #f5efe8; padding: 10px; border-radius: 5px; word-break: break-all;">
    <small><a href="{$resetLink}">{$resetLink}</a></small>
</p>
<p><strong>This link expires in 1 hour</strong></p>
<p>If you didn't request this, please ignore this email.</p>
HTML;
        
        return $this->send(
            ['email' => $toEmail, 'name' => $toName],
            'Reset Your Password - Fingerchops Ventures',
            $content,
            ['template' => 'password_reset']
        );
    }
    
    /**
     * Send welcome email to new users
     */
    public function sendWelcome(string $toEmail, string $toName, string $userType): bool {
        $loginLink = $this->getLoginLink();
        $userTypeDisplay = ucfirst($userType);
        
        $content = <<<HTML
<p>Welcome to <strong>Fingerchops Ventures</strong>!</p>
<p>Your {$userTypeDisplay} account has been created successfully. You can now log in and start using our services.</p>
<div style="text-align: center; margin: 30px 0;">
    <a href="{$loginLink}" class="button">Log In Now</a>
</div>
<p>If you have any questions, feel free to contact our support team.</p>
HTML;
        
        return $this->send(
            ['email' => $toEmail, 'name' => $toName],
            'Welcome to Fingerchops Ventures!',
            $content,
            ['template' => 'welcome']
        );
    }
    
    /**
     * Send account locked notification
     */
    public function sendAccountLocked(string $toEmail, string $toName, string $unlockTime): bool {
        $formattedTime = date('F j, Y \a\t g:i A', strtotime($unlockTime));
        
        $content = <<<HTML
<p>Your account has been temporarily locked due to multiple failed login attempts.</p>
<p>It will be automatically unlocked on: <strong>{$formattedTime}</strong></p>
<p>If you believe this was a mistake, please contact support immediately.</p>
<p>You can also reset your password to regain access:</p>
<div style="text-align: center; margin: 30px 0;">
    <a href="{$this->getResetRequestLink()}" class="button">Reset Password</a>
</div>
HTML;
        
        return $this->send(
            ['email' => $toEmail, 'name' => $toName],
            'Account Security Alert - Fingerchops Ventures',
            $content
        );
    }
    
    /**
     * Send order confirmation email
     */
    public function sendOrderConfirmation(string $toEmail, string $toName, array $orderDetails): bool {
        $orderHtml = $this->formatOrderDetails($orderDetails);
        $orderNumber = $orderDetails['order_number'] ?? $orderDetails['id'] ?? 'Unknown';
        
        $content = <<<HTML
<p>Thank you for your order! We're pleased to confirm your purchase.</p>
<h3 style="color: #2e241e; margin: 20px 0 10px;">Order Details</h3>
{$orderHtml}
<p>We'll notify you when your order is ready for pickup or out for delivery.</p>
<p>You can track your order status in your <a href="{$this->getDashboardLink()}">customer dashboard</a>.</p>
HTML;
        
        return $this->send(
            ['email' => $toEmail, 'name' => $toName],
            "Order Confirmation #{$orderNumber} - Fingerchops Ventures",
            $content,
            ['template' => 'order_confirmation']
        );
    }
    
    /**
     * Get password reset link
     */
    private function getResetLink(string $token): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $protocol . $host . $basePath . '/reset-password.php?token=' . urlencode($token);
    }
    
    /**
     * Get reset request link
     */
    private function getResetRequestLink(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $protocol . $host . $basePath . '/login_signup.php#reset';
    }
    
    /**
     * Get login link
     */
    private function getLoginLink(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $protocol . $host . $basePath . '/login_signup.php';
    }
    
    /**
     * Get dashboard link
     */
    private function getDashboardLink(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $protocol . $host . $basePath . '/dashboards/customer-dashboard.php';
    }
    
    /**
     * Format order details as HTML table
     */
    private function formatOrderDetails(array $order): string {
        $items = $order['items'] ?? [];
        $total = $order['total'] ?? 0;
        
        if (empty($items)) {
            return '<p>No items in order</p>';
        }
        
        $html = '<table style="width: 100%; border-collapse: collapse; margin: 15px 0;">';
        $html .= '<thead><tr style="border-bottom: 2px solid #2e241e;">';
        $html .= '<th style="text-align: left; padding: 8px;">Item</th>';
        $html .= '<th style="text-align: right; padding: 8px;">Qty</th>';
        $html .= '<th style="text-align: right; padding: 8px;">Price</th>';
        $html .= '<th style="text-align: right; padding: 8px;">Subtotal</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'] ?? 'Product', ENT_QUOTES);
            $qty = (int)($item['quantity'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $subtotal = $qty * $price;
            
            $html .= '<tr style="border-bottom: 1px solid #e2d6cb;">';
            $html .= "<td style=\"padding: 8px;\">{$name}</td>";
            $html .= "<td style=\"text-align: right; padding: 8px;\">{$qty}</td>";
            $html .= "<td style=\"text-align: right; padding: 8px;\">₦" . number_format($price, 2) . "</td>";
            $html .= "<td style=\"text-align: right; padding: 8px;\">₦" . number_format($subtotal, 2) . "</td>";
            $html .= '</tr>';
        }
        
        $html .= '<tr style="border-top: 2px solid #2e241e;">';
        $html .= '<td colspan="3" style="padding: 8px; text-align: right;"><strong>Total</strong></td>';
        $html .= '<td style="padding: 8px; text-align: right;"><strong>₦' . number_format($total, 2) . '</strong></td>';
        $html .= '</tr>';
        $html .= '</tbody></table>';
        
        return $html;
    }
    
    // =====================================================
    // LOGGING METHODS
    // =====================================================
    
    private function log(string $message): void {
        error_log("Mailer: " . $message);
    }
    
    private function logError(string $message): void {
        error_log("Mailer ERROR: " . $message);
    }
    
    private function debug(string $message): void {
        if ($this->debug) {
            error_log("Mailer DEBUG: " . $message);
        }
    }
}

// =====================================================
// HELPER FUNCTION
// =====================================================

if (!function_exists('createEmailQueueTable')) {
    function createEmailQueueTable(): bool {
        $db = Database::getInstance();
        
        $sql = "CREATE TABLE IF NOT EXISTS email_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient TEXT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            options TEXT,
            attempts INT DEFAULT 0,
            status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        return $db->preparedExecute($sql, '', []);
    }
}