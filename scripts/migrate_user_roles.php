<?php
declare(strict_types=1);

/**
 * One-time upgrade for existing Student ID Card Generator databases.
 *
 * Run from the project directory:
 *   php scripts/migrate_user_roles.php
 *
 * This script is CLI-only and safe to re-run. Back up the database first.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this migration from the command line, not a browser.\n");
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = database_connection();

    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll();
    $columnNames = array_column($columns, 'Field');

    if (!in_array('role', $columnNames, true)) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN role ENUM('super_admin', 'head_teacher', 'finance_manager', 'administrative_staff', 'teacher')
             NOT NULL DEFAULT 'administrative_staff' AFTER password_hash"
        );
        echo "Added users.role.\n";
    } else {
        echo "Column users.role already exists; leaving it unchanged.\n";
    }

    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll();
    $columnNames = array_column($columns, 'Field');

    if (!in_array('is_active', $columnNames, true)) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role"
        );
        echo "Added users.is_active.\n";
    } else {
        echo "Column users.is_active already exists; leaving it unchanged.\n";
    }

    $indexes = $pdo->query('SHOW INDEX FROM users')->fetchAll();
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    if (!in_array('idx_users_role', $indexNames, true)) {
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_role (role)');
        echo "Added index idx_users_role.\n";
    }

    if (!in_array('idx_users_active', $indexNames, true)) {
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_active (is_active)');
        echo "Added index idx_users_active.\n";
    }

    // Upgrade any legacy role enum and map old roles to least-privilege replacements.
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','user','head_teacher','finance_manager','administrative_staff','teacher') NOT NULL DEFAULT 'administrative_staff'");
    $pdo->exec("UPDATE users SET role = 'head_teacher' WHERE role = 'admin'");
    $pdo->exec("UPDATE users SET role = 'administrative_staff' WHERE role = 'user'");
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','head_teacher','finance_manager','administrative_staff','teacher') NOT NULL DEFAULT 'administrative_staff'");

    $superAdminCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role = 'super_admin'"
    )->fetchColumn();

    if ($superAdminCount === 0) {
        $oldestUserId = $pdo->query(
            'SELECT id FROM users ORDER BY id ASC LIMIT 1'
        )->fetchColumn();

        if ($oldestUserId !== false) {
            $statement = $pdo->prepare(
                "UPDATE users SET role = 'super_admin' WHERE id = :id"
            );
            $statement->execute(['id' => $oldestUserId]);
            echo "Promoted the oldest account (ID {$oldestUserId}) to Super Admin.\n";
        } else {
            echo "No accounts exist yet; the first account setup can assign Super Admin.\n";
        }
    } else {
        echo "A Super Admin already exists; no role was changed.\n";
    }

    echo "\nMigration completed successfully. You can now sign in again.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Migration failed: " . $error->getMessage() . "\n");
    exit(1);
}
