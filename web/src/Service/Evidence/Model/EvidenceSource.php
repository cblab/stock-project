<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines the source types for Evidence Engine Lite.
 *
 * Evidence sources are never silently mixed.
 * Trade outcome evidence and signal forward-return evidence
 * flow through separate pipelines and are aggregated separately.
 */
final readonly class EvidenceSource
{
    private const TRADE_OUTCOME = 'trade_outcome';
    private const SIGNAL_FORWARD_RETURN = 'signal_forward_return';

    /**
     * Trade outcome evidence derived from completed trade_campaign and trade_event data.
     *
     * Answers: How good were actual/paper/pseudo decisions?
     * Which entry/exit paths led to which outcomes?
     */
    public static function tradeOutcome(): self
    {
        return new self(self::TRADE_OUTCOME);
    }

    /**
     * Signal forward-return evidence derived from signal sources (SEPA, EPA, Buy-Signal, Kronos, Sentiment).
     *
     * Answers: How did signal states behave subsequently in the market?
     * Did certain score buckets have better forward returns?
     */
    public static function signalForwardReturn(): self
    {
        return new self(self::SIGNAL_FORWARD_RETURN);
    }

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * Get the string value of the source.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Check if this source is trade outcome evidence.
     */
    public function isTradeOutcome(): bool
    {
        return $this->value === self::TRADE_OUTCOME;
    }

    /**
     * Check if this source is signal forward-return evidence.
     */
    public function isSignalForwardReturn(): bool
    {
        return $this->value === self::SIGNAL_FORWARD_RETURN;
    }
}
