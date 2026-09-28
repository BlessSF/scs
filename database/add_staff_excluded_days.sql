-- Adds excluded_days to staff so hidden (owner/admin) staff can be
-- automatically skipped on certain days of the week.
-- Value is a comma-separated list of day numbers: 0=Sunday, 1=Monday, ... 6=Saturday
-- Empty string or NULL means "no exclusions — count every day".

ALTER TABLE staff
    ADD COLUMN excluded_days VARCHAR(20) NOT NULL DEFAULT '' AFTER is_hidden;
