<?php
// --------------------------------------------------------------
// Режим отладки (отключаем на продакшене)
// --------------------------------------------------------------
if (defined('DEBUG_MODE') && DEBUG_MODE === false) {
    ini_set('display_errors', 0);
    error_reporting(0);
} else {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

require_once __DIR__ . '/../library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

define('IN_ADMIN', true);
global $lang;

// --------------------------------------------------------------
// Проверка авторизации
// --------------------------------------------------------------
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /admin/login.php');
    exit;
}
$currentUser = getCurrentUser();
if (!isAdmin($currentUser)) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

global $pdo;
$message = '';
$error   = '';

// --------------------------------------------------------------
// CSRF-токен
// --------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function checkCsrf() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF token validation failed');
    }
}

// --------------------------------------------------------------
// Функция редиректа (ОБЯЗАТЕЛЬНО перед обработчиками POST)
// --------------------------------------------------------------
function redirectAfterPost() {
    $page = $_GET['page'] ?? 'dashboard';
    $additional = '';
    if (isset($_GET['status'])) $additional = '&status=' . urlencode($_GET['status']);
    if (isset($_GET['search'])) $additional = '&search=' . urlencode($_GET['search']);
    if (isset($_GET['user_id'])) $additional .= '&user_id=' . urlencode($_GET['user_id']);
    if (isset($_GET['files_page'])) $additional .= '&files_page=' . urlencode($_GET['files_page']);
    header("Location: /admin/index.php?page={$page}{$additional}");
    exit;
}

// --------------------------------------------------------------
// Статистика (оптимизированная)
// --------------------------------------------------------------
function getDailyStats() {
    global $pdo;
    $stats = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $stats[$date] = ['users' => 0, 'files' => 0];
    }
    $sql = "
        SELECT DATE(created_at) as dt, COUNT(*) as cnt, 'user' as type FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY dt
        UNION ALL
        SELECT DATE(upload_date) as dt, COUNT(*) as cnt, 'file' as type FROM files WHERE upload_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY dt
    ";
    $stmt = $pdo->query($sql);
    while ($row = $stmt->fetch()) {
        if (isset($stats[$row['dt']])) {
            $stats[$row['dt']][$row['type'] . 's'] = $row['cnt'];
        }
    }
    $result = [];
    foreach ($stats as $date => $data) {
        $result[] = [
            'date'  => date('d.m', strtotime($date)),
            'users' => $data['users'],
            'files' => $data['files']
        ];
    }
    return $result;
}

function getSiteStats() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM files) AS total_files,
            (SELECT COUNT(*) FROM topup_requests) AS total_requests,
            (SELECT COUNT(*) FROM files WHERE mime_type LIKE 'image/%') AS images_count,
            (SELECT SUM(size) FROM files) AS total_size,
            (SELECT SUM(balance) FROM users) AS total_balance
    ");
    return $stmt->fetch();
}

function getTariffStats() {
    global $pdo;
    $stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN subscription = 'standard' THEN 1 END) AS standard_users,
            COUNT(CASE WHEN subscription = 'base' THEN 1 END) AS base_users,
            COUNT(CASE WHEN subscription = 'pro' THEN 1 END) AS pro_users
        FROM users
    ");
    return $stmt->fetch();
}

function getTopupStats($status = 'pending') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total FROM topup_requests WHERE status = ?");
    $stmt->execute([$status]);
    return $stmt->fetch();
}

function getNewUsersToday() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    return $stmt->fetchColumn();
}

// --------------------------------------------------------------
// ОБРАБОТЧИКИ POST (все с проверкой CSRF)
// --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Все действия требуют CSRF, кроме тех, что помечены skip_csrf (таких нет)
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF token validation failed');
    }
}

// 1. Одобрение заявки
if (isset($_POST['approve_request']) && isset($_POST['request_id'])) {
    $requestId = (int)$_POST['request_id'];
    $stmt = $pdo->prepare("SELECT * FROM topup_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if ($request) {
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$request['amount'], $request['user_id']]);
        $pdo->prepare("UPDATE topup_requests SET status = 'approved', processed_at = NOW(), processed_by = ? WHERE id = ?")->execute([$currentUser['id'], $requestId]);
        logAdminAction($currentUser['id'], $currentUser['username'], 'Одобрение заявки', "Заявка #{$requestId} на сумму {$request['amount']} руб.");
        $message = "Баланс пополнен на {$request['amount']} руб.";
    } else {
        $error = "Заявка не найдена или уже обработана";
    }
    redirectAfterPost();
}

