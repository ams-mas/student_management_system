# سامانه مدیریت دانش‌آموزان | Student Management System

A Persian, right-to-left student management application built with PHP, MySQL, HTML, CSS, and JavaScript for local development with XAMPP.

## Current features

- Secure Persian login and session-based access.
- Role-based accounts: Super Admin, Admin, and User.
- Student records: add, view, search, edit, and activate/deactivate records.
- Student fields: student number, name, gender, date of birth, class/grade, contact details, guardian details, and address.
- Admin dashboard and a right-side RTL navigation menu.
- User management for administrators.
- PDO prepared statements, password hashing, output escaping, and CSRF protection for state-changing forms.
- Existing ID-card styling can be reused later to print student cards from stored student records.

## Technology

- PHP 8+
- MySQL / MariaDB
- HTML5, CSS3, JavaScript
- XAMPP and phpMyAdmin
- Persian Yekan font included in the repository

## Project structure

```text
student-id-generator/
├── index.php
├── login.php
├── logout.php
├── auth.php
├── dashboard.php
├── students.php
├── users.php
├── config/database.php
├── database/schema.sql
├── database/migrations/
└── assets/css/style.css
```

## Run locally with XAMPP

1. Start Apache and MySQL in XAMPP.
2. Open phpMyAdmin at http://localhost/phpmyadmin.
3. For a new database, import `database/schema.sql`.
4. For an existing installation, first back up the `student_id_generator` database, then import:
   - `database/migrations/2026_10_12_add_user_roles.sql` only if you have not already applied it.
   - `database/migrations/2026_10_12_create_students_table.sql`.
5. If you need to create the first account, run from Command Prompt:
   ```cmd
   cd /d E:\xampp\htdocs\student-id-generator
   E:\xampp\php\php.exe scripts\create_admin.php
   ```
6. Visit http://localhost/student-id-generator/ and sign in.
7. Open **مدیریت دانش‌آموزان** from the right-side menu.

If XAMPP is installed in another folder, change the PHP executable path accordingly.

## Permissions

- **Super Admin / Admin:** add and edit student records, activate/deactivate records, and manage users according to the existing role rules.
- **User:** can view and search student records but cannot change them.
- Access restrictions are enforced in PHP, not only by hiding buttons.

## Important notes

- This project is currently an initial student registry, not yet a complete school ERP. Attendance, grades, classes, fees, and reports can be added as separate modules.
- Back up the database before running migrations.
- Use fictional student information while developing. Do not commit real student records, student photos, passwords, or database secrets.
- Before public deployment, use HTTPS, a dedicated database account, persistent login rate limiting, audit logging, and production server configuration.



## School-year and class management

The school administration page is `school.php`. It supports school-year setup, teacher records, grades 1–12 with named sections, teacher assignment to a section, student enrollment, same-year transfers, promotion to the next grade in a different school year, current enrollment lists, and an enrollment-change history.

### Upgrade an existing XAMPP database

1. Back up the `student_id_generator` database in phpMyAdmin.
2. Open phpMyAdmin and select the existing database.
3. Import `database/migrations/2026_10_12_school_years_sections_enrollments.sql`.
4. Pull the latest code:
   ```bat
   cd /d E:\xampp\htdocs\student-id-generator
   git pull origin main
   ```
5. Sign in as an administrator and open `http://localhost/student-id-generator/school.php`.

Run the migration only once on the existing database. It adds tables and does not delete student or user records. First create the school years, sections and teachers, then enroll students. Promotion must target a section exactly one grade higher in a different, open school year; grade 12 students cannot be promoted. Test with a non-production database before using real student records.

**Current limitation:** teacher records are managed separately from login accounts; connecting a teacher record to a login account is optional at the database level and teacher-specific access restrictions still need a later implementation. Promotion is an administrative action and should be performed only after results/eligibility have been confirmed.


## Shared Persian calendar rule

All date fields in this project must use the shared Afghanistan Solar Hijri calendar (`assets/js/persian-calendar.js` and `assets/css/persian-calendar.css`). Add `data-persian-date` to each `<input type="date">` that should use it, and include the shared stylesheet and script on that page. Keep calendar layout, dropdowns, padding, and popup behavior in these shared assets so fixes apply consistently to every page. Display dates with the Afghanistan month names: حمل، ثور، جوزا، سرطان، اسد، سنبله، میزان، عقرب، قوس، جدی، دلو، حوت. Store dates in the database as Gregorian `YYYY-MM-DD`; convert only for display and input.
