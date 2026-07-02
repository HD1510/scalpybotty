<?php

namespace App\Trading\Strategies;

use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\Signal;
use App\Trading\Enums\SignalAction;
use App\Trading\Indicators\Indicators;

/**
 * Mean-reversion strategy on Bollinger Bands with an RSI oversold filter.
 * Fades moves below the lower band when RSI confirms oversold conditions,
 * targeting a reversion to the middle band (SMA) as take-profit while an
 * ATR-multiple stop-loss below entry limits the downside.
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

        if (
            $bands['upper'][$i] === null || $bands['middle'][$i] === null || $bands['lower'][$i] === null
            || $rsi[$i] === null || $atr[$i] === null
        ) {
            return Signal::hold('indicators warming up');
        }

        $close = $closes[$i];
        $lower = $bands['lower'][$i];
        $middle = $bands['middle'][$i];
        $rsiOversold = (float) $this->params['rsi_oversold'];

        if ($close < $lower && $rsi[$i] <= $rsiOversold) {
            $entry = $close;
            $stop = $entry - (float) $this->params['atr_stop_mult'] * $atr[$i];
            $takeProfit = $middle;

            if ($stop <= 0 || $takeProfit - $entry < (float) $this->params['min_tp_atr'] * $atr[$i]) {
                return Signal::hold('target too close to cover fees');
            }

            // Guard against division by zero: at threshold 0, RSI 0 is maximal oversold.
            $depth = $rsiOversold <= 0 ? 1.0 : min(1.0, ($rsiOversold - $rsi[$i]) / $rsiOversold);
            $confidence = 0.6 + 0.4 * $depth;
            $confidence = max(0.0, min(1.0, $confidence));

            return new Signal(
                SignalAction::Buy,
                $confidence,
                $stop,
                $takeProfit,
                sprintf(
                    'close %.4f below lower band %.4f (middle %.4f), RSI %.2f <= %.1f oversold, ATR %.4f',
                    $close,
                    $lower,
                    $middle,
                    $rsi[$i],
                    $rsiOversold,
                    $atr[$i],
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
