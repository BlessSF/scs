-- Adds is_hidden flag to staff table.
-- Hidden staff (owners/admin) are auto-added to every duty day
-- but never shown in branch-facing views.
--
-- Run once in phpMyAdmin SQL tab:

ALTER TABLE staff
    ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
