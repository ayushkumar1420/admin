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

const DATA_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs.json';
const CONFIG_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'config.php';

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function adminConfig(): array
{
    if (!is_file(CONFIG_FILE)) {
        return [];
    }
    $config = require CONFIG_FILE;
    return is_array($config) ? $config : [];
}

function updateLoginAttempts(string $clientKey, bool $successful = false): bool
{
    $path = dirname(CONFIG_FILE) . DIRECTORY_SEPARATOR . 'login-attempts.json';
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Login protection could not be initialized.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Login protection could not be locked.');
    }

    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false) {
            throw new RuntimeException('Login protection data could not be read.');
        }
        $attempts = [];
        if (trim($contents) !== '') {
            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Login protection data is invalid.');
            }
            $attempts = $decoded;
        }

        $now = time();
        $cutoff = $now - 900;
        foreach ($attempts as $key => $history) {
            if (!is_array($history)) {
                unset($attempts[$key]);
                continue;
            }
            $history = array_values(array_filter($history, static function ($timestamp) use ($cutoff, $now): bool {
                return is_int($timestamp) && $timestamp >= $cutoff && $timestamp <= $now;
            }));
            if ($history) {
                $attempts[$key] = $history;
            } else {
                unset($attempts[$key]);
            }
        }

        $history = $attempts[$clientKey] ?? [];
        if ($successful) {
            unset($attempts[$clientKey]);
            $allowed = true;
        } elseif (count($history) >= 5) {
            $allowed = false;
        } else {
            $history[] = $now;
            $attempts[$clientKey] = $history;
            $allowed = true;
        }
        $json = json_encode($attempts, JSON_THROW_ON_ERROR);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException('Login protection data could not be saved.');
        }
        rewind($handle);
        $written = fwrite($handle, $json);
        if ($written !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Login protection data could not be saved.');
        }
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function ensureDataFile(): void
{
    $directory = dirname(DATA_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The job data folder could not be created.');
    }
    if (!is_file(DATA_FILE)) {
        $initialData = json_encode(['jobs' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($initialData === false || file_put_contents(DATA_FILE, $initialData . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('The job data file could not be initialized.');
        }
        @chmod(DATA_FILE, 0600);
    }
}

function withJobs(callable $callback, bool $write = false)
{
    $directory = dirname(DATA_FILE);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('The job data folder could not be created.');
    }

    $lockPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'jobs.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException('The job data file could not be locked.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('The job data file could not be locked.');
    }

    try {
        ensureDataFile();
        $decoded = json_decode((string) file_get_contents(DATA_FILE), true);
        if (!is_array($decoded) || !isset($decoded['jobs']) || !is_array($decoded['jobs'])) {
            throw new RuntimeException('Job data is invalid. Check data/jobs.json.');
        }
        $jobs = array_values(array_filter($decoded['jobs'], 'is_array'));
        $result = $callback($jobs);
        if ($write) {
            $json = json_encode(['jobs' => $jobs], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Job data could not be encoded.');
            }
            if (file_put_contents(DATA_FILE, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Job data could not be saved.');
            }
            @chmod(DATA_FILE, 0600);
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['career_admin_csrf'])) {
        $_SESSION['career_admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['career_admin_csrf'];
}

function verifyCsrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    $expected = (string) ($_SESSION['career_admin_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Your session expired. Refresh the page and try again.');
    }
}

function requireLogin(): void
{
    if (empty($_SESSION['career_admin_email'])) {
        header('Location: index.php');
        exit;
    }
}

function cleanText(string $key, string $label, int $maxLength, bool $required = false): string
{
    $rawValue = $_POST[$key] ?? '';
    if (!is_string($rawValue) && !is_numeric($rawValue)) {
        throw new InvalidArgumentException($label . ' must be plain text.');
    }
    $value = trim(strip_tags((string) $rawValue));
    if ($required && $value === '') {
        throw new InvalidArgumentException($label . ' is required.');
    }
    if (strlen($value) > $maxLength) {
        throw new InvalidArgumentException($label . ' must be ' . $maxLength . ' characters or fewer.');
    }
    return $value;
}

function validateJob(): array
{
    $job = [
        'title' => cleanText('title', 'Job title', 120, true),
        'department' => cleanText('department', 'Department', 100, true),
        'job_type' => cleanText('job_type', 'Job type', 30, true),
        'work_mode' => cleanText('work_mode', 'Work mode', 30, true),
        'location' => cleanText('location', 'Location', 120, true),
        'openings' => cleanText('openings', 'Number of openings', 8),
        'experience' => cleanText('experience', 'Experience / eligibility', 500),
        'short_description' => cleanText('short_description', 'Short description', 500, true),
        'description' => cleanText('description', 'Full job description', 10000, true),
        'responsibilities' => cleanText('responsibilities', 'Responsibilities', 5000),
        'skills' => cleanText('skills', 'Required skills / qualifications', 5000),
        'deadline' => cleanText('deadline', 'Application deadline', 10),
        'status' => cleanText('status', 'Job status', 20, true),
    ];

    if (!in_array($job['job_type'], ['Internship', 'Full-time', 'Part-time', 'Contract'], true)) {
        throw new InvalidArgumentException('Choose a valid job type.');
    }
    if (!in_array($job['work_mode'], ['On-site', 'Hybrid', 'Remote'], true)) {
        throw new InvalidArgumentException('Choose a valid work mode.');
    }
    if (!in_array($job['status'], ['Draft', 'Published'], true)) {
        throw new InvalidArgumentException('Choose Draft or Published as the status.');
    }
    if ($job['openings'] !== '' && (!ctype_digit($job['openings']) || (int) $job['openings'] < 1)) {
        throw new InvalidArgumentException('Number of openings must be a positive whole number.');
    }
    if ($job['deadline'] !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $job['deadline']);
        if (!$date || $date->format('Y-m-d') !== $job['deadline']) {
            throw new InvalidArgumentException('Enter a valid application deadline.');
        }
    }
    return $job;
}

$config = adminConfig();
$notice = '';
$noticeType = 'success';
$authenticated = !empty($_SESSION['career_admin_email'])
    && !empty($config['admin_email'])
    && hash_equals((string) $config['admin_email'], (string) $_SESSION['career_admin_email']);
if (!$authenticated) {
    unset($_SESSION['career_admin_email']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf();
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'login') {
            $emailInput = $_POST['email'] ?? '';
            $passwordInput = $_POST['password'] ?? '';
            if (!is_string($emailInput) || !is_string($passwordInput)) {
                throw new RuntimeException('The email or password is incorrect.');
            }
            $clientKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            if (!updateLoginAttempts($clientKey)) {
                throw new RuntimeException('Too many login attempts. Wait 15 minutes before trying again.');
            }
            $email = strtolower(trim($emailInput));
            $password = $passwordInput;
            $config = adminConfig();
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)
                || empty($config['admin_email'])
                || !hash_equals(strtolower((string) $config['admin_email']), $email)
                || empty($config['admin_password_hash'])
                || !password_verify($password, (string) $config['admin_password_hash'])) {
                throw new RuntimeException('The email or password is incorrect.');
            }
            updateLoginAttempts($clientKey, true);
            session_regenerate_id(true);
            $_SESSION['career_admin_email'] = (string) $config['admin_email'];
            $_SESSION['career_admin_csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        }

        requireLogin();
        if ($action === 'logout') {
            $cookie = session_get_cookie_params();
            $_SESSION = [];
            session_destroy();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            header('Location: index.php');
            exit;
        }

        if ($action === 'save') {
            $jobData = validateJob();
            $id = (string) ($_POST['id'] ?? '');
            withJobs(static function (&$jobs) use ($jobData, $id): void {
                $now = gmdate('c');
                if ($id === '') {
                    $jobData['id'] = bin2hex(random_bytes(16));
                    $jobData['created_at'] = $now;
                    $jobData['updated_at'] = $now;
                    $jobData['applications'] = [];
                    $jobs[] = $jobData;
                    return;
                }
                foreach ($jobs as $index => $existing) {
                    if (($existing['id'] ?? '') === $id) {
                        $jobData['id'] = $id;
                        $jobData['created_at'] = $existing['created_at'] ?? $now;
                        $jobData['updated_at'] = $now;
                        $jobData['applications'] = $existing['applications'] ?? [];
                        $jobs[$index] = $jobData;
                        return;
                    }
                }
                throw new RuntimeException('The job you are editing could not be found.');
            }, true);
            header('Location: index.php?view=jobs&saved=1');
            exit;
        }

        if ($action === 'status') {
            $id = (string) ($_POST['id'] ?? '');
            withJobs(static function (&$jobs) use ($id): void {
                foreach ($jobs as &$job) {
                    if (($job['id'] ?? '') === $id) {
                        $job['status'] = ($job['status'] ?? '') === 'Published' ? 'Draft' : 'Published';
                        $job['updated_at'] = gmdate('c');
                        return;
                    }
                }
                throw new RuntimeException('The job could not be found.');
            }, true);
            header('Location: index.php?view=jobs');
            exit;
        }

        if ($action === 'delete') {
            $id = (string) ($_POST['id'] ?? '');
            withJobs(static function (&$jobs) use ($id): void {
                $before = count($jobs);
                $jobs = array_values(array_filter($jobs, static function ($job) use ($id): bool {
                    return ($job['id'] ?? '') !== $id;
                }));
                if (count($jobs) === $before) {
                    throw new RuntimeException('The job could not be found.');
                }
            }, true);
            header('Location: index.php?view=jobs&deleted=1');
            exit;
        }
        if ($action === 'application_status') {
            $applicationId = (string) ($_POST['application_id'] ?? '');
            $status = (string) ($_POST['application_status'] ?? '');
            if (!in_array($status, ['New', 'Under Review', 'Shortlisted', 'Rejected'], true)) {
                throw new InvalidArgumentException('Choose a valid application status.');
            }
            withApplications(static function (&$applications) use ($applicationId, $status): void {
                foreach ($applications as &$application) {
                    if (($application['id'] ?? '') === $applicationId) {
                        $application['status'] = $status;
                        $application['updated_at'] = gmdate('c');
                        return;
                    }
                }
                throw new RuntimeException('The application could not be found.');
            }, true);
            header('Location: index.php?view=applications&application=' . rawurlencode($applicationId) . '&updated=1');
            exit;
        }
        if ($action === 'retry_notification') {
            $applicationId = (string) ($_POST['application_id'] ?? '');
            retryApplicationNotification($applicationId);
            header('Location: index.php?view=applications&application=' . rawurlencode($applicationId) . '&retried=1');
            exit;
        }
        throw new RuntimeException('That action is not available.');
    } catch (Throwable $error) {
        $notice = $error instanceof InvalidArgumentException || $error instanceof RuntimeException
            ? $error->getMessage()
            : 'The request could not be completed. Please try again.';
        $noticeType = 'error';
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Job saved successfully.';
}
if (isset($_GET['deleted'])) {
    $notice = 'Job deleted.';
}
if (isset($_GET['updated'])) {
    $notice = 'Application status updated.';
}
if (isset($_GET['retried'])) {
    $notice = 'Notification retry completed. Check the notification status below.';
}

try {
    $jobs = withJobs(static fn(array &$items): array => $items);
} catch (Throwable $error) {
    error_log('Career admin storage error: ' . $error->getMessage());
    $jobs = [];
    $notice = 'Job storage could not be read. Check folder permissions and data/jobs.json.';
    $noticeType = 'error';
}

$applications = [];
if ($authenticated) {
    try {
        $applications = withApplications(static fn(array &$items): array => $items);
    } catch (Throwable $error) {
        error_log('Career application storage error: ' . $error->getMessage());
        $notice = 'Application storage could not be read. Check data/applications.json and folder permissions.';
        $noticeType = 'error';
    }
}
$selectedApplicationId = (string) ($_GET['application'] ?? '');
$selectedApplication = null;
foreach ($applications as $application) {
    if (($application['id'] ?? '') === $selectedApplicationId) {
        $selectedApplication = $application;
        break;
    }
}
$publishedCount = count(array_filter($jobs, static fn(array $job): bool => ($job['status'] ?? '') === 'Published'));
$draftCount = count($jobs) - $publishedCount;
$view = (string) ($_GET['view'] ?? 'dashboard');
$editId = (string) ($_GET['edit'] ?? '');
$editingJob = null;
foreach ($jobs as $job) {
    if (($job['id'] ?? '') === $editId) {
        $editingJob = $job;
        break;
    }
}
$isLogin = !$authenticated;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $isLogin ? 'Sign In' : h(ucfirst($view)) ?> | DeepshiTech Career Admin</title>
    <link rel="icon" type="image/png" href="assets/deepshitech-logo.png?v=1">
    <link rel="apple-touch-icon" href="assets/deepshitech-logo.png?v=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="<?= $isLogin ? 'login-page' : 'dashboard-page' ?>">
<?php if ($isLogin): ?>
    <main class="login-wrap">
        <section class="login-card">
            <img class="brand-mark" src="assets/deepshitech-logo.png" alt="DeepshiTech">
            <p class="eyebrow">CAREERS WORKSPACE</p>
            <h1>Career Admin</h1>
            <p class="login-copy">Sign in to manage your career opportunities.</p>
            <?php if ($notice !== ''): ?><div class="alert alert-error" role="alert"><?= h($notice) ?></div><?php endif; ?>
            <form method="post" autocomplete="on">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" required autofocus>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="button button-gold login-submit" type="submit">Log in</button>
            </form>
            <a class="back-link" href="../DeepshiTechW1/career/explore.html">← Back to Careers</a>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="sidebar-brand" href="index.php"><img class="brand-icon" src="assets/deepshitech-logo.png" alt=""><span><strong>DEEPSHITECH</strong><small>CAREER WORKSPACE</small></span></a>
            <p class="sidebar-label">WORKSPACE</p>
            <nav class="side-nav" aria-label="Career administration">
                <a class="<?= $view === 'dashboard' ? 'active' : '' ?>" href="index.php"><span>▦</span>Dashboard</a>
                <a class="<?= $view === 'jobs' ? 'active' : '' ?>" href="index.php?view=jobs"><span>▤</span>Manage Jobs</a>
                <a class="<?= $view === 'applications' ? 'active' : '' ?>" href="index.php?view=applications"><span>▧</span>Applications</a>
                <a class="<?= $view === 'new' ? 'active' : '' ?>" href="index.php?view=new"><span>＋</span>Create New Job</a>
            </nav>
            <div class="sidebar-bottom">CAREER ADMINISTRATION</div>
        </aside>
        <main class="workspace">
            <header class="topbar">
                <div class="breadcrumbs">Workspace <span>/</span> <?= h($view === 'new' ? 'Create Job' : ($editingJob ? 'Edit Job' : ($selectedApplication ? 'Application details' : ucfirst($view)))) ?></div>
                <div class="account">
                    <span class="online-dot"></span><span class="admin-label">Admin Panel</span>
                    <img class="avatar" src="assets/deepshitech-logo.png" alt="DeepshiTech logo">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button class="logout-button" type="submit">Logout</button>
                    </form>
                </div>
            </header>
            <div class="page-content">
                <?php if ($notice !== ''): ?><div class="alert <?= $noticeType === 'error' ? 'alert-error' : 'alert-success' ?>" role="status"><?= h($notice) ?></div><?php endif; ?>
                <?php if ($view === 'new' || $editingJob): ?>
                    <?php
                    $formJob = $editingJob ?? [
                        'title' => '', 'department' => '', 'job_type' => '', 'work_mode' => '',
                        'location' => '', 'openings' => '', 'experience' => '', 'deadline' => '',
                        'short_description' => '', 'description' => '', 'responsibilities' => '',
                        'skills' => '', 'status' => 'Draft',
                    ];
                    ?>
                    <div class="page-heading">
                        <div><p class="eyebrow">OPPORTUNITY DETAILS</p><h1><?= $editingJob ? 'Edit Job' : 'Create New Job' ?></h1><p>Add the role details. Draft jobs stay private.</p></div>
                        <a class="button button-light" href="index.php?view=jobs">Cancel</a>
                    </div>
                    <form class="editor-card" method="post">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?= h($editingJob['id'] ?? '') ?>">
                        <div class="form-grid">
                            <?php foreach ([
                                ['title', 'Job title', true], ['department', 'Department', true],
                                ['job_type', 'Job type', true], ['work_mode', 'Work mode', true],
                                ['location', 'Location', true], ['openings', 'Number of openings (optional)', false],
                                ['experience', 'Experience / eligibility (optional)', false], ['deadline', 'Application deadline (optional)', false],
                            ] as [$field, $label, $required]): ?>
                                <div class="field">
                                    <label for="<?= h($field) ?>"><?= h($label) ?><?= $required ? ' <span>*</span>' : '' ?></label>
                                    <?php if ($field === 'job_type'): ?>
                                        <select id="<?= h($field) ?>" name="<?= h($field) ?>" required>
                                            <option value="">Select job type</option>
                                            <?php foreach (['Internship', 'Full-time', 'Part-time', 'Contract'] as $option): ?><option <?= ($formJob[$field] ?? '') === $option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?>
                                        </select>
                                    <?php elseif ($field === 'work_mode'): ?>
                                        <select id="<?= h($field) ?>" name="<?= h($field) ?>" required>
                                            <option value="">Select work mode</option>
                                            <?php foreach (['On-site', 'Hybrid', 'Remote'] as $option): ?><option <?= ($formJob[$field] ?? '') === $option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <input id="<?= h($field) ?>" name="<?= h($field) ?>" value="<?= h($formJob[$field] ?? '') ?>" <?= $field === 'openings' ? 'type="number" min="1"' : ($field === 'deadline' ? 'type="date"' : 'type="text"') ?> <?= $required ? 'required' : '' ?>>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php foreach ([
                                ['short_description', 'Short description', true, 2],
                                ['description', 'Full job description', true, 6],
                                ['responsibilities', 'Responsibilities (optional)', false, 4],
                                ['skills', 'Required skills / qualifications (optional)', false, 4],
                            ] as [$field, $label, $required, $rows]): ?>
                                <div class="field field-full">
                                    <label for="<?= h($field) ?>"><?= h($label) ?><?= $required ? ' <span>*</span>' : '' ?></label>
                                    <textarea id="<?= h($field) ?>" name="<?= h($field) ?>" rows="<?= (int) $rows ?>" <?= $required ? 'required' : '' ?>><?= h($formJob[$field] ?? '') ?></textarea>
                                </div>
                            <?php endforeach; ?>
                            <div class="field">
                                <label for="status">Job status</label>
                                <select id="status" name="status">
                                    <option value="Draft" <?= ($formJob['status'] ?? '') === 'Draft' ? 'selected' : '' ?>>Draft</option>
                                    <option value="Published" <?= ($formJob['status'] ?? '') === 'Published' ? 'selected' : '' ?>>Published</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions"><a class="button button-light" href="index.php?view=jobs">Cancel</a><button class="button button-gold" type="submit">Save Job</button></div>
                    </form>
                <?php elseif ($view === 'applications'): ?>
                    <?php if ($selectedApplication): ?>
                        <div class="page-heading">
                            <div><p class="eyebrow">APPLICATION DETAILS</p><h1><?= h($selectedApplication['full_name'] ?? 'Applicant') ?></h1><p><?= h($selectedApplication['job_title'] ?? '') ?> · <?= h($selectedApplication['id'] ?? '') ?></p></div>
                            <a class="button button-light" href="index.php?view=applications">Back to applications</a>
                        </div>
                        <section class="table-card">
                            <div class="table-scroll"><table><tbody>
                                <?php foreach ([
                                    'Email' => $selectedApplication['email'] ?? '',
                                    'Phone' => $selectedApplication['phone'] ?? '',
                                    'Location' => $selectedApplication['location'] ?? '',
                                    'Qualification' => $selectedApplication['qualification'] ?? '',
                                    'College' => $selectedApplication['college'] ?? '',
                                    'Graduation year' => $selectedApplication['graduation_year'] ?? '',
                                    'LinkedIn' => $selectedApplication['linkedin_url'] ?? '',
                                    'Portfolio / GitHub' => $selectedApplication['portfolio_url'] ?? '',
                                    'Submitted' => $selectedApplication['submitted_at'] ?? '',
                                    'Recruitment consent' => !empty($selectedApplication['consent']) ? 'Given' : 'Not recorded',
                                    'Cover letter' => $selectedApplication['cover_letter'] ?? '',
                                ] as $label => $value): ?>
                                    <tr><th><?= h($label) ?></th><td><?= nl2br(h($value)) ?></td></tr>
                                <?php endforeach; ?>
                                <tr><th>Resume</th><td><a class="text-action" href="admin-resume.php?id=<?= rawurlencode((string) $selectedApplication['id']) ?>">Download resume (PDF)</a></td></tr>
                                <tr><th>HR notification</th><td><?= h($selectedApplication['notification_status'] ?? 'Pending') ?><?php if (!empty($selectedApplication['notification_attempted_at'])): ?> · <?= h($selectedApplication['notification_attempted_at']) ?><?php endif; ?><?php if (!empty($selectedApplication['notification_error'])): ?><br><?= h($selectedApplication['notification_error']) ?><?php endif; ?></td></tr>
                            </tbody></table></div>
                            <div class="form-actions">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="application_status">
                                    <input type="hidden" name="application_id" value="<?= h($selectedApplication['id']) ?>">
                                    <label for="application_status">Application status</label>
                                    <select id="application_status" name="application_status">
                                        <?php foreach (['New', 'Under Review', 'Shortlisted', 'Rejected'] as $status): ?><option value="<?= h($status) ?>" <?= ($selectedApplication['status'] ?? 'New') === $status ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="button button-gold" type="submit">Update status</button>
                                </form>
                                <?php if (($selectedApplication['notification_status'] ?? '') !== 'Sent'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="retry_notification">
                                        <input type="hidden" name="application_id" value="<?= h($selectedApplication['id']) ?>">
                                        <button class="button button-light" type="submit">Retry HR notification</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php else: ?>
                        <div class="page-heading">
                            <div><p class="eyebrow">CANDIDATE MANAGEMENT</p><h1>Applications</h1><p>Review candidate submissions and their HR notification status.</p></div>
                        </div>
                        <section class="table-card">
                            <?php if (!$applications): ?>
                                <div class="empty-state"><span>▧</span><h2>No applications yet</h2><p>Submitted applications will appear here.</p></div>
                            <?php else: ?>
                                <div class="table-scroll"><table><thead><tr><th>APPLICATION</th><th>APPLICANT</th><th>JOB</th><th>SUBMITTED</th><th>STATUS</th><th>HR NOTIFICATION</th></tr></thead><tbody>
                                <?php foreach ($applications as $application): ?>
                                    <tr>
                                        <td><a class="text-action" href="index.php?view=applications&amp;application=<?= rawurlencode((string) ($application['id'] ?? '')) ?>"><?= h($application['id'] ?? '') ?></a></td>
                                        <td><?= h($application['full_name'] ?? '') ?><small><?= h($application['email'] ?? '') ?></small></td>
                                        <td><?= h($application['job_title'] ?? '') ?></td>
                                        <td><?= h($application['submitted_at'] ?? '') ?></td>
                                        <td><span class="status status-draft"><?= h($application['status'] ?? 'New') ?></span></td>
                                        <td><?= h($application['notification_status'] ?? 'Pending') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                <?php elseif ($view === 'jobs'): ?>
                    <div class="page-heading">
                        <div><p class="eyebrow">CONTENT MANAGEMENT</p><h1>Manage Jobs</h1><p>Review and update your career listings.</p></div>
                        <a class="button button-gold" href="index.php?view=new">＋ Create New Job</a>
                    </div>
                    <section class="table-card">
                        <?php if (!$jobs): ?>
                            <div class="empty-state"><span>▤</span><h2>No jobs yet</h2><p>Create your first opportunity to get started.</p><a class="button button-gold" href="index.php?view=new">Create New Job</a></div>
                        <?php else: ?>
                            <div class="table-scroll"><table><thead><tr><th>JOB TITLE</th><th>DEPARTMENT</th><th>TYPE</th><th>STATUS</th><th>UPDATED</th><th>ACTIONS</th></tr></thead><tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td><strong><?= h($job['title'] ?? '') ?></strong><small><?= h($job['location'] ?? '') ?></small></td>
                                    <td><?= h($job['department'] ?? '') ?></td>
                                    <td><?= h($job['job_type'] ?? '') ?></td>
                                    <td><span class="status <?= ($job['status'] ?? '') === 'Published' ? 'status-published' : 'status-draft' ?>"><?= h($job['status'] ?? 'Draft') ?></span></td>
                                    <td><?= h(isset($job['updated_at']) ? date('d M Y', strtotime((string) $job['updated_at'])) : '') ?></td>
                                    <td><div class="row-actions">
                                        <a class="text-action" href="index.php?edit=<?= rawurlencode((string) $job['id']) ?>">Edit</a>
                                        <form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= h($job['id']) ?>"><button class="text-action" type="submit"><?= ($job['status'] ?? '') === 'Published' ? 'Unpublish' : 'Publish' ?></button></form>
                                        <form method="post" onsubmit="return confirm('Delete this job? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= h($job['id']) ?>"><button class="text-action action-danger" type="submit">Delete</button></form>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    </section>
                <?php else: ?>
                    <div class="page-heading">
                        <div><p class="eyebrow">CAREER MANAGEMENT</p><h1>Dashboard</h1><p>Manage your DeepshiTech career opportunities in one place.</p></div>
                        <a class="button button-gold" href="index.php?view=new">＋ Create New Job</a>
                    </div>
                    <div class="storage-notice"><span>i</span><p>Your dashboard is connected to local JSON storage. Jobs and dashboard statistics update using your saved listings.</p></div>
                    <section class="stats-grid">
                        <article class="stat-card"><div class="stat-label">Total Jobs <span>▤</span></div><strong><?= count($jobs) ?></strong></article>
                        <article class="stat-card"><div class="stat-label">Published <span class="stat-green">✓</span></div><strong><?= $publishedCount ?></strong></article>
                        <article class="stat-card"><div class="stat-label">Drafts <span class="stat-blue">✎</span></div><strong><?= $draftCount ?></strong></article>
                        <article class="stat-card"><div class="stat-label">Applications <span>▧</span></div><strong><?= count($applications) ?></strong></article>
                    </section>
                    <section class="recent-card">
                        <div class="recent-heading"><div><p class="eyebrow">LATEST OPPORTUNITIES</p><h2>Recent Jobs</h2></div><a class="text-action" href="index.php?view=jobs">View all jobs →</a></div>
                        <?php if (!$jobs): ?>
                            <div class="empty-state compact"><h2>No jobs posted yet</h2><p>Create a job and it will be saved in this folder’s JSON data file.</p><a class="button button-gold" href="index.php?view=new">Create New Job</a></div>
                        <?php else: ?>
                            <div class="recent-list"><?php foreach (array_slice(array_reverse($jobs), 0, 5) as $job): ?><a class="recent-row" href="index.php?edit=<?= rawurlencode((string) $job['id']) ?>"><span><strong><?= h($job['title'] ?? '') ?></strong><small><?= h($job['department'] ?? '') ?> · <?= h($job['job_type'] ?? '') ?></small></span><span class="status <?= ($job['status'] ?? '') === 'Published' ? 'status-published' : 'status-draft' ?>"><?= h($job['status'] ?? 'Draft') ?></span></a><?php endforeach; ?></div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    </div>
<?php endif; ?>
</body>
</html>
