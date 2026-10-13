-- =============================================================
-- CRM Fatturazione - Dati di esempio (TUTTI FITTIZI)
-- Da usare solo in ambiente di sviluppo, dopo sql/schema.sql.
-- =============================================================

SET NAMES utf8mb4;

-- Utente amministratore di sviluppo
-- Email: admin@example.com  -  Password: admin-demo-123 (cambiarla subito)
INSERT INTO `utenti` (`id`, `nome`, `email`, `ruolo`, `password_hash`, `attivo`) VALUES
(1, 'Admin Demo', 'admin@example.com', 'admin', SHA2('admin-demo-123', 256), 1);

INSERT INTO `clienti` (`id`, `nome`, `email`, `telefono`, `azienda`, `indirizzo`, `piva`, `codice_sdi`, `pec`, `note`) VALUES
(1, 'Mario Rossi', 'mario.rossi@example.com', '+39 000 0000001', 'Rossi Demo S.r.l.', 'Via Esempio 1, 00100 Roma RM', '00000000001', '0000000', 'rossi.demo@pec.example.com', 'Cliente di esempio'),
(2, 'Laura Bianchi', 'laura.bianchi@example.com', '+39 000 0000002', 'Bianchi Demo S.n.c.', 'Via Prova 2, 20100 Milano MI', '00000000002', '0000000', NULL, NULL),
(3, 'Giuseppe Verdi', 'giuseppe.verdi@example.com', NULL, NULL, 'Piazza Test 3, 10100 Torino TO', NULL, NULL, NULL, 'Privato');

INSERT INTO `fatture` (`id`, `cliente_id`, `numero`, `data_emissione`, `data_scadenza`, `stato`, `totale`, `note`, `document_type`) VALUES
(1, 1, '1/2026', '2026-01-15', '2026-02-14', 'pagata', 122.00, 'Fattura di esempio', 'invoice'),
(2, 2, '2/2026', '2026-02-01', '2026-03-03', 'emessa', 244.00, NULL, 'invoice'),
(3, 3, 'ORD-1', '2026-02-10', NULL, 'bozza', 61.00, 'Ordine di esempio', 'order');

INSERT INTO `fatture_righe` (`fattura_id`, `descrizione`, `quantita`, `prezzo_unitario`, `iva`, `totale_riga`) VALUES
(1, 'Servizio di consulenza (esempio)', 1.00, 100.00, 22.00, 122.00),
(2, 'Abbonamento mensile (esempio)', 2.00, 100.00, 22.00, 244.00),
(3, 'Prodotto di prova', 1.00, 50.00, 22.00, 61.00);

INSERT INTO `lead` (`nome`, `cognome`, `email`, `telefono`, `citta`, `esito`, `source`) VALUES
('Anna', 'Esempio', 'anna.esempio@example.com', '+39 000 0000003', 'Bologna', 'da ricontattare', 'manuale');
