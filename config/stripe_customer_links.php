<?php

if (!function_exists('tdg_stripe_customer_links_table_name')) {
    function tdg_stripe_customer_links_table_name(): string
    {
        return 'stripe_customer_links';
    }
}

if (!function_exists('tdg_stripe_customer_links_table_exists')) {
    function tdg_stripe_customer_links_table_exists(PDO $pdo): bool
    {
        static $cache = [];

        $key = spl_object_hash($pdo);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
            $stmt->execute([':table_name' => tdg_stripe_customer_links_table_name()]);
            $cache[$key] = (bool)$stmt->fetchColumn();
            return $cache[$key];
        } catch (Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }
}

if (!function_exists('tdg_ensure_stripe_customer_links_table')) {
    function tdg_ensure_stripe_customer_links_table(PDO $pdo): bool
    {
        if (tdg_stripe_customer_links_table_exists($pdo)) {
            return true;
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_customer_links (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                cliente_id INT UNSIGNED NOT NULL,
                stripe_customer_id VARCHAR(255) NOT NULL,
                stripe_email VARCHAR(190) NULL,
                stripe_name VARCHAR(255) NULL,
                source VARCHAR(50) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_stripe_customer_id (stripe_customer_id),
                KEY idx_cliente_id (cliente_id),
                CONSTRAINT fk_stripe_customer_links_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return tdg_stripe_customer_links_table_exists($pdo);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('tdg_find_cliente_id_by_stripe_customer')) {
    function tdg_find_cliente_id_by_stripe_customer(PDO $pdo, string $stripeCustomerId): int
    {
        $stripeCustomerId = trim($stripeCustomerId);
        if ($stripeCustomerId === '') {
            return 0;
        }

        if (tdg_stripe_customer_links_table_exists($pdo)) {
            try {
                $stmt = $pdo->prepare('SELECT cliente_id FROM stripe_customer_links WHERE stripe_customer_id = :stripe_customer_id LIMIT 1');
                $stmt->execute([':stripe_customer_id' => $stripeCustomerId]);
                $clienteId = (int)($stmt->fetchColumn() ?: 0);
                if ($clienteId > 0) {
                    return $clienteId;
                }
            } catch (Throwable $e) {
            }
        }

        try {
            $stmt = $pdo->prepare('SELECT cliente_id FROM stripe_sync WHERE stripe_customer_id = :stripe_customer_id LIMIT 1');
            $stmt->execute([':stripe_customer_id' => $stripeCustomerId]);
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('tdg_upsert_stripe_customer_link')) {
    function tdg_upsert_stripe_customer_link(PDO $pdo, int $clienteId, string $stripeCustomerId, string $stripeEmail = '', string $stripeName = '', string $source = ''): void
    {
        if ($clienteId <= 0) {
            return;
        }

        $stripeCustomerId = trim($stripeCustomerId);
        if ($stripeCustomerId === '') {
            return;
        }

        if (!tdg_ensure_stripe_customer_links_table($pdo)) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO stripe_customer_links (cliente_id, stripe_customer_id, stripe_email, stripe_name, source)
                 VALUES (:cliente_id, :stripe_customer_id, :stripe_email, :stripe_name, :source)
                 ON DUPLICATE KEY UPDATE
                    cliente_id = VALUES(cliente_id),
                    stripe_email = VALUES(stripe_email),
                    stripe_name = VALUES(stripe_name),
                    source = VALUES(source),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([
                ':cliente_id' => $clienteId,
                ':stripe_customer_id' => $stripeCustomerId,
                ':stripe_email' => $stripeEmail !== '' ? $stripeEmail : null,
                ':stripe_name' => $stripeName !== '' ? $stripeName : null,
                ':source' => $source !== '' ? $source : null,
            ]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('tdg_load_stripe_customer_map')) {
    function tdg_load_stripe_customer_map(PDO $pdo): array
    {
        $map = [];

        if (tdg_stripe_customer_links_table_exists($pdo)) {
            try {
                $stmt = $pdo->query('SELECT cliente_id, stripe_customer_id FROM stripe_customer_links WHERE stripe_customer_id IS NOT NULL AND TRIM(stripe_customer_id) <> ""');
                foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                    $stripeCustomerId = trim((string)($row['stripe_customer_id'] ?? ''));
                    $clienteId = (int)($row['cliente_id'] ?? 0);
                    if ($stripeCustomerId !== '' && $clienteId > 0) {
                        $map[$stripeCustomerId] = $clienteId;
                    }
                }
            } catch (Throwable $e) {
            }
        }

        try {
            $stmt = $pdo->query('SELECT cliente_id, stripe_customer_id FROM stripe_sync WHERE stripe_customer_id IS NOT NULL AND TRIM(stripe_customer_id) <> ""');
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                $stripeCustomerId = trim((string)($row['stripe_customer_id'] ?? ''));
                $clienteId = (int)($row['cliente_id'] ?? 0);
                if ($stripeCustomerId !== '' && $clienteId > 0 && !isset($map[$stripeCustomerId])) {
                    $map[$stripeCustomerId] = $clienteId;
                }
            }
        } catch (Throwable $e) {
        }

        return $map;
    }
}

if (!function_exists('tdg_cliente_sync_normalize_vat_number')) {
    function tdg_cliente_sync_normalize_vat_number($value): string
    {
        $vat = strtoupper(trim((string)$value));
        $vat = preg_replace('/\s+/', '', $vat);
        if (strpos($vat, 'IT') === 0) {
            $vat = substr($vat, 2);
        }
        $vat = preg_replace('/[^A-Z0-9]/', '', $vat);
        if (preg_match('/^\d{10}$/', $vat)) {
            $paddedVat = '0' . $vat;
            if (tdg_cliente_sync_is_valid_italian_vat_number($paddedVat)) {
                return $paddedVat;
            }
        }
        return $vat;
    }
}

if (!function_exists('tdg_cliente_sync_is_valid_italian_vat_number')) {
    function tdg_cliente_sync_is_valid_italian_vat_number(string $vat): bool
    {
        if (!preg_match('/^\d{11}$/', $vat)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int)$vat[$i];
            if (($i % 2) === 0) {
                $sum += $digit;
                continue;
            }

            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
            $sum += $digit;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit === (int)$vat[10];
    }
}

if (!function_exists('tdg_cliente_sync_has_valid_sdi')) {
    function tdg_cliente_sync_has_valid_sdi(?string $value): bool
    {
        return preg_match('/^[A-Z0-9]{7}$/', strtoupper(trim((string)$value))) === 1;
    }
}

if (!function_exists('tdg_cliente_sync_has_valid_pec')) {
    function tdg_cliente_sync_has_valid_pec(?string $value): bool
    {
        return filter_var(trim((string)$value), FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('tdg_cliente_sync_is_valid_city_name')) {
    function tdg_cliente_sync_is_valid_city_name(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ'\-\. ]+$/u", $value) === 1;
    }
}

if (!function_exists('tdg_cliente_sync_parse_address_parts')) {
    function tdg_cliente_sync_parse_address_parts($value): array
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return [
                'address_street' => '',
                'address_postal_code' => '',
                'address_city' => '',
                'address_province' => '',
            ];
        }

        $street = '';
        $postalCode = '';
        $city = '';
        $province = '';

        if (preg_match('/^(.*?)(?:,\s*|\s+)(\d{5})\s+(.+?)\s*\(?([A-Za-z]{2})\)?(?:\s*,.*)?$/u', $raw, $matches)) {
            $street = trim((string)$matches[1]);
            $postalCode = (string)$matches[2];
            $city = trim((string)$matches[3]);
            $province = strtoupper((string)$matches[4]);
        } else {
            $street = rtrim($raw, " ,.-");
        }

        if ($postalCode === '' && preg_match('/\b(\d{5})\b/', $raw, $matches)) {
            $postalCode = (string)$matches[1];
        }
        if ($province === '' && preg_match('/(?:\(|,\s*|\s+)([A-Za-z]{2})\)?\s*$/', trim($raw), $matches)) {
            $province = strtoupper((string)$matches[1]);
        }
        if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\s+(.+?)\s*\(?([A-Za-z]{2})\)?(?:\s*,|$)/i', $raw, $matches)) {
            $city = trim((string)$matches[1]);
            if ($province === '') {
                $province = strtoupper((string)$matches[2]);
            }
        }
        if ($city === '' && $postalCode !== '' && preg_match('/\b' . preg_quote($postalCode, '/') . '\b\s*,?\s*([^,]+?)(?:\s*,\s*[A-Za-z]{2})?(?:\s*,|$)/i', $raw, $matches)) {
            $city = trim((string)$matches[1]);
        }

        return [
            'address_street' => trim($street),
            'address_postal_code' => preg_match('/^\d{5}$/', $postalCode) ? $postalCode : '',
            'address_city' => trim($city),
            'address_province' => preg_match('/^[A-Z]{2}$/', $province) ? $province : '',
        ];
    }
}

if (!function_exists('tdg_cliente_sync_address_is_complete')) {
    function tdg_cliente_sync_address_is_complete(?string $value): bool
    {
        $address = tdg_cliente_sync_parse_address_parts($value);
        return trim((string)($address['address_street'] ?? '')) !== ''
            && preg_match('/^\d{5}$/', (string)($address['address_postal_code'] ?? '')) === 1
            && tdg_cliente_sync_is_valid_city_name((string)($address['address_city'] ?? ''))
            && preg_match('/^[A-Z]{2}$/', (string)($address['address_province'] ?? '')) === 1;
    }
}

if (!function_exists('tdg_cliente_sync_value_is_preferable')) {
    function tdg_cliente_sync_value_is_preferable(string $column, ?string $currentValue, ?string $incomingValue): bool
    {
        $current = trim((string)$currentValue);
        $incoming = trim((string)$incomingValue);
        if ($incoming === '') {
            return false;
        }

        switch ($column) {
            case 'piva':
                $currentVat = tdg_cliente_sync_normalize_vat_number($current);
                $incomingVat = tdg_cliente_sync_normalize_vat_number($incoming);
                if ($incomingVat === '') {
                    return false;
                }
                $currentValid = preg_match('/^\d{11}$/', $currentVat) === 1 && tdg_cliente_sync_is_valid_italian_vat_number($currentVat);
                $incomingValid = preg_match('/^\d{11}$/', $incomingVat) === 1 && tdg_cliente_sync_is_valid_italian_vat_number($incomingVat);
                return ($currentVat === '' || !$currentValid) && $incomingValid;

            case 'codice_sdi':
                return !tdg_cliente_sync_has_valid_sdi($current) && tdg_cliente_sync_has_valid_sdi($incoming);

            case 'pec':
                return !tdg_cliente_sync_has_valid_pec($current) && tdg_cliente_sync_has_valid_pec($incoming);

            case 'indirizzo':
                return !tdg_cliente_sync_address_is_complete($current) && tdg_cliente_sync_address_is_complete($incoming);

            default:
                return $current === '';
        }
    }
}

if (!function_exists('tdg_cliente_sync_collect_updates')) {
    function tdg_cliente_sync_collect_updates(PDO $pdo, int $clienteId, array $incomingFields): array
    {
        if ($clienteId <= 0 || !$incomingFields) {
            return [];
        }

        $allowedColumns = ['nome', 'email', 'telefono', 'azienda', 'indirizzo', 'piva', 'codice_sdi', 'pec'];
        $requestedColumns = [];
        foreach (array_keys($incomingFields) as $column) {
            if (in_array($column, $allowedColumns, true)) {
                $requestedColumns[] = $column;
            }
        }
        if (!$requestedColumns) {
            return [];
        }

        $sql = 'SELECT ' . implode(', ', array_map(static function (string $column): string {
            return '`' . $column . '`';
        }, $requestedColumns)) . ' FROM clienti WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $clienteId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$current) {
            return [];
        }

        $updates = [];
        foreach ($requestedColumns as $column) {
            $incomingValue = trim((string)($incomingFields[$column] ?? ''));
            if ($incomingValue === '') {
                continue;
            }
            if (tdg_cliente_sync_value_is_preferable($column, (string)($current[$column] ?? ''), $incomingValue)) {
                $updates[$column] = $incomingValue;
            }
        }

        return $updates;
    }
}

if (!function_exists('tdg_cliente_sync_apply_updates')) {
    function tdg_cliente_sync_apply_updates(PDO $pdo, int $clienteId, array $incomingFields): bool
    {
        $updates = tdg_cliente_sync_collect_updates($pdo, $clienteId, $incomingFields);
        if (!$updates) {
            return false;
        }

        $set = [];
        $params = [':id' => $clienteId];
        foreach ($updates as $column => $value) {
            $placeholder = ':' . $column;
            $set[] = '`' . $column . '` = ' . $placeholder;
            $params[$placeholder] = $value;
        }

        $stmt = $pdo->prepare('UPDATE clienti SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }
}