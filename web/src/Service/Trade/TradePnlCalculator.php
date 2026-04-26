<?php

declare(strict_types=1);

namespace App\Service\Trade;

/**
 * Deterministic P&L calculator for Trade Campaigns and Events.
 *
 * Part of v0.4 Truth Layer domain calculation.
 * Calculates realized gross and net P&L values for sales/trims.
 *
 * This class is intentionally simple. It is not responsible for complex tax calculations.
 * Gross P&L is truth. Net is optional.
 *
 * No side effects. No DB queries. Pure domain logic.
 */
final readonly class TradePnlCalculator
{
    /**
     * Calculate realized gross P&L for an exit event.
     *
     * Formula: gross = (exitPrice - avgEntryPrice) * quantity - fees
     *
     * @param string|float|int $avgEntryPrice Average entry price (must be > 0)
     * @param string|float|int $exitPrice Exit price (must be > 0)
     * @param string|float|int $quantity Quantity sold (must be > 0)
     * @param string|float|int $fees Fees (must be >= 0, default 0)
     *
     * @return float Realized gross P&L
     *
     * @throws \InvalidArgumentException If inputs are invalid
     */
    public function calculateRealizedGrossPnl(
        string|float|int $avgEntryPrice,
        string|float|int $exitPrice,
        string|float|int $quantity,
        string|float|int $fees = 0,
    ): float {
        $this->assertPositive($avgEntryPrice, 'avgEntryPrice');
        $this->assertPositive($exitPrice, 'exitPrice');
        $this->assertPositive($quantity, 'quantity');
        $this->assertNonNegative($fees, 'fees');

        $avgEntry = (float) $avgEntryPrice;
        $exit = (float) $exitPrice;
        $qty = (float) $quantity;
        $feeAmount = (float) $fees;

        return ($exit - $avgEntry) * $qty - $feeAmount;
    }

    /**
     * Calculate realized P&L percentage.
     *
     * Formula: pct = realizedGrossPnl / (avgEntryPrice * quantity)
     *
     * @param string|float|int $avgEntryPrice Average entry price
     * @param string|float|int $realizedGrossPnl Realized gross P&L
     * @param string|float|int $quantity Quantity
     *
     * @return float P&L as decimal (e.g., 0.134 for +13.4%)
     *
     * @throws \InvalidArgumentException If denominator is zero or inputs invalid
     */
    public function calculateRealizedPnlPct(
        string|float|int $avgEntryPrice,
        string|float|int $realizedGrossPnl,
        string|float|int $quantity,
    ): float {
        $this->assertPositive($avgEntryPrice, 'avgEntryPrice');
        $this->assertPositive($quantity, 'quantity');

        $avgEntry = (float) $avgEntryPrice;
        $gross = (float) $realizedGrossPnl;
        $qty = (float) $quantity;

        $denominator = $avgEntry * $qty;

        if ($denominator == 0) {
            throw new \InvalidArgumentException('Denominator (avgEntryPrice * quantity) must be > 0');
        }

        return $gross / $denominator;
    }

    /**
     * Calculate realized net P&L after tax.
     *
     * Rules:
     * - taxRate NULL => NULL zurückgeben
     * - taxRate muss zwischen 0 und 1 liegen
     * - bei realizedGrossPnl <= 0 keine Steuer abziehen; Netto = Brutto
     * - bei realizedGrossPnl > 0: net = gross * (1 - taxRate)
     *
     * @param string|float|int $realizedGrossPnl Realized gross P&L
     * @param string|float|int|null $taxRate Tax rate as decimal (e.g., 0.26375), or null
     *
     * @return float|null Realized net P&L, or null if no tax rate provided
     *
     * @throws \InvalidArgumentException If tax rate is invalid
     */
    public function calculateRealizedNetPnl(
        string|float|int $realizedGrossPnl,
        string|float|int|null $taxRate,
    ): ?float {
        if ($taxRate === null) {
            return null;
        }

        $this->assertTaxRate($taxRate, 'taxRate');

        $gross = (float) $realizedGrossPnl;
        $rate = (float) $taxRate;

        // No tax on losses or zero gains
        if ($gross <= 0) {
            return $gross;
        }

        return $gross * (1 - $rate);
    }

    /**
     * Calculate complete exit summary.
     *
     * Combines gross P&L, percentage, and net P&L in one call.
     *
     * @param string|float|int $avgEntryPrice Average entry price
     * @param string|float|int $exitPrice Exit price
     * @param string|float|int $quantity Quantity sold
     * @param string|float|int $fees Fees (default 0)
     * @param string|float|int|null $taxRate Tax rate (optional)
     *
     * @return array{
     *     realized_pnl_gross: float,
     *     realized_pnl_pct: float,
     *     realized_pnl_net: float|null,
     *     tax_rate_applied: float|null,
     * }
     *
     * @throws \InvalidArgumentException If inputs are invalid
     */
    public function calculateExitSummary(
        string|float|int $avgEntryPrice,
        string|float|int $exitPrice,
        string|float|int $quantity,
        string|float|int $fees = 0,
        string|float|int|null $taxRate = null,
    ): array {
        $gross = $this->calculateRealizedGrossPnl($avgEntryPrice, $exitPrice, $quantity, $fees);
        $pct = $this->calculateRealizedPnlPct($avgEntryPrice, $gross, $quantity);
        $net = $this->calculateRealizedNetPnl($gross, $taxRate);

        return [
            'realized_pnl_gross' => $gross,
            'realized_pnl_pct' => $pct,
            'realized_pnl_net' => $net,
            'tax_rate_applied' => $taxRate !== null ? (float) $taxRate : null,
        ];
    }

    /**
     * Assert that a value is positive (> 0).
     *
     * @param string|float|int $value
     * @param string $fieldName
     *
     * @throws \InvalidArgumentException
     */
    private function assertPositive(string|float|int $value, string $fieldName): void
    {
        $numeric = (float) $value;

        if (!is_numeric($value) || $numeric <= 0) {
            throw new \InvalidArgumentException(
                sprintf('%s must be > 0, got: %s', $fieldName, $value)
            );
        }
    }

    /**
     * Assert that a value is non-negative (>= 0).
     *
     * @param string|float|int $value
     * @param string $fieldName
     *
     * @throws \InvalidArgumentException
     */
    private function assertNonNegative(string|float|int $value, string $fieldName): void
    {
        $numeric = (float) $value;

        if (!is_numeric($value) || $numeric < 0) {
            throw new \InvalidArgumentException(
                sprintf('%s must be >= 0, got: %s', $fieldName, $value)
            );
        }
    }

    /**
     * Assert that a value is a valid tax rate (0 <= rate <= 1).
     *
     * @param string|float|int $value
     * @param string $fieldName
     *
     * @throws \InvalidArgumentException
     */
    private function assertTaxRate(string|float|int $value, string $fieldName): void
    {
        $numeric = (float) $value;

        if (!is_numeric($value) || $numeric < 0 || $numeric > 1) {
            throw new \InvalidArgumentException(
                sprintf('%s must be between 0 and 1, got: %s', $fieldName, $value)
            );
        }
    }
}