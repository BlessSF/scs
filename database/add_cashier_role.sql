-- ---------------------------------------------------------------
-- Adds the "cashier" role to an existing database.
-- Run this once in phpMyAdmin (SQL tab) against your live database.
--
-- The cashier role can access: Dashboard (limited), Duty Entry,
-- Periods & Summaries, and Staff (add/edit + password reset).
-- It cannot access: Branches, Settings, or Deductions.
-- ---------------------------------------------------------------

-- 1) Widen the role column to allow 'cashier' in addition to 'admin'/'staff'
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','staff','cashier') NOT NULL DEFAULT 'staff';

-- 2) Create the cashier login account.
--    Username: cashier
--    Password: cashier123
--    This hash was generated to match PHP's password_hash()/password_verify(),
--    the same way every other account in this system is stored.
INSERT INTO users (username, password, role, staff_id, full_name, is_active)
VALUES (
    'cashier',
    '$2b$10$P/TUfdMHlKJBFxUOVTP9lOjodprk9/lrRNI9ozRo2PEDhmiR0QqmK',
    'cashier',
    NULL,
    'Cashier',
    1
)
ON DUPLICATE KEY UPDATE
    password  = VALUES(password),
    role      = VALUES(role),
    full_name = VALUES(full_name),
    is_active = VALUES(is_active);
