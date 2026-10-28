<?php
require_once __DIR__ . '/library/includes/config.php';
require_once INCLUDES_DIR . 'db.php';
require_once INCLUDES_DIR . 'functions.php';

if (!isset($_GET['code'])) {
    http_response_code(404);
    die("File not found");
}

$file = getFileByShortCode($_GET['code']);
if (!$file) {
    http_response_code(404);
    die("File not found");
}

incrementFileViews($file['id']);

$filePath = UPLOAD_DIR . $file['stored_name'];
if (!file_exists($filePath)) {
    deleteFile($file['id']);
    http_response_code(404);
    die("File not found");
}

header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . $file['size']);
header('Content-Disposition: inline; filename="' . basename($file['original_name']) . '"');
readfile($filePath);
exit;
?>