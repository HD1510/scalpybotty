<?php

namespace App\Console\Commands\Concerns;

use App\Trading\Backtest\CsvCandleStore;
use App\Trading\Contracts\Exchange;
use App\Trading\Contracts\HistoricalDataProvider;
use App\Trading\Data\Candle;
use App\Trading\Exceptions\ExchangeException;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

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
     * Restrict candles to the --from/--to window (dates or datetimes, UTC).
     * Enables in-sample/out-of-sample discipline: tune on one date range,
     * validate on another the optimizer has never seen.
     *
     * @param  Candle[]  $candles
     * @return Candle[]|null null after printing an error for an unparsable date
     */
    private function filterCandleRange(array $candles, ?string $from, ?string $to): ?array
    {
        try {
            $fromMs = $from ? CarbonImmutable::parse($from, 'UTC')->getTimestampMs() : null;
            $toMs = $to ? CarbonImmutable::parse($to, 'UTC')->getTimestampMs() : null;
        } catch (Throwable) {
            $this->error(sprintf('Could not parse --from/--to date [%s / %s]; use e.g. 2026-06-01 or "2026-06-01 12:00".', $from ?? '', $to ?? ''));

            return null;
        }

        if ($fromMs === null && $toMs === null) {
            return $candles;
        }

        $filtered = array_values(array_filter(
            $candles,
            fn (Candle $c): bool => ($fromMs === null || $c->openTime >= $fromMs)
                && ($toMs === null || $c->closeTime <= $toMs),
        ));

        $this->line(sprintf('Date filter: %d of %d candles in range.', count($filtered), count($candles)));

        return $filtered;
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
