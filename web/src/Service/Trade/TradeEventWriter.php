<?php

declare(strict_types=1);

namespace App\Service\Trade;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * Central write logic for trade events in the v0.4 Truth Layer.
 */
final readonly class TradeEventWriter
{
    private const DEFAULT_FEES = '0.00';
    private const DEFAULT_CURRENCY = 'EUR';
    private const DEFAULT_TRADE_TYPE = 'live';
    private const NON_TERMINAL_STATES = ['open', 'trimmed', 'paused'];

    public function __construct(
        private Connection $connection,
        private TradeVersionProvider $versionProvider,
        private TradeSnapshotResolver $snapshotResolver,
        private TradeStateMachine $stateMachine,
        private TradeEventValidator $eventValidator,
        private TradePnlCalculator $pnlCalculator,
    ) {
    }

    public function write(array $payload): TradeEventWriteResult
    {
        $normalized = $this->normalizePayload($payload);
        $this->assertInstrumentExists($normalized['instrument_id']);
        $campaignContext = $this->findCampaignContext($normalized);
        $this->eventValidator->assertEventPayloadValid($normalized, $campaignContext['data']);
        $currentState = $campaignContext['data']['state'] ?? null;
        $this->stateMachine->assertTransitionAllowed($currentState, $normalized['event_type']);
        $snapshots = $this->snapshotResolver->resolve($normalized['instrument_id'], $normalized['event_timestamp']);
        $versions = $this->versionProvider->current();
        $normalized = $this->calculateEffectiveQuantity($normalized, $campaignContext['data']);

        $this->connection->beginTransaction();
        try {
            $campaign = $campaignContext['is_new']
                ? $this->createCampaign($normalized)
                : $campaignContext['data'];
            $eventId = $this->insertTradeEvent($normalized, $campaign['id'], $snapshots, $versions);
            $newState = $this->updateCampaign($campaign, $normalized);
            $this->connection->commit();

            return new TradeEventWriteResult(
                tradeCampaignId: $campaign['id'],
                tradeEventId: $eventId,
                campaignState: $newState,
                buySignalSnapshotId: $snapshots['buy_signal_snapshot_id'],
                sepaSnapshotId: $snapshots['sepa_snapshot_id'],
                epaSnapshotId: $snapshots['epa_snapshot_id'],
                versions: $versions,
            );
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    private function normalizePayload(array $payload): array
    {
        if (!isset($payload['instrument_id'])) {
            throw TradeValidationException::missingRequiredField('instrument_id', 'unknown');
        }
        $instrumentId = filter_var($payload['instrument_id'], FILTER_VALIDATE_INT);
        if ($instrumentId === false || $instrumentId < 1) {
            throw TradeValidationException::invalidFieldValue('instrument_id', 'must be a positive integer');
        }
        if (!isset($payload['event_type'])) {
            throw TradeValidationException::missingRequiredField('event_type', 'unknown');
        }
        if (!is_string($payload['event_type']) || $payload['event_type'] === '') {
            throw TradeValidationException::invalidFieldValue('event_type', 'must be a non-empty string');
        }
        if (!isset($payload['event_timestamp'])) {
            throw TradeValidationException::missingRequiredField('event_timestamp', 'unknown');
        }
        $eventTimestamp = $this->parseTimestamp($payload['event_timestamp']);
        if ($eventTimestamp === null) {
            throw TradeValidationException::invalidFieldValue('event_timestamp', 'must be a valid datetime');
        }

        return [
            'instrument_id' => $instrumentId,
            'event_type' => $payload['event_type'],
            'event_timestamp' => $eventTimestamp,
            'event_price' => isset($payload['event_price']) ? (string) $payload['event_price'] : null,
            'quantity' => isset($payload['quantity']) ? (string) $payload['quantity'] : null,
            'exit_reason' => isset($payload['exit_reason']) ? (string) $payload['exit_reason'] : null,
            'fees' => isset($payload['fees']) ? (string) $payload['fees'] : self::DEFAULT_FEES,
            'currency' => isset($payload['currency']) ? (string) $payload['currency'] : self::DEFAULT_CURRENCY,
            'trade_type' => isset($payload['trade_type']) ? (string) $payload['trade_type'] : self::DEFAULT_TRADE_TYPE,
            'entry_thesis' => isset($payload['entry_thesis']) ? (string) $payload['entry_thesis'] : null,
            'invalidation_rule' => isset($payload['invalidation_rule']) ? (string) $payload['invalidation_rule'] : null,
            'event_notes' => isset($payload['event_notes']) ? (string) $payload['event_notes'] : null,
        ];
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) return $value;
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s')) ?: null;
        }
        if (is_int($value)) {
            $dt = (new DateTimeImmutable())->setTimestamp($value);
            return $dt ?: null;
        }
        if (is_string($value)) {
            foreach (['Y-m-d H:i:s', 'Y-m-d', DateTimeImmutable::ATOM] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $value);
                if ($parsed !== false) return $parsed;
            }
        }
        return null;
    }

    private function assertInstrumentExists(int $instrumentId): void
    {
        $exists = $this->connection->fetchOne('SELECT 1 FROM instrument WHERE id = ?', [$instrumentId]);
        if ($exists === false) {
            throw new TradeValidationException(sprintf('Instrument with ID %d does not exist', $instrumentId));
        }
    }

    private function findCampaignContext(array $normalized): array
    {
        $eventType = $normalized['event_type'];
        $instrumentId = $normalized['instrument_id'];
        $tradeType = $normalized['trade_type'];
        $openCampaigns = $this->findOpenCampaigns($instrumentId, $tradeType);

        if ($eventType === 'entry' || $eventType === 'migration_seed') {
            if (count($openCampaigns) > 0) {
                throw new TradeValidationException(
                    sprintf('Cannot create %s: open campaign already exists for instrument %d', $eventType, $instrumentId)
                );
            }
            return ['is_new' => true, 'data' => ['state' => null]];
        }

        if (count($openCampaigns) === 0) {
            throw new TradeValidationException(
                sprintf('No open campaign found for instrument %d and trade_type %s', $instrumentId, $tradeType)
            );
        }
        if (count($openCampaigns) > 1) {
            throw new TradeValidationException(
                sprintf('Multiple open campaigns found for instrument %d and trade_type %s', $instrumentId, $tradeType)
            );
        }
        return ['is_new' => false, 'data' => $openCampaigns[0]];
    }

    private function findOpenCampaigns(int $instrumentId, string $tradeType): array
    {
        $placeholders = implode(',', array_fill(0, count(self::NON_TERMINAL_STATES), '?'));
        $params = array_merge([$instrumentId, $tradeType], self::NON_TERMINAL_STATES);
        return $this->connection->fetchAllAssociative(
            "SELECT * FROM trade_campaign WHERE instrument_id = ? AND trade_type = ? AND state IN ($placeholders)",
            $params
        );
    }

    private function calculateEffectiveQuantity(array $normalized, array $campaignData): array
    {
        $eventType = $normalized['event_type'];
        if (!in_array($eventType, ['hard_exit', 'return_to_watchlist'], true)) {
            return $normalized;
        }
        if ($normalized['quantity'] !== null) {
            return $normalized;
        }
        $openQty = $campaignData['open_quantity'] ?? null;
        if ($openQty === null) {
            throw new TradeValidationException(
                sprintf('%s requires quantity or existing open_quantity', $eventType)
            );
        }
        $normalized['quantity'] = (string) $openQty;
        return $normalized;
    }

    private function createCampaign(array $normalized): array
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $isEntry = $normalized['event_type'] === 'entry';

        $this->connection->insert('trade_campaign', [
            'instrument_id' => $normalized['instrument_id'],
            'trade_type' => $normalized['trade_type'],
            'state' => 'open',
            'entry_thesis' => $isEntry ? $normalized['entry_thesis'] : null,
            'invalidation_rule' => $isEntry ? $normalized['invalidation_rule'] : null,
            'total_quantity' => $normalized['quantity'],
            'open_quantity' => $normalized['quantity'],
            'avg_entry_price' => $normalized['event_price'],
            'realized_pnl_gross' => null,
            'realized_pnl_net' => null,
            'tax_rate_applied' => null,
            'realized_pnl_pct' => null,
            'opened_at' => $normalized['event_timestamp']->format('Y-m-d H:i:s'),
            'closed_at' => null,
            'entry_macro_snapshot_id' => null,
            'exit_macro_snapshot_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $this->connection->lastInsertId(),
            'state' => 'open',
            'total_quantity' => $normalized['quantity'],
            'open_quantity' => $normalized['quantity'],
            'avg_entry_price' => $normalized['event_price'],
            'realized_pnl_gross' => null,
        ];
    }

    private function insertTradeEvent(array $normalized, int $campaignId, array $snapshots, array $versions): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('trade_event', [
            'trade_campaign_id' => $campaignId,
            'instrument_id' => $normalized['instrument_id'],
            'event_type' => $normalized['event_type'],
            'exit_reason' => $normalized['exit_reason'],
            'event_price' => $normalized['event_price'],
            'quantity' => $normalized['quantity'],
            'fees' => $normalized['fees'],
            'currency' => $normalized['currency'],
            'event_timestamp' => $normalized['event_timestamp']->format('Y-m-d H:i:s'),
            'buy_signal_snapshot_id' => $snapshots['buy_signal_snapshot_id'],
            'sepa_snapshot_id' => $snapshots['sepa_snapshot_id'],
            'epa_snapshot_id' => $snapshots['epa_snapshot_id'],
            'macro_snapshot_id' => null,
            'scoring_version' => $versions['scoring_version'],
            'policy_version' => $versions['policy_version'],
            'model_version' => $versions['model_version'],
            'macro_version' => $versions['macro_version'],
            'event_notes' => $normalized['event_notes'],
            'created_at' => $now,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    private function updateCampaign(array $campaign, array $normalized): string
    {
        $eventType = $normalized['event_type'];
        $eventPrice = $normalized['event_price'] !== null ? (float) $normalized['event_price'] : null;
        $quantity = $normalized['quantity'] !== null ? (float) $normalized['quantity'] : null;
        $fees = (float) $normalized['fees'];

        $totalQty = $campaign['total_quantity'] !== null ? (float) $campaign['total_quantity'] : 0.0;
        $openQty = $campaign['open_quantity'] !== null ? (float) $campaign['open_quantity'] : 0.0;
        $avgEntry = $campaign['avg_entry_price'] !== null ? (float) $campaign['avg_entry_price'] : null;
        $realizedGross = $campaign['realized_pnl_gross'] !== null ? (float) $campaign['realized_pnl_gross'] : 0.0;

        return match ($eventType) {
            'add' => $this->handleAdd($campaign['id'], $totalQty, $openQty, $avgEntry, $eventPrice, $quantity, $campaign['state']),
            'trim' => $this->handleTrim($campaign['id'], $openQty, $avgEntry, $eventPrice, $quantity, $fees, $realizedGross),
            'pause' => $this->handlePause($campaign['id']),
            'resume' => $this->handleResume($campaign['id']),
            'hard_exit' => $this->handleHardExit($campaign['id'], $openQty, $avgEntry, $eventPrice, $quantity, $fees, $realizedGross, $normalized['event_timestamp']),
            'return_to_watchlist' => $this->handleReturnToWatchlist($campaign['id'], $openQty, $avgEntry, $eventPrice, $quantity, $fees, $realizedGross, $normalized['event_timestamp']),
            default => $campaign['state'] ?? 'open',
        };
    }

    private function handleAdd(int $campaignId, float $totalQty, float $openQty, ?float $avgEntry, ?float $eventPrice, ?float $quantity, ?string $currentState): string
    {
        if ($quantity === null || $eventPrice === null) {
            throw new TradeValidationException('add event requires quantity and event_price');
        }
        $newOpenQty = $openQty + $quantity;
        $newTotalQty = $totalQty + $quantity;
        $newAvgEntry = $this->calculateNewAvgPrice($avgEntry, $openQty, $eventPrice, $quantity);

        $this->connection->update('trade_campaign', [
            'total_quantity' => $newTotalQty,
            'open_quantity' => $newOpenQty,
            'avg_entry_price' => $newAvgEntry,
        ], ['id' => $campaignId]);

        return $currentState ?? 'open';
    }

    private function handleTrim(int $campaignId, float $openQty, ?float $avgEntry, ?float $eventPrice, ?float $quantity, float $fees, float $realizedGross): string
    {
        if ($quantity === null || $eventPrice === null) {
            throw new TradeValidationException('trim event requires quantity and event_price');
        }
        if ($quantity >= $openQty) {
            throw new TradeValidationException('trim quantity must be less than open_quantity; use hard_exit to close entire position');
        }

        $newOpenQty = $openQty - $quantity;
        $newRealizedGross = $realizedGross;

        if ($avgEntry !== null) {
            $exitSummary = $this->pnlCalculator->calculateExitSummary($avgEntry, $eventPrice, $quantity, $fees);
            $newRealizedGross = $realizedGross + $exitSummary['realized_pnl_gross'];
        }

        $this->connection->update('trade_campaign', [
            'state' => 'trimmed',
            'open_quantity' => $newOpenQty,
            'realized_pnl_gross' => $newRealizedGross,
        ], ['id' => $campaignId]);

        return 'trimmed';
    }

    private function handlePause(int $campaignId): string
    {
        $this->connection->update('trade_campaign', ['state' => 'paused'], ['id' => $campaignId]);
        return 'paused';
    }

    private function handleResume(int $campaignId): string
    {
        $this->connection->update('trade_campaign', ['state' => 'open'], ['id' => $campaignId]);
        return 'open';
    }

    private function handleHardExit(int $campaignId, float $openQty, ?float $avgEntry, ?float $eventPrice, ?float $quantity, float $fees, float $realizedGross, DateTimeImmutable $eventTimestamp): string
    {
        if ($eventPrice === null) {
            throw new TradeValidationException('hard_exit requires event_price');
        }
        if ($quantity === null || $quantity <= 0) {
            throw new TradeValidationException('hard_exit requires positive quantity');
        }

        $newRealizedGross = $realizedGross;
        $newRealizedNet = null;
        $taxRate = null;
        $realizedPct = null;

        if ($avgEntry !== null) {
            $exitSummary = $this->pnlCalculator->calculateExitSummary($avgEntry, $eventPrice, $quantity, $fees);
            $newRealizedGross = $realizedGross + $exitSummary['realized_pnl_gross'];
            $newRealizedNet = $exitSummary['realized_pnl_net'];
            $taxRate = $exitSummary['tax_rate_applied'];
            $realizedPct = $exitSummary['realized_pnl_pct'];
        }

        $newState = $newRealizedGross > 0 ? 'closed_profit' : ($newRealizedGross < 0 ? 'closed_loss' : 'closed_neutral');

        $this->connection->update('trade_campaign', [
            'state' => $newState,
            'open_quantity' => 0,
            'realized_pnl_gross' => $newRealizedGross,
            'realized_pnl_net' => $newRealizedNet,
            'tax_rate_applied' => $taxRate,
            'realized_pnl_pct' => $realizedPct,
            'closed_at' => $eventTimestamp->format('Y-m-d H:i:s'),
        ], ['id' => $campaignId]);

        return $newState;
    }

    private function handleReturnToWatchlist(int $campaignId, float $openQty, ?float $avgEntry, ?float $eventPrice, ?float $quantity, float $fees, float $realizedGross, DateTimeImmutable $eventTimestamp): string
    {
        if ($eventPrice === null) {
            throw new TradeValidationException('return_to_watchlist requires event_price');
        }
        if ($quantity === null || $quantity <= 0) {
            throw new TradeValidationException('return_to_watchlist requires positive quantity');
        }

        $newRealizedGross = $realizedGross;
        $newRealizedNet = null;
        $taxRate = null;
        $realizedPct = null;

        if ($avgEntry !== null) {
            $exitSummary = $this->pnlCalculator->calculateExitSummary($avgEntry, $eventPrice, $quantity, $fees);
            $newRealizedGross = $realizedGross + $exitSummary['realized_pnl_gross'];
            $newRealizedNet = $exitSummary['realized_pnl_net'];
            $taxRate = $exitSummary['tax_rate_applied'];
            $realizedPct = $exitSummary['realized_pnl_pct'];
        }

        $this->connection->update('trade_campaign', [
            'state' => 'returned_to_watchlist',
            'open_quantity' => 0,
            'realized_pnl_gross' => $newRealizedGross,
            'realized_pnl_net' => $newRealizedNet,
            'tax_rate_applied' => $taxRate,
            'realized_pnl_pct' => $realizedPct,
            'closed_at' => $eventTimestamp->format('Y-m-d H:i:s'),
        ], ['id' => $campaignId]);

        return 'returned_to_watchlist';
    }

    private function calculateNewAvgPrice(?float $avgEntry, float $openQty, float $eventPrice, float $quantity): ?float
    {
        if ($avgEntry === null || $openQty <= 0) {
            return $eventPrice;
        }
        $totalValue = ($avgEntry * $openQty) + ($eventPrice * $quantity);
        $totalQty = $openQty + $quantity;
        if ($totalQty <= 0) {
            return null;
        }
        return $totalValue / $totalQty;
    }
}