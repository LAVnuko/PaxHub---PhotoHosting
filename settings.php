<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang;

if (!isLoggedIn()) {
    redirect('/auth');
}

$user = getCurrentUser();
$userId = $user['id'];
$success = '';
$error = '';

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['settings_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = $lang['ERROR_CSRF'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_account') {
            $username = trim($_POST['username'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $auto_delete = $_POST['auto_delete'] ?? 'never';
            $keep_exif   = isset($_POST['keep_exif']) ? 1 : 0;
            $newsletter  = isset($_POST['newsletter']) ? 1 : 0;
            $show_unsafe = isset($_POST['show_unsafe']) ? 1 : 0;
            $language    = $_POST['language'] ?? 'ru';
            $timezone    = $_POST['timezone'] ?? 'Europe/Moscow';

            $errors = [];
            if (empty($username)) $errors[] = $lang['ERROR_USERNAME_EMPTY'];
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) $errors[] = $lang['ERROR_USERNAME_INVALID'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = $lang['ERROR_EMAIL_INVALID'];
            
            global $pdo;
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $userId]);
            if ($stmt->fetch()) $errors[] = $lang['ERROR_USERNAME_TAKEN'];
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) $errors[] = $lang['ERROR_EMAIL_TAKEN'];
            
            if (empty($errors)) {
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, auto_delete = ?, keep_exif = ?, newsletter = ?, show_unsafe = ?, language = ?, timezone = ? WHERE id = ?");
                $stmt->execute([$username, $email, $auto_delete, $keep_exif, $newsletter, $show_unsafe, $language, $timezone, $userId]);
                $success = $lang['ACCOUNT_UPDATED'];
                
                // ========== КЛЮЧЕВОЕ ИСПРАВЛЕНИЕ ==========
                // Обновляем язык в сессии и перезагружаем переводы
                $_SESSION['lang'] = $language;
                $lang = loadLanguage($language);
                // ==========================================
                
                $_SESSION['username'] = $username;
                $user = getCurrentUser();
            } else {
                $error = implode('<br>', $errors);
            }
        } elseif ($action === 'update_profile') {
            $full_name = trim($_POST['full_name'] ?? '');
            $website   = trim($_POST['website'] ?? '');
            $bio       = trim($_POST['bio'] ?? '');
            $visibility = $_POST['profile_visibility'] ?? 'public';
            
            global $pdo;
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, website = ?, bio = ?, profile_visibility = ? WHERE id = ?");
            $stmt->execute([$full_name, $website, $bio, $visibility, $userId]);
            
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $result = uploadAvatar($userId, $_FILES['avatar']);
                if (isset($result['error'])) {
                    $error = $result['error'];
                } else {
                    $success = $lang['AVATAR_UPDATED'];
                }
            }
            
            if (empty($error) && !isset($_FILES['avatar'])) {
                $success = $lang['PROFILE_UPDATED'];
            }
            $user = getCurrentUser();
        } elseif ($action === 'update_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            
            if (!password_verify($current, $user['password_hash'])) {
                $error = $lang['ERROR_WRONG_PASSWORD'];
            } elseif (strlen($new) < 6) {
                $error = $lang['ERROR_PASSWORD_TOO_SHORT'];
            } elseif ($new !== $confirm) {
                $error = $lang['ERROR_PASSWORDS_MISMATCH'];
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                global $pdo;
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $userId]);
                $success = $lang['PASSWORD_UPDATED'];
            }
        } elseif ($action === 'link_account') {
            $success = $lang['LINK_ACCOUNT_INFO'];
        }
    }
    
    // Генерируем новый CSRF-токен
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
    $csrfToken = $_SESSION['settings_csrf_token'];
    
    // После обработки POST делаем редирект на ту же страницу, чтобы избежать повторной отправки формы
    // и чтобы язык применился ко всем элементам страницы
    $redirectUrl = '/settings';
    if (!empty($success) || !empty($error)) {
        // Сохраняем сообщения в сессию
        if ($success) $_SESSION['settings_success'] = $success;
        if ($error) $_SESSION['settings_error'] = $error;
    }
    redirect($redirectUrl);
}

// Получаем сообщения из сессии после редиректа
if (isset($_SESSION['settings_success'])) {
    $success = $_SESSION['settings_success'];
    unset($_SESSION['settings_success']);
}
if (isset($_SESSION['settings_error'])) {
    $error = $_SESSION['settings_error'];
    unset($_SESSION['settings_error']);
}

