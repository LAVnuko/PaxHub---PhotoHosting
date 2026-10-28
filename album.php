<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang, $improved_template, $pdo;

$action = $_GET['action'] ?? '';
$username = $_GET['username'] ?? null;
$shortKey = $_GET['short_key'] ?? null;

// ============================================================
// 0. ВСЕ АЛЬБОМЫ ВСЕХ ПОЛЬЗОВАТЕЛЕЙ (/album)
// ============================================================
if (empty($action) && empty($username) && empty($shortKey)) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.username 
        FROM albums a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.created_at DESC
    ");
    $stmt->execute();
    $all_albums = $stmt->fetchAll();

    $template_data = [
        'ALL_ALBUMS_MODE' => true,
        'HAS_ALBUMS' => !empty($all_albums),
        'PAGE_TITLE' => $lang['ALL_ALBUMS_TITLE'] . ' | ' . SITE_NAME,
    ];

    foreach ($all_albums as $album) {
        $improved_template->assign_block_vars('albums', [
            'id'          => $album['id'],
            'short_key'   => $album['short_key'],
            'name'        => htmlspecialchars($album['name']),
            'description' => htmlspecialchars($album['description'] ?? ''),
            'created_at'  => date('d.m.Y', strtotime($album['created_at'])),
            'username'    => htmlspecialchars($album['username']),
            'user_url'    => '/album/' . urlencode($album['username']),
        ]);
    }

    renderTemplateImproved('album', $template_data);
    exit;
}

// ============================================================
// 1. СОЗДАНИЕ АЛЬБОМА (/album/create)
// ============================================================
if ($action === 'create') {
    if (!isLoggedIn()) redirect('/auth');
    $user = getCurrentUser();
    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (empty($name)) {
            $error = $lang['ALBUM_NAME_REQUIRED'];
        } else {
            createAlbum($user['id'], $name, $description);
            redirect('/album/' . urlencode($user['username']));
        }
    }

    $template_data = [
        'CREATE_MODE' => true,
        'error' => $error,
        'success' => $success,
        'user'  => $user,
        'PAGE_TITLE' => $lang['CREATE_ALBUM_TITLE'] . ' | ' . SITE_NAME,
    ];
    renderTemplateImproved('album', $template_data);
    exit;
}

// ========== 1.2 ДОБАВЛЕНИЕ ФАЙЛОВ В АЛЬБОМ (/album/add/КЛЮЧ) ==========
if ($action === 'add' && isset($_GET['album'])) {
    if (!isLoggedIn()) redirect('/auth');
    $albumKey = $_GET['album'];
    $album = getAlbumByShortKey($albumKey);
    if (!$album) { http_response_code(404); die('Альбом не найден'); }
    if ($_SESSION['user_id'] != $album['user_id']) { http_response_code(403); die('Доступ запрещён'); }

    $message = $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['file_ids'])) {
        $added = 0;
        foreach ((array)$_POST['file_ids'] as $fid) {
            $fid = (int)$fid;
            $stmt = $pdo->prepare("SELECT id FROM files WHERE id = ? AND user_id = ? AND (album_id IS NULL OR album_id = 0)");
            $stmt->execute([$fid, $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                $upd = $pdo->prepare("UPDATE files SET album_id = ? WHERE id = ?");
                $upd->execute([$album['id'], $fid]);
                $added++;
            }
        }
        if ($added) $message = "Добавлено файлов: $added";
        else $error = "Не выбрано ни одного подходящего файла";
    }

    $stmt = $pdo->prepare("SELECT id, original_name, stored_name, size, mime_type, upload_date FROM files WHERE user_id = ? AND (album_id IS NULL OR album_id = 0) ORDER BY upload_date DESC");
    $stmt->execute([$_SESSION['user_id']]);
    $user_files = $stmt->fetchAll();

    $template_data = [
        'ADD_MODE' => true,
        'ALBUM_NAME' => htmlspecialchars($album['name']),
        'ALBUM_SHORT_KEY' => $album['short_key'],
        'MESSAGE' => $message,
        'ERROR' => $error,
        'HAS_FILES' => !empty($user_files),
        'NO_FILES' => empty($user_files),
        'PAGE_TITLE' => 'Добавление файлов в альбом: ' . htmlspecialchars($album['name']) . ' | ' . SITE_NAME,
    ];

    foreach ($user_files as $file) {
        $is_image = strpos($file['mime_type'], 'image/') === 0;
        $file_url = '/uploads/' . $file['stored_name'];
        $thumb_url = $file_url;
        $improved_template->assign_block_vars('files', [
            'ID' => $file['id'],
            'NAME' => htmlspecialchars($file['original_name']),
            'SIZE' => round($file['size'] / 1024),
            'UPLOAD_DATE' => date('d.m.Y H:i', strtotime($file['upload_date'])),
            'IS_IMAGE' => $is_image,
            'URL' => $file_url,
            'THUMB_URL' => $thumb_url,
        ]);
    }

    renderTemplateImproved('album', $template_data);
    exit;
}

