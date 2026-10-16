-- Expand roles, map legacy accounts to least-privilege roles, then remove legacy role names.
-- Existing super_admin accounts remain unchanged. Back up the database before applying.
ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','admin','user','head_teacher','finance_manager','administrative_staff','teacher') NOT NULL DEFAULT 'administrative_staff';
UPDATE users SET role = 'head_teacher' WHERE role = 'admin';
UPDATE users SET role = 'administrative_staff' WHERE role = 'user';
ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','head_teacher','finance_manager','administrative_staff','teacher') NOT NULL DEFAULT 'administrative_staff';

-- Keep the system administrable if a legacy database had no super admin account.
SET @active_super_admin_count = (SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = 1);
SET @first_user_id = (SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1);
UPDATE users SET role = 'super_admin' WHERE id = @first_user_id AND @active_super_admin_count = 0;
