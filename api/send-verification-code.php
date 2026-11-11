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

function verificationApiError(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    verificationApiError('Method not allowed.', 405);
}

try {
    $email = applicationInput('email', 'Email address', 254, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!reserveEmailVerificationSend($email, $address)) {
        verificationApiError('A code was sent recently or the hourly limit was reached. Please wait before requesting another code.', 429);
    }

    $code = (string) random_int(100000, 999999);
    $challengeId = bin2hex(random_bytes(24));
    storeEmailVerificationChallenge($challengeId, $email, $code);
    $body = implode("\r\n", [
        'Your DeepshiTech Careers verification code is: ' . $code,
        '',
        'This code expires in 10 minutes. Enter it on the application form to verify your email address.',
        'If you did not request this code, you can ignore this email.',
    ]);
    sendCareerEmail($email, 'Verify your email for DeepshiTech Careers', $body);

    echo json_encode([
        'success' => true,
        'challenge_id' => $challengeId,
        'message' => 'If this address can receive email, a verification code has been sent.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    verificationApiError($error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Career email verification request failed: ' . $error->getMessage());
    verificationApiError('A verification email could not be sent. Please try again later.', 503);
}