// ========== 1.3 РЕДАКТИРОВАНИЕ АЛЬБОМА (/album/edit/ID) ==========
if ($action === 'edit' && isset($_GET['id'])) {
    if (!isLoggedIn()) redirect('/auth');
    $albumId = (int)$_GET['id'];
    $album = getAlbumById($albumId);
    if (!$album) { http_response_code(404); die('Альбом не найден'); }
    if ($_SESSION['user_id'] != $album['user_id']) { http_response_code(403); die('Доступ запрещён'); }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (empty($name)) {
            $error = $lang['ALBUM_NAME_REQUIRED'];
        } else {
            $stmt = $pdo->prepare("UPDATE albums SET name = ?, description = ? WHERE id = ?");
            $stmt->execute([$name, $description, $albumId]);
            redirect('/album/show/' . $album['short_key']);
        }
    }

    $template_data = [
        'EDIT_MODE' => true,
        'ALBUM_NAME'        => htmlspecialchars($album['name']),
        'ALBUM_DESCRIPTION' => htmlspecialchars($album['description'] ?? ''),
        'ALBUM_SHORT_KEY'   => $album['short_key'],
        'ERROR'             => $error,
        'PAGE_TITLE'        => 'Редактирование альбома: ' . htmlspecialchars($album['name']) . ' | ' . SITE_NAME,
    ];
    renderTemplateImproved('album', $template_data);
    exit;
}

// ========== 1.4 УДАЛЕНИЕ ФАЙЛА ИЗ АЛЬБОМА (/album/remove) ==========
if ($action === 'remove' && isset($_GET['file']) && isset($_GET['album'])) {
    if (!isLoggedIn()) redirect('/auth');
    $fileId = (int)$_GET['file'];
    $albumKey = $_GET['album'];
    
    $stmt = $pdo->prepare("
        SELECT f.id, a.user_id 
        FROM files f 
        JOIN albums a ON f.album_id = a.id 
        WHERE f.id = ? AND a.short_key = ? AND f.user_id = ?
    ");
    $stmt->execute([$fileId, $albumKey, $_SESSION['user_id']]);
    if ($stmt->fetch()) {
        $upd = $pdo->prepare("UPDATE files SET album_id = NULL WHERE id = ?");
        $upd->execute([$fileId]);
        redirect('/album/show/' . $albumKey);
    } else {
        http_response_code(403);
        die('Доступ запрещён или файл не в этом альбоме');
    }
    exit;
}

// ============================================================
// 2. СПИСОК АЛЬБОМОВ ПОЛЬЗОВАТЕЛЯ (/album/username)
// ============================================================
if ($username) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $profileUser = $stmt->fetch();
    if (!$profileUser) {
        http_response_code(404);
        renderTemplateRawImproved('access_denied', ['ERROR_MESSAGE' => $lang['USER_NOT_FOUND']]);
        exit;
    }

    $currentUser = getCurrentUser();
    $isOwnProfile = ($currentUser && $currentUser['id'] == $profileUser['id']);
    $albums = getUserAlbums($profileUser['id']);
    $hasAlbums = !empty($albums);

    $template_data = [
        'USER_ALBUMS_MODE' => true,
        'user'            => $profileUser,
        'IS_OWN_PROFILE'  => $isOwnProfile,
        'USERNAME'        => htmlspecialchars($profileUser['username']),
        'SHOW_ALBUMS'     => $hasAlbums,
        'SHOW_NO_ALBUMS'  => !$hasAlbums,
        'PAGE_TITLE'      => $lang['ALBUMS_TITLE'] . ' ' . htmlspecialchars($profileUser['username']) . ' | ' . SITE_NAME,
    ];

    foreach ($albums as $album) {
        $improved_template->assign_block_vars('albums', [
            'id'          => $album['id'],
            'short_key'   => $album['short_key'],
            'name'        => htmlspecialchars($album['name']),
            'description' => htmlspecialchars($album['description'] ?? ''),
            'created_at'  => date('d.m.Y', strtotime($album['created_at'])),
        ]);
    }

    renderTemplateImproved('album', $template_data);
    exit;
}

