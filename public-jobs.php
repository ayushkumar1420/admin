<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isLocalOrigin = static fn(string $value): bool => (bool) preg_match('~^https?://(?:localhost|127\.0\.0\.1)(?::\d{1,5})?$~i', $value);
if ($requestOrigin !== '' && $isLocalOrigin($requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!$isLocalOrigin($requestOrigin)) {
        http_response_code(403);
        exit;
    }
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept');
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$dataFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs.json';
$lockFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs.lock';

if (!is_file($dataFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Job data has not been initialized.']);
    exit;
}

$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_SH)) {
    http_response_code(503);
    echo json_encode(['error' => 'Job listings are temporarily unavailable.']);
    exit;
}

try {
    $decoded = json_decode((string) file_get_contents($dataFile), true);
    if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
        throw new RuntimeException('Invalid job data.');
    }

    $published = array_values(array_filter($decoded['jobs'], static function ($job): bool {
        return is_array($job) && ($job['status'] ?? '') === 'Published';
    }));
    foreach ($published as &$job) {
        unset($job['applications']);
    }
    unset($job);
    echo json_encode(['jobs' => $published], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Career public listing failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Job listings are temporarily unavailable.']);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
