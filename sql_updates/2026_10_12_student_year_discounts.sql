-- Store each student's tuition discount separately for each school year.
-- Type "percent" is a percentage; type "amount" is a fixed amount in the school's currency.
CREATE TABLE IF NOT EXISTS student_year_discounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    school_year_id BIGINT UNSIGNED NOT NULL,
    discount_type ENUM('percent', 'amount') NOT NULL,
    discount_value DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_student_year_discount (student_id, school_year_id),
    KEY idx_student_year_discounts_year (school_year_id),
    CONSTRAINT fk_student_year_discounts_student
        FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
    CONSTRAINT fk_student_year_discounts_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years (id) ON DELETE CASCADE,
    CONSTRAINT chk_student_year_discount_value
        CHECK (discount_value > 0),
    CONSTRAINT chk_student_year_discount_percent
        CHECK (discount_type <> 'percent' OR discount_value <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
