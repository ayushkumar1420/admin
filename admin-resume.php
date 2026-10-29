<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
    'use_only_cookies' => true,
    'use_strict_mode' => true,
]);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'applications.php';

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
$config = is_file($configPath) ? require $configPath : [];
$sessionEmail = (string) ($_SESSION['career_admin_email'] ?? '');
if (!is_array($config) || $sessionEmail === '' || empty($config['admin_email'])
    || !hash_equals((string) $config['admin_email'], $sessionEmail)) {
    http_response_code(401);
    header('Cache-Control: no-store');
    exit('Authentication required.');
}

$applicationId = (string) ($_GET['id'] ?? '');
if (!preg_match('/^APP-\d{8}-[A-F0-9]{32}$/', $applicationId)) {
    http_response_code(404);
    exit('Resume not found.');
}
$application = withApplications(static function (array &$applications) use ($applicationId): ?array {
    foreach ($applications as $item) {
        if (($item['id'] ?? '') === $applicationId) {
            return $item;
        }
    }
    return null;
});
if ($application === null || !isset($application['resume_reference'])
    || !preg_match('/^resume-[a-f0-9]{32}\.pdf$/', (string) $application['resume_reference'])) {
    http_response_code(404);
    exit('Resume not found.');
}
$directory = applicationStorageDirectory();
$path = $directory . DIRECTORY_SEPARATOR . $application['resume_reference'];
$realDirectory = realpath($directory);
$realPath = realpath($path);
if ($realDirectory === false || $realPath === false
    || !str_starts_with($realPath, $realDirectory . DIRECTORY_SEPARATOR) || !is_file($realPath)) {
    http_response_code(404);
    exit('Resume not found.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $applicationId . '.pdf"');
header('Content-Length: ' . (string) filesize($realPath));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
readfile($realPath);
