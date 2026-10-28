<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (!isLoggedIn() && !isset($_GET['username'])) {
    redirect('/auth');
}

$profileUser = null;
if (isset($_GET['username'])) {
    $username = rawurldecode($_GET['username']);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $profileUser = $stmt->fetch();
    if (!$profileUser) {
        http_response_code(404);
        renderTemplateRawImproved('access_denied', [
            'ERROR_MESSAGE' => $lang['USER_NOT_FOUND'] ?? 'Пользователь не найден'
        ]);
        exit;
    }
} else {
    $profileUser = getCurrentUser();
}

$currentUser = getCurrentUser();
$isOwnProfile = (isLoggedIn() && $_SESSION['user_id'] == $profileUser['id']);
$isGuest = !isLoggedIn();

// Видимость профиля
$visibility = $profileUser['profile_visibility'] ?? 'public';
$canView = false;
if ($isOwnProfile) {
    $canView = true;
} elseif ($visibility == 'public') {
    $canView = true;
} elseif ($visibility == 'registered' && isLoggedIn()) {
    $canView = true;
}
if (!$canView) {
    http_response_code(403);
    $errorMsg = ($visibility == 'registered' && !isLoggedIn()) 
        ? ($lang['PROFILE_REGISTERED_ONLY'] ?? 'Этот профиль доступен только зарегистрированным пользователям.')
        : ($lang['PROFILE_PRIVATE'] ?? 'Владелец профиля ограничил доступ.');
    renderTemplateRawImproved('access_denied', ['ERROR_MESSAGE' => $errorMsg]);
    exit;
}

// Пагинация
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE user_id = ?");
$stmt->execute([$profileUser['id']]);
$total_files = $stmt->fetchColumn();
$total_pages = ceil($total_files / $per_page);

$files = getUserFiles($profileUser['id'], $per_page, $offset);

// Общий объём
$stmt = $pdo->prepare("SELECT SUM(size) as total FROM files WHERE user_id = ?");
$stmt->execute([$profileUser['id']]);
$totalSize = $stmt->fetchColumn() ?? 0;
$totalSizeFormatted = formatFileSize($totalSize);

// Альбомы
$albumsCount = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM albums WHERE user_id = ?");
    $stmt->execute([$profileUser['id']]);
    $albumsCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    $albumsCount = 0;
}

// ========== Получаем подписку и лимиты ==========
$subscription = getUserSubscription($profileUser);
$limits = getUserLimits($profileUser);

$tariffNames = [
    'standard' => $lang['TARIFF_STANDARD_NAME'],
    'base'     => $lang['TARIFF_BASE_NAME'],
    'pro'      => $lang['TARIFF_PRO_NAME']
];
$tariffDisplay = $tariffNames[$subscription] ?? 'Стандарт';

$maxPhotosPerAlbum = ($limits['max_photos_per_album'] == PHP_INT_MAX) ? '∞' : $limits['max_photos_per_album'];
$storageDays = $limits['storage_days'];
$canVideo = $limits['can_upload_video'] ? 'Да' : 'Нет';
$hasAds = $limits['has_ads'] ? 'Есть' : 'Нет';

// Данные для шаблона
$template_data = [
    'PROFILEUSER_USERNAME'    => htmlspecialchars($profileUser['username']),
    'PROFILEUSER_FULL_NAME'   => htmlspecialchars($profileUser['full_name'] ?? ''),
    'PROFILEUSER_BIO'         => htmlspecialchars($profileUser['bio'] ?? ''),
    'PROFILEUSER_WEBSITE'     => htmlspecialchars($profileUser['website'] ?? ''),
    'PROFILEUSER_AVATAR'      => getAvatarUrl($profileUser),
    'PROFILEUSER_REGISTERED'  => date('d.m.Y', strtotime($profileUser['registered_at'] ?? $profileUser['created_at'])),
    'IS_OWN_PROFILE'          => $isOwnProfile,
    'IS_GUEST'                => $isGuest,
    'TOTAL_SIZE'              => $totalSizeFormatted,
    'ALBUMS_COUNT'            => $albumsCount,
    'FILES_COUNT'             => $total_files,
    'SHOW_NO_FILES'           => ($total_files == 0),
    'CURRENT_PAGE'            => $page,
    'TOTAL_PAGES'             => $total_pages,
    'USERNAME_FOR_PAGINATION' => urlencode($profileUser['username']),
    'SHOW_ALBUMS_BUTTON'      => true,

    // Тариф и лимиты
    'SUBSCRIPTION_NAME'       => $tariffDisplay,
    'MAX_PHOTOS_PER_ALBUM'    => $maxPhotosPerAlbum,
    'STORAGE_DAYS'            => $storageDays,
    'CAN_UPLOAD_VIDEO'        => $canVideo,
    'HAS_ADS'                 => $hasAds,
];

