-- =================================================================
-- Migration: add sub-branch support (e.g. "H Bar" as a sub-branch of "H")
-- Run this once in phpMyAdmin (SQL tab) against your live database.
-- Safe to run once. Existing branches are unaffected (they stay top-level).
--
-- What this does:
--   Adds a `parent_branch_id` column to `branches`. When it's set, that
--   branch is a sub-branch of another branch: same as any other branch
--   (its own staff, duty calendar, periods, and login account) but shown
--   nested under its parent on the Branches page, for grouping things
--   like separate bar sales under the branch they belong to.
-- =================================================================

ALTER TABLE branches
    ADD COLUMN parent_branch_id INT DEFAULT NULL AFTER id,
    ADD CONSTRAINT fk_branch_parent FOREIGN KEY (parent_branch_id) REFERENCES branches(id);

-- Done. Go to Branches in the admin nav — every branch row now has an
-- "+ Sub-Branch" action to create one nested under it.
