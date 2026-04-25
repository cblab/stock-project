<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class WatchlistIntakeActionService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IntakeSnapshotRefreshLauncher $snapshotRefreshLauncher,
    ) {
    }

    public function apply(int $candidateId, string $action): void
    {
        $candidate = $this->connection->fetchAssociative('SELECT * FROM watchlist_candidate_registry WHERE id = ?', [$candidateId]);
        if (!$candidate) {
            throw new \InvalidArgumentException('Unknown watchlist candidate.');
        }

        $status = match ($action) {
            'add' => 'ADDED_TO_WATCHLIST',
            'dismiss' => 'REJECTED',
            default => throw new \InvalidArgumentException('Unknown intake action.'),
        };

        $added = false;
        $wasAlreadyActive = false;
        $isPortfolio = false;
        $reason = match ($action) {
            'add' => 'manual_add',
            'dismiss' => 'manual_dismiss',
            default => 'manual_action',
        };
        if ($action === 'add') {
            $result = $this->addTickerToWatchlist((string) $candidate['ticker'], (string) $candidate['sector_label'], $candidate);
            $added = $result['added'];
            $wasAlreadyActive = $result['was_already_active'];
            $isPortfolio = $result['is_portfolio'];

            if (!$added) {
                if ($isPortfolio) {
                    $status = 'ALREADY_IN_PORTFOLIO';
                    $reason = 'already_in_portfolio';
                } else {
                    $status = 'ADDED_TO_WATCHLIST';
                    $reason = 'already_active_instrument';
                    $wasAlreadyActive = true;
                }
            }
        }

        $this->connection->update(
            'watchlist_candidate_registry',
            [
                'manual_state' => $status,
                'active_candidate' => in_array($status, ['ADDED_TO_WATCHLIST', 'REJECTED', 'ALREADY_IN_PORTFOLIO'], true) ? 0 : 1,
                'latest_reason' => $reason,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
            ['id' => $candidateId],
        );

        if ($candidate['latest_candidate_id'] !== null) {
            $this->connection->update(
                'sector_intake_candidate',
                [
                    'status' => $status,
                    'manual_action' => $action,
                    'added_to_watchlist' => ($added || ($wasAlreadyActive && !$isPortfolio)) ? 1 : 0,
                    'reason' => $reason,
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                ['id' => $candidate['latest_candidate_id']],
            );
        }

        if ($action === 'add' && $added) {
            $this->snapshotRefreshLauncher->queueForTicker((string) $candidate['ticker']);
        }
    }

    private function addTickerToWatchlist(string $ticker, string $sectorLabel, array $candidate): array
    {
        $instrument = $this->connection->fetchAssociative('SELECT * FROM instrument WHERE input_ticker = ? LIMIT 1', [$ticker]);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Extract master data from candidate registry (may be NULL if not resolved)
        $masterName = $candidate['name'] ?? null;
        $masterWkn = $candidate['wkn'] ?? null;
        $masterIsin = $candidate['isin'] ?? null;
        $masterRegion = $candidate['region'] ?? null;
        $masterDataStatus = $candidate['master_data_status'] ?? null;

        // Check if instrument is portfolio - portfolio instruments are tracked separately
        if ($instrument && (bool) $instrument['is_portfolio']) {
            // Don't add watchlist note to portfolio instruments, don't change status
            // Just fill missing master data fields if available
            $updateData = [
                'updated_at' => $now,
            ];

            // Only update NULL/empty fields - never overwrite existing values
            if (empty($instrument['name']) && $masterName !== null) {
                $updateData['name'] = $masterName;
            }
            if (empty($instrument['wkn']) && $masterWkn !== null) {
                $updateData['wkn'] = $masterWkn;
            }
            if (empty($instrument['isin']) && $masterIsin !== null) {
                $updateData['isin'] = $masterIsin;
            }
            if (empty($instrument['region']) && $masterRegion !== null) {
                $updateData['region'] = $masterRegion;
            }

            if (count($updateData) > 1) {
                $this->connection->update('instrument', $updateData, ['id' => $instrument['id']]);
            }

            return ['added' => false, 'was_already_active' => false, 'is_portfolio' => true];
        }

        $note = sprintf("Manual Watchlist Intake from %s.", $sectorLabel);

        // Build mapping note with master data status
        if ($masterDataStatus === 'unresolved' || $masterDataStatus === 'error' || $masterDataStatus === null) {
            $note .= " Master data unresolved.";
        } elseif ($masterDataStatus === 'ambiguous') {
            $note .= " Master data ambiguous - manual verification needed.";
        } elseif ($masterDataStatus === 'partial') {
            $note .= " Master data partial - some fields may be missing.";
        }

        if ($instrument) {
            // For existing non-portfolio instrument: always fill NULL fields, never overwrite existing values
            $updateData = [
                'updated_at' => $now,
            ];

            $wasReactivated = false;
            $hasMasterDataUpdate = false;

            // Only change active if not already active
            if (!(bool) $instrument['active']) {
                $updateData['active'] = 1;
                $updateData['is_portfolio'] = 0;
                $wasReactivated = true;
            }

            // Only update NULL/empty fields - never overwrite existing values
            if (empty($instrument['name']) && $masterName !== null) {
                $updateData['name'] = $masterName;
                $hasMasterDataUpdate = true;
            }
            if (empty($instrument['wkn']) && $masterWkn !== null) {
                $updateData['wkn'] = $masterWkn;
                $hasMasterDataUpdate = true;
            }
            if (empty($instrument['isin']) && $masterIsin !== null) {
                $updateData['isin'] = $masterIsin;
                $hasMasterDataUpdate = true;
            }
            if (empty($instrument['region']) && $masterRegion !== null) {
                $updateData['region'] = $masterRegion;
                $hasMasterDataUpdate = true;
            }

            // Only update mapping_note if:
            // - instrument was reactivated, OR
            // - master data fields were added, OR
            // - same note not already present
            $existingNote = (string) ($instrument['mapping_note'] ?? '');
            if ($wasReactivated || $hasMasterDataUpdate || strpos($existingNote, $note) === false) {
                $updateData['mapping_note'] = trim($existingNote . "\n" . $note);
            }

            $this->connection->update('instrument', $updateData, ['id' => $instrument['id']]);

            // Return false if already active (no "new" addition), true otherwise
            return ['added' => !(bool) $instrument['active'], 'was_already_active' => (bool) $instrument['active'], 'is_portfolio' => false];
        }

        // For new instrument: use master data if available, otherwise NULL
        // Region is nullable in schema - don't blindly default to US
        $region = $masterRegion;
        if (!$region) {
            // If no region resolved, mark in note but don't guess
            $note .= " Region unresolved.";
        }

        $this->connection->insert('instrument', [
            'input_ticker' => $ticker,
            'provider_ticker' => $ticker,
            'display_ticker' => $ticker,
            'name' => $masterName,
            'wkn' => $masterWkn,
            'isin' => $masterIsin,
            'asset_class' => 'Equity',
            'region' => $region,
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

        return ['added' => true, 'was_already_active' => false, 'is_portfolio' => false];
    }
}
