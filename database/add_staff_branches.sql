-- =================================================================
-- Migration: allow one staff member to be assigned to more than one
-- branch (e.g. someone who works both "Hero Breakfast To Bar" and
-- its sub-branch "H-Bar").
-- Run this once in phpMyAdmin (SQL tab) against your live database.
-- Safe to run once.
--
-- What this does:
--   Adds a `staff_branches` table (staff_id, branch_id). A staff
--   member's `staff.branch_id` column stays as their "home" branch
--   (used for their login, periods, and payroll slip), but they can
--   now also be checked into any other branch's staff list and duty
--   entry via this table — without duplicating their record.
--
--   Existing staff automatically get a row here for their current
--   home branch, so nothing changes until you assign someone to an
--   additional branch from the Staff form.
-- =================================================================

CREATE TABLE IF NOT EXISTS staff_branches (
    staff_id INT NOT NULL,
    branch_id INT NOT NULL,
    PRIMARY KEY (staff_id, branch_id),
    CONSTRAINT fk_sb_staff  FOREIGN KEY (staff_id)  REFERENCES staff(id)    ON DELETE CASCADE,
    CONSTRAINT fk_sb_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: every existing staff member's current branch counts as an assignment.
INSERT IGNORE INTO staff_branches (staff_id, branch_id)
SELECT id, branch_id FROM staff;

-- Done. Go to Staff → Edit on any staff member — there's now an
-- "Also assign to these branches" checklist under the branch field.
