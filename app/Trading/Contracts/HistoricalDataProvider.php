<?php

namespace App\Trading\Contracts;

use App\Trading\Data\Candle;

/**
 * Paginated access to historical candles, used by the backtester.
 */
interface HistoricalDataProvider
{
    /**
     * All closed candles in [$startTime, $endTime], oldest first. Times are
     * unix milliseconds (UTC). Implementations page through the exchange API
     * as needed.
     *
     * @return Candle[]
     */
    public function candlesBetween(string $symbol, string $interval, int $startTime, int $endTime): array;
}
