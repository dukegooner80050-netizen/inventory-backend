-- Run once if you cannot run "php artisan migrate" (for example in Adminer, one statement at a time).
-- Run these only after add_requested_role.sql (the sign-up role / Employee ID columns).
ALTER TABLE users ADD COLUMN email VARCHAR(120) NULL;
ALTER TABLE users ADD UNIQUE INDEX users_email_unique (email);
ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN email_code_hash VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN email_code_expires_at TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN email_code_sent_at TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN email_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0;
