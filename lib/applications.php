<?php
declare(strict_types=1);

const CAREER_APPLICATIONS_FILE = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'applications.json';
const CAREER_JOBS_FILE = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs.json';
const CAREER_EMAIL_OTPS_FILE = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'email-otps.json';
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

function withEmailOtpState(callable $callback)
{
    $directory = dirname(CAREER_EMAIL_OTPS_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Email verification storage could not be created.');
    }
    @chmod($directory, 0700);
    $lock = fopen(CAREER_EMAIL_OTPS_FILE . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Email verification storage is temporarily unavailable.');
    }
    try {
        if (!is_file(CAREER_EMAIL_OTPS_FILE)) {
            applicationWriteJsonAtomically(CAREER_EMAIL_OTPS_FILE, ['challenges' => [], 'send_attempts' => []]);
        }
        $contents = file_get_contents(CAREER_EMAIL_OTPS_FILE);
        $state = $contents === false ? null : json_decode($contents, true);
        if (!is_array($state) || !isset($state['challenges'], $state['send_attempts'])
            || !is_array($state['challenges']) || !is_array($state['send_attempts'])) {
            throw new RuntimeException('Email verification storage is invalid.');
        }

        $now = time();
        $state['challenges'] = array_values(array_filter($state['challenges'], static function ($challenge) use ($now): bool {
            return is_array($challenge) && (int) ($challenge['expires_at'] ?? 0) > $now;
        }));
        $state['send_attempts'] = array_values(array_filter($state['send_attempts'], static function ($attempt) use ($now): bool {
            return is_array($attempt) && (int) ($attempt['created_at'] ?? 0) > $now - 3600;
        }));

        $result = $callback($state);
        applicationWriteJsonAtomically(CAREER_EMAIL_OTPS_FILE, $state);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function reserveEmailOtpSend(string $email, string $clientAddress): bool
{
    $emailHash = hash('sha256', strtolower(trim($email)));
    $clientHash = hash('sha256', $clientAddress);
    $now = time();

    return withEmailOtpState(static function (&$state) use ($emailHash, $clientHash, $now): bool {
        $emailAttempts = array_values(array_filter($state['send_attempts'], static function ($attempt) use ($emailHash): bool {
            return ($attempt['email_hash'] ?? '') === $emailHash;
        }));
        $clientAttempts = array_values(array_filter($state['send_attempts'], static function ($attempt) use ($clientHash): bool {
            return ($attempt['client_hash'] ?? '') === $clientHash;
        }));
        $lastEmailAttempt = 0;
        foreach ($emailAttempts as $attempt) {
            $lastEmailAttempt = max($lastEmailAttempt, (int) ($attempt['created_at'] ?? 0));
        }
        if ($lastEmailAttempt > $now - 60 || count($emailAttempts) >= 5 || count($clientAttempts) >= 10) {
            return false;
        }

        $state['send_attempts'][] = [
            'email_hash' => $emailHash,
            'client_hash' => $clientHash,
            'created_at' => $now,
        ];
        return true;
    });
}

function storeEmailOtp(string $challengeId, string $email, string $otp): void
{
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $emailHash = hash('sha256', strtolower(trim($email)));
    withEmailOtpState(static function (&$state) use ($challengeId, $emailHash, $otpHash): void {
        $state['challenges'] = array_values(array_filter($state['challenges'], static function ($challenge) use ($emailHash): bool {
            return ($challenge['email_hash'] ?? '') !== $emailHash;
        }));
        if (count($state['challenges']) >= 1000) {
            throw new RuntimeException('Email verification storage is full.');
        }
        $state['challenges'][] = [
            'challenge_id' => $challengeId,
            'email_hash' => $emailHash,
            'otp_hash' => $otpHash,
            'attempts' => 0,
            'expires_at' => time() + 300,
            'verification_token_hash' => '',
        ];
    });
}

function removeEmailOtp(string $challengeId): void
{
    withEmailOtpState(static function (&$state) use ($challengeId): void {
        $state['challenges'] = array_values(array_filter($state['challenges'], static function ($challenge) use ($challengeId): bool {
            return ($challenge['challenge_id'] ?? '') !== $challengeId;
        }));
    });
}

function verifyEmailOtp(string $challengeId, string $email, string $otp): ?string
{
    $emailHash = hash('sha256', strtolower(trim($email)));
    return withEmailOtpState(static function (&$state) use ($challengeId, $emailHash, $otp): ?string {
        foreach ($state['challenges'] as &$challenge) {
            if (($challenge['challenge_id'] ?? '') !== $challengeId
                || !hash_equals((string) ($challenge['email_hash'] ?? ''), $emailHash)
                || (int) ($challenge['expires_at'] ?? 0) <= time()
                || (int) ($challenge['attempts'] ?? 0) >= 5
                || ($challenge['verification_token_hash'] ?? '') !== '') {
                continue;
            }
            if (!password_verify($otp, (string) ($challenge['otp_hash'] ?? ''))) {
                $challenge['attempts'] = (int) $challenge['attempts'] + 1;
                unset($challenge);
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $challenge['verification_token_hash'] = hash('sha256', $token);
            $challenge['expires_at'] = time() + 1800;
            unset($challenge);
            return $token;
        }
        unset($challenge);
        return null;
    });
}

function consumeEmailOtpVerification(string $token, string $email): bool
{
    $tokenHash = hash('sha256', $token);
    $emailHash = hash('sha256', strtolower(trim($email)));
    return withEmailOtpState(static function (&$state) use ($tokenHash, $emailHash): bool {
        foreach ($state['challenges'] as $index => $challenge) {
            if (($challenge['email_hash'] ?? '') === $emailHash
                && (int) ($challenge['expires_at'] ?? 0) > time()
                && ($challenge['verification_token_hash'] ?? '') !== ''
                && hash_equals((string) $challenge['verification_token_hash'], $tokenHash)) {
                unset($state['challenges'][$index]);
                return true;
            }
        }
        return false;
    });
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
    if ($email === false || trim($email) === '') {
        $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';
        $config = is_file($configPath) ? require $configPath : [];
        $email = is_array($config) ? (string) ($config['admin_email'] ?? '') : '';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The HR notification recipient is not configured correctly.');
    }
    return $email;
}

function localEmailLogMode(): bool
{
    $mode = getenv('CAREER_EMAIL_MODE');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $clientAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $mode === 'log'
        && PHP_SAPI === 'cli-server'
        && (bool) preg_match('/^(localhost|127\.0\.0\.1)(?::\d+)?$/', $host)
        && in_array($clientAddress, ['127.0.0.1', '::1'], true);
}

function sendCareerEmail(
    string $recipient,
    string $subject,
    string $body,
    array $localLogContext = [],
    ?string $attachmentPath = null,
    string $attachmentName = 'resume.pdf'
): bool
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
    if ($attachmentPath !== null) {
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $attachmentName)) {
            throw new RuntimeException('The email attachment name is invalid.');
        }
        $attachmentContents = file_get_contents($attachmentPath);
        if ($attachmentContents === false || strlen($attachmentContents) > CAREER_APPLICATION_MAX_RESUME_SIZE) {
            throw new RuntimeException('The application resume could not be attached to the email.');
        }
        $boundary = 'career-' . bin2hex(random_bytes(16));
        $headers[1] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: application/pdf; name="' . $attachmentName . '"' . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . $attachmentName . '"' . "\r\n\r\n"
            . chunk_split(base64_encode($attachmentContents), 76, "\r\n")
            . '--' . $boundary . '--';
    }
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
    $resumeReference = (string) ($application['resume_reference'] ?? '');
    if (!preg_match('/^resume-[a-f0-9]{32}\.pdf$/', $resumeReference)) {
        throw new RuntimeException('The application resume reference is invalid.');
    }
    $body = implode("\r\n", [
        'A new application has been submitted.',
        '',
        'Application ID: ' . $application['id'],
        'Job: ' . $application['job_title'],
        'Applicant: ' . $application['full_name'],
        'Email: ' . $application['email'],
        'Phone: ' . $application['phone'],
        'Location: ' . (string) ($application['location'] ?? ''),
        'Qualification: ' . (string) ($application['qualification'] ?? ''),
        'College: ' . (string) ($application['college'] ?? ''),
        'Graduation year: ' . (string) ($application['graduation_year'] ?? ''),
        'LinkedIn: ' . (string) ($application['linkedin_url'] ?? ''),
        'Portfolio: ' . (string) ($application['portfolio_url'] ?? ''),
        'Cover letter: ' . (string) ($application['cover_letter'] ?? ''),
        'Submitted: ' . $application['submitted_at'],
        '',
        'The candidate resume is attached. You can also download it from the authenticated Career Admin application details page.',
    ]);
    sendCareerEmail(applicationHrEmail(), 'New job application: ' . (string) $application['job_title'], $body, [
        'type' => 'hr-application-notification',
        'application_id' => $application['id'],
    ], applicationStorageDirectory() . DIRECTORY_SEPARATOR . $resumeReference, (string) $application['id'] . '.pdf');
}

