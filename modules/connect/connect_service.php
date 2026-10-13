<?php

function tdg_stripe_connect_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS stripe_connected_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NULL,
            stripe_account_id VARCHAR(255) NOT NULL,
            account_type VARCHAR(30) NOT NULL DEFAULT 'express',
            country VARCHAR(3) NULL,
            default_currency VARCHAR(3) NULL,
            email VARCHAR(190) NULL,
            business_name VARCHAR(255) NULL,
            business_type VARCHAR(30) NULL,
            charges_enabled TINYINT(1) NOT NULL DEFAULT 0,
            payouts_enabled TINYINT(1) NOT NULL DEFAULT 0,
            details_submitted TINYINT(1) NOT NULL DEFAULT 0,
            onboarding_completed TINYINT(1) NOT NULL DEFAULT 0,
            disabled_reason VARCHAR(190) NULL,
            capabilities_json LONGTEXT NULL,
            requirements_json LONGTEXT NULL,
            balance_available INT NOT NULL DEFAULT 0 COMMENT 'Importo in centesimi',
            balance_pending INT NOT NULL DEFAULT 0 COMMENT 'Importo in centesimi',
            livemode TINYINT(1) NOT NULL DEFAULT 0,
            stripe_created_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_stripe_account_id (stripe_account_id),
            KEY idx_cliente_id (cliente_id),
            KEY idx_onboarding_completed (onboarding_completed),
            KEY idx_last_synced_at (last_synced_at),
            CONSTRAINT fk_stripe_connected_accounts_cliente
                FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function tdg_stripe_connect_business_name(object $account): string
{
    $profileName = trim((string)($account->business_profile->name ?? ''));
    if ($profileName !== '') {
        return $profileName;
    }

    $companyName = trim((string)($account->company->name ?? ''));
    if ($companyName !== '') {
        return $companyName;
    }

    $firstName = trim((string)($account->individual->first_name ?? ''));
    $lastName = trim((string)($account->individual->last_name ?? ''));
    $fullName = trim($firstName . ' ' . $lastName);
    if ($fullName !== '') {
        return $fullName;
    }

    return trim((string)($account->email ?? ''));
}

function tdg_stripe_connect_sum_balance_for_currency($entries, string $currency): int
{
    $total = 0;
    $targetCurrency = strtolower($currency);
    if (!is_iterable($entries)) {
        return 0;
    }

    foreach ($entries as $entry) {
        $entryCurrency = strtolower((string)($entry->currency ?? ''));
        if ($entryCurrency !== $targetCurrency) {
            continue;
        }
        $total += (int)($entry->amount ?? 0);
    }

    return $total;
}

function tdg_stripe_connect_sum_balance_all($entries): int
{
    $total = 0;
    if (!is_iterable($entries)) {
        return 0;
    }

    foreach ($entries as $entry) {
        $total += (int)($entry->amount ?? 0);
    }

    return $total;
}

function tdg_stripe_connect_sync_accounts(PDO $pdo, int $limit = 20): array
{
    tdg_stripe_connect_ensure_schema($pdo);

    $apiLimit = max(1, min(100, $limit));
    $accounts = [];
    $stats = [
        'accounts_total' => 0,
        'accounts_active' => 0,
        'accounts_incomplete' => 0,
        'balance_available' => 0,
        'balance_pending' => 0,
    ];

    $upsert = $pdo->prepare(
        "INSERT INTO stripe_connected_accounts (
            stripe_account_id, account_type, country, default_currency, email, business_name, business_type,
            charges_enabled, payouts_enabled, details_submitted, onboarding_completed, disabled_reason,
            capabilities_json, requirements_json, balance_available, balance_pending, livemode,
            stripe_created_at, last_synced_at
        ) VALUES (
            :stripe_account_id, :account_type, :country, :default_currency, :email, :business_name, :business_type,
            :charges_enabled, :payouts_enabled, :details_submitted, :onboarding_completed, :disabled_reason,
            :capabilities_json, :requirements_json, :balance_available, :balance_pending, :livemode,
            :stripe_created_at, NOW()
        )
        ON DUPLICATE KEY UPDATE
            account_type = VALUES(account_type),
            country = VALUES(country),
            default_currency = VALUES(default_currency),
            email = VALUES(email),
            business_name = VALUES(business_name),
            business_type = VALUES(business_type),
            charges_enabled = VALUES(charges_enabled),
            payouts_enabled = VALUES(payouts_enabled),
            details_submitted = VALUES(details_submitted),
            onboarding_completed = VALUES(onboarding_completed),
            disabled_reason = VALUES(disabled_reason),
            capabilities_json = VALUES(capabilities_json),
            requirements_json = VALUES(requirements_json),
            balance_available = VALUES(balance_available),
            balance_pending = VALUES(balance_pending),
            livemode = VALUES(livemode),
            stripe_created_at = VALUES(stripe_created_at),
            last_synced_at = NOW()"
    );

    $accountsList = \Stripe\Account::all(['limit' => $apiLimit]);
    foreach ($accountsList->autoPagingIterator() as $account) {
        $accountId = (string)($account->id ?? '');
        if ($accountId === '' || strpos($accountId, 'acct_') !== 0) {
            continue;
        }

        $defaultCurrency = strtolower((string)($account->default_currency ?? 'eur'));
        $available = 0;
        $pending = 0;
        try {
            $balance = \Stripe\Balance::retrieve([], ['stripe_account' => $accountId]);
            $available = tdg_stripe_connect_sum_balance_for_currency($balance->available ?? [], $defaultCurrency);
            $pending = tdg_stripe_connect_sum_balance_for_currency($balance->pending ?? [], $defaultCurrency);

            if ($available === 0) {
                $available = tdg_stripe_connect_sum_balance_all($balance->available ?? []);
            }
            if ($pending === 0) {
                $pending = tdg_stripe_connect_sum_balance_all($balance->pending ?? []);
            }
        } catch (Throwable $e) {
            $available = 0;
            $pending = 0;
        }

        $capabilities = [];
        foreach ($account->capabilities ?? [] as $capName => $capState) {
            $capabilities[(string)$capName] = (string)$capState;
        }
        $requirements = [];
        foreach ($account->requirements ?? [] as $reqName => $reqValue) {
            if (is_array($reqValue) || is_object($reqValue)) {
                $requirements[(string)$reqName] = json_encode($reqValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $requirements[(string)$reqName] = (string)$reqValue;
            }
        }
        $chargesEnabled = (int)!empty($account->charges_enabled);
        $payoutsEnabled = (int)!empty($account->payouts_enabled);
        $detailsSubmitted = (int)!empty($account->details_submitted);
        $disabledReason = (string)($requirements['disabled_reason'] ?? '');
        $isOnboardingCompleted = $chargesEnabled === 1 && $payoutsEnabled === 1 && $detailsSubmitted === 1 && $disabledReason === '';

        $stripeCreatedAt = !empty($account->created)
            ? gmdate('Y-m-d H:i:s', (int)$account->created)
            : null;

        $record = [
            'stripe_account_id' => $accountId,
            'account_type' => (string)($account->type ?? 'express'),
            'country' => strtoupper((string)($account->country ?? '')),
            'default_currency' => strtoupper($defaultCurrency),
            'email' => (string)($account->email ?? ''),
            'business_name' => tdg_stripe_connect_business_name($account),
            'business_type' => (string)($account->business_type ?? ''),
            'charges_enabled' => $chargesEnabled,
            'payouts_enabled' => $payoutsEnabled,
            'details_submitted' => $detailsSubmitted,
            'onboarding_completed' => (int)$isOnboardingCompleted,
            'disabled_reason' => $disabledReason,
            'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'requirements_json' => json_encode($requirements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'balance_available' => $available,
            'balance_pending' => $pending,
            'livemode' => (int)!empty($account->livemode),
            'stripe_created_at' => $stripeCreatedAt,
        ];

        if ($record['capabilities_json'] === false) {
            $record['capabilities_json'] = '{}';
        }
        if ($record['requirements_json'] === false) {
            $record['requirements_json'] = '{}';
        }

        $upsert->execute($record);
        $accounts[] = $record;

        $stats['accounts_total']++;
        if ($isOnboardingCompleted) {
            $stats['accounts_active']++;
        } else {
            $stats['accounts_incomplete']++;
        }
        $stats['balance_available'] += $available;
        $stats['balance_pending'] += $pending;
    }

    return [
        'accounts' => $accounts,
        'stats' => $stats,
    ];
}

