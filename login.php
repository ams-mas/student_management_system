<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}
if (!csrf_token_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Invalid form token. Return to the sign-in page and try again.');
}

$username = strtolower(trim((string) ($_POST['username'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
if ($username === '' || strlen($username) > 80 || $password === '' || strlen($password) > 4096) {
    header('Location: index.php?invalid=1');
    exit;
}

$pdo = database_connection();

/*
 * Each real account is locked for 24 hours after five incorrect passwords
 * within 15 minutes. A separate, higher IP-only limit helps slow attacks on
 * unknown usernames without locking every school user after a few mistakes.
 * Use REMOTE_ADDR; do not trust forwarded headers without a trusted proxy.
 */
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ipKey = hash('sha256', "login-ip\0" . $clientIp);

try {
    $limitStmt = $pdo->prepare(
        'SELECT bucket_key, failed_attempts, locked_until
         FROM login_rate_limits
         WHERE bucket_key = :ip_key'
    );
    $limitStmt->execute(['ip_key' => $ipKey]);
    $limits = $limitStmt->fetchAll();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($limits as $limit) {
        if (!empty($limit['locked_until'])) {
            $lockedUntil = new DateTimeImmutable((string) $limit['locked_until'], new DateTimeZone('UTC'));
            if ($lockedUntil > $now) {
                header('Location: index.php?throttled=1');
                exit;
            }
        }
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, username, full_name, password_hash, role, is_active FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
    } catch (PDOException $nameColumnException) {
        // Allow login to continue during deployment before the full_name migration runs.
        if ((int) ($nameColumnException->errorInfo[1] ?? 0) !== 1054) throw $nameColumnException;
        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, role, is_active FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();
        if ($user) $user['full_name'] = '';
    }

    if ($user) {
        $accountLockStmt = $pdo->prepare(
            'SELECT locked_until FROM user_login_lockouts WHERE user_id = :user_id LIMIT 1'
        );
        $accountLockStmt->execute(['user_id' => (int) $user['id']]);
        $accountLock = $accountLockStmt->fetch();
        if ($accountLock && !empty($accountLock['locked_until'])) {
            $lockedUntil = new DateTimeImmutable((string) $accountLock['locked_until'], new DateTimeZone('UTC'));
            if ($lockedUntil > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                header('Location: index.php?account_locked=1');
                exit;
            }
        }
    }
} catch (PDOException $exception) {
    error_log('Student ID Generator login service error: ' . $exception->getMessage());
    header('Location: index.php?database=1');
    exit;
}

if (!$user || !password_verify($password, (string) $user['password_hash'])) {
    usleep(250000);
    try {
        $recordFailure = static function (PDO $pdo, string $key, int $threshold, int $lockMinutes): void {
            $statement = $pdo->prepare(
                'INSERT INTO login_rate_limits (bucket_key, failed_attempts, window_started_at, locked_until)
                 VALUES (:bucket_key, 1, UTC_TIMESTAMP(), NULL)
                 ON DUPLICATE KEY UPDATE
                    failed_attempts = IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 1, failed_attempts + 1),
                    locked_until = IF(
                        window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                        NULL,
                        IF(failed_attempts >= :threshold, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $lockMinutes . ' MINUTE), locked_until)
                    ),
                    window_started_at = IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), UTC_TIMESTAMP(), window_started_at)'
            );
            $statement->execute(['bucket_key' => $key, 'threshold' => $threshold]);
        };
        if ($user) {
            $accountFailure = $pdo->prepare(
                'INSERT INTO user_login_lockouts (user_id, failed_attempts, window_started_at, locked_until)
                 VALUES (:user_id, 1, UTC_TIMESTAMP(), NULL)
                 ON DUPLICATE KEY UPDATE
                    failed_attempts = IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), 1, failed_attempts + 1),
                    locked_until = IF(
                        window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                        NULL,
                        IF(failed_attempts >= 5, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), locked_until)
                    ),
                    window_started_at = IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), UTC_TIMESTAMP(), window_started_at)'
            );
            $accountFailure->execute(['user_id' => (int) $user['id']]);
        }

        $recordFailure($pdo, $ipKey, 40, 10);

        // Keep the rate-limit table small without deleting active IP lockouts.
        $pdo->exec('DELETE FROM login_rate_limits WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())');
    } catch (PDOException $exception) {
        error_log('Student ID Generator login rate-limit update failed: ' . $exception->getMessage());
        header('Location: index.php?database=1');
        exit;
    }
    header('Location: index.php?invalid=1');
    exit;
}

if ((int) $user['is_active'] !== 1) {
    header('Location: index.php?inactive=1');
    exit;
}

// Clear this account's failed-password history after successful authentication.
// Keep the IP-wide bucket so successful logins cannot reset the network limit.
try {
    $clearAccountStmt = $pdo->prepare('DELETE FROM user_login_lockouts WHERE user_id = :user_id');
    $clearAccountStmt->execute(['user_id' => (int) $user['id']]);
} catch (PDOException $exception) {
    error_log('Student ID Generator account lockout cleanup failed: ' . $exception->getMessage());
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = (string) $user['username'];
$_SESSION['full_name'] = trim((string) ($user['full_name'] ?? '')) !== '' ? (string) $user['full_name'] : (string) $user['username'];
$_SESSION['role'] = (string) $user['role'];
unset($_SESSION['csrf_token']);

header('Location: dashboard.php');
exit;
