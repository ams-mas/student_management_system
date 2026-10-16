CREATE TABLE IF NOT EXISTS login_rate_limits (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_login_rate_limits_updated_at (updated_at),
    KEY idx_login_rate_limits_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
