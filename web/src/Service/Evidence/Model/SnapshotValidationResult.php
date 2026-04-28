<?php

declare(strict_types=1);

namespace App\Service\Evidence\Model;

/**
 * Immutable result of snapshot validation.
 *
 * Contains validation state, machine-readable reason code, and optional
 * diagnostic details for logging/debugging.
 */
final readonly class SnapshotValidationResult
{
    /**
     * @param array<string, mixed> $details
     */
    private function __construct(
        private bool $valid,
        private ?string $reasonCode,
        private array $details,
    ) {
    }

    /**
     * Create a valid result.
     */
    public static function valid(): self
    {
        return new self(true, null, []);
    }

    /**
     * Create an invalid result with reason code and optional details.
     *
     * @param array<string, mixed> $details
     */
    public static function invalid(string $reasonCode, array $details = []): self
    {
        return new self(false, $reasonCode, $details);
    }

    /**
     * Check if validation passed.
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Get the machine-readable reason code (null if valid).
     */
    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }

    /**
     * Get diagnostic details for debugging.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
