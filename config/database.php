<?php
declare(strict_types=1);

/**
 * Create a PDO connection to the application's MySQL database.
 *
 * Environment variables supported: DB_HOST, DB_NAME, DB_USER, DB_PASS.
 * The defaults below are for a fresh local XAMPP installation only.
 * Set environment variables and use a dedicated database user in production.
 */
function database_connection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $database = getenv('DB_NAME') ?: 'student_id_generator';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS');

    if ($password === false) {
        $password = '';
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
