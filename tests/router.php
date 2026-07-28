<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

ob_start();
require __DIR__ . '/../index.php';
$output = ob_get_clean();

$output = preg_replace('/<div style="border:1px solid #990000.*?<\/div>\s*/s', '', $output);

$pos = strrpos($output, '{"status"');
if ($pos !== false) {
    $output = substr($output, $pos);
}

echo $output;
