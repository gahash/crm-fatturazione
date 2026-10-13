ALTER TABLE clienti
    ADD COLUMN codice_sdi VARCHAR(20) NULL AFTER piva,
    ADD COLUMN pec VARCHAR(190) NULL AFTER codice_sdi;
