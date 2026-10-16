-- Add a separate display name for user accounts while preserving login usernames.
-- The conditional DDL supports both older databases and fresh installs whose schema already has full_name.
SET @full_name_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'full_name'
);
SET @full_name_ddl = IF(
    @full_name_column_exists = 0,
    'ALTER TABLE users ADD COLUMN full_name VARCHAR(160) NULL AFTER username',
    'SELECT 1'
);
PREPARE full_name_stmt FROM @full_name_ddl;
EXECUTE full_name_stmt;
DEALLOCATE PREPARE full_name_stmt;

UPDATE users
SET full_name = username
WHERE full_name IS NULL OR TRIM(full_name) = '';

ALTER TABLE users
    MODIFY COLUMN full_name VARCHAR(160) NOT NULL DEFAULT '';
