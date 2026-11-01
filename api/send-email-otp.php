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

function emailOtpApiError(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    emailOtpApiError('Method not allowed.', 405);
}

try {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        throw new InvalidArgumentException('The request must be no larger than 4 KB.');
    }
    $email = applicationInput('email', 'Email address', 254, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    $clientAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!reserveEmailOtpSend($email, $clientAddress)) {
        emailOtpApiError('Please wait 60 seconds before requesting another code. The hourly request limit may also have been reached.', 429);
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $challengeId = bin2hex(random_bytes(24));
    storeEmailOtp($challengeId, $email, $otp);
    $body = implode("\r\n", [
        'Your DeepshiTech Careers verification code is: ' . $otp,
        '',
        'This code expires in 5 minutes. You can use it once to verify your email address.',
        'If you did not request this code, you can ignore this email.',
    ]);

    try {
        sendCareerEmail($email, 'Your DeepshiTech Careers verification code', $body, [
            'type' => 'email-otp',
            'email_hash' => hash('sha256', strtolower(trim($email))),
        ]);
    } catch (Throwable $error) {
        removeEmailOtp($challengeId);
        throw $error;
    }

    $response = [
        'success' => true,
        'challenge_id' => $challengeId,
        'message' => 'If this address can receive email, a verification code has been sent.',
    ];
    if (localEmailLogMode()) {
        $response['development_code'] = $otp;
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    emailOtpApiError($error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Career email OTP request failed: ' . $error->getMessage());
    emailOtpApiError('A verification email could not be sent. Please try again later.', 503);
}
