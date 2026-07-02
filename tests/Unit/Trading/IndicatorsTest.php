<?php

namespace Tests\Unit\Trading;

use App\Trading\Data\Candle;
use App\Trading\Indicators\Indicators;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IndicatorsTest extends TestCase
{
    public function test_sma_returns_warmup_nulls_then_window_means(): void
    {
        $result = Indicators::sma([1.0, 2.0, 3.0, 4.0, 5.0], 3);

        $this->assertCount(5, $result);
        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertEqualsWithDelta(2.0, $result[2], 1e-9);
        $this->assertEqualsWithDelta(3.0, $result[3], 1e-9);
        $this->assertEqualsWithDelta(4.0, $result[4], 1e-9);
    }

    public function test_ema_seeds_with_sma_then_applies_smoothing(): void
    {
        $result = Indicators::ema([10.0, 11.0, 13.0, 12.0, 15.0, 14.0], 3);

        $this->assertCount(6, $result);
        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertEqualsWithDelta(34 / 3, $result[2], 1e-9);
        $this->assertEqualsWithDelta(35 / 3, $result[3], 1e-9);
        $this->assertEqualsWithDelta(40 / 3, $result[4], 1e-9);
        $this->assertEqualsWithDelta(41 / 3, $result[5], 1e-9);
    }

    public function test_rsi_applies_wilder_smoothing(): void
    {
        $result = Indicators::rsi([10.0, 11.0, 10.5, 11.5, 12.0, 11.0], 3);

        $this->assertCount(6, $result);
        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertNull($result[2]);
        $this->assertEqualsWithDelta(80.0, $result[3], 1e-9);
        $this->assertEqualsWithDelta(1100 / 13, $result[4], 1e-9);
        $this->assertEqualsWithDelta(50.0, $result[5], 1e-9);
    }

    public function test_rsi_is_100_when_average_loss_is_zero(): void
    {
        $result = Indicators::rsi([1.0, 2.0, 3.0, 4.0, 5.0], 3);

        $this->assertNull($result[2]);
        $this->assertSame(100.0, $result[3]);
        $this->assertSame(100.0, $result[4]);
    }

    public function test_atr_uses_true_range_including_gaps(): void
    {
        $candles = [
            $this->candle(10.0, 9.0, 9.5),
            $this->candle(10.5, 9.5, 10.0),
            $this->candle(11.0, 10.0, 10.8),
            $this->candle(13.0, 12.0, 12.5),
            $this->candle(13.0, 12.4, 12.6),
            $this->candle(11.0, 10.5, 10.7),
        ];

        $result = Indicators::atr($candles, 3);

        $this->assertCount(6, $result);
        $this->assertNull($result[0]);
        $this->assertNull($result[1]);
        $this->assertNull($result[2]);
        $this->assertEqualsWithDelta(1.4, $result[3], 1e-9);
        $this->assertEqualsWithDelta(3.4 / 3, $result[4], 1e-9);
        $this->assertEqualsWithDelta(13.1 / 9, $result[5], 1e-9);
    }

    public function test_bollinger_bands_collapse_on_constant_series(): void
    {
        $result = Indicators::bollinger([5.0, 5.0, 5.0, 5.0], 3);

        $this->assertNull($result['upper'][1]);
        $this->assertNull($result['middle'][1]);
        $this->assertNull($result['lower'][1]);

        foreach ([2, 3] as $i) {
            $this->assertEqualsWithDelta(5.0, $result['middle'][$i], 1e-9);
            $this->assertEqualsWithDelta(5.0, $result['upper'][$i], 1e-9);
            $this->assertEqualsWithDelta(5.0, $result['lower'][$i], 1e-9);
        }
    }

    public function test_bollinger_uses_population_standard_deviation(): void
    {
        $result = Indicators::bollinger([2.0, 4.0, 6.0], 3, 2.0);

        $std = sqrt(8 / 3);
        $this->assertEqualsWithDelta(4.0, $result['middle'][2], 1e-9);
        $this->assertEqualsWithDelta(4.0 + 2 * $std, $result['upper'][2], 1e-9);
        $this->assertEqualsWithDelta(4.0 - 2 * $std, $result['lower'][2], 1e-9);
    }

    public function test_vwap_is_cumulative_typical_price_weighted_by_volume(): void
    {
        $candles = [
            $this->candle(12.0, 10.0, 11.0, 2.0),
            $this->candle(14.0, 12.0, 13.0, 3.0),
        ];

        $result = Indicators::vwap($candles);

        $this->assertEqualsWithDelta(11.0, $result[0], 1e-9);
        $this->assertEqualsWithDelta(12.2, $result[1], 1e-9);
    }

    public function test_vwap_is_null_while_cumulative_volume_is_zero(): void
    {
        $candles = [
            $this->candle(12.0, 10.0, 11.0, 0.0),
            $this->candle(14.0, 12.0, 13.0, 3.0),
        ];

        $result = Indicators::vwap($candles);

        $this->assertNull($result[0]);
        $this->assertEqualsWithDelta(13.0, $result[1], 1e-9);
    }

    public function test_sma_throws_for_period_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::sma([1.0, 2.0], 0);
    }

    public function test_ema_throws_for_period_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::ema([1.0, 2.0], 0);
    }

    public function test_rsi_throws_for_period_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::rsi([1.0, 2.0], -1);
    }

    public function test_atr_throws_for_period_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::atr([], 0);
    }

    public function test_bollinger_throws_for_period_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Indicators::bollinger([1.0, 2.0], 0);
    }

    public function test_empty_input_yields_empty_result(): void
    {
        $this->assertSame([], Indicators::sma([], 3));
        $this->assertSame([], Indicators::ema([], 3));
        $this->assertSame([], Indicators::rsi([], 3));
        $this->assertSame([], Indicators::atr([], 3));
        $this->assertSame([], Indicators::vwap([]));

        $bands = Indicators::bollinger([], 3);
        $this->assertSame([], $bands['upper']);
        $this->assertSame([], $bands['middle']);
        $this->assertSame([], $bands['lower']);
    }

    private function candle(float $high, float $low, float $close, float $volume = 1.0): Candle
    {
        return new Candle(0, $low, $high, $low, $close, $volume, 0);
    }
}
