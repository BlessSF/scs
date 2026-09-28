-- Service Charge Management System
-- Database schema (MySQL / MariaDB)

SET NAMES utf8mb4;

-- NOTE: no CREATE DATABASE / USE here on purpose — shared hosting accounts
-- (like the one this error came from) usually only grant access to a
-- database that's already been created for you in the host's control
-- panel. Just select that database in phpMyAdmin first, then import this
-- file — it will create the tables inside whichever database is selected.

-- ---------------------------------------------------------------
-- Branches
-- ---------------------------------------------------------------
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_branch_id INT DEFAULT NULL,   -- set for a sub-branch (e.g. "H Bar" under "H"); NULL = top-level branch
    name VARCHAR(120) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_branch_parent FOREIGN KEY (parent_branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO branches (name) VALUES ('Main Branch');

-- ---------------------------------------------------------------
-- Staff members
-- ---------------------------------------------------------------
CREATE TABLE staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    full_name VARCHAR(150) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'regular',   -- regular, not_regular, resigned, probationary
    remarks VARCHAR(255) DEFAULT NULL,
    date_added DATE NOT NULL DEFAULT (CURRENT_DATE),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_staff_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Users (login accounts) - admin or staff
-- ---------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,          -- password_hash()
    role ENUM('admin','staff','cashier') NOT NULL DEFAULT 'staff',
    branch_id INT DEFAULT NULL,               -- for role = cashier: which single branch this login can see. NULL = all branches.
    staff_id INT DEFAULT NULL,                -- linked staff record, required when role = staff
    full_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Duty days - one row per calendar day that has a recorded
-- total service charge amount to be split among staff on duty
-- ---------------------------------------------------------------
CREATE TABLE duty_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    duty_date DATE NOT NULL,
    shift ENUM('regular','bar_night') NOT NULL DEFAULT 'regular',   -- 'bar_night' only used for Fri/Sat entries recorded separately from the regular day
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_branch_date_shift (branch_id, duty_date, shift),
    CONSTRAINT fk_dd_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Attendance - which staff were on duty on a given day
-- (the day's total_amount is split evenly among these staff)
-- ---------------------------------------------------------------
CREATE TABLE duty_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    duty_day_id INT NOT NULL,
    staff_id INT NOT NULL,
    is_leader TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_day_staff (duty_day_id, staff_id),
    CONSTRAINT fk_da_day FOREIGN KEY (duty_day_id) REFERENCES duty_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_da_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Periods - a payout cycle (e.g. a month or a quarter).
-- Summaries & slips are generated per period.
-- ---------------------------------------------------------------
CREATE TABLE periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,               -- e.g. "APRIL 2026" or "APR 1 - JUN 30, 2026"
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    management_share_percent DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    original_budget DECIMAL(12,2) NOT NULL DEFAULT 0, -- unused; Original Budget is now a single default in `settings` (default_original_budget), applied to every period
    actual_cost DECIMAL(12,2) NOT NULL DEFAULT 0,      -- unused; Actual/Gross Cost is now auto-derived from Gross Service Charge, not stored here
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_period_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- Deductions recorded per staff, per period
-- ---------------------------------------------------------------
CREATE TABLE deductions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,
    staff_id INT NOT NULL,
    damages_charges DECIMAL(12,2) NOT NULL DEFAULT 0,
    cash_advance DECIMAL(12,2) NOT NULL DEFAULT 0,
    overcost_cogs DECIMAL(12,2) NOT NULL DEFAULT 0,
    remarks VARCHAR(255) DEFAULT NULL,
    received_by VARCHAR(150) DEFAULT NULL,
    UNIQUE KEY uniq_period_staff (period_id, staff_id),
    CONSTRAINT fk_ded_period FOREIGN KEY (period_id) REFERENCES periods(id) ON DELETE CASCADE,
    CONSTRAINT fk_ded_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- App settings (key/value)
-- ---------------------------------------------------------------
CREATE TABLE settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
    ('company_name', 'STELLA'),
    ('currency_symbol', '₱'),
    ('default_management_share_percent', '20'),
    ('default_original_budget', '0'),
    ('approved_by_name', '');

-- ---------------------------------------------------------------
-- Default admin account -> username: admin / password: admin123
-- (hash generated for 'admin123' with PHP password_hash/BCRYPT)
-- ---------------------------------------------------------------
INSERT INTO users (username, password, role, staff_id, full_name) VALUES
    ('admin', '$2b$10$SuPp7zrhlBpF1NER3gE4E.AQEQ/OSPfZM61mxp2ALjElPAAqqnGLi', 'admin', NULL, 'Claribel Moquete');

-- ---------------------------------------------------------------
-- Default login for "Main Branch" -> username: branch-main-branch
-- password: main123 (change this from the Branches page after login)
-- ---------------------------------------------------------------
INSERT INTO users (username, password, role, branch_id, staff_id, full_name) VALUES
    ('branch-main-branch', '$2b$10$Z5b.D/o339lESinrmYnwd.kJKuZdrquVrtRcdi8kPXOb8XsjX5T96', 'cashier', 1, NULL, 'Main Branch Account');
