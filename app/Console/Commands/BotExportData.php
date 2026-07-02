<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsCandles;
use App\Trading\Backtest\CsvCandleStore;
use App\Trading\Exchanges\BinanceExchange;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

final class BotExportData extends Command
{
    use LoadsCandles;

    protected $signature = 'bot:export-data
        {--symbol= : Symbol to export (defaults to the first of trading.symbols)}
        {--days=30 : How many days of history to fetch}
        {--interval= : Candle interval (defaults to trading.interval)}
        {--out= : Output CSV path (defaults to storage/app/candles/<symbol>-<interval>.csv)}
        {--testnet : Export candles from the Binance testnet instead of mainnet}';

    protected $description = 'Download historical candles to a CSV file for offline backtesting (bot:backtest --csv=...)';

    public function handle(CsvCandleStore $store): int
    {
        $symbol = (string) ($this->option('symbol') ?: Arr::first((array) config('trading.symbols'), null, ''));
        $interval = (string) ($this->option('interval') ?: config('trading.interval'));
        $days = max(1, (int) $this->option('days'));
        $path = (string) ($this->option('out') ?: storage_path("app/candles/{$symbol}-{$interval}.csv"));

        if ($symbol === '') {
            $this->error('No symbol given and trading.symbols is empty.');

            return self::FAILURE;
        }

        // Backtests need real market data: export always talks to Binance
        // directly and defaults to MAINNET (klines are public, no API key
        // required) regardless of BINANCE_TESTNET — the testnet keeps only a
        // few weeks of history and trades without real liquidity.
        $useTestnet = (bool) $this->option('testnet');
        $provider = new BinanceExchange(array_merge((array) config('trading.binance'), ['testnet' => $useTestnet]));

        $this->info(sprintf(
            'Fetching %s %s candles for the last %d day(s) from Binance %s...',
            $symbol,
            $interval,
            $days,
            $useTestnet ? 'TESTNET (limited history!)' : 'mainnet',
        ));

        $candles = $this->fetchCandlesFromExchange($symbol, $interval, $days, $provider);

        if ($candles === null) {
            return self::FAILURE;
        }

        if ($candles === []) {
            $this->warn('The exchange returned 0 candles — nothing written.');

            return self::FAILURE;
        }

        $count = $store->write($path, $candles);

        $this->info(sprintf('Wrote %d candles to %s', $count, $path));
        $this->line("Run the backtest offline with: php artisan bot:backtest --csv={$path}");

        return self::SUCCESS;
    }
}
