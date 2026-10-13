-- Tabella per salvare i dati Stripe sincronizzati
CREATE TABLE IF NOT EXISTS stripe_sync (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    stripe_customer_id VARCHAR(255) NULL,
    stripe_email VARCHAR(190) NULL,
    stripe_name VARCHAR(255) NULL,
    stripe_balance INT DEFAULT 0 COMMENT 'In centesimi',
    stripe_country VARCHAR(3) NULL,
    last_charge_amount INT DEFAULT 0 COMMENT 'In centesimi',
    last_charge_date DATETIME NULL,
    payment_method VARCHAR(100) NULL,
    total_charges INT UNSIGNED DEFAULT 0,
    total_amount_charged INT UNSIGNED DEFAULT 0 COMMENT 'In centesimi',
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
    INDEX (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
