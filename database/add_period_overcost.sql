-- Adds automatic Overcost tracking at the period level.
--
-- Overcost      = Actual/Gross Cost - Original Budget
-- Overcost %    = (Overcost / Original Budget) * 100
--
-- The admin sets "Original Budget" and "Actual/Gross Cost" once per period
-- (Edit Period screen, admin-only). Overcost is then computed automatically
-- everywhere it's used -- it is no longer typed in by hand per staff member.
--
-- Run this once against your existing database:
--   mysql -u youruser -p yourdb < database/add_period_overcost.sql

ALTER TABLE periods
    ADD COLUMN original_budget DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER management_share_percent,
    ADD COLUMN actual_cost     DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER original_budget;

-- Existing periods default to 0/0, which safely computes to 0 Overcost and
-- 0% (see the empty/zero-budget handling in includes/functions.php) until
-- the admin fills in real numbers on the Edit Period screen.
--
-- UPDATE (later revision): Original Budget moved out of the periods table.
-- It is now a single default set once in Settings (setting key
-- 'default_original_budget') and applied automatically to every period --
-- the `original_budget` / `actual_cost` columns added above are no longer
-- read or written by the app; they're left in place rather than dropped so
-- this migration stays non-destructive. Optionally seed the new setting:
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('default_original_budget', '0');
