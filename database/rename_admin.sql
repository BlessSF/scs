-- Run this once in phpMyAdmin (with your database selected) to rename the
-- default admin account on a database you already imported. Safe to run
-- even if you've since renamed it yourself — it only affects the row with
-- username 'admin'.

UPDATE users SET full_name = 'Claribel Moquete' WHERE username = 'admin';
