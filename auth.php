<?php
declare(strict_types=1);

/* Shared session, authentication, authorization, and CSRF helpers. */
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('student_id_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['username']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: index.php');
        exit;
    }

    // Refresh account status and role on each protected request so deactivated accounts
    // lose access and permission changes take effect without waiting for logout.
    require_once __DIR__ . '/config/database.php';
    try {
        $pdo = database_connection();
        $statement = $pdo->prepare(
            'SELECT username, role, is_active FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $_SESSION['user_id']]);
        $account = $statement->fetch();
        if ($account) {
            // Keep the database updater accessible immediately after code deployment,
            // even before the full_name migration has been applied.
            try {
                $nameStatement = $pdo->prepare('SELECT full_name FROM users WHERE id = :id LIMIT 1');
                $nameStatement->execute(['id' => (int) $_SESSION['user_id']]);
                $account['full_name'] = (string) ($nameStatement->fetchColumn() ?: '');
            } catch (PDOException $nameException) {
                if ((int) ($nameException->errorInfo[1] ?? 0) !== 1054) throw $nameException;
                $account['full_name'] = '';
            }
        }
    } catch (PDOException $exception) {
        error_log('Student ID Generator authorization lookup failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('The account service is temporarily unavailable. Please try again later.');
    }

    if (!$account || (int) $account['is_active'] !== 1) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: index.php?inactive=1');
        exit;
    }

    $_SESSION['username'] = (string) $account['username'];
    $_SESSION['full_name'] = trim((string) ($account['full_name'] ?? '')) !== '' ? (string) $account['full_name'] : (string) $account['username'];
    $_SESSION['role'] = (string) $account['role'];
}

function current_role(): string
{
    return isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : 'user';
}

function require_roles(array $allowedRoles): void
{
    require_login();
    if (!in_array(current_role(), $allowedRoles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token_is_valid(mixed $submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}
