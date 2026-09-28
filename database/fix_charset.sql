-- =================================================================
-- One-time repair: convert an already-created database's tables to
-- utf8mb4, for installs where the tables ended up on latin1 (common
-- on shared hosts like InfinityFree, where the database itself is
-- pre-created and you can't run CREATE DATABASE ... CHARACTER SET).
--
-- Run this ONCE in phpMyAdmin, with your database selected, if you
-- are seeing "?" instead of special characters (e.g. the ₱ sign)
-- after saving Settings.
--
-- Safe to run on a database that already has data — this converts
-- structure only, it does not delete anything. However, any value
-- that was ALREADY saved as "?" is permanently lost (the original
-- character was destroyed at save time) — you'll need to re-type it
-- once, after running this script.
-- =================================================================

ALTER TABLE branches        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE staff           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE users           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE duty_days       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE duty_attendance CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE periods         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE deductions      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE settings        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
