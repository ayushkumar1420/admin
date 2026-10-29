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
            echo json_encode(['error' => 'This origin is not allowed.']);
            exit;
        }
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin !== '' && !in_array($origin, $localOrigins, true)) {
        http_response_code(403);
        exit;
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST, OPTIONS');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

function applicationApiError(string $message, int $status): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 6 * 1024 * 1024) {
        throw new InvalidArgumentException('The complete request must be no larger than 6 MB.');
    }
    $jobId = applicationInput('job_id', 'Job ID', 128, true);
    $job = publishedJob($jobId);
    $name = applicationInput('full_name', 'Full name', 120, true);
    $email = applicationInput('email', 'Email address', 254, true);
    $phone = applicationInput('phone', 'Phone number', 30, true);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (!preg_match('/^[+0-9().\-\s]{7,30}$/', $phone) || strlen((string) preg_replace('/\D/', '', $phone)) < 7
        || strlen((string) preg_replace('/\D/', '', $phone)) > 15) {
        throw new InvalidArgumentException('Enter a valid phone number.');
    }
    if (applicationInput('recruitment_consent', 'Recruitment consent', 8, true) !== '1') {
        throw new InvalidArgumentException('Recruitment consent is required.');
    }

    $resume = validateResumeUpload();
    $application = [
        'id' => 'APP-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(16))),
        'job_id' => $jobId,
        'job_title' => (string) ($job['title'] ?? ''),
        'full_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'location' => applicationInput('location', 'Location', 120),
        'qualification' => applicationInput('qualification', 'Qualification', 160),
        'college' => applicationInput('college', 'College', 180),
        'graduation_year' => applicationInput('graduation_year', 'Graduation year', 4),
        'linkedin_url' => applicationUrl(applicationInput('linkedin_url', 'LinkedIn URL', 500), 'LinkedIn URL'),
        'portfolio_url' => applicationUrl(applicationInput('portfolio_url', 'Portfolio/GitHub URL', 500), 'Portfolio/GitHub URL'),
        'cover_letter' => applicationInput('cover_letter', 'Cover letter', 5000),
        'resume_reference' => 'resume-' . strtolower(bin2hex(random_bytes(16))) . '.pdf',
        'consent' => true,
        'consent_at' => gmdate('c'),
        'submitted_at' => gmdate('c'),
        'status' => 'New',
        'notification_status' => 'Pending',
        'notification_attempted_at' => null,
    ];
    if ($application['graduation_year'] !== ''
        && (!preg_match('/^\d{4}$/', $application['graduation_year'])
            || (int) $application['graduation_year'] < 1950
            || (int) $application['graduation_year'] > (int) gmdate('Y') + 10)) {
        throw new InvalidArgumentException('Enter a valid graduation year.');
    }

    $storageDirectory = applicationStorageDirectory();
    $resumePath = $storageDirectory . DIRECTORY_SEPARATOR . $application['resume_reference'];
    if (!move_uploaded_file($resume['temporary_path'], $resumePath)) {
        throw new RuntimeException('The uploaded resume could not be stored securely.');
    }
    @chmod($resumePath, 0600);
    try {
        withApplications(static function (&$applications) use ($application): void {
            $applications[] = $application;
        }, true);
    } catch (Throwable $error) {
        @unlink($resumePath);
        throw $error;
    }

    $notificationStatus = retryApplicationNotification($application['id']);
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'application_id' => $application['id'],
        'notification_status' => $notificationStatus,
        'message' => 'Your application has been received.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    applicationApiError($error->getMessage(), 422);
} catch (Throwable $error) {
    error_log('Career application submission failed: ' . $error->getMessage());
    applicationApiError('Your application could not be submitted. Please try again later.', 500);
}
