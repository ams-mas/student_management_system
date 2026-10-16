-- One set of identity/contact settings per installation (one school per deployment).
CREATE TABLE IF NOT EXISTS school_configuration (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    school_name VARCHAR(180) NOT NULL,
    school_address VARCHAR(500) NULL,
    school_email VARCHAR(190) NULL,
    school_phone_1 VARCHAR(50) NULL,
    school_phone_2 VARCHAR(50) NULL,
    logo_path VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO school_configuration (id, school_name) VALUES (1, 'نام مدرسه');
