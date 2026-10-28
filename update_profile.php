<?php
/**
 * Обработчик обновления профиля
 * Принимает POST запросы на обновление информации и аватара
 */

require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang;

// Проверка CSRF
if (!isset($_POST['csrf_token']) || !isset($_SESSION['profile_csrf_token']) || $_POST['csrf_token'] !== $_SESSION['profile_csrf_token']) {
    $_SESSION['error_message'] = $lang['ERROR_CSRF'];
    redirect('/profile');
}

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/auth');
}

$user = getCurrentUser();
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Обновление информации профиля
    if (isset($_POST['update_info'])) {
        $result = updateProfile($user['id'], array(
            'full_name' => trim($_POST['full_name'] ?? ''),
            'bio' => trim($_POST['bio'] ?? ''),
            'website' => trim($_POST['website'] ?? '')
        ));
        
        if (isset($result['error'])) {
            $errorMessage = $result['error'];
        } else {
            $successMessage = $lang['PROFILE_INFO_UPDATED'];
        }
    }
    
    // Загрузка аватара
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $result = uploadAvatar($user['id'], $_FILES['avatar']);
            
            if (isset($result['error'])) {
                $errorMessage = $result['error'];
            } else {
                $successMessage = $lang['AVATAR_UPLOADED'];
            }
        } else {
            $errorMessage = $lang['AVATAR_UPLOAD_ERROR'] . ' (' . $_FILES['avatar']['error'] . ')';
        }
    }
}

// Сохраняем сообщения в сессию
if ($successMessage) {
    $_SESSION['success_message'] = $successMessage;
}
if ($errorMessage) {
    $_SESSION['error_message'] = $errorMessage;
}

// Перенаправляем обратно в профиль
redirect('/profile/' . $user['username']);