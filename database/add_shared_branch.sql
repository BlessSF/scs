-- =================================================================
-- Migration: add a "Shared" branch for owners / other admins.
-- Run this once in phpMyAdmin (SQL tab) against your live database.
-- Safe to run more than once.
--
-- What this does:
--   Creates a branch named "Shared" that isn't a real operating
--   location -- it's just a home for owner/admin staff who shouldn't
--   appear in any single branch's day-to-day views.
--
--   Add owner/admin staff to it from the app: go to the new "Shared"
--   link in the top nav (admin only) -> "+ Add Staff to Shared", same
--   Staff form as any other staff member, but be sure to set
--   "Hidden (Owner / Admin)" to Yes on their record.
--
--   Requires database/add_staff_hidden.sql to already be applied
--   (adds staff.is_hidden), and includes/functions.php + header.php
--   from this update (adds the shared_branch_id() helper, the "Shared"
--   nav link, and the fix that lets hidden staff's cut count across
--   every branch's periods, not just their own).
-- =================================================================

INSERT INTO branches (name)
SELECT 'Shared'
WHERE NOT EXISTS (SELECT 1 FROM branches WHERE name = 'Shared');

-- Done. Log in as admin -- you should now see a "Shared" link in the
-- top nav. Use it to add the owner and other admins as staff there,
-- marking each one "Hidden (Owner / Admin)" on their staff record.
