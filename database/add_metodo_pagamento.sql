-- Aggiunge la colonna metodo_pagamento alla tabella fatture se non esiste
-- Esegui questo script sul database remoto se i test mostrano "migration_needed: true"

-- Verifica ed aggiungi metodo_pagamento
SET @dbname = DATABASE();
SET @tablename = 'fatture';
SET @columnname = 'metodo_pagamento';

SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 'La colonna metodo_pagamento esiste già.' AS risultato",
  CONCAT("ALTER TABLE ", @tablename, " ADD ", @columnname, " VARCHAR(50) NULL")
));

PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Verifica finale
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE 
    TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fatture'
    AND COLUMN_NAME = 'metodo_pagamento';
