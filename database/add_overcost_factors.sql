-- Adds the single overcost rate to settings.
--
-- Overcost per employee = Employee Gross × overcost_rate
--
-- Default: 0.11112393 (= 0.33336833 × 0.3333368)
--
-- Run once:
--   mysql -u youruser -p yourdb < database/add_overcost_factors.sql

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('overcost_rate', '0.11112393');
