<?php

declare(strict_types=1);

namespace App\Service\Trade;

use Doctrine\DBAL\Connection;
use DateTimeInterface;

/**
 * Resolves the latest available signal snapshots before a trade event timestamp.
 *
 * This service provides read-only access to historical snapshot data
 * for use in TradeEventWriter (Chunk 6).
 *
 * Lookup rule:
 * - For each snapshot table, find the latest row where as_of_date < DATE(event_timestamp)
 * - Same calendar day does NOT count
 * - Future snapshots do NOT count
 * - Missing snapshots return null (not an error)
 * - No look-ahead bias
 */
final readonly class TradeSnapshotResolver
{
    private const TABLE_BUY_SIGNAL = 'instrument_buy_signal_snapshot';
    private const TABLE_SEPA = 'instrument_sepa_snapshot';
    private const TABLE_EPA = 'instrument_epa_snapshot';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Resolve snapshot IDs for a given instrument and event timestamp.
     *
     * @return array{
     *     buy_signal_snapshot_id: int|null,
     *     sepa_snapshot_id: int|null,
     *     epa_snapshot_id: int|null,
     * }
     */
    public function resolve(int $instrumentId, DateTimeInterface $eventTimestamp): array
    {
        $eventDate = $eventTimestamp->format('Y-m-d');

        return [
            'buy_signal_snapshot_id' => $this->resolveBuySignalSnapshotId($instrumentId, $eventDate),
            'sepa_snapshot_id' => $this->resolveSepaSnapshotId($instrumentId, $eventDate),
            'epa_snapshot_id' => $this->resolveEpaSnapshotId($instrumentId, $eventDate),
        ];
    }

    /**
     * Resolve the latest buy signal snapshot ID before the event date.
     */
    private function resolveBuySignalSnapshotId(int $instrumentId, string $eventDate): ?int
    {
        return $this->resolveLatestId(self::TABLE_BUY_SIGNAL, $instrumentId, $eventDate);
    }

    /**
     * Resolve the latest SEPA snapshot ID before the event date.
     */
    private function resolveSepaSnapshotId(int $instrumentId, string $eventDate): ?int
    {
        return $this->resolveLatestId(self::TABLE_SEPA, $instrumentId, $eventDate);
    }

    /**
     * Resolve the latest EPA snapshot ID before the event date.
     */
    private function resolveEpaSnapshotId(int $instrumentId, string $eventDate): ?int
    {
        return $this->resolveLatestId(self::TABLE_EPA, $instrumentId, $eventDate);
    }

    /**
     * Generic resolver for the latest snapshot ID from a given table.
     *
     * Query: WHERE instrument_id = :id AND as_of_date < :event_date
     *        ORDER BY as_of_date DESC LIMIT 1
     *
     * @param string $table One of the allowed snapshot table constants
     */
    private function resolveLatestId(string $table, int $instrumentId, string $eventDate): ?int
    {
        // Validate table name against allowlist to prevent injection
        $allowedTables = [self::TABLE_BUY_SIGNAL, self::TABLE_SEPA, self::TABLE_EPA];
        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid snapshot table: %s', $table)
            );
        }

        $sql = sprintf(
            'SELECT id FROM %s WHERE instrument_id = :instrument_id AND as_of_date < :event_date ORDER BY as_of_date DESC LIMIT 1',
            $table
        );

        $result = $this->connection->fetchOne($sql, [
            'instrument_id' => $instrumentId,
            'event_date' => $eventDate,
        ]);

        // fetchOne returns false if no row found
        return $result !== false ? (int) $result : null;
    }
}
