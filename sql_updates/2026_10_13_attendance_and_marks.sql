-- Attendance and assessment records for the school staff roles.
CREATE TABLE IF NOT EXISTS student_attendance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_year_id BIGINT UNSIGNED NOT NULL,
  class_section_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('present','absent','late','excused') NOT NULL DEFAULT 'present',
  notes VARCHAR(500) NOT NULL DEFAULT '',
  recorded_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_student_date (student_id, attendance_date),
  KEY idx_attendance_section_date (class_section_id, attendance_date),
  CONSTRAINT fk_attendance_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_attendance_section FOREIGN KEY (class_section_id) REFERENCES class_sections(id),
  CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_attendance_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_marks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  school_year_id BIGINT UNSIGNED NOT NULL,
  class_section_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  subject_name VARCHAR(120) NOT NULL,
  assessment_name VARCHAR(120) NOT NULL,
  score DECIMAL(7,2) NOT NULL,
  max_score DECIMAL(7,2) NOT NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  entered_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_assessment (school_year_id, class_section_id, student_id, subject_name, assessment_name),
  KEY idx_marks_section_subject (class_section_id, subject_name, assessment_name),
  CONSTRAINT fk_marks_year FOREIGN KEY (school_year_id) REFERENCES school_years(id),
  CONSTRAINT fk_marks_section FOREIGN KEY (class_section_id) REFERENCES class_sections(id),
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id),
  CONSTRAINT fk_marks_enterer FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
