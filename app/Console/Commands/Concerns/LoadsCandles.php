<?php

namespace App\Console\Commands\Concerns;

use App\Trading\Backtest\CsvCandleStore;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\HistoricalDataProvider;
use App\Trading\Data\Candle;
use App\Trading\Exceptions\ExchangeException;
use RuntimeException;

/**
 * Candle acquisition shared by the backtest, optimize and export commands:
 * replay a CSV when a path is given, otherwise fetch a --days window from
 * the configured exchange. Failures are reported via the command's output.
 */
trait LoadsCandles
{
    /**
     * @return Candle[]|null oldest first; null after printing an error/warning
     */
    private function loadCandles(string $symbol, string $interval, int $days, string $csvPath): ?array
    {
        if ($csvPath !== '') {
            try {
                $candles = (new CsvCandleStore)->read($csvPath);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return null;
            }
        } else {
            $candles = $this->fetchCandlesFromExchange($symbol, $interval, $days);

            if ($candles === null) {
                return null;
            }
        }

        if ($candles === []) {
            $this->warn('Got 0 candles — check your network connection, symbol, interval or CSV file.');

            return null;
        }

        return $candles;
    }

    /**
     * @return Candle[]|null oldest first (may be empty); null after printing an error
     */
    private function fetchCandlesFromExchange(string $symbol, string $interval, int $days): ?array
    {
        $exchange = resolve(Exchange::class);

        if (! $exchange instanceof HistoricalDataProvider) {
            $this->error(sprintf(
                'Exchange [%s] cannot provide historical data; this command needs a HistoricalDataProvider implementation.',
                $exchange->name(),
            ));

            return null;
        }

        $endTime = now('UTC')->getTimestampMs();
        $startTime = $endTime - $days * 86_400_000;

        try {
            return $exchange->candlesBetween($symbol, $interval, $startTime, $endTime);
        } catch (ExchangeException $e) {
            $this->error("Failed to fetch candles: {$e->getMessage()}");

            return null;
        }
    }
}
