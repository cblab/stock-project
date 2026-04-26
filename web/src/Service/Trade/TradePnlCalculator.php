<?php

declare(strict_types=1);

namespace App\Service\Trade;

/**
 * Deterministic P&L calculator for Trade Campaigns and Events.
 *
 * Part of v0.4 Truth Layer domain calculation.
 * Calculates realized gross and net P&L values for sales/trims.
 *
 * All calculations use strict decimal precision (BCMath).
 * No side effects. No DB queries. Pure domain logic.
 */
final readonly class TradePnlCalculator
{
    private const SCALE = 10;

    /**
     * Calculate realized P&L for a single exit event (trim/hard_exit/return_to_watchlist).
     *
     * Formula (gross): (exit_price - avg_entry_price) * quantity
     *
     * @param string $avgEntryPrice Average entry price of the campaign
     * @param string $exitPrice Exit/Event price
     * @param string $quantity Sold/Trimmed quantity
     * @param string $fees Fees for this transaction (optional, default 0)
     * @param string|null $taxRate Tax rate as decimal (e.g., 0.26375 for 26.375%), null if not applicable
     *
     * @return array{
     *     realized_pnl_gross: string,
     *     realized_pnl_net: string,
     *     tax_amount: string|null,
     * }
     */
    public function calculateExitPnl(
        string $avgEntryPrice,
        string $exitPrice,
        string $quantity,
        string $fees = '0',
        ?string $taxRate = null,
    ): array {
        // Validate inputs
        $this->assertNumeric($avgEntryPrice, 'avg_entry_price');
        $this->assertNumeric($exitPrice, 'exit_price');
        $this->assertNumeric($quantity, 'quantity');
        $this->assertNumeric($fees, 'fees');
        if ($taxRate !== null) {
            $this->assertNumeric($taxRate, 'tax_rate');
        }

        // Gross P&L: (exit - entry) * quantity
        $priceDiff = bcsub($exitPrice, $avgEntryPrice, self::SCALE);
        $grossPnl = bcmul($priceDiff, $quantity, self::SCALE);

        // Net before tax: gross - fees
        $netBeforeTax = bcsub($grossPnl, $fees, self::SCALE);

        // Apply tax if rate provided (only on positive gains)
        $taxAmount = null;
        $netPnl = $netBeforeTax;

        if ($taxRate !== null && bccomp($netBeforeTax, '0', self::SCALE) > 0) {
            $taxAmount = bcmul($netBeforeTax, $taxRate, self::SCALE);
            $netPnl = bcsub($netBeforeTax, $taxAmount, self::SCALE);
        }

        return [
            'realized_pnl_gross' => $this->round4($grossPnl),
            'realized_pnl_net' => $this->round4($netPnl),
            'tax_amount' => $taxAmount !== null ? $this->round4($taxAmount) : null,
        ];
    }

    /**
     * Calculate cumulative P&L for a closed campaign.
     *
     * @param string $avgEntryPrice Average entry price
     * @param string $exitPrice Final exit price
     * @param string $totalQuantity Total quantity sold
     * @param string $totalFees Sum of all fees
     * @param string|null $taxRate Tax rate as decimal
     *
     * @return array{
     *     realized_pnl_gross: string,
     *     realized_pnl_net: string,
     *     realized_pnl_pct: string,
     *     tax_amount: string|null,
     * }
     */
    public function calculateClosedCampaignPnl(
        string $avgEntryPrice,
        string $exitPrice,
        string $totalQuantity,
        string $totalFees = '0',
        ?string $taxRate = null,
    ): array {
        $result = $this->calculateExitPnl(
            $avgEntryPrice,
            $exitPrice,
            $totalQuantity,
            $totalFees,
            $taxRate,
        );

        // Calculate percentage return: (gross_pnl / invested_capital) * 100
        // invested_capital = avg_entry_price * total_quantity
        $investedCapital = bcmul($avgEntryPrice, $totalQuantity, self::SCALE);

        $pnlPct = '0';
        if (bccomp($investedCapital, '0', self::SCALE) !== 0) {
            $ratio = bcdiv($result['realized_pnl_gross'], $investedCapital, self::SCALE);
            $pnlPct = bcmul($ratio, '100', self::SCALE);
        }

        return [
            'realized_pnl_gross' => $result['realized_pnl_gross'],
            'realized_pnl_net' => $result['realized_pnl_net'],
            'realized_pnl_pct' => $this->round6($pnlPct),
            'tax_amount' => $result['tax_amount'],
        ];
    }

    /**
     * Calculate remaining open position value.
     *
     * @param string $avgEntryPrice Average entry price of campaign
     * @param string $openQuantity Remaining open quantity
     * @param string|null $currentPrice Current market price (null if not available)
     *
     * @return array{
     *     invested_value: string,
     *     current_value: string|null,
     *     unrealized_pnl: string|null,
     * }
     */
    public function calculateOpenPositionValue(
        string $avgEntryPrice,
        string $openQuantity,
        ?string $currentPrice = null,
    ): array {
        $this->assertNumeric($avgEntryPrice, 'avg_entry_price');
        $this->assertNumeric($openQuantity, 'open_quantity');

        $investedValue = bcmul($avgEntryPrice, $openQuantity, self::SCALE);

        $currentValue = null;
        $unrealizedPnl = null;

        if ($currentPrice !== null) {
            $this->assertNumeric($currentPrice, 'current_price');
            $currentValue = bcmul($currentPrice, $openQuantity, self::SCALE);
            $unrealizedPnl = bcsub($currentValue, $investedValue, self::SCALE);
        }

        return [
            'invested_value' => $this->round4($investedValue),
            'current_value' => $currentValue !== null ? $this->round4($currentValue) : null,
            'unrealized_pnl' => $unrealizedPnl !== null ? $this->round4($unrealizedPnl) : null,
        ];
    }

    /**
     * Calculate average entry price after adding to position.
     *
     * Formula: (old_qty * old_price + new_qty * new_price) / (old_qty + new_qty)
     *
     * @param string $currentAvgPrice Current average entry price
     * @param string $currentQuantity Current quantity held
     * @param string $addPrice Price of additional shares
     * @param string $addQuantity Quantity to add
     *
     * @return string New average entry price
     */
    public function calculateAverageEntryPrice(
        string $currentAvgPrice,
        string $currentQuantity,
        string $addPrice,
        string $addQuantity,
    ): string {
        $this->assertNumeric($currentAvgPrice, 'current_avg_price');
        $this->assertNumeric($currentQuantity, 'current_quantity');
        $this->assertNumeric($addPrice, 'add_price');
        $this->assertNumeric($addQuantity, 'add_quantity');

        // Total value = (old_qty * old_price) + (new_qty * new_price)
        $oldValue = bcmul($currentQuantity, $currentAvgPrice, self::SCALE);
        $newValue = bcmul($addQuantity, $addPrice, self::SCALE);
        $totalValue = bcadd($oldValue, $newValue, self::SCALE);

        // Total quantity
        $totalQuantity = bcadd($currentQuantity, $addQuantity, self::SCALE);

        // New average = total_value / total_quantity
        if (bccomp($totalQuantity, '0', self::SCALE) === 0) {
            return '0';
        }

        return $this->round6(bcdiv($totalValue, $totalQuantity, self::SCALE));
    }

    /**
     * Assert that a value is numeric.
     *
     * @throws \InvalidArgumentException
     */
    private function assertNumeric(string $value, string $fieldName): void
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                sprintf('Field "%s" must be numeric, got: %s', $fieldName, $value)
            );
        }
    }

    /**
     * Round to 4 decimal places (for monetary values).
     */
    private function round4(string $value): string
    {
        return bcadd($value, '0', 4);
    }

    /**
     * Round to 6 decimal places (for percentages/prices).
     */
    private function round6(string $value): string
    {
        return bcadd($value, '0', 6);
    }
}