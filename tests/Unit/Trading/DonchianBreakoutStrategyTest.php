<?php

namespace Tests\Unit\Trading;

use App\Trading\Data\Candle;
use App\Trading\Enums\SignalAction;
use App\Trading\Strategies\DonchianBreakoutStrategy;
use PHPUnit\Framework\TestCase;

class DonchianBreakoutStrategyTest extends TestCase
{
    private const PARAMS = [
        'donchian_period' => 10,
        'exit_period' => 5,
        'chandelier_mult' => 3.0,
        'atr_period' => 5,
        'atr_stop_mult' => 2.0,
        'atr_tp_mult' => 6.0,
    ];

    public function test_breakout_above_prior_high_buys_with_wide_targets(): void
    {
        $strategy = new DonchianBreakoutStrategy(self::PARAMS);

        // Flat around 100, final close breaks well above every prior high.
        $signal = $strategy->evaluate($this->series(array_merge(array_fill(0, 20, 100.0), [104.0])));

        $this->assertSame(SignalAction::Buy, $signal->action);
        $this->assertNotNull($signal->stopLoss);
        $this->assertNotNull($signal->takeProfit);
        $this->assertLessThan(104.0, $signal->stopLoss);
        $this->assertGreaterThan(104.0, $signal->takeProfit);
        // Far target: distance to tp must be 3x the distance to the stop.
        $this->assertEqualsWithDelta(
            3.0 * (104.0 - $signal->stopLoss),
            $signal->takeProfit - 104.0,
            1e-9,
        );
        $this->assertGreaterThanOrEqual(0.6, $signal->confidence);
    }

    public function test_flat_series_holds(): void
    {
        $strategy = new DonchianBreakoutStrategy(self::PARAMS);

        $signal = $strategy->evaluate($this->series(array_fill(0, 21, 100.0)));

        $this->assertSame(SignalAction::Hold, $signal->action);
    }

    public function test_deep_pullback_below_chandelier_recommends_exit(): void
    {
        $strategy = new DonchianBreakoutStrategy(self::PARAMS);

        // Rally to 110, then a hard drop far below the chandelier level.
        $closes = array_merge(array_fill(0, 15, 100.0), [104.0, 107.0, 110.0], [108.0, 96.0]);
        $signal = $strategy->evaluate($this->series($closes));

        $this->assertSame(SignalAction::Sell, $signal->action);
        $this->assertSame(1.0, $signal->confidence);
    }

    public function test_insufficient_candles_hold(): void
    {
        $strategy = new DonchianBreakoutStrategy(self::PARAMS);

        $this->assertSame(SignalAction::Hold, $strategy->evaluate([])->action);
    }

    public function test_trend_filter_suppresses_breakouts_in_downtrend_regime(): void
    {
        // Decline from 130, then a flat base at 100 long enough that the
        // transition candle leaves the donchian window, and a pop to 103:
        // the pop breaks the 10-period high (~100.1) but stays below the
        // EMA(15), which still carries the decline (~105).
        $closes = array_merge(range(130, 112, 2), array_fill(0, 12, 100.0), [103.0]);

        $filtered = new DonchianBreakoutStrategy(['trend_ema' => 15] + self::PARAMS);
        $signal = $filtered->evaluate($this->series($closes));

        $this->assertSame(SignalAction::Hold, $signal->action);
        $this->assertStringContainsString('trend EMA', $signal->reason);

        // Without the filter the identical breakout is taken.
        $unfiltered = new DonchianBreakoutStrategy(self::PARAMS);
        $this->assertSame(SignalAction::Buy, $unfiltered->evaluate($this->series($closes))->action);
    }

    /**
     * @return Candle[]
     */
    private function series(array $closes): array
    {
        $candles = [];
        $previous = $closes[0];

        foreach ($closes as $index => $close) {
            $candles[] = new Candle(
                openTime: $index * 60_000,
                open: $previous,
                high: max($previous, $close) + 0.1,
                low: min($previous, $close) - 0.1,
                close: $close,
                volume: 10.0,
                closeTime: $index * 60_000 + 59_999,
            );
            $previous = $close;
        }

        return $candles;
    }
}
