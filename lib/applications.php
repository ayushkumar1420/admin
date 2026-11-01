<?php
declare(strict_types=1);

const CAREER_APPLICATIONS_FILE = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'applications.json';
const CAREER_JOBS_FILE = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs.json';
const CAREER_APPLICATION_MAX_RESUME_SIZE = 5242880;

function applicationStorageDirectory(): string
{
    $configured = getenv('CAREER_PRIVATE_STORAGE_DIR');
    $directory = $configured !== false && trim($configured) !== ''
        ? trim($configured)
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'application-resumes';
    $isAbsolute = DIRECTORY_SEPARATOR === '\\'
        ? (bool) preg_match('/^(?:[A-Za-z]:\\\\|\\\\\\\\)/', $directory)
        : str_starts_with($directory, '/');
    if (!$isAbsolute) {
        throw new RuntimeException('Private application storage must use an absolute path.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private application storage could not be created.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Private application storage is not writable.');
    }
    @chmod($directory, 0700);
    return rtrim($directory, DIRECTORY_SEPARATOR);
}

function applicationWriteJsonAtomically(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $temporary = tempnam(dirname($path), '.career-json-');
    if ($temporary === false) {
        throw new RuntimeException('Application data could not be staged for saving.');
    }
    try {
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Application data could not be staged for saving.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Application data could not be saved.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function withApplications(callable $callback, bool $write = false)
{
    $directory = dirname(CAREER_APPLICATIONS_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Application data storage could not be created.');
    }
    $lock = fopen($directory . DIRECTORY_SEPARATOR . 'applications.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Application data is temporarily unavailable.');
    }
    try {
        if (!is_file(CAREER_APPLICATIONS_FILE)) {
            applicationWriteJsonAtomically(CAREER_APPLICATIONS_FILE, ['applications' => []]);
        }
        $contents = file_get_contents(CAREER_APPLICATIONS_FILE);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['applications']) || !is_array($decoded['applications'])) {
            throw new RuntimeException('Application data is invalid.');
        }
        $applications = array_values(array_filter($decoded['applications'], 'is_array'));
        $result = $callback($applications);
        if ($write) {
            applicationWriteJsonAtomically(CAREER_APPLICATIONS_FILE, ['applications' => $applications]);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function publishedJob(string $jobId): array
{
    $lock = fopen(dirname(CAREER_JOBS_FILE) . DIRECTORY_SEPARATOR . 'jobs.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_SH)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Job listings are temporarily unavailable.');
    }
    try {
        $contents = file_get_contents(CAREER_JOBS_FILE);
        $decoded = $contents === false ? null : json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
            throw new RuntimeException('Job listings are temporarily unavailable.');
        }
        foreach ($decoded['jobs'] as $job) {
            if (is_array($job) && ($job['id'] ?? null) === $jobId && ($job['status'] ?? null) === 'Published') {
                return $job;
            }
        }
        throw new InvalidArgumentException('This job is unavailable or no longer accepting applications.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function applicationInput(string $key, string $label, int $maxLength, bool $required = false): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        throw new InvalidArgumentException($label . ' must be plain text.');
    }
    $value = trim($value);
    if ($required && $value === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    if (strlen($value) > $maxLength * 4) {
        throw new InvalidArgumentException($label . ' must be ' . $maxLength . ' characters or fewer.');
    }
    if (preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException($label . ' must use valid UTF-8 text.');
    }
    $characterCount = preg_match_all('/./us', $value, $matches);
    if ($characterCount === false || $characterCount > $maxLength) {
        throw new InvalidArgumentException($label . ' must be ' . $maxLength . ' characters or fewer.');
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException($label . ' contains invalid characters.');
    }
    return $value;
}

function applicationUrl(string $value, string $label): string
{
    if ($value === '') {
        return '';
    }
    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException($label . ' must be a valid URL.');
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true) || parse_url($value, PHP_URL_USER) !== null) {
        throw new InvalidArgumentException($label . ' must use an HTTP or HTTPS URL.');
    }
    return $value;
}

function validateResumeUpload(): array
{
    if (!extension_loaded('fileinfo') || !class_exists('finfo')) {
        throw new RuntimeException('Server-side resume MIME validation is unavailable.');
    }

    $file = $_FILES['resume'] ?? null;
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        throw new InvalidArgumentException('A PDF resume is required.');
    }
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new InvalidArgumentException('The resume must be 5 MB or smaller.');
        }
        throw new InvalidArgumentException('The resume upload did not complete successfully.');
    }
    $temporaryPath = $file['tmp_name'] ?? '';
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('The resume upload did not complete successfully.');
    }
    $size = filesize($temporaryPath);
    if ($size === false || $size < 10 || $size > CAREER_APPLICATION_MAX_RESUME_SIZE) {
        throw new InvalidArgumentException('The resume must be a PDF no larger than 5 MB.');
    }
    $handle = fopen($temporaryPath, 'rb');
    if ($handle === false) {
        throw new InvalidArgumentException('The uploaded resume could not be read.');
    }
    $signature = fread($handle, 5);
    $tailLength = min($size, 4096);
    if (fseek($handle, -$tailLength, SEEK_END) !== 0) {
        fclose($handle);
        throw new InvalidArgumentException('The uploaded resume is not a valid PDF.');
    }
    $tail = stream_get_contents($handle);
    fclose($handle);
    if ($signature !== '%PDF-' || !is_string($tail) || strpos($tail, '%%EOF') === false) {
        throw new InvalidArgumentException('The uploaded resume is not a valid PDF.');
    }
    $contents = file_get_contents($temporaryPath);
    if ($contents === false || preg_match('/<\?(?:php|=)/i', $contents)) {
        throw new InvalidArgumentException('Executable files are not accepted as resumes.');
    }
    if (!preg_match('/\b\d+\s+\d+\s+obj\b/', $contents)
        || !preg_match('/\btrailer\b|\/Type\s*\/Catalog|\/Root\s+\d+\s+\d+\s+R/', $contents)) {
        throw new InvalidArgumentException('The uploaded resume is not a valid PDF.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporaryPath);
    if (!in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        throw new InvalidArgumentException('The uploaded resume must be a PDF file.');
    }
    return ['temporary_path' => $temporaryPath, 'size' => $size];
}

function applicationHrEmail(): string
{
    $email = getenv('CAREER_HR_EMAIL');
    $email = $email === false || trim($email) === '' ? 'ayushmaurya496@gmail.com' : trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The HR notification recipient is not configured correctly.');
    }
    return $email;
}

