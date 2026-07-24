-- Zoho Dashboard — user auth schema
-- Run once against a fresh database:
--   mysql -u root -p < zoho-dashboard-config/schema.sql

CREATE DATABASE IF NOT EXISTS oms_zoho_dashboard
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE oms_zoho_dashboard;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NOT NULL UNIQUE,
    -- NULL = account created by an admin but the user hasn't set a password
    -- yet (they log in only after following the emailed activation/reset link).
    password_hash   VARCHAR(255) NULL DEFAULT NULL,
    role            ENUM('admin', 'staff', 'viewer') NOT NULL DEFAULT 'staff',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_resets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (user_id),
    INDEX (token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,
    ip              VARCHAR(45) NOT NULL,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (email, attempted_at),
    INDEX (ip, attempted_at)
) ENGINE=InnoDB;