// Приватные поля – только для владельца
if ($isOwnProfile) {
    $template_data['PROFILEUSER_EMAIL']   = htmlspecialchars($profileUser['email'] ?? ($lang['NOT_SPECIFIED'] ?? 'Не указан'));
    $template_data['PROFILEUSER_BALANCE'] = number_format($profileUser['balance'], 2);
} else {
    $template_data['PROFILEUSER_EMAIL']   = $lang['HIDDEN'] ?? 'Скрыто';
    $template_data['PROFILEUSER_BALANCE'] = $lang['HIDDEN'] ?? 'Скрыто';
}

// CSRF для удаления файлов
$delete_csrf_token = '';
if ($isOwnProfile) {
    if (empty($_SESSION['delete_csrf_token'])) {
        $_SESSION['delete_csrf_token'] = bin2hex(random_bytes(32));
    }
    $delete_csrf_token = $_SESSION['delete_csrf_token'];
}
$template_data['DELETE_CSRF_TOKEN'] = $delete_csrf_token;

// Блок файлов
global $improved_template;
foreach ($files as $file) {
    $improved_template->assign_block_vars('files', [
        'ID'            => $file['id'],
        'URL'           => "/uploads/" . $file['stored_name'],
        'ORIGINAL_NAME' => htmlspecialchars($file['original_name']),
        'SIZE'          => round($file['size'] / 1024),
        'UPLOAD_DATE'   => date('d.m.Y H:i', strtotime($file['upload_date'])),
        'END_DATE'      => ($file['expiration_date'] && $file['expiration_date'] != '0000-00-00 00:00:00') ? date('d.m.Y H:i', strtotime($file['expiration_date'])) : ($lang['UNLIMITED'] ?? '∞'),
        'VIEWS'         => $file['views'],
        'DELETE_CSRF_TOKEN' => $delete_csrf_token
    ]);
}

// Пагинация
$pagination_html = '';
if ($total_pages > 1) {
    $pagination_html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center flex-wrap">';
    if ($page > 1) {
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?username=' . urlencode($profileUser['username']) . '&page=' . ($page - 1) . '">&laquo;</a></li>';
    } else {
        $pagination_html .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
    }
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    if ($start > 1) {
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?username=' . urlencode($profileUser['username']) . '&page=1">1</a></li>';
        if ($start > 2) $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $page) {
            $pagination_html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="?username=' . urlencode($profileUser['username']) . '&page=' . $i . '">' . $i . '</a></li>';
        }
    }
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?username=' . urlencode($profileUser['username']) . '&page=' . $total_pages . '">' . $total_pages . '</a></li>';
    }
    if ($page < $total_pages) {
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?username=' . urlencode($profileUser['username']) . '&page=' . ($page + 1) . '">&raquo;</a></li>';
    } else {
        $pagination_html .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
    }
    $pagination_html .= '</ul></nav>';
}
$template_data['PAGINATION_HTML'] = $pagination_html;

// CSRF для редактирования профиля
if ($isOwnProfile) {
    $_SESSION['profile_csrf_token'] = bin2hex(random_bytes(32));
    $template_data['CSRF_TOKEN'] = $_SESSION['profile_csrf_token'];
}

$pageTitle = ($lang['PROFILE'] ?? 'Профиль') . ' ' . htmlspecialchars($profileUser['username']) . ' | ' . SITE_NAME;
$template_data['PAGE_TITLE'] = $pageTitle;

renderTemplateImproved('profile', $template_data);