function tdg_stripe_connect_list_local(PDO $pdo, int $limit = 20): array
{
    tdg_stripe_connect_ensure_schema($pdo);
    $query = $pdo->prepare(
        "SELECT
            stripe_account_id, account_type, country, default_currency, email, business_name, business_type,
            charges_enabled, payouts_enabled, details_submitted, onboarding_completed, disabled_reason,
            capabilities_json, requirements_json, balance_available, balance_pending, livemode,
            stripe_created_at, last_synced_at, created_at, updated_at
        FROM stripe_connected_accounts
        ORDER BY updated_at DESC
        LIMIT " . (int)max(1, $limit)
    );
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tdg_stripe_connect_get_local_account(PDO $pdo, string $accountId): ?array
{
    tdg_stripe_connect_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT
            stripe_account_id, account_type, country, default_currency, email, business_name, business_type,
            charges_enabled, payouts_enabled, details_submitted, onboarding_completed, disabled_reason,
            capabilities_json, requirements_json, balance_available, balance_pending, livemode,
            stripe_created_at, last_synced_at, created_at, updated_at
        FROM stripe_connected_accounts
        WHERE stripe_account_id = :account_id
        LIMIT 1"
    );
    $stmt->execute(['account_id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tdg_stripe_connect_get_account_live(string $accountId): array
{
    $account = \Stripe\Account::retrieve($accountId);
    $balance = \Stripe\Balance::retrieve([], ['stripe_account' => $accountId]);
    $payouts = \Stripe\Payout::all(['limit' => 8], ['stripe_account' => $accountId]);
    $transactions = \Stripe\BalanceTransaction::all(['limit' => 12], ['stripe_account' => $accountId]);

    $payoutRows = [];
    foreach ($payouts->data as $payout) {
        $payoutRows[] = [
            'id' => (string)($payout->id ?? ''),
            'amount' => (int)($payout->amount ?? 0),
            'currency' => strtoupper((string)($payout->currency ?? 'EUR')),
            'status' => (string)($payout->status ?? ''),
            'arrival_date' => !empty($payout->arrival_date) ? gmdate('Y-m-d H:i:s', (int)$payout->arrival_date) : null,
        ];
    }

    $transactionRows = [];
    foreach ($transactions->data as $transaction) {
        $transactionRows[] = [
            'id' => (string)($transaction->id ?? ''),
            'type' => (string)($transaction->type ?? ''),
            'amount' => (int)($transaction->amount ?? 0),
            'fee' => (int)($transaction->fee ?? 0),
            'net' => (int)($transaction->net ?? 0),
            'currency' => strtoupper((string)($transaction->currency ?? 'EUR')),
            'description' => (string)($transaction->description ?? ''),
            'created' => !empty($transaction->created) ? gmdate('Y-m-d H:i:s', (int)$transaction->created) : null,
        ];
    }

    return [
        'account' => $account,
        'balance' => $balance,
        'payouts' => $payoutRows,
        'transactions' => $transactionRows,
    ];
}
