<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsCandles;
use App\Trading\Backtest\Backtester;
use App\Trading\Contracts\Strategy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

final class BotBacktest extends Command
{
    use LoadsCandles;

    protected $signature = 'bot:backtest
        {--symbol= : Symbol to backtest (defaults to the first of trading.symbols)}
        {--days=7 : How many days of history to replay}
        {--interval= : Candle interval (defaults to trading.interval)}
        {--csv= : Replay candles from a CSV file (see bot:export-data) instead of fetching from the exchange}
        {--from= : Only replay candles from this UTC date/time on (e.g. 2026-06-01)}
        {--to= : Only replay candles up to this UTC date/time}';

    protected $description = 'Replay the configured strategy over historical candles and report performance';

    public function handle(Strategy $strategy): int
    {
        $symbol = (string) ($this->option('symbol') ?: Arr::first((array) config('trading.symbols'), null, ''));
        $interval = (string) ($this->option('interval') ?: config('trading.interval'));
        $days = max(1, (int) $this->option('days'));

        if ($symbol === '') {
            $this->error('No symbol given and trading.symbols is empty.');

            return self::FAILURE;
        }

        $csvPath = (string) $this->option('csv');

        if ($csvPath !== '') {
            $this->info(sprintf('Backtesting %s against candles from %s...', $symbol, $csvPath));
        } else {
            $this->info(sprintf('Backtesting %s %s over the last %d day(s)...', $symbol, $interval, $days));
        }

        $candles = $this->loadCandles($symbol, $interval, $days, $csvPath);

        if ($candles === null) {
            return self::FAILURE;
        }

        $candles = $this->filterCandleRange($candles, $this->option('from'), $this->option('to'));

        if ($candles === null || $candles === []) {
            $candles === [] && $this->warn('No candles left in the --from/--to range.');

            return self::FAILURE;
        }

        if (count($candles) < $strategy->warmupPeriod()) {
            $this->warn(sprintf(
                'Only %d candles fetched but strategy [%s] needs %d to warm up; no trades can be simulated.',
                count($candles),
                $strategy->name(),
                $strategy->warmupPeriod(),
            ));
        }

        $paperConfig = (array) config('trading.paper');
        $backtester = new Backtester($strategy, (array) config('trading.risk'), $paperConfig);
        $result = $backtester->run($candles, (float) $paperConfig['starting_balance']);

        $this->line(sprintf('Replayed %d candles with strategy [%s].', count($candles), $strategy->name()));

        $this->table(['Metric', 'Value'], [
            ['Total trades', (string) $result->totalTrades],
            ['Wins / losses', "{$result->wins} / {$result->losses}"],
            ['Win rate', sprintf('%.1f%%', $result->winRate * 100)],
            ['Gross profit', sprintf('%.2f', $result->grossProfit)],
            ['Gross loss', sprintf('%.2f', $result->grossLoss)],
            ['Profit factor', sprintf('%.2f', $result->profitFactor)],
            ['Net PnL', sprintf('%+.2f', $result->netPnl)],
            ['Net PnL %', sprintf('%+.2f%%', $result->netPnlPct * 100)],
            ['Total fees', sprintf('%.2f', $result->totalFees)],
            ['Max drawdown', sprintf('%.2f%%', $result->maxDrawdownPct * 100)],
            ['Starting balance', sprintf('%.2f', $result->startingBalance)],
            ['Ending balance', sprintf('%.2f', $result->endingBalance)],
        ]);

        if ($result->trades !== []) {
            $this->newLine();
            $this->line(sprintf('Last %d trade(s):', min(10, count($result->trades))));
            $this->table(
                ['Entry (UTC)', 'Exit (UTC)', 'Entry', 'Exit', 'Qty', 'PnL', 'Reason'],
                array_map(
                    fn (array $trade): array => [
                        CarbonImmutable::createFromTimestampMs($trade['entry_time'], 'UTC')->format('Y-m-d H:i'),
                        CarbonImmutable::createFromTimestampMs($trade['exit_time'], 'UTC')->format('Y-m-d H:i'),
                        sprintf('%.4f', $trade['entry']),
                        sprintf('%.4f', $trade['exit']),
                        sprintf('%.6f', $trade['qty']),
                        sprintf('%+.2f', $trade['pnl']),
                        $trade['reason'],
                    ],
                    array_slice($result->trades, -10),
                ),
            );
        }

        return self::SUCCESS;
    }
}
