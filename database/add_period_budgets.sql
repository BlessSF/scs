-- Period budgets table
-- Stores the admin-entered budget per period.
-- Run once in phpMyAdmin SQL tab:

CREATE TABLE IF NOT EXISTS period_budgets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id  INT UNSIGNED NOT NULL UNIQUE,
    budget     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