// ============================================================
// 3. ПРОСМОТР КОНКРЕТНОГО АЛЬБОМА (/album/show/key)
// ============================================================
if ($shortKey) {
    $album = getAlbumByShortKey($shortKey);
    if (!$album) {
        http_response_code(404);
        renderTemplateRawImproved('access_denied', ['ERROR_MESSAGE' => $lang['ALBUM_NOT_FOUND'] ?? 'Альбом не найден']);
        exit;
    }

    $ownerStmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $ownerStmt->execute([$album['user_id']]);
    $ownerUser = $ownerStmt->fetch();
    
    if (!$ownerUser) {
        $ownerUser = ['username' => $lang['DELETED_USER'] ?? 'Удалённый пользователь'];
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 21;
    $offset = ($page - 1) * $per_page;

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE album_id = ?");
    $totalStmt->execute([$album['id']]);
    $total_files = $totalStmt->fetchColumn();
    $total_pages = ceil($total_files / $per_page);
    
    $files = getAlbumFiles($album['id'], $per_page, $offset);

    $js_keys = ['SHORT_LINK', 'DIRECT_LINK', 'BBCODE_PREVIEW', 'BBCODE_IMAGE',
                'HTML_PREVIEW', 'HTML_IMAGE', 'MARKDOWN_PREVIEW', 'MARKDOWN_IMAGE', 'FILE_PAGE'];
    $js_lang = [];
    foreach ($js_keys as $key) {
        $js_lang[$key] = $lang[$key] ?? $key;
    }
    $lang_js = json_encode($js_lang, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    $template_data = [
        'VIEW_MODE' => true,
        'ALBUM_NAME'        => htmlspecialchars($album['name']),
        'ALBUM_DESCRIPTION' => htmlspecialchars($album['description'] ?? ''),
        'ALBUM_CREATED_AT'  => date('d.m.Y', strtotime($album['created_at'])),
        'ALBUM_SHORT_KEY'   => $album['short_key'],
        'ALBUM_ID'          => $album['id'],
        'OWNER_USERNAME'    => htmlspecialchars($ownerUser['username']),
        'OWNER_USER_ID'     => $album['user_id'],
        'TOTAL_FILES'       => $total_files,
        'SHOW_NO_FILES'     => ($total_files == 0),
        'SHOW_FILES'        => ($total_files > 0),
        'IS_OWNER'          => (isLoggedIn() && $_SESSION['user_id'] == $album['user_id']),
        'PAGE_TITLE'        => $lang['ALBUM'] . ': ' . htmlspecialchars($album['name']) . ' | ' . SITE_NAME,
        'LANG_JS'           => $lang_js,
    ];

    // Генерация Bootstrap пагинации
    $pagination_html = '';
    if ($total_pages > 1) {
        $pagination_html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center flex-wrap">';
        
        // Кнопка "Назад"
        if ($page > 1) {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . ($page - 1) . '" aria-label="Previous"><span aria-hidden="true">&laquo;</span><span class="visually-hidden">' . $lang['PREV'] . '</span></a></li>';
        } else {
            $pagination_html .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
        }
        
        // Первая страница и многоточие в начале
        if ($page > 3) {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
            if ($page > 4) $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        
        // Диапазон страниц вокруг текущей
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $page) {
                $pagination_html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
            } else {
                $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . $i . '">' . $i . '</a></li>';
            }
        }
        
        // Последняя страница и многоточие в конце
        if ($page < $total_pages - 2) {
            if ($page < $total_pages - 3) $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '">' . $total_pages . '</a></li>';
        }
        
        // Кнопка "Вперёд"
        if ($page < $total_pages) {
            $pagination_html .= '<li class="page-item"><a class="page-link" href="?page=' . ($page + 1) . '" aria-label="Next"><span aria-hidden="true">&raquo;</span><span class="visually-hidden">' . $lang['NEXT'] . '</span></a></li>';
        } else {
            $pagination_html .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
        }
        
        $pagination_html .= '</ul></nav>';
    }
    $template_data['PAGINATION_HTML'] = $pagination_html;

    foreach ($files as $file) {
        $extension = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
        $is_image = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
        $file_url = '/uploads/' . $file['stored_name'];
        $username = $ownerUser['username'];
        $user_url = ($ownerUser['username'] != ($lang['DELETED_USER'] ?? 'Удалённый пользователь')) ? '/profile/' . $username : '#';
        
        $improved_template->assign_block_vars('files', [
            'ID'            => $file['id'],
            'SHORT_CODE'    => $file['short_code'],
            'ORIGINAL_NAME' => htmlspecialchars($file['original_name']),
            'SIZE'          => round($file['size'] / 1024),
            'UPLOAD_DATE'   => date('d.m.Y H:i', strtotime($file['upload_date'])),
            'VIEWS'         => $file['views'],
            'URL'           => $file_url,
            'USERNAME'      => htmlspecialchars($username),
            'USER_URL'      => $user_url,
            'IS_IMAGE'      => $is_image,
            'EXTENSION'     => strtoupper($extension)
        ]);
    }

    renderTemplateImproved('album', $template_data);
    exit;
}

redirect('/');