function sendCandidateApplicationConfirmation(array $application): bool
{
    $body = implode("\r\n", [
        'Hello ' . (string) $application['full_name'] . ',',
        '',
        'Thank you for applying for ' . (string) $application['job_title'] . '.',
        'We have received your application and resume. Our team will review it and contact you if there is a suitable next step.',
        '',
        'Application reference: ' . (string) $application['id'],
        '',
        'Regards,',
        'DeepshiTech Careers',
    ]);
    return sendCareerEmail((string) $application['email'], 'Application received: ' . (string) $application['job_title'], $body, [
        'type' => 'candidate-application-confirmation',
        'application_id' => $application['id'],
    ]);
}

function recordCandidateConfirmation(string $applicationId, string $status, string $error = ''): void
{
    withApplications(static function (&$applications) use ($applicationId, $status, $error): void {
        foreach ($applications as &$application) {
            if (($application['id'] ?? '') === $applicationId) {
                $application['candidate_confirmation_status'] = $status;
                $application['candidate_confirmation_attempted_at'] = gmdate('c');
                if ($error !== '') {
                    $application['candidate_confirmation_error'] = $error;
                } else {
                    unset($application['candidate_confirmation_error']);
                }
                return;
            }
        }
        throw new RuntimeException('The application could not be found.');
    }, true);
}

function notifyCandidateApplicationReceived(array $application): string
{
    try {
        $sent = sendCandidateApplicationConfirmation($application);
        $status = $sent ? 'Sent' : 'Pending';
        recordCandidateConfirmation($application['id'], $status);
        return $status;
    } catch (Throwable $error) {
        error_log('Career candidate confirmation failed for ' . $application['id'] . ': ' . $error->getMessage());
        recordCandidateConfirmation($application['id'], 'Failed', 'Confirmation email could not be sent. Check server-side mail settings and logs.');
        return 'Failed';
    }
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
