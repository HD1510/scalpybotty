<?php

namespace Tests\Unit\Trading;

use App\Trading\Data\Candle;
use App\Trading\Enums\SignalAction;
use App\Trading\Strategies\EmaRsiScalpStrategy;
use PHPUnit\Framework\TestCase;

final class EmaRsiScalpStrategyTest extends TestCase
{
    private const EPSILON = 0.05;

    private const PARAMS = [
        'fast_ema' => 3,
        'slow_ema' => 5,
        'rsi_period' => 3,
        'rsi_entry_min' => 40.0,
        'rsi_entry_max' => 80.0,
        'atr_period' => 3,
        'atr_stop_mult' => 1.5,
        'atr_tp_mult' => 2.5,
    ];

    public function testReturnsHoldWithFewerCandlesThanWarmupPeriod(): void
    {
        $strategy = $this->makeStrategy();
        $closes = [100.0, 101.0, 100.5, 101.5, 100.0, 101.0];

        self::assertCount($strategy->warmupPeriod() - 1, $closes);

        $signal = $strategy->evaluate($this->candlesFromCloses($closes));

        self::assertSame(SignalAction::Hold, $signal->action);
        self::assertNull($signal->stopLoss);
        self::assertNull($signal->takeProfit);
    }

    public function testBullishCrossWithRsiInBandProducesBuySignal(): void
    {
        $strategy = $this->makeStrategy();

        // Downtrend, then a strong final candle: the fast EMA crosses above
        // the slow EMA on the last closed candle while RSI(3) sits inside
        // [40, 80].
        $closes = [100.0, 99.0, 100.0, 99.0, 98.0, 99.0, 98.0, 101.0];
        $candles = $this->candlesFromCloses($closes);

        $signal = $strategy->evaluate($candles);

        self::assertSame(SignalAction::Buy, $signal->action);
        self::assertNotNull($signal->stopLoss);
        self::assertNotNull($signal->takeProfit);

        $lastClose = end($closes);

        self::assertLessThan($lastClose, $signal->stopLoss);
        self::assertGreaterThan($lastClose, $signal->takeProfit);

        $atr = $this->wilderAtr($candles, (int) self::PARAMS['atr_period']);

        self::assertEqualsWithDelta(
            $lastClose - self::PARAMS['atr_stop_mult'] * $atr,
            $signal->stopLoss,
            1e-9,
        );
        self::assertEqualsWithDelta(
            $lastClose + self::PARAMS['atr_tp_mult'] * $atr,
            $signal->takeProfit,
            1e-9,
        );

        self::assertGreaterThanOrEqual(0.6, $signal->confidence);
        self::assertLessThanOrEqual(1.0, $signal->confidence);
    }

    public function testBullishCrossWithOverboughtRsiIsHeld(): void
    {
        $strategy = $this->makeStrategy();

        // Same shape as the buy scenario, but the final candle spikes so hard
        // that RSI(3) exceeds the 80 upper bound.
        $closes = [100.0, 99.0, 100.0, 99.0, 98.0, 99.0, 100.0, 110.0];

        $signal = $strategy->evaluate($this->candlesFromCloses($closes));

        self::assertSame(SignalAction::Hold, $signal->action);
        self::assertStringContainsString('RSI', $signal->reason);
    }

    public function testBearishCrossProducesSellSignalWithFullConfidence(): void
    {
        $strategy = $this->makeStrategy();

        // Uptrend, then a sharp final drop: the fast EMA crosses below the
        // slow EMA on the last closed candle.
        $closes = [100.0, 101.0, 100.0, 101.0, 102.0, 101.0, 102.0, 99.0];

        $signal = $strategy->evaluate($this->candlesFromCloses($closes));

        self::assertSame(SignalAction::Sell, $signal->action);
        self::assertSame(1.0, $signal->confidence);
    }

    public function testFlatSeriesWithoutCrossIsHeld(): void
    {
        $strategy = $this->makeStrategy();

        $closes = array_fill(0, 10, 100.0);

        $signal = $strategy->evaluate($this->candlesFromCloses($closes));

        self::assertSame(SignalAction::Hold, $signal->action);
        self::assertNull($signal->stopLoss);
        self::assertNull($signal->takeProfit);
    }

    private function makeStrategy(): EmaRsiScalpStrategy
    {
        return new EmaRsiScalpStrategy(self::PARAMS);
    }

    /**
     * Build synthetic candles: open = previous close, high/low bracket the
     * body by a small epsilon.
     *
     * @param  float[]  $closes
     * @return Candle[]
     */
    private function candlesFromCloses(array $closes): array
    {
        $candles = [];
        $previousClose = $closes[0];

        foreach (array_values($closes) as $i => $close) {
            $open = $i === 0 ? $close : $previousClose;

            $candles[] = new Candle(
                openTime: $i * 60_000,
                open: $open,
                high: max($open, $close) + self::EPSILON,
                low: min($open, $close) - self::EPSILON,
                close: $close,
                volume: 10.0,
                closeTime: $i * 60_000 + 59_999,
            );

            $previousClose = $close;
        }

        return $candles;
    }

    /**
     * Independent Wilder-smoothed ATR of the final candle, computed without
     * touching the production Indicators class.
     *
     * @param  Candle[]  $candles
     */
    private function wilderAtr(array $candles, int $period): float
    {
        $atr = 0.0;

        for ($i = 1, $count = count($candles); $i < $count; $i++) {
            $prevClose = $candles[$i - 1]->close;
            $tr = max(
                $candles[$i]->high - $candles[$i]->low,
                abs($candles[$i]->high - $prevClose),
                abs($candles[$i]->low - $prevClose),
            );

            if ($i <= $period) {
                $atr += $tr / $period;
            } else {
                $atr = ($atr * ($period - 1) + $tr) / $period;
            }
        }

        return $atr;
    }
}
