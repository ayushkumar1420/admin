<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'applications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$isLocalOrigin = static fn(string $value): bool => (bool) preg_match('~^https?://(?:localhost|127\.0\.0\.1)(?::\d{1,5})?$~i', $value);
if ($origin !== '') {
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    if (!$isLocalOrigin($origin) && $origin !== $scheme . '://' . $host) {
        http_response_code(403);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($origin !== '' && !$isLocalOrigin($origin)) {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        if ($origin !== $scheme . '://' . $host) {
            http_response_code(403);
            exit;
        }
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    http_response_code(204);
    exit;
}

function verifyCodeApiError(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    verifyCodeApiError('Method not allowed.', 405);
}

try {
    $email = applicationInput('email', 'Email address', 254, true);
    $challengeId = applicationInput('challenge_id', 'Verification request', 64, true);
    $code = applicationInput('code', 'Verification code', 6, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{48}$/', $challengeId)
        || !preg_match('/^\d{6}$/', $code)) {
        throw new InvalidArgumentException('Enter the six-digit code sent to your email.');
    }
    $token = verifyEmailVerificationCode($challengeId, $email, $code);
    if ($token === null) {
        verifyCodeApiError('That code is incorrect or expired. Check it and try again, or request a new code.', 422);
    }
    echo json_encode(['success' => true, 'verification_token' => $token, 'message' => 'Email verified.'], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    verifyCodeApiError($error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Career email code verification failed: ' . $error->getMessage());
    verifyCodeApiError('Email verification is temporarily unavailable. Please try again.', 503);
}
