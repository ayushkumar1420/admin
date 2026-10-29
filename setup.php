<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$email = getenv('CAREER_ADMIN_EMAIL');
if ($email === false || trim($email) === '') {
    fwrite(STDOUT, "Admin email: ");
    $email = trim((string) fgets(STDIN));
}

$password = getenv('CAREER_ADMIN_PASSWORD');
if ($password === false || $password === '') {
    fwrite(STDOUT, "Admin password (12+ characters): ");
    $password = trim((string) fgets(STDIN));
}

$email = strtolower(trim($email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid admin email is required.\n");
    exit(1);
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Use an admin password of at least 12 characters.\n");
    exit(1);
}

$directory = __DIR__ . DIRECTORY_SEPARATOR . 'private';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Could not create the private configuration folder.\n");
    exit(1);
}

$config = "<?php\nreturn [\n"
    . "    'admin_email' => " . var_export($email, true) . ",\n"
    . "    'admin_password_hash' => " . var_export(password_hash($password, PASSWORD_DEFAULT), true) . ",\n"
    . "];\n";
$target = $directory . DIRECTORY_SEPARATOR . 'config.php';
if (file_put_contents($target, $config, LOCK_EX) === false) {
    fwrite(STDERR, "Could not write private configuration.\n");
    exit(1);
}
@chmod($target, 0600);
fwrite(STDOUT, "Career admin credentials configured successfully.\n");
