-- =============================================================
-- CRM Fatturazione - Schema database (solo struttura, nessun dato)
-- Testato su MariaDB 10.6+ (richiede supporto a CHECK e JSON_VALID)
-- Import: mysql -u UTENTE -p NOME_DB < sql/schema.sql
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- -------------------------------------------------------------
-- Tabelle
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `clienti` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(150) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `azienda` varchar(150) DEFAULT NULL,
  `indirizzo` varchar(255) DEFAULT NULL,
  `piva` varchar(30) DEFAULT NULL,
  `codice_sdi` varchar(20) DEFAULT NULL,
  `pec` varchar(190) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp(),
  `aggiornato_il` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clienti_interazioni` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `descrizione` text NOT NULL,
  `data_interazione` date NOT NULL,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clienti_note` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `contenuto` text NOT NULL,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cron_sync_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `job_name` varchar(100) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `imported` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `invoices_created` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `clients_created` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `errors` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fatture` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `numero` varchar(50) NOT NULL,
  `data_emissione` date NOT NULL,
  `data_scadenza` date DEFAULT NULL,
  `stato` enum('bozza','emessa','pagata','scaduta') NOT NULL DEFAULT 'bozza',
  `totale` decimal(10,2) NOT NULL DEFAULT 0.00,
  `note` text DEFAULT NULL,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp(),
  `aggiornato_il` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `document_type` varchar(30) NOT NULL DEFAULT 'invoice',
  `mail_sent_at` datetime DEFAULT NULL,
  `mail_status` varchar(20) DEFAULT NULL,
  `mail_error` text DEFAULT NULL,
  `fic_synced_at` datetime DEFAULT NULL,
  `fic_document_id` varchar(255) DEFAULT NULL,
  `fic_status` varchar(50) DEFAULT NULL,
  `fic_pdf_url` text DEFAULT NULL,
  `metodo_pagamento` varchar(50) DEFAULT NULL,
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `stripe_checkout_url` text DEFAULT NULL,
  `data_pagamento` datetime DEFAULT NULL,
  `ei_sent_at` datetime DEFAULT NULL,
  `ei_status` varchar(40) DEFAULT NULL,
  `ei_error` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fatture_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `fattura_id` int(10) UNSIGNED DEFAULT NULL,
  `azione` varchar(60) NOT NULL,
  `esito` enum('ok','error','info') NOT NULL DEFAULT 'info',
  `messaggio` varchar(255) NOT NULL,
  `utente_nome` varchar(100) DEFAULT NULL,
  `utente_email` varchar(190) DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fatture_righe` (
  `id` int(10) UNSIGNED NOT NULL,
  `fattura_id` int(10) UNSIGNED NOT NULL,
  `descrizione` varchar(255) NOT NULL,
  `quantita` decimal(10,2) NOT NULL DEFAULT 1.00,
  `prezzo_unitario` decimal(10,2) NOT NULL DEFAULT 0.00,
  `iva` decimal(5,2) NOT NULL DEFAULT 0.00,
  `totale_riga` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fic_clienti_map` (
  `cliente_id` int(11) NOT NULL,
  `company_id` varchar(64) NOT NULL,
  `fic_entity_id` bigint(20) NOT NULL,
  `source` varchar(20) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_send_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `send_mode` varchar(20) NOT NULL DEFAULT 'manuale',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(150) NOT NULL,
  `cognome` varchar(150) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `telefono` varchar(50) DEFAULT NULL,
  `citta` varchar(120) DEFAULT NULL,
  `data_ricontatto` date DEFAULT NULL,
  `esito` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `servizio_acquistato` varchar(190) DEFAULT NULL,
  `costo_servizio` decimal(10,2) DEFAULT NULL,
  `destinazione` varchar(50) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL,
  `facebook_lead_id` varchar(100) DEFAULT NULL,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp(),
  `aggiornato_il` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `channel` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagamenti` (
  `id` int(10) UNSIGNED NOT NULL,
  `stripe_id` varchar(255) NOT NULL,
  `cliente_id` int(10) UNSIGNED DEFAULT NULL,
  `importo` int(11) NOT NULL COMMENT 'Importo in centesimi',
  `valuta` varchar(3) DEFAULT 'EUR',
  `stato` varchar(50) DEFAULT 'pending',
  `metodo` varchar(100) DEFAULT NULL,
  `descrizione` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `stripe_fee` int(11) DEFAULT 0 COMMENT 'Commissione Stripe in centesimi',
  `fic_document_id` varchar(50) DEFAULT NULL,
  `fic_status` varchar(50) DEFAULT NULL,
  `fic_last_sync` datetime DEFAULT NULL,
  `numero_fattura` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_connected_accounts` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED DEFAULT NULL,
  `stripe_account_id` varchar(255) NOT NULL,
  `account_type` varchar(30) NOT NULL DEFAULT 'express',
  `country` varchar(3) DEFAULT NULL,
  `default_currency` varchar(3) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `business_type` varchar(30) DEFAULT NULL,
  `charges_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `payouts_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `details_submitted` tinyint(1) NOT NULL DEFAULT 0,
  `onboarding_completed` tinyint(1) NOT NULL DEFAULT 0,
  `disabled_reason` varchar(190) DEFAULT NULL,
  `capabilities_json` longtext DEFAULT NULL,
  `requirements_json` longtext DEFAULT NULL,
  `balance_available` int(11) NOT NULL DEFAULT 0 COMMENT 'Importo in centesimi',
  `balance_pending` int(11) NOT NULL DEFAULT 0 COMMENT 'Importo in centesimi',
  `livemode` tinyint(1) NOT NULL DEFAULT 0,
  `stripe_created_at` datetime DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_customer_links` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `stripe_customer_id` varchar(255) NOT NULL,
  `stripe_email` varchar(190) DEFAULT NULL,
  `stripe_name` varchar(255) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_sync` (
  `id` int(10) UNSIGNED NOT NULL,
  `cliente_id` int(10) UNSIGNED NOT NULL,
  `stripe_customer_id` varchar(255) DEFAULT NULL,
  `stripe_email` varchar(190) DEFAULT NULL,
  `stripe_name` varchar(255) DEFAULT NULL,
  `stripe_balance` int(11) DEFAULT 0,
  `stripe_country` varchar(3) DEFAULT NULL,
  `last_charge_amount` int(11) DEFAULT 0,
  `last_charge_date` datetime DEFAULT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `total_charges` int(10) UNSIGNED DEFAULT 0,
  `total_amount_charged` int(10) UNSIGNED DEFAULT 0,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_scheduler_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `sync_key` varchar(100) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `interval_minutes` int(10) UNSIGNED NOT NULL DEFAULT 60,
  `last_run_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_activity` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `user_nome` varchar(100) NOT NULL,
  `user_email` varchar(190) NOT NULL,
  `user_ruolo` varchar(30) NOT NULL DEFAULT '',
  `azione` varchar(30) NOT NULL DEFAULT 'pageview',
  `pagina` varchar(500) NOT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `session_id` varchar(128) NOT NULL DEFAULT '',
  `creato_il` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `user_nome` varchar(100) NOT NULL,
  `user_email` varchar(190) NOT NULL,
  `session_id` varchar(128) NOT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_page` varchar(500) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `utenti` (
  `id` int(10) UNSIGNED NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(190) NOT NULL,
  `ruolo` enum('admin','ragioneria') NOT NULL DEFAULT 'ragioneria',
  `password_hash` varchar(255) NOT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT 1,
  `creato_il` datetime NOT NULL DEFAULT current_timestamp(),
  `aggiornato_il` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- Indici
-- -------------------------------------------------------------

ALTER TABLE `clienti`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_clienti_creato_il` (`creato_il`),
  ADD KEY `idx_clienti_email` (`email`),
  ADD KEY `idx_clienti_piva` (`piva`);

ALTER TABLE `clienti_interazioni`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`);

ALTER TABLE `clienti_note`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`);

ALTER TABLE `cron_sync_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `job_name` (`job_name`,`started_at`);

ALTER TABLE `fatture`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `idx_fic_document_id` (`fic_document_id`),
  ADD KEY `idx_fic_synced_at` (`fic_synced_at`),
  ADD KEY `idx_mail_status` (`mail_status`),
  ADD KEY `idx_fatture_doc_data` (`document_type`,`data_emissione`),
  ADD KEY `idx_fatture_doc_stato_data` (`document_type`,`stato`,`data_emissione`),
  ADD KEY `idx_fatture_doc_stato_scadenza` (`document_type`,`stato`,`data_scadenza`),
  ADD KEY `idx_fatture_doc_numero` (`document_type`,`numero`),
  ADD KEY `idx_fatture_cliente_doc_data` (`cliente_id`,`document_type`,`data_emissione`),
  ADD KEY `idx_ei_status` (`ei_status`);

ALTER TABLE `fatture_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fattura_creato` (`fattura_id`,`creato_il`),
  ADD KEY `idx_creato` (`creato_il`);

ALTER TABLE `fatture_righe`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fattura_id` (`fattura_id`);

ALTER TABLE `fic_clienti_map`
  ADD PRIMARY KEY (`cliente_id`,`company_id`),
  ADD KEY `idx_fic_entity_id` (`fic_entity_id`);

ALTER TABLE `invoice_send_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

ALTER TABLE `lead`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lead_data_ricontatto` (`data_ricontatto`),
  ADD KEY `idx_lead_esito` (`esito`);

ALTER TABLE `lead_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lead_id` (`lead_id`),
  ADD KEY `idx_lead_events_lead_id_id` (`lead_id`,`id`);

ALTER TABLE `pagamenti`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stripe_id` (`stripe_id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `idx_pagamenti_stato_created` (`stato`,`created_at`),
  ADD KEY `idx_pagamenti_stato_created_cliente` (`stato`,`created_at`,`cliente_id`),
  ADD KEY `idx_pagamenti_created_at` (`created_at`),
  ADD KEY `idx_pagamenti_cliente_created` (`cliente_id`,`created_at`);

ALTER TABLE `stripe_connected_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stripe_account_id` (`stripe_account_id`),
  ADD UNIQUE KEY `uq_cliente_id` (`cliente_id`),
  ADD KEY `idx_cliente_id` (`cliente_id`),
  ADD KEY `idx_onboarding_completed` (`onboarding_completed`),
  ADD KEY `idx_last_synced_at` (`last_synced_at`);

ALTER TABLE `stripe_customer_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stripe_customer_id` (`stripe_customer_id`),
  ADD KEY `idx_cliente_id` (`cliente_id`);

ALTER TABLE `stripe_sync`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cliente_id` (`cliente_id`),
  ADD UNIQUE KEY `uniq_cliente_id` (`cliente_id`),
  ADD KEY `synced_at` (`synced_at`);

ALTER TABLE `sync_scheduler_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sync_key` (`sync_key`);

ALTER TABLE `user_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_creato` (`user_id`,`creato_il`),
  ADD KEY `idx_creato` (`creato_il`),
  ADD KEY `idx_session` (`session_id`);

ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_id` (`session_id`),
  ADD KEY `idx_active` (`is_active`,`last_seen_at`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `utenti`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

-- -------------------------------------------------------------
-- AUTO_INCREMENT
-- -------------------------------------------------------------

ALTER TABLE `clienti`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `clienti_interazioni`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `clienti_note`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `cron_sync_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `fatture`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `fatture_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `fatture_righe`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `invoice_send_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `lead_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `pagamenti`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `stripe_connected_accounts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `stripe_customer_links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `stripe_sync`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `sync_scheduler_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_activity`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_sessions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `utenti`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- -------------------------------------------------------------
-- Vincoli (foreign key)
-- -------------------------------------------------------------

ALTER TABLE `clienti_interazioni`
  ADD CONSTRAINT `clienti_interazioni_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`) ON DELETE CASCADE;

ALTER TABLE `clienti_note`
  ADD CONSTRAINT `clienti_note_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`) ON DELETE CASCADE;

ALTER TABLE `fatture`
  ADD CONSTRAINT `fatture_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`);

ALTER TABLE `fatture_log`
  ADD CONSTRAINT `fk_fatture_log_fattura` FOREIGN KEY (`fattura_id`) REFERENCES `fatture` (`id`) ON DELETE SET NULL;

ALTER TABLE `fatture_righe`
  ADD CONSTRAINT `fatture_righe_ibfk_1` FOREIGN KEY (`fattura_id`) REFERENCES `fatture` (`id`) ON DELETE CASCADE;

ALTER TABLE `lead_events`
  ADD CONSTRAINT `lead_events_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `lead` (`id`) ON DELETE CASCADE;

ALTER TABLE `pagamenti`
  ADD CONSTRAINT `pagamenti_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`) ON DELETE SET NULL;

ALTER TABLE `stripe_connected_accounts`
  ADD CONSTRAINT `fk_stripe_connected_accounts_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`) ON DELETE SET NULL;

ALTER TABLE `stripe_customer_links`
  ADD CONSTRAINT `fk_stripe_customer_links_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`) ON DELETE CASCADE;

ALTER TABLE `stripe_sync`
  ADD CONSTRAINT `stripe_sync_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clienti` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
