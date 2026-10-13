-- Aggiunge colonne per integrazione Fatture in Cloud e Email
-- Script idempotente: eseguilo piu volte senza errori su colonne/indici duplicati.

SET @db_name := DATABASE();

-- Colonne per Fatture in Cloud
SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'fic_document_id') = 0,
	'ALTER TABLE fatture ADD COLUMN fic_document_id VARCHAR(255) NULL',
	'SELECT "fic_document_id gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'fic_status') = 0,
	'ALTER TABLE fatture ADD COLUMN fic_status VARCHAR(50) NULL',
	'SELECT "fic_status gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'fic_pdf_url') = 0,
	'ALTER TABLE fatture ADD COLUMN fic_pdf_url TEXT NULL',
	'SELECT "fic_pdf_url gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'fic_synced_at') = 0,
	'ALTER TABLE fatture ADD COLUMN fic_synced_at DATETIME NULL',
	'SELECT "fic_synced_at gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Colonne per invio fattura elettronica (SDI)
SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'ei_sent_at') = 0,
	'ALTER TABLE fatture ADD COLUMN ei_sent_at DATETIME NULL',
	'SELECT "ei_sent_at gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'ei_status') = 0,
	'ALTER TABLE fatture ADD COLUMN ei_status VARCHAR(40) NULL',
	'SELECT "ei_status gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'ei_error') = 0,
	'ALTER TABLE fatture ADD COLUMN ei_error TEXT NULL',
	'SELECT "ei_error gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Colonne per gestione email
SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'mail_sent_at') = 0,
	'ALTER TABLE fatture ADD COLUMN mail_sent_at DATETIME NULL',
	'SELECT "mail_sent_at gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'mail_status') = 0,
	'ALTER TABLE fatture ADD COLUMN mail_status VARCHAR(20) NULL',
	'SELECT "mail_status gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND COLUMN_NAME = 'mail_error') = 0,
	'ALTER TABLE fatture ADD COLUMN mail_error TEXT NULL',
	'SELECT "mail_error gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indici per velocizzare le ricerche
SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND INDEX_NAME = 'idx_fic_document_id') = 0,
	'ALTER TABLE fatture ADD INDEX idx_fic_document_id (fic_document_id)',
	'SELECT "idx_fic_document_id gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND INDEX_NAME = 'idx_fic_synced_at') = 0,
	'ALTER TABLE fatture ADD INDEX idx_fic_synced_at (fic_synced_at)',
	'SELECT "idx_fic_synced_at gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND INDEX_NAME = 'idx_ei_status') = 0,
	'ALTER TABLE fatture ADD INDEX idx_ei_status (ei_status)',
	'SELECT "idx_ei_status gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF (
	(SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
	 WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'fatture' AND INDEX_NAME = 'idx_mail_status') = 0,
	'ALTER TABLE fatture ADD INDEX idx_mail_status (mail_status)',
	'SELECT "idx_mail_status gia presente"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
