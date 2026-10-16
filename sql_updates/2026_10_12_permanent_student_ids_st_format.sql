-- Standardize permanent student IDs as ST-0001, ST-0002, ...
-- The numeric suffix is the immutable students.id; yearly enrollment remains in student_enrollments.
-- First move all existing values aside to avoid collisions with already-existing ST-xxxx values.
UPDATE students
SET student_number = CONCAT('TMP-', id, '-', SUBSTRING(REPLACE(UUID(), '-', ''), 1, 8));

-- Assign the permanent human-readable ID based on the permanent student record ID.
UPDATE students
SET student_number = CONCAT('ST-', LPAD(id, 4, '0'));
