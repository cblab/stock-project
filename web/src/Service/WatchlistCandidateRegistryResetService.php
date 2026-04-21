<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistCandidateRegistryResetService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function reactivateAfterInstrumentDelete(string $ticker): int
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '') {
            return 0;
        }

        return $this->connection->executeStatement(
            "UPDATE watchlist_candidate_registry
                SET manual_state = NULL,
                    active_candidate = 1,
                    latest_reason = 'instrument_deleted_reactivated',
                    updated_at = NOW()
              WHERE UPPER(ticker) = ?
                AND manual_state = 'ADDED_TO_WATCHLIST'",
            [$ticker],
        );
    }
}
