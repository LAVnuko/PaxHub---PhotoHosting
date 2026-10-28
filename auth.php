<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';
if (!defined('DEBUG_MODE')) define('DEBUG_MODE', true);
if (session_status() === PHP_SESSION_NONE) session_start();

global $pdo, $lang;

$action = $_GET['action'] ?? 'login';
$error = '';

// Получение настроек reCAPTCHA
$recaptcha_enabled = getSetting('recaptcha_enabled') == '1';
$recaptcha_site_key = getSetting('recaptcha_site_key');
$recaptcha_secret_key = getSetting('recaptcha_secret_key');

/**
 * Функция проверки reCAPTCHA
 */
function verifyRecaptcha($response, $secret) {
    if (empty($response)) return false;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'secret'   => $secret,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($resp, true);
    return $result['success'] ?? false;
}

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ---------- ЛОГИН ----------
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = $lang['ERROR_EMPTY_FIELDS'];
        }
        // Проверка капчи для логина
        elseif ($recaptcha_enabled) {
            $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
            if (!verifyRecaptcha($recaptcha_response, $recaptcha_secret_key)) {
                $error = $lang['ERROR_CAPTCHA_FAILED'] ?? 'Ошибка проверки каптчи';
            }
        }
        
        if (empty($error)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                if (defined('TEMPLATE_CACHE_DIR') && is_dir(TEMPLATE_CACHE_DIR)) {
                    clearDirectory(TEMPLATE_CACHE_DIR);
                }
                if (defined('CACHE_DIR') && is_dir(CACHE_DIR . 'data/')) {
                    clearDirectory(CACHE_DIR . 'data/');
                }
                redirect('/profile');
            } else {
                $error = $lang['ERROR_INVALID_CREDENTIALS'];
            }
        }
        
    // ---------- РЕГИСТРАЦИЯ ----------
    } elseif ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if (empty($username) || empty($email) || empty($password)) {
            $error = $lang['ERROR_EMPTY_FIELDS'];
        } elseif ($password !== $confirm) {
            $error = $lang['ERROR_PASSWORDS_MISMATCH'];
        } elseif (strlen($password) < 8) {
            $error = $lang['ERROR_PASSWORD_TOO_SHORT'];
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password)) {
            $error = $lang['ERROR_WEAK_PASSWORD'] ?? 'Пароль должен содержать минимум 8 символов, включая заглавные и строчные буквы, цифры и спецсимволы.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = $lang['ERROR_INVALID_EMAIL'];
        } else {
            $domain = substr(strrchr($email, "@"), 1);
            if (!checkdnsrr($domain, "MX")) {
                $error = $lang['ERROR_INVALID_EMAIL_DOMAIN'];
            } elseif ($recaptcha_enabled) {
                $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
                if (!verifyRecaptcha($recaptcha_response, $recaptcha_secret_key)) {
                    $error = $lang['ERROR_CAPTCHA_FAILED'] ?? 'Ошибка проверки каптчи';
                }
            }
        }
        
        if (empty($error)) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = $lang['ERROR_USER_EXISTS'];
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, registered_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$username, $email, $hash]);
                $userId = $pdo->lastInsertId();
                if (function_exists('getUserFolder')) getUserFolder($userId);
                if (function_exists('getUserAvatarFolder')) getUserAvatarFolder($userId);
                $_SESSION['user_id'] = $userId;
                if (defined('TEMPLATE_CACHE_DIR') && is_dir(TEMPLATE_CACHE_DIR)) clearDirectory(TEMPLATE_CACHE_DIR);
                if (defined('CACHE_DIR') && is_dir(CACHE_DIR . 'data/')) clearDirectory(CACHE_DIR . 'data/');
                redirect('/profile');
            }
        }
    }
}

// Определяем, какую форму показывать
$showAuthForm = ($action === 'login');
$showRegisterForm = ($action === 'register');

$template_data = [
    'PAGE_TITLE' => $showAuthForm ? $lang['AUTH_TITLE'] : $lang['REGISTER_TITLE']. ' | ' . SITE_NAME,
    'AUTH_BUTTON' => $showAuthForm ? $lang['LOGIN_BUTTON'] : $lang['REGISTER_BUTTON'],
    'IS_AUTH' => $showAuthForm,
    'IS_REGISTER' => $showRegisterForm,
    'ERROR' => $error,
    'RECAPTCHA_ENABLED' => $recaptcha_enabled,
    'RECAPTCHA_SITE_KEY' => $recaptcha_site_key,
    'AUTH_LINK_TEXT' => $showAuthForm ? $lang['REGISTER_LINK'] : $lang['LOGIN_LINK'],
    'AUTH_LINK_URL' => $showAuthForm ? '?action=register' : '?action=login'
];

renderTemplateImproved('auth', $template_data);