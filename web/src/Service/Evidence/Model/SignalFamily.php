<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines signal family categories for classification.
 *
 * Signal families group signal sources by their semantic purpose,
 * enabling cross-source aggregations (e.g., all structure signals).
 */
final readonly class SignalFamily
{
    private const STRUCTURE = 'structure';
    private const EXECUTION = 'execution';
    private const RISK = 'risk';
    private const SENTIMENT = 'sentiment';
    private const COMPOSITE = 'composite';
    private const UNKNOWN = 'unknown';

    /** Structure-based signals (breakouts, patterns, trend) */
    public static function structure(): self
    {
        return new self(self::STRUCTURE);
    }

    /** Execution/timing signals (entries, exits, microstructure) */
    public static function execution(): self
    {
        return new self(self::EXECUTION);
    }

    /** Risk management signals (stop levels, position sizing) */
    public static function risk(): self
    {
        return new self(self::RISK);
    }

    /** Sentiment-based signals (crowd behavior, fear/greed) */
    public static function sentiment(): self
    {
        return new self(self::SENTIMENT);
    }

    /** Composite signals (multiple factors combined) */
    public static function composite(): self
    {
        return new self(self::COMPOSITE);
    }

    /** Unknown or unclassified signal family */
    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    /**
     * Create from string value.
     *
     * @throws \InvalidArgumentException if value is invalid
     */
    public static function fromString(string $value): self
    {
        return match ($value) {
            self::STRUCTURE => new self(self::STRUCTURE),
            self::EXECUTION => new self(self::EXECUTION),
            self::RISK => new self(self::RISK),
            self::SENTIMENT => new self(self::SENTIMENT),
            self::COMPOSITE => new self(self::COMPOSITE),
            self::UNKNOWN => new self(self::UNKNOWN),
            default => throw new \InvalidArgumentException("Invalid SignalFamily: {$value}"),
        };
    }

    /**
     * Try to create from string value.
     * Returns null if value is invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        try {
            return self::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * Get the string value of the signal family.
     */
    public function value(): string
    {
        return $this->value;
    }

    /** Check if structure family */
    public function isStructure(): bool
    {
        return $this->value === self::STRUCTURE;
    }

    /** Check if execution family */
    public function isExecution(): bool
    {
        return $this->value === self::EXECUTION;
    }

    /** Check if risk family */
    public function isRisk(): bool
    {
        return $this->value === self::RISK;
    }

    /** Check if sentiment family */
    public function isSentiment(): bool
    {
        return $this->value === self::SENTIMENT;
    }

    /** Check if composite family */
    public function isComposite(): bool
    {
        return $this->value === self::COMPOSITE;
    }

    /** Check if unknown family */
    public function isUnknown(): bool
    {
        return $this->value === self::UNKNOWN;
    }
}
