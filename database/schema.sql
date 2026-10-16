-- Run this file in phpMyAdmin or the MySQL command line.
CREATE DATABASE IF NOT EXISTS student_id_generator
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE student_id_generator;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL,
    full_name VARCHAR(160) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'head_teacher', 'finance_manager', 'administrative_staff', 'teacher') NOT NULL DEFAULT 'administrative_staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role),
    KEY idx_users_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_number VARCHAR(40) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    gender ENUM('female', 'male', 'unspecified') NOT NULL DEFAULT 'unspecified',
    date_of_birth DATE NULL,
    class_name VARCHAR(100) NOT NULL DEFAULT '',
    phone VARCHAR(40) NOT NULL DEFAULT '',
    guardian_name VARCHAR(200) NOT NULL DEFAULT '',
    guardian_phone VARCHAR(40) NOT NULL DEFAULT '',
    address VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_student_number (student_number),
    KEY idx_students_name (last_name, first_name),
    KEY idx_students_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
