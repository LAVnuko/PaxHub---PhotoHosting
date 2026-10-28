<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang;

if (!isset($_GET['code'])) {
    redirect('/gallery');
}

$shortCode = $_GET['code'];
$file = getFileByShortCode($shortCode);

if (!$file) {
    http_response_code(404);
    renderTemplateRawImproved('error_page', ['ERROR_MESSAGE' => $lang['FILE_NOT_FOUND']]);
    exit;
}

// Чтение опций загрузки (Ч/Б, кнопка "Увеличить", размер превью)
$file_options = [];
if (!empty($file['options'])) {
    $file_options = json_decode($file['options'], true);
}
$is_bw = $file_options['biw'] ?? false;
$show_enlarge = $file_options['ptyes'] ?? false;
$enlarge_text = $file_options['ptext'] ?? 'Увеличить';
$preview_width = (int)($file_options['prew'] ?? 160);

// Увеличиваем счетчик просмотров
incrementFileViews($file['id']);

// Получаем информацию о пользователе, который загрузил файл
$uploader = null;
if ($file['user_id']) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT username, avatar, registered_at FROM users WHERE id = ?");
    $stmt->execute([$file['user_id']]);
    $uploader = $stmt->fetch();
}

// НОВОЕ: получаем категорию файла
$category = null;
if ($file['category_id']) {
    $category = getCategoryById($file['category_id']);
}

$pageTitle = htmlspecialchars($file['original_name']) . ' | ' . SITE_NAME;
$pageDescription = $lang['FILE_VIEW_DESCRIPTION'] . ' ' . htmlspecialchars($file['original_name']);
$pageKeywords = htmlspecialchars($file['original_name']);

// Определяем тип файла
$extension = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
$file_url = '/uploads/' . $file['stored_name'];

// Получаем похожие файлы (от того же пользователя)
$similar_files = [];
if ($file['user_id']) {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE user_id = ? AND id != ? ORDER BY views DESC LIMIT 8");
    $stmt->execute([$file['user_id'], $file['id']]);
    $similar_files = $stmt->fetchAll();
}

// CSRF-токен для удаления файла (только для владельца)
$delete_csrf_token = '';
$is_owner = isLoggedIn() && $_SESSION['user_id'] == $file['user_id'];
if ($is_owner) {
    if (empty($_SESSION['delete_csrf_token'])) {
        $_SESSION['delete_csrf_token'] = bin2hex(random_bytes(32));
    }
    $delete_csrf_token = $_SESSION['delete_csrf_token'];
}

// Готовые строки для кодов публикации
$http_host = $_SERVER['HTTP_HOST'];
$short_code = $file['short_code'];
$direct_url = "http://{$http_host}{$file_url}";
$view_url = "http://{$http_host}/view/{$short_code}";
$original_name_attr = htmlspecialchars($file['original_name'], ENT_QUOTES, 'UTF-8');

// Добавляем похожие файлы
global $improved_template;
foreach ($similar_files as $sim_file) {
    $sim_file_url = '/uploads/' . $sim_file['stored_name'];
    $sim_display_name = $sim_file['original_name'];
    if (mb_strlen($sim_display_name) > 50) {
        $sim_display_name = mb_substr($sim_display_name, 0, 47) . '...';
    }
    $improved_template->assign_block_vars('similar', [
        'ID' => $sim_file['id'],
        'SHORT_CODE' => $sim_file['short_code'],
        'ORIGINAL_NAME' => htmlspecialchars($sim_display_name),
        'SIZE' => round($sim_file['size'] / 1024),
        'VIEWS' => $sim_file['views'],
        'URL' => $sim_file_url,
        'FILE_URL' => $sim_file_url
    ]);
}
// Обрезаем длинное имя файла для отображения (макс. 50 символов)
$display_name = $file['original_name'];
if (mb_strlen($display_name) > 50) {
    $display_name = mb_substr($display_name, 0, 47) . '...';
}
$full_name = $file['original_name']; // полное имя для alt/title

// Данные для шаблона
$template_data = [
    'user' => getCurrentUser(),
    'FILE_ID' => $file['id'],
    'SHORT_CODE' => $file['short_code'],
    'ORIGINAL_NAME' => htmlspecialchars($display_name),
    'FULL_ORIGINAL_NAME' => htmlspecialchars($full_name),
    'SIZE_KB' => round($file['size'] / 1024),
    'SIZE_BYTES' => $file['size'],
    'MIME_TYPE' => $file['mime_type'],
    'UPLOAD_DATE' => date('d.m.Y H:i:s', strtotime($file['upload_date'])),
    'UPLOAD_DATE_FULL' => date('d F Y, H:i:s', strtotime($file['upload_date'])),
    'VIEWS' => $file['views'],
    'URL' => $file_url,
    'VIEW_URL' => '/view/' . $file['short_code'],
    'IS_IMAGE' => $is_image,
    'EXTENSION' => strtoupper($extension),
    'USER_ID' => $file['user_id'],
    'USERNAME' => $uploader ? htmlspecialchars($uploader['username']) : $lang['GUEST'],
    'USER_AVATAR' => $uploader && $uploader['avatar'] ? '/uploads/' . $uploader['avatar'] : '/img/default-avatar.png',
    'USER_PROFILE_URL' => $file['user_id'] ? '/profile/' . $uploader['username'] : '#',
    'USER_REGISTERED' => $uploader ? date('d.m.Y', strtotime($uploader['registered_at'])) : '',
    'EXPIRATION_DATE' => $file['expiration_date'] ? date('d.m.Y', strtotime($file['expiration_date'])) : $lang['UNLIMITED'],
    'IS_OWNER' => $is_owner,
    'SIMILAR_COUNT' => count($similar_files),
    'SHOW_SIMILAR' => count($similar_files) > 0,
    'PAGE_TITLE' => $pageTitle,
    'PAGE_DESCRIPTION' => $pageDescription,
    'PAGE_KEYWORDS' => $pageKeywords,
    'DELETE_CSRF_TOKEN' => $delete_csrf_token,
    'SHORT_LINK' => $view_url,
    'DIRECT_LINK' => $direct_url,
    'BBCODE_FULL' => "[url={$view_url}][img]{$direct_url}[/img][/url]",
    'BBCODE_IMAGE' => "[img]{$direct_url}[/img]",
    'HTML_FULL' => '<a href="' . $view_url . '"><img src="' . $direct_url . '" alt="' . $original_name_attr . '"></a>',
    'HTML_IMAGE' => '<img src="' . $direct_url . '" alt="' . $original_name_attr . '">',
    'MD_FULL' => "[![{$original_name_attr}]({$direct_url})]({$view_url})",
    'MD_IMAGE' => "![{$original_name_attr}]({$direct_url})",
    // Опции загрузки
    'IS_BW' => $is_bw,
    'SHOW_ENLARGE' => $show_enlarge,
    'ENLARGE_TEXT' => $enlarge_text,
    'PREVIEW_WIDTH' => $preview_width,
    // НОВОЕ: информация о категории
    'CATEGORY_ID' => $file['category_id'],
    'CATEGORY_NAME' => $category ? htmlspecialchars($category['name']) : ''
];

renderTemplateImproved('show', $template_data);