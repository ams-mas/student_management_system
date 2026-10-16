-- Idempotent migration for existing databases.
-- Adds role/status columns and indexes only when they are missing.
-- Safe to retry if a previous run stopped partway through.

SET @has_role = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
);
SET @sql = IF(
    @has_role = 0,
    "ALTER TABLE users ADD COLUMN role ENUM('super_admin', 'admin', 'user') NOT NULL DEFAULT 'admin' AFTER password_hash",
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_is_active = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_active'
);
SET @sql = IF(
    @has_is_active = 0,
    'ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_role_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_role'
);
SET @sql = IF(
    @has_role_index = 0,
    'ALTER TABLE users ADD KEY idx_users_role (role)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @has_active_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_active'
);
SET @sql = IF(
    @has_active_index = 0,
    'ALTER TABLE users ADD KEY idx_users_active (is_active)',
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Promote the oldest account only when no active super admin exists.
SET @has_super_admin = (
    SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1
);
SET @sql = IF(
    @has_super_admin = 0,
    "UPDATE users SET role = 'super_admin' WHERE id = (SELECT id FROM (SELECT id FROM users ORDER BY id ASC LIMIT 1) AS first_user)",
    'SELECT 1'
);
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
