<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'applications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$localOrigins = ['http://localhost:5500', 'http://127.0.0.1:5500'];
if ($origin !== '') {
    if (in_array($origin, $localOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    } else {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        if ($origin !== $scheme . '://' . $host) {
            http_response_code(403);
            exit;
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($origin !== '' && !in_array($origin, $localOrigins, true)) {
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

function verifyEmailOtpApiError(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    verifyEmailOtpApiError('Method not allowed.', 405);
}

try {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        throw new InvalidArgumentException('The request must be no larger than 4 KB.');
    }
    $email = applicationInput('email', 'Email address', 254, true);
    $challengeId = applicationInput('challenge_id', 'Verification request', 48, true);
    $otp = applicationInput('otp', 'Verification code', 6, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{48}$/', $challengeId)
        || !preg_match('/^\d{6}$/', $otp)) {
        throw new InvalidArgumentException('Enter the six-digit code sent to your email.');
    }

    $token = verifyEmailOtp($challengeId, $email, $otp);
    if ($token === null) {
        verifyEmailOtpApiError('The code is incorrect, expired, or has reached its attempt limit. Request a new code and try again.', 422);
    }
    echo json_encode([
        'success' => true,
        'verification_token' => $token,
        'message' => 'Email verified. Submit your application using this verification token.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    verifyEmailOtpApiError($error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Career email OTP verification failed: ' . $error->getMessage());
    verifyEmailOtpApiError('Email verification is temporarily unavailable. Please try again.', 503);
}
