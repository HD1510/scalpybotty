<?php

namespace App\Trading\Strategies;

use App\Trading\Contracts\Strategy;
use App\Trading\Data\Candle;
use App\Trading\Data\Signal;
use App\Trading\Enums\SignalAction;
use App\Trading\Indicators\Indicators;

/**
 * Turtle-style momentum breakout: buy when the close breaks above the
 * highest high of the previous donchian_period candles, with a wide ATR
 * stop and a far ATR target so winners can run. The exit recommendation is
 * a chandelier stop — close dropping chandelier_mult ATRs below the highest
 * close of the last exit_period candles ends the ride.
 *
 * Aggressive by design: it accepts many small stop-outs in exchange for
 * occasionally riding a large trend — the opposite trade-off of the
 * Bollinger mean-reversion strategy.
 */
final class DonchianBreakoutStrategy implements Strategy
{
    public function __construct(private array $params)
    {
    }

    public function name(): string
    {
        return 'donchian_breakout';
    }

    public function warmupPeriod(): int
    {
        return max(
            (int) $this->params['donchian_period'] + 1,
            (int) $this->params['exit_period'],
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

        $atr = Indicators::atr($candles, (int) $this->params['atr_period']);
        $i = count($candles) - 1;

        if ($atr[$i] === null || $atr[$i] <= 0) {
            return Signal::hold('indicators warming up');
        }

        $close = $candles[$i]->close;
        $donchianPeriod = (int) $this->params['donchian_period'];

        // Highest high of the donchian window BEFORE the current candle —
        // the level a breakout has to clear.
        $priorHigh = max(array_map(
            static fn (Candle $candle): float => $candle->high,
            array_slice($candles, $i - $donchianPeriod, $donchianPeriod),
        ));

        if ($close > $priorHigh) {
            $entry = $close;
            $stop = $entry - (float) $this->params['atr_stop_mult'] * $atr[$i];
            $takeProfit = $entry + (float) $this->params['atr_tp_mult'] * $atr[$i];

            if ($stop <= 0) {
                return Signal::hold('degenerate stop');
            }

            // Stronger breakouts (further beyond the prior high, in ATRs)
            // score higher confidence.
            $strength = min(1.0, ($close - $priorHigh) / $atr[$i]);
            $confidence = max(0.0, min(1.0, 0.6 + 0.4 * $strength));

            return new Signal(
                SignalAction::Buy,
                $confidence,
                $stop,
                $takeProfit,
                sprintf(
                    'close %.4f broke above %d-period high %.4f (strength %.2f ATR), ATR %.4f',
                    $close,
                    $donchianPeriod,
                    $priorHigh,
                    $strength,
                    $atr[$i],
                ),
            );
        }

        // Chandelier exit: the trend is over once the close falls
        // chandelier_mult ATRs below the recent highest close.
        $exitPeriod = (int) $this->params['exit_period'];
        $recentHigh = max(array_map(
            static fn (Candle $candle): float => $candle->close,
            array_slice($candles, $i - $exitPeriod + 1, $exitPeriod),
        ));
        $chandelier = $recentHigh - (float) $this->params['chandelier_mult'] * $atr[$i];

        if ($close < $chandelier) {
            return new Signal(
                SignalAction::Sell,
                1.0,
                null,
                null,
                sprintf(
                    'close %.4f fell below chandelier stop %.4f (%d-period high %.4f - %.1f ATR)',
                    $close,
                    $chandelier,
                    $exitPeriod,
                    $recentHigh,
                    (float) $this->params['chandelier_mult'],
                ),
            );
        }

        return Signal::hold(sprintf(
            'close %.4f below %d-period high %.4f, above chandelier %.4f',
            $close,
            $donchianPeriod,
            $priorHigh,
            $chandelier,
        ));
    }
}
