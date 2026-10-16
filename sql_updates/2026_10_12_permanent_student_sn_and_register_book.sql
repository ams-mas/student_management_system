-- Permanent student S/N uses the existing immutable auto-increment students.id.
-- Preserve the school's old کتاب اساس number separately for students registered before this system.
USE student_id_generator;
ALTER TABLE students
  ADD COLUMN register_book_number VARCHAR(100) NULL AFTER student_number,
  ADD KEY idx_students_register_book_number (register_book_number);
