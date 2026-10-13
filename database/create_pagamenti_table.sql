-- Esegui questo script sul database dell'applicazione
-- per creare la tabella pagamenti

CREATE TABLE IF NOT EXISTS pagamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_id VARCHAR(255) UNIQUE NOT NULL,
    cliente_id INT UNSIGNED NULL,
    importo INT NOT NULL COMMENT 'Importo in centesimi',
    valuta VARCHAR(3) DEFAULT 'EUR',
    stato VARCHAR(50) DEFAULT 'pending',
    metodo VARCHAR(100) NULL,
    descrizione TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