// 2. Отклонение заявки
if (isset($_POST['reject_request']) && isset($_POST['request_id'])) {
    $requestId = (int)$_POST['request_id'];
    $admin_note = trim($_POST['admin_note'] ?? '');
    $stmt = $pdo->prepare("UPDATE topup_requests SET status = 'rejected', processed_at = NOW(), processed_by = ?, admin_note = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([$currentUser['id'], $admin_note, $requestId]);
    logAdminAction($currentUser['id'], $currentUser['username'], 'Отклонение заявки', "Заявка #{$requestId}, причина: {$admin_note}");
    $message = "Заявка #{$requestId} отклонена";
    redirectAfterPost();
}

// 3. Изменение баланса
if (isset($_POST['update_balance']) && isset($_POST['user_id']) && isset($_POST['new_balance'])) {
    $userId = (int)$_POST['user_id'];
    $newBalance = (float)$_POST['new_balance'];
    $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$newBalance, $userId]);
    logAdminAction($currentUser['id'], $currentUser['username'], 'Изменение баланса', "Пользователь ID {$userId}, новый баланс: {$newBalance}");
    $message = "Баланс обновлён";
    redirectAfterPost();
}

// 4. Обновление тарифа
if (isset($_POST['update_tariff']) && isset($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    $tariff = $_POST['tariff'] ?? 'standard';
    $months = (int)($_POST['months'] ?? 0);
    if ($tariff === 'standard') {
        $pdo->prepare("UPDATE users SET subscription = 'standard', gold_expires = NULL WHERE id = ?")->execute([$userId]);
        logAdminAction($currentUser['id'], $currentUser['username'], 'Изменение тарифа', "Пользователь ID {$userId}, тариф: Стандарт");
        $message = "Тариф пользователя изменён на «Стандарт»";
    } elseif (in_array($tariff, ['base', 'pro']) && $months >= 1 && $months <= 12) {
        if ($tariff === 'pro') {
            $stmt = $pdo->prepare("SELECT gold_expires FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $newExpires = time();
            if ($user['gold_expires'] && strtotime($user['gold_expires']) > time()) {
                $newExpires = strtotime($user['gold_expires']);
            }
            $newExpires = strtotime("+{$months} months", $newExpires);
            $expiresDate = date('Y-m-d H:i:s', $newExpires);
            $pdo->prepare("UPDATE users SET subscription = 'pro', gold_expires = ? WHERE id = ?")->execute([$expiresDate, $userId]);
        } else {
            $pdo->prepare("UPDATE users SET subscription = 'base', gold_expires = NULL WHERE id = ?")->execute([$userId]);
        }
        logAdminAction($currentUser['id'], $currentUser['username'], 'Изменение тарифа', "Пользователь ID {$userId}, тариф: {$tariff} на {$months} мес.");
        $message = "Тариф обновлён: " . ucfirst($tariff) . " на {$months} мес.";
    } else {
        $error = "Неверные параметры";
    }
    redirectAfterPost();
}

// 5. Блокировка/разблокировка одного пользователя
if (isset($_POST['toggle_block']) && isset($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    $pdo->prepare("UPDATE users SET is_blocked = NOT is_blocked WHERE id = ?")->execute([$userId]);
    logAdminAction($currentUser['id'], $currentUser['username'], 'Блокировка/разблокировка', "Пользователь ID {$userId}");
    $message = "Статус блокировки изменён";
    redirectAfterPost();
}

// 5a. Массовая блокировка/разблокировка
if (isset($_POST['mass_toggle_block']) && isset($_POST['selected_users'])) {
    $userIds = array_map('intval', $_POST['selected_users']);
    if (!empty($userIds)) {
        $in = implode(',', $userIds);
        $pdo->query("UPDATE users SET is_blocked = NOT is_blocked WHERE id IN ($in)");
        logAdminAction($currentUser['id'], $currentUser['username'], 'Массовая блокировка/разблокировка', "Пользователи ID: " . implode(',', $userIds));
        $message = "Статус блокировки изменён для " . count($userIds) . " пользователей";
    }
    redirectAfterPost();
}

// 5b. Массовое удаление пользователей
if (isset($_POST['mass_delete_users']) && isset($_POST['selected_users'])) {
    $userIds = array_map('intval', $_POST['selected_users']);
    $deleted = 0;
    foreach ($userIds as $uid) {
        if ($uid == $currentUser['id']) continue;
        $files = getUserFiles($uid);
        foreach ($files as $file) deleteFile($file['id']);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        $deleted++;
    }
    logAdminAction($currentUser['id'], $currentUser['username'], 'Массовое удаление пользователей', "Удалено {$deleted} пользователей");
    $message = "Удалено {$deleted} пользователей";
    redirectAfterPost();
}

// 6. Удаление одного пользователя
if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
    $userId = (int)$_POST['user_id'];
    if ($userId == $currentUser['id']) {
        $error = "Нельзя удалить самого себя";
    } else {
        $files = getUserFiles($userId);
        foreach ($files as $file) deleteFile($file['id']);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        logAdminAction($currentUser['id'], $currentUser['username'], 'Удаление пользователя', "Удалён пользователь ID {$userId}");
        $message = "Пользователь удалён";
    }
    redirectAfterPost();
}

// 7. Удаление одного файла
if (isset($_POST['delete_file']) && isset($_POST['file_id'])) {
    $fileId = (int)$_POST['file_id'];
    if (deleteFile($fileId)) {
        logAdminAction($currentUser['id'], $currentUser['username'], 'Удаление файла', "Файл ID {$fileId}");
        $message = "Файл удалён";
    } else {
        $error = "Ошибка удаления файла";
    }
    redirectAfterPost();
}

// 8. Массовое удаление файлов
if (isset($_POST['mass_delete_files']) && isset($_POST['file_ids'])) {
    $deleted = 0;
    foreach ($_POST['file_ids'] as $fileId) {
        if (deleteFile((int)$fileId)) $deleted++;
    }
    logAdminAction($currentUser['id'], $currentUser['username'], 'Массовое удаление файлов', "Удалено {$deleted} файлов");
    $message = "Удалено {$deleted} файлов";
    redirectAfterPost();
}

// 9. Сохранение настроек
if (isset($_POST['save_settings'])) {
    // Сначала обрабатываем старые цены для тарифов
    $tariffPriceKeys = ['standard_price', 'base_price', 'pro_price'];
    foreach ($tariffPriceKeys as $priceKey) {
        if (isset($_POST['setting_' . $priceKey])) {
            $newPrice = $_POST['setting_' . $priceKey];
            // Получаем текущую цену из БД
            $stmt = $pdo->prepare("SELECT value FROM site_settings WHERE `key` = ?");
            $stmt->execute([$priceKey]);
            $oldPrice = $stmt->fetchColumn();
            if ($oldPrice !== false && $oldPrice != $newPrice) {
                // Сохраняем старую цену в *_price_old
                $oldKey = str_replace('_price', '_price_old', $priceKey);
                $stmtOld = $pdo->prepare("INSERT INTO site_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
                $stmtOld->execute([$oldKey, $oldPrice, $oldPrice]);
            }
        }
    }
    // Затем сохраняем все остальные настройки (как было)
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'setting_') === 0) {
            $setting_key = substr($key, 8);
            $stmt = $pdo->prepare("INSERT INTO site_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
            $stmt->execute([$setting_key, $value, $value]);
        }
    }
    logAdminAction($currentUser['id'], $currentUser['username'], 'Изменение настроек', 'Изменены настройки сайта');
    $message = "Настройки сохранены";
    redirectAfterPost();
}

// 10. Очистка кэша
if (isset($_POST['clear_cache'])) {
    $results = clearSystemCache();
    $totalDeleted = array_sum(array_column($results, 'deleted'));
    logAdminAction($currentUser['id'], $currentUser['username'], 'Очистка кэша', "Очистка кэша, удалено {$totalDeleted} элементов");
    $message = "Кэш очищен. Удалено элементов: {$totalDeleted}";
    $_SESSION['cache_clear_results'] = $results;
    redirectAfterPost();
}

// 11. Очистка логов
if (isset($_POST['clear_logs'])) {
    $pdo->exec("TRUNCATE TABLE admin_logs");
    logAdminAction($currentUser['id'], $currentUser['username'], 'Очистка логов', 'Все логи администратора удалены');
    $message = "Логи администратора успешно очищены";
    redirectAfterPost();
}

// 12. Экспорт пользователей в CSV
if (isset($_POST['export_users_csv'])) {
    $stmt = $pdo->query("SELECT id, username, email, balance, subscription, is_blocked, created_at FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=users_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Username', 'Email', 'Balance', 'Subscription', 'Blocked', 'Registered']);
    foreach ($users as $u) {
        fputcsv($out, [$u['id'], $u['username'], $u['email'], $u['balance'], $u['subscription'], $u['is_blocked'], $u['created_at']]);
    }
    fclose($out);
    exit;
}

// 13. Резервное копирование БД (mysqldump)
if (isset($_POST['backup_db'])) {
    $backupFile = CACHE_DIR . 'db_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $command = sprintf(
        'mysqldump --host=%s --user=%s --password=%s %s > %s',
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_NAME),
        escapeshellarg($backupFile)
    );
    system($command, $resultCode);
    if ($resultCode === 0 && file_exists($backupFile)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($backupFile));
        readfile($backupFile);
        unlink($backupFile);
        exit;
    } else {
        $error = 'Не удалось создать резервную копию БД. Убедитесь, что mysqldump доступен.';
        redirectAfterPost();
    }
}

