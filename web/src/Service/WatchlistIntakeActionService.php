<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistIntakeActionService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function apply(int $candidateId, string $action): void
    {
        $candidate = $this->connection->fetchAssociative('SELECT * FROM sector_intake_candidate WHERE id = ?', [$candidateId]);
        if (!$candidate) {
            throw new \InvalidArgumentException('Unknown intake candidate.');
        }

        $status = match ($action) {
            'add' => 'ADDED_TO_WATCHLIST',
            'dismiss' => 'DISMISSED',
            'recheck' => 'RECHECK_LATER',
            default => throw new \InvalidArgumentException('Unknown intake action.'),
        };

        $added = false;
        $reason = match ($action) {
            'add' => 'manual_add',
            'dismiss' => 'manual_dismiss',
            'recheck' => 'manual_recheck_later',
            default => 'manual_action',
        };
        if ($action === 'add') {
            $added = $this->addTickerToWatchlist((string) $candidate['ticker'], (string) $candidate['sector_label']);
            if (!$added) {
                $status = 'DISMISSED';
                $reason = 'already_active_instrument';
            }
        }

        $this->connection->update(
            'sector_intake_candidate',
            [
                'status' => $status,
                'manual_action' => $action,
                'added_to_watchlist' => $added ? 1 : 0,
                'reason' => $reason,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['id' => $candidateId],
        );
    }

    private function addTickerToWatchlist(string $ticker, string $sectorLabel): bool
    {
        $instrument = $this->connection->fetchAssociative('SELECT * FROM instrument WHERE UPPER(input_ticker) = UPPER(?) LIMIT 1', [$ticker]);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $note = sprintf("Manual Watchlist Intake from %s.", $sectorLabel);

        if ($instrument) {
            if ((bool) $instrument['active']) {
                return false;
            }

            $this->connection->update(
                'instrument',
                [
                    'active' => 1,
                    'is_portfolio' => 0,
                    'mapping_note' => trim(((string) ($instrument['mapping_note'] ?? ''))."\n".$note),
                    'updated_at' => $now,
                ],
                ['id' => $instrument['id']],
            );

            return true;
        }

        $this->connection->insert('instrument', [
            'input_ticker' => $ticker,
            'provider_ticker' => $ticker,
            'display_ticker' => $ticker,
            'name' => null,
            'asset_class' => 'Equity',
            'region' => 'US',
            'active' => 1,
            'is_portfolio' => 0,
            'mapping_status' => 'sector_intake_manual',
            'mapping_note' => $note,
            'region_exposure' => '[]',
            'sector_profile' => '[]',
            'top_holdings_profile' => '[]',
            'macro_profile' => '[]',
            'direct_news_weight' => 1.0,
            'context_news_weight' => 0.0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }
}
