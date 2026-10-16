-- Add the father's and grandfather's names to Afghan student records.
USE student_id_generator;
ALTER TABLE students
  ADD COLUMN father_name VARCHAR(100) NULL AFTER last_name,
  ADD COLUMN grandfather_name VARCHAR(100) NULL AFTER father_name;
