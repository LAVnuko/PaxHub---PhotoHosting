<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

global $lang;

$page = $_GET['page'] ?? 'about';

// Определяем заголовок и контент в зависимости от параметра
switch ($page) {
    case 'privacy':
        $pageTitle = $lang['PRIVACY_TITLE'] . ' | ' . SITE_NAME;
        $content = $lang['PRIVACY_CONTENT'];
        $pageHeading = $lang['PRIVACY_TITLE'];
        break;
    case 'terms':
        $pageTitle = $lang['TERMS_TITLE'] . ' | ' . SITE_NAME;
        $content = $lang['TERMS_CONTENT'];
        $pageHeading = $lang['TERMS_TITLE'];
        break;
    case 'about':
    default:
        $page = 'about';
        $pageTitle = $lang['ABOUT_TITLE'] . ' | ' . SITE_NAME;
        $content = $lang['ABOUT_CONTENT'];
        $pageHeading = $lang['ABOUT_TITLE'];
        break;
}

$template_data = [
    'PAGE_TITLE' => $pageTitle,
    'PAGE_CONTENT' => $content,
    'ACTIVE_PAGE' => $page,
    'LANG_ACTIVE_PAGE_TITLE' => $pageHeading
];

renderTemplateImproved('info', $template_data);