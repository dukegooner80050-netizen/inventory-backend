-- Run once on the database if you cannot run "php artisan migrate" (for example in Adminer, one statement at a time).
ALTER TABLE users ADD COLUMN requested_role VARCHAR(30) NULL;
ALTER TABLE users ADD COLUMN requested_iddept BIGINT UNSIGNED NULL;
ALTER TABLE users ADD COLUMN employee_id VARCHAR(30) NULL;
