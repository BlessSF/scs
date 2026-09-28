-- =================================================================
-- Migration: add multi-branch support to an EXISTING installation
-- Run this once in phpMyAdmin (or `mysql -u root service_charge_db < migrate_branches.sql`)
-- if you already have data and don't want to re-import schema.sql from scratch.
-- Safe to run only once. All existing data is kept and assigned to "Main Branch".
-- NOTE: no USE statement here on purpose — just select your database in
-- phpMyAdmin first, then run this. Shared hosting accounts usually can't
-- switch databases from inside a query.
-- =================================================================

-- 1. Create branches table (if not already there)
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    address VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO branches (id, name)
SELECT 1, 'Main Branch'
WHERE NOT EXISTS (SELECT 1 FROM branches WHERE id = 1);

-- 2. Add branch_id to staff
ALTER TABLE staff
    ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_staff_branch FOREIGN KEY (branch_id) REFERENCES branches(id);

-- 3. Add branch_id to duty_days, replace the old unique(duty_date) with unique(branch_id, duty_date)
ALTER TABLE duty_days
    ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_dd_branch FOREIGN KEY (branch_id) REFERENCES branches(id);

ALTER TABLE duty_days DROP INDEX duty_date;
ALTER TABLE duty_days ADD UNIQUE KEY uniq_branch_date (branch_id, duty_date);

-- 4. Add branch_id to periods
ALTER TABLE periods
    ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_period_branch FOREIGN KEY (branch_id) REFERENCES branches(id);

-- Done. Everything that existed before is now under "Main Branch" (id 1).
-- Go to Branches in the admin nav to rename it or add more branches.