function localEmailLogMode(): bool
{
    $mode = getenv('CAREER_EMAIL_MODE');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $mode === 'log'
        && PHP_SAPI === 'cli-server'
        && (bool) preg_match('/^(localhost|127\.0\.0\.1)(?::\d+)?$/', $host);
}

function sendCareerEmail(string $recipient, string $subject, string $body, array $localLogContext = []): bool
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strpbrk($subject . $recipient, "\r\n") !== false) {
        throw new RuntimeException('The email recipient or subject is invalid.');
    }
    if (localEmailLogMode()) {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'email-attempts.jsonl';
        $entry = json_encode(array_merge([
            'attempted_at' => gmdate('c'),
            'delivered' => false,
            'mode' => 'development-log-only',
        ], $localLogContext), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('The local email attempt could not be recorded.');
        }
        @chmod($path, 0600);
        return false;
    }

    if (!function_exists('mail')) {
        throw new RuntimeException('PHP mail delivery is not available on this server.');
    }

    $fromEmail = getenv('CAREER_MAIL_FROM_ADDRESS');
    $fromName = (string) (getenv('CAREER_MAIL_FROM_NAME') ?: 'DeepshiTech Careers');
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $additionalParameters = '';
    if ($fromEmail !== false && trim($fromEmail) !== '') {
        $fromEmail = trim($fromEmail);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strpbrk($fromEmail . $fromName, "\r\n") !== false) {
            throw new RuntimeException('PHP mail sender details are not configured correctly.');
        }
        $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers[] = 'From: ' . $encodedFromName . ' <' . $fromEmail . '>';
        $additionalParameters = '-f' . $fromEmail;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $accepted = $additionalParameters === ''
        ? mail($recipient, $encodedSubject, $body, implode("\r\n", $headers))
        : mail($recipient, $encodedSubject, $body, implode("\r\n", $headers), $additionalParameters);
    if (!$accepted) {
        throw new RuntimeException('The server did not accept the email for delivery.');
    }
    return true;
}

function sendApplicationNotification(array $application): void
{
    $body = implode("\r\n", [
        'A new application has been submitted.',
        '',
        'Application ID: ' . $application['id'],
        'Job: ' . $application['job_title'],
        'Applicant: ' . $application['full_name'],
        'Email: ' . $application['email'],
        'Phone: ' . $application['phone'],
        'Submitted: ' . $application['submitted_at'],
        '',
        'Sign in to the Career Admin dashboard to review the application.',
    ]);
    sendCareerEmail(applicationHrEmail(), 'New job application: ' . (string) $application['job_title'], $body, [
        'application_id' => $application['id'],
    ]);
}

function recordApplicationNotification(string $applicationId, string $status, string $error = ''): void
{
    withApplications(static function (&$applications) use ($applicationId, $status, $error): void {
        foreach ($applications as &$application) {
            if (($application['id'] ?? '') === $applicationId) {
                $application['notification_status'] = $status;
                $application['notification_attempted_at'] = gmdate('c');
                if ($error !== '') {
                    $application['notification_error'] = $error;
                } else {
                    unset($application['notification_error']);
                }
                return;
            }
        }
        throw new RuntimeException('The application could not be found.');
    }, true);
}

function retryApplicationNotification(string $applicationId): string
{
    $application = withApplications(static function (array &$applications) use ($applicationId): array {
        foreach ($applications as $item) {
            if (($item['id'] ?? '') === $applicationId) {
                return $item;
            }
        }
        throw new RuntimeException('The application could not be found.');
    });
    try {
        sendApplicationNotification($application);
        $status = localEmailLogMode() ? 'Pending' : 'Sent';
        recordApplicationNotification($applicationId, $status);
        return $status;
    } catch (Throwable $error) {
        error_log('Career application notification failed for ' . $applicationId . ': ' . $error->getMessage());
        recordApplicationNotification($applicationId, 'Failed', localEmailLogMode()
            ? 'Development notification logging failed; no email was sent.'
            : 'Email could not be sent. Check PHP mail support and server logs.');
        return 'Failed';
    }
}
