<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    http_response_code(403);
    die('Доступ запрещён');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Метод не разрешён');
}

// Проверка CSRF-токена
if (!isset($_POST['csrf_token']) || !isset($_SESSION['delete_csrf_token']) ||
    !hash_equals($_SESSION['delete_csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    die('Ошибка безопасности. Повторите попытку.');
}

$fileId = (int)($_POST['id'] ?? 0);
if ($fileId) {
    deleteFile($fileId, $_SESSION['user_id']);
}

unset($_SESSION['delete_csrf_token']);

redirect('/profile');