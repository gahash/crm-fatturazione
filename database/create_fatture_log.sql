CREATE TABLE IF NOT EXISTS fatture_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fattura_id INT UNSIGNED NULL,
    azione VARCHAR(60) NOT NULL,
    esito ENUM('ok','error','info') NOT NULL DEFAULT 'info',
    messaggio VARCHAR(255) NOT NULL,
    utente_nome VARCHAR(100) NULL,
    utente_email VARCHAR(190) NULL,
    meta_json TEXT NULL,
    creato_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fattura_creato (fattura_id, creato_il),
    KEY idx_creato (creato_il),
    CONSTRAINT fk_fatture_log_fattura FOREIGN KEY (fattura_id) REFERENCES fatture(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
