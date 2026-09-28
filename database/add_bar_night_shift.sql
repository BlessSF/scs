-- Adds support for recording a separate "Bar Night" service charge entry
-- (in addition to the Regular entry) on the same day. Only Friday and
-- Saturday show the Bar Night section on the entry form, but the column
-- itself has no day-of-week restriction at the database level.
--
-- Run this once against your existing database:
--   mysql -u youruser -p yourdb < database/add_bar_night_shift.sql

ALTER TABLE duty_days
    ADD COLUMN shift ENUM('regular','bar_night') NOT NULL DEFAULT 'regular' AFTER duty_date;

-- Replace the old (branch_id, duty_date) uniqueness with one that also
-- includes the shift, so a branch can have both a Regular row and a
-- Bar Night row for the same date.
ALTER TABLE duty_days
    DROP INDEX uniq_branch_date,
    ADD UNIQUE KEY uniq_branch_date_shift (branch_id, duty_date, shift);

-- Done. Existing rows are all treated as 'regular' automatically via the
-- DEFAULT above -- no data is changed or lost.
