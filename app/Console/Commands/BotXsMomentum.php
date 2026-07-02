<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsCandles;
use App\Trading\Backtest\CsvCandleStore;
use App\Trading\Backtest\PortfolioBacktester;
use App\Trading\Exchanges\BinanceExchange;
use App\Trading\Support\Num;
use InvalidArgumentException;
use Illuminate\Console\Command;

final class BotXsMomentum extends Command
{
    use LoadsCandles;

    protected $signature = 'bot:xsmom
        {--days=730 : How much daily history to use}
        {--lookback= : Momentum lookback in days (defaults to xs_momentum config)}
        {--skip= : Recent days to skip in the momentum measure}
        {--top= : How many symbols to hold}
        {--rebalance= : Rebalance interval in days}
        {--min-momentum= : Minimum trailing return to be held at all (absolute momentum)}
        {--from= : Only evaluate from this UTC date on (out-of-sample splits)}
        {--to= : Only evaluate up to this UTC date}';

    protected $description = 'Backtest cross-sectional momentum rotation over the configured coin universe';

    public function handle(CsvCandleStore $store): int
    {
        $config = (array) config('trading.xs_momentum');

        foreach (['lookback' => 'lookback_days', 'skip' => 'skip_days', 'top' => 'top_k', 'rebalance' => 'rebalance_days', 'min-momentum' => 'min_momentum'] as $option => $key) {
            if ($this->option($option) !== null) {
                $config[$key] = $this->option($option) + 0;
            }
        }

        $days = max(90, (int) $this->option('days'));
        $universe = (array) $config['universe'];

        if (count($universe) < 2) {
            $this->error('xs_momentum.universe needs at least two symbols.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'XS momentum over %d symbols, %dd lookback (skip %dd), top %d, rebalance every %dd...',
            count($universe),
            (int) $config['lookback_days'],
            (int) $config['skip_days'],
            (int) $config['top_k'],
            (int) $config['rebalance_days'],
        ));

        // Daily candles per symbol; missing CSVs are exported from mainnet.
        $provider = new BinanceExchange(array_merge((array) config('trading.binance'), ['testnet' => false]));
        $series = [];

        foreach ($universe as $symbol) {
            $csv = storage_path("app/candles/{$symbol}-1d.csv");

            if (! is_file($csv)) {
                $this->line("  exporting {$symbol} 1d...");
                $candles = $this->fetchCandlesFromExchange($symbol, '1d', $days, $provider);

                if ($candles === null || $candles === []) {
                    $this->warn("  {$symbol}: no data — skipped.");

                    continue;
                }

                $store->write($csv, $candles);
            }

            $candles = $store->read($csv);
            $candles = $this->filterCandleRange($candles, $this->option('from'), $this->option('to'));

            if ($candles === null) {
                return self::FAILURE;
            }

            if ($candles !== []) {
                $series[$symbol] = $candles;
            }
        }

        if (count($series) < 2) {
            $this->error('Fewer than two symbols delivered data — nothing to rank.');

            return self::FAILURE;
        }

        $feeRate = (float) config('trading.paper.fee_rate')
            * ((config('trading.paper.fee_bnb_discount') ?? false) ? 0.75 : 1.0);
        $starting = (float) config('trading.paper.starting_balance');

        try {
            $result = (new PortfolioBacktester($config, $feeRate))->run($series, $starting);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Metric', 'Value'], [
            ['Evaluated days', (string) $result['days']],
            ['Rebalances', (string) $result['rebalances']],
            ['Days fully in cash', (string) $result['cashDays']],
            ['Total return', sprintf('%+.2f%%', $result['totalReturnPct'] * 100)],
            ['Benchmark (equal-weight hold)', sprintf('%+.2f%%', $result['benchmarkReturnPct'] * 100)],
            [sprintf('Benchmark (%s hold)', array_key_first($series)), sprintf('%+.2f%%', $result['firstSymbolReturnPct'] * 100)],
            ['Max drawdown', sprintf('%.2f%%', $result['maxDrawdownPct'] * 100)],
            ['Sharpe (ann.)', sprintf('%.2f', $result['sharpe'])],
            ['Total fees', sprintf('%.2f', $result['totalFees'])],
            ['Ending balance', sprintf('%.2f', $result['endingBalance'])],
        ]);

        $allocation = $result['lastAllocation'] === []
            ? 'cash'
            : implode(', ', array_map(
                fn (string $symbol, float $weight): string => sprintf('%s %s%%', $symbol, Num::trim($weight * 100, 1)),
                array_keys($result['lastAllocation']),
                $result['lastAllocation'],
            ));
        $this->line("Current allocation: {$allocation}");

        $edge = $result['totalReturnPct'] - $result['benchmarkReturnPct'];
        $this->line(sprintf(
            'Edge vs equal-weight hold: %+.2f pp — %s',
            $edge * 100,
            $edge > 0 ? 'rotation added value' : 'buy-and-hold would have done better',
        ));

        return self::SUCCESS;
    }
}
