<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php';

use App\Core\Database;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;

// Загрузка .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Whoops
if ($_ENV['APP_DEBUG'] ?? false) {
    $whoops = new Run();
    $whoops->pushHandler(new PrettyPageHandler());
    $whoops->register();
}

session_start();

// Подключаем шаблонизатор
require_once __DIR__ . '/includes/template.php';

$db = Database::getInstance();
$projectRepo = new \App\Repository\ProjectRepository($db);

// Разбор URL
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ==================== РОУТЫ ====================

// Главная страница
if ($uri === '/' && $method === 'GET') {
    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');

    // Авторизация для шапки
    if (isset($_SESSION['user_id'])) {
        $template->assign_block_vars('user_logged_in', []);
        $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
    } else {
        $template->assign_vars(['USERNAME' => 'Гость']);
    }

    // === Пагинация ===
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;

    // === Общая статистика ===
    $totalServers = $db->query('SELECT COUNT(*) FROM servers')->fetchColumn();
    $totalPlayers = $db->query('SELECT SUM(players_online) FROM servers')->fetchColumn() ?: 0;
    $lastUpdated = date('d.m.Y H:i');

    // === Список серверов с пагинацией ===
    $stmt = $db->prepare('SELECT * FROM servers ORDER BY votes_count DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
    $stmt->execute();
    $servers = $stmt->fetchAll();

    // === Переменные для пагинации ===
    $totalPages = ceil($totalServers / $limit);
    $prevPage = $page > 1 ? $page - 1 : null;
    $nextPage = $page < $totalPages ? $page + 1 : null;

    $template->assign_vars([
        'PAGE_TITLE'      => 'Главная',
        'TOTAL_SERVERS'   => number_format($totalServers, 0, '', ' '),
        'TOTAL_PLAYERS'   => number_format($totalPlayers, 0, '', ' '),
        'LAST_UPDATED'    => $lastUpdated,
        'CURRENT_PAGE'    => $page,
        'PREV_PAGE'       => $prevPage,
        'NEXT_PAGE'       => $nextPage,
    ]);

    // === Блок серверов ===
    $rowNumber = $offset + 1;
    foreach ($servers as $server) {
        $rank = $rowNumber - $offset;
        $badgeCrown = ($rank == 1);
        $badgeGold = ($rank <= 3);
        $badgeBlue = ($rank >= 4 && $rank <= 6);
        $badgeGreen = ($server['players_online'] > 1000);
        $badgePurple = (strtotime($server['created_at']) > strtotime('-7 days'));
        $badgeTeal = ($server['players_online'] > 100 && $server['players_online'] <= 500);

        $wipeDays = null;
        if (!empty($server['created_at'])) {
            $diff = (new \DateTime())->diff(new \DateTime($server['created_at']));
            $wipeDays = $diff->days;
        }

        $template->assign_block_vars('servers', [
            'ROW_NUMBER'     => $rowNumber++,
            'ID'             => $server['id'],
            'NAME'           => htmlspecialchars($server['name']),
            'SHORT_DESC'     => htmlspecialchars(substr($server['short_description'] ?? $server['description'] ?? '', 0, 100)),
            'IP'             => htmlspecialchars($server['ip']),
            'VERSION'        => htmlspecialchars($server['version']),
            'PLAYERS_ONLINE' => $server['players_online'],
            'MAX_PLAYERS'    => $server['max_players'],
            'VOTES'          => $server['votes_count'],
            'RATING'         => $server['rating'],
            'STATUS'         => $server['status'],
            'BANNER'         => $server['banner'] ?? '/assets/img/default-banner.png',
            'ICON'           => htmlspecialchars(substr($server['name'], 0, 1)),
            'WIPE_DAYS'      => $wipeDays,
            'VOTE_BONUS'     => false,
            'COPIES_MONTH'   => 0,
            'COPIES_TODAY'   => 0,
            'BADGE_CROWN'    => $badgeCrown,
            'BADGE_GOLD'     => $badgeGold,
            'BADGE_BLUE'     => $badgeBlue,
            'BADGE_GREEN'    => $badgeGreen,
            'BADGE_PURPLE'   => $badgePurple,
            'BADGE_TEAL'     => $badgeTeal,
        ]);
    }

    $template->set_filenames(['header' => 'header.tpl', 'body' => 'index.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

// ==================== СТРАНИЦА ДОБАВЛЕНИЯ СЕРВЕРА (GET) ====================
if ($uri === '/add-server' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }

    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
    if (isset($_SESSION['user_id'])) {
        $template->assign_block_vars('user_logged_in', []);
        $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
    } else {
        $template->assign_vars(['USERNAME' => 'Гость']);
    }

    $projectId = (int)($_GET['project_id'] ?? 0);

    $template->assign_vars([
        'PAGE_TITLE'         => 'Добавить сервер',
        'ERROR'              => '',
        'NAME'               => '',
        'SITE'               => '',
        'IP'                 => '',
        'PORT'               => 25565,
        'VERSION'            => '',
        'GAME_MODE'          => 'Survival',
        'BANNER'             => '',
        'SHORT_DESCRIPTION'  => '',
        'DESCRIPTION'        => '',
        'COUNTRY'            => '',
        'VK'                 => '',
        'FB'                 => '',
        'GP'                 => '',
        'YOUTUBE'            => '',
        'MODS'               => '',
        'PLUGINS'            => '',
        'PROJECT_ID'         => $projectId,
    ]);

    $template->set_filenames(['header' => 'header.tpl', 'body' => 'add_server.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

// ==================== ОБРАБОТКА ФОРМЫ ДОБАВЛЕНИЯ СЕРВЕРА (POST) ====================
if ($uri === '/add-server' && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo 'Доступ запрещён';
        exit;
    }

    // Получаем все поля
    $name = trim($_POST['name'] ?? '');
    $site = trim($_POST['site'] ?? '');
    $ip = trim($_POST['ip'] ?? '');
    $port = (int)($_POST['port'] ?? 25565);
    $version = trim($_POST['version'] ?? '');
    $game_mode = trim($_POST['game_mode'] ?? 'Survival');
    $banner = trim($_POST['banner'] ?? '');
    $short_description = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $vk = trim($_POST['vk'] ?? '');
    $fb = trim($_POST['fb'] ?? '');
    $gp = trim($_POST['gp'] ?? '');
    $youtube = trim($_POST['youtube'] ?? '');
    $mods = array_map('trim', explode(',', $_POST['mods'] ?? ''));
    $plugins = array_map('trim', explode(',', $_POST['plugins'] ?? ''));
    $projectIdFromPost = (int)($_POST['project_id'] ?? 0);

    // Валидация
    $errors = [];
    if (empty($name)) $errors[] = 'Название обязательно';
    if (empty($site)) $errors[] = 'Сайт обязателен';
    if (empty($ip)) $errors[] = 'IP-адрес обязателен';
    if (empty($version)) $errors[] = 'Версия обязательна';
    if (empty($banner)) $errors[] = 'Ссылка на баннер обязательна';
    if (empty($short_description)) $errors[] = 'Краткое описание обязательно';

    // Обработка скриншотов
    $screenshots = [];
    if (!empty($_FILES['screenshots']['name'][0])) {
        $uploadDir = __DIR__ . '/uploads/screenshots/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $allowed = ['image/jpeg', 'image/png', 'image/gif'];
        $maxFiles = 12;
        $totalFiles = count($_FILES['screenshots']['name']);
        if ($totalFiles > $maxFiles) {
            $errors[] = "Можно загрузить не более $maxFiles скриншотов";
        } else {
            for ($i = 0; $i < $totalFiles; $i++) {
                if ($_FILES['screenshots']['error'][$i] === UPLOAD_ERR_OK) {
                    $type = mime_content_type($_FILES['screenshots']['tmp_name'][$i]);
                    if (!in_array($type, $allowed)) {
                        $errors[] = 'Разрешены только JPG, PNG, GIF';
                        break;
                    }
                    $ext = pathinfo($_FILES['screenshots']['name'][$i], PATHINFO_EXTENSION);
                    $filename = uniqid() . '.' . $ext;
                    $target = $uploadDir . $filename;
                    if (move_uploaded_file($_FILES['screenshots']['tmp_name'][$i], $target)) {
                        $screenshots[] = '/uploads/screenshots/' . $filename;
                    } else {
                        $errors[] = 'Ошибка загрузки скриншота #' . ($i+1);
                    }
                }
            }
        }
    }

    if (!empty($errors)) {
        $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
        if (isset($_SESSION['user_id'])) {
            $template->assign_block_vars('user_logged_in', []);
            $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
        }
        $template->assign_vars([
            'PAGE_TITLE'         => 'Добавить сервер',
            'ERROR'              => implode('<br>', $errors),
            'NAME'               => htmlspecialchars($name),
            'SITE'               => htmlspecialchars($site),
            'IP'                 => htmlspecialchars($ip),
            'PORT'               => $port,
            'VERSION'            => htmlspecialchars($version),
            'GAME_MODE'          => htmlspecialchars($game_mode),
            'BANNER'             => htmlspecialchars($banner),
            'SHORT_DESCRIPTION'  => htmlspecialchars($short_description),
            'DESCRIPTION'        => htmlspecialchars($description),
            'COUNTRY'            => htmlspecialchars($country),
            'VK'                 => htmlspecialchars($vk),
            'FB'                 => htmlspecialchars($fb),
            'GP'                 => htmlspecialchars($gp),
            'YOUTUBE'            => htmlspecialchars($youtube),
            'MODS'               => htmlspecialchars($_POST['mods'] ?? ''),
            'PLUGINS'            => htmlspecialchars($_POST['plugins'] ?? ''),
            'PROJECT_ID'         => $projectIdFromPost,
        ]);
        $template->set_filenames(['header' => 'header.tpl', 'body' => 'add_server.tpl', 'footer' => 'footer.tpl']);
        $template->pparse('header');
        $template->pparse('body');
        $template->pparse('footer');
        exit;
    }

    // --- ОПРЕДЕЛЯЕМ PROJECT_ID ---
    $projectId = null;
    if ($projectIdFromPost > 0) {
        // Проверяем, что проект принадлежит текущему пользователю
        $project = $projectRepo->find($projectIdFromPost);
        if ($project && $project->getOwnerId() == $_SESSION['user_id']) {
            $projectId = $projectIdFromPost;
        } else {
            // Если чужой проект — создаём новый или используем первый
            $projects = $projectRepo->findByOwner((int)$_SESSION['user_id']);
            $projectId = empty($projects) ? null : $projects[0]->getId();
        }
    } else {
        // Старая логика
        $projects = $projectRepo->findByOwner((int)$_SESSION['user_id']);
        if (empty($projects)) {
            $project = new \App\Entity\Project();
            $project->setName($name)
                    ->setDescription($short_description)
                    ->setBanner($banner)
                    ->setSite($site)
                    ->setOwnerId((int)$_SESSION['user_id']);
            $projectRepo->save($project);
            $projectId = $project->getId();
        } else {
            $projectId = $projects[0]->getId();
        }
    }

    // Сохранение сервера с project_id
    $stmt = $db->prepare("
        INSERT INTO servers 
        (name, site, ip, port, version, game_mode, banner, short_description, description, country, vk, fb, gp, youtube, mods, plugins, screenshots, owner_id, project_id, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown')
    ");
    $stmt->execute([
        $name,
        $site,
        $ip,
        $port,
        $version,
        $game_mode,
        $banner,
        $short_description,
        $description,
        $country,
        $vk,
        $fb,
        $gp,
        $youtube,
        json_encode($mods),
        json_encode($plugins),
        json_encode($screenshots),
        (int)$_SESSION['user_id'],
        $projectId,
    ]);
    $serverId = $db->lastInsertId();

    header('Location: /server/' . $serverId);
    exit;
}

// ==================== ВХОД ====================
if ($uri === '/login' && $method === 'GET') {
    if (isset($_SESSION['user_id'])) {
        header('Location: /');
        exit;
    }
    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
    $template->assign_vars(['PAGE_TITLE' => 'Вход', 'ERROR' => '', 'EMAIL' => '']);
    $template->set_filenames(['header' => 'header.tpl', 'body' => 'login.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

if ($uri === '/login' && $method === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
        $template->assign_vars(['PAGE_TITLE' => 'Вход', 'ERROR' => 'Заполните все поля', 'EMAIL' => htmlspecialchars($email)]);
        $template->set_filenames(['header' => 'header.tpl', 'body' => 'login.tpl', 'footer' => 'footer.tpl']);
        $template->pparse('header');
        $template->pparse('body');
        $template->pparse('footer');
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        header('Location: /');
        exit;
    } else {
        $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
        $template->assign_vars(['PAGE_TITLE' => 'Вход', 'ERROR' => 'Неверный email или пароль', 'EMAIL' => htmlspecialchars($email)]);
        $template->set_filenames(['header' => 'header.tpl', 'body' => 'login.tpl', 'footer' => 'footer.tpl']);
        $template->pparse('header');
        $template->pparse('body');
        $template->pparse('footer');
        exit;
    }
}

// Выход
if ($uri === '/logout') {
    session_destroy();
    header('Location: /');
    exit;
}

// ==================== РЕГИСТРАЦИЯ ====================
if ($uri === '/register' && $method === 'GET') {
    if (isset($_SESSION['user_id'])) {
        header('Location: /');
        exit;
    }
    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
    $template->assign_vars(['PAGE_TITLE' => 'Регистрация', 'ERROR' => '']);
    $template->set_filenames(['header' => 'header.tpl', 'body' => 'register.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

if ($uri === '/register' && $method === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    $errors = [];
    if (empty($username)) $errors[] = 'Имя пользователя обязательно';
    if (empty($email)) $errors[] = 'Email обязателен';
    if (empty($password)) $errors[] = 'Пароль обязателен';
    if ($password !== $password_confirm) $errors[] = 'Пароли не совпадают';

    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            $errors[] = 'Пользователь с таким email или именем уже существует';
        }
    }

    if (!empty($errors)) {
        $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
        $template->assign_vars([
            'PAGE_TITLE' => 'Регистрация',
            'ERROR' => implode('<br>', $errors),
            'USERNAME' => htmlspecialchars($username),
            'EMAIL' => htmlspecialchars($email),
        ]);
        $template->set_filenames(['header' => 'header.tpl', 'body' => 'register.tpl', 'footer' => 'footer.tpl']);
        $template->pparse('header');
        $template->pparse('body');
        $template->pparse('footer');
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, "user")');
    $stmt->execute([$username, $email, $hash]);

    $userId = $db->lastInsertId();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['user_role'] = 'user';
    header('Location: /');
    exit;
}

// ==================== ПРОФИЛЬ ====================
if ($uri === '/profile' && $method === 'GET') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');
    if (isset($_SESSION['user_id'])) {
        $template->assign_block_vars('user_logged_in', []);
        $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: /login?error=session_expired');
        exit;
    }

    $template->assign_vars([
        'PAGE_TITLE' => 'Профиль',
        'USERNAME' => $user['username'],
        'EMAIL' => $user['email'],
        'ROLE' => $user['role'],
        'REG_DATE' => $user['created_at'],
    ]);

    $stmt = $db->prepare('SELECT id, name, votes_count FROM servers WHERE owner_id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $userServers = $stmt->fetchAll();
    foreach ($userServers as $srv) {
        $template->assign_block_vars('my_servers', [
            'ID' => $srv['id'],
            'NAME' => $srv['name'],
            'VOTES' => $srv['votes_count'],
        ]);
    }

    $template->set_filenames(['header' => 'header.tpl', 'body' => 'profile.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

// ==================== ПРОСМОТР СЕРВЕРА ====================
if (preg_match('#^/server/(\d+)$#', $uri, $matches) && $method === 'GET') {
    $serverId = (int)$matches[1];

    $stmt = $db->prepare('SELECT * FROM servers WHERE id = ?');
    $stmt->execute([$serverId]);
    $server = $stmt->fetch();

    if (!$server) {
        http_response_code(404);
        echo 'Сервер не найден';
        exit;
    }

    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');

    if (isset($_SESSION['user_id'])) {
        $template->assign_block_vars('user_logged_in', []);
        $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
    } else {
        $template->assign_vars(['USERNAME' => 'Гость']);
    }

    $serverData = [
        'ID'            => $server['id'],
        'NAME'          => htmlspecialchars($server['name']),
        'SITE'          => htmlspecialchars($server['site'] ?? ''),
        'IP'            => htmlspecialchars($server['ip']),
        'PORT'          => $server['port'],
        'VERSION'       => htmlspecialchars($server['version']),
        'DESCRIPTION'   => nl2br(htmlspecialchars($server['description'] ?? '')),
        'SHORT_DESCRIPTION' => htmlspecialchars($server['short_description'] ?? ''),
        'COUNTRY'       => htmlspecialchars($server['country'] ?? 'Россия'),
        'VK'            => htmlspecialchars($server['vk'] ?? ''),
        'FB'            => htmlspecialchars($server['fb'] ?? ''),
        'GP'            => htmlspecialchars($server['gp'] ?? ''),
        'YOUTUBE'       => htmlspecialchars($server['youtube'] ?? ''),
        'BANNER'        => $server['banner'] ?? '',
        'PLAYERS_ONLINE'=> $server['players_online'],
        'MAX_PLAYERS'   => $server['max_players'],
        'VOTES'         => $server['votes_count'],
        'RATING'        => $server['rating'],
        'STATUS'        => $server['status'],
        'UPTIME'        => $server['uptime'] ?? 0,
        'UPDATED_AT'    => date('d.m.Y H:i', strtotime($server['updated_at'])),
        'OWNER_ID'      => $server['owner_id'],
        'SCREENSHOTS'   => json_decode($server['screenshots'] ?? '[]', true),
        'SERVER_ICON'   => substr($server['name'], 0, 1),
        'AVG_ONLINE'    => rand(0, 100),
        'TODAY_VOTES'   => rand(0, 10),
    ];
    $template->assign_vars($serverData);

    // Скриншоты
    if (!empty($serverData['SCREENSHOTS'])) {
        foreach ($serverData['SCREENSHOTS'] as $url) {
            $template->assign_block_vars('screenshots', ['URL' => $url]);
        }
    }

    // Теги (заглушка)
    $tags = ['Выживание', 'PVP', 'Приват', 'Кланы', 'Экономика'];
    foreach ($tags as $tag) {
        $template->assign_block_vars('tags', ['NAME' => $tag]);
    }

    // Владелец
    $stmt = $db->prepare('SELECT username, email, created_at FROM users WHERE id = ?');
    $stmt->execute([$server['owner_id']]);
    $owner = $stmt->fetch();
    if ($owner) {
        $template->assign_vars([
            'OWNER_USERNAME' => htmlspecialchars($owner['username']),
            'OWNER_EMAIL'    => htmlspecialchars($owner['email']),
            'OWNER_REG_DATE' => date('d.m.Y', strtotime($owner['created_at'])),
        ]);
    }

    // Последние голоса (заглушка)
    $recentVotes = [
        ['USERNAME' => 'Игрок1', 'DATE' => '10 часов назад'],
        ['USERNAME' => 'Игрок2', 'DATE' => '5 часов назад'],
        ['USERNAME' => 'Игрок3', 'DATE' => '2 часа назад'],
    ];
    foreach ($recentVotes as $vote) {
        $template->assign_block_vars('recent_votes', $vote);
    }

    // Отзывы
    $stmt = $db->prepare('
        SELECT r.*, u.username 
        FROM reviews r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.server_id = ? 
        ORDER BY r.created_at DESC 
        LIMIT 5
    ');
    $stmt->execute([$serverId]);
    $reviews = $stmt->fetchAll();
    foreach ($reviews as $review) {
        $template->assign_block_vars('reviews', [
            'USERNAME' => htmlspecialchars($review['username']),
            'RATING'   => $review['rating'],
            'COMMENT'  => nl2br(htmlspecialchars($review['comment'] ?? '')),
            'DATE'     => date('d.m.Y H:i', strtotime($review['created_at'])),
        ]);
    }

    // Проверка голосования
    $canVote = false;
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM votes WHERE server_id = ? AND user_id = ? AND vote_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute([$serverId, $_SESSION['user_id']]);
        if ($stmt->fetchColumn() == 0) {
            $canVote = true;
        }
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM votes WHERE server_id = ? AND ip_address = ? AND vote_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute([$serverId, $ip]);
        if ($stmt->fetchColumn() == 0) {
            $canVote = true;
        }
    }
    $template->assign_vars(['CAN_VOTE' => $canVote]);

    $template->assign_vars(['PAGE_TITLE' => $server['name']]);

    $template->set_filenames(['header' => 'header.tpl', 'body' => 'server.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

// ==================== ГОЛОСОВАНИЕ ====================
if (preg_match('#^/vote/(\d+)$#', $uri, $matches) && $method === 'POST') {
    header('Content-Type: application/json');
    $serverId = (int)$matches[1];

    $stmt = $db->prepare('SELECT id FROM servers WHERE id = ?');
    $stmt->execute([$serverId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Сервер не найден']);
        exit;
    }

    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'];

    $canVote = true;
    if ($userId) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM votes WHERE server_id = ? AND user_id = ? AND vote_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute([$serverId, $userId]);
        if ($stmt->fetchColumn() > 0) {
            $canVote = false;
        }
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM votes WHERE server_id = ? AND ip_address = ? AND vote_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
        $stmt->execute([$serverId, $ip]);
        if ($stmt->fetchColumn() > 0) {
            $canVote = false;
        }
    }

    if (!$canVote) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Вы уже голосовали за последние 24 часа']);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO votes (server_id, user_id, ip_address) VALUES (?, ?, ?)');
    $stmt->execute([$serverId, $userId, $ip]);

    $stmt = $db->prepare('UPDATE servers SET votes_count = votes_count + 1 WHERE id = ?');
    $stmt->execute([$serverId]);

    echo json_encode(['success' => true, 'message' => 'Голос засчитан!']);
    exit;
}

// ==================== ДОБАВЛЕНИЕ ОТЗЫВА ====================
if (preg_match('#^/review/(\d+)$#', $uri, $matches) && $method === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo 'Доступ запрещён';
        exit;
    }

    $serverId = (int)$matches[1];
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5 || empty($comment)) {
        header('Location: /server/' . $serverId . '?error=invalid_review');
        exit;
    }

    $stmt = $db->prepare('INSERT INTO reviews (server_id, user_id, rating, comment) VALUES (?, ?, ?, ?)');
    $stmt->execute([$serverId, $_SESSION['user_id'], $rating, $comment]);

    header('Location: /server/' . $serverId);
    exit;
}

// ==================== СТРАНИЦА "О НАС" ====================
if ($uri === '/about' && $method === 'GET') {
    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');

    if (isset($_SESSION['user_id'])) {
        $template->assign_block_vars('user_logged_in', []);
        $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
    } else {
        $template->assign_vars(['USERNAME' => 'Гость']);
    }

    $template->assign_vars(['PAGE_TITLE' => 'О нас']);

    $template->set_filenames(['header' => 'header.tpl', 'body' => 'about.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

// ==================== ПРОСМОТР ПРОЕКТА ====================
if (preg_match('#^/project/(\d+)$#', $uri, $matches) && $method === 'GET') {
    $projectId = (int)$matches[1];

    $project = $projectRepo->find($projectId);
    if (!$project) {
        http_response_code(404);
        echo 'Проект не найден';
        exit;
    }

    // Получаем серверы проекта
    $stmt = $db->prepare('SELECT * FROM servers WHERE project_id = ? ORDER BY votes_count DESC');
    $stmt->execute([$projectId]);
    $servers = $stmt->fetchAll();

    $template = new Template(__DIR__ . '/templates/', __DIR__ . '/cache/');

    if (isset($_SESSION['user_id'])) {
        $template->assign_block_vars('user_logged_in', []);
        $template->assign_vars(['USERNAME' => $_SESSION['username'] ?? 'Пользователь']);
    } else {
        $template->assign_vars(['USERNAME' => 'Гость']);
    }

    // Информация о владельце
    $owner = null;
    if ($project->getOwnerId()) {
        $stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$project->getOwnerId()]);
        $owner = $stmt->fetch();
    }

    $template->assign_vars([
        'PAGE_TITLE'          => $project->getName(),
        'PROJECT_ID'          => $projectId,
        'PROJECT_NAME'        => htmlspecialchars($project->getName()),
        'PROJECT_DESCRIPTION' => nl2br(htmlspecialchars($project->getDescription() ?? '')),
        'PROJECT_BANNER'      => $project->getBanner() ?? '',
        'PROJECT_SITE'        => htmlspecialchars($project->getSite() ?? ''),
        'PROJECT_CREATED'     => $project->getCreatedAt()->format('d.m.Y'),
        'PROJECT_OWNER_NAME'  => $owner ? htmlspecialchars($owner['username']) : 'Неизвестен',
    ]);

    $rowNumber = 1;
    foreach ($servers as $server) {
        $template->assign_block_vars('project_servers', [
            'ROW_NUMBER'      => $rowNumber++,
            'ID'              => $server['id'],
            'NAME'            => htmlspecialchars($server['name']),
            'IP'              => htmlspecialchars($server['ip']),
            'PORT'            => $server['port'],
            'VERSION'         => htmlspecialchars($server['version']),
            'PLAYERS_ONLINE'  => $server['players_online'],
            'MAX_PLAYERS'     => $server['max_players'],
            'STATUS'          => $server['status'],
            'VOTES'           => $server['votes_count'],
            'RATING'          => $server['rating'],
            'BANNER'          => $server['banner'] ?? '',
        ]);
    }

    $template->set_filenames(['header' => 'header.tpl', 'body' => 'project.tpl', 'footer' => 'footer.tpl']);
    $template->pparse('header');
    $template->pparse('body');
    $template->pparse('footer');
    exit;
}

// 404
http_response_code(404);
echo '404 Not Found';