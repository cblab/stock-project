<?php

declare(strict_types=1);

namespace App\Tests\Service\Trade;

use App\Service\Trade\TradePnlCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TradePnlCalculator.
 *
 * Pure domain logic tests - no database required.
 */
final class TradePnlCalculatorTest extends TestCase
{
    private TradePnlCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TradePnlCalculator();
    }

    // ==================== calculateRealizedGrossPnl ====================

    public function testCalculateRealizedGrossPnlProfitWithoutFees(): void
    {
        $result = $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 10
        );

        self::assertSame(100.0, $result);
    }

    public function testCalculateRealizedGrossPnlProfitWithFees(): void
    {
        $result = $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 10,
            fees: 5.0
        );

        self::assertSame(95.0, $result);
    }

    public function testCalculateRealizedGrossPnlLoss(): void
    {
        $result = $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 90.0,
            quantity: 10
        );

        self::assertSame(-100.0, $result);
    }

    public function testCalculateRealizedGrossPnlLossWithFees(): void
    {
        $result = $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 90.0,
            quantity: 10,
            fees: 5.0
        );

        self::assertSame(-105.0, $result);
    }

    public function testCalculateRealizedGrossPnlWithStringInputs(): void
    {
        $result = $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: '100.50',
            exitPrice: '110.25',
            quantity: '5',
            fees: '2.50'
        );

        self::assertSame(46.25, $result);
    }

    public function testCalculateRealizedGrossPnlZeroFees(): void
    {
        $result = $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 10,
            fees: 0
        );

        self::assertSame(100.0, $result);
    }

    // ==================== calculateRealizedPnlPct ====================

    public function testCalculateRealizedPnlPctPositive(): void
    {
        $result = $this->calculator->calculateRealizedPnlPct(
            avgEntryPrice: 100.0,
            realizedGrossPnl: 100.0,
            quantity: 10
        );

        self::assertSame(0.1, $result);
    }

    public function testCalculateRealizedPnlPctNegative(): void
    {
        $result = $this->calculator->calculateRealizedPnlPct(
            avgEntryPrice: 100.0,
            realizedGrossPnl: -100.0,
            quantity: 10
        );

        self::assertSame(-0.1, $result);
    }

    public function testCalculateRealizedPnlPctZero(): void
    {
        $result = $this->calculator->calculateRealizedPnlPct(
            avgEntryPrice: 100.0,
            realizedGrossPnl: 0.0,
            quantity: 10
        );

        self::assertSame(0.0, $result);
    }

    // ==================== calculateRealizedNetPnl ====================

    public function testCalculateRealizedNetPnlReturnsNullForNullTaxRate(): void
    {
        $result = $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: 100.0,
            taxRate: null
        );

        self::assertNull($result);
    }

    public function testCalculateRealizedNetPnlWithProfitAndTaxRate(): void
    {
        $result = $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: 100.0,
            taxRate: 0.25
        );

        self::assertSame(75.0, $result);
    }

    public function testCalculateRealizedNetPnlWithLossAndTaxRate(): void
    {
        $result = $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: -100.0,
            taxRate: 0.25
        );

        self::assertSame(-100.0, $result);
    }

    public function testCalculateRealizedNetPnlWithZeroProfitAndTaxRate(): void
    {
        $result = $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: 0.0,
            taxRate: 0.25
        );

        self::assertSame(0.0, $result);
    }

    public function testCalculateRealizedNetPnlWithGermanTaxRate(): void
    {
        $result = $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: 1000.0,
            taxRate: 0.26375
        );

        self::assertEqualsWithDelta(736.25, $result, 0.0001);
    }

    // ==================== calculateExitSummary ====================

    public function testCalculateExitSummaryComplete(): void
    {
        $result = $this->calculator->calculateExitSummary(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 10,
            fees: 5.0,
            taxRate: 0.25
        );

        self::assertSame(95.0, $result['realized_pnl_gross']);
        self::assertSame(0.095, $result['realized_pnl_pct']);
        self::assertSame(71.25, $result['realized_pnl_net']);
        self::assertSame(0.25, $result['tax_rate_applied']);
    }

    public function testCalculateExitSummaryWithoutTax(): void
    {
        $result = $this->calculator->calculateExitSummary(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 10,
            fees: 0.0
        );

        self::assertSame(100.0, $result['realized_pnl_gross']);
        self::assertSame(0.1, $result['realized_pnl_pct']);
        self::assertNull($result['realized_pnl_net']);
        self::assertNull($result['tax_rate_applied']);
    }

    // ==================== Validation Errors ====================

    public function testZeroAvgEntryPriceThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('avgEntryPrice must be > 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 0,
            exitPrice: 110.0,
            quantity: 10
        );
    }

    public function testNegativeAvgEntryPriceThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('avgEntryPrice must be > 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: -100.0,
            exitPrice: 110.0,
            quantity: 10
        );
    }

    public function testZeroExitPriceThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exitPrice must be > 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 0,
            quantity: 10
        );
    }

    public function testNegativeExitPriceThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exitPrice must be > 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: -110.0,
            quantity: 10
        );
    }

    public function testZeroQuantityThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be > 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 0
        );
    }

    public function testNegativeQuantityThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be > 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: -10
        );
    }

    public function testNegativeFeesThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fees must be >= 0');

        $this->calculator->calculateRealizedGrossPnl(
            avgEntryPrice: 100.0,
            exitPrice: 110.0,
            quantity: 10,
            fees: -5.0
        );
    }

    public function testTaxRateLessThanZeroThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxRate must be between 0 and 1');

        $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: 100.0,
            taxRate: -0.1
        );
    }

    public function testTaxRateGreaterThanOneThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxRate must be between 0 and 1');

        $this->calculator->calculateRealizedNetPnl(
            realizedGrossPnl: 100.0,
            taxRate: 1.1
        );
    }
}
