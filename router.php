<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';
for ($decodePass = 0; $decodePass < 5; $decodePass++) {
    $decodedPath = rawurldecode($requestPath);
    if ($decodedPath === $requestPath) {
        break;
    }
    $requestPath = $decodedPath;
}
if (rawurldecode($requestPath) !== $requestPath) {
    http_response_code(404);
    exit;
}
$requestPath = str_replace('\\', '/', $requestPath);
$pathSegments = [];
foreach (explode('/', $requestPath) as $segment) {
    if ($segment === '' || $segment === '.') {
        continue;
    }
    if ($segment === '..') {
        array_pop($pathSegments);
        continue;
    }
    $pathSegments[] = $segment;
}
$requestPath = '/' . strtolower(implode('/', $pathSegments));
$adminPrefix = '/' . strtolower(basename(__DIR__)) . '/';
$dataPath = $adminPrefix . 'data';
$privatePath = $adminPrefix . 'private';

if ($requestPath === $dataPath || strpos($requestPath, $dataPath . '/') === 0
    || $requestPath === $privatePath || strpos($requestPath, $privatePath . '/') === 0
    || $requestPath === $adminPrefix . 'setup.php'
    || $requestPath === $adminPrefix . '.htaccess'
    || strpos($requestPath, $adminPrefix . 'private/') === 0
    || strpos($requestPath, $adminPrefix . 'data/') === 0) {
    http_response_code(404);
    exit;
}

return false;
