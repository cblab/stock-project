<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Defines qualitative evidence confidence levels.
 *
 * These levels represent the strength/quality of evidence,
 * not statistical confidence intervals.
 *
 * Distinct from standardErrorOfMean (statistical measure).
 */
final readonly class EvidenceConfidenceLevel
{
    private const ANECDOTAL = 'anecdotal';
    private const VERY_LOW = 'very_low';
    private const LOW = 'low';
    private const MEDIUM = 'medium';
    private const HIGH = 'high';

    /** Anecdotal evidence: single observations, no statistical basis */
    public static function anecdotal(): self
    {
        return new self(self::ANECDOTAL);
    }

    /** Very low confidence: minimal data, high uncertainty */
    public static function veryLow(): self
    {
        return new self(self::VERY_LOW);
    }

    /** Low confidence: limited data, substantial uncertainty */
    public static function low(): self
    {
        return new self(self::LOW);
    }

    /** Medium confidence: moderate data, reasonable certainty */
    public static function medium(): self
    {
        return new self(self::MEDIUM);
    }

    /** High confidence: substantial data, high certainty */
    public static function high(): self
    {
        return new self(self::HIGH);
    }

    /**
     * Create from string value.
     *
     * @throws \InvalidArgumentException if value is invalid
     */
    public static function fromString(string $value): self
    {
        return match ($value) {
            self::ANECDOTAL => new self(self::ANECDOTAL),
            self::VERY_LOW => new self(self::VERY_LOW),
            self::LOW => new self(self::LOW),
            self::MEDIUM => new self(self::MEDIUM),
            self::HIGH => new self(self::HIGH),
            default => throw new \InvalidArgumentException("Invalid EvidenceConfidenceLevel: {$value}"),
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
     * Get the string value of the confidence level.
     */
    public function value(): string
    {
        return $this->value;
    }

    /** Check if anecdotal level */
    public function isAnecdotal(): bool
    {
        return $this->value === self::ANECDOTAL;
    }

    /** Check if very low level */
    public function isVeryLow(): bool
    {
        return $this->value === self::VERY_LOW;
    }

    /** Check if low level */
    public function isLow(): bool
    {
        return $this->value === self::LOW;
    }

    /** Check if medium level */
    public function isMedium(): bool
    {
        return $this->value === self::MEDIUM;
    }

    /** Check if high level */
    public function isHigh(): bool
    {
        return $this->value === self::HIGH;
    }
}