// 14. Очистка старых сессий
if (isset($_POST['clean_sessions'])) {
    $deleted = 0;
    $sessionSavePath = session_save_path();
    if (empty($sessionSavePath)) $sessionSavePath = sys_get_temp_dir();
    foreach (glob($sessionSavePath . '/sess_*') as $file) {
        if (filemtime($file) < time() - 3600 * 24) {
            if (unlink($file)) $deleted++;
        }
    }
    logAdminAction($currentUser['id'], $currentUser['username'], 'Очистка сессий', "Удалено {$deleted} устаревших сессий");
    $message = "Удалено {$deleted} старых сессий";
    redirectAfterPost();
}

// --------------------------------------------------------------
// ОПРЕДЕЛЕНИЕ ТЕКУЩЕЙ СТРАНИЦЫ
// --------------------------------------------------------------
$page = $_GET['page'] ?? 'dashboard';
$pageData = [];
$pageTitle = 'Админ-панель';

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'pending';
$user_filter = $_GET['user_id'] ?? '';

switch ($page) {
    case 'dashboard':
        $pageTitle = 'Дашборд';
        $pageData['heading'] = '📊 Дашборд';
        $siteStats = getSiteStats();
        $tariffStats = getTariffStats();
        $pendingStats = getTopupStats('pending');
        $newUsersToday = getNewUsersToday();
        $stats_daily = getDailyStats();
        
        // Топ пользователи
        $top_users = $pdo->query("
            SELECT u.username, COUNT(f.id) as files_count
            FROM users u LEFT JOIN files f ON u.id = f.user_id
            GROUP BY u.id ORDER BY files_count DESC LIMIT 10
        ")->fetchAll();
        $users_num = 1;
        foreach ($top_users as $u) {
            $improved_template->assign_block_vars('top_users', [
                'ROW_NUM' => $users_num++,
                'USERNAME' => htmlspecialchars($u['username']),
                'FILES_COUNT' => $u['files_count']
            ]);
        }
        
        $top_files = $pdo->query("
            SELECT original_name, views FROM files ORDER BY views DESC LIMIT 10
        ")->fetchAll();
        $file_num = 1;
        foreach ($top_files as $f) {
            $improved_template->assign_block_vars('top_files', [
                'ROW_NUM' => $file_num++,
                'NAME' => htmlspecialchars(mb_substr($f['original_name'], 0, 30)),
                'VIEWS' => number_format($f['views'])
            ]);
        }
        
        $recent_requests = $pdo->query("
            SELECT r.*, u.username FROM topup_requests r
            LEFT JOIN users u ON r.user_id = u.id
            ORDER BY r.created_at DESC LIMIT 5
        ")->fetchAll();
        foreach ($recent_requests as $req) {
            $status_text = $req['status'] == 'pending' ? '⏳ Ожидает' : ($req['status'] == 'approved' ? '✅ Одобрено' : '❌ Отклонено');
            $status_class = $req['status'] == 'pending' ? 'warning' : ($req['status'] == 'approved' ? 'success' : 'danger');
            $improved_template->assign_block_vars('recent_requests', [
                'ID' => $req['id'],
                'USERNAME' => htmlspecialchars($req['username']),
                'AMOUNT' => number_format($req['amount'], 2),
                'DATE' => date('d.m.Y H:i', strtotime($req['created_at'])),
                'STATUS_TEXT' => $status_text,
                'STATUS_CLASS' => $status_class
            ]);
        }
        $newProToday = $pdo->query("SELECT COUNT(*) FROM users WHERE subscription = 'pro' AND DATE(created_at) = CURDATE()")->fetchColumn();
        $pageData = [
            'TOTAL_USERS' => $siteStats['total_users'],
            'TOTAL_BALANCE' => number_format($siteStats['total_balance'], 2),
            'TOTAL_FILES' => $siteStats['total_files'],
            'TOTAL_SIZE_FORMATTED' => formatFileSize($siteStats['total_size']),
            'IMAGES_COUNT' => number_format($siteStats['images_count']),
            'STANDARD_USERS' => $tariffStats['standard_users'],
            'BASE_USERS' => $tariffStats['base_users'],
            'PRO_USERS' => $tariffStats['pro_users'],
            'PENDING_TOPUPS' => $pendingStats['count'],
            'TOTAL_TOPUPS' => number_format($pendingStats['total'], 2),
            'NEW_USERS_TODAY' => $newUsersToday,
            'CHART_LABELS' => json_encode(array_column($stats_daily, 'date')),
            'CHART_USERS_DATA' => json_encode(array_map('intval', array_column($stats_daily, 'users'))),
            'CHART_FILES_DATA' => json_encode(array_map('intval', array_column($stats_daily, 'files'))),
            'LANG_NEW_USERS' => $lang['NEW_USERS'] ?? 'Новые пользователи',
            'LANG_NEW_FILES' => $lang['NEW_FILES'] ?? 'Новые файлы',
            'NEW_PRO_TODAY' => $newProToday,
        ];
        break;
        
    case 'topup_requests':
        $pageTitle = 'Заявки на пополнение';
        $stmt = $pdo->prepare("
            SELECT r.*, u.email, u.balance as user_balance
            FROM topup_requests r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.status = ?
            ORDER BY r.created_at DESC
            
        ");
        $stmt->execute([$status_filter]);
        $requests = $stmt->fetchAll();
        $requestsStats = getSiteStats();
        foreach ($requests as $req) {
            $improved_template->assign_block_vars('requests', [
                'ID' => $req['id'],
                'USERNAME' => htmlspecialchars($req['username']),
                'USER_ID' => $req['user_id'],
                'USER_BALANCE' => number_format($req['user_balance'] ?? 0, 2),
                'AMOUNT' => number_format($req['amount'], 2),
                'PAYMENT_METHOD' => htmlspecialchars($req['payment_method']),
                'PAYMENT_DETAILS' => nl2br(htmlspecialchars($req['payment_details'])),
                'CREATED_AT' => date('d.m.Y H:i', strtotime($req['created_at'])),
                'STATUS' => $req['status'],
                'IS_PENDING'   => ($req['status'] == 'pending'),
                'IS_APPROVED'  => ($req['status'] == 'approved'),
                'IS_REJECTED'  => ($req['status'] == 'rejected'),
            ]);
        }
        $pageData = [
            'heading' => '💰 Заявки на пополнение',
            'status_filter' => $status_filter,
            'ACTIVE_PENDING' => ($status_filter == 'pending') ? 'active' : '',
            'ACTIVE_APPROVED' => ($status_filter == 'approved') ? 'active' : '',
            'ACTIVE_REJECTED' => ($status_filter == 'rejected') ? 'active' : '',
            'TOTAL_REQUESTS' => $requestsStats['total_requests'],
        ];
        break;
        
    case 'users':
        $pageTitle = 'Управление пользователями';
        $siteStats = getSiteStats();
        $pro_users = $pdo->query("SELECT COUNT(*) FROM users WHERE subscription = 'pro'")->fetchColumn();
        
        $sql = "SELECT u.*, 
                (SELECT COUNT(*) FROM files WHERE user_id = u.id) as files_count,
                (SELECT SUM(size) FROM files WHERE user_id = u.id) as total_size
                FROM users u";
        $params = [];
        if ($search) {
            $sql .= " WHERE u.username LIKE ? OR u.email LIKE ?";
            $params = ["%{$search}%", "%{$search}%"];
        }
        $sql .= " ORDER BY u.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        foreach ($users as $user) {
            // PHP 7.4 — без match
            $tariffDisplay = 'Стандарт';
            if (($user['subscription'] ?? 'standard') === 'base') {
                $tariffDisplay = 'Базовый';
            } elseif (($user['subscription'] ?? 'standard') === 'pro') {
                $tariffDisplay = 'Профи';
            }
            $improved_template->assign_block_vars('users', [
                'ID'           => $user['id'],
                'USERNAME'     => htmlspecialchars($user['username']),
                'USERNAME_JS'  => addslashes(htmlspecialchars($user['username'])),
                'EMAIL'        => htmlspecialchars($user['email'] ?? '-'),
                'BALANCE'      => number_format($user['balance'], 2),
                'BLOCKED_BADGE'=> $user['is_blocked'],
                'FILES_COUNT'  => $user['files_count'],
                'TOTAL_SIZE'   => formatFileSize($user['total_size'] ?? 0),
                'CREATED_AT'   => date('d.m.Y', strtotime($user['created_at'])),
                'IS_CURRENT_USER' => ($user['id'] == $currentUser['id']),
                'TARIFF_DISPLAY' => $tariffDisplay,
            ]);
        }
        $pageData = [
            'heading' => '👥 Пользователи',
            'search' => $search,
            'TOTAL_USERS' => $siteStats['total_users'],
            'TOTAL_BALANCE' => number_format($siteStats['total_balance'], 2),
            'TOTAL_FILES' => $siteStats['total_files'],
            'PRO_USERS' => $pro_users,
            'CSRF_TOKEN' => $_SESSION['csrf_token']
        ];
        break;
        
    case 'files':
        $pageTitle = 'Управление файлами';
        $files_page = isset($_GET['files_page']) ? max(1, (int)$_GET['files_page']) : 1;
        $per_page = 20;
        $offset = ($files_page - 1) * $per_page;
        $siteStats = getSiteStats();
        
        $users_list = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
        foreach ($users_list as $u) {
            $improved_template->assign_block_vars('user_filter_options', [
                'ID' => $u['id'],
                'USERNAME' => htmlspecialchars($u['username']),
                'SELECTED' => ($user_filter == $u['id']) ? 'selected' : ''
            ]);
        }
        
        $sql_where = " WHERE 1=1";
        $params = [];
        if ($search) {
            $sql_where .= " AND (f.original_name LIKE ? OR u.username LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($user_filter) {
            $sql_where .= " AND f.user_id = ?";
            $params[] = $user_filter;
        }
        
        $count_sql = "SELECT COUNT(*) FROM files f LEFT JOIN users u ON f.user_id = u.id" . $sql_where;
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_filtered = $stmt->fetchColumn();
        $total_pages = ceil($total_filtered / $per_page);
        
        $sql = "SELECT f.*, u.username, u.subscription FROM files f LEFT JOIN users u ON f.user_id = u.id" . $sql_where . " ORDER BY f.id DESC LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $per_page, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $files = $stmt->fetchAll();
        
        foreach ($files as $file) {
            $isImage = strpos($file['mime_type'], 'image/') === 0;
            $sizeKb = round($file['size'] / 1024);
            $sizeText = $sizeKb . ' КБ';
            if ($sizeKb > 1024) $sizeText = round($sizeKb / 1024, 2) . ' МБ';
            $fileUrl = '/uploads/' . $file['stored_name'];
            $safeFileUrl = htmlspecialchars($fileUrl);
            $isExpired = $file['expiration_date'] && strtotime($file['expiration_date']) < time();
            $originalName = htmlspecialchars(mb_substr($file['original_name'], 0, 40));
            $is_pro = ($file['subscription'] === 'pro');
            
            $improved_template->assign_block_vars('files', [
                'ID' => $file['id'],
                'FILE_URL' => $safeFileUrl,
                'ORIGINAL_NAME' => $originalName,
                'IS_IMAGE' => $isImage,
                'IS_PRO' => $is_pro,
                'IS_EXPIRED' => $isExpired,
                'SIZE' => $sizeText,
                'TYPE_BADGE' => $isImage ? '🖼️ Изобр.' : '📁 Другой',
                'VIEWS' => number_format($file['views']),
                'USER_ID' => $file['user_id'],
                'IS_GUEST' => ($file['user_id'] == 0 || $file['user_id'] === null),
                'USERNAME' => htmlspecialchars($file['username'] ?? ''),
                'USERNAME_URLENCODED' => urlencode($file['username'] ?? ''),
                'UPLOAD_DATE' => date('d.m.Y', strtotime($file['upload_date'])),
            ]);
        }
        
        // ВСТАВЬТЕ СВОЮ ПАГИНАЦИЮ (или используйте код из исходного файла)
        $pagination_html = ''; // TODO: сгенерировать пагинацию по аналогии с вашим старым кодом
        $pageData = [
            'heading' => '📁 Файлы',
            'search' => $search,
            'user_filter' => $user_filter,
            'TOTAL_FILES' => number_format($siteStats['total_files']),
            'TOTAL_SIZE' => formatFileSize($siteStats['total_size']),
            'IMAGES_COUNT' => number_format($siteStats['images_count']),
            'OTHER_COUNT' => number_format($siteStats['total_files'] - $siteStats['images_count']),
            'PAGINATION_HTML' => $pagination_html,
            'SHOW_PAGINATION' => ($total_pages > 1),
            'CSRF_TOKEN' => $_SESSION['csrf_token']
        ];
        break;
        
    case 'settings':
        $pageTitle = 'Настройки сайта';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (`key` varchar(100) NOT NULL, `value` text, PRIMARY KEY (`key`))");
        $settings = [];
        $stmt = $pdo->query("SELECT * FROM site_settings");
        while ($row = $stmt->fetch()) $settings[$row['key']] = $row['value'];
        
        $pageData = [
            'heading' => '⚙️ Настройки',
            'CSRF_TOKEN' => $_SESSION['csrf_token'],
            'STORAGE_DAYS_GUEST' => $settings['storage_days_guest'] ?? 7,
            'STORAGE_DAYS_USER' => $settings['storage_days_user'] ?? 30,
            'MAINTENANCE_MODE' => $settings['maintenance_mode'] ?? 0,
            'MAINTENANCE_TEXT' => htmlspecialchars($settings['maintenance_text'] ?? 'Сайт временно недоступен, ведутся технические работы.'),
            'RECAPTCHA_ENABLED' => $settings['recaptcha_enabled'] ?? '0',
            'RECAPTCHA_SITE_KEY' => htmlspecialchars($settings['recaptcha_site_key'] ?? ''),
            'RECAPTCHA_SECRET_KEY' => htmlspecialchars($settings['recaptcha_secret_key'] ?? ''),
            'OAUTH_ENABLED' => $settings['oauth_enabled'] ?? '0',
            'STANDARD_PRICE' => $settings['standard_price'] ?? 0,
            'BASE_PRICE' => $settings['base_price'] ?? 0,
            'PRO_PRICE' => $settings['pro_price'] ?? 0,
            'STANDART_PRICE_OLD' => $settings['standard_price_old'] ?? '',
            'BASE_PRICE_OLD' => $settings['base_price_old'] ?? '',
            'PRO_PRICE_OLD' => $settings['pro_price_old'] ?? '',
        ];

        
        
        // Настройки тарифов
        $tariffDefaults = [
            'standard' => ['max_size' => 10, 'price' => 0, 'storage_days' => 30, 'max_video' => 0,   'has_ads' => 1, 'priority' => 0, 'max_photos' => 40],
            'base'     => ['max_size' => 10, 'price' => 50, 'storage_days' => 60, 'max_video' => 512, 'has_ads' => 1, 'priority' => 0, 'max_photos' => 80],
            'pro'      => ['max_size' => 20, 'price' => 100, 'storage_days' => 90, 'max_video' => 1024,'has_ads' => 0, 'priority' => 1, 'max_photos' => PHP_INT_MAX],
        ];
        foreach ($tariffDefaults as $tariff => $defaults) {
            foreach ($defaults as $field => $default) {
                $key = $tariff . '_' . $field;
                $value = $settings[$key] ?? $default;
                $pageData[strtoupper($key)] = $value;
                if ($field == 'has_ads') {
                    $pageData[strtoupper($tariff . '_ADS_ENABLED')] = ($value == 1) ? 'selected' : '';
                    $pageData[strtoupper($tariff . '_ADS_DISABLED')] = ($value == 0) ? 'selected' : '';
                }
                if ($field == 'priority') {
                    $pageData[strtoupper($tariff . '_PRIORITY_ENABLED')] = ($value == 1) ? 'selected' : '';
                    $pageData[strtoupper($tariff . '_PRIORITY_DISABLED')] = ($value == 0) ? 'selected' : '';
                }
            }
        }
        
        $maintenanceMode = (int) getSetting('maintenance_mode', 0);
        $maintenanceOptions = sprintf(
            '<option value="0"%s>Выключен</option><option value="1"%s>Включен</option>',
            $maintenanceMode === 0 ? ' selected' : '',
            $maintenanceMode === 1 ? ' selected' : ''
        );
        $pageData['MAINTENANCE_OPTIONS'] = $maintenanceOptions;
        break;
        
    case 'logs':
        $pageTitle = 'Журнал действий';
        $pageData['heading'] = '📜 Логи администратора';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `admin_id` int(11) NOT NULL,
                `admin_name` varchar(255) NOT NULL,
                `action` varchar(255) NOT NULL,
                `details` text,
                `ip_address` varchar(45) DEFAULT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $log_page = max(1, (int)($_GET['log_page'] ?? 1));
        $per_page = 50;
        $offset = ($log_page - 1) * $per_page;
        $total_logs = $pdo->query("SELECT COUNT(*) FROM admin_logs")->fetchColumn();
        $total_pages = ceil($total_logs / $per_page);
        $logs = $pdo->query("SELECT * FROM admin_logs ORDER BY created_at DESC LIMIT $per_page OFFSET $offset")->fetchAll();
        foreach ($logs as $log) {
            $improved_template->assign_block_vars('logs', [
                'ID' => $log['id'],
                'ADMIN_NAME' => htmlspecialchars($log['admin_name']),
                'ACTION' => htmlspecialchars($log['action']),
                'DETAILS' => htmlspecialchars($log['details']),
                'IP_ADDRESS' => $log['ip_address'],
                'CREATED_AT' => date('d.m.Y H:i:s', strtotime($log['created_at']))
            ]);
        }
        // Пагинацию для логов оставляем как в вашем коде
        $pageData['PAGINATION_HTML'] = ''; // TODO: вставьте свою пагинацию
        $pageData['CSRF_TOKEN'] = $_SESSION['csrf_token'];
        break;
        
    case 'badwords':
        $pageData['CSRF_TOKEN'] = $_SESSION['csrf_token'];
        $pageTitle = 'Управление запрещёнными словами';
        $pageData['heading'] = '🚫 Запрещённые слова';
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS `bad_words` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `word` varchar(100) NOT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `word` (`word`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_word'])) {
            $newWord = trim($_POST['word']);
            if (!empty($newWord)) {
                if (addBadWord($newWord)) {
                    $message = "Слово «" . htmlspecialchars($newWord) . "» добавлено.";
                    logAdminAction($currentUser['id'], $currentUser['username'], 'Добавление слова', $newWord);
                } else {
                    $error = "Слово уже существует или недопустимо.";
                }
            } else {
                $error = "Введите слово.";
            }
            redirectAfterPost();
        }
        
        if (isset($_GET['delete'])) {
            $wordToDelete = urldecode($_GET['delete']);
            if (removeBadWord($wordToDelete)) {
                $message = "Слово удалено.";
                logAdminAction($currentUser['id'], $currentUser['username'], 'Удаление слова', $wordToDelete);
            } else {
                $error = "Ошибка удаления.";
            }
            header("Location: /admin/index.php?page=badwords");
            exit;
        }
        
        $badWordsList = getAllBadWords();
        $pageData['HAS_BAD_WORDS'] = !empty($badWordsList);
        $pageData['BAD_WORDS_COUNT'] = count($badWordsList);   // <-- ДОБАВИТЬ ЭТУ СТРОКУ

        foreach ($badWordsList as $word) {
            $improved_template->assign_block_vars('bad_words', [
                'WORD' => htmlspecialchars($word),
                'WORD_ENCODED' => urlencode($word)
            ]);
        }
        break;
    case 'categories':
        $pageTitle = 'Управление категориями';
        
        $pageData = [
            'heading' => '🏷️ Категории изображений'
            ];
        
        // Обработка POST-запросов
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                $error = 'CSRF токен недействителен';
            } else {
                $action = $_POST['action'] ?? '';
                if ($action === 'add' && !empty($_POST['name'])) {
                    $name = trim($_POST['name']);
                    $catId = createCategory($name);
                    if ($catId) {
                        $message = "Категория «{$name}» добавлена.";
                        logAdminAction($currentUser['id'], $currentUser['username'], 'Добавление категории', "ID {$catId}: {$name}");
                    } else {
                        $error = "Не удалось добавить категорию (возможно, уже существует).";
                    }
                } 
                elseif ($action === 'edit' && !empty($_POST['id']) && !empty($_POST['name'])) {
                    $id = (int)$_POST['id'];
                    $name = trim($_POST['name']);
                    if (updateCategory($id, $name)) {
                        $message = "Категория ID {$id} обновлена.";
                        logAdminAction($currentUser['id'], $currentUser['username'], 'Редактирование категории', "ID {$id}: {$name}");
                    } else {
                        $error = "Ошибка обновления категории.";
                    }
                }
                elseif ($action === 'delete' && !empty($_POST['id'])) {
                    $id = (int)$_POST['id'];
                    // Проверяем, есть ли файлы, использующие категорию
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE category_id = ?");
                    $stmt->execute([$id]);
                    $count = $stmt->fetchColumn();
                    if ($count > 0) {
                        $error = "Невозможно удалить категорию: {$count} файлов используют её. Сначала переназначьте категорию у этих файлов.";
                    } else {
                        if (deleteCategory($id)) {
                            $message = "Категория ID {$id} удалена.";
                            logAdminAction($currentUser['id'], $currentUser['username'], 'Удаление категории', "ID {$id}");
                        } else {
                            $error = "Ошибка удаления категории.";
                        }
                    }
                }
            }
            // Редирект, чтобы избежать повторной отправки формы
            header('Location: /admin/index.php?page=categories');
            exit;
        }
        
        // Получение списка категорий
        $categories = getAllCategories();
        foreach ($categories as $cat) {
            $improved_template->assign_block_vars('categories', [
                'ID'   => $cat['id'],
                'NAME' => htmlspecialchars($cat['name']),
                'SLUG' => htmlspecialchars($cat['slug'])
            ]);
        }
        $pageData['CATEGORIES'] = $categories;
        $pageData['CSRF_TOKEN'] = $_SESSION['csrf_token'];
        break;
    case 'clear_cache':
        $pageTitle = 'Очистка кэша';
        $pageData['heading'] = '🧹 Очистка кэша';
        if (isset($_SESSION['cache_clear_results'])) {
            $results = $_SESSION['cache_clear_results'];
            unset($_SESSION['cache_clear_results']);
            foreach ($results as $key => $info) {
                $improved_template->assign_block_vars('cache_results', [
                    'TYPE' => ucfirst($key),
                    'PATH' => htmlspecialchars($info['path']),
                    'DELETED' => (int)$info['deleted']
                ]);
            }
            $pageData['SHOW_RESULTS'] = true;
        } else {
            $pageData['SHOW_RESULTS'] = false;
        }
        $pageData['CSRF_TOKEN'] = $_SESSION['csrf_token'];
        break;
        
    default:
        $page = 'dashboard';
        $pageTitle = 'Дашборд';
        break;
}

logAdminAction($currentUser['id'], $currentUser['username'], 'Просмотр страницы', "Страница: {$page}");

if ($message) $pageData['MESSAGE'] = $message;
if ($error) $pageData['ERROR'] = $error;

// Передаём CSRF-токен во все шаблоны
$pageData['CSRF_TOKEN'] = $_SESSION['csrf_token'];

renderAdminPage($page, $pageData, $pageTitle);