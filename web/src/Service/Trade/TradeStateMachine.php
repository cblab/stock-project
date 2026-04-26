<?php

declare(strict_types=1);

namespace App\Service\Trade;

/**
 * Finite State Machine for Trade Campaign lifecycle management.
 *
 * Validates allowed state transitions based on current state and event type.
 * Part of v0.4 Truth Layer domain validation.
 */
final readonly class TradeStateMachine
{
    // Valid states
    private const STATE_OPEN = 'open';
    private const STATE_TRIMMED = 'trimmed';
    private const STATE_PAUSED = 'paused';
    private const STATE_CLOSED_PROFIT = 'closed_profit';
    private const STATE_CLOSED_LOSS = 'closed_loss';
    private const STATE_CLOSED_NEUTRAL = 'closed_neutral';
    private const STATE_RETURNED_TO_WATCHLIST = 'returned_to_watchlist';

    // Valid event types
    private const EVENT_ENTRY = 'entry';
    private const EVENT_ADD = 'add';
    private const EVENT_TRIM = 'trim';
    private const EVENT_PAUSE = 'pause';
    private const EVENT_RESUME = 'resume';
    private const EVENT_HARD_EXIT = 'hard_exit';
    private const EVENT_RETURN_TO_WATCHLIST = 'return_to_watchlist';
    private const EVENT_MIGRATION_SEED = 'migration_seed';

    // Terminal states - no events allowed after reaching these
    private const TERMINAL_STATES = [
        self::STATE_CLOSED_PROFIT,
        self::STATE_CLOSED_LOSS,
        self::STATE_CLOSED_NEUTRAL,
        self::STATE_RETURNED_TO_WATCHLIST,
    ];

    // Allowed transitions: currentState => [allowedEventTypes]
    private const ALLOWED_TRANSITIONS = [
        null => [
            self::EVENT_ENTRY,
            self::EVENT_MIGRATION_SEED,
        ],
        self::STATE_OPEN => [
            self::EVENT_ADD,
            self::EVENT_TRIM,
            self::EVENT_PAUSE,
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
        ],
        self::STATE_TRIMMED => [
            self::EVENT_ADD,
            self::EVENT_TRIM,
            self::EVENT_PAUSE,
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
        ],
        self::STATE_PAUSED => [
            self::EVENT_RESUME,
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
        ],
    ];

    /**
     * Assert that a transition is allowed from the current state with the given event type.
     *
     * @param string|null $currentState Current state or null if no campaign exists yet
     * @param string $eventType Event type to apply
     *
     * @throws TradeValidationException if the transition is not allowed
     */
    public function assertTransitionAllowed(?string $currentState, string $eventType): void
    {
        $this->validateEventType($eventType);

        // Check for terminal state
        if ($currentState !== null && $this->isTerminalState($currentState)) {
            throw TradeValidationException::terminalStateViolation($currentState, $eventType);
        }

        // Validate state
        $this->validateState($currentState);

        // Check if transition is allowed
        $allowedEvents = self::ALLOWED_TRANSITIONS[$currentState] ?? [];

        if (!in_array($eventType, $allowedEvents, true)) {
            throw TradeValidationException::invalidTransition($currentState, $eventType);
        }
    }

    /**
     * Check if a transition is allowed without throwing.
     *
     * @param string|null $currentState Current state or null if no campaign exists yet
     * @param string $eventType Event type to apply
     */
    public function isTransitionAllowed(?string $currentState, string $eventType): bool
    {
        try {
            $this->assertTransitionAllowed($currentState, $eventType);
            return true;
        } catch (TradeValidationException) {
            return false;
        }
    }

    /**
     * Check if a state is a terminal state.
     */
    private function isTerminalState(string $state): bool
    {
        return in_array($state, self::TERMINAL_STATES, true);
    }

    /**
     * Validate that the state is known.
     *
     * @throws TradeValidationException if state is unknown
     */
    private function validateState(?string $state): void
    {
        if ($state === null) {
            return;
        }

        $validStates = [
            self::STATE_OPEN,
            self::STATE_TRIMMED,
            self::STATE_PAUSED,
            self::STATE_CLOSED_PROFIT,
            self::STATE_CLOSED_LOSS,
            self::STATE_CLOSED_NEUTRAL,
            self::STATE_RETURNED_TO_WATCHLIST,
        ];

        if (!in_array($state, $validStates, true)) {
            throw new TradeValidationException(
                sprintf('Unknown trade state: "%s"', $state)
            );
        }
    }

    /**
     * Validate that the event type is known.
     *
     * @throws TradeValidationException if event type is unknown
     */
    private function validateEventType(string $eventType): void
    {
        $validEventTypes = [
            self::EVENT_ENTRY,
            self::EVENT_ADD,
            self::EVENT_TRIM,
            self::EVENT_PAUSE,
            self::EVENT_RESUME,
            self::EVENT_HARD_EXIT,
            self::EVENT_RETURN_TO_WATCHLIST,
            self::EVENT_MIGRATION_SEED,
        ];

        if (!in_array($eventType, $validEventTypes, true)) {
            throw new TradeValidationException(
                sprintf('Unknown event type: "%s"', $eventType)
            );
        }
    }
}
