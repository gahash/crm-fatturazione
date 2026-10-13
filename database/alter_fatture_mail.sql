ALTER TABLE fatture
    ADD COLUMN mail_sent_at DATETIME NULL,
    ADD COLUMN mail_status VARCHAR(20) NULL,
    ADD COLUMN mail_error TEXT NULL;
