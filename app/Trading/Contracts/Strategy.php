<?php

namespace App\Trading\Contracts;

use App\Trading\Data\Candle;
use App\Trading\Data\Signal;

/**
 * A trading strategy. Stateless: each evaluation receives the full candle
 * window and must not depend on previous calls, so the same class works
 * unchanged in live trading and backtesting.
 */
interface Strategy
{
    /** Short identifier used in config and persisted with trades, e.g. 'ema_rsi_scalp'. */
    public function name(): string;

    /**
     * Minimum number of candles evaluate() needs. The bot always passes at
     * least this many; evaluate() must return Signal::hold() if given fewer.
     */
    public function warmupPeriod(): int;

    /**
     * Evaluate the market. $candles are closed candles, oldest first; the
     * last element is the most recently closed candle.
     *
     * Buy signals MUST set stopLoss and takeProfit (absolute prices).
     * Sell signals are exit recommendations for an open position.
     *
     * @param  Candle[]  $candles
     */
    public function evaluate(array $candles): Signal;
}
