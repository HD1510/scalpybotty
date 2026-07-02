<?php

namespace App\Trading\Strategies;

use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\Signal;
use App\Trading\Enums\SignalAction;
use App\Trading\Indicators\Indicators;

/**
 * EMA-crossover scalping strategy with an RSI momentum filter.
 * Enters long when the fast EMA crosses above the slow EMA while RSI sits
 * inside a bullish-but-not-overbought band; stop-loss and take-profit are
 * placed at ATR multiples from entry. A bearish EMA cross recommends exit.
 */
final class EmaRsiScalpStrategy implements Strategy
{
    public function __construct(private array $params)
    {
    }

    public function name(): string
    {
        return 'ema_rsi_scalp';
    }

    public function warmupPeriod(): int
    {
        return max(
            (int) $this->params['slow_ema'],
            (int) $this->params['rsi_period'] + 1,
            (int) $this->params['atr_period'] + 1,
        ) + 2;
    }

    /**
     * @param  Candle[]  $candles
     */
    public function evaluate(array $candles): Signal
    {
        if (count($candles) < $this->warmupPeriod()) {
            return Signal::hold('insufficient candles');
        }

        $closes = array_map(static fn (Candle $candle): float => $candle->close, $candles);

        $emaFast = Indicators::ema($closes, (int) $this->params['fast_ema']);
        $emaSlow = Indicators::ema($closes, (int) $this->params['slow_ema']);
        $rsi = Indicators::rsi($closes, (int) $this->params['rsi_period']);
        $atr = Indicators::atr($candles, (int) $this->params['atr_period']);

        $i = count($candles) - 1;
        $p = $i - 1;

        if (
            $emaFast[$i] === null || $emaFast[$p] === null
            || $emaSlow[$i] === null || $emaSlow[$p] === null
            || $rsi[$i] === null || $atr[$i] === null
        ) {
            return Signal::hold('indicators warming up');
        }

        $rsiMin = (float) $this->params['rsi_entry_min'];
        $rsiMax = (float) $this->params['rsi_entry_max'];

        $bullishCross = $emaFast[$i] > $emaSlow[$i] && $emaFast[$p] <= $emaSlow[$p];
        $bearishCross = $emaFast[$i] < $emaSlow[$i] && $emaFast[$p] >= $emaSlow[$p];

        if ($bullishCross && $rsi[$i] >= $rsiMin && $rsi[$i] <= $rsiMax) {
            $entry = $closes[$i];
            $stop = $entry - (float) $this->params['atr_stop_mult'] * $atr[$i];
            $takeProfit = $entry + (float) $this->params['atr_tp_mult'] * $atr[$i];

            if ($stop <= 0) {
                return Signal::hold('degenerate stop');
            }

            // Guard against division by zero when the RSI entry band collapses to a point.
            $rsiBand = $rsiMax - $rsiMin;
            $confidence = $rsiBand <= 0 ? 0.6 : 0.6 + 0.4 * ($rsi[$i] - $rsiMin) / $rsiBand;
            $confidence = max(0.0, min(1.0, $confidence));

            return new Signal(
                SignalAction::Buy,
                $confidence,
                $stop,
                $takeProfit,
                sprintf(
                    'bullish EMA cross (fast %.4f > slow %.4f, prev %.4f <= %.4f), RSI %.2f in [%.1f, %.1f], ATR %.4f',
                    $emaFast[$i],
                    $emaSlow[$i],
                    $emaFast[$p],
                    $emaSlow[$p],
                    $rsi[$i],
                    $rsiMin,
                    $rsiMax,
                    $atr[$i],
                ),
            );
        }

        if ($bearishCross) {
            return new Signal(
                SignalAction::Sell,
                1.0,
                null,
                null,
                sprintf(
                    'bearish EMA cross (fast %.4f < slow %.4f, prev %.4f >= %.4f), RSI %.2f',
                    $emaFast[$i],
                    $emaSlow[$i],
                    $emaFast[$p],
                    $emaSlow[$p],
                    $rsi[$i],
                ),
            );
        }

        if ($bullishCross) {
            return Signal::hold(sprintf('bullish EMA cross but RSI %.2f outside [%.1f, %.1f]', $rsi[$i], $rsiMin, $rsiMax));
        }

        return Signal::hold(sprintf(
            'no cross: fast EMA %.4f %s slow EMA %.4f, RSI %.2f',
            $emaFast[$i],
            $emaFast[$i] > $emaSlow[$i] ? 'above' : 'below',
            $emaSlow[$i],
            $rsi[$i],
        ));
    }
}
