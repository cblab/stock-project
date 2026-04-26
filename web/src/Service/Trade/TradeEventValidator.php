<?php

declare(strict_types=1);

namespace App\Service\Trade;

/**
 * Validates trade event payloads for domain rules.
 *
 * Part of v0.4 Truth Layer domain validation.
 * Validates field presence and constraints based on event type and context.
 */
final readonly class TradeEventValidator
{
    private const EVENT_ENTRY = 'entry';
    private const EVENT_ADD = 'add';
    private const EVENT_TRIM = 'trim';
    private const EVENT_PAUSE = 'pause';
    private const EVENT_RESUME = 'resume';
    private const EVENT_HARD_EXIT = 'hard_exit';
    private const EVENT_RETURN_TO_WATCHLIST = 'return_to_watchlist';
    private const EVENT_MIGRATION_SEED = 'migration_seed';

    private const TRADE_TYPE_LIVE = 'live';
    private const TRADE_TYPE_PAPER = 'paper';
    private const TRADE_TYPE_PSEUDO = 'pseudo';

    /**
     * Assert that an event payload is valid for the given campaign context.
     *
     * @param array<string, mixed> $payload Event payload data
     * @param array<string, mixed>|null $campaign Current campaign data or null if new
     *
     * @throws TradeValidationException if validation fails
     */
    public function assertEventPayloadValid(array $payload, ?array $campaign = null): void
    {
        $eventType = $this->extractEventType($payload);

        // Validate trade_type if present
        if (isset($payload['trade_type'])) {
            $this->validateTradeType($payload['trade_type']);
        }

        // Validate exit_reason rules
        $this->validateExitReason($payload, $eventType);

        // Validate price/quantity rules
        $this->validatePriceAndQuantity($payload, $eventType, $campaign);

        // Validate fees are non-negative if present
        if (isset($payload['fees'])) {
            $this->validateFees($payload['fees']);
        }
    }

    /**
     * Extract and validate event_type from payload.
     *
     * @param array<string, mixed> $payload
     *
     * @throws TradeValidationException
     */
    private function extractEventType(array $payload): string
    {
        if (!isset($payload['event_type'])) {
            throw TradeValidationException::missingRequiredField('event_type', 'unknown');
        }

        $eventType = $payload['event_type'];

        if (!is_string($eventType)) {
            throw TradeValidationException::invalidFieldValue(
                'event_type',
                'must be a string'
            );
        }

        $validTypes = [
            self::EVENT_ENTRY,
            self::EVENT_ADD,
            self::EVENT_TRIM,
            self::EVENT_PAUSE,
            self::EVENT_RESUME,
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
            self::EVENT_MIGRATION_SEED,
        ];

        if (!in_array($eventType, $validTypes, true)) {
            throw TradeValidationException::invalidFieldValue(
                'event_type',
                sprintf('must be one of: %s', implode(', ', $validTypes))
            );
        }

        return $eventType;
    }

    /**
     * Validate trade_type value.
     *
     * @param mixed $tradeType
     *
     * @throws TradeValidationException
     */
    private function validateTradeType(mixed $tradeType): void
    {
        if (!is_string($tradeType)) {
            throw TradeValidationException::invalidFieldValue(
                'trade_type',
                'must be a string'
            );
        }

        $validTypes = [
            self::TRADE_TYPE_LIVE,
            self::TRADE_TYPE_PAPER,
            self::TRADE_TYPE_PSEUDO,
        ];

        if (!in_array($tradeType, $validTypes, true)) {
            throw TradeValidationException::invalidFieldValue(
                'trade_type',
                sprintf('must be one of: %s', implode(', ', $validTypes))
            );
        }
    }

    /**
     * Validate exit_reason field rules based on event type.
     *
     * @param array<string, mixed> $payload
     * @param string $eventType
     *
     * @throws TradeValidationException
     */
    private function validateExitReason(array $payload, string $eventType): void
    {
        $hasExitReason = isset($payload['exit_reason']) && $payload['exit_reason'] !== null;

        // Events that REQUIRE exit_reason
        $requiresExitReason = [
            self::EVENT_TRIM,
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
        ];

        // Events that FORBID exit_reason
        $forbidsExitReason = [
            self::EVENT_ENTRY,
            self::EVENT_ADD,
            self::EVENT_PAUSE,
            self::EVENT_RESUME,
            self::EVENT_MIGRATION_SEED,
        ];

        if (in_array($eventType, $requiresExitReason, true) && !$hasExitReason) {
            throw TradeValidationException::missingRequiredField('exit_reason', $eventType);
        }

        if (in_array($eventType, $forbidsExitReason, true) && $hasExitReason) {
            throw TradeValidationException::invalidFieldValue(
                'exit_reason',
                sprintf('must not be set for event type "%s"', $eventType)
            );
        }
    }

    /**
     * Validate price and quantity field rules.
     *
     * @param array<string, mixed> $payload
     * @param string $eventType
     * @param array<string, mixed>|null $campaign
     *
     * @throws TradeValidationException
     */
    private function validatePriceAndQuantity(
        array $payload,
        string $eventType,
        ?array $campaign
    ): void {
        $hasPrice = isset($payload['event_price']) && $payload['event_price'] !== null;
        $hasQuantity = isset($payload['quantity']) && $payload['quantity'] !== null;

        // Events that REQUIRE both price and quantity
        $requiresPriceAndQuantity = [
            self::EVENT_ENTRY,
            self::EVENT_ADD,
            self::EVENT_TRIM,
        ];

        // Events that REQUIRE price, quantity is optional
        $requiresPrice = [
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
        ];

        // Events where neither is required
        $noRequirements = [
            self::EVENT_PAUSE,
            self::EVENT_RESUME,
            self::EVENT_MIGRATION_SEED,
        ];

        if (in_array($eventType, $requiresPriceAndQuantity, true)) {
            if (!$hasPrice) {
                throw TradeValidationException::missingRequiredField('event_price', $eventType);
            }
            if (!$hasQuantity) {
                throw TradeValidationException::missingRequiredField('quantity', $eventType);
            }
            $this->validateNumericPositive($payload['event_price'], 'event_price');
            $this->validateNumericPositive($payload['quantity'], 'quantity');
        }

        if (in_array($eventType, $requiresPrice, true)) {
            if (!$hasPrice) {
                throw TradeValidationException::missingRequiredField('event_price', $eventType);
            }
            $this->validateNumericPositive($payload['event_price'], 'event_price');

            // If quantity is provided, validate it
            if ($hasQuantity) {
                $this->validateQuantityAgainstOpenPosition(
                    $payload['quantity'],
                    $campaign,
                    $eventType
                );
            }
        }

        // For events with no requirements, ensure values are numeric if provided
        if (in_array($eventType, $noRequirements, true)) {
            if ($hasPrice) {
                $this->validateNumericPositive($payload['event_price'], 'event_price');
            }
            if ($hasQuantity) {
                $this->validateNumericPositive($payload['quantity'], 'quantity');
            }
        }
    }

    /**
     * Validate that a value is numeric and positive.
     *
     * @param mixed $value
     * @param string $fieldName
     *
     * @throws TradeValidationException
     */
    private function validateNumericPositive(mixed $value, string $fieldName): void
    {
        if (!is_numeric($value)) {
            throw TradeValidationException::invalidFieldValue(
                $fieldName,
                'must be a numeric value'
            );
        }

        $numericValue = (float) $value;
        if ($numericValue <= 0) {
            throw TradeValidationException::invalidFieldValue(
                $fieldName,
                'must be greater than zero'
            );
        }
    }

    /**
     * Validate quantity against open position size.
     *
     * @param mixed $quantity
     * @param array<string, mixed>|null $campaign
     * @param string $eventType
     *
     * @throws TradeValidationException
     */
    private function validateQuantityAgainstOpenPosition(
        mixed $quantity,
        ?array $campaign,
        string $eventType
    ): void {
        $this->validateNumericPositive($quantity, 'quantity');

        if ($campaign === null) {
            return;
        }

        $openQuantity = $campaign['open_quantity'] ?? null;

        if ($openQuantity === null) {
            return;
        }

        $quantityValue = (float) $quantity;
        $openValue = (float) $openQuantity;

        if ($quantityValue > $openValue) {
            throw TradeValidationException::invalidFieldValue(
                'quantity',
                sprintf(
                    'cannot exceed open position quantity (%s) for event "%s"',
                    $openValue,
                    $eventType
                )
            );
        }
    }

    /**
     * Validate fees value.
     *
     * @param mixed $fees
     *
     * @throws TradeValidationException
     */
    private function validateFees(mixed $fees): void
    {
        if (!is_numeric($fees)) {
            throw TradeValidationException::invalidFieldValue(
                'fees',
                'must be a numeric value'
            );
        }

        if ((float) $fees < 0) {
            throw TradeValidationException::invalidFieldValue(
                'fees',
                'cannot be negative'
            );
        }
    }
}