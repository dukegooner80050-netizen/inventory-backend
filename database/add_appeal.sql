-- Run once if you cannot run "php artisan migrate" (for example in Adminer, one statement at a time).
ALTER TABLE request ADD COLUMN appeal_message VARCHAR(100) NULL;
ALTER TABLE request ADD COLUMN appealed_at TIMESTAMP NULL;
ALTER TABLE request ADD COLUMN appeal_decision VARCHAR(20) NULL;
ALTER TABLE request ADD COLUMN appeal_reply VARCHAR(100) NULL;
ALTER TABLE request ADD COLUMN appeal_decided_by BIGINT UNSIGNED NULL;
ALTER TABLE request ADD COLUMN appeal_decided_at TIMESTAMP NULL;
