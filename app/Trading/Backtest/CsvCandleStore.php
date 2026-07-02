<?php

namespace App\Trading\Backtest;

use App\Trading\Data\Candle;
use RuntimeException;

/**
 * Reads and writes candle history as CSV so backtests can run offline.
 *
 * Format: header row "open_time,open,high,low,close,volume,close_time",
 * one candle per line, times in unix milliseconds (UTC), oldest first.
 */
final class CsvCandleStore
{
    private const HEADER = ['open_time', 'open', 'high', 'low', 'close', 'volume', 'close_time'];

    /**
     * @return Candle[] oldest first
     */
    public function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Candle CSV [{$path}] does not exist or is not readable.");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open candle CSV [{$path}].");
        }

        try {
            $header = fgetcsv($handle);

            if ($header !== self::HEADER) {
                throw new RuntimeException(sprintf(
                    'Candle CSV [%s] has an unexpected header [%s]; expected [%s].',
                    $path,
                    implode(',', (array) $header),
                    implode(',', self::HEADER),
                ));
            }

            $candles = [];
            $line = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $line++;

                if ($row === [null]) {
                    continue; // blank line
                }

                if (count($row) !== count(self::HEADER)) {
                    throw new RuntimeException("Candle CSV [{$path}] line {$line}: expected 7 columns, got ".count($row).'.');
                }

                $candles[] = new Candle(
                    openTime: (int) $row[0],
                    open: (float) $row[1],
                    high: (float) $row[2],
                    low: (float) $row[3],
                    close: (float) $row[4],
                    volume: (float) $row[5],
                    closeTime: (int) $row[6],
                );
            }
        } finally {
            fclose($handle);
        }

        usort($candles, fn (Candle $a, Candle $b): int => $a->openTime <=> $b->openTime);

        return $candles;
    }

    /**
     * @param  Candle[]  $candles
     * @return int number of candles written
     */
    public function write(string $path, array $candles): int
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory [{$directory}].");
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$path}] for writing.");
        }

        try {
            fputcsv($handle, self::HEADER);

            foreach ($candles as $candle) {
                fputcsv($handle, [
                    $candle->openTime,
                    $candle->open,
                    $candle->high,
                    $candle->low,
                    $candle->close,
                    $candle->volume,
                    $candle->closeTime,
                ]);
            }
        } finally {
            fclose($handle);
        }

        return count($candles);
    }
}
