<?php

namespace App\Trading\Indicators;

use App\Trading\Data\Candle;
use InvalidArgumentException;

/**
 * Pure static technical indicator functions.
 *
 * All methods return arrays index-aligned with the input; entries are null
 * until enough data exists for the indicator (warmup). Every method taking a
 * period throws InvalidArgumentException for period < 1, and empty input
 * yields an empty result.
 */
final class Indicators
{
    private function __construct()
    {
    }

    /**
     * Simple Moving Average: mean of the last $period values.
     * Warmup: null before index $period - 1.
     *
     * @param  float[]  $values
     * @return array<int, float|null>
     */
    public static function sma(array $values, int $period): array
    {
        self::assertPeriod($period);

        $values = array_values($values);
        $count = count($values);
        $result = [];
        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum += $values[$i];

            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }

            $result[$i] = $i >= $period - 1 ? $sum / $period : null;
        }

        return $result;
    }

    /**
     * Exponential Moving Average with smoothing k = 2 / (period + 1).
     * Seeded with the SMA of the first $period values at index $period - 1,
     * then ema = value * k + prevEma * (1 - k). Null before index $period - 1.
     *
     * @param  float[]  $values
     * @return array<int, float|null>
     */
    public static function ema(array $values, int $period): array
    {
        self::assertPeriod($period);

        $values = array_values($values);
        $count = count($values);
        $result = [];
        $k = 2 / ($period + 1);
        $ema = null;
        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            if ($ema === null) {
                $sum += $values[$i];

                if ($i === $period - 1) {
                    $ema = $sum / $period;
                    $result[$i] = $ema;
                } else {
                    $result[$i] = null;
                }

                continue;
            }

            $ema = $values[$i] * $k + $ema * (1 - $k);
            $result[$i] = $ema;
        }

        return $result;
    }

    /**
     * Relative Strength Index (0..100) with Wilder smoothing.
     * Average gain/loss are seeded over the first $period price changes, then
     * avg = (prevAvg * (period - 1) + current) / period. RSI = 100 - 100 / (1 + RS);
     * 100.0 when the average loss is zero. First value at index $period.
     *
     * @param  float[]  $closes
     * @return array<int, float|null>
     */
    public static function rsi(array $closes, int $period): array
    {
        self::assertPeriod($period);

        $closes = array_values($closes);
        $count = count($closes);
        $result = [];
        $avgGain = 0.0;
        $avgLoss = 0.0;

        for ($i = 0; $i < $count; $i++) {
            if ($i === 0) {
                $result[$i] = null;

                continue;
            }

            $change = $closes[$i] - $closes[$i - 1];
            $gain = max($change, 0.0);
            $loss = max(-$change, 0.0);

            if ($i <= $period) {
                $avgGain += $gain / $period;
                $avgLoss += $loss / $period;
            } else {
                $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
                $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;
            }

            if ($i < $period) {
                $result[$i] = null;
            } elseif ($avgLoss == 0.0) {
                $result[$i] = 100.0;
            } else {
                $result[$i] = 100.0 - 100.0 / (1 + $avgGain / $avgLoss);
            }
        }

        return $result;
    }

    /**
     * Average True Range with Wilder smoothing.
     * TR = max(high - low, |high - prevClose|, |low - prevClose|), so the first
     * true range exists at index 1. Seeded with the mean of the first $period
     * true ranges at index $period, then atr = (prevAtr * (period - 1) + tr) / period.
     *
     * @param  Candle[]  $candles
     * @return array<int, float|null>
     */
    public static function atr(array $candles, int $period): array
    {
        self::assertPeriod($period);

        $candles = array_values($candles);
        $count = count($candles);
        $result = [];
        $atr = 0.0;

        for ($i = 0; $i < $count; $i++) {
            if ($i === 0) {
                $result[$i] = null;

                continue;
            }

            $candle = $candles[$i];
            $prevClose = $candles[$i - 1]->close;
            $tr = max(
                $candle->high - $candle->low,
                abs($candle->high - $prevClose),
                abs($candle->low - $prevClose),
            );

            if ($i <= $period) {
                $atr += $tr / $period;
            } else {
                $atr = ($atr * ($period - 1) + $tr) / $period;
            }

            $result[$i] = $i >= $period ? $atr : null;
        }

        return $result;
    }

    /**
     * Bollinger Bands: middle = SMA($period), upper/lower = middle +/- $stdDev
     * population standard deviations of the window. Each band is null before
     * index $period - 1.
     *
     * @param  float[]  $closes
     * @return array{upper: array<int, float|null>, middle: array<int, float|null>, lower: array<int, float|null>}
     */
    public static function bollinger(array $closes, int $period = 20, float $stdDev = 2.0): array
    {
        self::assertPeriod($period);

        $closes = array_values($closes);
        $count = count($closes);
        $middle = [];
        $upper = [];
        $lower = [];
        $sum = 0.0;
        $sumSq = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum += $closes[$i];
            $sumSq += $closes[$i] * $closes[$i];

            if ($i >= $period) {
                $old = $closes[$i - $period];
                $sum -= $old;
                $sumSq -= $old * $old;
            }

            if ($i < $period - 1) {
                $middle[$i] = null;
                $upper[$i] = null;
                $lower[$i] = null;

                continue;
            }

            $mean = $sum / $period;
            // Population variance; clamp tiny negatives from float rounding.
            $variance = max($sumSq / $period - $mean * $mean, 0.0);
            $deviation = sqrt($variance) * $stdDev;
            $middle[$i] = $mean;
            $upper[$i] = $mean + $deviation;
            $lower[$i] = $mean - $deviation;
        }

        return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
    }

    /**
     * Cumulative Volume-Weighted Average Price over the given window:
     * sum(typicalPrice * volume) / sum(volume) from index 0 up to each index.
     * A value exists from index 0; null only while the cumulative volume is 0.
     *
     * @param  Candle[]  $candles
     * @return array<int, float|null>
     */
    public static function vwap(array $candles): array
    {
        $candles = array_values($candles);
        $count = count($candles);
        $result = [];
        $priceVolumeSum = 0.0;
        $volumeSum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $priceVolumeSum += $candles[$i]->typicalPrice() * $candles[$i]->volume;
            $volumeSum += $candles[$i]->volume;

            $result[$i] = $volumeSum == 0.0 ? null : $priceVolumeSum / $volumeSum;
        }

        return $result;
    }

    private static function assertPeriod(int $period): void
    {
        if ($period < 1) {
            throw new InvalidArgumentException("Indicator period must be >= 1, got {$period}.");
        }
    }
}
