<?php

namespace App\Trading\Strategies;

use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\Signal;
use App\Trading\Enums\SignalAction;
use App\Trading\Indicators\Indicators;

/**
 * Mean-reversion strategy on Bollinger Bands with an RSI oversold filter.
 * Fades moves below the lower band, targeting a reversion to the middle
 * band while an ATR-multiple stop-loss limits the downside.
 *
 * With entry_confirmation (default) the entry waits until the close crosses
 * back ABOVE the lower band after having been below it — buying the turn,
 * not the falling knife. trend_ema > 0 additionally restricts entries to
 * dips above that EMA (mean reversion within an uptrend only).
 */
final class MeanReversionBollingerStrategy implements Strategy
{
    public function __construct(private array $params)
    {
    }

    public function name(): string
    {
        return 'bollinger_reversion';
    }

    public function warmupPeriod(): int
    {
        return max(
            (int) $this->params['bb_period'],
            (int) $this->params['rsi_period'] + 1,
            (int) $this->params['atr_period'] + 1,
            (int) ($this->params['trend_ema'] ?? 0),
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

        $bands = Indicators::bollinger($closes, (int) $this->params['bb_period'], (float) $this->params['bb_std_dev']);
        $rsi = Indicators::rsi($closes, (int) $this->params['rsi_period']);
        $atr = Indicators::atr($candles, (int) $this->params['atr_period']);

        $i = count($candles) - 1;
        $p = $i - 1;

        if (
            $bands['upper'][$i] === null || $bands['middle'][$i] === null || $bands['lower'][$i] === null
            || $bands['lower'][$p] === null || $rsi[$i] === null || $rsi[$p] === null || $atr[$i] === null
        ) {
            return Signal::hold('indicators warming up');
        }

        $close = $closes[$i];
        $lower = $bands['lower'][$i];
        $middle = $bands['middle'][$i];
        $rsiOversold = (float) $this->params['rsi_oversold'];
        $confirm = (bool) ($this->params['entry_confirmation'] ?? true);

        // Entry trigger: with confirmation, the previous close must have been
        // below its band while the current close is back at/above the band
        // (the dip has turned); without it, any close below the band fires.
        // The oversold check applies to the dip bar in confirmation mode.
        $entrySetup = $confirm
            ? $closes[$p] < $bands['lower'][$p] && $close >= $lower && $rsi[$p] <= $rsiOversold
            : $close < $lower && $rsi[$i] <= $rsiOversold;

        $trendEmaPeriod = (int) ($this->params['trend_ema'] ?? 0);
        $trendValue = null;

        if ($entrySetup && $trendEmaPeriod > 0) {
            $trend = Indicators::ema($closes, $trendEmaPeriod);
            $trendValue = $trend[$i];

            if ($trendValue === null || $close < $trendValue) {
                return Signal::hold(sprintf(
                    'dip signal suppressed — close %.4f below trend EMA(%d) %.4f (downtrend regime)',
                    $close,
                    $trendEmaPeriod,
                    $trendValue ?? 0.0,
                ));
            }
        }

        if ($entrySetup) {
            $entry = $close;
            $stop = $entry - (float) $this->params['atr_stop_mult'] * $atr[$i];
            $takeProfit = $middle;

            if ($stop <= 0 || $takeProfit - $entry < (float) $this->params['min_tp_atr'] * $atr[$i]) {
                return Signal::hold('target too close to cover fees');
            }

            $dipRsi = $confirm ? $rsi[$p] : $rsi[$i];
            // Guard against division by zero: at threshold 0, RSI 0 is maximal oversold.
            $depth = $rsiOversold <= 0 ? 1.0 : min(1.0, ($rsiOversold - $dipRsi) / $rsiOversold);
            $confidence = max(0.0, min(1.0, 0.6 + 0.4 * $depth));

            return new Signal(
                SignalAction::Buy,
                $confidence,
                $stop,
                $takeProfit,
                sprintf(
                    '%s lower band %.4f (middle %.4f), dip RSI %.2f <= %.1f oversold, ATR %.4f%s',
                    $confirm ? sprintf('close %.4f turned back above', $close) : sprintf('close %.4f below', $close),
                    $lower,
                    $middle,
                    $dipRsi,
                    $rsiOversold,
                    $atr[$i],
                    $trendValue !== null ? sprintf(', above trend EMA %.4f', $trendValue) : '',
                ),
            );
        }

        if ($close >= $middle) {
            return new Signal(
                SignalAction::Sell,
                1.0,
                null,
                null,
                sprintf(
                    'close %.4f reached middle band %.4f (mean-reversion target), RSI %.2f',
                    $close,
                    $middle,
                    $rsi[$i],
                ),
            );
        }

        return Signal::hold(sprintf(
            'close %.4f between lower band %.4f and middle band %.4f, RSI %.2f',
            $close,
            $lower,
            $middle,
            $rsi[$i],
        ));
    }
}
