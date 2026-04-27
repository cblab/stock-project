<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines specific signal sources for signal_forward_return evidence.
 *
 * Signal sources are extensible. New sources (Kronos, Sentiment, etc.)
 * can be added without changing the Evidence architecture.
 */
final readonly class SignalSource
{
    private const SEPA = 'sepa';
    private const EPA = 'epa';
    private const BUY_SIGNAL = 'buy_signal';
    private const KRONOS = 'kronos';
    private const SENTIMENT = 'sentiment';
    private const CUSTOM = 'custom';

    /** SEPA (Stock Selection) signal source */
    public static function sepa(): self
    {
        return new self(self::SEPA);
    }

    /** EPA (Early Pullback Alert) signal source */
    public static function epa(): self
    {
        return new self(self::EPA);
    }

    /** Buy-Signal composite signal source */
    public static function buySignal(): self
    {
        return new self(self::BUY_SIGNAL);
    }

    /** Kronos (seasonality/timing) signal source */
    public static function kronos(): self
    {
        return new self(self::KRONOS);
    }

    /** Sentiment signal source */
    public static function sentiment(): self
    {
        return new self(self::SENTIMENT);
    }

    /** Custom or undefined signal source */
    public static function custom(): self
    {
        return new self(self::CUSTOM);
    }

    /**
     * Create from string value.
     *
     * @throws \InvalidArgumentException if value is invalid
     */
    public static function fromString(string $value): self
    {
        return match ($value) {
            self::SEPA => new self(self::SEPA),
            self::EPA => new self(self::EPA),
            self::BUY_SIGNAL => new self(self::BUY_SIGNAL),
            self::KRONOS => new self(self::KRONOS),
            self::SENTIMENT => new self(self::SENTIMENT),
            self::CUSTOM => new self(self::CUSTOM),
            default => throw new \InvalidArgumentException("Invalid SignalSource: {$value}"),
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
     * Get the string value of the signal source.
     */
    public function value(): string
    {
        return $this->value;
    }

    /** Check if SEPA signal source */
    public function isSepa(): bool
    {
        return $this->value === self::SEPA;
    }

    /** Check if EPA signal source */
    public function isEpa(): bool
    {
        return $this->value === self::EPA;
    }

    /** Check if Buy-Signal source */
    public function isBuySignal(): bool
    {
        return $this->value === self::BUY_SIGNAL;
    }

    /** Check if Kronos signal source */
    public function isKronos(): bool
    {
        return $this->value === self::KRONOS;
    }

    /** Check if Sentiment signal source */
    public function isSentiment(): bool
    {
        return $this->value === self::SENTIMENT;
    }

    /** Check if Custom signal source */
    public function isCustom(): bool
    {
        return $this->value === self::CUSTOM;
    }
}
