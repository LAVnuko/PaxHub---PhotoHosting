<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

if (!isLoggedIn()) {
    redirect('/auth');
}

$user = getCurrentUser();
$error = '';
$success = '';
global $lang;

// CSRF-токен
if (empty($_SESSION['topup_csrf_token'])) {
    $_SESSION['topup_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['topup_csrf_token'];

// Обработка отправки заявки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = $lang['ERROR_CSRF'];
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? '');
        $payment_details = trim($_POST['payment_details'] ?? '');
        
        if ($amount <= 0) {
            $error = $lang['ERROR_AMOUNT_ZERO'];
        } elseif ($amount < 10) {
            $error = $lang['ERROR_MIN_AMOUNT'];
        } elseif (empty($payment_method)) {
            $error = $lang['ERROR_PAYMENT_METHOD'];
        } else {
            global $pdo;
            
            $stmt = $pdo->prepare("
                INSERT INTO topup_requests (user_id, username, amount, payment_method, payment_details, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $user['id'], 
                $user['username'], 
                $amount, 
                $payment_method, 
                $payment_details
            ]);
            
            $requestId = $pdo->lastInsertId();
            
            $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'latypov.azat102@gmail.com';
            $subject = str_replace('{id}', $requestId, $lang['EMAIL_SUBJECT']);
            
            $emailBody = "
                <html>
                <head><title>{$lang['EMAIL_TITLE']}</title></head>
                <body>
                    <h2>" . str_replace('{id}', $requestId, $lang['EMAIL_HEADING']) . "</h2>
                    <p><strong>{$lang['EMAIL_USER']}</strong> {$user['username']} (ID: {$user['id']})</p>
                    <p><strong>{$lang['EMAIL_AMOUNT']}</strong> {$amount} {$lang['CURRENCY']}</p>
                    <p><strong>{$lang['EMAIL_PAYMENT_METHOD']}</strong> {$payment_method}</p>
                    <p><strong>{$lang['EMAIL_PAYMENT_DETAILS']}</strong><br>" . nl2br(htmlspecialchars($payment_details)) . "</p>
                    <p><strong>{$lang['EMAIL_DATE']}</strong> " . date('d.m.Y H:i:s') . "</p>
                    <hr>
                    <p><a href='https://{$_SERVER['HTTP_HOST']}/admin/topup_requests.php'>{$lang['EMAIL_LINK']}</a></p>
                </body>
                </html>
            ";
            
            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: no-reply@{$_SERVER['HTTP_HOST']}\r\n";
            mail($adminEmail, $subject, $emailBody, $headers);
            
            $success = str_replace('{id}', $requestId, $lang['SUCCESS_MESSAGE']);
            
            $_SESSION['topup_csrf_token'] = bin2hex(random_bytes(32));
            $csrfToken = $_SESSION['topup_csrf_token'];
        }
    }
}

$template_data = [
    'user' => $user,
    'PROFILEUSER_BALANCE' => number_format($user['balance'], 2),
    'error' => $error,
    'success' => $success,
    'csrf_token' => $csrfToken,
    'HAS_ERROR' => !empty($error),
    'HAS_SUCCESS' => !empty($success),
    'PAGE_TITLE' => $lang['TOPUP_PAGE_TITLE'] . ' | ' . SITE_NAME,
];

renderTemplateImproved('topup_request', $template_data);