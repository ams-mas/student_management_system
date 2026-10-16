-- Tuition fees are configured by school year, term/period, and grade range.
CREATE TABLE IF NOT EXISTS tuition_fee_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    school_year_id BIGINT UNSIGNED NOT NULL,
    period_name VARCHAR(100) NOT NULL,
    grade_from TINYINT UNSIGNED NOT NULL,
    grade_to TINYINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    due_date DATE NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tuition_fee_rule (school_year_id, period_name, grade_from, grade_to),
    KEY idx_tuition_fee_lookup (school_year_id, grade_from, grade_to),
    CONSTRAINT fk_tuition_fee_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
    CONSTRAINT chk_tuition_fee_grades CHECK (grade_from BETWEEN 1 AND 12 AND grade_to BETWEEN grade_from AND 12),
    CONSTRAINT chk_tuition_fee_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_fee_charges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    school_year_id BIGINT UNSIGNED NOT NULL,
    fee_rule_id BIGINT UNSIGNED NOT NULL,
    period_name VARCHAR(100) NOT NULL,
    amount_due DECIMAL(12,2) NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_fee_charge (student_id, school_year_id, fee_rule_id),
    KEY idx_student_fee_charge_student (student_id, school_year_id),
    CONSTRAINT fk_student_fee_charge_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_fee_charge_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_fee_charge_rule FOREIGN KEY (fee_rule_id) REFERENCES tuition_fee_rules(id) ON DELETE RESTRICT,
    CONSTRAINT chk_student_fee_amount CHECK (amount_due >= 0 AND discount_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_fee_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    charge_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    payment_method ENUM('cash','bank','other') NOT NULL DEFAULT 'cash',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    received_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_receipt_number (receipt_number),
    KEY idx_fee_payment_charge (charge_id, received_at),
    CONSTRAINT fk_fee_payment_charge FOREIGN KEY (charge_id) REFERENCES student_fee_charges(id) ON DELETE CASCADE,
    CONSTRAINT fk_fee_payment_user FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_fee_payment_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
