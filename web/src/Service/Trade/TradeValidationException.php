<?php

declare(strict_types=1);

namespace App\Service\Trade;

/**
 * Exception for invalid trade state transitions or invalid event payloads.
 *
 * Used by TradeStateMachine and TradeEventValidator to signal
 * domain validation failures in the v0.4 Truth Layer.
 */
final class TradeValidationException extends \RuntimeException
{
    /**
     * @param string $message Clear error message describing the validation failure
     * @param int $code Optional error code (defaults to 0)
     * @param \Throwable|null $previous Optional previous exception for chaining
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Factory method for invalid state transitions.
     *
     * @param string|null $currentState Current state or null if no campaign
     * @param string $eventType Attempted event type
     */
    public static function invalidTransition(
        ?string $currentState,
        string $eventType
    ): self {
        $stateStr = $currentState ?? 'no campaign';

        return new self(
            sprintf(
                'Invalid transition: event "%s" is not allowed from state "%s"',
                $eventType,
                $stateStr
            )
        );
    }

    /**
     * Factory method for terminal state violations.
     *
     * @param string $terminalState The terminal state that was already reached
     * @param string $attemptedEvent The event type that was attempted
     */
    public static function terminalStateViolation(
        string $terminalState,
        string $attemptedEvent
    ): self {
        return new self(
            sprintf(
                'Cannot apply event "%s": campaign is already in terminal state "%s"',
                $attemptedEvent,
                $terminalState
            )
        );
    }

    /**
     * Factory method for missing required payload fields.
     *
     * @param string $fieldName Name of the missing field
     * @param string $eventType Event type that requires the field
     */
    public static function missingRequiredField(
        string $fieldName,
        string $eventType
    ): self {
        return new self(
            sprintf(
                'Missing required field "%s" for event type "%s"',
                $fieldName,
                $eventType
            )
        );
    }

    /**
     * Factory method for invalid field values.
     *
     * @param string $fieldName Name of the field with invalid value
     * @param string $reason Description of why the value is invalid
     */
    public static function invalidFieldValue(
        string $fieldName,
        string $reason
    ): self {
        return new self(
            sprintf(
                'Invalid value for field "%s": %s',
                $fieldName,
                $reason
            )
        );
    }
}