$user = getCurrentUser();
$pageTitle = $lang['SETTINGS_PAGE_TITLE'] . ' ' . htmlspecialchars($user['username']) . ' | ' . SITE_NAME;

// Флаги для выбранных значений в select (как было ранее)
$template_data = [];
$autoDeleteMap = [
    'never'   => 'AUTO_DELETE_NEVER_SELECTED',
    '1day'    => 'AUTO_DELETE_1DAY_SELECTED',
    '1week'   => 'AUTO_DELETE_1WEEK_SELECTED',
    '1month'  => 'AUTO_DELETE_1MONTH_SELECTED'
];
foreach ($autoDeleteMap as $val => $flag) {
    $template_data[$flag] = ($user['auto_delete'] ?? 'never') == $val ? 'selected' : '';
}

$template_data['KEEP_EXIF_CHECKED']   = !empty($user['keep_exif']) ? 'checked' : '';
$template_data['NEWSLETTER_CHECKED']  = !empty($user['newsletter']) ? 'checked' : '';
$template_data['SHOW_UNSAFE_CHECKED'] = !empty($user['show_unsafe']) ? 'checked' : '';

$template_data['LANGUAGE_RU_SELECTED'] = ($user['language'] ?? 'ru') == 'ru' ? 'selected' : '';
$template_data['LANGUAGE_EN_SELECTED'] = ($user['language'] ?? '') == 'en' ? 'selected' : '';

$template_data['TIMEZONE_MSK_SELECTED'] = ($user['timezone'] ?? 'Europe/Moscow') == 'Europe/Moscow' ? 'selected' : '';
$template_data['TIMEZONE_LON_SELECTED'] = ($user['timezone'] ?? '') == 'Europe/London' ? 'selected' : '';
$template_data['TIMEZONE_NY_SELECTED']  = ($user['timezone'] ?? '') == 'America/New_York' ? 'selected' : '';

$template_data['PROFILE_VISIBILITY_PUBLIC_SELECTED'] = ($user['profile_visibility'] ?? 'public') == 'public' ? 'selected' : '';
$template_data['PROFILE_VISIBILITY_REG_SELECTED']    = ($user['profile_visibility'] ?? '') == 'registered' ? 'selected' : '';
$template_data['PROFILE_VISIBILITY_PRIVATE_SELECTED']= ($user['profile_visibility'] ?? '') == 'private' ? 'selected' : '';
$oauth_enabled = getSetting('oauth_enabled') == '1';
// Получаем уже привязанные аккаунты
$linked = [];
if ($oauth_enabled && isLoggedIn()) {
    $stmt = $pdo->prepare("SELECT provider FROM user_oauth WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    while ($row = $stmt->fetch()) {
        $linked[] = $row['provider'];
    }
}


// Основные данные
$template_data = array_merge($template_data, [
    'PAGE_TITLE' => $pageTitle,
    'CSRF_TOKEN' => $csrfToken,
    'USER_USERNAME' => htmlspecialchars($user['username']),
    'USER_EMAIL' => htmlspecialchars($user['email'] ?? ''),
    'FULL_NAME' => htmlspecialchars($user['full_name'] ?? ''),
    'WEBSITE' => htmlspecialchars($user['website'] ?? ''),
    'BIO' => htmlspecialchars($user['bio'] ?? ''),
    'HAS_AVATAR' => !empty($user['avatar']),
    'AVATAR_URL' => !empty($user['avatar']) ? getAvatarUrl($user) : '',
    'OAUTH_ENABLED' => $oauth_enabled,
    'LINKED_GOOGLE' => in_array('google', $linked),
    'LINKED_GITHUB' => in_array('github', $linked),
    'LINKED_VK' => in_array('vk', $linked),
]);

if ($success) {
    $template_data['HAS_MESSAGE'] = true;
    $template_data['MESSAGE_TYPE'] = 'success';
    $template_data['MESSAGE_TEXT'] = htmlspecialchars($success);
} elseif ($error) {
    $template_data['HAS_MESSAGE'] = true;
    $template_data['MESSAGE_TYPE'] = 'error';
    $template_data['MESSAGE_TEXT'] = $error;
} else {
    $template_data['HAS_MESSAGE'] = false;
}

renderTemplateImproved('settings', $template_data);