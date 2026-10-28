<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

if (!isLoggedIn()) {
    redirect('/auth');
}

$user = getCurrentUser();
$currentSubscription = getUserSubscription($user);
$message = '';
$messageType = '';
global $lang, $pdo;

$prices = [
    'base' => 29,
    'pro'  => 59,
];

// Обработка POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['tariff_csrf']) || $_POST['csrf_token'] !== $_SESSION['tariff_csrf']) {
        $message = $lang['ERROR_CSRF'] ?? 'Ошибка безопасности. Попробуйте снова.';
        $messageType = 'error';
    } else {
        $tariff = $_POST['tariff'] ?? '';
        $months = (int)($_POST['months'] ?? 0);

        if ($tariff === 'standard') {
            $stmt = $pdo->prepare("UPDATE users SET subscription = 'standard', gold_expires = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            $message = 'Вы переключены на тариф "Стандарт".';
            $messageType = 'success';
            $currentSubscription = 'standard';
            $user = getCurrentUser();
        } elseif (in_array($tariff, ['base', 'pro']) && $months >= 1 && $months <= 12) {
            $cost = $prices[$tariff] * $months;
            if ($user['balance'] >= $cost) {
                $newBalance = $user['balance'] - $cost;
                $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$newBalance, $user['id']]);
        
                if ($tariff === 'pro') {
                    // Рассчитываем новую дату окончания Pro-подписки
                    $newExpires = time();
                    if ($currentSubscription === 'pro' && $user['gold_expires'] && strtotime($user['gold_expires']) > time()) {
                        $newExpires = strtotime($user['gold_expires']);
                    }
                    $newExpires += $months * 30 * 86400;
                    $expiresDate = date('Y-m-d H:i:s', $newExpires);
                    $stmt = $pdo->prepare("UPDATE users SET subscription = ?, gold_expires = ? WHERE id = ?");
                    $stmt->execute(['pro', $expiresDate, $user['id']]);
                } else {
                    // Для Базового – сбрасываем дату окончания
                    $stmt = $pdo->prepare("UPDATE users SET subscription = ?, gold_expires = NULL WHERE id = ?");
                    $stmt->execute(['base', $user['id']]);
                }
        
                $message = "Тариф «" . ucfirst($tariff) . "» активирован на {$months} месяц(ев).";
                $messageType = 'success';
                $currentSubscription = $tariff;
                $user = getCurrentUser();
            } else {
                $message = 'Недостаточно средств.';
                $messageType = 'error';
            }
        } else {
            $message = 'Неверные параметры.';
            $messageType = 'error';
        }
        unset($_SESSION['tariff_csrf']);
        $_SESSION['tariff_csrf'] = bin2hex(random_bytes(32));
    }
}

// CSRF-токен
if (empty($_SESSION['tariff_csrf'])) {
    $_SESSION['tariff_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['tariff_csrf'];

// Названия тарифов
$subscriptionNames = [
    'standard' => 'Стандарт',
    'base'     => 'Базовый',
    'pro'      => 'Профи',
];
$currentSubscriptionName = $subscriptionNames[$currentSubscription] ?? 'Стандарт';

// Флаги для шаблона
$is_standard = ($currentSubscription === 'standard');
$is_base = ($currentSubscription === 'base');
$is_pro = ($currentSubscription === 'pro');
$alert_class = ($messageType === 'success') ? 'alert-success' : 'alert-danger';

// Данные для шаблона
$template_data = [
    'CURRENT_SUBSCRIPTION'      => $currentSubscription,
    'CURRENT_SUBSCRIPTION_NAME' => $currentSubscriptionName,
    'IS_STANDARD'               => $is_standard,
    'IS_BASE'                   => $is_base,
    'IS_PRO'                    => $is_pro,
    'ALERT_CLASS'               => $alert_class,
    'MESSAGE'                   => $message,
    'MESSAGE_TYPE'              => $messageType,
    'HAS_MESSAGE'               => !empty($message),
    'CSRF_TOKEN'                => $csrf_token,
    'PAGE_TITLE'                => $lang['RATE_PAGE_TITLE'] . ' | ' . SITE_NAME,
    'USER_BALANCE'              => number_format($user['balance'], 2),
    'PRICES'                    => $prices,
];

renderTemplateImproved('rate', $template_data);