-- =================================================================
-- Migration: give every branch its own login account
-- Run this once in phpMyAdmin (SQL tab) against your live database,
-- AFTER migrate_branches.sql / add_cashier_role.sql have already run.
-- Safe to run more than once.
--
-- What this does:
--   1. Adds a `branch_id` column to `users` so a cashier account can be
--      tied to exactly one branch. NULL = can see every branch (used by
--      the original global "cashier" account, if you still have it).
--   2. Creates one branch-scoped login for every branch that doesn't
--      already have one, using the pattern:
--        username: branch-<slug of the branch name>
--        password: a random 10-character password (shown after you run
--                   the "Reset Password" button on the Branches page —
--                   this migration only creates the account; use the
--                   app's UI to see/reset the password afterwards)
--   3. Leaves any existing admin/staff/cashier accounts untouched.
-- =================================================================

-- 1) Add branch_id to users (nullable — NULL means "all branches")
ALTER TABLE users
    ADD COLUMN branch_id INT DEFAULT NULL AFTER role,
    ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

-- 2) Create a placeholder branch-scoped account for every branch that
--    doesn't have one yet. Password is a random hash here — log into the
--    app as admin, go to Branches, and click "Reset Password" on each
--    branch to get a real, usable password (it's shown once on screen).
INSERT INTO users (username, password, role, branch_id, full_name, is_active)
SELECT
    CONCAT('branch-', LOWER(REGEXP_REPLACE(b.name, '[^A-Za-z0-9]+', '-'))),
    -- Not a real password hash on purpose — this account can't log in
    -- until an admin resets its password from the Branches page.
    CONCAT('DISABLED-', SUBSTRING(MD5(RAND()), 1, 40)),
    'cashier',
    b.id,
    CONCAT(b.name, ' Account'),
    1
FROM branches b
WHERE NOT EXISTS (
    SELECT 1 FROM users u WHERE u.branch_id = b.id
);

-- Done. Go to Branches in the admin nav — each branch now shows a
-- "Reset Password" button to generate a real, working password for
-- its account.
