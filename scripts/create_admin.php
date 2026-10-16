<?php
declare(strict_types=1);

/* Run from a terminal only: php scripts/create_admin.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/database.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    return $value === false ? '' : rtrim($value, "\r\n");
}

$username = strtolower(trim(prompt('Choose username (3-80 characters): ')));
if (!preg_match('/^[a-z0-9_.-]{3,80}$/', $username)) {
    fwrite(STDERR, "Use 3-80 characters: lowercase letters, numbers, dot, underscore, or hyphen.\n");
    exit(1);
}
$password = prompt('Choose a password (at least 8 characters): ');
$confirmation = prompt('Confirm password: ');
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

try {
    $pdo = database_connection();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $role = $count === 0 ? 'super_admin' : 'administrative_staff';
    $statement = $pdo->prepare(
        'INSERT INTO users (username, password_hash, role, is_active) VALUES (:username, :password_hash, :role, 1)'
    );
    $statement->execute([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    fwrite(STDOUT, "Account created with role: {$role}. Sign in at http://localhost/student-id-generator/\n");
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        fwrite(STDERR, "That username already exists. Choose a different username.\n");
    } else {
        error_log('Student ID Generator account setup error: ' . $exception->getMessage());
        fwrite(STDERR, "Could not create the account. Check MySQL is running and the database migration has been applied.\n");
    }
    exit(1);
}
