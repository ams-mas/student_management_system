-- School years, grades/sections, teacher ownership, and historical student placement.
-- Back up the database before applying this migration.
USE student_id_generator;

CREATE TABLE IF NOT EXISTS school_years (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(40) NOT NULL,
  starts_on DATE NULL,
  ends_on DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_school_year_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teachers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(200) NOT NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  user_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teacher_user (user_id),
  KEY idx_teacher_name (full_name),
  CONSTRAINT fk_teacher_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_year_id BIGINT UNSIGNED NOT NULL,
  grade TINYINT UNSIGNED NOT NULL,
  section_name VARCHAR(40) NOT NULL,
  teacher_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_year_grade_section (school_year_id, grade, section_name),
  KEY idx_section_teacher (teacher_id),
  CONSTRAINT fk_section_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_section_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
  CONSTRAINT chk_section_grade CHECK (grade BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  school_year_id BIGINT UNSIGNED NOT NULL,
  class_section_id BIGINT UNSIGNED NOT NULL,
  status ENUM('enrolled','transferred','promoted','completed','withdrawn') NOT NULL DEFAULT 'enrolled',
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY idx_enrollment_section (class_section_id, is_current),
  KEY idx_enrollment_year (school_year_id, is_current),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_enrollment_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_enrollment_section FOREIGN KEY (class_section_id) REFERENCES class_sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_enrollment_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  school_year_id BIGINT UNSIGNED NOT NULL,
  from_section_id BIGINT UNSIGNED NULL,
  to_section_id BIGINT UNSIGNED NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  change_type ENUM('assigned','transferred','promoted') NOT NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_history_student (student_id, changed_at),
  CONSTRAINT fk_history_enrollment FOREIGN KEY (enrollment_id) REFERENCES student_enrollments(id),
  CONSTRAINT fk_history_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_history_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_history_from_section FOREIGN KEY (from_section_id) REFERENCES class_sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_history_to_section FOREIGN KEY (to_section_id) REFERENCES class_sections(id),
  CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
