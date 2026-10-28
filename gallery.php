<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang, $pdo, $improved_template;

$pageTitle = $lang['GALLERY'] . ' | ' . SITE_NAME;
$pageDescription = $lang['GALLERY_DESCRIPTION'] ?? 'Просмотр всех изображений на фотохостинге. Галерея фотографий пользователей.';
$pageKeywords = 'галерея, фото, изображения, картинки';

// Пагинация
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 12;
$offset = ($page - 1) * $per_page;

// Фильтр по категории
$selectedCategoryId = isset($_GET['category']) ? (int)$_GET['category'] : null;
if ($selectedCategoryId) {
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
    $stmt->execute([$selectedCategoryId]);
    if (!$stmt->fetch()) $selectedCategoryId = null;
}

// Общее количество файлов с учётом фильтра
$countSql = "SELECT COUNT(*) FROM files f";
$countParams = [];
if ($selectedCategoryId) {
    $countSql .= " WHERE f.category_id = ?";
    $countParams[] = $selectedCategoryId;
}
$stmt = $pdo->prepare($countSql);
$stmt->execute($countParams);
$total_files = $stmt->fetchColumn();
$total_pages = ceil($total_files / $per_page);

// Получаем файлы с присоединёнными именами категорий
$sql = "SELECT f.*, u.username, c.name as category_name 
        FROM files f 
        LEFT JOIN users u ON f.user_id = u.id 
        LEFT JOIN categories c ON f.category_id = c.id";
if ($selectedCategoryId) {
    $sql .= " WHERE f.category_id = ?";
}
$sql .= " ORDER BY f.upload_date DESC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($sql);
$params = [];
if ($selectedCategoryId) $params[] = $selectedCategoryId;
$stmt->execute($params);
$files = $stmt->fetchAll();

// Построение пагинации (без изменений)
$pagination_pages = [];
$show_pagination = ($total_pages > 1);

for ($i = 1; $i <= $total_pages; $i++) {
    if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)) {
        $pagination_pages[] = ['num' => $i, 'is_current' => ($i == $page)];
    } elseif ($i == $page - 3 || $i == $page + 3) {
        $pagination_pages[] = ['num' => '...', 'is_dots' => true];
    }
}

$has_prev = ($page > 1);
$has_next = ($page < $total_pages);
$show_no_files = ($total_files == 0);
$show_files_list = ($total_files > 0);
$categories = getAllCategories();
// После получения $categories (через getAllCategories())
foreach ($categories as $cat) {
    $improved_template->assign_block_vars('categories', [
        'ID' => $cat['id'],
        'NAME' => htmlspecialchars($cat['name']),
        'SELECTED' => ($selectedCategoryId == $cat['id']) ? 'selected' : ''
    ]);
}
$template_data = [
    'user' => getCurrentUser(),
    'TOTAL_FILES' => $total_files,
    'CURRENT_PAGE' => $page,
    'TOTAL_PAGES' => $total_pages,
    'HAS_PREV' => $has_prev,
    'HAS_NEXT' => $has_next,
    'PREV_PAGE' => $page - 1,
    'NEXT_PAGE' => $page + 1,
    'SHOW_PAGINATION' => $show_pagination,
    'SHOW_NO_FILES' => $show_no_files,
    'SHOW_FILES_LIST' => $show_files_list,
    'PAGE_TITLE' => $pageTitle,
    'PAGE_DESCRIPTION' => $pageDescription,
    'PAGE_KEYWORDS' => $pageKeywords,
    'CURRENT_LANG' => $_SESSION['lang'] ?? DEFAULT_LANG,
    'SELECTED_CATEGORY_ID' => $selectedCategoryId,
    'CATEGORIES' => $categories,
];

$js_keys = ['SHORT_LINK', 'COPY', 'DIRECT_LINK', 'BBCODE_PREVIEW', 'BBCODE_IMAGE',
            'HTML_PREVIEW', 'HTML_IMAGE', 'MARKDOWN_PREVIEW', 'MARKDOWN_IMAGE', 'FILE_PAGE'];
$js_lang = [];
foreach ($js_keys as $key) {
    $js_lang[$key] = $lang[$key] ?? $key;
}
$template_data['LANG_JS'] = json_encode($js_lang, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (json_last_error() !== JSON_ERROR_NONE) {
    die('JSON error: ' . json_last_error_msg());
}

foreach ($files as $file) {
    $extension = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
    $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
    $file_url = '/uploads/' . $file['stored_name'];
    $view_url = '/view/' . $file['short_code'];
    $username = $file['username'] ?? $lang['GUEST'] ?? 'Гость';
    $user_url = $file['user_id'] ? '/profile/' . $username : '#';
    
    $improved_template->assign_block_vars('files', [
        'ID' => $file['id'],
        'SHORT_CODE' => $file['short_code'],
        'ORIGINAL_NAME' => htmlspecialchars($file['original_name']),
        'SIZE' => round($file['size'] / 1024),
        'UPLOAD_DATE' => date('d.m.Y H:i', strtotime($file['upload_date'])),
        'VIEWS' => $file['views'],
        'URL' => $file_url,
        'VIEW_URL' => $view_url,
        'USERNAME' => htmlspecialchars($username),
        'USER_URL' => $user_url,
        'IS_IMAGE' => $is_image,
        'EXTENSION' => strtoupper($extension),
        'CATEGORY_ID' => $file['category_id'],
        'CATEGORY_NAME' => htmlspecialchars($file['category_name'] ?? '')
    ]);
}

// Пагинация HTML
$pagination_html = '';
if ($total_pages > 1) {
    $pagination_html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center flex-wrap">';
    if ($page > 1) {
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . ($page - 1) . ($selectedCategoryId ? '&category=' . $selectedCategoryId : '') . '">&laquo;</a></li>';
    } else {
        $pagination_html .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
    }
    if ($page > 3) {
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=1' . ($selectedCategoryId ? '&category=' . $selectedCategoryId : '') . '">1</a></li>';
        if ($page > 4) $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
    }
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $page) {
            $pagination_html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . $i . ($selectedCategoryId ? '&category=' . $selectedCategoryId : '') . '">' . $i . '</a></li>';
        }
    }
    if ($page < $total_pages - 2) {
        if ($page < $total_pages - 3) $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . ($selectedCategoryId ? '&category=' . $selectedCategoryId : '') . '">' . $total_pages . '</a></li>';
    }
    if ($page < $total_pages) {
        $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . ($page + 1) . ($selectedCategoryId ? '&category=' . $selectedCategoryId : '') . '">&raquo;</a></li>';
    } else {
        $pagination_html .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
    }
    $pagination_html .= '</ul></nav>';
}
$template_data['PAGINATION_HTML'] = $pagination_html;

renderTemplateImproved('gallery', $template_data);